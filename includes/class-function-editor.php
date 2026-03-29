<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPSI_Function_Editor
 *
 * Saves per-job PHP functions to a real file in wp-content/uploads/wpsi-functions/
 * and includes that file during import. NO eval() is used.
 *
 * The user writes normal PHP code. A standard hook is available:
 *
 *   add_filter( 'wpsi_transform_record', function( $mapped, $record, $post_id, $job_id ) {
 *       // $mapped = [ 'post_data' => [...], 'meta' => [...], 'tax' => [...] ]
 *       // modify and return $mapped
 *       return $mapped;
 *   }, 10, 4 );
 *
 * The file also receives constants:
 *   WPSI_JOB_ID      – current job ID
 *   WPSI_POST_TYPE   – post type being imported
 *   WPSI_DRY_RUN     – true during dry-run
 *
 * Functions are stored at:
 *   {uploads}/wpsi-functions/job-{id}.php
 */
class WPSI_Function_Editor {

    const DIR_SLUG = 'wpsi-functions';

    /** Return the directory path (creates it if needed). */
    public static function functions_dir(): string {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . self::DIR_SLUG;
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            // Protect directory from direct browsing
            file_put_contents( $dir . '/.htaccess', "Order Deny,Allow\nDeny from All\n" );
            file_put_contents( $dir . '/index.php', '<?php // Silence is golden' );
        }
        return $dir;
    }

    /** Return the path for a specific job's function file. */
    public static function job_file( int $job_id ): string {
        return self::functions_dir() . '/job-' . $job_id . '.php';
    }

    /**
     * Save function code for a job.
     * Prepends a safety header and wraps in try-catch.
     */
    public static function save( int $job_id, string $code ): bool {
        $code = trim( $code );
        if ( $code === '' ) {
            // Remove file if code is cleared
            $file = self::job_file( $job_id );
            if ( file_exists( $file ) ) unlink( $file );
            return true;
        }

        // Strip opening PHP tag if user included it
        $code = preg_replace( '/^\s*<\?php\s*/i', '', $code );

        $header = "<?php\n"
            . "// WP Smart Importer — Job {$job_id} Functions\n"
            . "// Auto-generated. Do not edit manually.\n"
            . "defined('ABSPATH') || exit;\n\n";

        $content = $header . $code . "\n";

        return (bool) file_put_contents( self::job_file( $job_id ), $content );
    }

    /** Load function code for a job (returns raw user code without header). */
    public static function load( int $job_id ): string {
        $file = self::job_file( $job_id );
        if ( ! file_exists( $file ) ) return '';
        $raw = file_get_contents( $file );
        // Strip the auto-generated header (first 5 lines)
        $lines = explode( "\n", $raw );
        $code  = implode( "\n", array_slice( $lines, 5 ) );
        return trim( $code );
    }

    /**
     * Include the job's function file.
     * Called once per import run, before the loop.
     * Returns true if the file was included.
     */
    public static function include_for_job( int $job_id, string $post_type = 'post', bool $dry_run = false ): bool {
        $file = self::job_file( $job_id );
        if ( ! file_exists( $file ) ) return false;

        // Define constants available inside the function file
        if ( ! defined( 'WPSI_JOB_ID' ) )    define( 'WPSI_JOB_ID',    $job_id );
        if ( ! defined( 'WPSI_POST_TYPE' ) )  define( 'WPSI_POST_TYPE', $post_type );
        if ( ! defined( 'WPSI_DRY_RUN' ) )    define( 'WPSI_DRY_RUN',   $dry_run );

        try {
            include_once $file;
        } catch ( \Throwable $e ) {
            // Log the error but don't crash the whole import
            error_log( "WPSI Function Editor error (job {$job_id}): " . $e->getMessage() );
            return false;
        }
        return true;
    }

    /**
     * Apply the wpsi_transform_record filter.
     * Called per record after normal mapping, before saving.
     *
     * @param array $mapped  { post_data, meta, tax }
     * @param array $record  Raw parsed record
     * @param int   $post_id 0 for new, existing ID for updates
     * @param int   $job_id
     * @return array Modified $mapped
     */
    public static function transform( array $mapped, array $record, int $post_id, int $job_id ): array {
        return apply_filters( 'wpsi_transform_record', $mapped, $record, $post_id, $job_id );
    }

    /** Delete function file when a job is deleted. */
    public static function delete( int $job_id ): void {
        $file = self::job_file( $job_id );
        if ( file_exists( $file ) ) unlink( $file );
    }

    // -----------------------------------------------------------------------
    // Template system
    // -----------------------------------------------------------------------

    /** Save the current function code as a named template. */
    public static function save_template( string $name, string $code ): void {
        $templates         = self::get_templates();
        $templates[ $name ] = $code;
        update_option( 'wpsi_function_templates', $templates );
    }

    /** Return all saved templates [ name => code ]. */
    public static function get_templates(): array {
        return get_option( 'wpsi_function_templates', [] );
    }

    /** Delete a named template. */
    public static function delete_template( string $name ): void {
        $templates = self::get_templates();
        unset( $templates[ $name ] );
        update_option( 'wpsi_function_templates', $templates );
    }

    /** Starter template shown in the editor for new jobs. */
    public static function starter_template(): string {
        return <<<'PHP'
/**
 * Transform mapped data before it is saved to WordPress.
 *
 * @param array $mapped   Keys: post_data, meta, tax
 * @param array $record   Raw parsed record from source
 * @param int   $post_id  0 for new posts, existing ID for updates
 * @param int   $job_id   Current import job ID
 * @return array          Modified $mapped
 */
add_filter( 'wpsi_transform_record', function( $mapped, $record, $post_id, $job_id ) {

    // Example: derive _stock_status from a "dostepnosc" field
    // $qty = (int) ( $record['stock'] ?? 0 );
    // $mapped['meta']['_stock_status'] = $qty > 0 ? 'instock' : 'outofstock';

    // Example: prefix the post title
    // $mapped['post_data']['post_title'] = '[Import] ' . $mapped['post_data']['post_title'];

    return $mapped;

}, 10, 4 );
PHP;
    }
}
