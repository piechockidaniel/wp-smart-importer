<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPSI_Dry_Run
 *
 * Runs the full import pipeline but intercepts all DB writes.
 * Returns a preview of what WOULD happen, record by record.
 *
 * Result per record:
 * {
 *   "action"   : "create|update|skip|locked|unchanged|error",
 *   "uid"      : "resolved unique identifier value",
 *   "post_data": { ... },      // what would be written to wp_posts
 *   "meta"     : { ... },      // what would be written to postmeta
 *   "tax"      : { ... },      // taxonomy terms
 *   "images"   : [ "url1", ... ],
 *   "error"    : null | "message"
 * }
 */
class WPSI_Dry_Run {

    /**
     * Run a dry-run preview for the first $limit records.
     *
     * @param array $job       Full job config array
     * @param int   $limit     Max records to preview (default 5)
     * @return array { records: [...], stats: {...}, errors: [...] }
     */
    public static function run( array $job, int $limit = 5 ): array {
        $results = [];
        $stats   = [ 'total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'locked' => 0, 'unchanged' => 0, 'error' => 0 ];
        $errors  = [];

        try {
            // 1. Fetch & parse (real)
            $content = WPSI_Parser_Base::fetch_url( $job['source_url'], $job['auth_type'] ?? 'none', $job['auth_value'] ?? '' );
            if ( is_wp_error( $content ) ) throw new \RuntimeException( $content->get_error_message() );

            $parser  = WPSI_Parser::make( $job['file_type'] );
            $records = $parser->parse( $content, $job['root_path'] ?? '' );

            if ( ! empty( $job['xpath_filter'] ) ) {
                $records = self::apply_filter( $records, $job['xpath_filter'] );
            }

            $stats['total'] = count( $records );
            $sample         = array_slice( $records, 0, $limit );

            $field_map     = is_string( $job['field_map'] )    ? json_decode( $job['field_map'], true )    : ( $job['field_map'] ?? [] );
            $update_fields = is_string( $job['update_fields'] ) ? json_decode( $job['update_fields'], true ) : ( $job['update_fields'] ?? [] );
            $meta_fields   = is_string( $job['meta_fields'] )   ? json_decode( $job['meta_fields'], true )   : ( $job['meta_fields'] ?? [] );
            $image_config  = is_string( $job['image_config'] ?? '' ) ? json_decode( $job['image_config'] ?? '', true ) : ( $job['image_config'] ?? [] );

            foreach ( $sample as $i => $record ) {
                try {
                    $result = self::preview_record( $record, $field_map, $job, $update_fields, $meta_fields, $image_config, $i + 1 );
                    $results[]       = $result;
                    $stats[ $result['action'] ] = ( $stats[ $result['action'] ] ?? 0 ) + 1;
                } catch ( \Throwable $e ) {
                    $results[] = [ 'action' => 'error', 'error' => $e->getMessage(), 'record_num' => $i + 1 ];
                    $stats['error']++;
                    $errors[]  = "Record #{$i}: " . $e->getMessage();
                }
            }

        } catch ( \Throwable $e ) {
            $errors[] = 'FATAL: ' . $e->getMessage();
        }

        return compact( 'results', 'stats', 'errors' );
    }

    // -----------------------------------------------------------------------

    private static function preview_record( array $record, array $field_map, array $job, array $update_fields, array $meta_fields, array $image_config, int $num ): array {
        $mapped    = WPSI_Mapper::apply( $record, $field_map );
        $post_data = array_merge( $mapped['post_data'], [
            'post_type'   => $job['post_type'] ?? 'post',
            'post_status' => $mapped['post_data']['post_status'] ?? 'publish',
        ] );
        $meta = $mapped['meta'];
        $tax  = $mapped['tax'];

        // Image URLs (resolved but NOT downloaded)
        $images = [];
        if ( ! empty( $image_config ) && ( $image_config['mode'] ?? 'none' ) !== 'none' ) {
            $source = $image_config['url_source'] ?? '';
            if ( $source ) {
                $raw = str_contains( $source, '{' )
                    ? preg_replace_callback( '/\{([^}]+)\}/', fn($m) => (string)( WPSI_Parser_Base::get_value( $record, $m[1] ) ?? '' ), $source )
                    : (string)( WPSI_Parser_Base::get_value( $record, $source ) ?? '' );
                $sep    = $image_config['url_separator'] ?? ',';
                $images = array_filter( array_map( 'trim', $sep ? explode( $sep, $raw ) : [ $raw ] ) );
            }
        }

        // Unique identifier
        $uid_template = $job['unique_identifier'] ?? '';
        $uid_value    = '';
        $action       = 'create';
        $existing_id  = null;

        if ( $uid_template !== '' ) {
            $uid_value = preg_replace_callback( '/\{([^}]+)\}/', fn($m) =>
                (string)( WPSI_Parser_Base::get_value( $record, $m[1] ) ?? '' ), $uid_template );

            if ( $uid_value !== '' ) {
                // Find existing post
                global $wpdb;
                $uid_field = preg_match( '/\{([^}]+)\}/', $uid_template, $m ) ? $m[1] : $uid_template;
                // Try meta first
                $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
                     WHERE p.post_type=%s AND pm.meta_key=%s AND pm.meta_value=%s LIMIT 1",
                    $job['post_type'] ?? 'post', $uid_field, $uid_value
                ) ) ?: null;

                if ( $existing_id ) {
                    if ( WPSI_Sync_Lock::is_locked( $existing_id ) ) {
                        $action = 'locked';
                    } elseif ( $job['on_duplicate'] === 'skip' ) {
                        $action = 'skip';
                    } elseif ( ! empty( $job['skip_unchanged'] ) ) {
                        $new_hash = md5( serialize( array_merge( $post_data, $meta ) ) );
                        $old_hash = get_post_meta( $existing_id, '_wpsi_hash', true );
                        $action   = $old_hash === $new_hash ? 'unchanged' : 'update';
                    } else {
                        $action = 'update';
                    }
                }
            }
        }

        return [
            'record_num'  => $num,
            'action'      => $action,
            'uid'         => $uid_value ?: '—',
            'existing_id' => $existing_id,
            'post_data'   => $post_data,
            'meta'        => $meta,
            'tax'         => $tax,
            'images'      => array_values( $images ),
            'error'       => null,
        ];
    }

    private static function apply_filter( array $records, string $filter ): array {
        return array_values( array_filter( $records, function( $r ) use ( $filter ) {
            $or_parts = preg_split( '/\bor\b/i', $filter );
            foreach ( $or_parts as $and_expr ) {
                $and_parts = preg_split( '/\band\b/i', $and_expr );
                $all = true;
                foreach ( $and_parts as $cond ) {
                    if ( preg_match( "/^(.+?)\s*=\s*['\"](.+?)['\"]\s*$/", trim($cond), $m ) ) {
                        if ( (string)( WPSI_Parser_Base::get_value($r, trim($m[1])) ?? '' ) !== $m[2] ) { $all = false; break; }
                    }
                }
                if ( $all ) return true;
            }
            return false;
        } ) );
    }
}
