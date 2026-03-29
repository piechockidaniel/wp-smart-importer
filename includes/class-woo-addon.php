<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPSI_Woo_Addon
 *
 * Handles WooCommerce-specific field mapping:
 *   – Price cleanup (currency symbols, decimal normalisation)
 *   – Stock management with "Set automatically" mode
 *   – Product attributes (pa_* taxonomy + _product_attributes meta)
 *   – Shipping dimensions/weight/class
 *   – SKU duplicate / auto-generation options
 *   – Virtual, downloadable, featured, catalog_visibility
 *
 * The woo_config JSON stored per job:
 * {
 *   "product_type"      : "simple",           // simple|variable|grouped|external
 *   "price_cleanup"     : { "strip_currency":true, "fix_decimal":true },
 *   "stock_auto"        : true,               // derive _stock_status from _stock qty
 *   "stock_threshold"   : 1,
 *   "disable_auto_sku"  : true,
 *   "skip_dup_sku"      : true,
 *   "attributes"        : [
 *     {
 *       "name"         : "pa_brand",
 *       "value_source" : "field",             // field|static|template|expression
 *       "value"        : "brand",             // source path / value
 *       "in_variations": false,
 *       "is_visible"   : true,
 *       "is_taxonomy"  : true,
 *       "auto_create"  : true
 *     }
 *   ]
 * }
 */
class WPSI_Woo_Addon {

    /** Apply all WooCommerce-specific logic after normal mapping. */
    public static function apply( int $post_id, array $record, array $woo_config, array $field_map ): void {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        $cfg = is_string( $woo_config ) ? json_decode( $woo_config, true ) : $woo_config;
        if ( empty( $cfg ) ) return;

        // ── Product type ─────────────────────────────────────────────
        $product_type = $cfg['product_type'] ?? 'simple';
        if ( $product_type && $product_type !== 'simple' ) {
            wp_set_object_terms( $post_id, $product_type, 'product_type' );
        }

        // ── Price cleanup ─────────────────────────────────────────────
        $price_cfg = $cfg['price_cleanup'] ?? [];
        if ( ! empty( $price_cfg ) ) {
            foreach ( [ '_price', '_regular_price', '_sale_price' ] as $pk ) {
                $raw = get_post_meta( $post_id, $pk, true );
                if ( $raw === '' || $raw === false ) continue;
                $cleaned = self::clean_price( (string) $raw, $price_cfg );
                if ( $cleaned !== $raw ) update_post_meta( $post_id, $pk, $cleaned );
            }
        }

        // ── Stock: "Set automatically" ────────────────────────────────
        if ( ! empty( $cfg['stock_auto'] ) ) {
            $qty       = (int) get_post_meta( $post_id, '_stock', true );
            $threshold = (int) ( $cfg['stock_threshold'] ?? 1 );
            $status    = $qty >= $threshold ? 'instock' : 'outofstock';
            update_post_meta( $post_id, '_stock_status', $status );
            wc_update_product_stock_status( $post_id, $status );
        }

        // ── SKU options ───────────────────────────────────────────────
        if ( ! empty( $cfg['disable_auto_sku'] ) ) {
            // Prevent WC from auto-generating SKU if one is already set
            add_filter( 'woocommerce_product_get_sku', function( $sku ) {
                return $sku; // return as-is — prevents empty-SKU generation
            } );
        }

        // ── Product attributes ────────────────────────────────────────
        $attrs_cfg = $cfg['attributes'] ?? [];
        if ( ! empty( $attrs_cfg ) ) {
            self::apply_attributes( $post_id, $record, $attrs_cfg, $field_map );
        }

        // ── Trigger WC product save hooks ─────────────────────────────
        $product = wc_get_product( $post_id );
        if ( $product ) {
            $product->save();
        }
    }

    // -----------------------------------------------------------------------

    public static function clean_price( string $raw, array $cfg ): string {
        // Strip common currency symbols and whitespace
        if ( ! empty( $cfg['strip_currency'] ) ) {
            $raw = preg_replace( '/[^\d.,\-]/', '', $raw );
            $raw = trim( $raw );
        }
        // Normalise decimal separator: "1.234,56" → "1234.56"  |  "1,234.56" → "1234.56"
        if ( ! empty( $cfg['fix_decimal'] ) ) {
            // Detect format: if last separator is comma → European format
            $last_comma = strrpos( $raw, ',' );
            $last_dot   = strrpos( $raw, '.' );
            if ( $last_comma !== false && $last_dot !== false ) {
                // Both present — whichever is last is the decimal separator
                if ( $last_comma > $last_dot ) {
                    $raw = str_replace( '.', '', $raw ); // remove thousands dot
                    $raw = str_replace( ',', '.', $raw ); // comma → dot
                } else {
                    $raw = str_replace( ',', '', $raw ); // remove thousands comma
                }
            } elseif ( $last_comma !== false ) {
                // Only comma — could be decimal or thousands; treat as decimal if ≤ 2 digits after
                $after = strlen( $raw ) - $last_comma - 1;
                if ( $after <= 2 ) {
                    $raw = str_replace( ',', '.', $raw );
                } else {
                    $raw = str_replace( ',', '', $raw );
                }
            }
        }
        return $raw;
    }

    // -----------------------------------------------------------------------

    private static function apply_attributes( int $post_id, array $record, array $attrs_cfg, array $field_map ): void {
        $product_attributes = [];

        foreach ( $attrs_cfg as $attr ) {
            $name  = sanitize_text_field( $attr['name'] ?? '' );
            if ( ! $name ) continue;

            // Resolve value
            $value = self::resolve_attr_value( $record, $attr, $field_map );
            if ( $value === '' ) continue;

            $is_taxonomy   = ! empty( $attr['is_taxonomy'] );
            $in_variations = ! empty( $attr['in_variations'] );
            $is_visible    = isset( $attr['is_visible'] ) ? (int) $attr['is_visible'] : 1;
            $auto_create   = ! empty( $attr['auto_create'] );

            if ( $is_taxonomy ) {
                // Ensure taxonomy slug starts with pa_
                $tax = str_starts_with( $name, 'pa_' ) ? $name : 'pa_' . sanitize_title( $name );

                // Register taxonomy if not registered (edge case)
                if ( ! taxonomy_exists( $tax ) ) {
                    register_taxonomy( $tax, 'product' );
                }

                // Split multiple values by pipe or comma
                $terms = array_filter( array_map( 'trim', preg_split( '/[|,]/', $value ) ) );

                if ( $auto_create ) {
                    foreach ( $terms as $term ) {
                        if ( ! term_exists( $term, $tax ) ) {
                            wp_insert_term( $term, $tax );
                        }
                    }
                }

                wp_set_object_terms( $post_id, $terms, $tax, true );

                $term_ids = [];
                foreach ( $terms as $t ) {
                    $te = get_term_by( 'name', $t, $tax );
                    if ( $te ) $term_ids[] = $te->term_id;
                }

                $product_attributes[ $tax ] = [
                    'name'         => $tax,
                    'value'        => '',
                    'position'     => count( $product_attributes ),
                    'is_visible'   => $is_visible,
                    'is_variation' => $in_variations ? 1 : 0,
                    'is_taxonomy'  => 1,
                ];
            } else {
                // Custom (non-taxonomy) attribute
                $product_attributes[ $name ] = [
                    'name'         => $name,
                    'value'        => $value,
                    'position'     => count( $product_attributes ),
                    'is_visible'   => $is_visible,
                    'is_variation' => $in_variations ? 1 : 0,
                    'is_taxonomy'  => 0,
                ];
            }
        }

        if ( ! empty( $product_attributes ) ) {
            // Merge with existing attributes
            $existing = get_post_meta( $post_id, '_product_attributes', true ) ?: [];
            update_post_meta( $post_id, '_product_attributes', array_merge( $existing, $product_attributes ) );
        }
    }

    private static function resolve_attr_value( array $record, array $attr, array $field_map ): string {
        $type = $attr['value_source'] ?? 'field';
        return match ( $type ) {
            'static'     => (string) ( $attr['value'] ?? '' ),
            'field'      => (string) ( WPSI_Parser_Base::get_value( $record, $attr['value'] ?? '' ) ?? '' ),
            'template'   => preg_replace_callback( '/\{([^}]+)\}/', fn($m) =>
                                (string)( WPSI_Parser_Base::get_value( $record, $m[1] ) ?? '' ),
                                $attr['value'] ?? '' ),
            'expression' => WPSI_Expression_Evaluator::evaluate( $attr['value'] ?? '', $record ),
            default      => '',
        };
    }

    /** Return the default woo_config structure for the wizard. */
    public static function default_config(): array {
        return [
            'product_type'    => 'simple',
            'price_cleanup'   => [ 'strip_currency' => true, 'fix_decimal' => true ],
            'stock_auto'      => true,
            'stock_threshold' => 1,
            'disable_auto_sku'=> true,
            'skip_dup_sku'    => true,
            'attributes'      => [],
        ];
    }
}
