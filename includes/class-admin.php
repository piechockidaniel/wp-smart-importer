<?php
defined('ABSPATH') || exit;
class WPSI_Admin {
    public static function init(): void {
        add_action('admin_menu',            [__CLASS__,'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__,'enqueue']);
        foreach(['fetch_preview','get_dest_fields','save_job','run_now','delete_job','get_job','toggle_status','test_expression','dry_run','save_function','load_function','save_function_template','load_function_templates','delete_function_template'] as $a)
            add_action("wp_ajax_wpsi_{$a}", [__CLASS__,"ajax_{$a}"]);
    }
    public static function add_menu(): void {
        add_menu_page(__('WP Smart Importer','wp-smart-importer'),__('Smart Importer','wp-smart-importer'),'manage_options','wp-smart-importer',[__CLASS__,'render_page'],'dashicons-database-import',56);
        add_submenu_page('wp-smart-importer',__('Import Jobs','wp-smart-importer'),__('Import Jobs','wp-smart-importer'),'manage_options','wp-smart-importer',[__CLASS__,'render_page']);
        add_submenu_page('wp-smart-importer',__('New Import','wp-smart-importer'),__('New Import','wp-smart-importer'),'manage_options','wp-smart-importer-wizard',[__CLASS__,'render_wizard']);
    }
    public static function enqueue(string $hook): void {
        if(!str_contains($hook,'wp-smart-importer'))return;
        wp_enqueue_style('wpsi-admin',WPSI_PLUGIN_URL.'admin/css/admin.css',[],WPSI_VERSION);
        wp_enqueue_script('wpsi-admin',WPSI_PLUGIN_URL.'admin/js/admin.js',['jquery','wp-util'],WPSI_VERSION,true);
        wp_localize_script('wpsi-admin','wpsiData',['ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wpsi_nonce'),'postTypes'=>self::post_types_js(),'enumHints'=>WPSI_Enum_Registry::all_keys_for_js('product')]);
    }
    public static function render_page(): void { $jobs=WPSI_Database::get_jobs(); include WPSI_PLUGIN_DIR.'admin/views/jobs-list.php'; }
    public static function render_wizard(): void { include WPSI_PLUGIN_DIR.'admin/views/wizard.php'; }
    private static function verify(): void { if(!current_user_can('manage_options'))wp_die('Forbidden',403); check_ajax_referer('wpsi_nonce','nonce'); }
    private static function post_types_js(): array {
        $out=[];
        foreach(get_post_types(['public'=>true],'objects') as $s=>$o)$out[]=['slug'=>$s,'label'=>$o->label];
        return $out;
    }
    public static function ajax_fetch_preview(): void {
        self::verify();
        try {
            $content=WPSI_Parser_Base::fetch_url(esc_url_raw($_POST['url']??''),sanitize_text_field($_POST['auth_type']??'none'),sanitize_text_field($_POST['auth_value']??''));
            if(is_wp_error($content))throw new \RuntimeException($content->get_error_message());
            $parser=WPSI_Parser::make(sanitize_text_field($_POST['file_type']??'json'));
            $records=$parser->parse($content,sanitize_text_field($_POST['root_path']??''));
            if(empty($records))throw new \RuntimeException('No records found.');
            wp_send_json_success(['fields'=>$parser->extract_fields($records),'preview'=>array_slice($records,0,5),'total'=>count($records)]);
        } catch(\Throwable $e){ wp_send_json_error(['message'=>$e->getMessage()]); }
    }
    public static function ajax_get_dest_fields(): void {
        self::verify();
        wp_send_json_success(WPSI_Mapper::get_destination_options(sanitize_key($_POST['post_type']??'post')));
    }
    public static function ajax_save_job(): void {
        self::verify();
        $raw=$_POST['job']??'';
        $data=is_string($raw)?json_decode(stripslashes($raw),true):(array)$raw;
        if(empty($data)){wp_send_json_error(['message'=>'No job data.']);return;}
        if(isset($data['field_map'])&&is_string($data['field_map']))$data['field_map']=json_decode(stripslashes($data['field_map']),true);
        $job_id=WPSI_Database::save_job($data);
        wp_send_json_success(['job_id'=>$job_id]);
    }
    public static function ajax_run_now(): void {
        self::verify();
        $job=WPSI_Database::get_job((int)($_POST['job_id']??0));
        if(!$job){wp_send_json_error(['message'=>'Job not found.']);return;}
        wp_send_json_success(WPSI_Importer::run($job));
    }
    public static function ajax_delete_job(): void {
        self::verify();
        WPSI_Database::delete_job((int)($_POST['job_id']??0));
        wp_send_json_success();
    }
    public static function ajax_get_job(): void {
        self::verify();
        $job=WPSI_Database::get_job((int)($_POST['job_id']??0));
        if(!$job){wp_send_json_error(['message'=>'Not found.']);return;}
        if(is_string($job['field_map']))$job['field_map']=json_decode($job['field_map'],true);
        $job['logs']=WPSI_Database::get_logs((int)$_POST['job_id'],5);
        $job['function_code']=WPSI_Function_Editor::load((int)$_POST['job_id']);
        wp_send_json_success($job);
    }
    public static function ajax_toggle_status(): void {
        self::verify();
        $job_id=(int)($_POST['job_id']??0);
        $job=WPSI_Database::get_job($job_id);
        if(!$job){wp_send_json_error(['message'=>'Not found.']);return;}
        $s=$job['status']==='active'?'paused':'active';
        WPSI_Database::save_job(array_merge($job,['status'=>$s]));
        wp_send_json_success(['status'=>$s]);
    }
    public static function ajax_test_expression(): void {
        self::verify();
        $expr=stripslashes(sanitize_textarea_field($_POST['expression']??''));
        $records=$_POST['preview_records']??'';
        if(is_string($records))$records=json_decode(stripslashes($records),true);
        if(!is_array($records)||empty($records)){wp_send_json_error(['message'=>'No preview records. Complete Step 2 first.']);return;}
        $results=[];
        foreach(array_slice($records,0,5) as $r)$results[]=['record'=>$r,'output'=>WPSI_Expression_Evaluator::evaluate($expr,$r)];
        wp_send_json_success(['results'=>$results]);
    }
    public static function ajax_dry_run(): void {
        self::verify();
        $job_id=(int)($_POST['job_id']??0);
        $job=$job_id?WPSI_Database::get_job($job_id):null;
        if(!$job){$raw=stripslashes($_POST['job']??'');$job=$raw?json_decode($raw,true):null;}
        if(!$job){wp_send_json_error(['message'=>'Job not found.']);return;}
        if(is_string($job['field_map']??''))$job['field_map']=json_decode(stripslashes($job['field_map']??'[]'),true);
        $job['dry_run_limit']=(int)($_POST['limit']??5);
        wp_send_json_success(WPSI_Dry_Run::run($job,(int)($_POST['limit']??5)));
    }
    public static function ajax_save_function(): void {
        self::verify();
        $id=(int)($_POST['job_id']??0);
        if(!$id){wp_send_json_error(['message'=>'Job ID required.']);return;}
        wp_send_json_success(['saved'=>WPSI_Function_Editor::save($id,stripslashes($_POST['code']??''))]);
    }
    public static function ajax_load_function(): void {
        self::verify();
        $id=(int)($_POST['job_id']??0);
        wp_send_json_success(['code'=>$id?WPSI_Function_Editor::load($id):WPSI_Function_Editor::starter_template()]);
    }
    public static function ajax_save_function_template(): void {
        self::verify();
        $name=sanitize_text_field($_POST['template_name']??'');
        if(!$name){wp_send_json_error(['message'=>'Name required.']);return;}
        WPSI_Function_Editor::save_template($name,stripslashes($_POST['code']??''));
        wp_send_json_success(['templates'=>WPSI_Function_Editor::get_templates()]);
    }
    public static function ajax_load_function_templates(): void {
        self::verify();
        wp_send_json_success(['templates'=>WPSI_Function_Editor::get_templates()]);
    }
    public static function ajax_delete_function_template(): void {
        self::verify();
        WPSI_Function_Editor::delete_template(sanitize_text_field($_POST['template_name']??''));
        wp_send_json_success(['templates'=>WPSI_Function_Editor::get_templates()]);
    }
}
