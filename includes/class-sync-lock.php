<?php
defined('ABSPATH') || exit;
class WPSI_Sync_Lock {
    const LOCK='_wpsi_sync_lock'; const REASON='_wpsi_sync_lock_reason';
    const BY='_wpsi_sync_lock_by'; const AT='_wpsi_sync_lock_at';
    const JOB='_wpsi_sync_job_id'; const LAST='_wpsi_sync_last_run';
    public static function init(): void {
        add_action('add_meta_boxes',[__CLASS__,'add_meta_box']);
        add_action('save_post',[__CLASS__,'save_meta_box'],10,2);
        foreach(['posts','pages','product_posts'] as $t){
            add_filter("manage_{$t}_columns",[__CLASS__,'add_column']);
            add_action("manage_{$t}_custom_column",[__CLASS__,'render_column'],10,2);
        }
        foreach(['post','page','product'] as $t){
            add_filter("bulk_actions-edit-{$t}",[__CLASS__,'bulk_actions']);
            add_filter("handle_bulk_actions-edit-{$t}",[__CLASS__,'handle_bulk'],10,3);
        }
        add_action('admin_notices',[__CLASS__,'bulk_notice']);
        add_action('wp_ajax_wpsi_toggle_lock',[__CLASS__,'ajax_toggle']);
        add_action('admin_enqueue_scripts',[__CLASS__,'enqueue']);
    }
    public static function is_locked(int $id): bool { return (bool)get_post_meta($id,self::LOCK,true); }
    public static function lock(int $id,string $reason='',int $uid=0): void {
        update_post_meta($id,self::LOCK,1); update_post_meta($id,self::REASON,$reason);
        update_post_meta($id,self::BY,$uid?:get_current_user_id()); update_post_meta($id,self::AT,current_time('mysql'));
    }
    public static function unlock(int $id): void {
        update_post_meta($id,self::LOCK,0); delete_post_meta($id,self::REASON);
        delete_post_meta($id,self::BY); delete_post_meta($id,self::AT);
    }
    public static function stamp(int $id,int $job_id): void {
        update_post_meta($id,self::JOB,$job_id); update_post_meta($id,self::LAST,current_time('mysql'));
    }
    public static function add_meta_box(): void {
        foreach(get_post_types(['public'=>true],'names') as $t)
            add_meta_box('wpsi_sync_lock','<span class="dashicons dashicons-lock" style="color:#d63638;vertical-align:middle;margin-right:4px;"></span> Import Sync',[__CLASS__,'render_meta_box'],$t,'side','high');
    }
    public static function render_meta_box(\WP_Post $post): void {
        wp_nonce_field('wpsi_sync_lock_nonce','wpsi_sync_lock_nonce');
        $locked=(bool)get_post_meta($post->ID,self::LOCK,true);
        $reason=(string)get_post_meta($post->ID,self::REASON,true);
        $at=(string)get_post_meta($post->ID,self::AT,true);
        $last=(string)get_post_meta($post->ID,self::LAST,true);
        $job_id=(int)get_post_meta($post->ID,self::JOB,true);
        $jname=$job_id?(WPSI_Database::get_job($job_id)['name']??"Job #{$job_id}"):'—';
        ?>
        <div class="wpsi-lock-box <?php echo $locked?'wpsi-lock-box--locked':''; ?>">
            <div class="wpsi-lock-status">
                <span><?php echo $locked?'🔒':'🔓'; ?></span>
                <strong><?php echo $locked?'Sync Locked':'Sync Active'; ?></strong>
                <p class="wpsi-lock-desc"><?php echo $locked?'Excluded from all import jobs.':'Will be updated by import jobs normally.'; ?></p>
            </div>
            <label class="wpsi-lock-toggle-label"><input type="checkbox" name="wpsi_sync_lock" value="1" <?php checked($locked); ?>> Exclude this post from import sync</label>
            <div id="wpsi-lock-reason-wrap" style="<?php echo $locked?'':'display:none;'; ?>margin-top:8px;">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Reason / Note (optional)</label>
                <textarea name="wpsi_sync_lock_reason" rows="3" class="widefat" style="font-size:12px;" placeholder="e.g. Bought entire vendor stock"><?php echo esc_textarea($reason); ?></textarea>
            </div>
            <?php if($locked&&$at):?><div class="wpsi-lock-meta"><div>🕐 <?php echo esc_html(human_time_diff(strtotime($at)).' ago'); ?></div></div><?php endif; ?>
            <?php if($last):?><div class="wpsi-lock-import-info"><small>Last imported: <?php echo esc_html(human_time_diff(strtotime($last)).' ago'); ?><?php if($jname!=='—'):?> via <em><?php echo esc_html($jname); ?></em><?php endif; ?></small></div><?php endif; ?>
        </div>
        <script>(function(){var c=document.querySelector('input[name="wpsi_sync_lock"]'),r=document.getElementById('wpsi-lock-reason-wrap');if(c&&r)c.addEventListener('change',function(){r.style.display=this.checked?'':'none';});})()</script>
        <?php
    }
    public static function save_meta_box(int $id,\WP_Post $post): void {
        if(!isset($_POST['wpsi_sync_lock_nonce'])||!wp_verify_nonce($_POST['wpsi_sync_lock_nonce'],'wpsi_sync_lock_nonce'))return;
        if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
        if(!current_user_can('edit_post',$id))return;
        !empty($_POST['wpsi_sync_lock'])?self::lock($id,sanitize_textarea_field($_POST['wpsi_sync_lock_reason']??'')):self::unlock($id);
    }
    public static function add_column(array $cols): array {
        $p=array_search('title',array_keys($cols),true);
        $col=['wpsi_sync'=>'<span class="dashicons dashicons-update" title="Import Sync"></span>'];
        return $p!==false?array_slice($cols,0,$p+1,true)+$col+array_slice($cols,$p+1,null,true):$cols+$col;
    }
    public static function render_column(string $col,int $id): void {
        if($col!=='wpsi_sync')return;
        $l=self::is_locked($id);
        printf('<button class="wpsi-col-lock-btn %s" data-id="%d" data-locked="%d" title="%s">%s</button>',$l?'is-locked':'is-active',$id,$l?1:0,$l?'Locked — click to unlock':'Active — click to lock',$l?'🔒':'🔓');
    }
    public static function bulk_actions(array $a): array {$a['wpsi_lock']='🔒 Lock Sync';$a['wpsi_unlock']='🔓 Unlock Sync';return $a;}
    public static function handle_bulk(string $r,string $a,array $ids): string {
        if(!in_array($a,['wpsi_lock','wpsi_unlock'],true))return $r;
        foreach($ids as $id)$a==='wpsi_lock'?self::lock((int)$id):self::unlock((int)$id);
        return add_query_arg(['wpsi_bulk_action'=>$a,'wpsi_bulk_count'=>count($ids)],$r);
    }
    public static function bulk_notice(): void {
        if(empty($_GET['wpsi_bulk_action']))return;
        $c=(int)($_GET['wpsi_bulk_count']??0);
        $msg=$_GET['wpsi_bulk_action']==='wpsi_lock'?"$c post(s) locked — will be skipped by imports.":"$c post(s) unlocked.";
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($msg).'</p></div>';
    }
    public static function ajax_toggle(): void {
        if(!check_ajax_referer('wpsi_nonce','nonce',false))wp_die('Forbidden',403);
        if(!current_user_can('edit_posts'))wp_die('Forbidden',403);
        $id=(int)($_POST['post_id']??0);
        if(!$id){wp_send_json_error(['message'=>'Invalid post ID']);return;}
        self::is_locked($id)?self::unlock($id):self::lock($id,sanitize_text_field($_POST['reason']??''));
        wp_send_json_success(['locked'=>self::is_locked($id)]);
    }
    public static function enqueue(): void {
        $s=get_current_screen(); if(!$s||$s->base!=='edit')return;
        wp_add_inline_style('list-tables','.wpsi-col-lock-btn{background:none;border:none;cursor:pointer;font-size:16px;padding:2px;line-height:1;transition:opacity .15s}.wpsi-col-lock-btn:hover{opacity:.7}.wpsi-col-lock-btn.is-active{filter:grayscale(.6)}.column-wpsi_sync{width:42px;text-align:center}');
        wp_add_inline_script('jquery','jQuery(function($){$(document).on("click",".wpsi-col-lock-btn",function(){var $b=$(this),id=$b.data("id"),locked=$b.data("locked"),reason="";if(!locked){reason=prompt("Optional: reason for locking (blank to skip)");if(reason===null)return;}$b.prop("disabled",true).css("opacity",.4);$.post("'.admin_url('admin-ajax.php').'",{action:"wpsi_toggle_lock",nonce:"'.wp_create_nonce('wpsi_nonce').'",post_id:id,reason:reason||""}).done(function(r){if(r.success){var nl=r.data.locked;$b.data("locked",nl?1:0).text(nl?"🔒":"🔓").attr("title",nl?"Locked — click to unlock":"Active — click to lock").removeClass("is-locked is-active").addClass(nl?"is-locked":"is-active");}}).always(function(){$b.prop("disabled",false).css("opacity",1);});}); });');
    }
}
