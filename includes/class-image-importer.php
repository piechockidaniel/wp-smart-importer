<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPSI_Image_Importer
 *
 * Downloads remote images, deduplicates against Media Library,
 * sets featured image (_thumbnail_id), and writes alt/title/caption/description.
 *
 * image_config JSON per job:
 * {
 *   "mode"               : "download",    // download | media_library | none
 *   "url_source"         : "image_url",   // dot-path in record, or template
 *   "url_separator"      : ",",           // separator for multiple URLs
 *   "dedup"              : "url",         // url | filename | none
 *   "set_featured"       : true,
 *   "keep_existing"      : true,          // don't replace images on update
 *   "draft_if_no_image"  : false,
 *   "skip_thumbnails"    : false,
 *   "meta" : {
 *     "title"   : "",     // source path / template, or ""
 *     "alt"     : "",
 *     "caption" : "",
 *     "description" : ""
 *   }
 * }
 */
class WPSI_Image_Importer {

    public static function run( int $post_id, array $record, array $image_config ): array {
        $cfg = is_string( $image_config ) ? json_decode( $image_config, true ) : $image_config;
        if ( empty( $cfg ) || ( $cfg['mode'] ?? 'none' ) === 'none' ) return [];

        $urls = self::resolve_urls( $record, $cfg );
        if ( empty( $urls ) ) {
            if ( ! empty( $cfg['draft_if_no_image'] ) ) {
                wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
            }
            return [];
        }

        // Keep existing images on update?
        if ( ! empty( $cfg['keep_existing'] ) ) {
            $existing = get_post_thumbnail_id( $post_id );
            if ( $existing ) return [ $existing ];
        }

        $attachment_ids = [];
        $skip_thumbnails = ! empty( $cfg['skip_thumbnails'] );

        if ( $skip_thumbnails ) {
            add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
        }

        foreach ( $urls as $url ) {
            $url = trim( $url );
            if ( ! $url ) continue;

            $att_id = self::get_or_create_attachment( $url, $post_id, $cfg );
            if ( $att_id ) {
                $attachment_ids[] = $att_id;
                // Write meta for this attachment
                self::apply_attachment_meta( $att_id, $record, $cfg['meta'] ?? [] );
            }
        }

        if ( $skip_thumbnails ) {
            remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
        }

        // Set first image as featured
        if ( ! empty( $cfg['set_featured'] ) && ! empty( $attachment_ids ) ) {
            set_post_thumbnail( $post_id, $attachment_ids[0] );
        }

        // Attach remaining to post gallery (stored as comma-sep IDs in _product_image_gallery for WC)
        if ( count( $attachment_ids ) > 1 && class_exists( 'WooCommerce' ) ) {
            $gallery = implode( ',', array_slice( $attachment_ids, 1 ) );
            update_post_meta( $post_id, '_product_image_gallery', $gallery );
        }

        return $attachment_ids;
    }

    // -----------------------------------------------------------------------

    private static function resolve_urls( array $record, array $cfg ): array {
        $source = $cfg['url_source'] ?? '';
        if ( ! $source ) return [];

        // Template or plain field path
        if ( str_contains( $source, '{' ) ) {
            $raw = preg_replace_callback( '/\{([^}]+)\}/', fn($m) =>
                (string)( WPSI_Parser_Base::get_value( $record, $m[1] ) ?? '' ), $source );
        } else {
            $raw = (string)( WPSI_Parser_Base::get_value( $record, $source ) ?? '' );
        }

        $separator = $cfg['url_separator'] ?? ',';
        $urls      = $separator ? array_filter( array_map( 'trim', explode( $separator, $raw ) ) ) : [ $raw ];
        return array_values( $urls );
    }

    private static function get_or_create_attachment( string $url, int $post_id, array $cfg ): ?int {
        // 1. Deduplication check
        $dedup = $cfg['dedup'] ?? 'url';

        if ( $dedup === 'url' ) {
            $existing = self::find_by_url( $url );
            if ( $existing ) return $existing;
        } elseif ( $dedup === 'filename' ) {
            $filename = basename( parse_url( $url, PHP_URL_PATH ) );
            $existing = self::find_by_filename( $filename );
            if ( $existing ) return $existing;
        }

        // 2. Download
        if ( ( $cfg['mode'] ?? 'download' ) !== 'download' ) return null;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) return null;

        $filename  = sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ) );
        $file_array = [
            'name'     => $filename ?: 'image-' . time(),
            'tmp_name' => $tmp,
        ];

        $att_id = media_handle_sideload( $file_array, $post_id );
        @unlink( $tmp );

        if ( is_wp_error( $att_id ) ) return null;

        // Store original URL as meta for future dedup
        update_post_meta( $att_id, '_wpsi_source_url', $url );

        return (int) $att_id;
    }

    private static function find_by_url( string $url ): ?int {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpsi_source_url' AND meta_value = %s LIMIT 1",
            $url
        ) );
        if ( $id ) return (int) $id;

        // Also check WP's built-in _wp_attached_file (partial match on basename)
        $file = basename( parse_url( $url, PHP_URL_PATH ) );
        return self::find_by_filename( $file );
    }

    private static function find_by_filename( string $filename ): ?int {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
               AND pm.meta_key = '_wp_attached_file'
               AND pm.meta_value LIKE %s
             LIMIT 1",
            '%' . $wpdb->esc_like( $filename )
        ) );
        return $id ? (int) $id : null;
    }

    private static function apply_attachment_meta( int $att_id, array $record, array $meta_cfg ): void {
        $update = [];

        if ( ! empty( $meta_cfg['title'] ) ) {
            $update['post_title'] = self::resolve_meta_value( $record, $meta_cfg['title'] );
        }
        if ( ! empty( $meta_cfg['caption'] ) ) {
            $update['post_excerpt'] = self::resolve_meta_value( $record, $meta_cfg['caption'] );
        }
        if ( ! empty( $meta_cfg['description'] ) ) {
            $update['post_content'] = self::resolve_meta_value( $record, $meta_cfg['description'] );
        }

        if ( ! empty( $update ) ) {
            $update['ID'] = $att_id;
            wp_update_post( $update );
        }

        if ( ! empty( $meta_cfg['alt'] ) ) {
            update_post_meta( $att_id, '_wp_attachment_image_alt', self::resolve_meta_value( $record, $meta_cfg['alt'] ) );
        }
    }

    private static function resolve_meta_value( array $record, string $source ): string {
        if ( str_contains( $source, '{' ) ) {
            return preg_replace_callback( '/\{([^}]+)\}/', fn($m) =>
                (string)( WPSI_Parser_Base::get_value( $record, $m[1] ) ?? '' ), $source );
        }
        return (string)( WPSI_Parser_Base::get_value( $record, $source ) ?? $source );
    }

    /** Default config for new jobs */
    public static function default_config(): array {
        return [
            'mode'             => 'none',
            'url_source'       => '',
            'url_separator'    => ',',
            'dedup'            => 'url',
            'set_featured'     => true,
            'keep_existing'    => true,
            'draft_if_no_image'=> false,
            'skip_thumbnails'  => false,
            'meta'             => [ 'title' => '', 'alt' => '', 'caption' => '', 'description' => '' ],
        ];
    }
}
