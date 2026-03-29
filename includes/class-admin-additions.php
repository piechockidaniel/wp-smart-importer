<?php
/**
 * Additional AJAX handlers for v2 features.
 * These are appended to WPSI_Admin::init() hook registrations.
 */
defined( 'ABSPATH' ) || exit;

// These actions are registered inside WPSI_Admin::init() additions:
// wp_ajax_wpsi_dry_run
// wp_ajax_wpsi_save_function
// wp_ajax_wpsi_load_function
// wp_ajax_wpsi_save_template
// wp_ajax_wpsi_load_templates
// wp_ajax_wpsi_delete_template

// Handler implementations (to be merged into class-admin.php):

function wpsi_ajax_dry_run(): void {
    if ( !current_user_can('manage_options') ) wp_die('Forbidden', 403);
    check_ajax_referer('wpsi_nonce','nonce');

    $job_id = (int)($_POST['job_id'] ?? 0);
    $limit  = max(1, min(20, (int)($_POST['limit'] ?? 5)));
    $job    = WPSI_Database::get_job($job_id);

    if (!$job) {
        // Allow passing a full job config inline (for wizard preview before save)
        $raw = stripslashes($_POST['job'] ?? '');
        $job = $raw ? json_decode($raw, true) : null;
    }

    if (!$job) { wp_send_json_error(['message' => 'Job not found.']); return; }

    if (is_string($job['field_map'] ?? '')) {
        $job['field_map'] = json_decode(stripslashes($job['field_map'] ?? '[]'), true);
    }
    $job['dry_run_limit'] = $limit;

    $result = WPSI_Dry_Run::run($job, $limit);
    wp_send_json_success($result);
}
add_action('wp_ajax_wpsi_dry_run', 'wpsi_ajax_dry_run');

function wpsi_ajax_save_function(): void {
    if ( !current_user_can('manage_options') ) wp_die('Forbidden', 403);
    check_ajax_referer('wpsi_nonce','nonce');

    $job_id = (int)($_POST['job_id'] ?? 0);
    $code   = stripslashes($_POST['code'] ?? '');
    if (!$job_id) { wp_send_json_error(['message' => 'Job ID required.']); return; }

    $ok = WPSI_Function_Editor::save($job_id, $code);
    wp_send_json_success(['saved' => $ok]);
}
add_action('wp_ajax_wpsi_save_function', 'wpsi_ajax_save_function');

function wpsi_ajax_load_function(): void {
    if ( !current_user_can('manage_options') ) wp_die('Forbidden', 403);
    check_ajax_referer('wpsi_nonce','nonce');

    $job_id = (int)($_POST['job_id'] ?? 0);
    $code   = $job_id ? WPSI_Function_Editor::load($job_id) : WPSI_Function_Editor::starter_template();
    wp_send_json_success(['code' => $code]);
}
add_action('wp_ajax_wpsi_load_function', 'wpsi_ajax_load_function');

function wpsi_ajax_save_function_template(): void {
    if ( !current_user_can('manage_options') ) wp_die('Forbidden', 403);
    check_ajax_referer('wpsi_nonce','nonce');

    $name = sanitize_text_field($_POST['template_name'] ?? '');
    $code = stripslashes($_POST['code'] ?? '');
    if (!$name) { wp_send_json_error(['message' => 'Template name required.']); return; }

    WPSI_Function_Editor::save_template($name, $code);
    wp_send_json_success(['templates' => WPSI_Function_Editor::get_templates()]);
}
add_action('wp_ajax_wpsi_save_function_template', 'wpsi_ajax_save_function_template');

function wpsi_ajax_load_function_templates(): void {
    if ( !current_user_can('manage_options') ) wp_die('Forbidden', 403);
    check_ajax_referer('wpsi_nonce','nonce');
    wp_send_json_success(['templates' => WPSI_Function_Editor::get_templates()]);
}
add_action('wp_ajax_wpsi_load_function_templates', 'wpsi_ajax_load_function_templates');

function wpsi_ajax_delete_function_template(): void {
    if ( !current_user_can('manage_options') ) wp_die('Forbidden', 403);
    check_ajax_referer('wpsi_nonce','nonce');

    $name = sanitize_text_field($_POST['template_name'] ?? '');
    if ($name) WPSI_Function_Editor::delete_template($name);
    wp_send_json_success(['templates' => WPSI_Function_Editor::get_templates()]);
}
add_action('wp_ajax_wpsi_delete_function_template', 'wpsi_ajax_delete_function_template');
