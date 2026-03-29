<?php
/**
 * Plugin Name: WP Smart Importer
 * Plugin URI:  https://github.com/YOUR_REPO
 * Description: Import posts/CPT from remote URLs (JSON, XML, CSV) with visual field mapper, WooCommerce Add-On, image importer, expression engine, function editor and scheduler.
 * Version:     2.0.0
 * Author:      Daniel
 * Text Domain: wp-smart-importer
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */
defined( 'ABSPATH' ) || exit;

define( 'WPSI_VERSION',     '2.0.0' );
define( 'WPSI_PLUGIN_FILE', __FILE__ );
define( 'WPSI_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WPSI_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Core
require_once WPSI_PLUGIN_DIR . 'includes/class-database.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-parser.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-enum-registry.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-expression-evaluator.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-mapper.php';

// Feature modules
require_once WPSI_PLUGIN_DIR . 'includes/class-sync-lock.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-function-editor.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-image-importer.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-woo-addon.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-taxonomy-importer.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-dry-run.php';

// Runtime
require_once WPSI_PLUGIN_DIR . 'includes/class-importer.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once WPSI_PLUGIN_DIR . 'includes/class-admin.php';

// AJAX additions (function editor, dry-run)
require_once WPSI_PLUGIN_DIR . 'includes/class-admin-additions.php';

register_activation_hook( __FILE__, [ 'WPSI_Database', 'install' ] );
register_deactivation_hook( __FILE__, [ 'WPSI_Scheduler', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    WPSI_Admin::init();
    WPSI_Scheduler::init();
    WPSI_Sync_Lock::init();
} );
