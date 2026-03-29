<?php
/**
 * WPSI_Importer (v2)
 * 
 * Full import pipeline integrating:
 *  - Image importer
 *  - WooCommerce Add-On
 *  - Function Editor hooks
 *  - Enhanced taxonomy import
 *  - Dry-run mode
 *  - Per-field update control
 *  - Skip-unchanged (hash)
 *  - Sync-lock check
 *  - Batch processing
 */
defined( 'ABSPATH' ) || exit;

class WPSI_Importer {

    public static function run( array $job, bool $dry_run = false ): array {
        if ( $dry_run ) {
            $limit = (int) ( $job['dry_run_limit'] ?? 5 );
            return WPSI_Dry_Run::run( $job, $limit );
        }

        $log_id    = WPSI_Database::start_log( (int) $job['id'] );
        $log_lines = [];
        $stats     = [ 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                        'failed' => 0, 'unchanged' => 0, 'locked' => 0 ];

        // ── Email suppression ─────────────────────────────────────────
        if ( ! empty( $job['suppress_emails'] ) ) {
            add_filter( 'woocommerce_email_enabled_new_order',               '__return_false' );
            add_filter( 'woocommerce_email_enabled_customer_completed_order','__return_false' );
            add_filter( 'woocommerce_email_enabled_customer_processing_order','__return_false' );
            add_filter( 'woocommerce_defer_transactional_emails',            '__return_true' );
        }

        // ── Hook suppression ──────────────────────────────────────────
        if ( ! empty( $job['disable_hooks'] ) ) {
            self::remove_heavy_hooks();
        }

        // ── Include per-job function file ─────────────────────────────
        WPSI_Function_Editor::include_for_job( (int) $job['id'], $job['post_type'] ?? 'post', false );

        try {
            // 1. Fetch
            $content = WPSI_Parser_Base::fetch_url( $job['source_url'], $job['auth_type'] ?? 'none', $job['auth_value'] ?? '' );
            if ( is_wp_error( $content ) ) throw new \RuntimeException( $content->get_error_message() );

            // 2. Parse
            $parser  = WPSI_Parser::make( $job['file_type'] );
            $records = $parser->parse( $content, $job['root_path'] ?? '' );

            // 3. Filter
            if ( ! empty( $job['xpath_filter'] ) ) {
                $records = self::apply_xpath_filter( $records, $job['xpath_filter'], $job['file_type'] );
            }
            if ( ! empty( $job['import_only_ids'] ) ) {
                $ids     = array_map( 'trim', explode( ',', $job['import_only_ids'] ) );
                $records = array_values( array_filter( $records, fn($r,$i) => in_array((string)($i+1),$ids,true), ARRAY_FILTER_USE_BOTH ) );
            }

            $stats['total'] = count( $records );
            $log_lines[]    = "Fetched {$stats['total']} records from {$job['source_url']}";

            // 4. Decode configs
            $field_map     = self::maybe_decode( $job['field_map']     ?? [] );
            $update_fields = self::maybe_decode( $job['update_fields'] ?? [] );
            $meta_fields   = self::maybe_decode( $job['meta_fields']   ?? [] );
            $image_config  = self::maybe_decode( $job['image_config']  ?? [] );
            $woo_config    = self::maybe_decode( $job['woo_config']    ?? [] );
            $tax_configs   = self::maybe_decode( $job['tax_configs']   ?? [] );

            if ( empty( $field_map ) ) throw new \RuntimeException( 'Field map is empty.' );

            $batch_size = max( 1, (int) ( $job['batch_size'] ?? 20 ) );

            // 5. Process in batches
            foreach ( array_chunk( $records, $batch_size ) as $batch ) {
                foreach ( $batch as $record ) {
                    try {
                        $result = self::process_record(
                            $record, $field_map, $job,
                            $update_fields, $meta_fields,
                            $image_config, $woo_config, $tax_configs
                        );
                        $stats[ $result ]++;
                        $log_lines[] = "Record: {$result}";
                    } catch ( \Throwable $e ) {
                        $stats['failed']++;
                        $log_lines[] = 'FAILED – ' . $e->getMessage();
                    }
                }
                wp_cache_flush();
            }

        } catch ( \Throwable $e ) {
            $log_lines[] = 'FATAL: ' . $e->getMessage();
        }

        WPSI_Database::finish_log( $log_id, $stats, implode( "\n", $log_lines ) );
        WPSI_Database::update_run_times( (int) $job['id'], WPSI_Scheduler::calc_next_run( $job ) );

        return $stats;
    }

    // -----------------------------------------------------------------------

    private static function process_record(
        array $record, array $field_map, array $job,
        array $update_fields, array $meta_fields,
        array $image_config, array $woo_config, array $tax_configs
    ): string {

        $mapped    = WPSI_Mapper::apply( $record, $field_map );
        $post_data = array_merge( $mapped['post_data'], [
            'post_type'   => $job['post_type'] ?? 'post',
            'post_status' => $mapped['post_data']['post_status'] ?? 'publish',
        ] );
        $meta = $mapped['meta'];
        $tax  = $mapped['tax'];

        // ── Sync-lock pre-check ───────────────────────────────────────
        $uid_template = $job['unique_identifier'] ?? '';
        $existing_id  = null;

        if ( $uid_template !== '' ) {
            $uid_value = preg_replace_callback( '/\{([^}]+)\}/', fn($m) =>
                (string)( WPSI_Parser_Base::get_value($record,$m[1]) ?? '' ), $uid_template );
            if ( $uid_value !== '' ) {
                $uid_field   = self::quick_uid_field( $uid_template, $field_map );
                $existing_id = self::find_existing_id( $job['post_type'] ?? 'post', $uid_field, $uid_value );
                if ( $existing_id && WPSI_Sync_Lock::is_locked( $existing_id ) ) {
                    return 'locked';
                }
            }
        }

        // ── Apply Function Editor transform ───────────────────────────
        $mapped_merged = compact( 'post_data', 'meta', 'tax' );
        $transformed   = WPSI_Function_Editor::transform( $mapped_merged, $record, $existing_id ?? 0, (int) $job['id'] );
        $post_data     = $transformed['post_data'] ?? $post_data;
        $meta          = $transformed['meta']      ?? $meta;
        $tax           = $transformed['tax']       ?? $tax;

        // ── Duplicate handling ────────────────────────────────────────
        $on_duplicate = $job['on_duplicate'] ?? 'skip';

        if ( $existing_id ) {
            if ( $on_duplicate === 'skip' ) return 'skipped';

            if ( $on_duplicate === 'update' ) {
                if ( ! empty( $job['skip_unchanged'] ) ) {
                    $new_hash = md5( serialize( array_merge( $post_data, $meta ) ) );
                    if ( get_post_meta( $existing_id, '_wpsi_hash', true ) === $new_hash ) return 'unchanged';
                    update_post_meta( $existing_id, '_wpsi_hash', $new_hash );
                }
                $post_data = self::filter_fields( $post_data, $job['update_strategy'] ?? 'all', $update_fields );
                $meta      = self::filter_fields( $meta,      $job['meta_strategy']   ?? 'all', $meta_fields );
                $post_data['ID'] = $existing_id;
                $result    = wp_update_post( $post_data, true );
                if ( is_wp_error( $result ) ) throw new \RuntimeException( $result->get_error_message() );
                self::apply_meta_and_tax( $existing_id, $meta, $tax );
                self::apply_images( $existing_id, $record, $image_config );
                self::apply_woo( $existing_id, $record, $woo_config, $field_map );
                self::apply_tax_configs( $existing_id, $meta, $tax_configs );
                WPSI_Sync_Lock::stamp( $existing_id, (int) $job['id'] );
                return 'updated';
            }
            // create mode — fall through
        }

        // ── Insert new post ───────────────────────────────────────────
        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) throw new \RuntimeException( $post_id->get_error_message() );

        if ( ! empty( $job['skip_unchanged'] ) ) {
            update_post_meta( $post_id, '_wpsi_hash', md5( serialize( array_merge( $post_data, $meta ) ) ) );
        }

        self::apply_meta_and_tax( $post_id, $meta, $tax );
        self::apply_images( $post_id, $record, $image_config );
        self::apply_woo( $post_id, $record, $woo_config, $field_map );
        self::apply_tax_configs( $post_id, $meta, $tax_configs );
        WPSI_Sync_Lock::stamp( $post_id, (int) $job['id'] );

        return 'created';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private static function apply_images( int $post_id, array $record, array $cfg ): void {
        if ( empty( $cfg ) || ( $cfg['mode'] ?? 'none' ) === 'none' ) return;
        WPSI_Image_Importer::run( $post_id, $record, $cfg );
    }

    private static function apply_woo( int $post_id, array $record, array $cfg, array $field_map ): void {
        if ( empty( $cfg ) ) return;
        WPSI_Woo_Addon::apply( $post_id, $record, $cfg, $field_map );
    }

    private static function apply_tax_configs( int $post_id, array $meta, array $tax_configs ): void {
        foreach ( $tax_configs as $tc ) {
            $src = $tc['value_source'] ?? '';
            $val = $meta[ $src ] ?? ( WPSI_Parser_Base::get_value( [], $src ) ?? '' );
            if ( $val === '' ) continue;
            WPSI_Taxonomy_Importer::apply( $post_id, (string) $val, $tc );
        }
    }

    private static function apply_meta_and_tax( int $post_id, array $meta, array $tax ): void {
        foreach ( $meta as $key => $value ) update_post_meta( $post_id, $key, $value );
        foreach ( $tax  as $taxonomy => $terms ) wp_set_post_terms( $post_id, $terms, $taxonomy );
    }

    private static function filter_fields( array $fields, string $strategy, array $list ): array {
        if ( $strategy === 'all' || empty( $list ) ) return $fields;
        return $strategy === 'whitelist'
            ? array_intersect_key( $fields, array_flip( $list ) )
            : array_diff_key( $fields, array_flip( $list ) );
    }

    private static function find_existing_id( string $post_type, string $key, string $value ): ?int {
        global $wpdb;
        if ( in_array( $key, WPSI_Mapper::CORE_FIELDS, true ) ) {
            $id = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND {$key}=%s LIMIT 1", $post_type, $value ) );
        } else {
            $id = $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
                 WHERE p.post_type=%s AND pm.meta_key=%s AND pm.meta_value=%s LIMIT 1",
                $post_type, $key, $value ) );
        }
        return $id ? (int) $id : null;
    }

    private static function quick_uid_field( string $template, array $field_map ): string {
        if ( preg_match( '/\{([^}]+)\}/', $template, $m ) ) {
            foreach ( $field_map as $r ) {
                if ( ( $r['source'] ?? '' ) === $m[1] ) return $r['destination'] ?? $m[1];
            }
            return $m[1];
        }
        return $template;
    }

    private static function maybe_decode( mixed $val ): array {
        if ( is_string( $val ) ) { $d = json_decode( $val, true ); return is_array( $d ) ? $d : []; }
        return is_array( $val ) ? $val : [];
    }

    private static function apply_xpath_filter( array $records, string $filter, string $type ): array {
        return array_values( array_filter( $records, function( $r ) use ( $filter ) {
            $or = preg_split( '/\bor\b/i', $filter );
            foreach ( $or as $and_expr ) {
                $and = preg_split( '/\band\b/i', $and_expr );
                $ok  = true;
                foreach ( $and as $c ) {
                    $c = trim($c);
                    if ( preg_match("/^(.+?)\s*=\s*['\"](.+?)['\"]\s*$/", $c, $m) ) {
                        if ( (string)(WPSI_Parser_Base::get_value($r,trim($m[1]))??'') !== $m[2] ) { $ok=false; break; }
                    } elseif ( preg_match("/^(.+?)\s+contains\s+['\"](.+?)['\"]\s*$/i", $c, $m) ) {
                        if ( !str_contains((string)(WPSI_Parser_Base::get_value($r,trim($m[1]))??''),$m[2]) ) { $ok=false; break; }
                    }
                }
                if ( $ok ) return true;
            }
            return false;
        } ) );
    }

    private static function remove_heavy_hooks(): void {
        // Disable WC reindex hooks during bulk import for speed
        if ( class_exists('WC_Product_Data_Store_CPT') ) {
            remove_action('save_post', ['WC_Product_Data_Store_CPT', 'save_product_price'], 10);
        }
    }
}
