<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPSI_Taxonomy_Importer
 *
 * Enhanced taxonomy handling:
 *  - single term
 *  - multiple terms (comma or pipe separated)
 *  - hierarchical  (Sports > Golf > Clubs > Putters)
 *  - auto-create terms
 *  - value remapping (vendor value → WP term name)
 *
 * tax_config per taxonomy:
 * {
 *   "taxonomy"    : "product_cat",
 *   "mode"        : "single|multiple|hierarchical",
 *   "separator"   : ",",
 *   "hierarchy_sep": ">",
 *   "auto_create" : true,
 *   "match_child" : false,    // try to match existing child terms
 *   "value_map"   : {         // vendor value → WP term name
 *     "Elektronika" : "Electronics",
 *     "AGD"         : "Home Appliances"
 *   }
 * }
 */
class WPSI_Taxonomy_Importer {

    /**
     * Apply a taxonomy import config.
     *
     * @param int    $post_id
     * @param string $raw_value  Resolved value from source (after mapper)
     * @param array  $tax_config
     * @param bool   $append     Whether to add to existing terms (true) or replace (false)
     */
    public static function apply( int $post_id, string $raw_value, array $tax_config, bool $append = false ): void {
        $taxonomy    = sanitize_key( $tax_config['taxonomy'] ?? '' );
        if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) return;
        if ( $raw_value === '' ) return;

        $mode         = $tax_config['mode']          ?? 'single';
        $separator    = $tax_config['separator']     ?? ',';
        $hier_sep     = $tax_config['hierarchy_sep'] ?? '>';
        $auto_create  = (bool) ( $tax_config['auto_create']  ?? true );
        $match_child  = (bool) ( $tax_config['match_child']  ?? false );
        $value_map    = $tax_config['value_map']     ?? [];

        $term_ids = [];

        if ( $mode === 'hierarchical' ) {
            $parts    = array_filter( array_map( 'trim', explode( $hier_sep, $raw_value ) ) );
            $term_ids = self::ensure_hierarchy( $parts, $taxonomy, $auto_create, $value_map );

        } elseif ( $mode === 'multiple' ) {
            $values = array_filter( array_map( 'trim', explode( $separator, $raw_value ) ) );
            foreach ( $values as $v ) {
                $v      = $value_map[ $v ] ?? $v;
                $tid    = self::ensure_term( $v, $taxonomy, 0, $auto_create, $match_child );
                if ( $tid ) $term_ids[] = $tid;
            }

        } else {
            // single
            $v   = $value_map[ $raw_value ] ?? $raw_value;
            $tid = self::ensure_term( $v, $taxonomy, 0, $auto_create, $match_child );
            if ( $tid ) $term_ids[] = $tid;
        }

        if ( ! empty( $term_ids ) ) {
            wp_set_object_terms( $post_id, $term_ids, $taxonomy, $append );
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Ensure a hierarchy of terms exists and returns the deepest term ID.
     * e.g. ['Sports', 'Golf', 'Clubs'] → creates/finds each level.
     *
     * @return int[] IDs of all terms in the chain (for setting on post)
     */
    private static function ensure_hierarchy( array $parts, string $taxonomy, bool $auto_create, array $value_map ): array {
        $parent_id = 0;
        $ids       = [];

        foreach ( $parts as $part ) {
            $part = $value_map[ $part ] ?? $part;
            $tid  = self::ensure_term( $part, $taxonomy, $parent_id, $auto_create );
            if ( ! $tid ) break;
            $ids[]     = $tid;
            $parent_id = $tid;
        }

        // For WP: set all ancestor terms + the leaf term
        return $ids;
    }

    /**
     * Find or create a term. Returns term ID or 0.
     */
    private static function ensure_term( string $name, string $taxonomy, int $parent = 0, bool $auto_create = true, bool $match_child = false ): int {
        if ( $name === '' ) return 0;

        // Try exact match
        $args = $parent ? [ 'parent' => $parent ] : [];
        $term = get_term_by( 'name', $name, $taxonomy );

        // If match_child: look for a child term with this name
        if ( ! $term && $match_child ) {
            $children = get_terms( [ 'taxonomy' => $taxonomy, 'name' => $name, 'hide_empty' => false ] );
            if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
                $term = $children[0];
            }
        }

        if ( $term && ! is_wp_error( $term ) ) return (int) $term->term_id;

        // Create if not found
        if ( $auto_create ) {
            $insert_args = $parent ? [ 'parent' => $parent ] : [];
            $result = wp_insert_term( $name, $taxonomy, $insert_args );
            if ( ! is_wp_error( $result ) ) return (int) $result['term_id'];
        }

        return 0;
    }
}
