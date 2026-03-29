<?php
defined( 'ABSPATH' ) || exit;

class WPSI_Database {
    const JOBS_TABLE = 'wpsi_jobs';
    const LOGS_TABLE = 'wpsi_logs';

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $jobs    = $wpdb->prefix . self::JOBS_TABLE;
        $logs    = $wpdb->prefix . self::LOGS_TABLE;

        $sql = "
        CREATE TABLE IF NOT EXISTS {$jobs} (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name               VARCHAR(255)    NOT NULL,
            friendly_name      VARCHAR(255)    DEFAULT NULL,
            source_url         TEXT            NOT NULL,
            file_type          ENUM('json','xml','csv') NOT NULL DEFAULT 'json',
            auth_type          ENUM('none','basic','bearer') NOT NULL DEFAULT 'none',
            auth_value         TEXT            DEFAULT NULL,
            root_path          VARCHAR(255)    DEFAULT NULL,
            xpath_filter       TEXT            DEFAULT NULL,
            post_type          VARCHAR(100)    NOT NULL DEFAULT 'post',
            field_map          LONGTEXT        NOT NULL,
            unique_identifier  TEXT            DEFAULT NULL,
            on_duplicate       ENUM('create','update','skip') NOT NULL DEFAULT 'skip',
            skip_unchanged     TINYINT(1)      NOT NULL DEFAULT 0,
            update_strategy    ENUM('all','whitelist','blacklist') NOT NULL DEFAULT 'all',
            update_fields      TEXT            DEFAULT NULL,
            meta_strategy      ENUM('all','whitelist','blacklist') NOT NULL DEFAULT 'all',
            meta_fields        TEXT            DEFAULT NULL,
            image_config       LONGTEXT        DEFAULT NULL,
            woo_config         LONGTEXT        DEFAULT NULL,
            tax_configs        LONGTEXT        DEFAULT NULL,
            schedule_type      ENUM('none','automatic','cron') NOT NULL DEFAULT 'none',
            schedule_days      VARCHAR(50)     DEFAULT NULL,
            schedule_times     TEXT            DEFAULT NULL,
            schedule_monthly   VARCHAR(10)     DEFAULT NULL,
            schedule_timezone  VARCHAR(50)     NOT NULL DEFAULT 'UTC',
            cron_expression    VARCHAR(100)    DEFAULT NULL,
            batch_size         SMALLINT UNSIGNED NOT NULL DEFAULT 20,
            disable_hooks      TINYINT(1)      NOT NULL DEFAULT 0,
            suppress_emails    TINYINT(1)      NOT NULL DEFAULT 1,
            import_only_ids    TEXT            DEFAULT NULL,
            status             ENUM('active','paused','draft') NOT NULL DEFAULT 'draft',
            last_run           DATETIME        DEFAULT NULL,
            next_run           DATETIME        DEFAULT NULL,
            created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE IF NOT EXISTS {$logs} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id      BIGINT UNSIGNED NOT NULL,
            started_at  DATETIME        NOT NULL,
            finished_at DATETIME        DEFAULT NULL,
            total       INT UNSIGNED    NOT NULL DEFAULT 0,
            created     INT UNSIGNED    NOT NULL DEFAULT 0,
            updated     INT UNSIGNED    NOT NULL DEFAULT 0,
            skipped     INT UNSIGNED    NOT NULL DEFAULT 0,
            failed      INT UNSIGNED    NOT NULL DEFAULT 0,
            unchanged   INT UNSIGNED    NOT NULL DEFAULT 0,
            locked      INT UNSIGNED    NOT NULL DEFAULT 0,
            log_text    LONGTEXT        DEFAULT NULL,
            PRIMARY KEY (id),
            KEY job_id (job_id)
        ) $charset;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Migrate existing installs — add new columns if missing
        $cols = $wpdb->get_col( "DESCRIBE {$jobs}", 0 );
        $new  = [ 'image_config', 'woo_config', 'tax_configs' ];
        foreach ( $new as $col ) {
            if ( ! in_array( $col, $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$jobs} ADD COLUMN {$col} LONGTEXT DEFAULT NULL" );
            }
        }

        update_option( 'wpsi_db_version', WPSI_VERSION );
    }

    public static function get_jobs(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}" . self::JOBS_TABLE . " ORDER BY id DESC", ARRAY_A ) ?: [];
    }

    public static function get_job( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::JOBS_TABLE . " WHERE id=%d", $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function save_job( array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . self::JOBS_TABLE;

        $fields = [
            'name'              => sanitize_text_field( $data['name']          ?? '' ),
            'friendly_name'     => sanitize_text_field( $data['friendly_name'] ?? '' ) ?: null,
            'source_url'        => esc_url_raw( $data['source_url'] ?? '' ),
            'file_type'         => in_array( $data['file_type']??'', ['json','xml','csv'] )              ? $data['file_type']     : 'json',
            'auth_type'         => in_array( $data['auth_type']??'', ['none','basic','bearer'] )         ? $data['auth_type']     : 'none',
            'auth_value'        => sanitize_text_field( $data['auth_value']    ?? '' ) ?: null,
            'root_path'         => sanitize_text_field( $data['root_path']     ?? '' ) ?: null,
            'xpath_filter'      => sanitize_textarea_field( $data['xpath_filter'] ?? '' ) ?: null,
            'post_type'         => sanitize_key( $data['post_type'] ?? 'post' ),
            'field_map'         => wp_json_encode( $data['field_map']    ?? [] ),
            'unique_identifier' => sanitize_text_field( $data['unique_identifier'] ?? '' ) ?: null,
            'on_duplicate'      => in_array( $data['on_duplicate']??'', ['create','update','skip'] )      ? $data['on_duplicate']  : 'skip',
            'skip_unchanged'    => (int)!empty( $data['skip_unchanged'] ),
            'update_strategy'   => in_array( $data['update_strategy']??'', ['all','whitelist','blacklist'] ) ? $data['update_strategy'] : 'all',
            'update_fields'     => wp_json_encode( $data['update_fields'] ?? [] ),
            'meta_strategy'     => in_array( $data['meta_strategy']??'', ['all','whitelist','blacklist'] )   ? $data['meta_strategy']   : 'all',
            'meta_fields'       => wp_json_encode( $data['meta_fields']  ?? [] ),
            'image_config'      => wp_json_encode( $data['image_config'] ?? [] ),
            'woo_config'        => wp_json_encode( $data['woo_config']   ?? [] ),
            'tax_configs'       => wp_json_encode( $data['tax_configs']  ?? [] ),
            'schedule_type'     => in_array( $data['schedule_type']??'', ['none','automatic','cron'] )    ? $data['schedule_type'] : 'none',
            'schedule_days'     => sanitize_text_field( $data['schedule_days']    ?? '' ) ?: null,
            'schedule_times'    => wp_json_encode( $data['schedule_times'] ?? [] ),
            'schedule_monthly'  => sanitize_text_field( $data['schedule_monthly'] ?? '' ) ?: null,
            'schedule_timezone' => sanitize_text_field( $data['schedule_timezone']?? 'UTC' ),
            'cron_expression'   => sanitize_text_field( $data['cron_expression']  ?? '' ) ?: null,
            'batch_size'        => max(1, min(1000, (int)($data['batch_size'] ?? 20))),
            'disable_hooks'     => (int)!empty( $data['disable_hooks'] ),
            'suppress_emails'   => (int)!empty( $data['suppress_emails'] ),
            'import_only_ids'   => sanitize_text_field( $data['import_only_ids'] ?? '' ) ?: null,
            'status'            => in_array( $data['status']??'', ['active','paused','draft'] )           ? $data['status']        : 'draft',
        ];

        if ( !empty( $data['id'] ) ) {
            $wpdb->update( $table, $fields, ['id' => (int)$data['id']] );
            $job_id = (int)$data['id'];
        } else {
            $wpdb->insert( $table, $fields );
            $job_id = (int)$wpdb->insert_id;
        }

        // Save function code to file
        if ( isset( $data['function_code'] ) ) {
            WPSI_Function_Editor::save( $job_id, stripslashes( $data['function_code'] ) );
        }

        return $job_id;
    }

    public static function delete_job( int $id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::JOBS_TABLE, ['id' => $id] );
        $wpdb->delete( $wpdb->prefix . self::LOGS_TABLE, ['job_id' => $id] );
        WPSI_Function_Editor::delete( $id );
    }

    public static function start_log( int $job_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::LOGS_TABLE, [ 'job_id' => $job_id, 'started_at' => current_time('mysql') ] );
        return (int)$wpdb->insert_id;
    }

    public static function finish_log( int $log_id, array $stats, string $log_text = '' ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . self::LOGS_TABLE, [
            'finished_at' => current_time('mysql'),
            'total'       => (int)($stats['total']     ?? 0),
            'created'     => (int)($stats['created']   ?? 0),
            'updated'     => (int)($stats['updated']   ?? 0),
            'skipped'     => (int)($stats['skipped']   ?? 0),
            'failed'      => (int)($stats['failed']    ?? 0),
            'unchanged'   => (int)($stats['unchanged'] ?? 0),
            'locked'      => (int)($stats['locked']    ?? 0),
            'log_text'    => $log_text,
        ], ['id' => $log_id] );
    }

    public static function get_logs( int $job_id, int $limit = 10 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::LOGS_TABLE . " WHERE job_id=%d ORDER BY id DESC LIMIT %d", $job_id, $limit ),
            ARRAY_A
        ) ?: [];
    }

    public static function update_run_times( int $job_id, ?string $next_run ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . self::JOBS_TABLE,
            ['last_run' => current_time('mysql'), 'next_run' => $next_run],
            ['id' => $job_id] );
    }

    public static function get_due_jobs(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}" . self::JOBS_TABLE .
            " WHERE status='active' AND schedule_type!='none' AND (next_run IS NULL OR next_run<=NOW())",
            ARRAY_A ) ?: [];
    }
}
