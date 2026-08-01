<?php
if (!defined('ABSPATH')) exit;

class HRIM_Core {
    private static $instance;
    public static function instance(){ return self::$instance ?: (self::$instance = new self()); }

    private function __construct(){
        add_action('wp_enqueue_scripts',[$this,'assets']);
        add_filter('template_include',[$this,'blank_template'],99);
        add_action('template_redirect',[$this,'disable_app_cache'],0);
        add_action('send_headers',[$this,'send_no_cache_headers']);
        add_action('admin_menu',[$this,'admin_menu']);
        add_action('admin_init',[$this,'admin_redirect']);
        add_filter('cron_schedules',[$this,'cron_schedules']);
        add_action('hrim_notification_cron',[$this,'run_notification_triggers']);
        foreach ([
            'hrim_login'=>'login_shortcode','hrim_dashboard'=>'dashboard_shortcode','hrim_room_scores'=>'room_scores_shortcode','hrim_floor_scores'=>'floor_scores_shortcode','hrim_properties'=>'properties_shortcode',
            'hrim_floors'=>'floors_shortcode','hrim_asset_types'=>'asset_types_shortcode','hrim_room_categories'=>'room_categories_shortcode','hrim_rooms'=>'rooms_shortcode','hrim_sections'=>'sections_shortcode','hrim_sectionwise_checklist'=>'sectionwise_checklist_shortcode','hrim_templates'=>'templates_shortcode','hrim_inspection_form'=>'inspection_form_shortcode','hrim_inspection_in_progress'=>'inspection_in_progress_shortcode','hrim_schedule_tasks_in_progress'=>'schedule_tasks_in_progress_shortcode','hrim_schedule_tasks_history'=>'periodic_update_log_shortcode','hrim_periodic_tasks'=>'periodic_tasks_shortcode','hrim_add_periodic_task'=>'add_periodic_task_shortcode','hrim_update_periodic_task'=>'update_periodic_task_shortcode','hrim_add_occupancy'=>'add_occupancy_shortcode','hrim_update_office_count'=>'update_office_count_shortcode','hrim_occupancy_check'=>'occupancy_check_shortcode','hrim_history'=>'history_shortcode',
            'hrim_issues'=>'issues_shortcode','hrim_pending_assign_issues'=>'pending_assign_issues_shortcode','hrim_pending_issues'=>'pending_issues_shortcode','hrim_resolved_issues'=>'resolved_issues_shortcode','hrim_reports'=>'reports_shortcode','hrim_departments'=>'departments_shortcode','hrim_users'=>'users_shortcode',
            'hrim_profile'=>'profile_shortcode','hrim_settings'=>'settings_shortcode','hrim_communications'=>'communications_shortcode','hrim_learn'=>'learn_shortcode','hrim_audit_log'=>'audit_log_shortcode'
        ] as $tag=>$method) add_shortcode($tag,[$this,$method]);

        foreach (['save_periodic_task','delete_periodic_task','delete_periodic_instance','delete_schedule_task_instance','delete_periodic_update_log','save_periodic_task_answers','save_hotel','delete_hotel','save_floor','delete_floor','save_asset_type','delete_asset_type','save_room_category','delete_room_category','save_room','delete_room','save_department','delete_department','save_section','delete_section','save_template','delete_template','save_question','delete_question','submit_inspection','delete_inspection','delete_in_progress','save_issue','assign_issue','progress_issue','update_issue','delete_issue','save_user','update_user_status','delete_user','save_profile','save_settings','save_meta_settings','save_notification_settings','test_whatsapp','test_telegram','run_notifications_now','regenerate_pages','install_dummy_data','install_checklist_library','clear_dummy_data','repair_application','clear_audit_log','create_occupancy_check','save_occupancy_office_count','download_master_template','upload_master_template'] as $a)
            add_action('admin_post_hrim_'.$a,[$this,$a]);
        add_action('wp_ajax_hrim_get_rooms',[$this,'ajax_get_rooms']);
        add_action('wp_ajax_hrim_get_questions',[$this,'ajax_get_questions']);
        add_action('wp_ajax_hrim_get_room_checklist',[$this,'ajax_get_room_checklist']);
        add_action('wp_ajax_hrim_upload_image',[$this,'ajax_upload_image']);
        add_action('wp_ajax_hrim_upload_video',[$this,'ajax_upload_video']);
        add_action('wp_ajax_hrim_save_occupancy_room',[$this,'ajax_save_occupancy_room']);
        add_action('wp_ajax_hrim_save_occupancy_system',[$this,'ajax_save_occupancy_system']);
        add_action('wp_ajax_hrim_save_learn_progress',[$this,'ajax_save_learn_progress']);
    }

    public function cron_schedules($schedules){
        $schedules['hrim_five_minutes']=['interval'=>300,'display'=>'Every Five Minutes'];
        return $schedules;
    }

    public function assets(){
        if (!$this->is_app_page()) return;
        wp_enqueue_style('dashicons');
        wp_enqueue_style('hrim-montserrat','https://fonts.googleapis.com/css2?family=Montserrat:wght@600&display=swap',[],null);
        wp_enqueue_style('hrim-front',HRIM_URL.'assets/css/front.css',['hrim-montserrat'],HRIM_VERSION);
        $accent = sanitize_hex_color(get_option('hrim_accent','#2563eb')) ?: '#2563eb';
        wp_add_inline_style('hrim-front', ':root{--hrim-accent:'.$accent.';}');
        wp_enqueue_script('hrim-front',HRIM_URL.'assets/js/front.js',['jquery'],HRIM_VERSION,true);
        wp_localize_script('hrim-front','HRIM',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('hrim_ajax')]);
    }

    public function blank_template($template){
        if (is_page() && get_post_meta(get_queried_object_id(),'_hrim_app_page',true)==='1') return HRIM_DIR.'templates/blank-app.php';
        return $template;
    }

    private function is_app_page(){ return is_page() && get_post_meta(get_queried_object_id(),'_hrim_app_page',true)==='1'; }
    public function disable_app_cache(){
        if(!$this->is_app_page()) return;

        // Open the Room Score login form directly for logged-out users.
        // Do not show an intermediate "Login Required" screen.
        $login_url=$this->page_url('hotel-inspection-login');
        $current_id=get_queried_object_id();
        $page_ids=(array)get_option('hrim_page_ids',[]);
        $login_id=!empty($page_ids['hotel-inspection-login'])?(int)$page_ids['hotel-inspection-login']:0;
        if(!is_user_logged_in() && (!$login_id || (int)$current_id!==$login_id)){
            wp_safe_redirect($login_url);
            exit;
        }

        if(is_user_logged_in() && !$this->user_is_active()){
            wp_logout();
            wp_safe_redirect(add_query_arg('inactive','1',$this->page_url('hotel-inspection-login')));
            exit;
        }
        if(is_user_logged_in()){
            $current_slug=$this->current_app_page_slug();
            if($current_slug && $current_slug!=='hotel-inspection-login' && !user_can(get_current_user_id(),'manage_options')){
                if(!in_array($current_slug,$this->get_user_allowed_view_slugs(),true)){
                    wp_safe_redirect($this->first_allowed_app_url());
                    exit;
                }
            }
        }
        if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE',true);
        if(!defined('DONOTCACHEDB')) define('DONOTCACHEDB',true);
        if(!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT',true);
        nocache_headers();
    }
    public function send_no_cache_headers(){
        if(!$this->is_app_page()) return;
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        header('Vary: Cookie');
    }
    private function page_url($slug){ $ids=(array)get_option('hrim_page_ids',[]); return !empty($ids[$slug])?get_permalink($ids[$slug]):home_url('/'.$slug.'/'); }

    private function configurable_view_map(){
        return [
            'dashboard'=>['label'=>'Dashboard','slug'=>'hotel-inspection-dashboard'],
            'room_scores'=>['label'=>'Room Score','slug'=>'hotel-inspection-room-scores'],
            'floor_scores'=>['label'=>'Floor Score','slug'=>'hotel-inspection-floor-scores'],
            'inspect'=>['label'=>'Perform Inspection','slug'=>'hotel-inspection-inspection-form'],
            'in_progress'=>['label'=>'Inspection In Progress','slug'=>'hotel-inspection-in-progress'],
            'schedule_tasks_in_progress'=>['label'=>'Schedule Tasks In Progress','slug'=>'hotel-inspection-schedule-tasks-in-progress'],
            'periodic_update_log'=>['label'=>'Schedule Tasks History','slug'=>'hotel-inspection-schedule-tasks-history'],
            'history'=>['label'=>'Inspection History','slug'=>'hotel-inspection-history'],
            'add_occupancy'=>['label'=>'Add Occupancy','slug'=>'hotel-inspection-add-occupancy'],
            'update_office_count'=>['label'=>'Update Office Count','slug'=>'hotel-inspection-update-office-count'],
            'occupancy'=>['label'=>'Occupancy Check','slug'=>'hotel-inspection-occupancy-check'],
            'issues'=>['label'=>'Issues','slug'=>'hotel-inspection-issues'],
            'pending_assign'=>['label'=>'Pending Assign Issue','slug'=>'hotel-inspection-pending-assign-issues'],
            'pending_issues'=>['label'=>'Pending Issues','slug'=>'hotel-inspection-pending-issues'],
            'resolved_issues'=>['label'=>'Issue Resolved','slug'=>'hotel-inspection-resolved-issues'],
            'properties'=>['label'=>'Properties','slug'=>'hotel-inspection-properties'],
            'floors'=>['label'=>'Floors','slug'=>'hotel-inspection-floors'],
            'asset_types'=>['label'=>'Asset Types','slug'=>'hotel-inspection-asset-types'],
            'rooms'=>['label'=>'Assets','slug'=>'hotel-inspection-rooms'],
            'departments'=>['label'=>'Departments','slug'=>'hotel-inspection-departments'],
            'users'=>['label'=>'Users','slug'=>'hotel-inspection-users'],
            'add_periodic_task'=>['label'=>'Add Periodic Tasks','slug'=>'hotel-inspection-add-periodic-task'],
            'periodic_tasks'=>['label'=>'Periodic Tasks','slug'=>'hotel-inspection-periodic-tasks'],
            'sections'=>['label'=>'Checklist Sections','slug'=>'hotel-inspection-checklist-sections'],
            'sectionwise'=>['label'=>'Sectionwise Checklist','slug'=>'hotel-inspection-sectionwise-checklist'],
            'templates'=>['label'=>'Templates','slug'=>'hotel-inspection-templates'],
            'reports'=>['label'=>'Reports','slug'=>'hotel-inspection-reports'],
            'learn'=>['label'=>'Learn','slug'=>'hotel-inspection-learn'],
            'communications'=>['label'=>'Communications','slug'=>'hotel-inspection-communications'],
            'settings'=>['label'=>'Settings','slug'=>'hotel-inspection-settings'],
        ];
    }
    private function all_view_slugs(){
        return array_values(array_map(function($view){ return $view['slug']; },$this->configurable_view_map()));
    }
    private function get_user_allowed_view_slugs($user_id=0){
        $user_id=$user_id?:get_current_user_id();
        $all=$this->all_view_slugs();
        if(!$user_id) return $all;
        if(user_can($user_id,'manage_options')) return $all;
        $raw=get_user_meta($user_id,'hrim_allowed_views',true);
        if(empty($raw)) return $all;
        if(!is_array($raw)) $raw=(array)$raw;
        $raw=array_values(array_filter(array_map('sanitize_text_field',$raw)));
        $allowed=array_values(array_intersect($all,$raw));
        return $allowed ?: $all;
    }
    private function get_user_allowed_nav_keys($user_id=0){
        $slugs=$this->get_user_allowed_view_slugs($user_id);
        $keys=[];
        foreach($this->configurable_view_map() as $key=>$view){
            if(in_array($view['slug'],$slugs,true)) $keys[]=$key;
        }
        return $keys;
    }
    private function current_app_page_slug(){
        $current_id=get_queried_object_id();
        if(!$current_id) return '';
        foreach((array)get_option('hrim_page_ids',[]) as $slug=>$id){
            if((int)$id===(int)$current_id) return (string)$slug;
        }
        $post=get_post($current_id);
        return $post ? (string)$post->post_name : '';
    }
    private function first_allowed_app_slug($user_id=0){
        $allowed=$this->get_user_allowed_view_slugs($user_id);
        return !empty($allowed[0]) ? (string)$allowed[0] : 'hotel-inspection-dashboard';
    }
    private function first_allowed_app_url($user_id=0){
        return $this->page_url($this->first_allowed_app_slug($user_id));
    }
    private function can_manage(){ return current_user_can('hrim_manage_all'); }
    private function can_inspect(){ return current_user_can('hrim_perform_inspections') || $this->can_manage(); }
    private function can_issues(){ return current_user_can('hrim_manage_issues') || $this->can_manage(); }
    private function can_reports(){ return current_user_can('hrim_view_reports') || $this->can_manage(); }
    private function user_is_active($user_id=0){
        $user_id=$user_id?:get_current_user_id();
        if(!$user_id) return false;
        if(user_can($user_id,'manage_options')) return true;
        $status=(string)get_user_meta($user_id,'hrim_user_status',true);
        return $status==='' || $status==='active';
    }
    private function active_app_users($roles){
        return get_users([
            'role__in'=>(array)$roles,
            'orderby'=>'display_name',
            'number'=>-1,
            'meta_query'=>[
                'relation'=>'OR',
                ['key'=>'hrim_user_status','compare'=>'NOT EXISTS'],
                ['key'=>'hrim_user_status','value'=>'active','compare'=>'='],
            ],
        ]);
    }

    private function is_global_department_user($user_id=0){
        $user_id=$user_id?:get_current_user_id();
        return user_can($user_id,'manage_options') || user_can($user_id,'hrim_manage_all');
    }
    private function user_department_ids($user_id=0){
        global $wpdb;
        $user_id=$user_id?:get_current_user_id();
        if(!$user_id) return [];
        return array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT department_id FROM {$wpdb->prefix}hrim_user_departments WHERE user_id=%d",$user_id)));
    }
    private function department_scope_sql($alias='x'){
        global $wpdb;
        if($this->is_global_department_user()) return ['1=1',[]];
        $ids=$this->user_department_ids();
        if(!$ids) return ['1=0',[]];
        return ["{$alias}.department_id IN (".implode(',',array_fill(0,count($ids),'%d')).")",$ids];
    }
    private function active_departments(){
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_departments WHERE status='active' ORDER BY sort_order,name,id");
    }

    private function ist_timezone(){ return new DateTimeZone('Asia/Kolkata'); }
    private function ist_now_mysql(){ return (new DateTime('now',$this->ist_timezone()))->format('Y-m-d H:i:s'); }
    private function ist_date($format,$value='now'){
        try {
            if($value instanceof DateTimeInterface){
                $dt=DateTime::createFromInterface($value);
                $dt->setTimezone($this->ist_timezone());
            } elseif(is_numeric($value)){
                // Unix timestamps represent an absolute instant, so convert them to IST.
                $dt=new DateTime('@'.intval($value));
                $dt->setTimezone($this->ist_timezone());
            } else {
                $text=trim((string)$value);
                // RoomScore stores its application DATETIME values in Asia/Kolkata.
                // Parse them as IST to prevent a second timezone conversion.
                $dt=new DateTime($text===''?'now':$text,$this->ist_timezone());
            }
            return $dt->format($format);
        } catch(Exception $e){ return (string)$value; }
    }
    private function filter_dates(){
        $from=sanitize_text_field(wp_unslash($_GET['from']??''));
        $to=sanitize_text_field(wp_unslash($_GET['to']??''));
        return [$from,$to];
    }
    private function excel_button($filename){ return '<button type="button" class="hrim-secondary hrim-export-csv hrim-icon-only-button" data-filename="'.esc_attr($filename).'" title="Download Excel" aria-label="Download Excel"><span class="dashicons dashicons-download" aria-hidden="true"></span></button>'; }

    public function admin_menu(){ add_menu_page('Room Score','Room Score','manage_options','hrim-launch',[$this,'admin_launch'],'dashicons-clipboard',26); }
    public function admin_launch(){ echo '<div class="wrap"><h1>Room Score</h1><p>This plugin is frontend-first.</p><p><a class="button button-primary" href="'.esc_url($this->page_url('hotel-inspection-dashboard')).'">Open Application</a></p></div>'; }
    public function admin_redirect(){ if (!is_admin()||wp_doing_ajax()||!is_user_logged_in()) return; $u=wp_get_current_user(); if (in_array('hrim_worker',$u->roles,true)||in_array('hrim_supervisor',$u->roles,true)||in_array('hrim_owner',$u->roles,true)||in_array('hrim_inspector',$u->roles,true)||in_array('hrim_housekeeping',$u->roles,true)||in_array('hrim_manager',$u->roles,true)){ global $pagenow; if($pagenow==='index.php') wp_safe_redirect($this->page_url('hotel-inspection-dashboard')); } }

    private function sidebar_counts(){
        global $wpdb;
        $prefix=$wpdb->prefix;
        $uid=get_current_user_id();
        $inspection_scope=$this->can_manage()?'':$wpdb->prepare(' AND inspector_id=%d',$uid);

        $counts=[];
        $counts['in_progress']=(int)$wpdb->get_var("SELECT COUNT(DISTINCT draft_batch_id) FROM {$prefix}hrim_inspections WHERE status='in_progress' AND draft_batch_id IS NOT NULL AND draft_batch_id<>''{$inspection_scope}");
        $counts['history']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_inspections WHERE status='completed'{$inspection_scope}");
        $counts['issues']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_issues");
        $counts['pending_assign']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_issues WHERE (assigned_to IS NULL OR assigned_to=0) AND status IN ('open','reopened')");
        $counts['pending_issues']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_issues WHERE assigned_to IS NOT NULL AND assigned_to>0 AND status IN ('assigned','in_progress','reopened','open')");
        $counts['resolved_issues']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_issues WHERE status IN ('resolved','verified','closed')");
        $counts['room_scores']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_rooms WHERE status='active'");
        $counts['floor_scores']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_floors WHERE status='active'");
        $counts['periodic_tasks']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_periodic_tasks WHERE status='active'");
        $today=$this->ist_date('Y-m-d');
        $counts['schedule_tasks_in_progress']=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}hrim_periodic_task_instances WHERE due_date<=%s AND LOWER(TRIM(COALESCE(status,'due'))) NOT IN ('completed','verified')",
            $today
        ));

        if($this->can_manage()){
            $counts['properties']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_hotels WHERE status='active'");
            $counts['floors']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_floors WHERE status='active'");
            $counts['asset_types']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_asset_types WHERE status='active'");
            $counts['categories']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_room_categories WHERE status='active'");
            $counts['rooms']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_rooms WHERE status='active'");
            $counts['departments']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_departments WHERE status='active'");
            $counts['sections']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_sections WHERE status='active'");
            $counts['sectionwise']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_questions WHERE is_library=1");
            $counts['templates']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_templates WHERE status='active'");
            $counts['users']=count($this->active_app_users(['administrator','hrim_manager','hrim_inspector','hrim_housekeeping']));
            $counts['audit']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}hrim_audit_log");
        }
        return $counts;
    }

    private function sidebar_count_label($count){
        $count=(int)$count;
        if($count<=0) return '';
        return $count>99?'99+':(string)$count;
    }

    private function shell($title,$content,$active='dashboard'){
        if (!is_user_logged_in()) return $this->login_notice();
        $u=wp_get_current_user();
        if(isset($_GET['hrim_msg']) && !(isset($_GET['hrim_form']) && $active==='issues')){
            $cls=isset($_GET['error'])?'error':'success';
            $content='<div class="hrim-alert '.$cls.'">'.esc_html(wp_unslash($_GET['hrim_msg'])).'</div>'.$content;
        }
        $nav=[
            'dashboard'=>['Dashboard','hotel-inspection-dashboard','dashicons-dashboard'],
            'inspect'=>['Perform Inspection','perform-room-inspection','dashicons-yes-alt'],
            'in_progress'=>['Inspection In Progress','hotel-inspection-in-progress','dashicons-update'],
            'schedule_tasks_in_progress'=>['Schedule Tasks In Progress','hotel-inspection-schedule-tasks-in-progress','dashicons-calendar-alt'],
            'periodic_update_log'=>['Schedule Tasks History','hotel-inspection-schedule-tasks-history','dashicons-backup'],
            'periodic_tasks'=>['Periodic Tasks','hotel-inspection-periodic-tasks','dashicons-calendar-alt'],
            'add_periodic_task'=>['Add Periodic Tasks','hotel-inspection-add-periodic-task','dashicons-plus-alt2'],
            'add_occupancy'=>['Add Occupancy','hotel-inspection-add-occupancy','dashicons-plus-alt2'],
            'update_office_count'=>['Update Office Count','hotel-inspection-update-office-count','dashicons-edit'],
            'occupancy'=>['Occupancy Check','hotel-inspection-occupancy-check','dashicons-list-view'],
            'history'=>['Inspection History','hotel-inspection-history','dashicons-clipboard'],
            'issues'=>['Issues','hotel-inspection-issues','dashicons-warning'],
            'pending_assign'=>['Pending Assign Issue','hotel-inspection-pending-assign-issues','dashicons-admin-users'],
            'pending_issues'=>['Pending Issues','hotel-inspection-pending-issues','dashicons-clock'],
            'resolved_issues'=>['Issue Resolved','hotel-inspection-resolved-issues','dashicons-yes-alt'],
            'reports'=>['Reports','hotel-inspection-reports','dashicons-chart-bar'],
            'learn'=>['Learn','hotel-inspection-learn','dashicons-welcome-learn-more'],
        ];
        if(get_option('hrim_show_room_score','1')==='1'){
            $nav = array_slice($nav,0,1,true) + ['room_scores'=>['Room Score','hotel-inspection-room-scores','dashicons-star-filled']] + array_slice($nav,1,null,true);
        }
        if(get_option('hrim_show_floor_score','1')==='1'){
            $offset = isset($nav['room_scores']) ? 2 : 1;
            $nav = array_slice($nav,0,$offset,true) + ['floor_scores'=>['Floor Score','hotel-inspection-floor-scores','dashicons-chart-area']] + array_slice($nav,$offset,null,true);
        }
        if($this->can_manage()){
            $nav['properties']=['Properties','hotel-inspection-properties','dashicons-building'];
            $nav['floors']=['Floors','hotel-inspection-floors','dashicons-editor-ol'];
            $nav['asset_types']=['Asset Types','hotel-inspection-asset-types','dashicons-screenoptions'];
            $nav['rooms']=['Assets','hotel-inspection-rooms','dashicons-admin-home'];
            $nav['sections']=['Checklist Sections','hotel-inspection-checklist-sections','dashicons-editor-ul'];
            $nav['sectionwise']=['Sectionwise Checklist','hotel-inspection-sectionwise-checklist','dashicons-list-view'];
            $nav['templates']=['Templates','hotel-inspection-templates','dashicons-list-view'];
            $nav['departments']=['Departments','hotel-inspection-departments','dashicons-networking'];
            $nav['users']=['Users','hotel-inspection-users','dashicons-groups'];
            $nav['communications']=['Communications','hotel-inspection-communications','dashicons-format-chat'];
            $nav['settings']=['Settings','hotel-inspection-settings','dashicons-admin-generic'];
        }

        $nav_groups=[
            'inspection'=>[
                'label'=>'Inspection',
                'icon'=>'dashicons-clipboard',
                'items'=>['inspect','in_progress','schedule_tasks_in_progress','periodic_update_log','history'],
            ],
            'occupancy_group'=>[
                'label'=>'Occupancy',
                'icon'=>'dashicons-admin-home',
                'items'=>['add_occupancy','update_office_count','occupancy'],
            ],
            'issue_group'=>[
                'label'=>'Issues',
                'icon'=>'dashicons-warning',
                'items'=>['issues','pending_assign','pending_issues','resolved_issues'],
            ],
        ];
        if($this->can_manage()){
            $nav_groups['masters']=[
                'label'=>'Masters',
                'icon'=>'dashicons-portfolio',
                'items'=>['properties','floors','asset_types','categories','rooms','departments','users'],
            ];
        }
        // Keep Periodic Tasks directly below the Masters section in the sidebar.
        $nav_groups['periodic_group']=[
            'label'=>'Periodic Tasks',
            'icon'=>'dashicons-calendar-alt',
            'items'=>['add_periodic_task','periodic_tasks'],
        ];
        if($this->can_manage()){
            $nav_groups['section_group']=[
                'label'=>'Sections',
                'icon'=>'dashicons-editor-ul',
                'items'=>['sections','sectionwise','templates'],
            ];
        }
        $nav_counts=$this->sidebar_counts();
        ob_start(); ?>
        <div class="hrim-app-shell">
          <div id="hrimGlobalSpinner" class="hrim-global-spinner" aria-hidden="true"><div class="hrim-spinner-ring" role="status" aria-label="Loading"></div><span>Loading...</span></div>
          <aside class="hrim-sidebar" id="hrimSidebar"><button type="button" class="hrim-sidebar-mobile-close" aria-label="Close menu" title="Close menu"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button><div class="hrim-brand"><img class="hrim-brand-logo" src="https://res.cloudinary.com/qmkv5rd2/image/upload/v1784719520/Room_Score_cs4m3f.png" alt="Room Score"></div>
          <nav class="hrim-sidebar-nav">
            <?php
            $allowed_nav_keys=$this->get_user_allowed_nav_keys();
            $render_link=function($key,$is_child=false) use ($nav,$nav_counts,$active,$allowed_nav_keys){
                if(!in_array($key,$allowed_nav_keys,true)) return;
                if(empty($nav[$key])) return;
                $n=$nav[$key];
                $count_label=$this->sidebar_count_label($nav_counts[$key]??0);
                $classes=trim(($active===$key?'active ':'').($is_child?'hrim-subnav':''));
                ?>
                <a class="<?php echo esc_attr($classes); ?>" href="<?php echo esc_url($this->page_url($n[1])); ?>" title="<?php echo esc_attr($n[0]); ?>">
                  <span class="dashicons <?php echo esc_attr($n[2]); ?>" aria-hidden="true"></span>
                  <span class="hrim-nav-label"><?php echo esc_html($n[0]); ?></span>
                  <?php if($count_label!==''): ?><span class="hrim-nav-count" aria-label="<?php echo esc_attr($count_label.' records'); ?>"><?php echo esc_html($count_label); ?></span><?php endif; ?>
                </a>
                <?php
            };
            $render_link('dashboard');
            $render_link('room_scores');
            $render_link('floor_scores');
            foreach($nav_groups as $group_key=>$group):
                $group_items=array_values(array_filter($group['items'],function($item_key) use ($allowed_nav_keys){ return in_array($item_key,$allowed_nav_keys,true); }));
                if(!$group_items) continue;
                $group_open=in_array($active,$group_items,true); // Expand only the section containing the current view.
                // Parent group headings do not show counts; child view badges remain visible.
                $group_count='';
            ?>
              <div class="hrim-nav-group <?php echo $group_open?'is-open':''; ?>" data-group="<?php echo esc_attr($group_key); ?>">
                <button type="button" class="hrim-nav-group-toggle" aria-expanded="<?php echo $group_open?'true':'false'; ?>">
                  <span class="dashicons <?php echo esc_attr($group['icon']); ?>" aria-hidden="true"></span>
                  <span class="hrim-nav-label"><?php echo esc_html($group['label']); ?></span>
                  <?php if($group_count!==''): ?><span class="hrim-nav-count"><?php echo esc_html($group_count); ?></span><?php endif; ?>
                  <span class="dashicons dashicons-arrow-down-alt2 hrim-nav-chevron" aria-hidden="true"></span>
                </button>
                <div class="hrim-nav-group-items" <?php echo $group_open?'':'hidden'; ?>>
                  <?php foreach($group_items as $item_key) $render_link($item_key,true); ?>
                </div>
              </div>
            <?php endforeach;
            $render_link('reports');
            $render_link('learn');
            if($this->can_manage()) {
                $render_link('communications');
                $render_link('settings');
            }
            ?>
          </nav>
          <div class="hrim-sidebar-user"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><div><strong><?php echo esc_html($u->display_name); ?></strong><a href="<?php echo esc_url(wp_logout_url($this->page_url('hotel-inspection-login'))); ?>">Logout</a></div></div>
          </aside>
          <main class="hrim-main"><header class="hrim-topbar"><button class="hrim-menu-toggle" type="button" aria-label="Menu">☰</button><button type="button" class="hrim-sidebar-collapse-toggle" id="hrimSidebarCollapseToggle" aria-expanded="true" title="Hide Menu" aria-label="Hide Menu"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></button><div><h1><?php echo esc_html($title); ?></h1></div></header><section class="hrim-content"><?php if(!empty($_GET['hrim_saved'])): ?><div class="hrim-alert success"><?php echo esc_html(wp_unslash($_GET['hrim_message'] ?? 'Saved successfully.')); ?></div><?php endif; ?><?php if(!empty($_GET['hrim_error'])): ?><div class="hrim-alert error"><?php echo esc_html(wp_unslash($_GET['hrim_error'])); ?></div><?php endif; ?><?php echo $content; ?></section></main>
        </div><?php return ob_get_clean();
    }

    public function login_shortcode(){
        if(is_user_logged_in()) return '<script>location.href='.wp_json_encode($this->first_allowed_app_url()).';</script>';
        $error=''; if(isset($_GET['login'])&&$_GET['login']==='failed') $error='<div class="hrim-alert error">Invalid username or password.</div>'; if(isset($_GET['inactive'])) $error='<div class="hrim-alert error">Your application access is inactive. Please contact the administrator.</div>'; 
        ob_start(); ?>
        <div class="hrim-login-page">
          <div class="hrim-login-shell">
            <main class="hrim-login-card">
              <div class="hrim-login-mobile-brand">
                <img class="hrim-login-brand-logo" src="https://res.cloudinary.com/qmkv5rd2/image/upload/v1784719520/Room_Score_cs4m3f.png" alt="Room Score">
              </div>
              <?php echo $error; ?>
              <form method="post" action="<?php echo esc_url(wp_login_url()); ?>">
                <label class="hrim-login-field"><span>Username or Email</span><span class="hrim-login-input-wrap"><i class="dashicons dashicons-admin-users"></i><input type="text" name="log" autocomplete="username" placeholder="Enter your username or email" required autofocus></span></label>
                <label class="hrim-login-field"><span>Password</span><span class="hrim-login-input-wrap"><i class="dashicons dashicons-lock"></i><input type="password" name="pwd" autocomplete="current-password" placeholder="Enter your password" required></span></label>
                <div class="hrim-login-options"><label class="hrim-login-remember"><input type="checkbox" name="rememberme" value="forever"><span>Remember me</span></label><a href="<?php echo esc_url(wp_lostpassword_url()); ?>">Forgot password?</a></div>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($this->page_url('hotel-inspection-dashboard')); ?>">
                <button class="hrim-primary hrim-login-submit" type="submit"><span>Sign In</span><i class="dashicons dashicons-arrow-right-alt"></i></button>
              </form>
              
            </main>
          </div>
        </div><?php return ob_get_clean();
    }

    private function single_active_property(){
        global $wpdb;
        static $cache = null;
        if ($cache !== null) return $cache;
        $rows = $wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_hotels WHERE status='active' ORDER BY id ASC LIMIT 2");
        $cache = (count($rows) === 1) ? $rows[0] : false;
        return $cache;
    }

    private function effective_property_id($posted_id=0){
        $single = $this->single_active_property();
        return $single ? (int)$single->id : absint($posted_id);
    }

    private function stats(){
        global $wpdb;
        $today_ist=$this->ist_date('Y-m-d');
        return [
            'rooms'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_rooms WHERE status='active'"),
            'inspections'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_inspections WHERE status<>'in_progress'"),
            'in_progress'=>(int)$wpdb->get_var("SELECT COUNT(DISTINCT draft_batch_id) FROM {$wpdb->prefix}hrim_inspections WHERE status='in_progress' AND draft_batch_id IS NOT NULL AND draft_batch_id<>''"),
            'pending_assign'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE (assigned_to IS NULL OR assigned_to=0) AND status IN ('open','reopened')"),
            'pending_inspections'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE assigned_to IS NOT NULL AND assigned_to>0 AND status IN ('assigned','in_progress','reopened','open')"),
            'failed'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_inspections WHERE status='failed'"),
            'open'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE status NOT IN ('resolved','verified','closed')"),
            'delayed'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE status NOT IN ('resolved','verified','closed') AND due_date IS NOT NULL AND due_date<>'' AND DATE(due_date)<%s",$today_ist)),
        ];
    }

    public function dashboard_shortcode(){
        global $wpdb;
        $s=$this->stats();
        $recent=$wpdb->get_results("SELECT i.*,r.room_no,f.name floor_name,h.name hotel_name,u.display_name inspector FROM {$wpdb->prefix}hrim_inspections i LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=i.room_id LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=r.floor_id LEFT JOIN {$wpdb->prefix}hrim_hotels h ON h.id=i.hotel_id LEFT JOIN {$wpdb->users} u ON u.ID=i.inspector_id WHERE i.status<>'in_progress' ORDER BY i.id DESC LIMIT 8");
        $in_progress_where=$this->can_manage()?"i.status='in_progress'":"i.status='in_progress' AND i.inspector_id=".get_current_user_id();
        $in_progress_rows=$wpdb->get_results("SELECT i.draft_batch_id,MIN(i.started_at) started_at,MAX(i.last_saved_at) last_saved_at,MAX(i.room_id) room_id,MAX(i.inspector_id) inspector_id,r.room_no,f.name floor_name,u.display_name inspector FROM {$wpdb->prefix}hrim_inspections i LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=i.room_id LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=i.floor_id LEFT JOIN {$wpdb->users} u ON u.ID=i.inspector_id WHERE {$in_progress_where} AND i.draft_batch_id IS NOT NULL AND i.draft_batch_id<>'' GROUP BY i.draft_batch_id ORDER BY last_saved_at DESC LIMIT 8");
        $pending_assign=$this->get_issue_rows("(x.assigned_to IS NULL OR x.assigned_to=0) AND x.status IN ('open','reopened')",[],8);
        $pending=$this->get_issue_rows("x.status IN ('assigned','in_progress','reopened') OR (x.status='open' AND x.assigned_to IS NOT NULL)",[],8);
        $today_ist=$this->ist_date('Y-m-d');
        $delay=$this->get_issue_rows("x.status NOT IN ('resolved','verified','closed') AND x.due_date IS NOT NULL AND x.due_date<>'' AND DATE(x.due_date)<%s",[$today_ist],8);
        $resolved=$this->get_issue_rows("x.status IN ('resolved','verified','closed') AND DATE(COALESCE(x.resolved_at,x.created_at))=%s",[$today_ist],8);
        $this->generate_periodic_instances();
        $schedule_scope_sql='';
        $schedule_scope_args=[];
        if(!$this->is_global_department_user()){
            $schedule_department_ids=$this->user_department_ids();
            if($schedule_department_ids){
                $schedule_placeholders=implode(',',array_fill(0,count($schedule_department_ids),'%d'));
                $schedule_scope_sql=" AND t.department_id IN ({$schedule_placeholders})";
                $schedule_scope_args=$schedule_department_ids;
            }else{
                $schedule_scope_sql=' AND 1=0';
            }
        }
        $schedule_sql="SELECT i.*,t.task_name,d.name department_name FROM {$wpdb->prefix}hrim_periodic_task_instances i JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=i.task_id LEFT JOIN {$wpdb->prefix}hrim_departments d ON d.id=t.department_id WHERE i.due_date<=%s AND LOWER(TRIM(COALESCE(i.status,'due'))) NOT IN ('completed','verified'){$schedule_scope_sql} ORDER BY i.due_date ASC,i.id ASC LIMIT 8";
        $schedule_args=array_merge([$today_ist],$schedule_scope_args);
        $schedule_rows=$wpdb->get_results($wpdb->prepare($schedule_sql,$schedule_args));
        $schedule_count_sql="SELECT COUNT(*) FROM {$wpdb->prefix}hrim_periodic_task_instances i JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=i.task_id WHERE i.due_date<=%s AND LOWER(TRIM(COALESCE(i.status,'due'))) NOT IN ('completed','verified'){$schedule_scope_sql}";
        $schedule_count=(int)$wpdb->get_var($wpdb->prepare($schedule_count_sql,$schedule_args));
        $department_rows=$this->active_departments();
        if(!$this->is_global_department_user()){
            $allowed=$this->user_department_ids();
            $department_rows=array_values(array_filter($department_rows,function($d) use($allowed){ return in_array((int)$d->id,$allowed,true); }));
        }
        foreach($department_rows as $department){
            $user_sql=$this->is_global_department_user()?'':$wpdb->prepare(" AND x.assigned_to=%d",get_current_user_id());
            $department->pending_assign=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues x WHERE x.department_id=%d AND (x.assigned_to IS NULL OR x.assigned_to=0) AND x.status IN ('open','reopened')".$user_sql,$department->id));
            $department->pending=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues x WHERE x.department_id=%d AND x.assigned_to IS NOT NULL AND x.assigned_to>0 AND x.status IN ('open','assigned','in_progress','reopened')".$user_sql,$department->id));
            $department->delayed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues x WHERE x.department_id=%d AND x.status NOT IN ('resolved','verified','closed') AND x.due_date IS NOT NULL AND DATE(x.due_date)<%s".$user_sql,$department->id,$today_ist));
            $department->resolved=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues x WHERE x.department_id=%d AND x.status IN ('resolved','verified','closed') AND DATE(COALESCE(x.resolved_at,x.created_at))=%s".$user_sql,$department->id,$today_ist));
        }
        ob_start(); ?>
        <?php
        $kpis=[
            ['in_progress','Total Inspection In Progress','update',$this->page_url('hotel-inspection-in-progress')],
            ['pending_assign','Pending Assign Issues','admin-users',$this->page_url('hotel-inspection-pending-assign-issues')],
            ['pending_inspections','Pending Inspections','clock',$this->page_url('hotel-inspection-pending-issues')],
            ['open','Open Issues','warning',$this->page_url('hotel-inspection-issues')],
            ['delayed','Delayed Issues','clock',add_query_arg('delayed','1',$this->page_url('hotel-inspection-pending-issues'))],
            ['schedule_tasks','Schedule Tasks In Progress','calendar-alt',$this->page_url('hotel-inspection-schedule-tasks-in-progress')],
        ];
        $s['schedule_tasks']=$schedule_count;
        ?>
        <div class="hrim-kpis"><?php foreach($kpis as $x): $extra_class=$x[0]==='schedule_tasks'?' hrim-kpi-schedule-tasks':''; ?><a class="hrim-kpi hrim-kpi-link<?php echo esc_attr($extra_class); ?>" href="<?php echo esc_url($x[3]); ?>" aria-label="<?php echo esc_attr($x[1]); ?>"><small><?php echo esc_html($x[1]); ?></small><strong><?php echo esc_html($s[$x[0]]); ?></strong></a><?php endforeach; ?></div>
        <div class="hrim-panel hrim-dashboard-panel hrim-department-summary"><div class="hrim-panel-head"><h2>Department-wise Issue Count</h2><?php echo $this->excel_button('dashboard-department-count'); ?></div><div class="hrim-table-wrap"><table class="hrim-table hrim-exportable"><thead><tr><th>Department</th><th>Pending Assign</th><th>Pending</th><th>Delayed</th><th>Resolved Today</th></tr></thead><tbody><?php foreach($department_rows as $department): ?><tr><td><strong><?php echo esc_html($department->name); ?></strong></td><td><?php echo esc_html($department->pending_assign); ?></td><td><?php echo esc_html($department->pending); ?></td><td><?php echo esc_html($department->delayed); ?></td><td><?php echo esc_html($department->resolved); ?></td></tr><?php endforeach; ?><?php if(!$department_rows): ?><tr><td colspan="5" class="hrim-empty">No departments available.</td></tr><?php endif; ?></tbody></table></div></div>
        <div class="hrim-panel hrim-dashboard-panel hrim-dashboard-schedule-tasks"><div class="hrim-panel-head"><h2>Schedule Tasks In Progress</h2><?php echo $this->excel_button('dashboard-schedule-tasks-in-progress'); ?></div><?php echo $this->dashboard_schedule_tasks_table($schedule_rows); ?></div>
        <div class="hrim-panel hrim-dashboard-panel hrim-dashboard-in-progress"><div class="hrim-panel-head"><h2>Inspection In Progress</h2><?php echo $this->excel_button('dashboard-inspection-in-progress'); ?></div><?php echo $this->dashboard_in_progress_table($in_progress_rows); ?></div>
        <div class="hrim-panel hrim-dashboard-panel hrim-dashboard-recent"><div class="hrim-panel-head"><h2>Recent Inspections</h2><?php echo $this->excel_button('dashboard-recent-inspections'); ?></div><?php echo $this->inspection_table($recent); ?></div>
        <div class="hrim-panel hrim-dashboard-panel hrim-dashboard-unassigned"><div class="hrim-panel-head"><div><h2>Pending Assign Issues</h2></div><?php echo $this->excel_button('dashboard-pending-assign-issues'); ?></div><?php echo $this->dashboard_issue_table($pending_assign,'pending_assign'); ?></div>
        <div class="hrim-panel hrim-dashboard-panel hrim-dashboard-pending"><div class="hrim-panel-head"><div><h2>Pending Issues</h2></div><?php echo $this->excel_button('dashboard-pending-issues'); ?></div><?php echo $this->dashboard_issue_table($pending,'pending'); ?></div>
        <div class="hrim-panel hrim-dashboard-panel hrim-dashboard-delay"><div class="hrim-panel-head"><div><h2>Delayed Issues</h2></div><?php echo $this->excel_button('dashboard-delayed-issues'); ?></div><?php echo $this->dashboard_issue_table($delay,'delayed'); ?></div>
        <div class="hrim-panel hrim-dashboard-panel hrim-dashboard-resolved"><div class="hrim-panel-head"><div><h2>Resolved Issues</h2></div><?php echo $this->excel_button('dashboard-resolved-issues'); ?></div><?php echo $this->dashboard_issue_table($resolved,'resolved'); ?></div>
        <?php return $this->shell('Dashboard',ob_get_clean(),'dashboard');
    }

    private function dashboard_schedule_tasks_table($rows){
        global $wpdb;
        ob_start(); ?>
        <div class="hrim-table-wrap"><table class="hrim-table hrim-exportable"><thead><tr><th>Sl.</th><th>Due Date</th><th>Task</th><th>Department</th><th>Rooms</th><th>Completed</th><th>Progress</th><th>Status</th><th class="hrim-no-export">Action</th></tr></thead><tbody>
        <?php foreach($rows as $i=>$x):
            $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_periodic_task_rooms WHERE instance_id=%d",$x->id));
            $done=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_periodic_task_rooms WHERE instance_id=%d AND LOWER(TRIM(status)) IN ('completed','complete','1')",$x->id));
            $pct=$total?round($done*100/$total):0;
            if($pct>=100){ $progress_status='completed'; $progress_status_label='Completed'; }
            elseif($pct>0){ $progress_status='in_progress'; $progress_status_label='In Progress'; }
            else { $progress_status='yet_to_start'; $progress_status_label='Yet to Start'; }
        ?><tr><td><?php echo $i+1; ?></td><td><?php echo esc_html($this->ist_date('d/m/y',$x->due_date)); ?></td><td><?php echo esc_html($x->task_name); ?></td><td><?php echo esc_html($x->department_name?:'—'); ?></td><td><?php echo esc_html($total); ?></td><td><?php echo esc_html($done); ?></td><td><?php echo esc_html($pct); ?>%</td><td><span class="hrim-status <?php echo esc_attr($progress_status); ?>"><?php echo esc_html($progress_status_label); ?></span></td><td class="hrim-no-export"><div class="hrim-periodic-actions"><a class="hrim-action view" title="Open Task" href="<?php echo esc_url(add_query_arg('instance_id',$x->id,$this->page_url('hotel-inspection-schedule-tasks-in-progress'))); ?>"><span class="dashicons dashicons-visibility"></span></a></div></td></tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="9" class="hrim-empty">No scheduled tasks are due.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php return ob_get_clean();
    }

    private function dashboard_in_progress_table($rows){
        ob_start(); ?>
        <div class="hrim-table-wrap"><table class="hrim-table hrim-exportable"><thead><tr><th>Sl.</th><th>Date</th><th>Floor</th><th>Room</th><th>Inspector</th><th>Completed</th><th>Progress</th><th>Last Updated</th><th class="hrim-no-export">Actions</th></tr></thead><tbody>
        <?php $sl=0; foreach($rows as $r):
            $checklist=$this->in_progress_room_checklist((int)$r->room_id,(string)$r->draft_batch_id);
            $total_count=0; $answered_count=0;
            foreach($checklist as $section_items){
                $total_count+=count($section_items);
                foreach($section_items as $item){
                    $saved=$item[1]??null;
                    if($saved && in_array(strtolower(trim((string)$saved->answer_text)),['yes','no'],true)) $answered_count++;
                }
            }
            $pct=$total_count?round(($answered_count/$total_count)*100):0;
        ?><tr><td><?php echo ++$sl; ?></td><td><?php echo esc_html($this->ist_date('d/m/y',$r->started_at?:$r->last_saved_at)); ?></td><td><?php echo esc_html($r->floor_name); ?></td><td><?php echo esc_html($r->room_no); ?></td><td><?php echo esc_html($r->inspector); ?></td><td><?php echo esc_html($answered_count.' / '.$total_count); ?></td><td><strong><?php echo esc_html($pct); ?>%</strong></td><td><?php echo esc_html($this->ist_date('d/m/y, h:i A',$r->last_saved_at)); ?></td><td class="hrim-no-export"><div class="hrim-actions"><a class="hrim-icon-btn hrim-action-edit" title="Continue Inspection" href="<?php echo esc_url(add_query_arg('draft',$r->draft_batch_id,$this->page_url('perform-room-inspection'))); ?>"><span class="dashicons dashicons-edit"></span></a></div></td></tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="9" class="hrim-empty">Inspection records not found.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php return ob_get_clean();
    }


    /** Return the latest submitted inspection score and unresolved issue count for each active room. */
    private function latest_room_score_rows(){
        global $wpdb;
        $p=$wpdb->prefix;
        $rooms=$wpdb->get_results("SELECT r.id,r.room_no,r.floor_id,f.name floor_name,f.floor_manager_id,u.display_name floor_manager FROM {$p}hrim_rooms r LEFT JOIN {$p}hrim_floors f ON f.id=r.floor_id LEFT JOIN {$wpdb->users} u ON u.ID=f.floor_manager_id WHERE r.status='active' ORDER BY f.sort_order,f.name,r.room_no");
        foreach($rooms as $room){
            /* Final inspections can have status completed or failed. Only drafts are excluded. */
            $latest=$wpdb->get_row($wpdb->prepare(
                "SELECT id,submitted_at,completed_at,overall_score,status FROM {$p}hrim_inspections WHERE room_id=%d AND status<>'in_progress' ORDER BY COALESCE(completed_at,submitted_at) DESC,id DESC LIMIT 1",
                $room->id
            ));
            $room->last_inspection='';
            $room->passed=0;
            $room->failed=0;
            $room->score=null;
            if($latest){
                $room->last_inspection=$latest->completed_at?:$latest->submitted_at;
                $answer_rows=$wpdb->get_results($wpdb->prepare(
                    "SELECT LOWER(COALESCE(result_status,'')) result_status,LOWER(TRIM(COALESCE(answer_text,''))) answer_text,COUNT(*) qty FROM {$p}hrim_answers WHERE inspection_id=%d GROUP BY result_status,answer_text",
                    $latest->id
                ));
                foreach($answer_rows as $a){
                    if($a->result_status==='pass'||$a->answer_text==='yes') $room->passed+=(int)$a->qty;
                    elseif($a->result_status==='fail'||$a->answer_text==='no') $room->failed+=(int)$a->qty;
                }
                $answered=$room->passed+$room->failed;
                if($answered){
                    $room->score=round(($room->passed/$answered)*100);
                } elseif($latest->overall_score!==null && $latest->overall_score!=='') {
                    $room->score=round((float)$latest->overall_score);
                }
            }
            /* Some older issues only carry inspection_id, so use the inspection room as fallback. */
            $room->open_issues=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}hrim_issues x LEFT JOIN {$p}hrim_inspections i ON i.id=x.inspection_id WHERE COALESCE(NULLIF(x.room_id,0),i.room_id)=%d AND x.status NOT IN ('resolved','verified','closed')",
                $room->id
            ));
        }
        return $rooms;
    }

    public function room_scores_shortcode(){
        if(!$this->can_reports() && !$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','room_scores');
        $rows=$this->latest_room_score_rows();
        ob_start(); ?>
        <?php usort($rows, static function($a,$b){ return strnatcasecmp((string)$a->room_no,(string)$b->room_no); }); ?>
        <div class="hrim-panel hrim-master-panel hrim-no-pagination"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->excel_button('room-scores'); ?></div></div><div class="hrim-table-wrap"><table class="hrim-table hrim-exportable hrim-mobile-score-cards hrim-room-score-table"><thead><tr><th>Sl.</th><th>Room</th><th>Score %</th><th>Issues</th><th>Last Inspection Date</th></tr></thead><tbody>
        <?php $sl=0; foreach($rows as $r): ?><tr><td class="hrim-mobile-hide-sl" data-label="Sl."><?php echo ++$sl; ?></td><td class="hrim-score-card-title" data-label="Room"><strong><?php echo esc_html($r->room_no); ?></strong></td><td class="hrim-score-card-field" data-label="Score %"><?php if($r->score===null): ?><span class="hrim-muted">—</span><?php else: ?><span class="hrim-score-pill"><?php echo esc_html($r->score); ?>%</span><?php endif; ?></td><td class="hrim-score-card-field" data-label="Issues"><?php if((int)$r->open_issues>0): $room_issue_url=add_query_arg(['room_id'=>(int)$r->id,'open_only'=>'1'],$this->page_url('hotel-inspection-issues')); ?><a class="hrim-issue-count-link" href="<?php echo esc_url($room_issue_url); ?>" title="Open issues for Room <?php echo esc_attr($r->room_no); ?>"><?php echo esc_html((int)$r->open_issues); ?></a><?php else: ?>0<?php endif; ?></td><td class="hrim-score-card-field" data-label="Last Inspection Date"><?php echo $r->last_inspection?esc_html($this->ist_date('d/m/y',$r->last_inspection)):'—'; ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="5" class="hrim-empty">No assets found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php return $this->shell('Room Score',ob_get_clean(),'room_scores');
    }

    public function floor_scores_shortcode(){
        if(!$this->can_reports() && !$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','floor_scores');
        global $wpdb; $p=$wpdb->prefix;
        $room_rows=$this->latest_room_score_rows(); $by_floor=[];
        foreach($room_rows as $r){
            if(!isset($by_floor[$r->floor_id])) $by_floor[$r->floor_id]=['name'=>$r->floor_name,'manager'=>$r->floor_manager,'scores'=>[],'issues'=>0,'issue_rooms'=>[]];
            if($r->score!==null) $by_floor[$r->floor_id]['scores'][]=(float)$r->score;
            $by_floor[$r->floor_id]['issues']+=(int)$r->open_issues;
            if((int)$r->open_issues>0) $by_floor[$r->floor_id]['issue_rooms'][(int)$r->id]=(string)$r->room_no;
        }
        $floors=$wpdb->get_results("SELECT f.id,f.name,u.display_name manager FROM {$p}hrim_floors f LEFT JOIN {$wpdb->users} u ON u.ID=f.floor_manager_id WHERE f.status='active' ORDER BY f.sort_order,f.name");
        usort($floors, static function($a,$b){ return strnatcasecmp((string)$a->name,(string)$b->name); });
        ob_start(); ?>
        <div class="hrim-panel hrim-master-panel hrim-no-pagination"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->excel_button('floor-scores'); ?></div></div><div class="hrim-table-wrap"><table class="hrim-table hrim-exportable hrim-mobile-score-cards hrim-floor-score-table"><thead><tr><th>Sl.</th><th>Floor No.</th><th>Manager</th><th>Score %</th><th>Rooms with Issues</th><th>Issues</th></tr></thead><tbody>
        <?php $sl=0; foreach($floors as $f): $data=$by_floor[$f->id]??['scores'=>[],'issues'=>0,'issue_rooms'=>[]]; $score=$data['scores']?round(array_sum($data['scores'])/count($data['scores'])):null; $issue_rooms=$data['issue_rooms']??[]; natcasesort($issue_rooms); ?><tr><td class="hrim-mobile-hide-sl" data-label="Sl."><?php echo ++$sl; ?></td><td class="hrim-score-card-title" data-label="Floor No."><strong><?php echo esc_html($f->name); ?></strong></td><td class="hrim-score-card-field" data-label="Manager"><?php echo esc_html($f->manager?:'—'); ?></td><td class="hrim-score-card-field" data-label="Score %"><?php if($score===null): ?><span class="hrim-muted">—</span><?php else: ?><span class="hrim-score-pill"><?php echo esc_html($score); ?>%</span><?php endif; ?></td><td class="hrim-score-card-field hrim-score-card-wide" data-label="Rooms with Issues"><?php if($issue_rooms): ?><div class="hrim-issue-room-list"><?php foreach($issue_rooms as $room_id=>$room_no): $room_issue_url=add_query_arg(['room_id'=>(int)$room_id,'open_only'=>'1'],$this->page_url('hotel-inspection-issues')); ?><a class="hrim-issue-room-link" href="<?php echo esc_url($room_issue_url); ?>" title="Open issues for Room <?php echo esc_attr($room_no); ?>"><?php echo esc_html($room_no); ?></a><?php endforeach; ?></div><?php else: ?>—<?php endif; ?></td><td class="hrim-score-card-field" data-label="Issues"><?php if((int)$data['issues']>0): $floor_issue_url=add_query_arg(['floor_id'=>(int)$f->id,'open_only'=>'1'],$this->page_url('hotel-inspection-issues')); ?><a class="hrim-issue-count-link" href="<?php echo esc_url($floor_issue_url); ?>" title="Open issues for <?php echo esc_attr($f->name); ?>"><?php echo esc_html((int)$data['issues']); ?></a><?php else: ?>0<?php endif; ?></td></tr><?php endforeach; ?><?php if(!$floors): ?><tr><td colspan="6" class="hrim-empty">No floors found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php return $this->shell('Floor Score',ob_get_clean(),'floor_scores');
    }

    public function sectionwise_checklist_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','sectionwise');
        global $wpdb; $p=$wpdb->prefix;
        $edit_id=absint($_GET['edit_checkpoint']??0);
        $default_section_id=absint($_GET['section_id']??0);
        $show_form=isset($_GET['add_checkpoint'])||$edit_id;
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}hrim_questions WHERE id=%d AND is_library=1",$edit_id)):null;
        $sections=$wpdb->get_results("SELECT s.*,d.name department_name,COUNT(q.id) checkpoint_count FROM {$p}hrim_sections s LEFT JOIN {$p}hrim_departments d ON d.id=s.department_id LEFT JOIN {$p}hrim_questions q ON q.section_id=s.id AND q.is_library=1 WHERE s.status='active' GROUP BY s.id ORDER BY s.sort_order,s.name");
        $questions=$wpdb->get_results("SELECT q.*,s.name section_name,s.sort_order section_order FROM {$p}hrim_questions q INNER JOIN {$p}hrim_sections s ON s.id=q.section_id WHERE q.is_library=1 AND s.status='active' ORDER BY s.sort_order,s.name,q.sort_order,q.id");
        $grouped=[]; foreach($questions as $q) $grouped[$q->section_id][]=$q;
        ob_start(); ?>
        <div class="hrim-panel hrim-master-panel hrim-sectionwise-panel"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->excel_button('sectionwise-checklist'); ?><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add_checkpoint','1',$this->page_url('hotel-inspection-sectionwise-checklist'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add Checkpoint</a></div></div><div class="hrim-table-wrap"><table class="hrim-table hrim-exportable hrim-sectionwise-table"><thead><tr><th>Sl.</th><th class="hrim-col-checkpoint">Checkpoint</th><th>Mandatory</th><th>Status</th><th class="hrim-no-export">Actions</th></tr></thead><tbody>
        <?php $sl=0; foreach($sections as $section): $items=$grouped[$section->id]??[]; ?><tr class="hrim-section-heading-row" data-section-name="<?php echo esc_attr(strtolower($section->name)); ?>"><td colspan="4"><div class="hrim-section-heading-main"><span class="hrim-section-heading-name"><?php echo esc_html($section->name); ?></span><span class="hrim-section-heading-count"><?php echo esc_html(count($items)); ?></span></div></td><td class="hrim-section-heading-action hrim-no-export"><a class="hrim-section-add-checkpoint" href="<?php echo esc_url(add_query_arg(['add_checkpoint'=>'1','section_id'=>(int)$section->id],$this->page_url('hotel-inspection-sectionwise-checklist'))); ?>" aria-label="Add checkpoint to <?php echo esc_attr($section->name); ?>" title="Add checkpoint"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span></a></td></tr><?php if(!$items): ?><tr class="hrim-section-checkpoint-row" data-section-id="<?php echo esc_attr($section->id); ?>"><td>—</td><td class="hrim-muted">No checkpoints</td><td>—</td><td>—</td><td>—</td></tr><?php else: foreach($items as $q): ?><tr class="hrim-section-checkpoint-row" data-section-id="<?php echo esc_attr($section->id); ?>"><td><?php echo ++$sl; ?></td><td class="hrim-col-checkpoint"><?php echo esc_html($q->question); ?></td><td><?php echo $q->required_flag?'Yes':'No'; ?></td><td><span class="hrim-badge success">Active</span></td><td class="hrim-no-export"><?php echo $this->action_buttons('question',$q->id,'hotel-inspection-sectionwise-checklist','edit_checkpoint'); ?></td></tr><?php endforeach; endif; endforeach; ?><?php if(!$sections): ?><tr><td colspan="5" class="hrim-empty">No checklist sections found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php if($show_form): ?><div class="hrim-modal open"><div class="hrim-modal-card"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-sectionwise-checklist')); ?>">×</a><h2><?php echo $edit?'Edit Checkpoint':'Add Checkpoint'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_question"><input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>"><input type="hidden" name="redirect_slug" value="hotel-inspection-sectionwise-checklist"><?php wp_nonce_field('hrim_save_question'); ?><label>Section<select name="section_id" required><option value="">Select Section</option><?php foreach($sections as $section): ?><option value="<?php echo esc_attr($section->id); ?>" <?php selected($edit->section_id??$default_section_id,$section->id); ?>><?php echo esc_html($section->name); ?><?php echo !empty($section->department_name)?' — '.esc_html($section->department_name):''; ?></option><?php endforeach; ?></select></label><label>Checkpoint<textarea name="question" required><?php echo esc_textarea($edit->question??''); ?></textarea></label><label>Display Order<input type="number" name="sort_order" value="<?php echo esc_attr($edit->sort_order??0); ?>"></label><label class="hrim-check"><input type="checkbox" name="required_flag" value="1" <?php checked($edit->required_flag??1,1); ?>> Mandatory</label><input type="hidden" name="question_type" value="yes_no"><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit?'Update':'Save'; ?></button></div></form></div></div><?php endif; ?>
        <?php return $this->shell('Sectionwise Checklist',ob_get_clean(),'sectionwise');
    }

    private function master_template_config($type){
        $configs=[
            'floors'=>['slug'=>'hotel-inspection-floors','headers'=>['Property','Floor','Floor Manager','Sort Order','Status']],
            'room_categories'=>['slug'=>'hotel-inspection-room-categories','headers'=>['Category','Description','Assigned Templates','Status']],
            'rooms'=>['slug'=>'hotel-inspection-rooms','headers'=>['Property','Floor','Asset','Asset Type','Applicable Templates','Status']],
            'departments'=>['slug'=>'hotel-inspection-departments','headers'=>['Department','Department Head','Description','Status']],
            'users'=>['slug'=>'hotel-inspection-users','headers'=>['Full Name','Username','Email','Mobile','Password','Role','Departments','Status','Views']],
        ];
        return $configs[$type]??null;
    }
    private function master_import_controls($type){
        $config=$this->master_template_config($type); if(!$config)return '';
        $download=wp_nonce_url(add_query_arg(['action'=>'hrim_download_master_template','type'=>$type],admin_url('admin-post.php')),'hrim_download_master_template_'.$type);
        $upload=add_query_arg(['import'=>'1','import_type'=>$type],$this->page_url($config['slug']));
        return '<a class="hrim-secondary hrim-template-download" data-no-spinner="1" href="'.esc_url($download).'" title="Download Template"><span class="dashicons dashicons-download"></span> Download Template</a><a class="hrim-secondary hrim-template-upload" href="'.esc_url($upload).'" title="Upload Template"><span class="dashicons dashicons-upload"></span> Upload Template</a>';
    }
    private function master_import_modal($type){
        $config=$this->master_template_config($type); if(!$config || empty($_GET['import']) || sanitize_key($_GET['import_type']??'')!==$type)return '';
        ob_start(); ?><div class="hrim-modal open"><div class="hrim-modal-card"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url($config['slug'])); ?>">×</a><h2>Upload Template</h2><form class="hrim-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_upload_master_template"><input type="hidden" name="type" value="<?php echo esc_attr($type); ?>"><?php wp_nonce_field('hrim_upload_master_template_'.$type); ?><label>CSV Template *<input type="file" name="template_file" accept=".csv,text/csv" required></label><p class="hrim-help">Download the template first, fill the required columns, and upload the CSV file. Duplicate records are not allowed.</p><div class="hrim-form-actions"><button class="hrim-primary" type="submit">Validate and Import</button></div></form></div></div><?php return ob_get_clean();
    }
    private function master_import_feedback($type){
        $key='hrim_import_'.get_current_user_id().'_'.$type; $data=get_transient($key); if(!$data)return '';
        delete_transient($key); ob_start();
        if(!empty($data['success'])):?><div class="hrim-alert success"><?php echo esc_html($data['success']); ?></div><?php endif;
        if(!empty($data['errors'])):?><div class="hrim-panel hrim-import-errors"><h2>Upload Validation Errors</h2><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Row No.</th><th>Column Name</th><th>Entered Value</th><th>Error</th></tr></thead><tbody><?php foreach($data['errors'] as $error): ?><tr><td><?php echo esc_html($error['row']); ?></td><td><?php echo esc_html($error['column']); ?></td><td><?php echo esc_html($error['value']); ?></td><td><?php echo esc_html($error['error']); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif;
        return ob_get_clean();
    }
    private function csv_header_key($value){$value=preg_replace('/^\xEF\xBB\xBF/','',(string)$value);return sanitize_key(str_replace([' ','-','/'],'_',trim($value)));}
    private function csv_list($value){return array_values(array_filter(array_map('trim',preg_split('/[,;|]+/',(string)$value))));}
    private function import_error(&$errors,$row,$column,$value,$message){$errors[]=['row'=>$row,'column'=>$column,'value'=>(string)$value,'error'=>$message];}
    private function lookup_user_id($value){
        $value=trim((string)$value); if($value==='')return 0;
        $u=get_user_by('email',$value); if(!$u)$u=get_user_by('login',$value);
        if($u)return (int)$u->ID;
        $users=get_users(['search'=>'*'.$value.'*','search_columns'=>['display_name'],'number'=>2]);
        return count($users)===1?(int)$users[0]->ID:0;
    }
    public function download_master_template(){
        if(!$this->can_manage())wp_die('Permission denied'); $type=sanitize_key($_GET['type']??''); $config=$this->master_template_config($type); if(!$config)wp_die('Invalid template'); check_admin_referer('hrim_download_master_template_'.$type);
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="roomscore-'.$type.'-template.csv"');
        $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,$config['headers']); fclose($out); exit;
    }
    public function upload_master_template(){
        if(!$this->can_manage())wp_die('Permission denied'); global $wpdb;
        $type=sanitize_key($_POST['type']??''); $config=$this->master_template_config($type); if(!$config)wp_die('Invalid template'); check_admin_referer('hrim_upload_master_template_'.$type);
        $redirect=$this->page_url($config['slug']); $key='hrim_import_'.get_current_user_id().'_'.$type;
        if(empty($_FILES['template_file']['tmp_name']) || !is_uploaded_file($_FILES['template_file']['tmp_name'])){set_transient($key,['errors'=>[['row'=>'—','column'=>'File','value'=>'','error'=>'Please select a CSV file.']]],300);wp_safe_redirect($redirect);exit;}
        $fh=fopen($_FILES['template_file']['tmp_name'],'r'); if(!$fh){set_transient($key,['errors'=>[['row'=>'—','column'=>'File','value'=>'','error'=>'The uploaded file could not be read.']]],300);wp_safe_redirect($redirect);exit;}
        $raw_headers=fgetcsv($fh); if(!$raw_headers){fclose($fh);set_transient($key,['errors'=>[['row'=>'—','column'=>'Header','value'=>'','error'=>'The CSV file is empty.']]],300);wp_safe_redirect($redirect);exit;}
        $headers=array_map([$this,'csv_header_key'],$raw_headers); $required=array_map([$this,'csv_header_key'],$config['headers']); $missing=array_diff($required,$headers); $errors=[];
        foreach($missing as $m)$this->import_error($errors,1,ucwords(str_replace('_',' ',$m)),'','Required column is missing from the template.');
        $rows=[];$row_no=1; while(($values=fgetcsv($fh))!==false){$row_no++; if(!array_filter($values,function($v){return trim((string)$v)!=='';}))continue; $values=array_pad($values,count($headers),''); $rows[]=['row'=>$row_no,'data'=>array_combine($headers,array_slice($values,0,count($headers)))];} fclose($fh);
        if(!$rows)$this->import_error($errors,2,'Data','','No data rows were found.');
        $seen=[]; $validated=[]; $status_ok=['active','inactive'];
        foreach($rows as $entry){$r=$entry['data'];$n=$entry['row'];$clean=[];
            if($type==='floors'){
                $property=trim($r['property']??'');$floor=trim($r['floor']??'');$manager=trim($r['floor_manager']??'');$sort=trim($r['sort_order']??'0');$status=strtolower(trim($r['status']??'active'));
                $single=$this->single_active_property();$hotel=$property!==''?$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$wpdb->prefix}hrim_hotels WHERE LOWER(TRIM(name))=LOWER(TRIM(%s))",$property)):$single;
                if(!$hotel)$this->import_error($errors,$n,'Property',$property,'Property not found.'); if($floor==='')$this->import_error($errors,$n,'Floor',$floor,'Floor is required.'); if(!is_numeric($sort))$this->import_error($errors,$n,'Sort Order',$sort,'Sort Order must be numeric.'); if(!in_array($status,$status_ok,true))$this->import_error($errors,$n,'Status',$status,'Use Active or Inactive.');
                $manager_id=$manager===''?0:$this->lookup_user_id($manager); if($manager!==''&&!$manager_id)$this->import_error($errors,$n,'Floor Manager',$manager,'User not found or name is ambiguous.');
                $dupkey=strtolower(($hotel->id??0).'|'.$floor); if(isset($seen[$dupkey]))$this->import_error($errors,$n,'Floor',$floor,'Duplicate floor in uploaded file.');$seen[$dupkey]=1;
                if($hotel && $floor!=='' && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_floors WHERE hotel_id=%d AND LOWER(TRIM(name))=LOWER(TRIM(%s))",$hotel->id,$floor)))$this->import_error($errors,$n,'Floor',$floor,'This floor already exists for the property.');
                $clean=['hotel_id'=>(int)($hotel->id??0),'name'=>$floor,'floor_manager_id'=>$manager_id?:null,'sort_order'=>(int)$sort,'status'=>$status];
            }elseif($type==='room_categories'){
                $name=trim($r['category']??'');$description=trim($r['description']??'');$status=strtolower(trim($r['status']??'active'));$templates=$this->csv_list($r['assigned_templates']??'');$tids=[];
                if($name==='')$this->import_error($errors,$n,'Category',$name,'Category is required.'); if(!in_array($status,$status_ok,true))$this->import_error($errors,$n,'Status',$status,'Use Active or Inactive.');$keyn=strtolower($name);if(isset($seen[$keyn]))$this->import_error($errors,$n,'Category',$name,'Duplicate category in uploaded file.');$seen[$keyn]=1;
                if($name!==''&&$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_room_categories WHERE LOWER(TRIM(name))=LOWER(TRIM(%s))",$name)))$this->import_error($errors,$n,'Category',$name,'This room category already exists.');
                foreach($templates as $tn){$tid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_templates WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) AND status='active'",$tn));if(!$tid)$this->import_error($errors,$n,'Assigned Templates',$tn,'Template not found.');else$tids[]=$tid;}
                $clean=['name'=>$name,'description'=>$description,'status'=>$status,'template_ids'=>$tids];
            }elseif($type==='rooms'){
                $property=trim($r['property']??'');$floor=trim($r['floor']??'');$asset=trim($r['asset']??'');$asset_type_name=trim($r['asset_type']??'Asset');$status=strtolower(trim($r['status']??'active'));$templates=$this->csv_list($r['applicable_templates']??'');$tids=[];
                $single=$this->single_active_property();$hotel=$property!==''?$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$wpdb->prefix}hrim_hotels WHERE LOWER(TRIM(name))=LOWER(TRIM(%s))",$property)):$single;if(!$hotel)$this->import_error($errors,$n,'Property',$property,'Property not found.');
                $floorrow=$hotel?$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$wpdb->prefix}hrim_floors WHERE hotel_id=%d AND LOWER(TRIM(name))=LOWER(TRIM(%s))",$hotel->id,$floor)):null;if(!$floorrow)$this->import_error($errors,$n,'Floor',$floor,'Floor not found under the selected property.');
                $asset_type=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$wpdb->prefix}hrim_asset_types WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) AND status='active'",$asset_type_name));if(!$asset_type)$this->import_error($errors,$n,'Asset Type',$asset_type_name,'Asset Type not found.');
                if($asset==='')$this->import_error($errors,$n,'Asset',$asset,'Asset is required.');if(!in_array($status,$status_ok,true))$this->import_error($errors,$n,'Status',$status,'Use Active or Inactive.');
                $dupkey=strtolower(($hotel->id??0).'|'.$asset);if(isset($seen[$dupkey]))$this->import_error($errors,$n,'Asset',$asset,'Duplicate asset in uploaded file.');$seen[$dupkey]=1;if($hotel&&$asset!==''&&$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_rooms WHERE hotel_id=%d AND room_no=%s",$hotel->id,$asset)))$this->import_error($errors,$n,'Asset',$asset,'This asset name or number already exists for the property.');
                foreach($templates as $tn){$tid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_templates WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) AND status='active'",$tn));if(!$tid)$this->import_error($errors,$n,'Applicable Templates',$tn,'Template not found.');else$tids[]=$tid;}
                $clean=['hotel_id'=>(int)($hotel->id??0),'floor_id'=>(int)($floorrow->id??0),'asset_type_id'=>(int)($asset_type->id??0),'category_id'=>null,'category'=>'','room_no'=>$asset,'status'=>$status,'template_ids'=>$tids];
            }elseif($type==='departments'){
                $name=trim($r['department']??'');$head_value=trim($r['department_head']??'');$head_id=$head_value===''?0:$this->lookup_user_id($head_value);$description=trim($r['description']??'');$status=strtolower(trim($r['status']??'active'));if($head_value!==''&&!$head_id)$this->import_error($errors,$n,'Department Head',$head_value,'User not found or name is ambiguous.');if($head_id&&!$this->normalize_mobile_number(get_user_meta($head_id,'hrim_mobile',true)))$this->import_error($errors,$n,'Department Head',$head_value,'Selected user does not have a mobile number.');if($name==='')$this->import_error($errors,$n,'Department',$name,'Department is required.');if(!in_array($status,$status_ok,true))$this->import_error($errors,$n,'Status',$status,'Use Active or Inactive.');$keyn=strtolower($name);if(isset($seen[$keyn]))$this->import_error($errors,$n,'Department',$name,'Duplicate department in uploaded file.');$seen[$keyn]=1;if($name!==''&&$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_departments WHERE LOWER(TRIM(name))=LOWER(TRIM(%s))",$name)))$this->import_error($errors,$n,'Department',$name,'This department already exists.');$clean=['name'=>$name,'description'=>$description,'department_head_user_id'=>$head_id?:null,'sort_order'=>0,'status'=>$status];
            }elseif($type==='users'){
                $name=trim($r['full_name']??'');$username=sanitize_user($r['username']??'',true);$email=sanitize_email($r['email']??'');$mobile=$this->normalize_mobile_number($r['mobile']??'');if(strlen($mobile)<10||strlen($mobile)>15)$this->import_error($errors,$n,'Mobile',$r['mobile']??'','Valid mobile number with country code is required.');$password=(string)($r['password']??'');$role=strtolower(trim($r['role']??''));$status=strtolower(trim($r['status']??'active'));$departments=$this->csv_list($r['departments']??'');$views=$this->csv_list($r['views']??'All');$roles=['worker'=>'hrim_worker','supervisor'=>'hrim_supervisor','manager'=>'hrim_manager','owner'=>'hrim_owner'];$dids=[];$view_slugs=[];
                if($name==='')$this->import_error($errors,$n,'Full Name',$name,'Full Name is required.');if($username==='')$this->import_error($errors,$n,'Username',$r['username']??'','Valid Username is required.');if(!$email)$this->import_error($errors,$n,'Email',$r['email']??'','Valid Email is required.');if(strlen($password)<8)$this->import_error($errors,$n,'Password','********','Password must contain at least 8 characters.');if(!isset($roles[$role]))$this->import_error($errors,$n,'Role',$role,'Use Worker, Supervisor, Manager or Owner.');if(!in_array($status,$status_ok,true))$this->import_error($errors,$n,'Status',$status,'Use Active or Inactive.');
                foreach([['username',$username],['email',$email]] as $pair){$k=$pair[0].'|'.strtolower($pair[1]);if(isset($seen[$k]))$this->import_error($errors,$n,ucfirst($pair[0]),$pair[1],'Duplicate value in uploaded file.');$seen[$k]=1;}if($username&&username_exists($username))$this->import_error($errors,$n,'Username',$username,'Username already exists.');if($email&&email_exists($email))$this->import_error($errors,$n,'Email',$email,'Email already exists.');
                foreach($departments as $dn){$did=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_departments WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) AND status='active'",$dn));if(!$did)$this->import_error($errors,$n,'Departments',$dn,'Active department not found.');else$dids[]=$did;}if(!$dids)$this->import_error($errors,$n,'Departments',implode(', ',$departments),'At least one valid department is required.');
                $viewmap=$this->configurable_view_map();if(count($views)===1&&strtolower($views[0])==='all')$view_slugs=$this->all_view_slugs();else{foreach($views as $vn){$found='';foreach($viewmap as $v){if(strtolower($v['label'])===strtolower($vn)||strtolower($v['slug'])===strtolower($vn)){$found=$v['slug'];break;}}if(!$found)$this->import_error($errors,$n,'Views',$vn,'View name not found.');else$view_slugs[]=$found;}}if(!$view_slugs)$this->import_error($errors,$n,'Views',implode(', ',$views),'At least one valid view is required.');
                $clean=['display_name'=>$name,'username'=>$username,'email'=>$email,'mobile'=>$mobile,'password'=>$password,'role'=>$roles[$role]??'','status'=>$status,'department_ids'=>$dids,'allowed_views'=>$view_slugs];
            }
            $validated[]=$clean;
        }
        if($errors){set_transient($key,['errors'=>$errors],600);wp_safe_redirect($redirect);exit;}
        $imported=0;
        foreach($validated as $data){
            if($type==='floors'){$ok=$wpdb->insert($wpdb->prefix.'hrim_floors',$data);if($ok)$imported++;}
            elseif($type==='room_categories'){$tids=$data['template_ids'];unset($data['template_ids']);if($wpdb->insert($wpdb->prefix.'hrim_room_categories',$data)){ $cid=(int)$wpdb->insert_id;foreach($tids as $tid)$wpdb->insert($wpdb->prefix.'hrim_category_templates',['category_id'=>$cid,'template_id'=>$tid]);$imported++;}}
            elseif($type==='rooms'){$tids=$data['template_ids'];unset($data['template_ids']);$data['template_id']=null;if($wpdb->insert($wpdb->prefix.'hrim_rooms',$data)){ $rid=(int)$wpdb->insert_id;foreach($tids as $tid)$wpdb->insert($wpdb->prefix.'hrim_room_templates',['room_id'=>$rid,'template_id'=>$tid]);$imported++;}}
            elseif($type==='departments'){if($wpdb->insert($wpdb->prefix.'hrim_departments',$data)){ $did=(int)$wpdb->insert_id; if(!empty($data['department_head_user_id']))$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}hrim_user_departments (user_id,department_id) VALUES (%d,%d)",(int)$data['department_head_user_id'],$did)); $imported++; }}
            elseif($type==='users'){$uid=wp_create_user($data['username'],$data['password'],$data['email']);if(!is_wp_error($uid)){wp_update_user(['ID'=>$uid,'display_name'=>$data['display_name']]);(new WP_User($uid))->set_role($data['role']);update_user_meta($uid,'hrim_user_status',$data['status']);update_user_meta($uid,'hrim_mobile',$data['mobile']);update_user_meta($uid,'hrim_allowed_views',$data['allowed_views']);foreach($data['department_ids'] as $did)$wpdb->insert($wpdb->prefix.'hrim_user_departments',['user_id'=>$uid,'department_id'=>$did]);$imported++;}}
        }
        set_transient($key,['success'=>$imported.' record(s) imported successfully.'],300);wp_safe_redirect($redirect);exit;
    }

    public function properties_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','properties');
        global $wpdb; $edit_id=absint($_GET['edit']??0); $show_form=isset($_GET['add'])||$edit_id; $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_hotels WHERE id=%d",$edit_id)):null;
        $hotels=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_hotels ORDER BY name"); ob_start(); ?>
        <div class="hrim-panel hrim-master-panel"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->excel_button('properties'); ?><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add','1',$this->page_url('hotel-inspection-properties'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add Property</a></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><th>Property</th><th>Address</th><th>Status</th><th class="hrim-no-export">Actions</th></tr></thead><tbody><?php $sl=0; foreach($hotels as $h): ?><tr><td><?php echo ++$sl; ?></td><td><strong><?php echo esc_html($h->name); ?></strong></td><td><?php echo esc_html($h->address); ?></td><td><span class="hrim-badge <?php echo $h->status==='active'?'success':''; ?>"><?php echo esc_html(ucfirst($h->status)); ?></span></td><td><?php echo $this->action_buttons('hotel',$h->id,'hotel-inspection-properties'); ?></td></tr><?php endforeach; ?><?php if(!$hotels): ?><tr><td colspan="5" class="hrim-empty">No properties found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php if($show_form): ?><div class="hrim-modal open"><div class="hrim-modal-card"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-properties')); ?>">×</a><h2><?php echo $edit?'Edit Property':'Add Property'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_hotel"><input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>"><?php wp_nonce_field('hrim_save_hotel'); ?><label>Property Name<input name="name" required value="<?php echo esc_attr($edit->name??''); ?>"></label><label>Address<textarea name="address"><?php echo esc_textarea($edit->address??''); ?></textarea></label><label>Status<select name="status"><option value="active" <?php selected($edit->status??'active','active'); ?>>Active</option><option value="inactive" <?php selected($edit->status??'active','inactive'); ?>>Inactive</option></select></label><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit?'Update':'Save'; ?></button></div></form></div></div><?php endif; ?>
        <?php return $this->shell('Properties',ob_get_clean(),'properties');
    }

    public function floors_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','floors');
        global $wpdb; $edit_id=absint($_GET['edit']??0); $show_form=isset($_GET['add'])||$edit_id; $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_floors WHERE id=%d",$edit_id)):null;
        $hotels=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_hotels WHERE status='active' ORDER BY name");
        $floor_managers=$this->active_app_users(['administrator','hrim_manager','hrim_inspector','hrim_housekeeping']);
        $floors=$wpdb->get_results("SELECT f.*,h.name hotel_name,u.display_name floor_manager_name FROM {$wpdb->prefix}hrim_floors f LEFT JOIN {$wpdb->prefix}hrim_hotels h ON h.id=f.hotel_id LEFT JOIN {$wpdb->users} u ON u.ID=f.floor_manager_id ORDER BY h.name,f.sort_order,f.name"); ob_start(); ?>
        <?php $single_property=$this->single_active_property(); ?>
        <?php echo $this->master_import_feedback('floors'); ?><div class="hrim-panel hrim-master-panel"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->master_import_controls('floors'); ?><?php echo $this->excel_button('floors'); ?><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add','1',$this->page_url('hotel-inspection-floors'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add Floor</a></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><?php if(!$single_property): ?><th>Property</th><?php endif; ?><th>Floor</th><th>Floor Manager</th><th>Sort Order</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php $sl=0; foreach($floors as $f): ?><tr><td><?php echo ++$sl; ?></td><?php if(!$single_property): ?><td><?php echo esc_html($f->hotel_name); ?></td><?php endif; ?><td><strong><?php echo esc_html($f->name); ?></strong></td><td><?php echo esc_html($f->floor_manager_name ?: '—'); ?></td><td><?php echo esc_html($f->sort_order); ?></td><td><span class="hrim-badge <?php echo $f->status==='active'?'success':''; ?>"><?php echo esc_html(ucfirst($f->status)); ?></span></td><td><?php echo $this->action_buttons('floor',$f->id,'hotel-inspection-floors'); ?></td></tr><?php endforeach; ?><?php if(!$floors): ?><tr><td colspan="<?php echo $single_property?6:7; ?>" class="hrim-empty">No floors found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php if($show_form): ?><div class="hrim-modal open"><div class="hrim-modal-card"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-floors')); ?>">×</a><h2><?php echo $edit?'Edit Floor':'Add Floor'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_floor"><input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>"><?php wp_nonce_field('hrim_save_floor'); ?><?php if($single_property): ?><input type="hidden" name="hotel_id" value="<?php echo esc_attr($single_property->id); ?>"><?php else: ?><label>Property<select name="hotel_id" required><option value="">Select</option><?php foreach($hotels as $h): ?><option value="<?php echo esc_attr($h->id); ?>" <?php selected($edit->hotel_id??0,$h->id); ?>><?php echo esc_html($h->name); ?></option><?php endforeach; ?></select></label><?php endif; ?><label>Floor Name<input name="name" required value="<?php echo esc_attr($edit->name??''); ?>"></label><label>Floor Manager<select name="floor_manager_id"><option value="0">Select Floor Manager</option><?php foreach($floor_managers as $manager): ?><option value="<?php echo esc_attr($manager->ID); ?>" <?php selected((int)($edit->floor_manager_id??0),(int)$manager->ID); ?>><?php echo esc_html($manager->display_name); ?></option><?php endforeach; ?></select></label><label>Sort Order<input type="number" name="sort_order" value="<?php echo esc_attr($edit->sort_order??0); ?>"></label><label>Status<select name="status"><option value="active" <?php selected($edit->status??'active','active'); ?>>Active</option><option value="inactive" <?php selected($edit->status??'active','inactive'); ?>>Inactive</option></select></label><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit?'Update':'Save'; ?></button></div></form></div></div><?php endif; ?>
        <?php echo $this->master_import_modal('floors'); return $this->shell('Floors',ob_get_clean(),'floors');
    }

    public function asset_types_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','asset_types');
        global $wpdb;
        HRIM_Activator::create_tables();
        $edit_id=absint($_GET['edit']??0); $show_form=isset($_GET['add'])||$edit_id;
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_asset_types WHERE id=%d",$edit_id)):null;
        $rows=$wpdb->get_results("SELECT at.*,COUNT(r.id) asset_count FROM {$wpdb->prefix}hrim_asset_types at LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.asset_type_id=at.id GROUP BY at.id ORDER BY at.sort_order,at.name");
        ob_start(); ?>
        <div class="hrim-panel hrim-master-panel"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->excel_button('asset-types'); ?><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add','1',$this->page_url('hotel-inspection-asset-types'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add Asset Type</a></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><th>Asset Type</th><th>Description</th><th>Assets</th><th>Sort Order</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php $sl=0; foreach($rows as $r): ?><tr><td><?php echo ++$sl; ?></td><td><strong><?php echo esc_html($r->name); ?></strong></td><td><?php echo esc_html($r->description); ?></td><td><?php echo esc_html($r->asset_count); ?></td><td><?php echo esc_html($r->sort_order); ?></td><td><span class="hrim-badge <?php echo $r->status==='active'?'success':''; ?>"><?php echo esc_html(ucfirst($r->status)); ?></span></td><td><?php echo $this->action_buttons('asset_type',$r->id,'hotel-inspection-asset-types'); ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="9" class="hrim-empty">No asset types found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php if($show_form): ?><div class="hrim-modal open"><div class="hrim-modal-card"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-asset-types')); ?>">×</a><h2><?php echo $edit?'Edit Asset Type':'Add Asset Type'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_asset_type"><input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>"><?php wp_nonce_field('hrim_save_asset_type'); ?><label>Asset Type *<input name="name" required value="<?php echo esc_attr($edit->name??''); ?>"></label><label>Description<textarea name="description"><?php echo esc_textarea($edit->description??''); ?></textarea></label><label>Sort Order<input type="number" name="sort_order" value="<?php echo esc_attr($edit->sort_order??0); ?>"></label><label>Status<select name="status"><option value="active" <?php selected($edit->status??'active','active'); ?>>Active</option><option value="inactive" <?php selected($edit->status??'active','inactive'); ?>>Inactive</option></select></label><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit?'Update Asset Type':'Save Asset Type'; ?></button></div></form></div></div><?php endif; ?>
        <?php return $this->shell('Asset Types',ob_get_clean(),'asset_types');
    }

    public function room_categories_shortcode(){
        if(!headers_sent()){
            wp_safe_redirect($this->page_url('hotel-inspection-rooms'));
            exit;
        }
        return '<script>window.location.href='.wp_json_encode($this->page_url('hotel-inspection-rooms')).';</script>';
    }

    public function rooms_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','rooms');
        global $wpdb;
        $edit_id=absint($_GET['edit']??0); $show_form=isset($_GET['add'])||$edit_id;
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_rooms WHERE id=%d",$edit_id)):null;
        $hotels=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_hotels WHERE status='active' ORDER BY name");
        $floors=$wpdb->get_results("SELECT f.*,h.name hotel_name FROM {$wpdb->prefix}hrim_floors f LEFT JOIN {$wpdb->prefix}hrim_hotels h ON h.id=f.hotel_id WHERE f.status='active' ORDER BY h.name,f.sort_order,f.name");
        $asset_types=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_asset_types WHERE status='active' ORDER BY sort_order,name");
        $templates=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_templates WHERE status='active' ORDER BY name");

        $selected_templates=[];
        $inherited_templates=[];
        $excluded_templates=[];
        $available_room_questions=[];
        $excluded_question_ids=[];
        $room_additions=[];
        if($edit_id && $edit){
            $direct=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT template_id FROM {$wpdb->prefix}hrim_room_templates WHERE room_id=%d",$edit_id)));
            $excluded_templates=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT template_id FROM {$wpdb->prefix}hrim_room_template_exclusions WHERE room_id=%d",$edit_id)));
            $legacy_inherited=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT template_id FROM {$wpdb->prefix}hrim_category_templates WHERE category_id=%d",(int)$edit->category_id)));
            $inherited_templates=[];
            $selected_templates=array_values(array_unique(array_merge(array_diff($legacy_inherited,$excluded_templates),$direct)));
            $excluded_question_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT question_id FROM {$wpdb->prefix}hrim_room_question_exclusions WHERE room_id=%d",$edit_id)));
            if($selected_templates){
                $ph=implode(',',array_fill(0,count($selected_templates),'%d'));
                $available_room_questions=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT q.id,q.question,q.question_type,q.required_flag,q.sort_order,s.id section_id,s.name section_name,s.sort_order section_order,GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') template_names FROM {$wpdb->prefix}hrim_template_sections ts INNER JOIN {$wpdb->prefix}hrim_templates t ON t.id=ts.template_id INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=ts.section_id INNER JOIN {$wpdb->prefix}hrim_questions q ON q.section_id=s.id AND q.is_library=1 LEFT JOIN {$wpdb->prefix}hrim_room_question_removals rr ON rr.room_id=%d AND rr.question_id=q.id WHERE ts.template_id IN ($ph) AND rr.question_id IS NULL GROUP BY q.id,q.question,q.question_type,q.required_flag,q.sort_order,s.id,s.name,s.sort_order ORDER BY s.sort_order,s.name,q.sort_order,q.id",array_merge([$edit_id],$selected_templates)));
            }
            $room_additions=$wpdb->get_results($wpdb->prepare("SELECT a.id addition_id,a.template_id,a.question_id,q.section_id,q.question,q.question_type,q.required_flag,q.sort_order FROM {$wpdb->prefix}hrim_room_question_additions a INNER JOIN {$wpdb->prefix}hrim_questions q ON q.id=a.question_id WHERE a.room_id=%d ORDER BY a.id",$edit_id));
        }
        $selected_asset_sections=[];
        if($selected_templates){
            $ph=implode(',',array_fill(0,count($selected_templates),'%d'));
            $selected_asset_sections=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT s.id,s.name,s.sort_order FROM {$wpdb->prefix}hrim_template_sections ts INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=ts.section_id WHERE ts.template_id IN ($ph) AND s.status='active' ORDER BY s.sort_order,s.name",$selected_templates));
        }

        $rooms=$wpdb->get_results("SELECT r.*,h.name hotel_name,f.name floor_name,at.name asset_type_name FROM {$wpdb->prefix}hrim_rooms r LEFT JOIN {$wpdb->prefix}hrim_hotels h ON h.id=r.hotel_id LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=r.floor_id LEFT JOIN {$wpdb->prefix}hrim_asset_types at ON at.id=r.asset_type_id ORDER BY h.name,f.sort_order,r.room_no");
        foreach($rooms as $r){
            $effective=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT t.name FROM {$wpdb->prefix}hrim_templates t WHERE t.id IN (SELECT template_id FROM {$wpdb->prefix}hrim_room_templates WHERE room_id=%d UNION SELECT ct.template_id FROM {$wpdb->prefix}hrim_category_templates ct WHERE ct.category_id=%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}hrim_room_template_exclusions x WHERE x.room_id=%d AND x.template_id=ct.template_id)) ORDER BY t.name",$r->id,$r->category_id,$r->id));
            $r->effective_template_names=implode(', ',$effective);
        }
        ob_start(); ?>
        <?php $single_property=$this->single_active_property(); ?>
        <?php echo $this->master_import_feedback('rooms'); ?><div class="hrim-panel hrim-master-panel"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->master_import_controls('rooms'); ?><?php echo $this->excel_button('rooms'); ?><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add','1',$this->page_url('hotel-inspection-rooms'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add Asset</a></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><?php if(!$single_property): ?><th>Property</th><?php endif; ?><th>Floor</th><th>Asset</th><th>Asset Type</th><th>Applicable Templates</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php $sl=0; foreach($rooms as $r): ?><tr><td><?php echo ++$sl; ?></td><?php if(!$single_property): ?><td><?php echo esc_html($r->hotel_name); ?></td><?php endif; ?><td><?php echo esc_html($r->floor_name); ?></td><td><strong><?php echo esc_html($r->room_no); ?></strong></td><td><?php echo esc_html($r->asset_type_name?:'Asset'); ?></td><td><?php echo esc_html($r->effective_template_names?:'No template assigned'); ?></td><td><span class="hrim-badge <?php echo $r->status==='active'?'success':''; ?>"><?php echo esc_html(ucfirst($r->status)); ?></span></td><td><?php echo $this->action_buttons('room',$r->id,'hotel-inspection-rooms'); ?></td></tr><?php endforeach; ?><?php if(!$rooms): ?><tr><td colspan="<?php echo $single_property?7:8; ?>" class="hrim-empty">No assets found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php if($show_form): ?><div class="hrim-modal open"><div class="hrim-modal-card hrim-modal-wide"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-rooms')); ?>">×</a><h2><?php echo $edit?'Edit Asset':'Add Asset'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_room"><input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>"><?php wp_nonce_field('hrim_save_room'); ?><div class="hrim-grid-2"><?php if($single_property): ?><input type="hidden" name="hotel_id" value="<?php echo esc_attr($single_property->id); ?>"><?php else: ?><label>Property<select name="hotel_id" required><option value="">Select</option><?php foreach($hotels as $h): ?><option value="<?php echo esc_attr($h->id); ?>" <?php selected($edit->hotel_id??0,$h->id); ?>><?php echo esc_html($h->name); ?></option><?php endforeach; ?></select></label><?php endif; ?><label>Floor<select name="floor_id" required><option value="">Select</option><?php foreach($floors as $f): ?><option value="<?php echo esc_attr($f->id); ?>" <?php selected($edit->floor_id??0,$f->id); ?>><?php echo esc_html($single_property ? $f->name : ($f->hotel_name.' – '.$f->name)); ?></option><?php endforeach; ?></select></label><label>Asset Name / Number<input name="room_no" required value="<?php echo esc_attr($edit->room_no??''); ?>"></label><label>Asset Type<select name="asset_type_id" required><option value="">Select Asset Type</option><?php foreach($asset_types as $asset_type): ?><option value="<?php echo esc_attr($asset_type->id); ?>" <?php selected($edit->asset_type_id??0,$asset_type->id); ?>><?php echo esc_html($asset_type->name); ?></option><?php endforeach; ?></select></label></div><label>Applicable Inspection Templates<select name="template_ids[]" id="hrim-room-templates" multiple size="8"><?php foreach($templates as $t): ?><option value="<?php echo esc_attr($t->id); ?>" <?php selected(in_array((int)$t->id,$selected_templates,true)); ?>><?php echo esc_html($t->name); ?></option><?php endforeach; ?></select></label>
        <?php if($edit_id): ?>
        <div class="hrim-subpanel hrim-asset-checkpoint-preview">
            <h3>Applicable Checkpoints</h3>
            <?php if($available_room_questions): ?>
                <div class="hrim-table-wrap">
                    <table class="hrim-table hrim-applicable-checkpoints-table">
                        <colgroup><col class="hrim-checkpoint-col-main"><col class="hrim-checkpoint-col-show"><col class="hrim-checkpoint-col-action"></colgroup>
                        <thead><tr><th>Checkpoint</th><th class="hrim-checkpoint-show-col"><span class="hrim-checkpoint-header-text">Show</span><span class="hrim-checkpoint-header-icon dashicons dashicons-visibility" aria-hidden="true"></span></th><th class="hrim-checkpoint-action-col"><span class="hrim-checkpoint-header-text">Action</span><span class="hrim-checkpoint-header-icon dashicons dashicons-no-alt" aria-hidden="true"></span></th></tr></thead>
                        <input type="hidden" name="hrim_manage_additional_checkpoints" value="1">
                        <tbody>
                        <?php
                        $additions_by_section=[];
                        foreach($room_additions as $addition){
                            $additions_by_section[(int)$addition->section_id][]=$addition;
                        }
                        $preview_section_id=0;
                        foreach($available_room_questions as $checkpoint):
                        ?>
                            <?php if($preview_section_id!==(int)$checkpoint->section_id): $preview_section_id=(int)$checkpoint->section_id; ?>
                                <tr class="hrim-applicable-section-row" data-section-id="<?php echo esc_attr($preview_section_id); ?>">
                                    <td colspan="3">
                                        <div class="hrim-applicable-section-heading">
                                            <span><?php echo esc_html($checkpoint->section_name); ?></span>
                                            <button type="button" class="hrim-section-add-checkpoint" data-section-id="<?php echo esc_attr($preview_section_id); ?>" title="Add checkpoint under <?php echo esc_attr($checkpoint->section_name); ?>" aria-label="Add checkpoint under <?php echo esc_attr($checkpoint->section_name); ?>"><span class="dashicons dashicons-plus-alt2"></span></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php foreach(($additions_by_section[$preview_section_id]??[]) as $custom_checkpoint): ?>
                                    <tr class="hrim-custom-checkpoint-row" data-section-id="<?php echo esc_attr($preview_section_id); ?>">
                                        <td>
                                            <input type="hidden" name="additional_id[]" value="<?php echo esc_attr($custom_checkpoint->addition_id); ?>">
                                            <input type="hidden" name="additional_section_id[]" value="<?php echo esc_attr($preview_section_id); ?>">
                                            <input class="hrim-custom-checkpoint-input" type="text" name="additional_question[]" required value="<?php echo esc_attr($custom_checkpoint->question); ?>" aria-label="Edit checkpoint">
                                        </td>
                                        <td class="hrim-checkpoint-show-col"><input type="checkbox" checked disabled aria-label="Show <?php echo esc_attr($custom_checkpoint->question); ?>"></td>
                                        <td class="hrim-checkpoint-action-col"><button type="submit" class="hrim-delete-custom-checkpoint" name="delete_additional_checkpoint_id" value="<?php echo esc_attr($custom_checkpoint->addition_id); ?>" formnovalidate title="Permanently delete this checkpoint from this asset" aria-label="Permanently delete this checkpoint from this asset" onclick="return confirm('Permanently delete this checkpoint from this asset?');"><span class="dashicons dashicons-no-alt"></span></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr data-section-id="<?php echo esc_attr($preview_section_id); ?>">
                                <td><?php echo esc_html($checkpoint->question); ?></td>
                                <td class="hrim-checkpoint-show-col"><input type="checkbox" name="included_question_ids[]" value="<?php echo esc_attr($checkpoint->id); ?>" <?php checked(!in_array((int)$checkpoint->id,$excluded_question_ids,true)); ?> aria-label="Show <?php echo esc_attr($checkpoint->question); ?>"></td>
                                <td class="hrim-checkpoint-action-col"><button type="submit" class="hrim-delete-custom-checkpoint" name="delete_standard_checkpoint_id" value="<?php echo esc_attr($checkpoint->id); ?>" formnovalidate title="Remove this checkpoint from this asset" aria-label="Remove this checkpoint from this asset" onclick="return confirm('Permanently delete this checkpoint from this asset and its backend asset data?');"><span class="dashicons dashicons-no-alt"></span></button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="hrim-empty">No checkpoints are available under the selected inspection templates.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <label>Status<select name="status"><option value="active" <?php selected($edit->status??'active','active'); ?>>Active</option><option value="inactive" <?php selected($edit->status??'active','inactive'); ?>>Inactive</option></select></label><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit?'Update Asset':'Save Asset'; ?></button></div></form></div></div><?php endif; ?>
        <?php echo $this->master_import_modal('rooms'); return $this->shell('Assets',ob_get_clean(),'rooms');
    }

    public function sections_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','sections');
        global $wpdb;
        $edit_id=absint($_GET['edit']??0); $show_form=isset($_GET['add'])||$edit_id;
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_sections WHERE id=%d",$edit_id)):null;
        $rows=$wpdb->get_results("SELECT s.*,d.name department_name,(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_questions q WHERE q.section_id=s.id) point_count FROM {$wpdb->prefix}hrim_sections s LEFT JOIN {$wpdb->prefix}hrim_departments d ON d.id=s.department_id ORDER BY s.sort_order,s.name,s.id");
        $departments=$this->active_departments();
        ob_start(); ?>
        <div class="hrim-panel hrim-master-panel"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->excel_button('checklist-sections'); ?><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add','1',$this->page_url('hotel-inspection-checklist-sections'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add Section</a></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><th>Date</th><th>Section</th><th>Department</th><th>Description</th><th>Display Order</th><th>Checklist Points</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php $sl=0; foreach($rows as $r): ?><tr><td><?php echo ++$sl; ?></td><td><?php echo !empty($r->created_at)?esc_html($this->ist_date('d/m/y',$r->created_at)):'—'; ?></td><td><strong><?php echo esc_html($r->name); ?></strong></td><td><?php echo esc_html($r->department_name?:'General'); ?></td><td><?php echo esc_html($r->description); ?></td><td><?php echo esc_html($r->sort_order); ?></td><td><?php echo esc_html($r->point_count); ?></td><td><span class="hrim-badge <?php echo $r->status==='active'?'success':''; ?>"><?php echo esc_html(ucfirst($r->status)); ?></span></td><td><?php echo $this->action_buttons('section',$r->id,'hotel-inspection-checklist-sections'); ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="9" class="hrim-empty">No checklist sections found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php if($show_form): ?><div class="hrim-modal open"><div class="hrim-modal-card"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-checklist-sections')); ?>">×</a><h2><?php echo $edit?'Edit Checklist Section':'Add Checklist Section'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_section"><input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>"><?php wp_nonce_field('hrim_save_section'); ?><label>Section Name<input name="name" required value="<?php echo esc_attr($edit->name??''); ?>"></label><label>Department<select name="department_id" required><option value="">Select Department</option><?php foreach($departments as $department): ?><option value="<?php echo esc_attr($department->id); ?>" <?php selected($edit->department_id??0,$department->id); ?>><?php echo esc_html($department->name); ?></option><?php endforeach; ?></select></label><label>Description<textarea name="description"><?php echo esc_textarea($edit->description??''); ?></textarea></label><label>Display Order<input type="number" name="sort_order" value="<?php echo esc_attr($edit->sort_order??0); ?>"></label><label>Status<select name="status"><option value="active" <?php selected($edit->status??'active','active'); ?>>Active</option><option value="inactive" <?php selected($edit->status??'active','inactive'); ?>>Inactive</option></select></label><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit?'Update':'Save'; ?></button></div></form></div></div><?php endif; ?>
        <?php return $this->shell('Checklist Sections',ob_get_clean(),'sections');
    }

    public function templates_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','templates');
        global $wpdb;
        $et=absint($_GET['edit_template']??0); $eq=absint($_GET['edit_question']??0);
        $show_t=isset($_GET['add_template'])||$et; $show_q=isset($_GET['add_question'])||$eq;
        $edit_t=$et?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_templates WHERE id=%d",$et)):null;
        $edit_q=$eq?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_questions WHERE id=%d",$eq)):null;
        $templates=$wpdb->get_results("SELECT t.*, (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_template_sections ts WHERE ts.template_id=t.id) section_count, (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_template_sections ts INNER JOIN {$wpdb->prefix}hrim_questions q ON q.section_id=ts.section_id AND q.is_library=1 WHERE ts.template_id=t.id) point_count FROM {$wpdb->prefix}hrim_templates t ORDER BY t.id DESC");
        $sections=$wpdb->get_results("SELECT s.*, (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_questions q WHERE q.section_id=s.id AND q.is_library=1) point_count FROM {$wpdb->prefix}hrim_sections s WHERE s.status='active' ORDER BY s.sort_order,s.name");
        $selected_sections=$et?array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT section_id FROM {$wpdb->prefix}hrim_template_sections WHERE template_id=%d ORDER BY sort_order,section_id",$et))):[];
        $qs=$wpdb->get_results("SELECT q.*,s.name section_display FROM {$wpdb->prefix}hrim_questions q LEFT JOIN {$wpdb->prefix}hrim_sections s ON s.id=q.section_id WHERE q.is_library=1 ORDER BY COALESCE(s.sort_order,999999),s.name,q.sort_order,q.id");
        ob_start(); ?>
        <div class="hrim-panel hrim-master-panel"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->excel_button('inspection-templates'); ?><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add_template','1',$this->page_url('hotel-inspection-templates'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add Template</a></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><th>Date</th><th>Name</th><th>Sections</th><th>Checklist Points</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php $sl=0; foreach($templates as $t): ?><tr><td><?php echo ++$sl; ?></td><td><?php echo !empty($t->created_at)?esc_html($this->ist_date('d/m/y',$t->created_at)):'—'; ?></td><td><strong><?php echo esc_html($t->name); ?></strong></td><td><?php echo esc_html($t->section_count); ?></td><td><?php echo esc_html($t->point_count); ?></td><td><span class="hrim-badge <?php echo $t->status==='active'?'success':''; ?>"><?php echo esc_html(ucfirst($t->status)); ?></span></td><td><?php echo $this->action_buttons('template',$t->id,'hotel-inspection-templates','edit_template'); ?></td></tr><?php endforeach; ?><?php if(!$templates): ?><tr><td colspan="9" class="hrim-empty">No templates found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php if($show_t): ?><div class="hrim-modal open"><div class="hrim-modal-card hrim-modal-wide"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-templates')); ?>">×</a><h2><?php echo $edit_t?'Edit Template':'Create Template'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_template"><input type="hidden" name="id" value="<?php echo esc_attr($et); ?>"><?php wp_nonce_field('hrim_save_template'); ?><label>Template Name<input name="name" required value="<?php echo esc_attr($edit_t->name??''); ?>"></label><div><div class="hrim-section-picker"><?php foreach($sections as $section): ?><label class="hrim-section-option"><input type="checkbox" name="section_ids[]" value="<?php echo esc_attr($section->id); ?>" <?php checked(in_array((int)$section->id,$selected_sections,true)); ?>><span><strong><?php echo esc_html($section->name); ?></strong><small><?php echo esc_html($section->point_count); ?> checkpoints</small></span></label><?php endforeach; ?><?php if(!$sections): ?><div class="hrim-empty">Create checklist sections first.</div><?php endif; ?></div></div><label>Status<select name="status"><option value="active" <?php selected($edit_t->status??'active','active'); ?>>Active</option><option value="inactive" <?php selected($edit_t->status??'active','inactive'); ?>>Inactive</option></select></label><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit_t?'Update Template':'Create Template'; ?></button></div></form></div></div><?php endif; ?>
        <?php if($show_q): ?><div class="hrim-modal open"><div class="hrim-modal-card"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-templates')); ?>">×</a><h2><?php echo $edit_q?'Edit Section Checkpoint':'Add Section Checkpoint'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_question"><input type="hidden" name="id" value="<?php echo esc_attr($eq); ?>"><?php wp_nonce_field('hrim_save_question'); ?><label>Section<select name="section_id" required><option value="">Select Section</option><?php foreach($sections as $section): ?><option value="<?php echo esc_attr($section->id); ?>" <?php selected($edit_q->section_id??0,$section->id); ?>><?php echo esc_html($section->name); ?></option><?php endforeach; ?></select></label><label>Checkpoint<textarea name="question" required><?php echo esc_textarea($edit_q->question??''); ?></textarea></label><label>Response Type<select name="question_type"><option value="yes_no" <?php selected($edit_q->question_type??'yes_no','yes_no'); ?>>Yes / No / N.A.</option><option value="rating" <?php selected($edit_q->question_type??'','rating'); ?>>Rating 1–5</option><option value="text" <?php selected($edit_q->question_type??'','text'); ?>>Text</option></select></label><label>Display Order<input type="number" name="sort_order" value="<?php echo esc_attr($edit_q->sort_order??0); ?>"></label><label class="hrim-check"><input type="checkbox" name="required_flag" value="1" <?php checked($edit_q->required_flag??0,1); ?>> Mandatory</label><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit_q?'Update Checkpoint':'Add Checkpoint'; ?></button></div></form></div></div><?php endif; ?>
        <?php return $this->shell('Inspection Templates',ob_get_clean(),'templates');
    }

    public function inspection_form_shortcode(){
        if(!$this->can_inspect()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','inspect');
        global $wpdb;
        $hotels=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_hotels WHERE status='active' ORDER BY name");
        $draft_batch=sanitize_text_field(wp_unslash($_GET['draft']??''));
        $draft_room_id=0; $draft_answers=[]; $draft_overall_remarks='';
        if($draft_batch){
            $draft_rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_inspections WHERE draft_batch_id=%s AND status='in_progress' AND inspector_id=%d ORDER BY id",$draft_batch,get_current_user_id()));
            if($draft_rows){
                $draft_room_id=(int)$draft_rows[0]->room_id;
                $draft_overall_remarks=(string)$draft_rows[0]->remarks;
                foreach($draft_rows as $dr){
                    $ans=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_answers WHERE inspection_id=%d",$dr->id));
                    foreach($ans as $a){
                        $draft_answers[(int)$a->question_id]=[
                            'value'=>$a->answer_text,
                            'result'=>$a->result_status,
                            'remarks'=>$a->remarks,
                            'attachments'=>$this->normalize_attachment_ids($a->attachment_ids),
                            'videos'=>$this->normalize_attachment_ids($a->video_attachment_ids??''),
                        ];
                    }
                }
            } else $draft_batch='';
        }
        ob_start();
        ?>
        <div class="hrim-panel"><form id="hrimInspectionForm" class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
          <input type="hidden" name="action" value="hrim_submit_inspection">
          <input type="hidden" name="draft_batch_id" id="hrimDraftBatchId" value="<?php echo esc_attr($draft_batch); ?>">
          <?php wp_nonce_field('hrim_submit_inspection'); ?>
          <?php $single_property=$this->single_active_property(); ?>
          <div class="<?php echo $single_property?'':'hrim-grid-2'; ?>">
            <?php if($single_property): ?><input type="hidden" id="hrimHotel" name="hotel_id" value="<?php echo esc_attr($single_property->id); ?>"><?php else: ?>
            <label>Hotel<select id="hrimHotel" name="hotel_id" required><option value="">Select hotel</option><?php foreach($hotels as $h): ?><option value="<?php echo esc_attr($h->id); ?>"><?php echo esc_html($h->name); ?></option><?php endforeach; ?></select></label><?php endif; ?>
            <label>Room<select id="hrimRoom" name="room_id" required data-draft-room="<?php echo esc_attr($draft_room_id); ?>"><option value=""><?php echo $single_property?'Select Room':'Select hotel first'; ?></option></select></label>
          </div>
          <div id="hrimQuestions" class="hrim-question-list"></div>
          <div id="hrimInspectionFooter" class="hrim-inspection-footer" <?php echo $draft_room_id ? '' : 'style="display:none;"'; ?>>
            <label>Overall Remarks<textarea name="remarks" rows="4"><?php echo esc_textarea($draft_overall_remarks); ?></textarea></label>
            <div class="hrim-form-actions hrim-inspection-actions">
              <button class="hrim-secondary hrim-large hrim-save-progress" type="submit" name="save_mode" value="in_progress" formnovalidate>Save In Progress</button>
              <button class="hrim-primary hrim-large hrim-submit-complete" type="submit" name="save_mode" value="complete" hidden>Submit All Checklists</button>
            </div>
          </div>
        </form></div>
        <script>window.HRIM_DRAFT_ANSWERS=<?php echo wp_json_encode($draft_answers); ?>;</script>
        <?php return $this->shell('Perform Room Inspection',ob_get_clean(),'inspect');
    }

    public function inspection_in_progress_shortcode(){
        if(!$this->can_inspect()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','in_progress');
        global $wpdb;
        $where=$this->can_manage()?"i.status='in_progress'":"i.status='in_progress' AND i.inspector_id=".get_current_user_id();
        $rows=$wpdb->get_results("SELECT i.draft_batch_id,MIN(i.started_at) started_at,MAX(i.last_saved_at) last_saved_at,MAX(i.room_id) room_id,MAX(i.inspector_id) inspector_id,r.room_no,f.name floor_name,u.display_name inspector FROM {$wpdb->prefix}hrim_inspections i LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=i.room_id LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=i.floor_id LEFT JOIN {$wpdb->users} u ON u.ID=i.inspector_id WHERE {$where} AND i.draft_batch_id IS NOT NULL AND i.draft_batch_id<>'' GROUP BY i.draft_batch_id ORDER BY last_saved_at DESC");
        ob_start(); ?>
        <div class="hrim-panel"><div class="hrim-panel-head"><div><small><?php echo count($rows); ?> records</small></div><?php echo $this->excel_button('inspection-in-progress'); ?></div>
        <div class="hrim-table-wrap"><table class="hrim-table hrim-exportable"><thead><tr><th>Sl.</th><th>Date</th><th>Floor</th><th>Room</th><th>Inspector</th><th>Completed</th><th>Progress</th><th>Last Updated</th><th class="hrim-no-export">Actions</th></tr></thead><tbody>
        <?php $sl=0; foreach($rows as $r):
            $checklist=$this->in_progress_room_checklist((int)$r->room_id,(string)$r->draft_batch_id);
            $total_count=0; $answered_count=0;
            foreach($checklist as $section_items){
                $total_count+=count($section_items);
                foreach($section_items as $item){
                    $saved=$item[1]??null;
                    if($saved && in_array(strtolower(trim((string)$saved->answer_text)),['yes','no'],true)) $answered_count++;
                }
            }
            $pct=$total_count?round(($answered_count/$total_count)*100):0;
            $detail_id='hrimDraftDetail'.substr(md5((string)$r->draft_batch_id),0,12);
        ?><tr class="hrim-in-progress-row" tabindex="0" role="button" aria-expanded="false" data-target="<?php echo esc_attr($detail_id); ?>"><td><?php echo ++$sl; ?></td><td><?php echo esc_html($this->ist_date('d/m/y',$r->started_at?:$r->last_saved_at)); ?></td><td><?php echo esc_html($r->floor_name); ?></td><td><?php echo esc_html($r->room_no); ?></td><td><?php echo esc_html($r->inspector); ?></td><td><?php echo esc_html($answered_count.' / '.$total_count); ?></td><td><strong><?php echo esc_html($pct); ?>%</strong></td><td><?php echo esc_html($this->ist_date('d/m/y, h:i A',$r->last_saved_at)); ?></td><td class="hrim-no-export"><div class="hrim-actions"><a class="hrim-icon-btn hrim-action-edit" title="Continue Inspection" href="<?php echo esc_url(add_query_arg('draft',$r->draft_batch_id,$this->page_url('perform-room-inspection'))); ?>"><span class="dashicons dashicons-edit"></span></a><?php if($this->can_manage()): ?><form class="hrim-inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this in-progress inspection?');"><input type="hidden" name="action" value="hrim_delete_in_progress"><input type="hidden" name="draft_batch_id" value="<?php echo esc_attr($r->draft_batch_id); ?>"><?php wp_nonce_field('hrim_delete_in_progress'); ?><button class="hrim-icon-btn hrim-delete" title="Delete Draft"><span class="dashicons dashicons-trash"></span></button></form><?php endif; ?></div></td></tr>
        <?php echo $this->in_progress_detail_row($r,$detail_id); endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="9" class="hrim-empty">Inspection records not found.</td></tr><?php endif; ?>
        </tbody></table></div></div>
        <?php return $this->shell('Inspection In Progress',ob_get_clean(),'in_progress');
    }

    private function in_progress_room_checklist($room_id,$draft_batch){
        static $cache=[];
        $cache_key=(int)$room_id.'|'.(string)$draft_batch;
        if(isset($cache[$cache_key])) return $cache[$cache_key];

        global $wpdb;
        $room=$wpdb->get_row($wpdb->prepare("SELECT id,category_id FROM {$wpdb->prefix}hrim_rooms WHERE id=%d",(int)$room_id));
        if(!$room) return $cache[$cache_key]=[];

        $template_ids=array_map('intval',$wpdb->get_col($wpdb->prepare(
            "SELECT template_id FROM {$wpdb->prefix}hrim_room_templates WHERE room_id=%d
             UNION
             SELECT ct.template_id FROM {$wpdb->prefix}hrim_category_templates ct
             WHERE ct.category_id=%d AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}hrim_room_template_exclusions x
                WHERE x.room_id=%d AND x.template_id=ct.template_id
             )",
            (int)$room_id,(int)$room->category_id,(int)$room_id
        )));
        $template_ids=array_values(array_unique(array_filter($template_ids)));

        $sections=[];
        $questions=[];
        if($template_ids){
            $ph=implode(',',array_fill(0,count($template_ids),'%d'));
            $sections=$wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT s.id,s.name,s.sort_order
                 FROM {$wpdb->prefix}hrim_template_sections ts
                 INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=ts.section_id AND s.status='active'
                 WHERE ts.template_id IN ($ph)
                 ORDER BY s.sort_order,s.name,s.id",
                $template_ids
            ));
            $questions=$wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT q.id,q.question,q.section_id,s.name section_name,s.sort_order section_order,q.sort_order
                 FROM {$wpdb->prefix}hrim_template_sections ts
                 INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=ts.section_id AND s.status='active'
                 INNER JOIN {$wpdb->prefix}hrim_questions q ON q.section_id=s.id AND q.is_library=1
                 LEFT JOIN {$wpdb->prefix}hrim_room_question_exclusions rx ON rx.room_id=%d AND rx.question_id=q.id
                 WHERE ts.template_id IN ($ph) AND rx.question_id IS NULL
                 ORDER BY s.sort_order,s.name,q.sort_order,q.id",
                array_merge([(int)$room_id],$template_ids)
            ));
        }

        $additions=$wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT q.id,q.question,q.section_id,s.name section_name,s.sort_order section_order,q.sort_order
             FROM {$wpdb->prefix}hrim_room_question_additions a
             INNER JOIN {$wpdb->prefix}hrim_questions q ON q.id=a.question_id
             INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=q.section_id AND s.status='active'
             WHERE a.room_id=%d
             ORDER BY s.sort_order,s.name,q.sort_order,q.id",
            (int)$room_id
        ));
        foreach($additions as $addition){
            $questions[]=$addition;
            $found=false;
            foreach($sections as $section){ if((int)$section->id===(int)$addition->section_id){$found=true;break;} }
            if(!$found) $sections[]=(object)['id'=>(int)$addition->section_id,'name'=>$addition->section_name,'sort_order'=>(int)$addition->section_order];
        }
        usort($sections,function($a,$b){ return [(int)$a->sort_order,(string)$a->name,(int)$a->id] <=> [(int)$b->sort_order,(string)$b->name,(int)$b->id]; });

        $answers=$wpdb->get_results($wpdb->prepare(
            "SELECT a.* FROM {$wpdb->prefix}hrim_answers a
             INNER JOIN {$wpdb->prefix}hrim_inspections i ON i.id=a.inspection_id
             WHERE i.draft_batch_id=%s AND i.status='in_progress'
             ORDER BY a.id",
            (string)$draft_batch
        ));
        $answer_map=[];
        foreach($answers as $answer) $answer_map[(int)$answer->question_id]=$answer;

        $grouped=[];
        foreach($sections as $section) $grouped[$section->name?:'General']=[];
        $seen=[];
        foreach($questions as $question){
            $qid=(int)$question->id;
            if(isset($seen[$qid])) continue;
            $seen[$qid]=true;
            $section=$question->section_name?:'General';
            if(!isset($grouped[$section])) $grouped[$section]=[];
            $grouped[$section][]=[$question,$answer_map[$qid]??null];
        }
        return $cache[$cache_key]=$grouped;
    }

    private function in_progress_detail_row($draft,$detail_id){
        $grouped=$this->in_progress_room_checklist((int)$draft->room_id,(string)$draft->draft_batch_id);

        ob_start(); ?>
        <tr id="<?php echo esc_attr($detail_id); ?>" class="hrim-in-progress-detail-row hrim-no-export" hidden><td colspan="9"><div class="hrim-inspection-detail hrim-in-progress-inline-detail">
        <section class="hrim-section-score-card"><div class="hrim-table-wrap"><table class="hrim-table hrim-section-score-table"><thead><tr><th>Section</th><th>Total</th><th>Answered</th><th>Pending</th><th>Progress</th></tr></thead><tbody>
        <?php if(!$grouped): ?><tr><td colspan="5" class="hrim-empty">No checklist sections are assigned to this room.</td></tr><?php endif; ?>
        <?php $section_index=0; foreach($grouped as $section=>$section_items):
            $total=count($section_items);
            $answered=0;
            foreach($section_items as $item){
                $saved=$item[1];
                if($saved && in_array(strtolower(trim((string)$saved->answer_text)),['yes','no'],true)) $answered++;
            }
            $pending=max(0,$total-$answered);
            $percentage=$total?round(($answered/$total)*100):0;
            $panel_id=$detail_id.'_section_'.$section_index++;
        ?>
        <tr class="hrim-section-score-row" tabindex="0" role="button" aria-expanded="false" data-target="<?php echo esc_attr($panel_id); ?>"><td><strong><?php echo esc_html($section); ?></strong><span class="hrim-section-row-arrow" aria-hidden="true">&#9662;</span></td><td><?php echo esc_html($total); ?></td><td><?php echo esc_html($answered); ?></td><td><?php echo esc_html($pending); ?></td><td><span class="hrim-section-percentage"><?php echo esc_html($percentage); ?>%</span></td></tr>
        <tr class="hrim-section-detail-row"><td colspan="5"><div id="<?php echo esc_attr($panel_id); ?>" class="hrim-section-detail-panel" hidden>
        <?php foreach($section_items as $item):
            $question=$item[0];
            $a=$item[1];
            $value=$a?strtolower(trim((string)$a->answer_text)):'';
            $is_unanswered=$value==='';
            $is_fail=$a && ($value==='no'||strtolower((string)$a->result_status)==='fail');
        ?>
        <div class="hrim-detail-answer <?php echo $is_unanswered?'is-na':($is_fail?'is-fail':'is-pass'); ?>"><div class="hrim-answer-icon"><span class="dashicons <?php echo $is_unanswered?'dashicons-minus':($is_fail?'dashicons-no-alt':'dashicons-yes-alt'); ?>"></span></div><div><strong><?php echo esc_html($question->question?:'Checkpoint'); ?></strong><div class="hrim-answer-value">Answer: <?php echo esc_html($is_unanswered?'Not answered':ucfirst($a->answer_text)); ?></div><?php if($a && trim((string)$a->remarks)!==''): ?><p><b>Remarks:</b> <?php echo nl2br(esc_html($a->remarks)); ?></p><?php endif; ?><?php $photo_ids=$a?$this->normalize_attachment_ids($a->attachment_ids):[]; if($photo_ids): ?><div class="hrim-draft-photo-list"><?php foreach($photo_ids as $photo_id): $url=wp_get_attachment_image_url($photo_id,'thumbnail'); if($url): ?><a href="<?php echo esc_url(wp_get_attachment_url($photo_id)); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url($url); ?>" alt="Checkpoint photo"></a><?php endif; endforeach; ?></div><?php endif; ?></div></div>
        <?php endforeach; ?></div></td></tr><?php endforeach; ?>
        </tbody></table></div></section></div></td></tr>
        <?php return ob_get_clean();
    }


    public function add_occupancy_shortcode(){
        if(!$this->can_inspect()) return $this->denied();
        global $wpdb;
        $sessions=$wpdb->prefix.'hrim_occupancy_checks';
        $entries=$wpdb->prefix.'hrim_occupancy_check_rooms';
        $hotels=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_hotels WHERE status='active' ORDER BY name");
        $hotel_id=absint($_GET['hotel_id']??0);
        if(!$hotel_id && count($hotels)===1) $hotel_id=(int)$hotels[0]->id;
        $today=substr($this->ist_now_mysql(),0,10);
        $session_id=0;
        if($hotel_id){
            $session_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$sessions} WHERE hotel_id=%d AND check_date=%s ORDER BY id DESC LIMIT 1",$hotel_id,$today));
            $now=$this->ist_now_mysql();
            if(!$session_id){
                $wpdb->insert($sessions,['hotel_id'=>$hotel_id,'check_date'=>$today,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
                $session_id=(int)$wpdb->insert_id;
            }
            $rooms=$wpdb->get_results($wpdb->prepare("SELECT id,floor_id FROM {$wpdb->prefix}hrim_rooms WHERE hotel_id=%d AND status='active' ORDER BY floor_id,room_no",$hotel_id));
            foreach($rooms as $room){
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$entries} (occupancy_check_id,floor_id,room_id,created_at) VALUES (%d,%d,%d,%s)",
                    $session_id,$room->floor_id,$room->id,$now
                ));
            }
        }
        $floors=[];$rows_by_floor=[];
        if($session_id){
            $floors=$wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT f.id,f.name,f.sort_order
                 FROM {$entries} e
                 INNER JOIN {$wpdb->prefix}hrim_floors f ON f.id=e.floor_id
                 WHERE e.occupancy_check_id=%d
                 ORDER BY CASE WHEN COALESCE(f.sort_order,0)>0 THEN f.sort_order ELSE CAST(f.name AS UNSIGNED) END ASC, f.name ASC",
                $session_id
            ));
            $all_rows=$wpdb->get_results($wpdb->prepare("SELECT e.*,r.room_no FROM {$entries} e INNER JOIN {$wpdb->prefix}hrim_rooms r ON r.id=e.room_id WHERE e.occupancy_check_id=%d ORDER BY e.floor_id,CAST(r.room_no AS UNSIGNED),r.room_no",$session_id));
            foreach($all_rows as $row){
                $fid=(int)$row->floor_id;
                if(!isset($rows_by_floor[$fid])) $rows_by_floor[$fid]=[];
                $rows_by_floor[$fid][]=$row;
            }
        }
        ob_start(); ?>
        <div class="hrim-panel hrim-add-occupancy-panel">
          <div class="hrim-occupancy-topline">
            <strong class="hrim-occupancy-date"><?php echo esc_html($this->ist_date('d/m/y',$today)); ?></strong>
            <?php echo $this->excel_button('occupancy-'.$today); ?>
          </div>
          <?php if(count($hotels)>1 && !$hotel_id): ?>
            <form method="get" action="<?php echo esc_url($this->page_url('hotel-inspection-add-occupancy')); ?>" class="hrim-filter hrim-occupancy-floor-filter">
              <label>Property *<select name="hotel_id" required onchange="this.form.submit()"><option value="">Select Property</option><?php foreach($hotels as $hotel): ?><option value="<?php echo esc_attr($hotel->id); ?>"><?php echo esc_html($hotel->name); ?></option><?php endforeach; ?></select></label>
            </form>
          <?php elseif($session_id): ?>
            <div class="hrim-occ-floor-button-list">
              <?php if(!$floors): ?><div class="hrim-empty">No active floors or rooms are available.</div><?php endif; ?>
              <?php foreach($floors as $floor): ?>
                <button type="button" class="hrim-occ-floor-button hrim-view-floor-rooms" style="background:#fff !important;background-color:#fff !important;color:#000 !important;border:2px solid var(--hrim-accent) !important;" data-floor-id="<?php echo esc_attr($floor->id); ?>" data-floor-name="<?php echo esc_attr($floor->name); ?>">
                  <span class="hrim-occ-floor-name"><?php $total=count($rows_by_floor[(int)$floor->id]??[]); $done=0; foreach(($rows_by_floor[(int)$floor->id]??[]) as $__r){ if(!empty($__r->status)) $done++; } echo esc_html($floor->name." (".$done."/".$total.")"); ?></span>
                  
                </button>
              <?php endforeach; ?>
            </div>

            <div class="hrim-modal hrim-occupancy-rooms-modal" id="hrimOccupancyRoomsModal" aria-hidden="true">
              <div class="hrim-modal-card hrim-modal-wide">
                <button type="button" class="hrim-modal-close" aria-label="Close">&times;</button>
                <h2 class="hrim-occ-modal-title">Rooms</h2>
                <div class="hrim-occ-modal-body">
                <?php foreach($floors as $floor): $floor_rows=$rows_by_floor[(int)$floor->id]??[]; ?>
                  <div class="hrim-occ-floor-room-panel" data-floor-panel="<?php echo esc_attr($floor->id); ?>" hidden>
                    <div class="hrim-table-wrap"><table class="hrim-table hrim-exportable hrim-occupancy-room-table"><thead><tr><th>Room No.</th><th>Status</th></tr></thead><tbody>
                    <?php if(!$floor_rows): ?><tr><td colspan="2" class="hrim-empty">No rooms found on this floor.</td></tr><?php endif; ?>
                    <?php foreach($floor_rows as $row): $updated_ts=$row->updated_at?(new DateTime($row->updated_at,$this->ist_timezone()))->getTimestamp():0; $editable=!$row->status||!$updated_ts||(time()-$updated_ts)<=300; ?>
                      <tr data-entry-id="<?php echo esc_attr($row->id); ?>"><td><?php echo esc_html($row->room_no); ?></td><td><div class="hrim-occ-status-buttons<?php echo $editable?'':' is-locked'; ?>" role="group" aria-label="Occupancy status"><button type="button" class="hrim-occ-status-btn yes<?php echo $row->status==='yes'?' is-selected':''; ?>" data-status="yes" <?php echo $editable?'':'disabled'; ?>>Yes</button><button type="button" class="hrim-occ-status-btn no<?php echo $row->status==='no'?' is-selected':''; ?>" data-status="no" <?php echo $editable?'':'disabled'; ?>>No</button><?php if(!$editable): ?><span class="hrim-occ-lock-icon" title="Locked" aria-label="Locked"><span class="dashicons dashicons-lock"></span></span><?php endif; ?></div></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                  </div>
                <?php endforeach; ?>
                </div>

              </div>
            </div>
          <?php else: ?><div class="hrim-empty">No active property is available.</div><?php endif; ?>
        </div>
        <?php return $this->shell('Add Occupancy',ob_get_clean(),'add_occupancy');
    }


    public function update_office_count_shortcode(){
        if(!$this->can_inspect()) return $this->denied();
        global $wpdb;
        $sessions=$wpdb->prefix.'hrim_occupancy_checks';
        $hotels=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_hotels WHERE status='active' ORDER BY name");
        $hotel_id=$this->effective_property_id(absint($_GET['hotel_id']??0));
        $today=substr($this->ist_now_mysql(),0,10);

        if($hotel_id){
            $today_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$sessions} WHERE hotel_id=%d AND check_date=%s ORDER BY id DESC LIMIT 1",$hotel_id,$today));
            if(!$today_id){
                $now=$this->ist_now_mysql();
                $wpdb->insert($sessions,[
                    'hotel_id'=>$hotel_id,
                    'check_date'=>$today,
                    'created_by'=>get_current_user_id(),
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ]);
            }
        }

        $rows=[];
        if($hotel_id){
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT c.* FROM {$sessions} c WHERE c.hotel_id=%d ORDER BY c.check_date DESC,c.id DESC",
                $hotel_id
            ));
        }

        ob_start(); ?>
        <div class="hrim-panel hrim-update-office-count-panel">
          <?php if(count($hotels)>1 && !$hotel_id): ?>
            <form method="get" action="<?php echo esc_url($this->page_url('hotel-inspection-update-office-count')); ?>" class="hrim-filter">
              <label>Property *<select name="hotel_id" required onchange="this.form.submit()"><option value="">Select Property</option><?php foreach($hotels as $hotel): ?><option value="<?php echo esc_attr($hotel->id); ?>"><?php echo esc_html($hotel->name); ?></option><?php endforeach; ?></select></label>
            </form>
          <?php elseif($hotel_id): ?>
            <div class="hrim-table-wrap"><table class="hrim-table hrim-office-count-table">
              <thead><tr><th>Date</th><th>Office Count</th><th>Action</th></tr></thead>
              <tbody>
              <?php if(!$rows): ?><tr><td colspan="3">No occupancy records found.</td></tr><?php endif; ?>
              <?php foreach($rows as $row):
                $saved=!($row->system_count===null || $row->system_count==='');
                $updated_ts=($saved && !empty($row->updated_at)) ? (new DateTime($row->updated_at,$this->ist_timezone()))->getTimestamp() : 0;
                $editable=!$saved || !$updated_ts || (time()-$updated_ts)<=300;
                $icon=$saved?'dashicons-edit':'dashicons-yes';
                $title=$saved?'Edit Office Count':'Add Office Count';
              ?>
                <tr>
                  <td><?php echo esc_html($this->ist_date('d/m/y',$row->check_date)); ?></td>
                  <td><?php echo $saved?esc_html((int)$row->system_count):'—'; ?></td>
                  <td>
                    <?php if($editable): ?>
                      <button type="button" class="hrim-action <?php echo $saved?'edit':'verify'; ?> hrim-open-office-count-modal" data-session-id="<?php echo esc_attr($row->id); ?>" data-date="<?php echo esc_attr($this->ist_date('d/m/y',$row->check_date)); ?>" data-count="<?php echo $saved?esc_attr((int)$row->system_count):''; ?>" title="<?php echo esc_attr($title); ?>"><span class="dashicons <?php echo esc_attr($icon); ?>"></span></button>
                    <?php else: ?>
                      <span class="hrim-action hrim-office-count-locked" title="Locked"><span class="dashicons dashicons-lock"></span></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php else: ?>
            <div class="hrim-empty">No active property is available.</div>
          <?php endif; ?>
        </div>

        <div class="hrim-modal" id="hrimOfficeCountModal" aria-hidden="true">
          <div class="hrim-modal-card">
            <button type="button" class="hrim-modal-close" aria-label="Close">&times;</button>
            <h2>Update Office Count</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hrim-form">
              <?php wp_nonce_field('hrim_save_occupancy_office_count'); ?>
              <input type="hidden" name="action" value="hrim_save_occupancy_office_count">
              <input type="hidden" name="session_id" id="hrimOfficeSessionId" value="">
              <input type="hidden" name="hotel_id" value="<?php echo esc_attr($hotel_id); ?>">
              <label>Date *<input type="text" id="hrimOfficeDate" value="" readonly></label>
              <label>Office Count *<input type="number" name="system_count" id="hrimOfficeCount" min="0" step="1" required></label>
              <div class="hrim-form-actions"><button type="submit" class="hrim-primary">Update</button></div>
            </form>
          </div>
        </div>
        <?php return $this->shell('Update Office Count',ob_get_clean(),'update_office_count');
    }

    public function save_occupancy_office_count(){
        if(!$this->can_inspect()) wp_die('Access denied');
        check_admin_referer('hrim_save_occupancy_office_count');
        global $wpdb;
        $session_id=absint($_POST['session_id']??0);
        $hotel_id=$this->effective_property_id(absint($_POST['hotel_id']??0));
        $count_raw=isset($_POST['system_count'])?trim((string)wp_unslash($_POST['system_count'])):'';
        if($count_raw==='' || !is_numeric($count_raw) || intval($count_raw)<0){
            wp_safe_redirect(add_query_arg(['hotel_id'=>$hotel_id,'hrim_error'=>'Office Count is required.'],$this->page_url('hotel-inspection-update-office-count'))); exit;
        }
        $session=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_occupancy_checks WHERE id=%d AND hotel_id=%d",$session_id,$hotel_id));
        if(!$session){
            wp_safe_redirect(add_query_arg(['hotel_id'=>$hotel_id,'hrim_error'=>'Occupancy record was not found.'],$this->page_url('hotel-inspection-update-office-count'))); exit;
        }
        $office_count_saved = !($session->system_count===null || $session->system_count==='');
        if($office_count_saved && !empty($session->updated_at)){
            $office_updated_ts=(new DateTime($session->updated_at,$this->ist_timezone()))->getTimestamp();
            if((time()-$office_updated_ts)>300){
                wp_safe_redirect(add_query_arg(['hotel_id'=>$hotel_id,'hrim_error'=>'The 5-minute Office Count edit window has expired.'],$this->page_url('hotel-inspection-update-office-count'))); exit;
            }
        }
        $wpdb->update($wpdb->prefix.'hrim_occupancy_checks',[
            'system_count'=>max(0,intval($count_raw)),
            'system_updated_by'=>get_current_user_id(),
            'updated_at'=>$this->ist_now_mysql(),
        ],['id'=>$session_id]);
        wp_safe_redirect(add_query_arg(['hotel_id'=>$hotel_id,'hrim_saved'=>1,'hrim_message'=>'Office Count updated successfully.'],$this->page_url('hotel-inspection-update-office-count'))); exit;
    }

    public function occupancy_check_shortcode(){
        if(!$this->can_inspect()) return $this->denied();
        global $wpdb;
        $sessions=$wpdb->prefix.'hrim_occupancy_checks';
        $entries=$wpdb->prefix.'hrim_occupancy_check_rooms';
        $hotels=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_hotels WHERE status='active' ORDER BY name");
        $session_id=absint($_GET['check_id']??0);
        ob_start();
        if($session_id){
            $session=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$sessions} WHERE id=%d",$session_id));
            if(!$session) return $this->shell('Occupancy Check','<div class="hrim-alert error">Occupancy check not found.</div>','occupancy');

            $floors=$wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT f.id,f.name,f.sort_order
                 FROM {$entries} e
                 INNER JOIN {$wpdb->prefix}hrim_floors f ON f.id=e.floor_id
                 WHERE e.occupancy_check_id=%d
                 ORDER BY f.sort_order,f.name",
                $session_id
            ));
            $all_rows=$wpdb->get_results($wpdb->prepare(
                "SELECT e.*,r.room_no,f.name floor_name,f.sort_order
                 FROM {$entries} e
                 INNER JOIN {$wpdb->prefix}hrim_rooms r ON r.id=e.room_id
                 INNER JOIN {$wpdb->prefix}hrim_floors f ON f.id=e.floor_id
                 WHERE e.occupancy_check_id=%d
                 ORDER BY f.sort_order,f.name,r.room_no",
                $session_id
            ));
            $rows_by_floor=[];
            foreach($all_rows as $row){ $rows_by_floor[(int)$row->floor_id][]=$row; }
            ?>
            <div class="hrim-panel">
              <div class="hrim-occupancy-topline">
                <strong class="hrim-occupancy-date"><?php echo esc_html($this->ist_date('d/m/y',$session->check_date)); ?></strong>
              </div>
              <div class="hrim-occ-floor-button-list">
                <?php if(!$floors): ?><div class="hrim-empty">No floors are available.</div><?php endif; ?>
                <?php foreach($floors as $floor): ?>
                  <button type="button" class="hrim-occ-floor-button hrim-view-floor-rooms" style="background:#fff !important;background-color:#fff !important;color:#000 !important;border:2px solid var(--hrim-accent) !important;" data-floor-id="<?php echo esc_attr($floor->id); ?>" data-floor-name="<?php echo esc_attr($floor->name); ?>">
                    <span class="hrim-occ-floor-name"><?php $total=count($rows_by_floor[(int)$floor->id]??[]); $done=0; foreach(($rows_by_floor[(int)$floor->id]??[]) as $__r){ if(!empty($__r->status)) $done++; } echo esc_html($floor->name." (".$done."/".$total.")"); ?></span>
                    
                  </button>
                <?php endforeach; ?>
              </div>
              <div class="hrim-modal hrim-occupancy-rooms-modal" id="hrimOccupancyRoomsModal" aria-hidden="true">
                <div class="hrim-modal-card hrim-modal-wide">
                  <button type="button" class="hrim-modal-close" aria-label="Close">&times;</button>
                  <h2 class="hrim-occ-modal-title">Rooms</h2>
                  <div class="hrim-occ-modal-body">
                    <?php foreach($floors as $floor): $floor_rows=$rows_by_floor[(int)$floor->id]??[]; ?>
                      <div class="hrim-occ-floor-room-panel" data-floor-panel="<?php echo esc_attr($floor->id); ?>" hidden>
                        <div class="hrim-table-wrap"><table class="hrim-table hrim-occupancy-room-table"><thead><tr><th>Room No.</th><th>Status</th></tr></thead><tbody>
                        <?php if(!$floor_rows): ?><tr><td colspan="2" class="hrim-empty">No rooms found on this floor.</td></tr><?php endif; ?>
                        <?php foreach($floor_rows as $row): ?>
                          <tr><td><?php echo esc_html($row->room_no); ?></td><td><div class="hrim-occ-status-buttons is-locked"><button type="button" class="hrim-occ-status-btn yes<?php echo $row->status==='yes'?' is-selected':''; ?>" disabled>Yes</button><button type="button" class="hrim-occ-status-btn no<?php echo $row->status==='no'?' is-selected':''; ?>" disabled>No</button><span class="hrim-occ-lock-icon" title="Locked" aria-label="Locked"><span class="dashicons dashicons-lock"></span></span></div></td></tr>
                        <?php endforeach; ?></tbody></table></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
            <?php
        } else {
            $rows=$wpdb->get_results("SELECT c.*,h.name hotel_name,u.display_name system_user_name,(SELECT COUNT(*) FROM {$entries} e WHERE e.occupancy_check_id=c.id AND e.status='yes') yes_count,(SELECT COUNT(*) FROM {$entries} e WHERE e.occupancy_check_id=c.id) total_count FROM {$sessions} c LEFT JOIN {$wpdb->prefix}hrim_hotels h ON h.id=c.hotel_id LEFT JOIN {$wpdb->users} u ON u.ID=c.system_updated_by ORDER BY c.check_date DESC,c.id DESC");
            ?>
            <div class="hrim-panel">
              <div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><th>Date</th><th>Security Count</th><th>Office Count</th><th>Office Updated By</th><th>Office Update Status</th><th>Actions</th></tr></thead><tbody>
              <?php if(!$rows): ?><tr><td colspan="7">No occupancy checks found.</td></tr><?php endif; ?>
              <?php foreach($rows as $i=>$row): $sys_status=$row->system_count===null||$row->system_count===''?'Pending':(((int)$row->system_count===(int)$row->yes_count)?'Matched':'Mismatch'); $is_old=((string)$row->check_date < substr($this->ist_now_mysql(),0,10)); ?>
                <tr data-session-id="<?php echo esc_attr($row->id); ?>"><td><?php echo esc_html($i+1); ?></td><td><?php echo esc_html($this->ist_date('d/m/y',$row->check_date)); ?></td><td class="hrim-occ-yes-count"><?php echo esc_html((int)$row->yes_count); ?></td><td class="hrim-occ-system-count-value"><?php echo ($row->system_count===null || $row->system_count==='')?'—':esc_html((int)$row->system_count); ?></td><td class="hrim-occ-system-user-name"><?php echo $row->system_user_name ? esc_html($row->system_user_name) : '—'; ?></td><td class="hrim-occ-system-status"><span class="hrim-status <?php echo strtolower($sys_status); ?>"><?php echo esc_html($sys_status); ?></span></td><td><a class="hrim-action view" href="<?php echo esc_url(add_query_arg('check_id',$row->id,$this->page_url('hotel-inspection-occupancy-check'))); ?>" title="Open"><span class="dashicons dashicons-visibility"></span></a></td></tr>
              <?php endforeach; ?></tbody></table></div>
            </div>
            <?php
        }
        return $this->shell('Occupancy Check',ob_get_clean(),'occupancy');
    }

    public function create_occupancy_check(){
        if(!$this->can_inspect()) wp_die('Access denied');
        check_admin_referer('hrim_create_occupancy_check');
        global $wpdb;
        $hotel_id=absint($_POST['hotel_id']??0);
        if(!$hotel_id){ wp_safe_redirect(add_query_arg('hrim_error','Property is required.',$this->page_url('hotel-inspection-occupancy-check'))); exit; }
        $now=$this->ist_now_mysql();
        $check_date=substr($now,0,10);
        $existing_id=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hrim_occupancy_checks WHERE hotel_id=%d AND check_date=%s ORDER BY id DESC LIMIT 1",
            $hotel_id,$check_date
        ));
        if($existing_id){
            wp_safe_redirect(add_query_arg('check_id',$existing_id,$this->page_url('hotel-inspection-occupancy-check')));
            exit;
        }
        $wpdb->insert($wpdb->prefix.'hrim_occupancy_checks',['hotel_id'=>$hotel_id,'check_date'=>$check_date,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);
        $id=(int)$wpdb->insert_id;
        $rooms=$wpdb->get_results($wpdb->prepare("SELECT id,floor_id FROM {$wpdb->prefix}hrim_rooms WHERE hotel_id=%d AND status='active' ORDER BY floor_id,room_no",$hotel_id));
        foreach($rooms as $room) $wpdb->insert($wpdb->prefix.'hrim_occupancy_check_rooms',['occupancy_check_id'=>$id,'floor_id'=>$room->floor_id,'room_id'=>$room->id,'created_at'=>$now]);
        wp_safe_redirect(add_query_arg('check_id',$id,$this->page_url('hotel-inspection-occupancy-check'))); exit;
    }

    public function ajax_save_occupancy_room(){
        check_ajax_referer('hrim_ajax','nonce');
        if(!$this->can_inspect()) wp_send_json_error(['message'=>'Access denied'],403);
        global $wpdb;
        $id=absint($_POST['entry_id']??0); $status=sanitize_key($_POST['status']??'');
        if(!in_array($status,['yes','no'],true)) wp_send_json_error(['message'=>'Select Yes or No.']);
        $table=$wpdb->prefix.'hrim_occupancy_check_rooms';
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$id));
        if(!$row) wp_send_json_error(['message'=>'Room entry not found.']);
        if($row->updated_at){
            $age=time()-(new DateTime($row->updated_at,$this->ist_timezone()))->getTimestamp();
            if($age>300) wp_send_json_error(['message'=>'The 5-minute edit window has expired.','locked'=>true]);
        }
        $now=$this->ist_now_mysql(); $uid=get_current_user_id();
        $wpdb->update($table,['status'=>$status,'updated_by'=>$uid,'updated_at'=>$now],['id'=>$id]);
        $yes=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE occupancy_check_id=%d AND status='yes'",$row->occupancy_check_id));
        wp_send_json_success(['time'=>$this->ist_date('d/m/y, h:i A',$now),'user'=>wp_get_current_user()->display_name,'yes_count'=>$yes,'editable_until'=>time()+300]);
    }

    public function ajax_save_occupancy_system(){
        check_ajax_referer('hrim_ajax','nonce');
        if(!$this->can_manage()) wp_send_json_error(['message'=>'Only Manager or Owner can update system fields.'],403);
        global $wpdb;
        $id=absint($_POST['session_id']??0);
        $session=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_occupancy_checks WHERE id=%d",$id));
        if(!$session) wp_send_json_error(['message'=>'Occupancy Check not found.'],404);
        $office_count_saved = !($session->system_count===null || $session->system_count==='');
        if($office_count_saved && !empty($session->updated_at)){
            $office_updated_ts=(new DateTime($session->updated_at,$this->ist_timezone()))->getTimestamp();
            if((time()-$office_updated_ts)>300) wp_send_json_error(['message'=>'The 5-minute Office Count edit window has expired.','locked'=>true],403);
        }
        $count=isset($_POST['system_count']) && $_POST['system_count']!=='' ? max(0,intval($_POST['system_count'])) : null;
        $user_id=get_current_user_id();
        $data=['system_count'=>$count,'system_updated_by'=>$user_id,'updated_at'=>$this->ist_now_mysql()];
        $wpdb->update($wpdb->prefix.'hrim_occupancy_checks',$data,['id'=>$id]);
        $yes=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_occupancy_check_rooms WHERE occupancy_check_id=%d AND status='yes'",$id));
        $status=$count===null?'Pending':($count===$yes?'Matched':'Mismatch');
        wp_send_json_success(['status'=>$status,'user'=>wp_get_current_user()->display_name]);
    }


    private function periodic_next_date($start,$periodicity,$after=null){
        try{$d=new DateTime($start,new DateTimeZone('Asia/Kolkata'));}catch(Exception $e){return $start;}
        $target=$after?new DateTime($after,new DateTimeZone('Asia/Kolkata')):new DateTime('today',new DateTimeZone('Asia/Kolkata'));
        $legacy_days=['daily'=>1,'weekly'=>7,'fortnightly'=>14,'monthly'=>30,'quarterly'=>90,'half-yearly'=>180,'yearly'=>365];
        $days=is_numeric($periodicity)?max(1,(int)$periodicity):($legacy_days[$periodicity]??30);
        while($d<$target) $d->modify('+'.$days.' days');
        return $d->format('Y-m-d');
    }
    private function generate_periodic_instances(){
        global $wpdb; $tasks=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_periodic_tasks WHERE status='active'");
        $today=(new DateTime('now',$this->ist_timezone()))->format('Y-m-d');
        foreach($tasks as $t){
            $due=$this->periodic_next_date($t->start_date,$t->periodicity,$t->start_date);
            $guard=0;
            while($due<=$today && $guard++<500){
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}hrim_periodic_task_instances (task_id,due_date,status,assigned_to,created_at) VALUES (%d,%s,%s,%d,%s)",$t->id,$due,$due<$today?'overdue':'due',0,$this->ist_now_mysql()));
                $instance_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_periodic_task_instances WHERE task_id=%d AND due_date=%s LIMIT 1",$t->id,$due));
                if($instance_id){
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}hrim_periodic_task_rooms(instance_id,room_id,category_id) SELECT %d,r.id,r.category_id FROM {$wpdb->prefix}hrim_rooms r JOIN {$wpdb->prefix}hrim_periodic_task_categories c ON c.category_id=r.category_id WHERE c.task_id=%d AND LOWER(TRIM(COALESCE(r.status,'active'))) IN ('active','1','yes')",$instance_id,$t->id));
                }
                $due=$this->periodic_next_date($t->start_date,$t->periodicity,date('Y-m-d',strtotime($due.' +1 day')));
            }
        }
    }
    public function periodic_tasks_shortcode(){
        if(!$this->can_manage() && !current_user_can('hrim_perform_inspections')) return $this->shell('Periodic Tasks','<div class="hrim-alert error">You do not have access.</div>','periodic_tasks');
        global $wpdb; $this->generate_periodic_instances();
        $tasks=$wpdb->get_results("SELECT t.*,d.name department_name FROM {$wpdb->prefix}hrim_periodic_tasks t LEFT JOIN {$wpdb->prefix}hrim_departments d ON d.id=t.department_id ORDER BY t.id DESC");
        $categories=$wpdb->get_results("SELECT c.id,c.name,(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_rooms r WHERE (r.category_id=c.id OR (COALESCE(r.category_id,0)=0 AND TRIM(LOWER(r.category))=TRIM(LOWER(c.name)))) AND LOWER(TRIM(COALESCE(r.status,'active'))) IN ('active','1','yes')) room_count FROM {$wpdb->prefix}hrim_room_categories c WHERE LOWER(TRIM(COALESCE(c.status,'active'))) IN ('active','1','yes') ORDER BY c.name");
        $sections=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_sections WHERE status='active' ORDER BY sort_order,name");
        $departments=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_departments WHERE status='active' ORDER BY name");
        $edit_id=absint($_GET['edit']??0); $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_periodic_tasks WHERE id=%d",$edit_id)):null;
        $sel_c=$edit?$wpdb->get_col($wpdb->prepare("SELECT category_id FROM {$wpdb->prefix}hrim_periodic_task_categories WHERE task_id=%d",$edit_id)):[];
        $sel_s=$edit?$wpdb->get_col($wpdb->prepare("SELECT section_id FROM {$wpdb->prefix}hrim_periodic_task_sections WHERE task_id=%d",$edit_id)):[];
        if(isset($_GET['instance_id'])) return $this->periodic_instance_view(absint($_GET['instance_id']));
        ob_start(); ?>
        <div class="hrim-panel">
          <div class="hrim-table-tools hrim-periodic-task-tools">
            <input type="search" class="hrim-table-search" placeholder="">
            <?php echo $this->excel_button('periodic-tasks'); ?>
          </div>
          <?php $instances=$wpdb->get_results("SELECT i.*,t.task_name,t.periodicity,d.name department_name,
            (SELECT GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') FROM {$wpdb->prefix}hrim_periodic_task_categories tc INNER JOIN {$wpdb->prefix}hrim_room_categories c ON c.id=tc.category_id WHERE tc.task_id=t.id) applicability_names,
            (SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.sort_order,s.name SEPARATOR ', ') FROM {$wpdb->prefix}hrim_periodic_task_sections ts INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=ts.section_id WHERE ts.task_id=t.id) section_names
            FROM {$wpdb->prefix}hrim_periodic_task_instances i
            JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=i.task_id
            LEFT JOIN {$wpdb->prefix}hrim_departments d ON d.id=t.department_id
            INNER JOIN (SELECT task_id,MAX(due_date) latest_due FROM {$wpdb->prefix}hrim_periodic_task_instances GROUP BY task_id) latest ON latest.task_id=i.task_id AND latest.latest_due=i.due_date
            ORDER BY i.due_date DESC,i.id DESC LIMIT 100"); ?>
          <div class="hrim-table-wrap">
            <table class="hrim-table hrim-exportable">
              <thead><tr><th>Sl.</th><th>Task</th><th>Department</th><th>Applicability</th><th>Sections</th><th>Due Date</th><th>Periodicity (Days)</th><th>Rooms</th><th>Action</th></tr></thead>
              <tbody>
              <?php foreach($instances as $i=>$x):
                $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_periodic_task_rooms WHERE instance_id=%d",$x->id));
              ?>
                <tr>
                  <td><?php echo $i+1;?></td>
                  <td><?php echo esc_html($x->task_name);?></td>
                  <td><?php echo esc_html($x->department_name ?: '—');?></td>
                  <td><?php echo esc_html($x->applicability_names ?: '—');?></td>
                  <td><?php echo esc_html($x->section_names ?: '—');?></td>
                  <td><?php echo esc_html($this->ist_date('d/m/y',$x->due_date));?></td>
                  <?php $period_days=is_numeric($x->periodicity) ? max(1,(int)$x->periodicity) : (['daily'=>1,'weekly'=>7,'fortnightly'=>14,'monthly'=>30,'quarterly'=>90,'half-yearly'=>180,'yearly'=>365][$x->periodicity] ?? 30); ?>
                  <td><?php echo esc_html($period_days);?></td>
                  <td><?php echo $total?:'Not started';?></td>
                  <td><div class="hrim-periodic-actions"><?php if($this->can_manage()): ?><a class="hrim-action edit" title="Edit Periodic Task" href="<?php echo esc_url(add_query_arg('task_id',$x->task_id,$this->page_url('hotel-inspection-update-periodic-task')));?>"><span class="dashicons dashicons-edit"></span></a><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" onsubmit="return confirm('Delete this scheduled task?');"><?php wp_nonce_field('hrim_delete_periodic_instance');?><input type="hidden" name="action" value="hrim_delete_periodic_instance"><input type="hidden" name="id" value="<?php echo esc_attr($x->id);?>"><button class="hrim-action delete" type="submit" title="Delete"><span class="dashicons dashicons-trash"></span></button></form><?php endif; ?></div></td>
                </tr>
              <?php endforeach; if(!$instances): ?>
                <tr><td colspan="9">No task schedule found.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php return $this->shell('Periodic Tasks',ob_get_clean(),'periodic_tasks');
    }
    public function delete_periodic_instance(){
        if(!$this->can_manage()) wp_die('Access denied');
        check_admin_referer('hrim_delete_periodic_instance');
        global $wpdb; $id=absint($_POST['id']??0);
        if($id){
            $wpdb->delete($wpdb->prefix.'hrim_periodic_task_rooms',['instance_id'=>$id]);
            $wpdb->delete($wpdb->prefix.'hrim_periodic_task_update_log',['instance_id'=>$id]);
            $wpdb->delete($wpdb->prefix.'hrim_periodic_task_instances',['id'=>$id]);
        }
        wp_safe_redirect($this->page_url('hotel-inspection-periodic-tasks')); exit;
    }

    public function schedule_tasks_in_progress_shortcode(){
        if(!$this->can_manage() && !current_user_can('hrim_perform_inspections')) return $this->shell('Schedule Tasks In Progress','<div class="hrim-alert error">You do not have access.</div>','schedule_tasks_in_progress');
        global $wpdb; $this->generate_periodic_instances();
        if(isset($_GET['instance_id'])) return $this->periodic_instance_view(absint($_GET['instance_id']));
        $today=(new DateTime('now',$this->ist_timezone()))->format('Y-m-d');
        $sql="SELECT i.*,t.task_name FROM {$wpdb->prefix}hrim_periodic_task_instances i JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=i.task_id WHERE i.due_date<=%s AND LOWER(TRIM(COALESCE(i.status,'due'))) NOT IN ('completed','verified') ORDER BY i.due_date ASC,i.id ASC";
        $instances=$wpdb->get_results($wpdb->prepare($sql,$today));
        ob_start(); ?>
        <div class="hrim-panel hrim-schedule-task-list-panel">
          <div class="hrim-table-tools hrim-schedule-task-tools"><input type="search" class="hrim-table-search" placeholder=""><?php echo $this->excel_button('schedule-tasks-in-progress'); ?></div>
          <div class="hrim-table-wrap"><table class="hrim-table hrim-exportable"><thead><tr><th>Sl.</th><th>Due Date</th><th>Task</th><th>Rooms</th><th>Completed</th><th>Progress</th><th>Status</th><th>Action</th></tr></thead><tbody>
          <?php foreach($instances as $i=>$x):
            $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_periodic_task_rooms WHERE instance_id=%d",$x->id));
            $done=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_periodic_task_rooms WHERE instance_id=%d AND LOWER(TRIM(status)) IN ('completed','complete','1')",$x->id));
            $pct=$total?round($done*100/$total):0;
            if($pct>=100){ $progress_status='completed'; $progress_status_label='Completed'; }
            elseif($pct>0){ $progress_status='in_progress'; $progress_status_label='In Progress'; }
            else { $progress_status='yet_to_start'; $progress_status_label='Yet to Start'; } ?>
            <tr><td><?php echo $i+1;?></td><td><?php echo esc_html($this->ist_date('d/m/y',$x->due_date));?></td><td><?php echo esc_html($x->task_name);?></td><td><?php echo $total?:'Not started';?></td><td><?php echo $done;?></td><td><?php echo $pct;?>%</td><td><span class="hrim-status <?php echo esc_attr($progress_status);?>"><?php echo esc_html($progress_status_label);?></span></td><td><div class="hrim-periodic-actions"><a class="hrim-action view" title="Open Task" href="<?php echo esc_url(add_query_arg('instance_id',$x->id,$this->page_url('hotel-inspection-schedule-tasks-in-progress')));?>"><span class="dashicons dashicons-visibility"></span></a><?php if($this->can_manage()): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" onsubmit="return confirm('Delete this scheduled task cycle and all of its room updates?');"><?php wp_nonce_field('hrim_delete_schedule_task_instance');?><input type="hidden" name="action" value="hrim_delete_schedule_task_instance"><input type="hidden" name="id" value="<?php echo esc_attr($x->id);?>"><button class="hrim-action delete" type="submit" title="Delete" aria-label="Delete"><span class="dashicons dashicons-trash"></span></button></form><?php endif; ?></div></td></tr>
          <?php endforeach; if(!$instances): ?><tr><td colspan="8">No scheduled tasks are due.</td></tr><?php endif; ?>
          </tbody></table></div>
        </div>
        <?php return $this->shell('Schedule Tasks In Progress',ob_get_clean(),'schedule_tasks_in_progress');
    }

    public function delete_schedule_task_instance(){
        if(!$this->can_manage()) wp_die('Access denied');
        check_admin_referer('hrim_delete_schedule_task_instance');
        global $wpdb;
        $id=absint($_POST['id']??0);
        if($id){
            $wpdb->delete($wpdb->prefix.'hrim_periodic_task_update_log',['instance_id'=>$id]);
            $wpdb->delete($wpdb->prefix.'hrim_periodic_task_rooms',['instance_id'=>$id]);
            $wpdb->delete($wpdb->prefix.'hrim_periodic_task_instances',['id'=>$id]);
        }
        wp_safe_redirect($this->page_url('hotel-inspection-schedule-tasks-in-progress'));
        exit;
    }

    public function delete_periodic_update_log(){
        if(!$this->can_manage()) wp_die('Access denied');
        check_admin_referer('hrim_delete_periodic_update_log');
        global $wpdb;
        $id=absint($_POST['id']??0);
        if($id) $wpdb->delete($wpdb->prefix.'hrim_periodic_task_update_log',['id'=>$id]);
        wp_safe_redirect($this->page_url('hotel-inspection-schedule-tasks-history'));
        exit;
    }

    public function add_periodic_task_shortcode(){
        if(!$this->can_manage()) return $this->shell('Add Periodic Tasks','<div class="hrim-alert error">You do not have access.</div>','add_periodic_task');
        global $wpdb;
        $categories=$wpdb->get_results("SELECT c.id,c.name,(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_rooms r WHERE (r.category_id=c.id OR (COALESCE(r.category_id,0)=0 AND TRIM(LOWER(r.category))=TRIM(LOWER(c.name)))) AND LOWER(TRIM(COALESCE(r.status,'active'))) IN ('active','1','yes')) room_count FROM {$wpdb->prefix}hrim_room_categories c WHERE LOWER(TRIM(COALESCE(c.status,'active'))) IN ('active','1','yes') ORDER BY c.name");
        $sections=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_sections WHERE status='active' ORDER BY sort_order,name");
        $departments=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_departments WHERE status='active' ORDER BY name");
        ob_start(); ?>
        <div class="hrim-panel hrim-periodic-add-panel">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hrim-form hrim-periodic-form" id="hrimAddPeriodicTaskForm">
            <?php wp_nonce_field('hrim_save_periodic_task');?>
            <input type="hidden" name="action" value="hrim_save_periodic_task">
            <input type="hidden" name="id" value="0">
            <div class="hrim-periodic-grid">
              <label><span>Task *</span><input name="task_name" required placeholder="Enter task name"></label>
              <label><span>Applicability *</span><select id="hrimPeriodicCategories" name="category_ids[]" multiple required><?php foreach($categories as $index=>$c):?><option value="<?php echo esc_attr($c->id);?>" data-room-count="<?php echo esc_attr((int)$c->room_count);?>" <?php selected($index===0);?>><?php echo esc_html($c->name);?></option><?php endforeach;?></select><small>Select one or more room categories.</small></label>
              <label><span>Periodicity (Days) *</span><input type="number" min="1" step="1" name="periodicity" required value="1" placeholder="Enter number of days"></label>
              <label><span>No of Rooms</span><input id="hrimPeriodicCount" class="hrim-calculated-field" type="text" readonly aria-readonly="true" value="0" style="background:#eef1f5!important;background-color:#eef1f5!important;color:#475569!important;border-color:#c4cbd5!important;box-shadow:none!important;cursor:not-allowed!important;opacity:1!important;"><small>Automatically calculated from selected room categories.</small></label>
              <label><span>Select Sections *</span><select name="section_ids[]" multiple required data-initial-empty="1"><?php foreach($sections as $sec):?><option value="<?php echo esc_attr($sec->id);?>"><?php echo esc_html($sec->name);?></option><?php endforeach;?></select></label>
              <label><span>Start Date *</span><input type="date" name="start_date" required value="<?php echo esc_attr((new DateTime('now',$this->ist_timezone()))->format('Y-m-d'));?>"></label>
              <label><span>Responsible Department *</span><select id="hrimPeriodicDepartment" name="department_id" required><option value="">Select Department</option><?php foreach($departments as $d):?><option value="<?php echo esc_attr($d->id);?>"><?php echo esc_html($d->name);?></option><?php endforeach;?></select></label>
              <label><span class="hrim-label-title">Status <span class="hrim-required-mark">*</span></span><select name="status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            </div>
            <div class="hrim-form-actions"><button type="submit" class="hrim-btn primary" id="hrimSavePeriodicTaskButton">Save Periodic Task</button></div>
          </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
          const categories=document.getElementById('hrimPeriodicCategories');
          const count=document.getElementById('hrimPeriodicCount');
          const updateCount=function(){
            let total=0;
            if(categories){
              let selected=Array.from(categories.options).filter(function(option){ return option.selected; });
              if(!selected.length && categories.options.length){
                categories.options[0].selected=true;
                selected=[categories.options[0]];
              }
              selected.forEach(function(option){ total+=parseInt(option.dataset.roomCount||'0',10); });
            }
            if(count) count.value=String(total);
          };
          if(categories){ categories.addEventListener('change',updateCount); updateCount(); setTimeout(updateCount,250); }

          const periodicForm=document.getElementById('hrimAddPeriodicTaskForm');
          const saveButton=document.getElementById('hrimSavePeriodicTaskButton');
          if(periodicForm && saveButton){
            periodicForm.addEventListener('submit',function(){
              saveButton.disabled=true;
              saveButton.classList.add('is-submitting');
              saveButton.textContent='Submitting...';
            });
          }
        });
        </script>
        <?php return $this->shell('Add Periodic Tasks',ob_get_clean(),'add_periodic_task');
    }


    public function update_periodic_task_shortcode(){
        if(!$this->can_manage()) return $this->shell('Update Periodic Task','<div class="hrim-alert error">You do not have access.</div>','periodic_tasks');
        global $wpdb;
        $task_id=absint($_GET['task_id']??0);
        $task=$task_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_periodic_tasks WHERE id=%d",$task_id)):null;
        if(!$task) return $this->shell('Update Periodic Task','<div class="hrim-alert error">Periodic task not found.</div>','periodic_tasks');
        $categories=$wpdb->get_results("SELECT c.id,c.name,(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_rooms r WHERE (r.category_id=c.id OR (COALESCE(r.category_id,0)=0 AND TRIM(LOWER(r.category))=TRIM(LOWER(c.name)))) AND LOWER(TRIM(COALESCE(r.status,'active'))) IN ('active','1','yes')) room_count FROM {$wpdb->prefix}hrim_room_categories c WHERE LOWER(TRIM(COALESCE(c.status,'active'))) IN ('active','1','yes') ORDER BY c.name");
        $sections=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_sections WHERE status='active' ORDER BY sort_order,name");
        $departments=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_departments WHERE status='active' ORDER BY name");
        $sel_c=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT category_id FROM {$wpdb->prefix}hrim_periodic_task_categories WHERE task_id=%d",$task_id)));
        $sel_s=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT section_id FROM {$wpdb->prefix}hrim_periodic_task_sections WHERE task_id=%d",$task_id)));
        $period_days=is_numeric($task->periodicity)?max(1,(int)$task->periodicity):(['daily'=>1,'weekly'=>7,'fortnightly'=>14,'monthly'=>30,'quarterly'=>90,'half-yearly'=>180,'yearly'=>365][$task->periodicity]??30);
        $periodic_questions=$wpdb->get_results("SELECT q.id,q.section_id,q.question,q.required_flag,s.name section_name,s.sort_order section_order,q.sort_order FROM {$wpdb->prefix}hrim_questions q INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=q.section_id WHERE q.is_library=1 AND s.status='active' ORDER BY s.sort_order,s.name,q.sort_order,q.id");
        $excluded_periodic_questions=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT question_id FROM {$wpdb->prefix}hrim_periodic_task_question_exclusions WHERE task_id=%d",$task_id)));
        ob_start(); ?>
        <div class="hrim-panel hrim-periodic-add-panel">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hrim-form hrim-periodic-form">
            <?php wp_nonce_field('hrim_save_periodic_task');?>
            <input type="hidden" name="action" value="hrim_save_periodic_task">
            <input type="hidden" name="id" value="<?php echo esc_attr($task_id);?>">
            <div class="hrim-periodic-grid">
              <label><span>Task *</span><input name="task_name" required value="<?php echo esc_attr($task->task_name);?>" placeholder="Enter task name"></label>
              <label><span>Applicability *</span><select id="hrimPeriodicCategories" name="category_ids[]" multiple required><?php foreach($categories as $c):?><option value="<?php echo esc_attr($c->id);?>" data-room-count="<?php echo esc_attr((int)$c->room_count);?>" <?php selected(in_array((int)$c->id,$sel_c,true));?>><?php echo esc_html($c->name);?></option><?php endforeach;?></select><small>Select one or more room categories.</small></label>
              <label><span>Periodicity (Days) *</span><input type="number" min="1" step="1" name="periodicity" required value="<?php echo esc_attr($period_days);?>"></label>
              <label><span>No of Rooms</span><input id="hrimPeriodicCount" class="hrim-calculated-field" type="text" readonly aria-readonly="true" value="0" style="background:#eef1f5!important;background-color:#eef1f5!important;color:#475569!important;border-color:#c4cbd5!important;box-shadow:none!important;cursor:not-allowed!important;opacity:1!important;"><small>Automatically calculated from selected room categories.</small></label>
              <label><span>Select Sections *</span><select name="section_ids[]" multiple required><?php foreach($sections as $sec):?><option value="<?php echo esc_attr($sec->id);?>" <?php selected(in_array((int)$sec->id,$sel_s,true));?>><?php echo esc_html($sec->name);?></option><?php endforeach;?></select></label>
              <label><span>Start Date *</span><input type="date" name="start_date" required value="<?php echo esc_attr($task->start_date);?>"></label>
              <label><span>Responsible Department *</span><select id="hrimPeriodicDepartment" name="department_id" required><option value="">Select Department</option><?php foreach($departments as $d):?><option value="<?php echo esc_attr($d->id);?>" <?php selected((int)$task->department_id,(int)$d->id);?>><?php echo esc_html($d->name);?></option><?php endforeach;?></select></label>
              <label><span class="hrim-label-title">Status <span class="hrim-required-mark">*</span></span><select name="status" required><option value="active" <?php selected($task->status,'active');?>>Active</option><option value="inactive" <?php selected($task->status,'inactive');?>>Inactive</option></select></label>
            </div>
            <div class="hrim-subpanel hrim-periodic-checkpoint-selector">
              <div class="hrim-panel-head"><div><h3>Periodic Task Checkpoints</h3><p class="hrim-subtext">Untick any checkpoint that should not be included in this periodic task.</p></div></div>
              <?php $last_periodic_section=''; foreach($periodic_questions as $pq): if($last_periodic_section!==$pq->section_name): if($last_periodic_section!=='') echo '</div>'; $last_periodic_section=$pq->section_name; ?><h4 class="hrim-override-section" data-periodic-section-id="<?php echo esc_attr($pq->section_id);?>"><?php echo esc_html($pq->section_name);?></h4><div class="hrim-checkpoint-override-list" data-periodic-section="<?php echo esc_attr($pq->section_id);?>"><?php endif; ?><label class="hrim-checkpoint-override"><input type="checkbox" name="included_periodic_question_ids[]" value="<?php echo esc_attr($pq->id);?>" data-section-id="<?php echo esc_attr($pq->section_id);?>" <?php checked(!in_array((int)$pq->id,$excluded_periodic_questions,true));?>><span><strong><?php echo esc_html($pq->question);?></strong><?php if((int)$pq->required_flag===1):?><small>Mandatory</small><?php endif;?></span></label><?php endforeach; if($last_periodic_section!=='') echo '</div>'; ?>
            </div>
            <div class="hrim-form-actions"><button class="hrim-btn primary">Update Periodic Task</button></div>
          </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
          const categories=document.getElementById('hrimPeriodicCategories');
          const count=document.getElementById('hrimPeriodicCount');
          const updateCount=function(){
            let total=0;
            if(categories) Array.from(categories.options).filter(function(option){return option.selected;}).forEach(function(option){total+=parseInt(option.dataset.roomCount||'0',10);});
            if(count) count.value=String(total);
          };
          if(categories){categories.addEventListener('change',updateCount);updateCount();setTimeout(updateCount,250);}
          const selectedSections=document.querySelector('select[name="section_ids[]"]');
          const syncCheckpointSections=function(){
            if(!selectedSections)return;
            const active=Array.from(selectedSections.options).filter(function(o){return o.selected;}).map(function(o){return o.value;});
            document.querySelectorAll('[data-periodic-section-id]').forEach(function(h){h.style.display=active.includes(h.dataset.periodicSectionId)?'':'none';});
            document.querySelectorAll('[data-periodic-section]').forEach(function(box){box.style.display=active.includes(box.dataset.periodicSection)?'':'none';});
          };
          if(selectedSections){selectedSections.addEventListener('change',syncCheckpointSections);syncCheckpointSections();}
        });
        </script>
        <?php return $this->shell('Update Periodic Task',ob_get_clean(),'schedule_tasks_in_progress');
    }

    public function save_periodic_task(){
        if(!$this->can_manage()) wp_die('Access denied'); check_admin_referer('hrim_save_periodic_task'); global $wpdb;
        $id=absint($_POST['id']??0); $periodicity=max(1,absint($_POST['periodicity']??1)); $data=['task_name'=>sanitize_text_field($_POST['task_name']??''),'periodicity'=>(string)$periodicity,'start_date'=>sanitize_text_field($_POST['start_date']??''),'department_id'=>absint($_POST['department_id']??0),'assigned_to'=>0,'status'=>sanitize_key($_POST['status']??'active'),'updated_at'=>$this->ist_now_mysql()];
        if(!$id){$data['reminder_value']=0;$data['reminder_unit']='days';}
        if($id)$wpdb->update($wpdb->prefix.'hrim_periodic_tasks',$data,['id'=>$id]); else{$data['created_by']=get_current_user_id();$data['created_at']=$this->ist_now_mysql();$wpdb->insert($wpdb->prefix.'hrim_periodic_tasks',$data);$id=$wpdb->insert_id;}
        $wpdb->delete($wpdb->prefix.'hrim_periodic_task_categories',['task_id'=>$id]); foreach(array_map('absint',(array)($_POST['category_ids']??[])) as $cid)$wpdb->insert($wpdb->prefix.'hrim_periodic_task_categories',['task_id'=>$id,'category_id'=>$cid]);
        $wpdb->delete($wpdb->prefix.'hrim_periodic_task_sections',['task_id'=>$id]); $selected_section_ids=array_map('absint',(array)($_POST['section_ids']??[])); foreach($selected_section_ids as $sid)$wpdb->insert($wpdb->prefix.'hrim_periodic_task_sections',['task_id'=>$id,'section_id'=>$sid]);
        $wpdb->delete($wpdb->prefix.'hrim_periodic_task_question_exclusions',['task_id'=>$id]);
        if(array_key_exists('included_periodic_question_ids',$_POST)){
            $included_question_ids=array_map('absint',(array)$_POST['included_periodic_question_ids']);
            if($selected_section_ids){
                $placeholders=implode(',',array_fill(0,count($selected_section_ids),'%d'));
                $all_question_ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_questions WHERE is_library=1 AND section_id IN ($placeholders)",$selected_section_ids));
                foreach(array_diff(array_map('intval',$all_question_ids),$included_question_ids) as $qid)$wpdb->insert($wpdb->prefix.'hrim_periodic_task_question_exclusions',['task_id'=>$id,'question_id'=>$qid]);
            }
        }
        wp_safe_redirect($this->page_url('hotel-inspection-periodic-tasks'));exit;
    }
    public function delete_periodic_task(){if(!$this->can_manage())wp_die('Access denied');check_admin_referer('hrim_delete_periodic_task');global $wpdb;$id=absint($_POST['id']??0);$has=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_periodic_task_instances WHERE task_id=%d AND status IN ('completed','verified')",$id));if($has)$wpdb->update($wpdb->prefix.'hrim_periodic_tasks',['status'=>'inactive'],['id'=>$id]);else{foreach(['categories','sections','instances'] as $x)$wpdb->delete($wpdb->prefix.'hrim_periodic_task_'.$x,['task_id'=>$id]);$wpdb->delete($wpdb->prefix.'hrim_periodic_tasks',['id'=>$id]);}wp_safe_redirect($this->page_url('hotel-inspection-periodic-tasks'));exit;}
    private function periodic_instance_view($id){
        global $wpdb;$ins=$wpdb->get_row($wpdb->prepare("SELECT i.*,t.task_name,t.department_id,t.periodicity FROM {$wpdb->prefix}hrim_periodic_task_instances i JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=i.task_id WHERE i.id=%d",$id));if(!$ins)return $this->shell('Schedule Tasks In Progress','<div class="hrim-alert error">Task instance not found.</div>','schedule_tasks_in_progress');
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_periodic_task_rooms WHERE instance_id=%d",$id));if(!$count){$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}hrim_periodic_task_rooms(instance_id,room_id,category_id) SELECT %d,r.id,r.category_id FROM {$wpdb->prefix}hrim_rooms r JOIN {$wpdb->prefix}hrim_periodic_task_categories c ON c.category_id=r.category_id WHERE c.task_id=%d AND LOWER(TRIM(COALESCE(r.status,'active'))) IN ('active','1','yes')",$id,$ins->task_id));$wpdb->update($wpdb->prefix.'hrim_periodic_task_instances',['status'=>'in_progress','started_at'=>$this->ist_now_mysql()],['id'=>$id]);}
        $room_id=absint($_GET['room_id']??0); if($room_id)return $this->periodic_room_form($ins,$room_id);
        $rooms=$wpdb->get_results($wpdb->prepare("SELECT tr.*,r.room_no,c.name category_name FROM {$wpdb->prefix}hrim_periodic_task_rooms tr JOIN {$wpdb->prefix}hrim_rooms r ON r.id=tr.room_id LEFT JOIN {$wpdb->prefix}hrim_room_categories c ON c.id=tr.category_id WHERE tr.instance_id=%d ORDER BY c.name,CAST(r.room_no AS UNSIGNED),r.room_no",$id));ob_start();?><div class="hrim-panel"><h2><?php echo esc_html($ins->task_name);?></h2><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Room Category</th><th>Room</th><th>Task</th><th>Status</th><th>Last Update Date</th><th>Last Update Remarks</th><th>Next Action Date</th><th>Action</th></tr></thead><tbody><?php foreach($rooms as $r): $room_task_status=strtolower(trim((string)($r->status??'pending'))); $room_task_status=in_array($room_task_status,['completed','complete','1'],true)?'completed':'pending'; $last_update_raw=!empty($r->updated_at)?$r->updated_at:(!empty($r->completed_at)?$r->completed_at:''); $last_update_date=$last_update_raw?$this->ist_date('d/m/y',$last_update_raw):'—'; $last_update_remarks=trim((string)($r->remarks??'')); $periodicity_days=max(1,absint($ins->periodicity??1)); if($room_task_status==='completed' && !empty($r->completed_at)){ $next_action_ts=strtotime($r->completed_at.' +'.$periodicity_days.' days'); $next_action_date=$next_action_ts?$this->ist_date('d/m/y',date('Y-m-d H:i:s',$next_action_ts)):'—'; } else { $next_action_date=$this->ist_date('d/m/y',$ins->due_date); } ?><tr><td><?php echo esc_html($r->category_name);?></td><td><?php echo esc_html($r->room_no);?></td><td><?php echo esc_html($ins->task_name);?></td><td><span class="hrim-status <?php echo esc_attr($room_task_status);?>"><?php echo esc_html(ucfirst($room_task_status));?></span></td><td><?php echo esc_html($last_update_date);?></td><td><?php echo $last_update_remarks!==''?esc_html($last_update_remarks):'—';?></td><td><?php echo esc_html($next_action_date);?></td><td><a class="hrim-action edit" title="Update Periodic Task" href="<?php echo esc_url(add_query_arg(['instance_id'=>$id,'room_id'=>$r->room_id],$this->page_url('hotel-inspection-schedule-tasks-in-progress')));?>"><span class="dashicons dashicons-edit"></span></a></td></tr><?php endforeach;?></tbody></table></div></div><?php return $this->shell('Periodic Task',ob_get_clean(),'schedule_tasks_in_progress');
    }
    private function periodic_room_form($ins,$room_id){
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT tr.*,r.room_no,c.name category_name FROM {$wpdb->prefix}hrim_periodic_task_rooms tr JOIN {$wpdb->prefix}hrim_rooms r ON r.id=tr.room_id LEFT JOIN {$wpdb->prefix}hrim_room_categories c ON c.id=tr.category_id WHERE tr.instance_id=%d AND tr.room_id=%d",$ins->id,$room_id));
        if(!$row)return $this->shell('Update Periodic Task','<div class="hrim-alert error">Room task not found.</div>','schedule_tasks_in_progress');

        $photo_attachment_id=isset($row->photo_attachment_id)?absint($row->photo_attachment_id):0;
        $photo_url=$photo_attachment_id?wp_get_attachment_url($photo_attachment_id):'';

        $questions=$wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT q.id,q.section_id,s.name section_name,s.sort_order section_order,q.question,q.required_flag,q.sort_order
             FROM {$wpdb->prefix}hrim_periodic_task_sections pts
             INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=pts.section_id AND s.status='active'
             INNER JOIN {$wpdb->prefix}hrim_questions q ON q.section_id=s.id AND q.is_library=1
             LEFT JOIN {$wpdb->prefix}hrim_room_question_exclusions rx ON rx.room_id=%d AND rx.question_id=q.id
             LEFT JOIN {$wpdb->prefix}hrim_periodic_task_question_exclusions pex ON pex.task_id=pts.task_id AND pex.question_id=q.id
             WHERE pts.task_id=%d AND rx.question_id IS NULL AND pex.question_id IS NULL
             UNION ALL
             SELECT q.id,q.section_id,s.name section_name,s.sort_order section_order,q.question,q.required_flag,q.sort_order
             FROM {$wpdb->prefix}hrim_periodic_task_sections pts
             INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=pts.section_id AND s.status='active'
             INNER JOIN {$wpdb->prefix}hrim_room_question_additions a ON a.room_id=%d
             INNER JOIN {$wpdb->prefix}hrim_questions q ON q.id=a.question_id AND q.section_id=s.id
             LEFT JOIN {$wpdb->prefix}hrim_periodic_task_question_exclusions pex ON pex.task_id=pts.task_id AND pex.question_id=q.id
             WHERE pts.task_id=%d AND pex.question_id IS NULL
             ORDER BY section_order,section_name,sort_order,id",
            $room_id,(int)$ins->task_id,$room_id,(int)$ins->task_id
        ));
        $answer_rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hrim_periodic_task_answers WHERE instance_id=%d AND room_id=%d",
            (int)$ins->id,$room_id
        ));
        $answers=[];
        foreach($answer_rows as $a)$answers[(int)$a->question_id]=$a;
        $grouped=[];
        foreach($questions as $q)$grouped[$q->section_id]['section']=$q->section_name;
        foreach($questions as $q)$grouped[$q->section_id]['questions'][]=$q;

        ob_start();?>
        <div class="hrim-panel hrim-periodic-inspection-panel">
            <form id="hrimPeriodicCheckpointForm" class="hrim-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hrim_save_periodic_task_answers');?>
                <input type="hidden" name="action" value="hrim_save_periodic_task_answers">
                <input type="hidden" name="instance_id" value="<?php echo esc_attr($ins->id);?>">
                <input type="hidden" name="room_id" value="<?php echo esc_attr($room_id);?>">

                <div class="hrim-periodic-room-summary">
                    <div><small>Room Category</small><strong><?php echo esc_html($row->category_name??'—');?></strong></div>
                    <div><small>Room</small><strong><?php echo esc_html($row->room_no??'—');?></strong></div>
                    <div><small>Periodic Task</small><strong><?php echo esc_html($ins->task_name??'—');?></strong></div>
                </div>

                <?php if(!$questions):?>
                    <div class="hrim-alert error">No active checkpoints were found in the selected sections.</div>
                <?php else: $checkpoint_no=0; foreach($grouped as $section):?>
                    <div class="hrim-periodic-section-block">
                        <div class="hrim-periodic-section-header">
                            <span><?php echo esc_html($section['section']);?></span>
                            <small><?php echo esc_html(count($section['questions']));?> Checkpoint<?php echo count($section['questions'])===1?'':'s';?></small>
                        </div>
                        <?php foreach($section['questions'] as $q): $checkpoint_no++; $saved=$answers[(int)$q->id]??null; $answer=strtolower(trim((string)($saved->answer??''))); $qremarks=(string)($saved->remarks??''); $qphoto_id=absint($saved->photo_attachment_id??0); $qphoto_url=$qphoto_id?wp_get_attachment_url($qphoto_id):'';?>
                            <div class="hrim-question-card hrim-question-yes-no hrim-periodic-checkpoint-card <?php echo $answer==='yes'?'hrim-response-yes':($answer==='no'?'hrim-response-no':'');?>">
                                <div class="hrim-question-number"><?php echo esc_html($checkpoint_no);?></div>
                                <div class="hrim-question-content">
                                    <h3><?php echo esc_html($q->question);?><?php echo (int)$q->required_flag===1?' *':'';?></h3>
                                    <div class="hrim-options hrim-periodic-answer-options" role="group" aria-label="Checkpoint answer">
                                        <label class="<?php echo $answer==='yes'?'hrim-option-selected-yes':'';?>">
                                            <input type="radio" name="answers[<?php echo esc_attr($q->id);?>]" value="yes" <?php checked($answer,'yes');?> <?php echo (int)$q->required_flag===1?'required':'';?>>
                                            <span class="dashicons dashicons-yes-alt"></span><span>Yes</span>
                                        </label>
                                        <label class="<?php echo $answer==='no'?'hrim-option-selected-no':'';?>">
                                            <input type="radio" name="answers[<?php echo esc_attr($q->id);?>]" value="no" <?php checked($answer,'no');?> <?php echo (int)$q->required_flag===1?'required':'';?>>
                                            <span class="dashicons dashicons-dismiss"></span><span>No</span>
                                        </label>
                                    </div>
                                    <div class="hrim-response-row hrim-periodic-response-row">
                                        <div class="hrim-row-photo">
                                            <label class="hrim-photo-button" title="Add photo">
                                                <span class="dashicons dashicons-camera" aria-hidden="true"></span><span class="hrim-action-text">Photo</span>
                                                <input type="file" name="question_photo_<?php echo esc_attr($q->id);?>" accept="image/*" capture="environment" hidden>
                                            </label>
                                            <?php if($qphoto_url):?><a class="hrim-periodic-existing-photo" href="<?php echo esc_url($qphoto_url);?>" target="_blank" rel="noopener"><span class="dashicons dashicons-format-image"></span> Existing Photo</a><?php endif;?>
                                        </div>
                                        <button type="button" class="hrim-remarks-toggle" style="background:transparent!important;background-color:transparent!important;background-image:none!important;border:0!important;box-shadow:none!important;color:#2563eb!important;width:auto!important;min-width:0!important;height:auto!important;min-height:0!important;padding:4px!important;border-radius:0!important;" title="Remarks" aria-expanded="<?php echo $qremarks!==''?'true':'false';?>">
                                            <span class="dashicons dashicons-admin-comments" aria-hidden="true"></span><span class="hrim-action-text">Remarks</span>
                                        </button>
                                    </div>
                                    <textarea class="hrim-checkpoint-remarks" name="remarks[<?php echo esc_attr($q->id);?>]" rows="3" placeholder="Enter remarks<?php echo $answer==='no'?' (required for No)':'';?>" <?php echo $qremarks!==''||$answer==='no'?'style="display:block"':'';?>><?php echo esc_textarea($qremarks);?></textarea>
                                </div>
                            </div>
                        <?php endforeach;?>
                    </div>
                <?php endforeach; endif;?>

                <div class="hrim-periodic-overall-evidence">
                    <label><span>Overall Photo <small>(optional)</small></span><input type="file" name="task_photo" accept="image/*"></label>
                    <?php if($photo_url):?><a class="hrim-periodic-existing-photo" href="<?php echo esc_url($photo_url);?>" target="_blank" rel="noopener"><span class="dashicons dashicons-format-image"></span> View Existing Overall Photo</a><?php endif;?>
                </div>

                <div class="hrim-form-actions hrim-inspection-actions">
                    <button class="hrim-primary hrim-large" type="submit">Update Periodic Task</button>
                </div>
            </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
            var form=document.getElementById('hrimPeriodicCheckpointForm');
            if(!form)return;
            form.querySelectorAll('.hrim-periodic-checkpoint-card').forEach(function(card){
                var remarks=card.querySelector('.hrim-checkpoint-remarks');
                var toggle=card.querySelector('.hrim-remarks-toggle');
                function sync(){
                    var selected=card.querySelector('input[type="radio"]:checked');
                    card.classList.remove('hrim-response-yes','hrim-response-no');
                    card.querySelectorAll('.hrim-periodic-answer-options label').forEach(function(label){label.classList.remove('hrim-option-selected-yes','hrim-option-selected-no');});
                    if(!selected)return;
                    var label=selected.closest('label');
                    if(selected.value==='yes'){
                        card.classList.add('hrim-response-yes');
                        label.classList.add('hrim-option-selected-yes');
                    }else{
                        card.classList.add('hrim-response-no');
                        label.classList.add('hrim-option-selected-no');
                        if(remarks)remarks.style.display='block';
                    }
                }
                card.querySelectorAll('input[type="radio"]').forEach(function(input){input.addEventListener('change',sync);});
                if(toggle&&remarks){toggle.addEventListener('click',function(){var open=remarks.style.display==='block';remarks.style.display=open?'none':'block';toggle.setAttribute('aria-expanded',open?'false':'true');if(!open)remarks.focus();});}
                sync();
            });
            form.addEventListener('submit',function(e){
                var invalid=false;
                form.querySelectorAll('.hrim-periodic-checkpoint-card').forEach(function(card){
                    var no=card.querySelector('input[type="radio"][value="no"]:checked');
                    var remarks=card.querySelector('.hrim-checkpoint-remarks');
                    if(no&&remarks&&!remarks.value.trim()){remarks.style.display='block';remarks.focus();invalid=true;}
                });
                if(invalid){e.preventDefault();alert('Remarks are required for every checkpoint marked No.');}
            });
        });
        </script>
        <?php return $this->shell('Update Periodic Task',ob_get_clean(),'schedule_tasks_in_progress');
    }
    public function save_periodic_task_answers(){
        check_admin_referer('hrim_save_periodic_task_answers');
        global $wpdb;

        $instance_id=absint($_POST['instance_id']??0);
        $room_id=absint($_POST['room_id']??0);
        if(!$instance_id || !$room_id){
            wp_safe_redirect(add_query_arg(['instance_id'=>$instance_id,'periodic_error'=>'invalid_record'],$this->page_url('hotel-inspection-schedule-tasks-in-progress')));
            exit;
        }

        if(!class_exists('HRIM_Activator')) require_once HRIM_DIR.'includes/class-hrim-activator.php';
        HRIM_Activator::create_tables();

        $instance=$wpdb->get_row($wpdb->prepare(
            "SELECT i.task_id,i.due_date,t.periodicity FROM {$wpdb->prefix}hrim_periodic_task_instances i INNER JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=i.task_id WHERE i.id=%d",
            $instance_id
        ));
        if(!$instance){
            wp_safe_redirect($this->page_url('hotel-inspection-schedule-tasks-in-progress'));
            exit;
        }

        $questions=$wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT q.id,q.section_id,q.required_flag,q.question,s.department_id
             FROM {$wpdb->prefix}hrim_periodic_task_sections pts
             INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=pts.section_id AND s.status='active'
             INNER JOIN {$wpdb->prefix}hrim_questions q ON q.section_id=s.id AND q.is_library=1
             LEFT JOIN {$wpdb->prefix}hrim_room_question_exclusions rx ON rx.room_id=%d AND rx.question_id=q.id
             LEFT JOIN {$wpdb->prefix}hrim_periodic_task_question_exclusions pex ON pex.task_id=pts.task_id AND pex.question_id=q.id
             WHERE pts.task_id=%d AND rx.question_id IS NULL AND pex.question_id IS NULL
             UNION ALL
             SELECT q.id,q.section_id,q.required_flag,q.question,s.department_id
             FROM {$wpdb->prefix}hrim_periodic_task_sections pts
             INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=pts.section_id AND s.status='active'
             INNER JOIN {$wpdb->prefix}hrim_room_question_additions a ON a.room_id=%d
             INNER JOIN {$wpdb->prefix}hrim_questions q ON q.id=a.question_id AND q.section_id=s.id
             LEFT JOIN {$wpdb->prefix}hrim_periodic_task_question_exclusions pex ON pex.task_id=pts.task_id AND pex.question_id=q.id
             WHERE pts.task_id=%d AND pex.question_id IS NULL",
            $room_id,(int)$instance->task_id,$room_id,(int)$instance->task_id
        ));
        $valid=[];
        foreach($questions as $q)$valid[(int)$q->id]=$q;
        $posted_answers=(array)($_POST['answers']??[]);
        $posted_remarks=(array)($_POST['remarks']??[]);
        $now=$this->ist_now_mysql();
        $all_required_complete=true;
        $has_no=false;
        $summary_remarks=[];
        $checkpoint_log_rows=[];
        $room_context=$wpdb->get_row($wpdb->prepare(
            "SELECT r.hotel_id,r.floor_id,r.category_id FROM {$wpdb->prefix}hrim_rooms r WHERE r.id=%d",
            $room_id
        ));

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        foreach($valid as $qid=>$q){
            $answer=strtolower(trim(sanitize_key($posted_answers[$qid]??'')));
            if(!in_array($answer,['yes','no'],true))$answer='';
            $remarks=sanitize_textarea_field($posted_remarks[$qid]??'');
            if((int)$q->required_flag===1 && $answer==='')$all_required_complete=false;
            if($answer==='no'){
                $has_no=true;
                if($remarks===''){
                    wp_safe_redirect(add_query_arg(['instance_id'=>$instance_id,'room_id'=>$room_id,'periodic_error'=>'remarks_required'],$this->page_url('hotel-inspection-schedule-tasks-in-progress')));
                    exit;
                }
            }
            if($remarks!=='')$summary_remarks[]=$remarks;

            $existing=$wpdb->get_row($wpdb->prepare(
                "SELECT id,photo_attachment_id FROM {$wpdb->prefix}hrim_periodic_task_answers WHERE instance_id=%d AND room_id=%d AND question_id=%d",
                $instance_id,$room_id,$qid
            ));
            $data=[
                'instance_id'=>$instance_id,
                'room_id'=>$room_id,
                'section_id'=>(int)$q->section_id,
                'question_id'=>$qid,
                'answer'=>$answer?:null,
                'remarks'=>$remarks,
                'updated_by'=>get_current_user_id(),
                'updated_at'=>$now,
            ];
            $file_key='question_photo_'.$qid;
            if(!empty($_FILES[$file_key]['name'])){
                $attachment_id=media_handle_upload($file_key,0);
                if(!is_wp_error($attachment_id))$data['photo_attachment_id']=$attachment_id;
            }
            if($existing)$wpdb->update($wpdb->prefix.'hrim_periodic_task_answers',$data,['id'=>(int)$existing->id]);
            else $wpdb->insert($wpdb->prefix.'hrim_periodic_task_answers',$data);

            $effective_photo_id=absint($data['photo_attachment_id']??($existing->photo_attachment_id??0));
            $checkpoint_log_rows[]=[
                'section_id'=>(int)$q->section_id,
                'question_id'=>$qid,
                'answer'=>$answer,
                'remarks'=>$remarks,
                'photo_attachment_id'=>$effective_photo_id,
            ];

            if($answer==='no' && $room_context){
                $open_issue_id=(int)$wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}hrim_issues WHERE source='periodic_task' AND room_id=%d AND question_id=%d AND LOWER(TRIM(COALESCE(status,'open'))) NOT IN ('resolved','verified','closed') ORDER BY id DESC LIMIT 1",
                    $room_id,$qid
                ));
                $issue_data=[
                    'source'=>'periodic_task',
                    'inspection_id'=>null,
                    'hotel_id'=>absint($room_context->hotel_id),
                    'floor_id'=>absint($room_context->floor_id)?:null,
                    'room_id'=>$room_id,
                    'section_id'=>(int)$q->section_id,
                    'department_id'=>absint($q->department_id??0),
                    'question_id'=>$qid,
                    'title'=>(string)($q->question??'Periodic task checkpoint failed'),
                    'description'=>$remarks,
                    'priority'=>'medium',
                    'status'=>'open',
                    'reported_by'=>get_current_user_id(),
                    'attachment_ids'=>$effective_photo_id?(string)$effective_photo_id:null,
                    'created_at'=>$now,
                ];
                if($open_issue_id)$wpdb->update($wpdb->prefix.'hrim_issues',$issue_data,['id'=>$open_issue_id]);
                else { $wpdb->insert($wpdb->prefix.'hrim_issues',$issue_data); $new_issue_id=(int)$wpdb->insert_id; if($new_issue_id)$this->notify_department_for_issue($new_issue_id); }
            }
        }

        $room_status=$all_required_complete?'completed':'pending';
        $room_table=$wpdb->prefix.'hrim_periodic_task_rooms';
        $room_data=[
            'status'=>$room_status,
            'remarks'=>implode(' | ',array_slice($summary_remarks,0,10)),
            'completed_at'=>$room_status==='completed'?$now:null,
            'completed_by'=>$room_status==='completed'?get_current_user_id():null,
            'updated_at'=>$now,
        ];
        if(!empty($_FILES['task_photo']['name'])){
            $attachment_id=media_handle_upload('task_photo',0);
            if(!is_wp_error($attachment_id))$room_data['photo_attachment_id']=$attachment_id;
        }
        $wpdb->update($room_table,$room_data,['instance_id'=>$instance_id,'room_id'=>$room_id],null,['%d','%d']);

        $room_record=$wpdb->get_row($wpdb->prepare(
            "SELECT category_id,photo_attachment_id,completed_at,updated_at,remarks,status FROM {$room_table} WHERE instance_id=%d AND room_id=%d",
            $instance_id,$room_id
        ));
        if($room_record){
            $periodicity_days=max(1,absint($instance->periodicity??1));
            $next_action_date=$room_status==='completed'&&!empty($room_record->completed_at)
                ?date('Y-m-d',strtotime($room_record->completed_at.' +'.$periodicity_days.' days'))
                :$instance->due_date;
            foreach($checkpoint_log_rows as $checkpoint_log){
                if(empty($checkpoint_log['answer'])) continue;
                $wpdb->insert($wpdb->prefix.'hrim_periodic_task_update_log',[
                    'instance_id'=>$instance_id,
                    'task_id'=>(int)$instance->task_id,
                    'room_id'=>$room_id,
                    'category_id'=>absint($room_record->category_id??0),
                    'section_id'=>absint($checkpoint_log['section_id']??0)?:null,
                    'question_id'=>absint($checkpoint_log['question_id']??0)?:null,
                    'answer'=>sanitize_key($checkpoint_log['answer']??''),
                    'status'=>$room_status,
                    'remarks'=>(string)($checkpoint_log['remarks']??''),
                    'photo_attachment_id'=>absint($checkpoint_log['photo_attachment_id']??0)?:null,
                    'completed_at'=>!empty($room_record->completed_at)?$room_record->completed_at:null,
                    'next_action_date'=>$next_action_date,
                    'updated_by'=>get_current_user_id(),
                    'updated_at'=>$now,
                ]);
            }
        }

        $pending=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$room_table} WHERE instance_id=%d AND LOWER(TRIM(COALESCE(status,'pending'))) NOT IN ('completed','complete','1')",
            $instance_id
        ));
        $wpdb->update(
            $wpdb->prefix.'hrim_periodic_task_instances',
            $pending
                ?['status'=>'in_progress','completed_at'=>null,'completed_by'=>null,'updated_at'=>$now]
                :['status'=>'completed','completed_at'=>$now,'completed_by'=>get_current_user_id(),'updated_at'=>$now],
            ['id'=>$instance_id]
        );

        wp_safe_redirect(add_query_arg(['instance_id'=>$instance_id,'periodic_updated'=>'1'],$this->page_url('hotel-inspection-schedule-tasks-in-progress')));
        exit;
    }

    public function periodic_update_log_shortcode(){
        if(!$this->can_manage() && !current_user_can('hrim_perform_inspections')){
            return $this->shell('Schedule Tasks History','<div class="hrim-alert error">You do not have access.</div>','periodic_update_log');
        }
        global $wpdb;
        if(!class_exists('HRIM_Activator')) require_once HRIM_DIR.'includes/class-hrim-activator.php';
        HRIM_Activator::create_tables();

        $rows=$wpdb->get_results("SELECT l.*,t.task_name,r.room_no,c.name category_name,s.name section_name,q.question,u.display_name updated_by_name
            FROM {$wpdb->prefix}hrim_periodic_task_update_log l
            LEFT JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=l.task_id
            LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=l.room_id
            LEFT JOIN {$wpdb->prefix}hrim_room_categories c ON c.id=l.category_id
            LEFT JOIN {$wpdb->prefix}hrim_sections s ON s.id=l.section_id
            LEFT JOIN {$wpdb->prefix}hrim_questions q ON q.id=l.question_id
            LEFT JOIN {$wpdb->users} u ON u.ID=l.updated_by
            ORDER BY l.updated_at DESC,l.id DESC LIMIT 2000");

        ob_start();?>
        <div class="hrim-panel hrim-schedule-history-list-panel">
            <div class="hrim-table-tools hrim-schedule-history-tools">
                <input type="search" class="hrim-table-search" placeholder="">
                <?php echo $this->excel_button('periodic-task-update-log'); ?>
            </div>
            <div class="hrim-table-wrap">
                <table class="hrim-table hrim-exportable">
                    <thead><tr>
                        <th>Sl.</th><th>Update Date</th><th>Task</th><th>Room Category</th><th>Room</th><th>Section</th><th>Checkpoint</th><th>Answer</th><th>Status</th><th>Remarks</th><th>Photo</th><th>Updated By</th><th>Next Action Date</th><th class="hrim-no-export">Action</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach($rows as $i=>$row):
                        $photo_url=!empty($row->photo_attachment_id)?wp_get_attachment_url(absint($row->photo_attachment_id)):'';
                    ?>
                        <tr>
                            <td><?php echo $i+1;?></td>
                            <td><?php echo esc_html($this->ist_date('d/m/y H:i',$row->updated_at));?></td>
                            <td><?php echo esc_html($row->task_name?:'—');?></td>
                            <td><?php echo esc_html($row->category_name?:'—');?></td>
                            <td><?php echo esc_html($row->room_no?:'—');?></td>
                            <td><?php echo esc_html($row->section_name?:'—');?></td>
                            <td><?php echo esc_html($row->question?:'—');?></td>
                            <td><?php $answer=strtolower(trim((string)($row->answer??''))); ?><span class="hrim-status <?php echo $answer==='no'?'overdue':'completed';?>"><?php echo $answer?esc_html(ucfirst($answer)):'—';?></span></td>
                            <td><span class="hrim-status <?php echo esc_attr($row->status);?>"><?php echo esc_html(ucfirst($row->status));?></span></td>
                            <td><?php echo trim((string)$row->remarks)!==''?esc_html($row->remarks):'—';?></td>
                            <td><?php echo $photo_url?'<a href="'.esc_url($photo_url).'" target="_blank" rel="noopener">Open</a>':'—';?></td>
                            <td><?php echo esc_html($row->updated_by_name?:'—');?></td>
                            <td><?php echo !empty($row->next_action_date)?esc_html($this->ist_date('d/m/y',$row->next_action_date)):'—';?></td>
                            <td class="hrim-no-export"><?php if($this->can_manage()): ?><div class="hrim-periodic-actions"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" onsubmit="return confirm('Delete this history update row?');"><?php wp_nonce_field('hrim_delete_periodic_update_log');?><input type="hidden" name="action" value="hrim_delete_periodic_update_log"><input type="hidden" name="id" value="<?php echo esc_attr($row->id);?>"><button class="hrim-action delete" type="submit" title="Delete" aria-label="Delete"><span class="dashicons dashicons-trash"></span></button></form></div><?php else: ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; if(!$rows):?>
                        <tr><td colspan="14">No periodic task updates found.</td></tr>
                    <?php endif;?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php return $this->shell('Schedule Tasks History',ob_get_clean(),'periodic_update_log');
    }

    public function history_shortcode(){
        global $wpdb;
        [$from,$to]=$this->filter_dates();
        $floor_id=absint($_GET['floor_id']??0);
        $room_id=absint($_GET['room_id']??0);
        $inspector_id=absint($_GET['inspector_id']??0);
        $where=["i.status<>'in_progress'"]; $args=[];
        if(!$this->can_manage()){ $where[]='i.inspector_id=%d'; $args[]=get_current_user_id(); }
        elseif($inspector_id){ $where[]='i.inspector_id=%d'; $args[]=$inspector_id; }
        if($floor_id){ $where[]='r.floor_id=%d'; $args[]=$floor_id; }
        if($room_id){ $where[]='i.room_id=%d'; $args[]=$room_id; }
        if($from){ $where[]='DATE(i.submitted_at)>=%s'; $args[]=$from; }
        if($to){ $where[]='DATE(i.submitted_at)<=%s'; $args[]=$to; }
        $sql="SELECT i.*,r.room_no,f.name floor_name,h.name hotel_name,u.display_name inspector FROM {$wpdb->prefix}hrim_inspections i LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=i.room_id LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=r.floor_id LEFT JOIN {$wpdb->prefix}hrim_hotels h ON h.id=i.hotel_id LEFT JOIN {$wpdb->users} u ON u.ID=i.inspector_id WHERE ".implode(' AND ',$where)." ORDER BY i.id DESC";
        if($args) $sql=$wpdb->prepare($sql,$args);
        $rows=$wpdb->get_results($sql);
        $floors=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_floors WHERE status='active' ORDER BY sort_order,name");
        if($floor_id){
            $rooms=$wpdb->get_results($wpdb->prepare("SELECT id,room_no FROM {$wpdb->prefix}hrim_rooms WHERE status='active' AND floor_id=%d ORDER BY room_no",$floor_id));
        } else {
            $rooms=$wpdb->get_results("SELECT id,room_no FROM {$wpdb->prefix}hrim_rooms WHERE status='active' ORDER BY room_no");
        }
        $inspectors=$this->can_manage()?$this->active_app_users(['administrator','hrim_manager','hrim_inspector']):[];
        ob_start(); ?>
        <div class="hrim-panel hrim-no-print"><form class="hrim-filter" method="get"><label>From Date<input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label><label>To Date<input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label><label>Floor<select name="floor_id"><option value="">All Floors</option><?php foreach($floors as $floor): ?><option value="<?php echo esc_attr($floor->id); ?>" <?php selected($floor_id,$floor->id); ?>><?php echo esc_html($floor->name); ?></option><?php endforeach; ?></select></label><label>Room<select name="room_id"><option value="">All Rooms</option><?php foreach($rooms as $room): ?><option value="<?php echo esc_attr($room->id); ?>" <?php selected($room_id,$room->id); ?>><?php echo esc_html($room->room_no); ?></option><?php endforeach; ?></select></label><?php if($this->can_manage()): ?><label>Inspector<select name="inspector_id"><option value="">All Inspectors</option><?php foreach($inspectors as $inspector): ?><option value="<?php echo esc_attr($inspector->ID); ?>" <?php selected($inspector_id,$inspector->ID); ?>><?php echo esc_html($inspector->display_name); ?></option><?php endforeach; ?></select></label><?php endif; ?><label>Search<input type="search" class="hrim-table-search" placeholder=""></label><a class="hrim-secondary hrim-clear-filter" href="<?php echo esc_url($this->page_url('hotel-inspection-history')); ?>">Clear</a><?php echo $this->excel_button('inspection-history'); ?></form></div>
        <div class="hrim-panel"><div class="hrim-panel-head"><div><small><?php echo count($rows); ?> records</small></div></div><?php echo $this->inspection_table($rows); ?></div><?php return $this->shell('Inspection History',ob_get_clean(),'history');
    }

    private function get_issue_rows($where='1=1',$args=[],$limit=0){
        global $wpdb;
        [$department_where,$department_args]=$this->department_scope_sql('x');
        $where="({$where}) AND ({$department_where})";
        $args=array_merge((array)$args,$department_args);
        $sql="SELECT x.*,i.attachment_ids inspection_attachment_ids,r.room_no,f.name floor_name,h.name hotel_name,se.name section_name,d.name department_name,u.display_name assigned_name,ru.display_name reported_name FROM {$wpdb->prefix}hrim_issues x LEFT JOIN {$wpdb->prefix}hrim_inspections i ON i.id=x.inspection_id LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=COALESCE(NULLIF(x.room_id,0),i.room_id) LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=COALESCE(x.floor_id,r.floor_id) LEFT JOIN {$wpdb->prefix}hrim_hotels h ON h.id=COALESCE(x.hotel_id,r.hotel_id) LEFT JOIN {$wpdb->prefix}hrim_sections se ON se.id=x.section_id LEFT JOIN {$wpdb->prefix}hrim_departments d ON d.id=x.department_id LEFT JOIN {$wpdb->users} u ON u.ID=x.assigned_to LEFT JOIN {$wpdb->users} ru ON ru.ID=x.reported_by WHERE {$where} ORDER BY FIELD(x.status,'open','assigned','in_progress','reopened','resolved','verified','closed'),x.id DESC";
        if($limit>0) $sql.=' LIMIT '.absint($limit);
        if($args) $sql=$wpdb->prepare($sql,$args);
        return $wpdb->get_results($sql);
    }

    private function normalize_attachment_ids($raw){
        if(empty($raw)) return [];
        $values=[];
        if(is_array($raw)) $values=$raw;
        else {
            $raw=trim((string)$raw);
            $decoded=json_decode($raw,true);
            if(is_array($decoded)) $values=$decoded;
            else {
                preg_match_all('/\d+/', $raw, $m);
                $values=$m[0]??[];
            }
        }
        return array_values(array_unique(array_filter(array_map('absint',$values))));
    }

    private function attachment_payload($ids,$context=[]){
        $items=[];
        foreach($this->normalize_attachment_ids($ids) as $id){
            $url=wp_get_attachment_url($id);
            if(!$url) continue;
            $items[]=[
                'id'=>$id,
                'url'=>$url,
                'title'=>get_the_title($id)?:basename(parse_url($url,PHP_URL_PATH)),
                'mime'=>get_post_mime_type($id)?:'',
                'context'=>$context,
            ];
        }
        return $items;
    }

    private function original_issue_documents($row){
        $ids=[];
        if(is_object($row)){
            $ids=array_merge($ids,$this->normalize_attachment_ids($row->attachment_ids??''));
            $ids=array_merge($ids,$this->normalize_attachment_ids($row->inspection_attachment_ids??''));
        }
        return $this->attachment_payload(array_values(array_unique(array_filter(array_map('absint',$ids)))));
    }

    private function issue_update_documents($issue_id){
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT u.*,usr.display_name updated_name FROM {$wpdb->prefix}hrim_issue_updates u LEFT JOIN {$wpdb->users} usr ON usr.ID=u.updated_by WHERE u.issue_id=%d AND u.attachment_ids IS NOT NULL AND u.attachment_ids<>'' ORDER BY u.update_date,u.id",$issue_id));
        $items=[];
        foreach($rows as $row){
            $context=[
                'date'=>$this->ist_date('d/m/y, h:i A',$row->update_date),
                'status'=>ucwords(str_replace('_',' ',$row->status?:'')),
                'remarks'=>$row->remarks?:'',
                'updated_by'=>$row->updated_name?:'System',
            ];
            $items=array_merge($items,$this->attachment_payload($row->attachment_ids,$context));
        }
        return $items;
    }

    private function document_button($items,$label='Open'){
        if(empty($items)) return '<span class="hrim-muted">—</span>';
        return '<button type="button" class="hrim-document-open" data-documents="'.esc_attr(wp_json_encode(array_values($items))).'">'.esc_html($label).'</button>';
    }

    private function issue_documents_modal(){
        return '<div class="hrim-modal" id="hrimDocumentModal"><div class="hrim-modal-card hrim-modal-wide"><button class="hrim-modal-close" type="button">×</button><h2 id="hrimDocumentModalTitle">Photos</h2><div id="hrimDocumentModalBody" class="hrim-document-grid"></div></div></div>';
    }

    private function dashboard_issue_table($rows,$mode='pending',$exportable=false,$show_documents=false,$show_update_documents=false){
        $single_property=$this->single_active_property();
        $is_resolved=($mode==='resolved');
        $show_assigned=($mode!=='pending_assign');
        $show_due=($mode!=='pending_assign');
        $show_status=false; // Dashboard summary tables do not need status.
        ob_start(); ?>
        <div class="hrim-table-wrap"><table class="hrim-table <?php echo $exportable?'hrim-exportable':''; ?>"><thead><tr><th>Sl.</th><th>Date</th><?php if(!$single_property): ?><th>Hotel</th><?php endif; ?><th>Floor</th><th>Room</th><th>Section</th><th class="hrim-col-checkpoint">Checkpoint</th><th class="hrim-col-issue">Issue</th><?php if($show_assigned): ?><th>Assigned To</th><?php endif; ?><?php if($show_due): ?><th><?php echo $is_resolved?'Resolved Date':'Due Date'; ?></th><?php endif; ?><?php if($show_status): ?><th>Status</th><?php endif; ?><?php if($show_documents): ?><th>Photo</th><?php endif; ?><?php if($show_update_documents): ?><th>Update Photo</th><?php endif; ?></tr></thead><tbody>
        <?php
        $base_cols=7+(!$single_property?1:0)+($show_assigned?1:0)+($show_due?1:0)+($show_status?1:0);
        $extra_cols=($show_documents?1:0)+($show_update_documents?1:0);
        if(!$rows): ?><tr><td colspan="<?php echo $base_cols+$extra_cols; ?>" class="hrim-empty">No issues found.</td></tr><?php endif;
        $sl=0;
        foreach($rows as $r):
            $original_docs=$show_documents?$this->original_issue_documents($r):[];
            $update_docs=$show_update_documents?$this->issue_update_documents($r->id):[];
        ?><tr><td><?php echo ++$sl; ?></td><td><?php echo !empty($r->created_at)?esc_html($this->ist_date('d/m/y',$r->created_at)):'—'; ?></td><?php if(!$single_property): ?><td><?php echo esc_html($r->hotel_name?:'—'); ?></td><?php endif; ?><td><?php echo esc_html($r->floor_name?:'—'); ?></td><td><?php echo esc_html($r->room_no?:'—'); ?></td><td><?php echo esc_html($r->section_name?:'General'); ?></td><td class="hrim-col-checkpoint"><strong><?php echo esc_html($r->title?:'—'); ?></strong></td><td class="hrim-col-issue"><?php echo trim((string)$r->description)!==''?nl2br(esc_html($r->description)):'<span class="hrim-muted">—</span>'; ?></td><?php if($show_assigned): ?><td><?php echo esc_html($r->assigned_name?:'Unassigned'); ?></td><?php endif; ?><?php if($show_due): ?><td><?php if($is_resolved){ echo $r->resolved_at?esc_html($this->ist_date('d/m/y',$r->resolved_at)):'—'; } else { echo $r->due_date?esc_html($this->ist_date('d/m/y',$r->due_date)):'—'; } ?></td><?php endif; ?><?php if($show_status): ?><td><span class="hrim-badge <?php echo esc_attr($r->status==='resolved'||$r->status==='verified'||$r->status==='closed'?'success':''); ?>"><?php echo esc_html(ucwords(str_replace('_',' ',$r->status))); ?></span></td><?php endif; ?><?php if($show_documents): ?><td data-export-value="<?php echo $original_docs?'Yes':'No'; ?>"><?php echo $this->document_button($original_docs); ?></td><?php endif; ?><?php if($show_update_documents): ?><td data-export-value="<?php echo $update_docs?'Yes':'No'; ?>"><?php echo $this->document_button($update_docs); ?></td><?php endif; ?></tr><?php endforeach; ?>
        </tbody></table></div><?php return ob_get_clean();
    }

    private function issue_action_buttons($r){
        ob_start(); ?><div class="hrim-actions">
          <button type="button" class="hrim-icon-btn hrim-action-assign hrim-assign-issue" data-id="<?php echo esc_attr($r->id); ?>" data-assigned="<?php echo esc_attr($r->assigned_to); ?>" data-due="<?php echo esc_attr($r->due_date); ?>" title="Assign Issue"><span class="dashicons dashicons-admin-users"></span></button>
          <button type="button" class="hrim-icon-btn hrim-action-edit hrim-edit-issue" data-id="<?php echo esc_attr($r->id); ?>" data-title="<?php echo esc_attr($r->title); ?>" data-description="<?php echo esc_attr($r->description); ?>" data-due="<?php echo esc_attr($r->due_date); ?>" title="Edit Issue"><span class="dashicons dashicons-edit"></span></button>
          <button type="button" class="hrim-icon-btn hrim-action-progress hrim-progress-issue" data-id="<?php echo esc_attr($r->id); ?>" data-status="<?php echo esc_attr($r->status); ?>" data-title="<?php echo esc_attr($r->title); ?>" data-issue="<?php echo esc_attr($r->description); ?>" data-location="<?php echo esc_attr(trim(($this->single_active_property()?'':(($r->hotel_name?:'').' / ')).($r->floor_name?:'').' / '.($r->room_no?:''),' /')); ?>" data-resolution="<?php echo esc_attr($r->resolution_remarks); ?>" title="Update Progress"><span class="dashicons dashicons-yes-alt"></span></button>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this issue?');"><input type="hidden" name="action" value="hrim_delete_issue"><input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>"><?php wp_nonce_field('hrim_delete_issue'); ?><button class="hrim-icon-btn hrim-action-delete hrim-delete" title="Delete"><span class="dashicons dashicons-trash"></span></button></form>
        </div><?php return ob_get_clean();
    }


    private function issue_filter_context(){
        global $wpdb;
        $floor_id=absint($_GET['floor_id']??0);
        $room_id=absint($_GET['room_id']??0);
        $section_id=absint($_GET['section_id']??0);
        $floors=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_floors WHERE status='active' ORDER BY sort_order,name");
        $room_sql="SELECT id,floor_id,room_no FROM {$wpdb->prefix}hrim_rooms WHERE status='active'";
        if($floor_id) $room_sql.=$wpdb->prepare(' AND floor_id=%d',$floor_id);
        $room_sql.=' ORDER BY room_no';
        $rooms=$wpdb->get_results($room_sql);
        $departments=$this->active_departments();
        $sections=$wpdb->get_results("SELECT id,name,department_id FROM {$wpdb->prefix}hrim_sections WHERE status='active' ORDER BY sort_order,name");
        return compact('floor_id','room_id','section_id','floors','rooms','sections');
    }

    private function apply_issue_location_filters(&$where,&$args,$filters){
        if(!empty($filters['floor_id'])){ $where.=' AND COALESCE(x.floor_id,r.floor_id)=%d'; $args[]=$filters['floor_id']; }
        if(!empty($filters['room_id'])){ $where.=' AND COALESCE(NULLIF(x.room_id,0),i.room_id)=%d'; $args[]=$filters['room_id']; }
        if(!empty($filters['section_id'])){ $where.=' AND x.section_id=%d'; $args[]=$filters['section_id']; }
    }

    private function issue_filter_selects($filters){
        ob_start(); ?>
        <label>Floor<select name="floor_id"><option value="">All Floors</option><?php foreach($filters['floors'] as $floor): ?><option value="<?php echo esc_attr($floor->id); ?>" <?php selected($filters['floor_id'],$floor->id); ?>><?php echo esc_html($floor->name); ?></option><?php endforeach; ?></select></label>
        <label>Room<select name="room_id"><option value="">All Rooms</option><?php foreach($filters['rooms'] as $room): ?><option value="<?php echo esc_attr($room->id); ?>" <?php selected($filters['room_id'],$room->id); ?>><?php echo esc_html($room->room_no); ?></option><?php endforeach; ?></select></label>
        <label>Section<select name="section_id"><option value="">All Sections</option><?php foreach($filters['sections'] as $section): ?><option value="<?php echo esc_attr($section->id); ?>" <?php selected($filters['section_id'],$section->id); ?>><?php echo esc_html($section->name); ?></option><?php endforeach; ?></select></label>
        <?php return ob_get_clean();
    }

    private function filtered_issue_view($title,$active,$where,$args=[],$empty='No issues found.') {
        if(!$this->can_issues()&&!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>',$active);
        [$from,$to]=$this->filter_dates();
        $issue_filters=$this->issue_filter_context();
        if($from){ $where.=' AND DATE(x.created_at)>=%s'; $args[]=$from; }
        if($to){ $where.=' AND DATE(x.created_at)<=%s'; $args[]=$to; }
        $this->apply_issue_location_filters($where,$args,$issue_filters);
        $rows=$this->get_issue_rows($where,$args,0);
        $single_property=$this->single_active_property();
        $users=$this->active_app_users(['administrator','hrim_owner','hrim_manager','hrim_supervisor','hrim_worker','hrim_inspector','hrim_housekeeping']);
        ob_start(); ?>
        <div class="hrim-panel hrim-no-print"><form class="hrim-filter" method="get"><?php if(!empty($_GET['delayed'])): ?><input type="hidden" name="delayed" value="1"><?php endif; ?><label>From Date<input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label><label>To Date<input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label><?php echo $this->issue_filter_selects($issue_filters); ?><label>Search<input type="search" class="hrim-table-search" placeholder=""></label><a class="hrim-secondary hrim-clear-filter" href="<?php echo esc_url(!empty($_GET['delayed'])?add_query_arg('delayed','1',$this->page_url('hotel-inspection-pending-issues')):$this->page_url($active==='pending_assign'?'hotel-inspection-pending-assign-issues':'hotel-inspection-pending-issues')); ?>">Clear</a><?php echo $this->excel_button(sanitize_title($title)); ?></form></div>
        <div class="hrim-panel"><div class="hrim-panel-head"><div><small><?php echo count($rows); ?> issues</small></div></div>
        <div class="hrim-table-wrap"><table class="hrim-table hrim-exportable"><thead><tr><th>Sl.</th><th>Date</th><?php if(!$single_property): ?><th>Hotel</th><?php endif; ?><th>Floor</th><th>Room</th><th>Section</th><th class="hrim-col-checkpoint">Checkpoint</th><th class="hrim-col-issue">Issue</th><?php if($active!=='pending_assign'): ?><th>Assigned To</th><th>Due Date</th><?php if($active!=='pending_issues'): ?><th>Status</th><?php endif; ?><?php endif; ?><th>Photo</th><th class="hrim-no-export">Actions</th></tr></thead><tbody>
        <?php if(!$rows): ?><tr><td colspan="<?php echo $active==='pending_assign'?($single_property?9:10):($active==='pending_issues'?($single_property?11:12):($single_property?12:13)); ?>" class="hrim-empty"><?php echo esc_html($empty); ?></td></tr><?php endif; ?>
        <?php $sl=0; foreach($rows as $r): ?><tr><td><?php echo ++$sl; ?></td><td><?php echo !empty($r->created_at)?esc_html($this->ist_date('d/m/y',$r->created_at)):'—'; ?></td><?php if(!$single_property): ?><td><?php echo esc_html($r->hotel_name?:'—'); ?></td><?php endif; ?><td><?php echo esc_html($r->floor_name?:'—'); ?></td><td><?php echo esc_html($r->room_no?:'—'); ?></td><td><?php echo esc_html($r->section_name?:'General'); ?></td><td class="hrim-col-checkpoint"><strong><?php echo esc_html($r->title?:'—'); ?></strong></td><td class="hrim-col-issue"><?php echo trim((string)$r->description)!==''?nl2br(esc_html($r->description)):'<span class="hrim-muted">—</span>'; ?></td><?php if($active!=='pending_assign'): ?><td><?php echo esc_html($r->assigned_name?:'Unassigned'); ?></td><td><?php echo $r->due_date?esc_html($this->ist_date('d/m/y',$r->due_date)):'—'; ?></td><?php if($active!=='pending_issues'): ?><td><span class="hrim-badge"><?php echo esc_html(ucwords(str_replace('_',' ',$r->status))); ?></span></td><?php endif; ?><?php endif; ?><?php $original_docs=$this->original_issue_documents($r); ?><td data-export-value="<?php echo $original_docs?'Yes':'No'; ?>"><?php echo $this->document_button($original_docs); ?></td><td class="hrim-no-export"><?php
            if($active==='pending_assign'){
                ?><div class="hrim-actions"><button type="button" class="hrim-icon-btn hrim-action-assign hrim-assign-issue" data-id="<?php echo esc_attr($r->id); ?>" data-assigned="<?php echo esc_attr($r->assigned_to); ?>" data-due="<?php echo esc_attr($r->due_date); ?>" title="Assign Issue"><span class="dashicons dashicons-admin-users"></span></button></div><?php
            } elseif($active==='pending_issues'){
                ?><div class="hrim-actions"><button type="button" class="hrim-icon-btn hrim-action-progress hrim-progress-issue" data-id="<?php echo esc_attr($r->id); ?>" data-status="<?php echo esc_attr($r->status); ?>" data-title="<?php echo esc_attr($r->title); ?>" data-issue="<?php echo esc_attr($r->description); ?>" data-location="<?php echo esc_attr(trim(($this->single_active_property()?'':(($r->hotel_name?:'').' / ')).($r->floor_name?:'').' / '.($r->room_no?:''),' /')); ?>" data-resolution="<?php echo esc_attr($r->resolution_remarks); ?>" title="Update Progress"><span class="dashicons dashicons-yes-alt"></span></button></div><?php
            } else {
                echo $this->issue_action_buttons($r);
            }
        ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <?php
        if($active==='pending_assign'){
            echo $this->assign_issue_modal($users);
        } elseif($active==='pending_issues'){
            echo $this->progress_issue_modal();
        } else {
            echo $this->assign_issue_modal($users); echo $this->edit_issue_modal(); echo $this->progress_issue_modal();
        }
        echo $this->issue_documents_modal();
        return $this->shell($title,ob_get_clean(),$active);
    }

    public function pending_assign_issues_shortcode(){
        return $this->filtered_issue_view('Pending Assign Issue','pending_assign',"(x.assigned_to IS NULL OR x.assigned_to=0) AND x.status IN ('open','reopened')");
    }
    public function pending_issues_shortcode(){
        if(!empty($_GET['delayed'])){
            $today_ist=$this->ist_date('Y-m-d');
            return $this->filtered_issue_view('Delayed Issues','pending_issues',"x.status NOT IN ('resolved','verified','closed') AND x.due_date IS NOT NULL AND x.due_date<>'' AND DATE(x.due_date)<%s",[$today_ist]);
        }
        return $this->filtered_issue_view('Pending Issues','pending_issues',"x.assigned_to IS NOT NULL AND x.assigned_to>0 AND x.status IN ('assigned','in_progress','reopened','open')");
    }
    public function resolved_issues_shortcode(){
        [$from,$to]=$this->filter_dates();
        $issue_filters=$this->issue_filter_context();
        $where="x.status IN ('resolved','verified','closed')"; $args=[];
        if($from){$where.=" AND DATE(COALESCE(x.resolved_at,x.created_at))>=%s";$args[]=$from;}
        if($to){$where.=" AND DATE(COALESCE(x.resolved_at,x.created_at))<=%s";$args[]=$to;}
        $this->apply_issue_location_filters($where,$args,$issue_filters);
        if(!$this->can_issues()&&!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','resolved_issues');
        $rows=$this->get_issue_rows($where,$args,0);
        ob_start(); ?><div class="hrim-panel hrim-no-print"><form class="hrim-filter" method="get"><label>From Date<input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label><label>To Date<input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label><?php echo $this->issue_filter_selects($issue_filters); ?><label>Search<input type="search" class="hrim-table-search" placeholder=""></label><a class="hrim-secondary hrim-clear-filter" href="<?php echo esc_url($this->page_url('hotel-inspection-resolved-issues')); ?>">Clear</a><?php echo $this->excel_button('issue-resolved'); ?></form></div><div class="hrim-panel"><div class="hrim-panel-head"><div><small><?php echo count($rows); ?> issues</small></div></div><?php echo $this->dashboard_issue_table($rows,true,true,true,true); ?></div><?php echo $this->issue_documents_modal(); return $this->shell('Issue Resolved',ob_get_clean(),'resolved_issues');
    }

    public function issues_shortcode(){
        if(!$this->can_issues()&&!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','issues');
        global $wpdb;
        $show_add_issue = isset($_GET['add_issue']);
        $open_only=!empty($_GET['open_only']);
        [$from,$to]=$this->filter_dates(); $issue_filters=$this->issue_filter_context(); $where=$open_only?"x.status NOT IN ('resolved','verified','closed')":"1=1"; $issue_args=[]; if($from){$where.=" AND DATE(x.created_at)>=%s";$issue_args[]=$from;} if($to){$where.=" AND DATE(x.created_at)<=%s";$issue_args[]=$to;} $this->apply_issue_location_filters($where,$issue_args,$issue_filters); $rows=$this->get_issue_rows($where,$issue_args,0);
        $hotels=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_hotels WHERE status='active' ORDER BY name");
        $floors=$wpdb->get_results("SELECT id,hotel_id,name FROM {$wpdb->prefix}hrim_floors WHERE status='active' ORDER BY sort_order,name");
        $rooms=$wpdb->get_results("SELECT id,hotel_id,floor_id,room_no FROM {$wpdb->prefix}hrim_rooms WHERE status='active' ORDER BY room_no");
        $single_property=$this->single_active_property();
        $sections=$wpdb->get_results("SELECT id,name,department_id FROM {$wpdb->prefix}hrim_sections WHERE status='active' ORDER BY sort_order,name");
        $departments=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}hrim_departments WHERE status='active' ORDER BY name");
        $users=$this->active_app_users(['administrator','hrim_manager','hrim_inspector','hrim_housekeeping']);
        ob_start(); ?>
        <div class="hrim-panel hrim-no-print"><form class="hrim-filter" method="get"><?php if($open_only): ?><input type="hidden" name="open_only" value="1"><?php endif; ?><label>From Date<input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label><label>To Date<input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label><?php echo $this->issue_filter_selects($issue_filters); ?><label>Search<input type="search" class="hrim-table-search" placeholder=""></label><a class="hrim-secondary hrim-clear-filter" href="<?php echo esc_url($this->page_url('hotel-inspection-issues')); ?>">Clear</a><?php echo $this->excel_button('issues'); ?></form></div><div class="hrim-panel"><div class="hrim-panel-head"><div><small><?php echo count($rows); ?> issues</small></div><div class="hrim-toolbar"><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add_issue','1',$this->page_url('hotel-inspection-issues'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add Issue</a></div></div>
        <div class="hrim-table-wrap"><table class="hrim-table hrim-exportable"><thead><tr><th>Sl.</th><th>Date</th><?php if(!$single_property): ?><th>Hotel</th><?php endif; ?><th>Floor</th><th>Room</th><th>Section</th><th class="hrim-col-checkpoint">Checkpoint</th><th class="hrim-col-issue">Issue</th><th>Assigned To</th><th>Due Date</th><th>Status</th><th>Photo</th><th>Update Photo</th><th class="hrim-no-export">Actions</th></tr></thead><tbody>
        <?php if(!$rows): ?><tr><td colspan="<?php echo $single_property?13:14; ?>" class="hrim-empty">No issues found.</td></tr><?php endif; ?>
        <?php $sl=0; foreach($rows as $r): ?><tr><td><?php echo ++$sl; ?></td><td><?php echo !empty($r->created_at)?esc_html($this->ist_date('d/m/y',$r->created_at)):'—'; ?></td><?php if(!$single_property): ?><td><?php echo esc_html($r->hotel_name?:'—'); ?></td><?php endif; ?><td><?php echo esc_html($r->floor_name?:'—'); ?></td><td><?php echo esc_html($r->room_no?:'—'); ?></td><td><?php echo esc_html($r->section_name ?: 'General'); ?></td><td class="hrim-col-checkpoint"><strong><?php echo esc_html($r->title); ?></strong></td><td class="hrim-col-issue"><?php echo $r->description !== '' && $r->description !== null ? nl2br(esc_html($r->description)) : '<span class="hrim-muted">—</span>'; ?></td><td><?php echo esc_html($r->assigned_name ?: 'Unassigned'); ?></td><td><?php echo $r->due_date ? esc_html($this->ist_date('d/m/y',$r->due_date)) : '—'; ?></td><td><?php echo esc_html(ucwords(str_replace('_',' ',$r->status))); ?></td><?php $original_docs=$this->original_issue_documents($r); $update_docs=$this->issue_update_documents($r->id); ?><td data-export-value="<?php echo $original_docs?'Yes':'No'; ?>"><?php echo $this->document_button($original_docs); ?></td><td data-export-value="<?php echo $update_docs?'Yes':'No'; ?>"><?php echo $this->document_button($update_docs); ?></td><td class="hrim-no-export"><div class="hrim-actions"><button type="button" class="hrim-icon-btn hrim-action-assign hrim-assign-issue" data-id="<?php echo esc_attr($r->id); ?>" data-assigned="<?php echo esc_attr($r->assigned_to); ?>" data-due="<?php echo esc_attr($r->due_date); ?>" title="Assign Issue"><span class="dashicons dashicons-admin-users"></span></button><button type="button" class="hrim-icon-btn hrim-action-edit hrim-edit-issue" data-id="<?php echo esc_attr($r->id); ?>" data-title="<?php echo esc_attr($r->title); ?>" data-description="<?php echo esc_attr($r->description); ?>" data-due="<?php echo esc_attr($r->due_date); ?>" title="Edit Issue"><span class="dashicons dashicons-edit"></span></button><button type="button" class="hrim-icon-btn hrim-action-progress hrim-progress-issue" data-id="<?php echo esc_attr($r->id); ?>" data-status="<?php echo esc_attr($r->status); ?>" data-title="<?php echo esc_attr($r->title); ?>" data-issue="<?php echo esc_attr($r->description); ?>" data-location="<?php echo esc_attr(trim(($this->single_active_property()?'':(($r->hotel_name?:'').' / ')).($r->floor_name?:'').' / '.($r->room_no?:''),' /')); ?>" data-resolution="<?php echo esc_attr($r->resolution_remarks); ?>" title="Update Progress"><span class="dashicons dashicons-yes-alt"></span></button><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this issue?');"><input type="hidden" name="action" value="hrim_delete_issue"><input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>"><?php wp_nonce_field('hrim_delete_issue'); ?><button class="hrim-icon-btn hrim-action-delete hrim-delete" title="Delete"><span class="dashicons dashicons-trash"></span></button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div>
        <?php echo $this->add_issue_modal($hotels,$floors,$rooms,$departments,$sections,$users,$show_add_issue); echo $this->assign_issue_modal($users); echo $this->edit_issue_modal(); echo $this->progress_issue_modal(); echo $this->issue_documents_modal(); return $this->shell('Issue Management',ob_get_clean(),'issues');
    }

    private function normalize_mobile_number($value){
        return preg_replace('/\D+/','',(string)$value);
    }
    private function whatsapp_settings(){
        return [
            'enabled'=>get_option('hrim_whatsapp_enabled','0')==='1',
            'graph_version'=>sanitize_text_field(get_option('hrim_whatsapp_graph_version','v25.0'))?:'v25.0',
            'phone_number_id'=>sanitize_text_field(get_option('hrim_whatsapp_phone_number_id','')),
            'waba_id'=>sanitize_text_field(get_option('hrim_whatsapp_waba_id','')),
            'access_token'=>(string)get_option('hrim_whatsapp_access_token',''),
            'template_name'=>sanitize_key(get_option('hrim_whatsapp_issue_template','roomscore_new_issue')),
            'template_without_photo'=>sanitize_key(get_option('hrim_whatsapp_issue_template_without_photo','roomscore_new_issue_without_photo')),
            'summary_template'=>sanitize_key(get_option('hrim_whatsapp_summary_template','roomscore_notification_summary')),
            'language_code'=>sanitize_text_field(get_option('hrim_whatsapp_language_code','en_US'))?:'en_US',
            'test_mobile'=>$this->normalize_mobile_number(get_option('hrim_whatsapp_test_mobile','')),
        ];
    }
    private function send_whatsapp_template($mobile,$parameters,$settings=null,$image_url=''){
        $settings=is_array($settings)?$settings:$this->whatsapp_settings();
        $mobile=$this->normalize_mobile_number($mobile);
        if(!$settings['enabled']) return new WP_Error('whatsapp_disabled','WhatsApp notifications are disabled.');
        if(strlen($mobile)<10 || strlen($mobile)>15) return new WP_Error('invalid_mobile','Recipient mobile number is invalid.');
        if(empty($settings['phone_number_id'])||empty($settings['access_token'])||empty($settings['template_name'])) return new WP_Error('whatsapp_incomplete','Meta / WhatsApp Business Settings are incomplete.');
        $components=[];
        if($image_url && filter_var($image_url,FILTER_VALIDATE_URL)!==false){
            $components[]=['type'=>'header','parameters'=>[['type'=>'image','image'=>['link'=>$image_url]]]];
        }
        if($parameters){
            $body=[];
            foreach($parameters as $value)$body[]=['type'=>'text','text'=>mb_substr(wp_strip_all_tags((string)$value),0,1024)];
            $components[]=['type'=>'body','parameters'=>$body];
        }
        $endpoint='https://graph.facebook.com/'.rawurlencode($settings['graph_version']).'/'.rawurlencode($settings['phone_number_id']).'/messages';
        $response=wp_remote_post($endpoint,[
            'timeout'=>20,
            'headers'=>['Authorization'=>'Bearer '.$settings['access_token'],'Content-Type'=>'application/json'],
            'body'=>wp_json_encode(['messaging_product'=>'whatsapp','to'=>$mobile,'type'=>'template','template'=>['name'=>$settings['template_name'],'language'=>['code'=>$settings['language_code']],'components'=>$components]]),
        ]);
        if(is_wp_error($response)) return $response;
        $code=(int)wp_remote_retrieve_response_code($response);
        $body=json_decode(wp_remote_retrieve_body($response),true);
        if($code<200||$code>=300){
            $message=$body['error']['message']??('Meta API returned HTTP '.$code.'.');
            return new WP_Error('meta_api_error',$message,$body);
        }
        return $body;
    }
    private function telegram_settings(){
        return [
            'enabled'=>get_option('hrim_telegram_enabled','0')==='1',
            'bot_token'=>(string)get_option('hrim_telegram_bot_token',''),
            'test_chat_id'=>sanitize_text_field(get_option('hrim_telegram_test_chat_id','')),
        ];
    }
    private function notification_rules(){
        $defaults=[
            'pending_issues'=>['enabled'=>0,'interval_minutes'=>1440,'head'=>1,'telegram'=>1],
            'upcoming_periodic'=>['enabled'=>0,'interval_minutes'=>1440,'days_before'=>3,'head'=>1,'telegram'=>1],
            'scheduled_not_started'=>['enabled'=>0,'interval_minutes'=>1440,'head'=>1,'telegram'=>1],
            'scheduled_in_progress'=>['enabled'=>0,'interval_minutes'=>1440,'head'=>1,'telegram'=>1],
        ];
        $saved=get_option('hrim_notification_rules',[]);
        foreach($defaults as $key=>$rule) if(!empty($saved[$key])&&is_array($saved[$key])) $defaults[$key]=array_merge($rule,$saved[$key]);
        return $defaults;
    }
    private function send_telegram_message($chat_id,$message,$image_url=''){
        $settings=$this->telegram_settings();
        if(!$settings['enabled']) return new WP_Error('telegram_disabled','Telegram notifications are disabled.');
        if(!$chat_id||!$settings['bot_token']) return new WP_Error('telegram_incomplete','Telegram Bot Token or Chat ID is missing.');
        $has_image=filter_var($image_url,FILTER_VALIDATE_URL)!==false;
        $method=$has_image?'sendPhoto':'sendMessage';
        $url='https://api.telegram.org/bot'.rawurlencode($settings['bot_token']).'/'.$method;
        $body=$has_image ? ['chat_id'=>$chat_id,'photo'=>$image_url,'caption'=>mb_substr($message,0,1024)] : ['chat_id'=>$chat_id,'text'=>$message,'disable_web_page_preview'=>'true'];
        $response=wp_remote_post($url,['timeout'=>20,'body'=>$body]);
        if(is_wp_error($response)) return $response;
        $code=(int)wp_remote_retrieve_response_code($response); $response_body=wp_remote_retrieve_body($response);
        if($code<200||$code>=300) return new WP_Error('telegram_api_error','Telegram API returned HTTP '.$code,$response_body);
        return true;
    }
    private function send_whatsapp_text_or_template($recipient,$message,$template_parameters=[]){
        $settings=$this->whatsapp_settings();
        if(!$settings['enabled']) return new WP_Error('whatsapp_disabled','WhatsApp notifications are disabled.');
        if(!$recipient) return new WP_Error('whatsapp_destination','WhatsApp destination is missing.');
        if($template_parameters) return $this->send_whatsapp_template($recipient,$template_parameters,$settings);
        if(empty($settings['phone_number_id'])||empty($settings['access_token'])) return new WP_Error('whatsapp_incomplete','Meta / WhatsApp Business Settings are incomplete.');
        $url='https://graph.facebook.com/'.$settings['graph_version'].'/'.$settings['phone_number_id'].'/messages';
        $response=wp_remote_post($url,['timeout'=>20,'headers'=>['Authorization'=>'Bearer '.$settings['access_token'],'Content-Type'=>'application/json'],'body'=>wp_json_encode(['messaging_product'=>'whatsapp','to'=>$recipient,'type'=>'text','text'=>['preview_url'=>false,'body'=>$message]])]);
        if(is_wp_error($response)) return $response;
        $code=(int)wp_remote_retrieve_response_code($response); $body=wp_remote_retrieve_body($response);
        if($code<200||$code>=300){$json=json_decode($body,true);$msg=$json['error']['message']??('Meta API returned HTTP '.$code);return new WP_Error('meta_api_error',$msg,$body);} return true;
    }
    private function send_whatsapp_image($recipient,$image_url){
        $settings=$this->whatsapp_settings();
        if(!$settings['enabled']) return new WP_Error('whatsapp_disabled','WhatsApp notifications are disabled.');
        if(!$recipient || filter_var($image_url,FILTER_VALIDATE_URL)===false) return new WP_Error('whatsapp_image_missing','WhatsApp recipient or image URL is missing.');
        if(empty($settings['phone_number_id'])||empty($settings['access_token'])) return new WP_Error('whatsapp_incomplete','Meta / WhatsApp Business Settings are incomplete.');
        $url='https://graph.facebook.com/'.$settings['graph_version'].'/'.$settings['phone_number_id'].'/messages';
        $response=wp_remote_post($url,['timeout'=>20,'headers'=>['Authorization'=>'Bearer '.$settings['access_token'],'Content-Type'=>'application/json'],'body'=>wp_json_encode(['messaging_product'=>'whatsapp','to'=>$recipient,'type'=>'image','image'=>['link'=>$image_url]])]);
        if(is_wp_error($response)) return $response;
        $code=(int)wp_remote_retrieve_response_code($response); $body=wp_remote_retrieve_body($response);
        if($code<200||$code>=300){$json=json_decode($body,true);$msg=$json['error']['message']??('Meta API returned HTTP '.$code);return new WP_Error('meta_api_error',$msg,$body);} return true;
    }
    private function issue_notification_image_url($issue_id){
        global $wpdb;
        $latest=$wpdb->get_var($wpdb->prepare("SELECT attachment_ids FROM {$wpdb->prefix}hrim_issue_updates WHERE issue_id=%d AND attachment_ids IS NOT NULL AND attachment_ids<>'' ORDER BY update_date DESC,id DESC LIMIT 1",$issue_id));
        $ids=$this->normalize_attachment_ids($latest);
        if(!$ids){
            $row=$wpdb->get_row($wpdb->prepare("SELECT attachment_ids,resolution_attachment_ids FROM {$wpdb->prefix}hrim_issues WHERE id=%d",$issue_id));
            if($row){$ids=$this->normalize_attachment_ids($row->resolution_attachment_ids);if(!$ids)$ids=$this->normalize_attachment_ids($row->attachment_ids);}
        }
        return $ids?wp_get_attachment_url((int)$ids[0]):'';
    }
    private function log_notification($event_key,$type,$department_id,$channel,$destination,$message,$result){
        global $wpdb; $ok=!is_wp_error($result);
        $wpdb->insert($wpdb->prefix.'hrim_notification_log',['event_key'=>$event_key,'notification_type'=>$type,'department_id'=>$department_id?:null,'channel'=>$channel,'destination'=>$destination,'message'=>$message,'status'=>$ok?'sent':'failed','api_response'=>$ok?'Accepted':$result->get_error_message(),'attempts'=>1,'scheduled_at'=>$this->ist_now_mysql(),'sent_at'=>$ok?$this->ist_now_mysql():null]);
        return $ok;
    }
    private function notification_already_sent($event_key,$interval_minutes=0){
        global $wpdb; if(!$event_key) return false;
        $sql="SELECT id FROM {$wpdb->prefix}hrim_notification_log WHERE event_key=%s AND status='sent'"; $args=[$event_key];
        if($interval_minutes>0){$sql.=' AND created_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)';$args[]=$interval_minutes;}
        $sql.=' LIMIT 1'; return (bool)$wpdb->get_var($wpdb->prepare($sql,$args));
    }
    private function department_destinations($department,$rule=[]){
        $dest=[];
        if(!empty($rule['head']) && !empty($department->notify_head_enabled) && !empty($department->department_head_user_id)){
            $mobile=$this->normalize_mobile_number(get_user_meta((int)$department->department_head_user_id,'hrim_mobile',true)); if($mobile)$dest[]=['channel'=>'whatsapp_head','destination'=>$mobile];
        }
        if(!empty($rule['telegram']) && !empty($department->notify_telegram_enabled) && !empty($department->telegram_chat_id)) $dest[]=['channel'=>'telegram','destination'=>$department->telegram_chat_id];
        return $dest;
    }
    private function dispatch_department_message($department,$type,$message,$event_base,$rule=[],$template_parameters=[],$image_url=''){
        $sent=0; foreach($this->department_destinations($department,$rule) as $dest){
            $event=$event_base.':'.$dest['channel']; if($this->notification_already_sent($event,(int)($rule['interval_minutes']??0))) continue;
            if($dest['channel']==='telegram'){
                $result=$this->send_telegram_message($dest['destination'],$message,$image_url);
            }elseif($dest['channel']==='whatsapp_head' && !$template_parameters){
                $ws=$this->whatsapp_settings();$ws['template_name']=$ws['summary_template'];$result=$this->send_whatsapp_template($dest['destination'],[$message],$ws);
            }else{
                $ws=$this->whatsapp_settings();
                if($image_url){
                    $ws['template_name']=$ws['template_name'];
                    $result=$this->send_whatsapp_template($dest['destination'],$template_parameters,$ws,$image_url);
                }else{
                    $ws['template_name']=$ws['template_without_photo'];
                    $result=$this->send_whatsapp_template($dest['destination'],$template_parameters,$ws);
                }
            }
            if($this->log_notification($event,$type,(int)$department->id,$dest['channel'],$dest['destination'],$message,$result))$sent++;
        } return $sent;
    }
    private function notify_department_for_issue($issue_id){
        global $wpdb;
        $issue=$wpdb->get_row($wpdb->prepare("SELECT x.*,d.name department_name,d.department_head_user_id,d.telegram_chat_id,d.notify_head_enabled,d.notify_telegram_enabled,r.room_no,f.name floor_name,s.name section_name FROM {$wpdb->prefix}hrim_issues x LEFT JOIN {$wpdb->prefix}hrim_departments d ON d.id=x.department_id LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=x.room_id LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=x.floor_id LEFT JOIN {$wpdb->prefix}hrim_sections s ON s.id=x.section_id WHERE x.id=%d",$issue_id));
        if(!$issue||!$issue->department_id)return false;
        $location=trim(($issue->floor_name?:'').' / '.($issue->room_no?:''),' /');
        $message="New Issue Created\nIssue ID: #{$issue->id}\nDepartment: ".($issue->department_name?:'—')."\nLocation: ".($location?:'—')."\nSection: ".($issue->section_name?:'—')."\nCheckpoint: ".($issue->title?:'—')."\nIssue: ".($issue->description?:'—');
        $params=['#'.$issue->id,$issue->department_name?:'—',$location?:'—',$issue->section_name?:'—',$issue->title?:'—',$issue->description?:'—'];
        return $this->dispatch_department_message($issue,'new_issue',$message,'issue:'.$issue->id,['head'=>1,'telegram'=>1,'interval_minutes'=>0],$params,$this->issue_notification_image_url($issue->id));
    }
    private function notify_department_for_issue_update($issue_id,$event_label,$details=''){
        global $wpdb;
        $issue=$wpdb->get_row($wpdb->prepare("SELECT x.*,d.name department_name,d.department_head_user_id,d.telegram_chat_id,d.notify_head_enabled,d.notify_telegram_enabled,r.room_no,f.name floor_name,s.name section_name FROM {$wpdb->prefix}hrim_issues x LEFT JOIN {$wpdb->prefix}hrim_departments d ON d.id=x.department_id LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=x.room_id LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=x.floor_id LEFT JOIN {$wpdb->prefix}hrim_sections s ON s.id=x.section_id WHERE x.id=%d",$issue_id));
        if(!$issue||!$issue->department_id)return false;
        $location=trim(($issue->floor_name?:'').' / '.($issue->room_no?:''),' /');
        $message="Issue Update\nIssue ID: #{$issue->id}\nUpdate: ".$event_label."\nDepartment: ".($issue->department_name?:'—')."\nLocation: ".($location?:'—')."\nSection: ".($issue->section_name?:'—')."\nIssue: ".($issue->title?:'—');
        if(trim((string)$details)!=='')$message.="\nDetails: ".trim((string)$details);
        $event_key='issue_update:'.$issue->id.':'.sanitize_key($event_label).':'.microtime(true);
        return $this->dispatch_department_message($issue,'issue_update',$message,$event_key,['head'=>1,'telegram'=>1,'interval_minutes'=>0],[],$this->issue_notification_image_url($issue->id));
    }
    public function run_notification_triggers(){
        if(get_option('hrim_notifications_enabled','0')!=='1') return;
        global $wpdb; $rules=$this->notification_rules(); $today=$this->ist_date('Y-m-d');
        $departments=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}hrim_departments WHERE status='active'");
        foreach($departments as $department){
            if(!empty($rules['pending_issues']['enabled'])){
                $rows=$wpdb->get_results($wpdb->prepare("SELECT x.id,x.title,x.status,r.room_no FROM {$wpdb->prefix}hrim_issues x LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=x.room_id WHERE x.department_id=%d AND x.status NOT IN ('resolved','closed','verified') ORDER BY x.due_date IS NULL,x.due_date,x.id",$department->id));
                if($rows){$assets=[];foreach($rows as $r)$assets[]=(($r->room_no?:'Asset').' — '.$r->title);$message="Pending Issues — {$department->name}\nTotal: ".count($rows)."\n".implode("\n",array_slice($assets,0,20) );$this->dispatch_department_message($department,'pending_issues',$message,'pending:'.$department->id,$rules['pending_issues']);}
            }
            if(!empty($rules['upcoming_periodic']['enabled'])){
                $days=max(0,(int)$rules['upcoming_periodic']['days_before']);
                $rows=$wpdb->get_results($wpdb->prepare("SELECT i.id,i.due_date,t.task_name FROM {$wpdb->prefix}hrim_periodic_task_instances i JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=i.task_id WHERE t.department_id=%d AND i.status='upcoming' AND i.due_date BETWEEN %s AND DATE_ADD(%s,INTERVAL %d DAY) ORDER BY i.due_date",$department->id,$today,$today,$days));
                if($rows){$lines=[];foreach($rows as $r)$lines[]=$r->task_name.' — '.$this->ist_date('d/m/Y',$r->due_date);$message="Upcoming Periodic Checks — {$department->name}\n".implode("\n",$lines );$this->dispatch_department_message($department,'upcoming_periodic',$message,'upcoming:'.$department->id,$rules['upcoming_periodic']);}
            }
            if(!empty($rules['scheduled_not_started']['enabled'])){
                $rows=$wpdb->get_results($wpdb->prepare("SELECT i.id,i.due_date,t.task_name FROM {$wpdb->prefix}hrim_periodic_task_instances i JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=i.task_id WHERE t.department_id=%d AND i.status='upcoming' AND i.due_date<=%s ORDER BY i.due_date",$department->id,$today));
                if($rows){$lines=[];foreach($rows as $r)$lines[]=$r->task_name.' — Due '.$this->ist_date('d/m/Y',$r->due_date);$message="Scheduled Tasks Not Yet Started — {$department->name}\n".implode("\n",$lines );$this->dispatch_department_message($department,'scheduled_not_started',$message,'notstarted:'.$department->id,$rules['scheduled_not_started']);}
            }
            if(!empty($rules['scheduled_in_progress']['enabled'])){
                $rows=$wpdb->get_results($wpdb->prepare("SELECT i.id,i.due_date,t.task_name FROM {$wpdb->prefix}hrim_periodic_task_instances i JOIN {$wpdb->prefix}hrim_periodic_tasks t ON t.id=i.task_id WHERE t.department_id=%d AND i.status='in_progress' ORDER BY i.due_date",$department->id));
                foreach($rows as $r){$pending=$wpdb->get_col($wpdb->prepare("SELECT a.room_no FROM {$wpdb->prefix}hrim_periodic_task_rooms pr JOIN {$wpdb->prefix}hrim_rooms a ON a.id=pr.room_id WHERE pr.instance_id=%d AND pr.status<>'completed' ORDER BY a.room_no",$r->id));if(!$pending)continue;$message="Scheduled Task In Progress — {$department->name}\nTask: {$r->task_name}\nPending Assets (".count($pending)."):\n".implode(', ',array_slice($pending,0,30))."\nDue: ".$this->ist_date('d/m/Y',$r->due_date );$this->dispatch_department_message($department,'scheduled_in_progress',$message,'inprogress:'.$r->id,$rules['scheduled_in_progress']);}
            }
        }
        update_option('hrim_notifications_last_run',$this->ist_now_mysql(),false);
    }

    public function reports_shortcode(){
        if(!$this->can_reports()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','reports');
        global $wpdb; $from=sanitize_text_field($_GET['from']??''); $to=sanitize_text_field($_GET['to']??''); $where='1=1'; $args=[]; if($from){$where.=' AND DATE(i.submitted_at)>=%s';$args[]=$from;} if($to){$where.=' AND DATE(i.submitted_at)<=%s';$args[]=$to;} $sql="SELECT i.*,r.room_no,f.name floor_name,h.name hotel_name,u.display_name inspector FROM {$wpdb->prefix}hrim_inspections i LEFT JOIN {$wpdb->prefix}hrim_rooms r ON r.id=i.room_id LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=r.floor_id LEFT JOIN {$wpdb->prefix}hrim_hotels h ON h.id=i.hotel_id LEFT JOIN {$wpdb->users} u ON u.ID=i.inspector_id WHERE $where ORDER BY i.id DESC"; if($args)$sql=$wpdb->prepare($sql,$args); $rows=$wpdb->get_results($sql);
        ob_start(); ?><div class="hrim-panel hrim-no-print"><form class="hrim-filter" method="get"><label>From<input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label><label>To<input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label><button type="button" class="hrim-secondary hrim-export-csv">Export Excel CSV</button></form></div><div class="hrim-panel"><?php echo $this->inspection_table($rows); ?></div><?php return $this->shell('Reports',ob_get_clean(),'reports');
    }


    public function departments_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','departments');
        global $wpdb;
        $edit_id=absint($_GET['edit']??0);
        $show_form=isset($_GET['add'])||$edit_id;
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_departments WHERE id=%d",$edit_id)):null;
        $department_users=get_users(['role__in'=>['hrim_owner','hrim_manager','hrim_supervisor','hrim_worker','hrim_inspector','hrim_housekeeping'],'orderby'=>'display_name','number'=>-1]);
        $rows=$wpdb->get_results("SELECT d.*,(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_sections s WHERE s.department_id=d.id) section_count,(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_user_departments ud WHERE ud.department_id=d.id) user_count FROM {$wpdb->prefix}hrim_departments d ORDER BY d.name,d.id");
        ob_start(); ?>
        <?php echo $this->master_import_feedback('departments'); ?><div class="hrim-panel hrim-master-panel"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->master_import_controls('departments'); ?><?php echo $this->excel_button('departments'); ?><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add','1',$this->page_url('hotel-inspection-departments'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add Department</a></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><th>Department</th><th>Department Head</th><th>Telegram Group</th><th>Description</th><th>Sections</th><th>Users</th><th>Actions</th></tr></thead><tbody><?php $sl=0; foreach($rows as $r): ?><tr><td><?php echo ++$sl; ?></td><td><strong><?php echo esc_html($r->name); ?></strong></td><td><?php $head=$r->department_head_user_id?get_userdata((int)$r->department_head_user_id):null; echo $head?esc_html($head->display_name):'<span class="hrim-muted">—</span>'; ?></td><td><?php echo esc_html($r->telegram_chat_id?:'—'); ?></td><td><?php echo $r->description!=='' && $r->description!==null ? nl2br(esc_html($r->description)) : '<span class="hrim-muted">—</span>'; ?></td><td><?php echo esc_html($r->section_count); ?></td><td><?php echo esc_html($r->user_count); ?></td><td><?php echo $this->action_buttons('department',$r->id,'hotel-inspection-departments'); ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="8" class="hrim-empty">No departments found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php if($show_form): ?><div class="hrim-modal open"><div class="hrim-modal-card"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-departments')); ?>">×</a><h2><?php echo $edit?'Edit Department':'Add Department'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_department"><input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>"><?php wp_nonce_field('hrim_save_department'); ?><label>Department Name<input name="name" required value="<?php echo esc_attr($edit->name??''); ?>"></label><label>Department Head<select name="department_head_user_id"><option value="">Select Department Head</option><?php foreach($department_users as $department_user): $head_mobile=$this->normalize_mobile_number(get_user_meta($department_user->ID,'hrim_mobile',true)); ?><option value="<?php echo esc_attr($department_user->ID); ?>" <?php selected($edit->department_head_user_id??0,$department_user->ID); ?>><?php echo esc_html($department_user->display_name.($head_mobile?' — '.$head_mobile:' — Mobile missing')); ?></option><?php endforeach; ?></select></label><label>Telegram Group Chat ID<input name="telegram_chat_id" value="<?php echo esc_attr($edit->telegram_chat_id??''); ?>" placeholder="Example: -1001234567890"></label><div class="hrim-grid-2"><label class="hrim-checkbox-row"><input type="checkbox" name="notify_head_enabled" value="1" <?php checked(!isset($edit->notify_head_enabled)||$edit->notify_head_enabled); ?>><span>Notify Department Head</span></label><label class="hrim-checkbox-row"><input type="checkbox" name="notify_telegram_enabled" value="1" <?php checked($edit->notify_telegram_enabled??0); ?>><span>Notify Telegram Group</span></label></div><label>Description<textarea name="description"><?php echo esc_textarea($edit->description??''); ?></textarea></label><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit?'Update':'Save'; ?></button></div></form></div></div><?php endif; ?>
        <?php echo $this->master_import_modal('departments'); return $this->shell('Departments',ob_get_clean(),'departments');
    }

    public function users_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','users');
        $edit_id=absint($_GET['edit']??0);
        $show_form=isset($_GET['add'])||$edit_id;
        $edit=$edit_id?get_userdata($edit_id):null;
        $users=get_users(['role__in'=>['administrator','hrim_owner','hrim_manager','hrim_supervisor','hrim_worker','hrim_inspector','hrim_housekeeping'],'orderby'=>'display_name','number'=>-1,'cache_results'=>false]);
        global $wpdb;
        $departments=$this->active_departments();
        $edit_departments=$edit_id?array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT department_id FROM {$wpdb->prefix}hrim_user_departments WHERE user_id=%d",$edit_id))):[];
        $view_map=$this->configurable_view_map();
        $edit_allowed_views=$edit_id?$this->get_user_allowed_view_slugs($edit_id):$this->all_view_slugs();
        ob_start(); ?>
        <?php echo $this->master_import_feedback('users'); ?><div class="hrim-panel hrim-master-panel"><div class="hrim-panel-head hrim-master-panel-head"><div class="hrim-toolbar hrim-master-toolbar"><input class="hrim-table-search" placeholder=""><?php echo $this->master_import_controls('users'); ?><?php echo $this->excel_button('users'); ?><a class="hrim-primary" href="<?php echo esc_url(add_query_arg('add','1',$this->page_url('hotel-inspection-users'))); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add User</a></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><th>Name</th><th>Username</th><th>Email</th><th>Mobile</th><th>Role</th><th>Departments</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php $sl=0; foreach($users as $u): $is_admin=in_array('administrator',$u->roles,true); $status=$this->user_is_active($u->ID)?'active':'inactive'; ?><tr><td><?php echo ++$sl; ?></td><td><?php echo esc_html($u->display_name); ?></td><td><?php echo esc_html($u->user_login); ?></td><td><?php echo esc_html($u->user_email); ?></td><td><?php echo esc_html(get_user_meta($u->ID,'hrim_mobile',true)?:'—'); ?></td><td><?php echo esc_html(ucwords(str_replace(['hrim_','_'],' ',$u->roles[0]??''))); ?></td><td><?php $ud=$wpdb->get_col($wpdb->prepare("SELECT d.name FROM {$wpdb->prefix}hrim_user_departments m INNER JOIN {$wpdb->prefix}hrim_departments d ON d.id=m.department_id WHERE m.user_id=%d ORDER BY d.sort_order,d.name",$u->ID)); echo esc_html($is_admin||in_array($u->roles[0]??'',['hrim_owner','hrim_manager'],true)?'All Departments':($ud?implode(', ',$ud):'—')); ?></td><td><?php if($is_admin): ?><span class="hrim-status-pill is-active">Active</span><?php else: ?><form class="hrim-inline hrim-user-status-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_update_user_status"><input type="hidden" name="id" value="<?php echo esc_attr($u->ID); ?>"><?php wp_nonce_field('hrim_update_user_status_'.$u->ID); ?><select name="status" class="hrim-user-status-select" onchange="this.form.submit()"><option value="active" <?php selected($status,'active'); ?>>Active</option><option value="inactive" <?php selected($status,'inactive'); ?>>Inactive</option></select></form><?php endif; ?></td><td><?php if($is_admin): ?><small>System</small><?php else: ?><?php echo $this->action_buttons('user',$u->ID,'hotel-inspection-users'); ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        <?php if($show_form): $edit_status=$edit?$this->user_is_active($edit_id):true; ?><div class="hrim-modal open"><div class="hrim-modal-card hrim-modal-wide"><a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-users')); ?>">×</a><h2><?php echo $edit?'Edit Application User':'Add Application User'; ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_user"><input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>"><?php wp_nonce_field('hrim_save_user'); ?><label>Full Name *<input name="display_name" required value="<?php echo esc_attr($edit->display_name??''); ?>"></label><label>Username *<input name="username" required <?php echo $edit?'readonly':''; ?> value="<?php echo esc_attr($edit->user_login??''); ?>"></label><label>Email *<input type="email" name="email" required value="<?php echo esc_attr($edit->user_email??''); ?>"></label><label>Mobile *<input type="tel" name="mobile" required inputmode="numeric" placeholder="919876543210" value="<?php echo esc_attr($edit?get_user_meta($edit_id,'hrim_mobile',true):''); ?>"><small>Enter country code and mobile number without spaces, for example 919876543210.</small></label><label><?php echo $edit?'New Password (leave blank to retain current)':'Password *'; ?><input type="password" name="password" minlength="8" <?php echo $edit?'':'required'; ?>></label><label>Role *<select name="role" required><option value="">Select Role</option><option value="hrim_worker" <?php selected($edit->roles[0]??'','hrim_worker'); ?>>Worker</option><option value="hrim_supervisor" <?php selected($edit->roles[0]??'','hrim_supervisor'); ?>>Supervisor</option><option value="hrim_manager" <?php selected($edit->roles[0]??'','hrim_manager'); ?>>Manager</option><option value="hrim_owner" <?php selected($edit->roles[0]??'','hrim_owner'); ?>>Owner</option></select></label><div class="hrim-user-department-field"><span class="hrim-field-label">Departments *</span><div class="hrim-multiselect" data-placeholder="Select"><button type="button" class="hrim-multiselect-toggle" aria-expanded="false"><span class="hrim-multiselect-value">Select</span><span class="hrim-multiselect-arrow">⌃</span></button><div class="hrim-multiselect-panel" hidden><input type="search" class="hrim-multiselect-search" placeholder="Search..."><div class="hrim-multiselect-tools"><button type="button" class="hrim-multiselect-all">☷ ALL</button><button type="button" class="hrim-multiselect-clear">⌫ CLEAR</button></div><div class="hrim-multiselect-options"><?php foreach($departments as $department): ?><label class="hrim-multiselect-option"><input type="checkbox" name="department_ids[]" value="<?php echo esc_attr($department->id); ?>" <?php checked(in_array((int)$department->id,$edit_departments,true)); ?>><span><?php echo esc_html($department->name); ?></span></label><?php endforeach; ?></div></div></div></div><div class="hrim-user-view-permissions"><div class="hrim-user-view-permissions-head"><span class="hrim-field-label">View Access</span><label class="hrim-check hrim-view-select-all"><input type="checkbox" class="hrim-view-select-all-checkbox" <?php checked(count($edit_allowed_views)===count($view_map)); ?>><span>Select All</span></label></div><div class="hrim-table-wrap hrim-view-access-desktop-wrap"><table class="hrim-table hrim-view-access-table hrim-view-access-table-desktop"><thead><tr><th>View Name</th><th>Show</th><th>View Name</th><th>Show</th></tr></thead><tbody><?php foreach(array_chunk($view_map,2,true) as $view_pair): $pair_values=array_values($view_pair); $left=$pair_values[0]??null; $right=$pair_values[1]??null; ?><tr><td><?php echo $left?esc_html($left['label']):''; ?></td><td><?php if($left): ?><label class="hrim-check hrim-view-checkbox-wrap"><input type="checkbox" class="hrim-view-checkbox" name="allowed_views[]" value="<?php echo esc_attr($left['slug']); ?>" <?php checked(in_array($left['slug'],$edit_allowed_views,true)); ?>></label><?php endif; ?></td><td><?php echo $right?esc_html($right['label']):''; ?></td><td><?php if($right): ?><label class="hrim-check hrim-view-checkbox-wrap"><input type="checkbox" class="hrim-view-checkbox" name="allowed_views[]" value="<?php echo esc_attr($right['slug']); ?>" <?php checked(in_array($right['slug'],$edit_allowed_views,true)); ?>></label><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><div class="hrim-table-wrap hrim-view-access-mobile-wrap"><table class="hrim-table hrim-view-access-table hrim-view-access-table-mobile"><thead><tr><th>View Name</th><th>Show</th></tr></thead><tbody><?php foreach($view_map as $view_item): ?><tr><td><?php echo esc_html($view_item['label']); ?></td><td><label class="hrim-check hrim-view-checkbox-wrap"><input type="checkbox" class="hrim-view-checkbox" name="allowed_views[]" value="<?php echo esc_attr($view_item['slug']); ?>" <?php checked(in_array($view_item['slug'],$edit_allowed_views,true)); ?>></label></td></tr><?php endforeach; ?></tbody></table></div></div><label>Status *<select name="status" required><option value="">Select Status</option><option value="active" <?php selected($edit_status,true); ?>>Active</option><option value="inactive" <?php selected($edit_status,false); ?>>Inactive</option></select></label><div class="hrim-form-actions"><button class="hrim-primary"><?php echo $edit?'Update User':'Create User'; ?></button></div></form></div></div><?php endif; ?>
        <?php echo $this->master_import_modal('users'); return $this->shell('Users',ob_get_clean(),'users');
    }

    public function audit_log_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','audit');
        global $wpdb;
        $rows=$wpdb->get_results("SELECT a.*,u.display_name FROM {$wpdb->prefix}hrim_audit_log a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id ORDER BY a.id DESC LIMIT 1000");
        ob_start(); ?>
        <div class="hrim-panel"><div class="hrim-panel-head"><div><h2>Audit Log</h2><p class="hrim-subtext">Recent create, update and delete activity.</p></div><div class="hrim-toolbar"><input class="hrim-table-search" placeholder=""><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Clear the complete audit log?');"><input type="hidden" name="action" value="hrim_clear_audit_log"><?php wp_nonce_field('hrim_clear_audit_log'); ?><button class="hrim-danger" type="submit">Clear Log</button></form></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><th>Date</th><th>Time</th><th>User</th><th>Module</th><th>Action</th><th>Details</th><th>IP</th></tr></thead><tbody><?php $sl=0; foreach($rows as $r): ?><tr><td><?php echo ++$sl; ?></td><td><?php echo esc_html($this->ist_date('d/m/y',$r->created_at)); ?></td><td><?php echo esc_html($this->ist_date('h:i A',$r->created_at)); ?></td><td><?php echo esc_html($r->display_name?:'System'); ?></td><td><?php echo esc_html($r->module); ?></td><td><span class="hrim-badge"><?php echo esc_html(ucfirst($r->action_name)); ?></span></td><td><small class="hrim-audit-details"><?php echo esc_html($r->details); ?></small></td><td><?php echo esc_html($r->ip_address); ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="8" class="hrim-empty">No audit activity found.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php return $this->shell('Audit Log',ob_get_clean(),'audit');
    }

    public function profile_shortcode(){ $u=wp_get_current_user(); ob_start(); if(isset($_GET['saved'])) echo '<div class="hrim-alert success">Profile updated.</div>'; ?><div class="hrim-panel hrim-narrow"><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_profile"><?php wp_nonce_field('hrim_save_profile'); ?><label>Display Name<input name="display_name" value="<?php echo esc_attr($u->display_name); ?>" required></label><label>Email<input value="<?php echo esc_attr($u->user_email); ?>" disabled></label><label>New Password <small>Leave blank to keep existing</small><input type="password" name="password" minlength="8"></label><button class="hrim-primary">Save Profile</button></form></div><?php return $this->shell('My Profile',ob_get_clean(),'profile'); }

    public function settings_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','settings');
        ob_start(); ?>
        <div class="hrim-settings-grid">
          <div class="hrim-panel"><div class="hrim-panel-head"><div><h2>General</h2></div></div>
            <form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-no-spinner><input type="hidden" name="action" value="hrim_save_settings"><?php wp_nonce_field('hrim_save_settings'); ?>
              <label>Accent Colour<input type="color" name="accent" value="<?php echo esc_attr(get_option('hrim_accent','#2563eb')); ?>"></label>
              <div class="hrim-settings-checkboxes">
                <label class="hrim-checkbox-row"><input type="checkbox" name="show_room_score" value="1" <?php checked(get_option('hrim_show_room_score','1'),'1'); ?>><span>Show Room Score View</span></label>
                <label class="hrim-checkbox-row"><input type="checkbox" name="show_floor_score" value="1" <?php checked(get_option('hrim_show_floor_score','1'),'1'); ?>><span>Show Floor Score View</span></label>
              </div>
              <button class="hrim-primary">Save Settings</button>
            </form>
          </div>
          <div class="hrim-panel"><div class="hrim-panel-head"><div><h2>Application</h2></div></div>
            <div class="hrim-setting-actions">
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-no-spinner><input type="hidden" name="action" value="hrim_regenerate_pages"><?php wp_nonce_field('hrim_regenerate_pages'); ?><div><span class="hrim-settings-action-title">Regenerate Pages</span></div><button class="hrim-secondary">Run</button></form>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-no-spinner onsubmit="return confirm('Install the detailed hotel section and checkpoint library? Existing sections and checkpoints will not be duplicated or removed.');"><input type="hidden" name="action" value="hrim_install_checklist_library"><?php wp_nonce_field('hrim_install_checklist_library'); ?><div><span class="hrim-settings-action-title">Install Checklist Library</span></div><button class="hrim-primary">Install Library</button></form>
            </div>
          </div>
          <div class="hrim-panel hrim-settings-wide"><div class="hrim-panel-head"><div><h2>Maintenance</h2></div></div>
            <form class="hrim-maintenance-row" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-no-spinner><input type="hidden" name="action" value="hrim_repair_application"><?php wp_nonce_field('hrim_repair_application'); ?><div><span class="hrim-settings-action-title">Repair Application</span></div><button class="hrim-secondary">Repair Now</button></form>
          </div>

        </div><?php return $this->shell('Settings',ob_get_clean(),'settings');
    }

    public function communications_shortcode(){
        if(!$this->can_manage()) return $this->shell('Access Denied','<div class="hrim-alert error">You do not have permission.</div>','communications');
        ob_start(); ?>
        <div class="hrim-settings-grid hrim-communications-grid">
          <?php $wa=$this->whatsapp_settings(); $last_wa=get_option('hrim_whatsapp_last_result',[]); ?>
          <div class="hrim-panel hrim-settings-wide"><div class="hrim-panel-head"><div><h2 class="hrim-communications-section-title">Meta / WhatsApp Business Settings</h2></div></div>
            <details class="hrim-meta-template-guide">
              <summary class="hrim-communications-guide-title"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> WhatsApp Template Setup Instructions</summary>
              <div class="hrim-meta-template-guide-body">
                <div class="hrim-meta-guide-actions">
                  <a class="hrim-secondary" href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" aria-hidden="true"></span> Open WhatsApp Manager</a>
                  <a class="hrim-secondary" href="https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates/" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-book-alt" aria-hidden="true"></span> Meta Template Guide</a>
                </div>
                <div class="hrim-meta-guide-steps">
                  <h3>How to create a template</h3>
                  <ol>
                    <li>Open WhatsApp Manager and select the correct WhatsApp Business Account.</li>
                    <li>Open <strong>Account Tools → Message Templates</strong> and click <strong>Create Template</strong>.</li>
                    <li>Select category <strong>Utility</strong> and language <strong>English (US)</strong>.</li>
                    <li>Enter the exact template name shown below. Use only lowercase letters and underscores.</li>
                    <li>Choose the header type and paste the body exactly without changing the variable order.</li>
                    <li>Add sample values for every variable and submit the template for approval.</li>
                    <li>After approval, enter the exact approved template name in the fields below.</li>
                  </ol>
                </div>
                <div class="hrim-meta-template-grid">
                  <section class="hrim-meta-template-card">
                    <h3>New Issue Template</h3>
                    <div class="hrim-meta-template-meta"><span><strong>Name:</strong> roomscore_new_issue</span><span><strong>Header:</strong> Image</span><span><strong>Language:</strong> en_US</span></div>
                    <pre>New Issue Created

Issue Reference: {{1}}
Responsible Department: {{2}}
Asset Location: {{3}}
Inspection Section: {{4}}
Checkpoint: {{5}}
Issue Details: {{6}}

Please take the required action.</pre>
                    <div class="hrim-table-wrap"><table class="hrim-table hrim-meta-variable-table"><thead><tr><th>Variable</th><th>Sample Value</th></tr></thead><tbody><tr><td>{{1}}</td><td>125</td></tr><tr><td>{{2}}</td><td>Electrical</td></tr><tr><td>{{3}}</td><td>1st Floor - Asset 101</td></tr><tr><td>{{4}}</td><td>Electrical</td></tr><tr><td>{{5}}</td><td>Bedside light is working</td></tr><tr><td>{{6}}</td><td>Bedside light is not working</td></tr></tbody></table></div>
                  </section>
                  <section class="hrim-meta-template-card">
                    <h3>New Issue Template Without Photo</h3>
                    <div class="hrim-meta-template-meta"><span><strong>Name:</strong> roomscore_new_issue_without_photo</span><span><strong>Header:</strong> None</span><span><strong>Language:</strong> en</span></div>
                    <pre>New Issue Created

Issue Reference: {{1}}
Responsible Department: {{2}}
Asset Location: {{3}}
Inspection Section: {{4}}
Checkpoint: {{5}}
Issue Details: {{6}}

Please take the required action.</pre>
                    <p class="hrim-subtext">RoomScore automatically uses this template when no issue photograph is available.</p>
                  </section>
                  <section class="hrim-meta-template-card">
                    <h3>Notification Summary Template</h3>
                    <div class="hrim-meta-template-meta"><span><strong>Name:</strong> roomscore_notification_summary</span><span><strong>Header:</strong> None</span><span><strong>Language:</strong> en_US</span></div>
                    <pre>RoomScore Notification

{{1}}</pre>
                    <p class="hrim-subtext">The complete department-wise pending issue, periodic check or scheduled-task summary is inserted into {{1}}.</p>
                    <div class="hrim-meta-sample"><strong>Sample value</strong><pre>Electrical Department

Pending Issues: 4

Assets:
101 - Bedside light not working
105 - Exhaust fan not working</pre></div>
                  </section>
                </div>
                <div class="hrim-meta-guide-note"><strong>Important:</strong> Do not add Open buttons or URL buttons. Create both new-issue templates. RoomScore uses the Image template when a photo is available and the No Header template when no photo is available.</div>
              </div>
            </details>
            <form id="hrimMetaSettingsForm" class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_meta_settings"><?php wp_nonce_field('hrim_save_meta_settings'); ?>
              <label class="hrim-checkbox-row"><input type="checkbox" name="whatsapp_enabled" value="1" <?php checked($wa['enabled']); ?>><span>Enable WhatsApp issue notifications</span></label>
              <div class="hrim-grid-2">
                <label>Graph API Version *<input name="graph_version" required value="<?php echo esc_attr($wa['graph_version']); ?>" placeholder="v25.0"></label>
                <label>Phone Number ID *<input name="phone_number_id" value="<?php echo esc_attr($wa['phone_number_id']); ?>"></label>
                <label>WhatsApp Business Account ID<input name="waba_id" value="<?php echo esc_attr($wa['waba_id']); ?>"></label>
                <label>New Issue Template With Photo *<input name="template_name" value="<?php echo esc_attr($wa['template_name']); ?>" placeholder="roomscore_new_issue"></label>
                <label>New Issue Template Without Photo *<input name="template_without_photo" value="<?php echo esc_attr($wa['template_without_photo']); ?>" placeholder="roomscore_new_issue_without_photo"></label>
                <label>Summary Template Name *<input name="summary_template" value="<?php echo esc_attr($wa['summary_template']); ?>" placeholder="roomscore_notification_summary"></label>
                <label>Template Language Code *<input name="language_code" value="<?php echo esc_attr($wa['language_code']); ?>" placeholder="en_US"></label>
                <label>Test Mobile Number<input name="test_mobile" value="<?php echo esc_attr($wa['test_mobile']); ?>" placeholder="919876543210"></label>
              </div>
              <label>Permanent Access Token<textarea name="access_token" rows="3" autocomplete="off" placeholder="Paste Meta system-user access token"></textarea></label>
            </form>
            <div class="hrim-communication-button-row">
              <form class="hrim-inline-action-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_test_whatsapp"><?php wp_nonce_field('hrim_test_whatsapp'); ?><button class="hrim-secondary">Send Test</button></form>
              <button type="submit" form="hrimMetaSettingsForm" class="hrim-primary">Save Meta Settings</button>
            </div>
          </div>
          <?php $tg=$this->telegram_settings(); $nr=$this->notification_rules(); $last_run=get_option('hrim_notifications_last_run','Never'); ?>
          <div class="hrim-panel hrim-settings-wide"><div class="hrim-panel-head"><div><h2 class="hrim-communications-section-title">Telegram Settings</h2></div></div>
            <form id="hrimTelegramSettingsForm" class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_notification_settings"><?php wp_nonce_field('hrim_save_notification_settings'); ?><input type="hidden" name="settings_section" value="telegram">
              <label class="hrim-checkbox-row"><input type="checkbox" name="telegram_enabled" value="1" <?php checked($tg['enabled']); ?>><span>Enable Telegram notifications</span></label>
              <div class="hrim-grid-2"><label>Telegram Bot Token<input type="password" name="telegram_bot_token" value="<?php echo esc_attr($tg['bot_token']); ?>" autocomplete="new-password"></label><label>Test Telegram Chat ID<input name="telegram_test_chat_id" value="<?php echo esc_attr($tg['test_chat_id']); ?>"></label></div>
            </form>
            <div class="hrim-communication-button-row">
              <form class="hrim-inline-action-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_test_telegram"><?php wp_nonce_field('hrim_test_telegram'); ?><button class="hrim-secondary">Send Test</button></form>
              <button type="submit" form="hrimTelegramSettingsForm" class="hrim-primary">Save Telegram Settings</button>
            </div>
          </div>
          <div class="hrim-panel hrim-settings-wide"><div class="hrim-panel-head"><div><h2 class="hrim-communications-section-title">Notification Rules & Triggers</h2></div></div>
            <form id="hrimNotificationRulesForm" class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_notification_settings"><?php wp_nonce_field('hrim_save_notification_settings'); ?><input type="hidden" name="settings_section" value="rules">
              <label class="hrim-checkbox-row"><input type="checkbox" name="notifications_enabled" value="1" <?php checked(get_option('hrim_notifications_enabled','0'),'1'); ?>><span>Enable scheduled notification engine</span></label>
              <div class="hrim-table-wrap"><table class="hrim-table hrim-notification-rule-table"><thead><tr><th>Notification</th><th>Active</th><th>Interval Minutes</th><th>Days Before</th><th>Head Number</th><th>Telegram Group</th></tr></thead><tbody>
              <?php foreach(['pending_issues'=>'Pending Issues List','upcoming_periodic'=>'Upcoming Periodic Checks','scheduled_not_started'=>'Scheduled Tasks Not Yet Started','scheduled_in_progress'=>'Scheduled Tasks In Progress'] as $key=>$label): $rule=$nr[$key]; ?><tr><td><?php echo esc_html($label); ?></td><td><input type="checkbox" name="rules[<?php echo esc_attr($key); ?>][enabled]" value="1" <?php checked(!empty($rule['enabled'])); ?>></td><td><input type="number" min="5" name="rules[<?php echo esc_attr($key); ?>][interval_minutes]" value="<?php echo esc_attr($rule['interval_minutes']); ?>"></td><td><?php if($key==='upcoming_periodic'): ?><input type="number" min="0" name="rules[<?php echo esc_attr($key); ?>][days_before]" value="<?php echo esc_attr($rule['days_before']); ?>"><?php else: ?>—<?php endif; ?></td><td><input type="checkbox" name="rules[<?php echo esc_attr($key); ?>][head]" value="1" <?php checked(!empty($rule['head'])); ?>></td><td><input type="checkbox" name="rules[<?php echo esc_attr($key); ?>][telegram]" value="1" <?php checked(!empty($rule['telegram'])); ?>></td></tr><?php endforeach; ?>
              </tbody></table></div></form>
            <div class="hrim-communication-button-row">
              <form class="hrim-inline-action-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_run_notifications_now"><?php wp_nonce_field('hrim_run_notifications_now'); ?><button class="hrim-secondary">Run Now</button></form>
              <button type="submit" form="hrimNotificationRulesForm" class="hrim-primary">Save Notification Rules</button>
            </div>
          </div>
          <?php global $wpdb; $notification_logs=$wpdb->get_results("SELECT l.*,d.name department_name FROM {$wpdb->prefix}hrim_notification_log l LEFT JOIN {$wpdb->prefix}hrim_departments d ON d.id=l.department_id WHERE l.channel<>'whatsapp_group' ORDER BY l.id DESC LIMIT 100"); ?>
          <div class="hrim-panel hrim-settings-wide"><div class="hrim-panel-head"><div><h2 class="hrim-communications-section-title">Notification Log</h2></div></div><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Date</th><th>Department</th><th>Notification</th><th>Channel</th><th>Destination</th><th>Status</th><th>Response</th></tr></thead><tbody><?php foreach($notification_logs as $log): ?><tr><td><?php echo esc_html($this->ist_date('d/m/y h:i A',$log->created_at)); ?></td><td><?php echo esc_html($log->department_name?:'—'); ?></td><td><?php echo esc_html(ucwords(str_replace('_',' ',$log->notification_type))); ?></td><td><?php echo esc_html(ucwords(str_replace('_',' ',$log->channel))); ?></td><td><?php echo esc_html($log->destination?:'—'); ?></td><td><span class="hrim-badge <?php echo $log->status==='sent'?'success':'high'; ?>"><?php echo esc_html(ucfirst($log->status)); ?></span></td><td><?php echo esc_html($log->api_response?:'—'); ?></td></tr><?php endforeach; ?><?php if(!$notification_logs): ?><tr><td colspan="7" class="hrim-empty">No notification activity yet.</td></tr><?php endif; ?></tbody></table></div></div>

        </div><?php return $this->shell('Communications',ob_get_clean(),'communications');
    }

    private function inspection_table($rows){ $single_property=$this->single_active_property();
        global $wpdb;
        ob_start(); ?><div class="hrim-table-wrap"><table class="hrim-table hrim-exportable"><thead><tr><th>Sl.</th><th>Date</th><?php if(!$single_property): ?><th>Hotel</th><?php endif; ?><th>Floor</th><th>Room</th><th>Inspector</th><th>Time</th><th class="hrim-no-export">Actions</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="<?php echo $single_property?8:9; ?>" class="hrim-empty">No records found.</td></tr><?php endif; $sl=0; foreach($rows as $r): ?><tr><td><?php echo ++$sl; ?></td><td><?php echo esc_html($this->ist_date('d/m/y',$r->submitted_at)); ?></td><?php if(!$single_property): ?><td><?php echo esc_html($r->hotel_name); ?></td><?php endif; ?><td><?php echo esc_html($r->floor_name?:'—'); ?></td><td><strong><?php echo esc_html($r->room_no); ?></strong></td><td><?php echo esc_html($r->inspector); ?></td><td><?php echo esc_html($this->ist_date('h:i A',$r->submitted_at)); ?></td><td class="hrim-no-export"><div class="hrim-actions"><button type="button" class="hrim-icon-btn hrim-action-view hrim-view-inspection" data-target="hrimInspectionModal<?php echo esc_attr($r->id); ?>" title="View Inspection Details" aria-label="View Inspection Details"><span class="hrim-eye-symbol" aria-hidden="true">&#128065;</span></button><?php if($this->can_manage()): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this inspection and all linked answers/issues?');"><input type="hidden" name="action" value="hrim_delete_inspection"><input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>"><?php wp_nonce_field('hrim_delete_inspection'); ?><button class="hrim-icon-btn hrim-delete" title="Delete"><span class="dashicons dashicons-trash"></span></button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php
        foreach($rows as $r){
            $answers=$wpdb->get_results($wpdb->prepare("SELECT a.*,q.question,s.name section_name,s.sort_order section_order FROM {$wpdb->prefix}hrim_answers a LEFT JOIN {$wpdb->prefix}hrim_questions q ON q.id=a.question_id LEFT JOIN {$wpdb->prefix}hrim_sections s ON s.id=q.section_id WHERE a.inspection_id=%d ORDER BY COALESCE(s.sort_order,9999),s.name,q.sort_order,q.id",$r->id));
            $issues=$wpdb->get_results($wpdb->prepare("SELECT id,title,description,status,created_at FROM {$wpdb->prefix}hrim_issues WHERE inspection_id=%d ORDER BY id",$r->id));
            $total=count($answers); $passed=0; $failed=0; $na=0; $grouped=[];
            foreach($answers as $a){
                $section=$a->section_name?:'Other'; $grouped[$section][]=$a;
                $value=strtolower(trim((string)$a->answer_text)); $status=strtolower((string)$a->result_status);
                if($status==='fail'||$value==='no')$failed++; elseif(in_array($value,['n/a','na','not applicable'],true))$na++; else $passed++;
            }
            ?><div class="hrim-modal hrim-inspection-detail-modal" id="hrimInspectionModal<?php echo esc_attr($r->id); ?>"><div class="hrim-modal-card hrim-modal-wide"><button type="button" class="hrim-modal-close hrim-inspection-modal-close" aria-label="Close inspection details" title="Close">&times;</button><div class="hrim-inspection-detail"><div class="hrim-detail-head"><div><h2>Inspection Details</h2><p><?php echo esc_html(($single_property?'':($r->hotel_name.' / ')).'Room '.$r->room_no); ?></p></div></div><section class="hrim-section-score-card"><div class="hrim-table-wrap"><table class="hrim-table hrim-section-score-table"><thead><tr><th>Section</th><th>Passed</th><th>Failed</th><th>Percentage</th></tr></thead><tbody><?php $section_index=0; foreach($grouped as $section=>$section_answers): $section_passed=0; $section_failed=0; foreach($section_answers as $section_answer){ $section_value=strtolower(trim((string)$section_answer->answer_text)); $section_status=strtolower((string)$section_answer->result_status); if($section_status==='fail'||$section_value==='no')$section_failed++; elseif(!in_array($section_value,['n/a','na','not applicable'],true))$section_passed++; } $section_count=$section_passed+$section_failed; $section_percentage=$section_count?round(($section_passed/$section_count)*100):0; $section_panel_id='hrimSectionDetail'.$r->id.'_'.$section_index++; ?><tr class="hrim-section-score-row" tabindex="0" role="button" aria-expanded="false" data-target="<?php echo esc_attr($section_panel_id); ?>"><td><strong><?php echo esc_html($section); ?></strong><span class="hrim-section-row-arrow" aria-hidden="true">&#9662;</span></td><td><?php echo esc_html($section_passed); ?></td><td><?php echo esc_html($section_failed); ?></td><td><span class="hrim-section-percentage"><?php echo esc_html($section_percentage); ?>%</span></td></tr><tr class="hrim-section-detail-row"><td colspan="4"><div id="<?php echo esc_attr($section_panel_id); ?>" class="hrim-section-detail-panel" hidden><div class="hrim-table-wrap"><table class="hrim-table hrim-checkpoint-result-table"><thead><tr><th>Sl.</th><th class="hrim-col-checkpoint">Checkpoint</th><th>Answer</th><th>Remarks</th></tr></thead><tbody><?php $checkpoint_sl=0; foreach($section_answers as $a): $value=strtolower(trim((string)$a->answer_text)); $is_fail=strtolower((string)$a->result_status)==='fail'||$value==='no'; $is_na=in_array($value,['n/a','na','not applicable'],true); ?><tr class="<?php echo $is_fail?'is-fail':($is_na?'is-na':'is-pass'); ?>"><td><?php echo ++$checkpoint_sl; ?></td><td><?php echo esc_html($a->question?:'Checkpoint'); ?></td><td><span class="hrim-result-label <?php echo $is_fail?'is-fail':($is_na?'is-na':'is-pass'); ?>"><?php echo esc_html($a->answer_text?:ucfirst($a->result_status)); ?></span></td><td><?php echo trim((string)$a->remarks)!==''?nl2br(esc_html($a->remarks)):'—'; ?></td></tr><?php endforeach; ?></tbody></table></div></div></td></tr><?php endforeach; ?></tbody></table></div></section><section class="hrim-section-score-card hrim-print-section-score-card" aria-hidden="true"><div class="hrim-table-wrap"><table class="hrim-table hrim-print-section-score-table"><thead><tr><th>Section</th><th>Passed</th><th>Failed</th><th>Percentage</th></tr></thead><tbody><?php foreach($grouped as $print_section=>$print_answers): $print_passed=0; $print_failed=0; foreach($print_answers as $print_answer){ $print_value=strtolower(trim((string)$print_answer->answer_text)); $print_status=strtolower((string)$print_answer->result_status); if($print_status==='fail'||$print_value==='no')$print_failed++; elseif(!in_array($print_value,['n/a','na','not applicable'],true))$print_passed++; } $print_count=$print_passed+$print_failed; $print_percentage=$print_count?round(($print_passed/$print_count)*100):0; ?><tr><td><?php echo esc_html($print_section); ?></td><td><?php echo esc_html($print_passed); ?></td><td><?php echo esc_html($print_failed); ?></td><td><?php echo esc_html($print_percentage); ?>%</td></tr><?php endforeach; ?></tbody></table></div></section><?php if($issues): ?><section class="hrim-detail-section hrim-issues-generated-section"><h3>Issues Generated</h3><div class="hrim-table-wrap"><table class="hrim-table"><thead><tr><th>Sl.</th><th>Date</th><th class="hrim-col-checkpoint">Checkpoint</th><th class="hrim-col-issue">Issue</th></tr></thead><tbody><?php $issue_sl=0; foreach($issues as $issue): ?><tr><td><?php echo ++$issue_sl; ?></td><td><?php echo !empty($issue->created_at)?esc_html($this->ist_date('d/m/y',$issue->created_at)):'—'; ?></td><td><?php echo esc_html($issue->title); ?></td><td><?php echo esc_html($issue->description?:'—'); ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?></div></div></div><?php
        }
        return ob_get_clean();
    }
    private function simple_form($title,$action,$fields){ ob_start(); ?><div class="hrim-panel"><h2><?php echo esc_html($title); ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo esc_attr($action); ?>"><?php wp_nonce_field($action); foreach($fields as $f): ?><label><?php echo esc_html($f[1]); ?><?php if($f[2]==='textarea'): ?><textarea name="<?php echo esc_attr($f[0]); ?>"></textarea><?php else: ?><input type="<?php echo esc_attr($f[2]); ?>" name="<?php echo esc_attr($f[0]); ?>" required><?php endif; ?></label><?php endforeach; ?><button class="hrim-primary">Save</button></form></div><?php return ob_get_clean(); }
    private function select_form($title,$action,$fields){ ob_start(); ?><div class="hrim-panel"><h2><?php echo esc_html($title); ?></h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo esc_attr($action); ?>"><?php wp_nonce_field($action); foreach($fields as $f): ?><label><?php echo esc_html($f[1]); ?><?php if(is_array($f[2])): ?><select name="<?php echo esc_attr($f[0]); ?>" required><option value="">Select</option><?php foreach($f[2] as $o): ?><option value="<?php echo esc_attr($o->{$f[3]}); ?>"><?php echo esc_html($o->{$f[4]}); ?></option><?php endforeach; ?></select><?php else: ?><input name="<?php echo esc_attr($f[0]); ?>" required><?php endif; ?></label><?php endforeach; ?><button class="hrim-primary">Save</button></form></div><?php return ob_get_clean(); }
    private function room_form($hotels,$floors){ ob_start(); ?><div class="hrim-panel"><h2>Add Room</h2><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_save_room"><?php wp_nonce_field('hrim_save_room'); ?><label>Hotel<select name="hotel_id" required><option value="">Select</option><?php foreach($hotels as $h): ?><option value="<?php echo esc_attr($h->id); ?>"><?php echo esc_html($h->name); ?></option><?php endforeach; ?></select></label><label>Floor<select name="floor_id" required><option value="">Select</option><?php foreach($floors as $f): ?><option value="<?php echo esc_attr($f->id); ?>"><?php echo esc_html($f->hotel_name.' – '.$f->name); ?></option><?php endforeach; ?></select></label><label>Room Number<input name="room_no" required></label><label>Category<input name="category"></label><button class="hrim-primary">Save</button></form></div><?php return ob_get_clean(); }
    private function issue_form_alert($form){
        $current=sanitize_key(wp_unslash($_GET['hrim_form']??''));
        if($current!==sanitize_key($form) || !isset($_GET['hrim_msg'])) return '';
        $cls=isset($_GET['error'])?'error':'success';
        return '<div class="hrim-alert '.$cls.' hrim-form-alert">'.esc_html(wp_unslash($_GET['hrim_msg'])).'</div>';
    }

    private function add_issue_modal($hotels,$floors,$rooms,$departments,$sections,$users,$open=false){
        $single_property=$this->single_active_property();
        ob_start(); ?>
        <?php $open=$open || sanitize_key(wp_unslash($_GET['hrim_form']??''))==='add'; ?>
        <div class="hrim-modal <?php echo $open?'open':''; ?>" id="hrimAddIssueModal">
            <div class="hrim-modal-card hrim-modal-wide">
                <a class="hrim-modal-close" href="<?php echo esc_url($this->page_url('hotel-inspection-issues')); ?>" aria-label="Close" title="Close">×</a>
                <h2>Add Manual Issue</h2>
                <?php echo $this->issue_form_alert('add'); ?>
                <form id="hrimAddIssueForm" class="hrim-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="hrim_save_issue">
                    <?php wp_nonce_field('hrim_save_issue'); ?>
                    <div class="hrim-grid-2">
                        <?php if($single_property): ?><input type="hidden" name="hotel_id" id="hrimIssueHotel" value="<?php echo esc_attr($single_property->id); ?>"><?php else: ?><label>Property *
                            <select name="hotel_id" id="hrimIssueHotel" required>
                                <option value="">Select Property</option>
                                <?php foreach($hotels as $h): ?><option value="<?php echo esc_attr($h->id); ?>"><?php echo esc_html($h->name); ?></option><?php endforeach; ?>
                            </select>
                        </label><?php endif; ?>
                        <label>Floor
                            <select name="floor_id" id="hrimIssueFloor">
                                <option value="">Select Floor</option>
                                <?php foreach($floors as $f): ?><option value="<?php echo esc_attr($f->id); ?>" data-hotel="<?php echo esc_attr($f->hotel_id); ?>"><?php echo esc_html($f->name); ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Room
                            <select name="room_id" id="hrimIssueRoom">
                                <option value="">Select Room</option>
                                <?php foreach($rooms as $r): ?><option value="<?php echo esc_attr($r->id); ?>" data-hotel="<?php echo esc_attr($r->hotel_id); ?>" data-floor="<?php echo esc_attr($r->floor_id); ?>"><?php echo esc_html($r->room_no); ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Department *
                            <select name="department_id" id="hrimIssueDepartment" required>
                                <option value="">Select Department</option>
                                <?php foreach($departments as $department): ?><option value="<?php echo esc_attr($department->id); ?>"><?php echo esc_html($department->name); ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Section
                            <select name="section_id" id="hrimIssueSection">
                                <option value="">Select Section</option>
                                <?php foreach($sections as $se): ?><option value="<?php echo esc_attr($se->id); ?>" data-department="<?php echo esc_attr($se->department_id); ?>"><?php echo esc_html($se->name); ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Issue Title *<input name="title" required maxlength="255"></label>
                        
                        <label>Assign To
                            <select name="assigned_to"><option value="">Unassigned</option><?php foreach($users as $u): $user_depts=$this->user_department_ids($u->ID); ?><option value="<?php echo esc_attr($u->ID); ?>" data-departments="<?php echo esc_attr(implode(',',$user_depts)); ?>" data-global="<?php echo $this->is_global_department_user($u->ID)?'1':'0'; ?>"><?php echo esc_html($u->display_name); ?></option><?php endforeach; ?></select>
                        </label>
                        <label>Due Date<input type="date" name="due_date"></label>
                    </div>
                    <label>Issue Description *<textarea name="description" required></textarea></label>
                    <label>Before Photographs<input type="file" name="issue_photos[]" accept="image/*" multiple></label>
                    <div class="hrim-form-actions"><button type="submit" class="hrim-primary">Save Issue</button></div>
                </form>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    private function assign_issue_modal($users){ $open=sanitize_key(wp_unslash($_GET['hrim_form']??''))==='assign'; ob_start(); ?><div class="hrim-modal <?php echo $open?'open':''; ?>" id="hrimAssignIssueModal"><div class="hrim-modal-card"><button class="hrim-modal-close" type="button">×</button><h2>Assign Issue</h2><?php echo $this->issue_form_alert('assign'); ?><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_assign_issue"><input type="hidden" name="issue_id" id="hrimAssignIssueId" value="<?php echo esc_attr(absint($_GET['hrim_issue_id']??0)); ?>"><?php wp_nonce_field('hrim_assign_issue'); ?><label>Assigned To *<select name="assigned_to" id="hrimAssignIssueUser" required><option value="">Select Staff Member</option><?php foreach($users as $u): $user_depts=$this->user_department_ids($u->ID); ?><option value="<?php echo esc_attr($u->ID); ?>" data-departments="<?php echo esc_attr(implode(',',$user_depts)); ?>" data-global="<?php echo $this->is_global_department_user($u->ID)?'1':'0'; ?>"><?php echo esc_html($u->display_name); ?></option><?php endforeach; ?></select></label><label>Due Date<input type="date" name="due_date" id="hrimAssignIssueDue"></label><label>Assignment Remarks<textarea name="assignment_remarks" id="hrimAssignIssueRemarks" placeholder="Optional assignment instructions"></textarea></label><div class="hrim-form-actions"><button class="hrim-primary">Assign Issue</button></div></form></div></div><?php return ob_get_clean(); }

    private function edit_issue_modal(){ $open=sanitize_key(wp_unslash($_GET['hrim_form']??''))==='edit'; ob_start(); ?><div class="hrim-modal <?php echo $open?'open':''; ?>" id="hrimEditIssueModal"><div class="hrim-modal-card"><button class="hrim-modal-close" type="button">×</button><h2>Edit Issue</h2><?php echo $this->issue_form_alert('edit'); ?><form class="hrim-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="hrim_update_issue"><input type="hidden" name="issue_id" id="hrimEditIssueId" value="<?php echo esc_attr(absint($_GET['hrim_issue_id']??0)); ?>"><?php wp_nonce_field('hrim_update_issue'); ?><label>Issue Title *<input name="title" id="hrimEditIssueTitle" required></label><label>Remarks / Description *<textarea name="description" id="hrimEditIssueDescription" required></textarea></label><label>Due Date<input type="date" name="due_date" id="hrimEditIssueDue"></label><div class="hrim-form-actions"><button class="hrim-primary">Update Issue</button></div></form></div></div><?php return ob_get_clean(); }

    private function progress_issue_modal(){ $open=sanitize_key(wp_unslash($_GET['hrim_form']??''))==='progress'; ob_start(); ?>
        <div class="hrim-modal <?php echo $open?'open':''; ?>" id="hrimProgressIssueModal">
            <div class="hrim-modal-card hrim-modal-wide">
                <button class="hrim-modal-close" type="button">×</button>
                <div id="hrimProgressIssueLocation" class="hrim-update-issue-location hrim-update-issue-location-top"></div>
                <h2>Update Issue</h2>
                <?php echo $this->issue_form_alert('progress'); ?>
                <div class="hrim-update-issue-context hrim-update-issue-context-combined">
                    <div class="hrim-update-issue-part">
                        <span class="hrim-update-issue-label">Checkpoint</span>
                        <strong id="hrimProgressIssueTitle"></strong>
                    </div>
                    <div class="hrim-update-issue-divider" aria-hidden="true"></div>
                    <div class="hrim-update-issue-part">
                        <span class="hrim-update-issue-label">Issue</span>
                        <div id="hrimProgressIssueDescription" class="hrim-update-issue-description"></div>
                    </div>
                </div>
                <form class="hrim-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="hrim_progress_issue">
                    <input type="hidden" name="issue_id" id="hrimProgressIssueId" value="<?php echo esc_attr(absint($_GET['hrim_issue_id']??0)); ?>">
                    <?php wp_nonce_field('hrim_progress_issue'); ?>
                    <div class="hrim-update-issue-row">
                        <label>Status *
                            <select name="status" id="hrimProgressIssueStatus" required>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                            </select>
                        </label>
                        <label>Update Photo<input type="file" name="update_photos[]" accept="image/*" multiple></label>
                    </div>
                    <label>Remarks *<textarea name="remarks" id="hrimProgressIssueRemarks" required placeholder="Describe the work completed or current progress"></textarea></label>
                    <div class="hrim-form-actions"><button class="hrim-primary">Save Update</button></div>
                </form>
            </div>
        </div>
        <?php return ob_get_clean(); }

    private function save_result($result, $slug, $success_message='Saved successfully.') {
        global $wpdb;
        if ($result === false) {
            $message = $wpdb->last_error ?: 'The record could not be saved. Please verify the database tables and submitted values.';
            wp_safe_redirect(add_query_arg(['hrim_error' => rawurlencode($message)], $this->page_url($slug)));
        } else {
            wp_safe_redirect(add_query_arg(['hrim_saved' => '1', 'hrim_message' => rawurlencode($success_message)], $this->page_url($slug)));
        }
        exit;
    }

    private function action_buttons($entity,$id,$slug,$edit_key='edit'){
        $edit=add_query_arg($edit_key,(int)$id,$this->page_url($slug)); ob_start(); ?><div class="hrim-actions"><a class="hrim-icon-btn hrim-edit hrim-symbol-btn" title="Edit" aria-label="Edit" href="<?php echo esc_url($edit); ?>"><span class="hrim-action-symbol" aria-hidden="true">&#9998;</span></a><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this record? This action cannot be undone.');"><input type="hidden" name="action" value="hrim_delete_<?php echo esc_attr($entity); ?>"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><?php if($entity==='question' && $slug==='hotel-inspection-sectionwise-checklist'): ?><input type="hidden" name="redirect_slug" value="hotel-inspection-sectionwise-checklist"><?php endif; ?><?php wp_nonce_field('hrim_delete_'.$entity); ?><button class="hrim-icon-btn hrim-delete hrim-symbol-btn" title="Delete" aria-label="Delete" type="submit"><span class="hrim-action-symbol" aria-hidden="true">&#128465;</span></button></form></div><?php return ob_get_clean();
    }
    private function dependency_error($slug,$message){ $this->back($slug,'error',$message); }
    public function save_hotel(){ $this->guard_manage('hrim_save_hotel'); global $wpdb; $id=absint($_POST['id']??0); $data=['name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'address'=>sanitize_textarea_field(wp_unslash($_POST['address']??'')),'status'=>in_array($_POST['status']??'active',['active','inactive'],true)?$_POST['status']:'active']; $r=$id?$wpdb->update($wpdb->prefix.'hrim_hotels',$data,['id'=>$id]):$wpdb->insert($wpdb->prefix.'hrim_hotels',$data); $this->save_result($r,'hotel-inspection-properties',$id?'Property updated successfully.':'Property saved successfully.'); }
    public function delete_hotel(){ $this->guard_manage('hrim_delete_hotel'); global $wpdb; $id=absint($_POST['id']); $deps=(int)$wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_floors WHERE hotel_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_rooms WHERE hotel_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_inspections WHERE hotel_id=%d)",$id,$id,$id)); if($deps)$this->dependency_error('hotel-inspection-properties','Cannot delete this property because floors, rooms or inspections are linked to it. Remove or reassign dependent records first.'); $this->save_result($wpdb->delete($wpdb->prefix.'hrim_hotels',['id'=>$id]),'hotel-inspection-properties','Property deleted successfully.'); }
    public function save_floor(){ $this->guard_manage('hrim_save_floor'); global $wpdb; $id=absint($_POST['id']??0); $hotel=$this->effective_property_id($_POST['hotel_id']??0); if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_hotels WHERE id=%d",$hotel)))$this->dependency_error('hotel-inspection-floors','Selected property does not exist.'); $manager_id=absint($_POST['floor_manager_id']??0); if($manager_id && (!get_userdata($manager_id) || !$this->user_is_active($manager_id))) $manager_id=0; $data=['hotel_id'=>$hotel,'name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'floor_manager_id'=>$manager_id?:null,'sort_order'=>intval($_POST['sort_order']??0),'status'=>in_array($_POST['status']??'active',['active','inactive'],true)?$_POST['status']:'active']; $r=$id?$wpdb->update($wpdb->prefix.'hrim_floors',$data,['id'=>$id]):$wpdb->insert($wpdb->prefix.'hrim_floors',$data); $this->save_result($r,'hotel-inspection-floors',$id?'Floor updated successfully.':'Floor saved successfully.'); }
    public function delete_floor(){ $this->guard_manage('hrim_delete_floor'); global $wpdb; $id=absint($_POST['id']); $deps=(int)$wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_rooms WHERE floor_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_inspections WHERE floor_id=%d)",$id,$id)); if($deps)$this->dependency_error('hotel-inspection-floors','Cannot delete this floor because rooms or inspections are linked to it.'); $this->save_result($wpdb->delete($wpdb->prefix.'hrim_floors',['id'=>$id]),'hotel-inspection-floors','Floor deleted successfully.'); }
    public function save_room_category(){
        $this->guard_manage('hrim_save_room_category');
        global $wpdb;
        HRIM_Activator::create_tables();
        $id=absint($_POST['id']??0);
        $name=trim(sanitize_text_field(wp_unslash($_POST['name']??'')));
        if(!$name) $this->dependency_error('hotel-inspection-room-categories','Category name is required.');
        $table=$wpdb->prefix.'hrim_room_categories';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) $this->dependency_error('hotel-inspection-room-categories','Room Categories table is unavailable. Please run Repair Application from Settings.');
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) AND id<>%d LIMIT 1",$name,$id));
        if($dup) $this->dependency_error('hotel-inspection-room-categories','A room category with this name already exists.');
        $data=['name'=>$name,'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'status'=>($_POST['status']??'active')==='inactive'?'inactive':'active'];
        $old=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$id),ARRAY_A):null;
        $r=$id?$wpdb->update($table,$data,['id'=>$id]):$wpdb->insert($table,$data);
        if($r===false) $this->dependency_error('hotel-inspection-room-categories','Room category could not be saved: '.$wpdb->last_error);
        if(!$id) $id=(int)$wpdb->insert_id;
        if(!$id || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d",$id))) $this->dependency_error('hotel-inspection-room-categories','The room category was not found after saving. Please run Repair Application and try again.');
        $wpdb->delete($wpdb->prefix.'hrim_category_templates',['category_id'=>$id]);
        foreach(array_unique(array_map('absint',(array)($_POST['template_ids']??[]))) as $tid){
            if($tid && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_templates WHERE id=%d",$tid))){
                $x=$wpdb->insert($wpdb->prefix.'hrim_category_templates',['category_id'=>$id,'template_id'=>$tid]);
                if($x===false && stripos($wpdb->last_error,'Duplicate')===false) $this->dependency_error('hotel-inspection-room-categories','Category saved, but template assignment failed: '.$wpdb->last_error);
            }
        }
        $this->audit('Room Categories',$old?'update':'create',$id,['old'=>$old,'new'=>$data,'template_ids'=>array_values(array_unique(array_map('absint',(array)($_POST['template_ids']??[]))))]);
        $this->back('hotel-inspection-room-categories','saved',$old?'Room category updated successfully.':'Room category saved successfully.');
    }
    public function delete_room_category(){
        $this->guard_manage('hrim_delete_room_category'); global $wpdb; $id=absint($_POST['id']??0);
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_rooms WHERE category_id=%d",$id)); if($count)$this->dependency_error('hotel-inspection-room-categories',sprintf('Cannot delete this category because %d room(s) are linked to it.',$count));
        $wpdb->delete($wpdb->prefix.'hrim_category_templates',['category_id'=>$id]); $deleted=$wpdb->delete($wpdb->prefix.'hrim_room_categories',['id'=>$id]); if($deleted)$this->audit('Room Categories','delete',$id); $this->save_result($deleted,'hotel-inspection-room-categories','Room category deleted successfully.');
    }

    public function save_asset_type(){
        $this->guard_manage('hrim_save_asset_type'); global $wpdb;
        HRIM_Activator::create_tables();
        $id=absint($_POST['id']??0); $name=trim(sanitize_text_field(wp_unslash($_POST['name']??'')));
        if($name==='')$this->dependency_error('hotel-inspection-asset-types','Asset Type is required.');
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_asset_types WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) AND id<>%d",$name,$id));
        if($dup)$this->dependency_error('hotel-inspection-asset-types','This Asset Type already exists.');
        $data=['name'=>$name,'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'sort_order'=>intval($_POST['sort_order']??0),'status'=>($_POST['status']??'active')==='inactive'?'inactive':'active'];
        $result=$id?$wpdb->update($wpdb->prefix.'hrim_asset_types',$data,['id'=>$id]):$wpdb->insert($wpdb->prefix.'hrim_asset_types',$data);
        if(!$id)$id=(int)$wpdb->insert_id;
        if($result!==false)$this->audit('Asset Types',$id?'update':'create',$id,$data);
        $this->save_result($result,'hotel-inspection-asset-types','Asset Type saved successfully.');
    }
    public function delete_asset_type(){
        $this->guard_manage('hrim_delete_asset_type'); global $wpdb; $id=absint($_POST['id']??0);
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_rooms WHERE asset_type_id=%d",$id));
        if($count)$this->dependency_error('hotel-inspection-asset-types',sprintf('Cannot delete this Asset Type because %d asset(s) are linked to it.',$count));
        $this->save_result($wpdb->delete($wpdb->prefix.'hrim_asset_types',['id'=>$id]),'hotel-inspection-asset-types','Asset Type deleted successfully.');
    }

    public function save_room(){
        $this->guard_manage('hrim_save_room'); global $wpdb; $id=absint($_POST['id']??0);

        // Permanently remove a standard template checkpoint from this asset only.
        // The master/template checkpoint remains unchanged for every other asset.
        $delete_standard_checkpoint_id=absint($_POST['delete_standard_checkpoint_id']??0);
        if($id && $delete_standard_checkpoint_id){
            $valid_question=(int)$wpdb->get_var($wpdb->prepare("SELECT q.id FROM {$wpdb->prefix}hrim_questions q WHERE q.id=%d AND q.is_library=1",$delete_standard_checkpoint_id));
            if(!$valid_question)$this->dependency_error('hotel-inspection-rooms','The selected checkpoint was not found.');
            // Cross action means permanent deletion for this asset. Remove all temporary
            // visibility/configuration data and keep only the permanent backend removal marker.
            $wpdb->delete($wpdb->prefix.'hrim_room_question_exclusions',['room_id'=>$id,'question_id'=>$delete_standard_checkpoint_id],['%d','%d']);
            $wpdb->delete($wpdb->prefix.'hrim_room_question_additions',['room_id'=>$id,'question_id'=>$delete_standard_checkpoint_id],['%d','%d']);
            $wpdb->replace($wpdb->prefix.'hrim_room_question_removals',['room_id'=>$id,'question_id'=>$delete_standard_checkpoint_id],['%d','%d']);
            $this->audit('Assets','permanently delete checkpoint from asset',$id,['question_id'=>$delete_standard_checkpoint_id,'backend_deleted'=>true]);
            $this->back('hotel-inspection-rooms','saved','Checkpoint permanently deleted from this asset and backend asset data.',['edit'=>$id]);
        }

        // Permanently remove an asset-specific checkpoint from this asset.
        // Historical question records are retained only when answers/issues already use them.
        $delete_addition_id=absint($_POST['delete_additional_checkpoint_id']??0);
        if($id && $delete_addition_id){
            $addition=$wpdb->get_row($wpdb->prepare("SELECT id,question_id FROM {$wpdb->prefix}hrim_room_question_additions WHERE id=%d AND room_id=%d",$delete_addition_id,$id));
            if(!$addition)$this->dependency_error('hotel-inspection-rooms','The selected asset-specific checkpoint was not found.');
            $wpdb->delete($wpdb->prefix.'hrim_room_question_additions',['id'=>$delete_addition_id,'room_id'=>$id]);
            $qid=(int)$addition->question_id;
            $used=(int)$wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_answers WHERE question_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE question_id=%d)",$qid,$qid));
            if(!$used)$wpdb->delete($wpdb->prefix.'hrim_questions',['id'=>$qid,'is_library'=>0]);
            $this->audit('Assets','delete asset checkpoint',$id,['addition_id'=>$delete_addition_id,'question_id'=>$qid]);
            $this->back('hotel-inspection-rooms','saved','Checkpoint permanently deleted from this asset.',['edit'=>$id]);
        }

        $hotel=absint($_POST['hotel_id']); $floor=absint($_POST['floor_id']); $category=0; $asset_type_id=absint($_POST['asset_type_id']??0);
        if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_hotels WHERE id=%d",$hotel)))$this->dependency_error('hotel-inspection-rooms','Selected property does not exist.');
        if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_floors WHERE id=%d AND hotel_id=%d",$floor,$hotel)))$this->dependency_error('hotel-inspection-rooms','Selected floor does not belong to the selected property.');
        $asset_type=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$wpdb->prefix}hrim_asset_types WHERE id=%d AND status='active'",$asset_type_id)); if(!$asset_type)$this->dependency_error('hotel-inspection-rooms','Selected Asset Type does not exist.');
        $data=['hotel_id'=>$hotel,'floor_id'=>$floor,'asset_type_id'=>$asset_type_id,'category_id'=>null,'category'=>'','template_id'=>null,'room_no'=>sanitize_text_field(wp_unslash($_POST['room_no']??'')),'status'=>($_POST['status']??'active')==='inactive'?'inactive':'active'];
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_rooms WHERE hotel_id=%d AND room_no=%s AND id<>%d",$hotel,$data['room_no'],$id)); if($dup)$this->dependency_error('hotel-inspection-rooms','This asset name or number already exists for the selected property.');
        $r=$id?$wpdb->update($wpdb->prefix.'hrim_rooms',$data,['id'=>$id]):$wpdb->insert($wpdb->prefix.'hrim_rooms',$data); if($r===false)$this->save_result(false,'hotel-inspection-rooms',''); if(!$id)$id=(int)$wpdb->insert_id;
        $selected=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['template_ids']??[])))));
        $valid=[];
        foreach($selected as $tid){ if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_templates WHERE id=%d AND status='active'",$tid)))$valid[]=$tid; }
        $direct=$valid;
        $excluded=[];
        $wpdb->delete($wpdb->prefix.'hrim_room_templates',['room_id'=>$id]);
        $wpdb->delete($wpdb->prefix.'hrim_room_template_exclusions',['room_id'=>$id]);
        foreach($direct as $tid)$wpdb->insert($wpdb->prefix.'hrim_room_templates',['room_id'=>$id,'template_id'=>$tid]);
        foreach($excluded as $tid)$wpdb->insert($wpdb->prefix.'hrim_room_template_exclusions',['room_id'=>$id,'template_id'=>$tid]);

        // Save checkpoint visibility for this asset. Checked checkpoints are shown;
        // unchecked checkpoints are stored as asset-level exclusions.
        $included_questions=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['included_question_ids']??[])))));
        $available_question_ids=[];
        if($valid){
            $ph=implode(',',array_fill(0,count($valid),'%d'));
            $available_question_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT q.id FROM {$wpdb->prefix}hrim_template_sections ts INNER JOIN {$wpdb->prefix}hrim_questions q ON q.section_id=ts.section_id AND q.is_library=1 WHERE ts.template_id IN ($ph)",$valid)));
        }
        $included_questions=array_values(array_intersect($available_question_ids,$included_questions));
        $excluded_questions=array_values(array_diff($available_question_ids,$included_questions));
        $wpdb->delete($wpdb->prefix.'hrim_room_question_exclusions',['room_id'=>$id]);
        foreach($excluded_questions as $qid){
            $wpdb->insert($wpdb->prefix.'hrim_room_question_exclusions',['room_id'=>$id,'question_id'=>$qid]);
        }

        // The Additional Asset Checkpoints editor has been removed. Preserve any historical
        // asset-specific checkpoints so older inspections and issues remain intact.
        if(isset($_POST['hrim_manage_additional_checkpoints'])){
        $existing_additions=$wpdb->get_results($wpdb->prepare("SELECT id,question_id FROM {$wpdb->prefix}hrim_room_question_additions WHERE room_id=%d",$id));
        $existing_map=[]; foreach($existing_additions as $ea)$existing_map[(int)$ea->id]=(int)$ea->question_id;
        $kept=[];
        $aids=(array)($_POST['additional_id']??[]); $asids=(array)($_POST['additional_section_id']??[]); $aqs=(array)($_POST['additional_question']??[]);
        foreach($aqs as $i=>$raw_question){
            $question=trim(sanitize_text_field(wp_unslash($raw_question))); if($question==='') continue;
            $section_id=absint($asids[$i]??0); if(!$section_id || !$valid) continue;
            $section=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$wpdb->prefix}hrim_sections WHERE id=%d AND status='active'",$section_id)); if(!$section) continue;
            $ph=implode(',',array_fill(0,count($valid),'%d'));
            $template_id=(int)$wpdb->get_var($wpdb->prepare("SELECT ts.template_id FROM {$wpdb->prefix}hrim_template_sections ts WHERE ts.section_id=%d AND ts.template_id IN ($ph) ORDER BY FIELD(ts.template_id,$ph) LIMIT 1",array_merge([$section_id],$valid,$valid)));
            if(!$template_id) continue;
            $type='yes_no'; $required=0; $sort_order=0; $addition_id=absint($aids[$i]??0);
            $qdata=['template_id'=>$template_id,'section_id'=>$section_id,'section_name'=>$section->name,'question'=>$question,'question_type'=>$type,'required_flag'=>$required,'is_library'=>0,'sort_order'=>$sort_order];
            if($addition_id && isset($existing_map[$addition_id])){
                $qid=$existing_map[$addition_id]; $wpdb->update($wpdb->prefix.'hrim_questions',$qdata,['id'=>$qid]); $wpdb->update($wpdb->prefix.'hrim_room_question_additions',['template_id'=>$template_id],['id'=>$addition_id]); $kept[]=$addition_id;
            } else {
                $wpdb->insert($wpdb->prefix.'hrim_questions',$qdata); $qid=(int)$wpdb->insert_id;
                if($qid){$wpdb->insert($wpdb->prefix.'hrim_room_question_additions',['room_id'=>$id,'template_id'=>$template_id,'question_id'=>$qid]); $kept[]=(int)$wpdb->insert_id;}
            }
        }
        foreach($existing_map as $addition_id=>$qid){
            if(in_array($addition_id,$kept,true)) continue;
            $wpdb->delete($wpdb->prefix.'hrim_room_question_additions',['id'=>$addition_id]);
            $used=(int)$wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_answers WHERE question_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE question_id=%d)",$qid,$qid));
            if(!$used)$wpdb->delete($wpdb->prefix.'hrim_questions',['id'=>$qid,'is_library'=>0]);
        }
        }

        $this->audit('Assets',$id?'update':'create',$id,['selected_templates'=>$valid,'direct_templates'=>$direct,'excluded_category_templates'=>$excluded,'excluded_questions'=>$excluded_questions]);
        $this->back('hotel-inspection-rooms','saved','Asset saved successfully.');
    }
    public function delete_room(){ $this->guard_manage('hrim_delete_room'); global $wpdb; $id=absint($_POST['id']); $deps=(int)$wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_inspections WHERE room_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE room_id=%d)",$id,$id)); if($deps)$this->dependency_error('hotel-inspection-rooms','Cannot delete this asset because inspections or issues are linked to it.'); $addition_qids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT question_id FROM {$wpdb->prefix}hrim_room_question_additions WHERE room_id=%d",$id))); $wpdb->delete($wpdb->prefix.'hrim_room_templates',['room_id'=>$id]); $wpdb->delete($wpdb->prefix.'hrim_room_template_exclusions',['room_id'=>$id]); $wpdb->delete($wpdb->prefix.'hrim_room_question_exclusions',['room_id'=>$id]); $wpdb->delete($wpdb->prefix.'hrim_room_question_additions',['room_id'=>$id]); foreach($addition_qids as $qid)$wpdb->delete($wpdb->prefix.'hrim_questions',['id'=>$qid,'is_library'=>0]); $this->save_result($wpdb->delete($wpdb->prefix.'hrim_rooms',['id'=>$id]),'hotel-inspection-rooms','Asset deleted successfully.'); }

    public function save_department(){
        $this->guard_manage('hrim_save_department'); global $wpdb;
        $id=absint($_POST['id']??0);
        $name=trim(sanitize_text_field(wp_unslash($_POST['name']??'')));
        if(!$name) $this->dependency_error('hotel-inspection-departments','Department name is required.');
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_departments WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) AND id<>%d LIMIT 1",$name,$id));
        if($dup) $this->dependency_error('hotel-inspection-departments','A department with this name already exists.');
        $head_user_id=absint($_POST['department_head_user_id']??0);
        if($head_user_id && !get_userdata($head_user_id)) $this->dependency_error('hotel-inspection-departments','Selected Department Head was not found.');
        if($head_user_id && !$this->normalize_mobile_number(get_user_meta($head_user_id,'hrim_mobile',true))) $this->dependency_error('hotel-inspection-departments','The selected Department Head does not have a mobile number.');
        $data=['name'=>$name,'department_head_user_id'=>$head_user_id?:null,'telegram_chat_id'=>sanitize_text_field(wp_unslash($_POST['telegram_chat_id']??'')),'notify_head_enabled'=>isset($_POST['notify_head_enabled'])?1:0,'notify_telegram_enabled'=>isset($_POST['notify_telegram_enabled'])?1:0,'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??''))];
        if($id){
            $r=$wpdb->update($wpdb->prefix.'hrim_departments',$data,['id'=>$id]);
        }else{
            $data['sort_order']=0;
            $data['status']='active';
            $r=$wpdb->insert($wpdb->prefix.'hrim_departments',$data);
        }
        if($r!==false){ $department_id=$id?:((int)$wpdb->insert_id); if($head_user_id && $department_id)$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}hrim_user_departments (user_id,department_id) VALUES (%d,%d)",$head_user_id,$department_id)); }
        $this->save_result($r,'hotel-inspection-departments',$id?'Department updated successfully.':'Department saved successfully.');
    }
    public function delete_department(){
        $this->guard_manage('hrim_delete_department'); global $wpdb; $id=absint($_POST['id']??0);
        $deps=(int)$wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_sections WHERE department_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_user_departments WHERE department_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE department_id=%d)",$id,$id,$id));
        if($deps) $this->dependency_error('hotel-inspection-departments','Cannot delete this department because sections, users or issues are linked to it.');
        $this->save_result($wpdb->delete($wpdb->prefix.'hrim_departments',['id'=>$id]),'hotel-inspection-departments','Department deleted successfully.');
    }

    public function save_section(){
        $this->guard_manage('hrim_save_section'); global $wpdb;
        $id=absint($_POST['id']??0); $name=trim(sanitize_text_field(wp_unslash($_POST['name']??'')));
        if(!$name) $this->dependency_error('hotel-inspection-checklist-sections','Section name is required.');
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_sections WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) AND id<>%d LIMIT 1",$name,$id));
        if($dup) $this->dependency_error('hotel-inspection-checklist-sections','A checklist section with this name already exists.');
        $department_id=absint($_POST['department_id']??0); if(!$department_id || !(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_departments WHERE id=%d AND status='active'",$department_id))) $this->dependency_error('hotel-inspection-checklist-sections','Please select a valid active department.');
        $data=['name'=>$name,'department_id'=>$department_id,'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'sort_order'=>intval($_POST['sort_order']??0),'status'=>($_POST['status']??'active')==='inactive'?'inactive':'active'];
        $r=$id?$wpdb->update($wpdb->prefix.'hrim_sections',$data,['id'=>$id]):$wpdb->insert($wpdb->prefix.'hrim_sections',$data);
        if($r!==false && $id){ $wpdb->update($wpdb->prefix.'hrim_questions',['section_name'=>$name],['section_id'=>$id]); }
        $this->save_result($r,'hotel-inspection-checklist-sections',$id?'Checklist section updated successfully.':'Checklist section saved successfully.');
    }
    public function delete_section(){
        $this->guard_manage('hrim_delete_section'); global $wpdb; $id=absint($_POST['id']??0);
        $deps=(int)$wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_questions WHERE section_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_template_sections WHERE section_id=%d)",$id,$id));
        if($deps) $this->dependency_error('hotel-inspection-checklist-sections','Cannot delete this section because checklist points are linked to it. Reassign those points first.');
        $this->save_result($wpdb->delete($wpdb->prefix.'hrim_sections',['id'=>$id]),'hotel-inspection-checklist-sections','Checklist section deleted successfully.');
    }

    public function save_template(){
        $this->guard_manage('hrim_save_template'); global $wpdb;
        $id=absint($_POST['id']??0);
        $data=['name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'status'=>in_array($_POST['status']??'active',['active','inactive'],true)?$_POST['status']:'active'];
        if(!$data['name']) $this->dependency_error('hotel-inspection-templates','Template name is required.');
        $r=$id?$wpdb->update($wpdb->prefix.'hrim_templates',$data,['id'=>$id]):$wpdb->insert($wpdb->prefix.'hrim_templates',$data);
        if($r===false) $this->dependency_error('hotel-inspection-templates',$wpdb->last_error?:'Template could not be saved.');
        if(!$id)$id=(int)$wpdb->insert_id;
        $section_ids=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['section_ids']??[])))));
        $wpdb->delete($wpdb->prefix.'hrim_template_sections',['template_id'=>$id]);
        foreach($section_ids as $order=>$sid){
            if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_sections WHERE id=%d AND status='active'",$sid))) $wpdb->insert($wpdb->prefix.'hrim_template_sections',['template_id'=>$id,'section_id'=>$sid,'sort_order'=>$order+1]);
        }
        $this->audit('Templates',$id?'update':'create',$id,['section_ids'=>$section_ids]);
        $this->back('hotel-inspection-templates','saved',$id?'Template updated successfully.':'Template created successfully.');
    }
    public function delete_template(){
        $this->guard_manage('hrim_delete_template'); global $wpdb; $id=absint($_POST['id']);
        if($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_inspections WHERE template_id=%d",$id))) $this->dependency_error('hotel-inspection-templates','Cannot delete this template because completed inspections use it.');
        $wpdb->delete($wpdb->prefix.'hrim_category_templates',['template_id'=>$id]);
        $wpdb->delete($wpdb->prefix.'hrim_room_templates',['template_id'=>$id]);
        $wpdb->delete($wpdb->prefix.'hrim_room_template_exclusions',['template_id'=>$id]);
        $wpdb->delete($wpdb->prefix.'hrim_template_sections',['template_id'=>$id]);
        $this->save_result($wpdb->delete($wpdb->prefix.'hrim_templates',['id'=>$id]),'hotel-inspection-templates','Template deleted. Reusable section checkpoints were retained.');
    }
    public function save_question(){
        $this->guard_manage('hrim_save_question'); global $wpdb;
        $redirect_slug=sanitize_key(wp_unslash($_POST['redirect_slug']??''))==='hotel-inspection-sectionwise-checklist'?'hotel-inspection-sectionwise-checklist':'hotel-inspection-templates';
        $id=absint($_POST['id']??0); $section_id=absint($_POST['section_id']??0);
        $section=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_sections WHERE id=%d AND status='active'",$section_id));
        if(!$section) $this->dependency_error($redirect_slug,'Please select a valid active checklist section.');
        $question=sanitize_textarea_field(wp_unslash($_POST['question']??''));
        if(!$question)$this->dependency_error($redirect_slug,'Checkpoint is required.');
        $dup=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_questions WHERE section_id=%d AND is_library=1 AND LOWER(TRIM(question))=LOWER(TRIM(%s)) AND id<>%d LIMIT 1",$section_id,$question,$id));
        if($dup)$this->dependency_error($redirect_slug,'This checkpoint already exists in the selected section.');
        $data=['template_id'=>0,'section_id'=>$section_id,'section_name'=>$section->name,'question'=>$question,'question_type'=>in_array($_POST['question_type']??'yes_no',['yes_no','rating','text'],true)?$_POST['question_type']:'yes_no','required_flag'=>isset($_POST['required_flag'])?1:0,'is_library'=>1,'sort_order'=>intval($_POST['sort_order']??0)];
        $r=$id?$wpdb->update($wpdb->prefix.'hrim_questions',$data,['id'=>$id]):$wpdb->insert($wpdb->prefix.'hrim_questions',$data);
        $this->save_result($r,$redirect_slug,$id?'Checkpoint updated successfully.':'Checkpoint saved successfully.');
    }
    public function delete_question(){
        $this->guard_manage('hrim_delete_question'); global $wpdb;
        $redirect_slug=sanitize_key(wp_unslash($_POST['redirect_slug']??''))==='hotel-inspection-sectionwise-checklist'?'hotel-inspection-sectionwise-checklist':'hotel-inspection-templates';
        $id=absint($_POST['id']);
        $deps=(int)$wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_answers WHERE question_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE question_id=%d)",$id,$id));
        if($deps)$this->dependency_error($redirect_slug,'Cannot delete this reusable checkpoint because inspection answers or issues are linked to it.');
        $this->save_result($wpdb->delete($wpdb->prefix.'hrim_questions',['id'=>$id]),$redirect_slug,'Checkpoint deleted successfully.');
    }
    public function submit_inspection(){
        if(!$this->can_inspect()) wp_die('Permission denied');
        check_admin_referer('hrim_submit_inspection'); global $wpdb;
        $mode=($_POST['save_mode']??'complete')==='in_progress'?'in_progress':'complete';
        $room_id=absint($_POST['room_id']??0);
        $room=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_rooms WHERE id=%d",$room_id));
        if(!$room) wp_die('Invalid room');
        $assigned_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT template_id FROM (SELECT template_id FROM {$wpdb->prefix}hrim_room_templates WHERE room_id=%d UNION SELECT ct.template_id FROM {$wpdb->prefix}hrim_category_templates ct WHERE ct.category_id=%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}hrim_room_template_exclusions x WHERE x.room_id=%d AND x.template_id=ct.template_id)) permitted ORDER BY template_id",$room_id,(int)$room->category_id,$room_id)));
        if(!$assigned_ids) wp_die('No inspection template is assigned to this room or its room category.');
        $posted=is_array($_POST['answers']??null)?$_POST['answers']:[];
        $batch=sanitize_text_field(wp_unslash($_POST['draft_batch_id']??''));
        if(!$batch) $batch=wp_generate_uuid4();
        $now=$this->ist_now_mysql();
        $overall_remarks=sanitize_textarea_field(wp_unslash($_POST['remarks']??''));
        $created=0; $wpdb->query('START TRANSACTION');
        foreach($assigned_ids as $template_id){
            $valid_rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT q.id,q.question,q.section_id,s.department_id,q.required_flag FROM {$wpdb->prefix}hrim_template_sections ts INNER JOIN {$wpdb->prefix}hrim_questions q ON q.section_id=ts.section_id AND q.is_library=1 INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=q.section_id LEFT JOIN {$wpdb->prefix}hrim_room_question_exclusions rx ON rx.room_id=%d AND rx.question_id=q.id WHERE ts.template_id=%d AND rx.question_id IS NULL UNION ALL SELECT q.id,q.question,q.section_id,s.department_id,q.required_flag FROM {$wpdb->prefix}hrim_room_question_additions a INNER JOIN {$wpdb->prefix}hrim_questions q ON q.id=a.question_id INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=q.section_id WHERE a.room_id=%d AND a.template_id=%d",$room_id,$template_id,$room_id,$template_id));
            $valid=[]; foreach($valid_rows as $q)$valid[(int)$q->id]=$q;
            $template_answers=is_array($posted[$template_id]??null)?$posted[$template_id]:[];
            if($mode==='complete'){
                foreach($valid as $qid=>$q){
                    $a=is_array($template_answers[$qid]??null)?$template_answers[$qid]:[];
                    $value=sanitize_textarea_field(wp_unslash($a['value']??''));
                    $result=sanitize_key($a['result']??'pass');
                    $remarks=sanitize_textarea_field(wp_unslash($a['remarks']??''));
                    if((int)$q->required_flag===1 && $value==='') $this->dependency_error('perform-room-inspection','Please complete all mandatory checkpoints before submitting.');
                    if(($result==='fail'||$value==='no') && $remarks==='') $this->dependency_error('perform-room-inspection','Remarks are required for every checkpoint marked No.');
                }
            }
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hrim_inspections WHERE draft_batch_id=%s AND template_id=%d AND room_id=%d AND inspector_id=%d LIMIT 1",$batch,$template_id,$room_id,get_current_user_id()));
            $failed=false;
            foreach($template_answers as $qid=>$a){ if(!isset($valid[(int)$qid]))continue; if(($a['result']??'')==='fail'||($a['value']??'')==='no'){$failed=true;break;} }
            $inspection_data=['hotel_id'=>$room->hotel_id,'floor_id'=>$room->floor_id,'room_id'=>$room_id,'template_id'=>$template_id,'inspector_id'=>get_current_user_id(),'status'=>$mode==='in_progress'?'in_progress':($failed?'failed':'completed'),'remarks'=>$overall_remarks,'last_saved_at'=>$now,'submitted_at'=>$now,'draft_batch_id'=>$batch];
            if($mode==='in_progress') $inspection_data['started_at']=$existing&&$existing->started_at?$existing->started_at:$now;
            else { $inspection_data['completed_at']=$now; $inspection_data['started_at']=$existing&&$existing->started_at?$existing->started_at:$now; }
            if($existing){ $ok=$wpdb->update($wpdb->prefix.'hrim_inspections',$inspection_data,['id'=>$existing->id]); $iid=(int)$existing->id; }
            else { $ok=$wpdb->insert($wpdb->prefix.'hrim_inspections',$inspection_data); $iid=(int)$wpdb->insert_id; }
            if($ok===false){$wpdb->query('ROLLBACK');wp_die(esc_html($wpdb->last_error?:'Inspection could not be saved.'));}
            $wpdb->delete($wpdb->prefix.'hrim_answers',['inspection_id'=>$iid]);
            foreach($template_answers as $qid=>$a){
                $qid=absint($qid); if(!isset($valid[$qid]))continue;
                $v=sanitize_textarea_field(wp_unslash($a['value']??''));
                $res=sanitize_key($a['result']??($v==='no'?'fail':'pass'));
                $rem=sanitize_textarea_field(wp_unslash($a['remarks']??''));
                $att=$this->normalize_attachment_ids($a['attachment_ids']??'');
                $videos=$this->normalize_attachment_ids($a['video_ids']??'');
                if($v==='' && $rem==='' && !$att && !$videos) continue;
                $wpdb->insert($wpdb->prefix.'hrim_answers',['inspection_id'=>$iid,'question_id'=>$qid,'answer_text'=>$v,'result_status'=>$res,'remarks'=>$rem,'attachment_ids'=>implode(',',$att),'video_attachment_ids'=>implode(',',$videos)]);
                if($mode==='complete' && ($res==='fail'||$v==='no')){
                    $already=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_issues WHERE inspection_id=%d AND question_id=%d LIMIT 1",$iid,$qid));
                    $issue_data=['source'=>'inspection','inspection_id'=>$iid,'hotel_id'=>$room->hotel_id,'floor_id'=>$room->floor_id,'room_id'=>$room_id,'section_id'=>$valid[$qid]->section_id,'department_id'=>absint($valid[$qid]->department_id??0),'question_id'=>$qid,'title'=>$valid[$qid]->question,'description'=>$rem,'priority'=>'medium','status'=>'open','reported_by'=>get_current_user_id(),'attachment_ids'=>implode(',',$att),'created_at'=>$now];
                    if($already) $wpdb->update($wpdb->prefix.'hrim_issues',$issue_data,['id'=>$already]); else { $wpdb->insert($wpdb->prefix.'hrim_issues',$issue_data); $new_issue_id=(int)$wpdb->insert_id; if($new_issue_id)$this->notify_department_for_issue($new_issue_id); }
                }
            }
            $created++;
        }
        $wpdb->query('COMMIT');
        if($mode==='in_progress') $this->back('hotel-inspection-in-progress','draft_saved','Inspection saved in progress successfully.');
        $this->back('perform-room-inspection','submitted',sprintf('%d assigned checklist(s) submitted successfully.',$created));
    }

    public function delete_in_progress(){
        $this->guard_manage('hrim_delete_in_progress'); global $wpdb;
        $batch=sanitize_text_field(wp_unslash($_POST['draft_batch_id']??''));
        if(!$batch) $this->dependency_error('hotel-inspection-in-progress','Invalid draft.');
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_inspections WHERE draft_batch_id=%s AND status='in_progress'",$batch)));
        $wpdb->query('START TRANSACTION');
        foreach($ids as $id){ $wpdb->delete($wpdb->prefix.'hrim_answers',['inspection_id'=>$id]); $wpdb->delete($wpdb->prefix.'hrim_inspections',['id'=>$id]); }
        $wpdb->query('COMMIT');
        $this->back('hotel-inspection-in-progress','saved','In-progress inspection deleted successfully.');
    }

    public function delete_inspection(){ $this->guard_manage('hrim_delete_inspection'); global $wpdb; $id=absint($_POST['id']); $wpdb->query('START TRANSACTION'); $ok1=$wpdb->delete($wpdb->prefix.'hrim_issues',['inspection_id'=>$id]); $ok2=$wpdb->delete($wpdb->prefix.'hrim_answers',['inspection_id'=>$id]); $ok3=$wpdb->delete($wpdb->prefix.'hrim_inspections',['id'=>$id]); if($ok3===false){$wpdb->query('ROLLBACK');$this->dependency_error('hotel-inspection-history',$wpdb->last_error?:'Inspection could not be deleted.');} $wpdb->query('COMMIT'); $this->back('hotel-inspection-history','saved','Inspection and its linked answers/issues deleted successfully.'); }
    public function save_issue(){
        if(!$this->can_issues()) wp_die('Permission denied');
        check_admin_referer('hrim_save_issue');
        global $wpdb;

        // Ensure installations upgraded from older versions have the complete issue schema.
        HRIM_Activator::create_tables();

        $hotel_id=$this->effective_property_id($_POST['hotel_id']??0);
        $floor_id=absint($_POST['floor_id']??0);
        $room_id=absint($_POST['room_id']??0);
        $section_id=absint($_POST['section_id']??0);
        $title=sanitize_text_field(wp_unslash($_POST['title']??''));
        $description=sanitize_textarea_field(wp_unslash($_POST['description']??''));

        if($title===''||$description===''){
            $this->issue_back('add',0,'error','Issue title and description are required.');
        }

        // When a room is selected, derive its actual parent keys instead of trusting stale dropdown values.
        if($room_id){
            $room=$wpdb->get_row($wpdb->prepare("SELECT id,hotel_id,floor_id FROM {$wpdb->prefix}hrim_rooms WHERE id=%d",$room_id));
            if(!$room) $this->issue_back('add',0,'error','Selected room was not found.');
            if($hotel_id && (int)$room->hotel_id!==$hotel_id) $this->issue_back('add',0,'error','Selected room does not belong to the selected property.');
            if($floor_id && (int)$room->floor_id!==$floor_id) $this->issue_back('add',0,'error','Selected room does not belong to the selected floor.');
            $hotel_id=(int)$room->hotel_id;
            $floor_id=(int)$room->floor_id;
        }

        if(!$hotel_id) $this->issue_back('add',0,'error','Please select a property.');
        if(!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_hotels WHERE id=%d",$hotel_id))){
            $this->issue_back('add',0,'error','Selected property was not found.');
        }
        if($floor_id && !(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_floors WHERE id=%d AND hotel_id=%d",$floor_id,$hotel_id))){
            $this->issue_back('add',0,'error','Selected floor does not belong to the selected property.');
        }
        if($section_id){
            $section_row=$wpdb->get_row($wpdb->prepare("SELECT id,department_id FROM {$wpdb->prefix}hrim_sections WHERE id=%d",$section_id));
            if(!$section_row) $this->issue_back('add',0,'error','Selected section was not found.');
            $department_id=(int)$section_row->department_id;
        }
        if(!$department_id || !(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_departments WHERE id=%d AND status='active'",$department_id))){
            $this->issue_back('add',0,'error','Please select a valid department.');
        }

        $priority='medium';
        $assigned=absint($_POST['assigned_to']??0);
        if($assigned && !get_userdata($assigned)) $this->issue_back('add',0,'error','Selected staff member was not found.');
        $due_date=sanitize_text_field(wp_unslash($_POST['due_date']??''));
        if($due_date!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due_date)) $this->issue_back('add',0,'error','Due date is invalid.');

        $attachments=[];
        if(!empty($_FILES['issue_photos']['name'][0])){
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
            foreach($_FILES['issue_photos']['name'] as $i=>$name){
                if(!$name) continue;
                $_FILES['hrim_single_issue_photo']=[
                    'name'=>$name,
                    'type'=>$_FILES['issue_photos']['type'][$i]??'',
                    'tmp_name'=>$_FILES['issue_photos']['tmp_name'][$i]??'',
                    'error'=>$_FILES['issue_photos']['error'][$i]??UPLOAD_ERR_NO_FILE,
                    'size'=>$_FILES['issue_photos']['size'][$i]??0,
                ];
                $aid=media_handle_upload('hrim_single_issue_photo',0);
                if(is_wp_error($aid)){
                    unset($_FILES['hrim_single_issue_photo']);
                    $this->issue_back('add',0,'error','Photo upload failed: '.$aid->get_error_message());
                }
                $attachments[]=(int)$aid;
            }
            unset($_FILES['hrim_single_issue_photo']);
        }

        $data=[
            'source'=>'manual',
            'inspection_id'=>null,
            'hotel_id'=>$hotel_id,
            'floor_id'=>$floor_id?:null,
            'room_id'=>$room_id?:null,
            'section_id'=>$section_id?:null,
            'department_id'=>$department_id,
            'question_id'=>null,
            'title'=>$title,
            'description'=>$description,
            'priority'=>$priority,
            'status'=>$assigned?'assigned':'open',
            'assigned_to'=>$assigned?:null,
            'due_date'=>$due_date?:null,
            'reported_by'=>get_current_user_id(),
            'attachment_ids'=>$attachments?implode(',',$attachments):null,
            'created_at'=>$this->ist_now_mysql(),
        ];
        $ok=$wpdb->insert($wpdb->prefix.'hrim_issues',$data);
        if($ok===false){
            $this->issue_back('add',0,'error',$wpdb->last_error?:'Manual issue could not be saved.');
        }
        $issue_id=(int)$wpdb->insert_id;
        if($issue_id)$this->notify_department_for_issue($issue_id);
        $this->audit('issues','create',$issue_id,['source'=>'manual','hotel_id'=>$hotel_id,'floor_id'=>$floor_id,'room_id'=>$room_id,'title'=>$title]);
        clean_post_cache($issue_id);
        $this->issue_back('add',(int)$wpdb->insert_id,'saved','Manual issue created successfully.');
    }

    public function assign_issue(){
        if(!$this->can_issues()) wp_die('Permission denied');
        check_admin_referer('hrim_assign_issue'); global $wpdb;
        $issue_id=absint($_POST['issue_id']??0); $assigned_to=absint($_POST['assigned_to']??0);
        $due_date=sanitize_text_field($_POST['due_date']??'')?:null;
        $remarks=sanitize_textarea_field(wp_unslash($_POST['assignment_remarks']??''));
        if(!$issue_id||!$assigned_to) $this->issue_back('assign',$issue_id,'error','Please select a staff member.');
        $issue=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$wpdb->prefix}hrim_issues WHERE id=%d",$issue_id));
        if(!$issue) $this->issue_back('assign',$issue_id,'error','Issue not found.');
        $new_status=in_array($issue->status,['open','reopened'],true)?'assigned':$issue->status;
        $ok=$wpdb->update($wpdb->prefix.'hrim_issues',['assigned_to'=>$assigned_to,'due_date'=>$due_date,'status'=>$new_status],['id'=>$issue_id]);
        if($ok===false) $this->issue_back('assign',$issue_id,'error',$wpdb->last_error?:'Issue could not be assigned.');
        $wpdb->insert($wpdb->prefix.'hrim_issue_updates',['issue_id'=>$issue_id,'update_type'=>'assignment','status'=>$new_status,'remarks'=>$remarks,'update_date'=>$this->ist_now_mysql(),'updated_by'=>get_current_user_id(),'assigned_to'=>$assigned_to,'due_date'=>$due_date]);
        $this->audit('issues','assign',$issue_id,['assigned_to'=>$assigned_to,'due_date'=>$due_date,'remarks'=>$remarks]);

        $redirect_url = $this->page_url('hotel-inspection-issues');
        $referer = wp_get_referer();
        if ($referer) {
            $allowed_pages = ['hotel-inspection-issues', 'hotel-inspection-pending-assign-issues'];
            foreach ($allowed_pages as $allowed_page) {
                if (strpos($referer, $allowed_page) !== false) {
                    $redirect_url = remove_query_arg(['saved','error','hrim_msg','hrim_form','hrim_issue_id','hrim_refresh'], $referer);
                    break;
                }
            }
        }
        $url = add_query_arg([
            'saved' => '1',
            'hrim_msg' => rawurlencode('Issue assigned successfully.'),
            'hrim_refresh' => time(),
        ], $redirect_url);
        wp_safe_redirect($url, 303);
        exit;
    }

    public function progress_issue(){
        if(!$this->can_issues()) wp_die('Permission denied');
        check_admin_referer('hrim_progress_issue'); global $wpdb;
        $issue_id=absint($_POST['issue_id']??0); $status=sanitize_key($_POST['status']??'');
        $remarks=sanitize_textarea_field(wp_unslash($_POST['remarks']??''));
        if(!$issue_id||!in_array($status,['in_progress','resolved'],true)||$remarks==='') $this->issue_back('progress',$issue_id,'error','Status and remarks are required.');
        $issue=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_issues WHERE id=%d",$issue_id));
        if(!$issue) $this->issue_back('progress',$issue_id,'error','Issue not found.');
        $update_date=$this->ist_now_mysql();
        $attachments=[];
        if(!empty($_FILES['update_photos']['name'][0])){ require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php'; foreach($_FILES['update_photos']['name'] as $i=>$name){ if(!$name)continue; $_FILES['hrim_single_update_photo']=['name'=>$name,'type'=>$_FILES['update_photos']['type'][$i],'tmp_name'=>$_FILES['update_photos']['tmp_name'][$i],'error'=>$_FILES['update_photos']['error'][$i],'size'=>$_FILES['update_photos']['size'][$i]]; $aid=media_handle_upload('hrim_single_update_photo',0); if(!is_wp_error($aid))$attachments[]=(int)$aid; } unset($_FILES['hrim_single_update_photo']); }
        $data=['status'=>$status,'resolution_remarks'=>$remarks];
        if($status==='resolved'){ $data['resolved_at']=$update_date; $data['resolved_by']=get_current_user_id(); $data['resolution_attachment_ids']=$attachments?wp_json_encode($attachments):null; }
        else { $data['resolved_at']=null; $data['resolved_by']=null; }
        $ok=$wpdb->update($wpdb->prefix.'hrim_issues',$data,['id'=>$issue_id]);
        if($ok===false) $this->issue_back('progress',$issue_id,'error',$wpdb->last_error?:'Issue update could not be saved.');
        $wpdb->insert($wpdb->prefix.'hrim_issue_updates',['issue_id'=>$issue_id,'update_type'=>'progress','status'=>$status,'remarks'=>$remarks,'attachment_ids'=>$attachments?wp_json_encode($attachments):null,'update_date'=>$update_date,'updated_by'=>get_current_user_id()]);
        $this->audit('issues','progress',$issue_id,['status'=>$status,'remarks'=>$remarks,'attachments'=>$attachments]);
        $this->notify_department_for_issue_update($issue_id,$status==='resolved'?'Issue Resolved':'Status Changed to In Progress',$remarks);
        
        $message=$status==='resolved'?'Issue marked as resolved.':'Issue progress updated successfully.';
        // On a successful update, return to the issue list and keep the modal closed.
        // Validation/database errors still return through issue_back() with the form open.
        $url=add_query_arg([
            'saved'=>'1',
            'hrim_msg'=>rawurlencode($message),
            'hrim_refresh'=>time(),
        ],$this->page_url('hotel-inspection-issues'));
        wp_safe_redirect($url,303);
        exit;

    }

    public function update_issue(){
        if(!$this->can_issues()) wp_die('Permission denied');
        check_admin_referer('hrim_update_issue');
        global $wpdb;
        $id=absint($_POST['issue_id']??0);
        if(!$id) $this->issue_back('edit',$id,'error','Invalid issue selected.');
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,title,description,due_date FROM {$wpdb->prefix}hrim_issues WHERE id=%d",$id));
        if(!$existing) $this->issue_back('edit',$id,'error','Issue not found.');
        $data=[
            'title'=>sanitize_text_field(wp_unslash($_POST['title']??'')),
            'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),
            'due_date'=>sanitize_text_field($_POST['due_date']??'')?:null,
        ];
        if($data['title']==='' || $data['description']==='') $this->issue_back('edit',$id,'error','Issue title and remarks are required.');
        $result=$wpdb->update($wpdb->prefix.'hrim_issues',$data,['id'=>$id]);
        if($result===false) $this->issue_back('edit',$id,'error',$wpdb->last_error?:'Issue could not be updated.');
        $changes=[];
        if((string)$existing->description!==(string)$data['description'])$changes[]='Remarks: '.$data['description'];
        if((string)($existing->due_date?:'')!==(string)($data['due_date']?:''))$changes[]='Due Date: '.($data['due_date']?$this->ist_date('d/m/Y',$data['due_date']):'Removed');
        if($changes)$this->notify_department_for_issue_update($id,'Remarks or Due Date Updated',implode("\n",$changes));
        $this->issue_back('edit',$id,'saved','Issue updated successfully.');
    }

    public function delete_issue(){ if(!$this->can_issues())wp_die('Permission denied');check_admin_referer('hrim_delete_issue');global $wpdb;$this->save_result($wpdb->delete($wpdb->prefix.'hrim_issues',['id'=>absint($_POST['id'])]),'hotel-inspection-issues','Issue deleted successfully.'); }
    public function save_user(){
        $this->guard_manage('hrim_save_user'); global $wpdb;
        $id=absint($_POST['id']??0);
        $email=sanitize_email($_POST['email']??'');
        if(!$email)$this->back('hotel-inspection-users','error','A valid email is required.');
        $exists=email_exists($email);
        if($exists && (int)$exists!==$id)$this->back('hotel-inspection-users','error','Email already belongs to another user.');
        $mobile=$this->normalize_mobile_number(wp_unslash($_POST['mobile']??''));
        if(strlen($mobile)<10 || strlen($mobile)>15)$this->back('hotel-inspection-users','error','A valid mobile number with country code is required.');
        $display_name=trim(sanitize_text_field(wp_unslash($_POST['display_name']??'')));
        if($display_name==='') $this->back('hotel-inspection-users','error','Full Name is required.');
        $username=sanitize_user(wp_unslash($_POST['username']??''),true);
        if($username==='') $this->back('hotel-inspection-users','error','Username is required.');
        $username_owner=username_exists($username);
        if($username_owner && (int)$username_owner!==$id) $this->back('hotel-inspection-users','error','Username already belongs to another user.');
        $role=sanitize_key($_POST['role']??'');
        if(!in_array($role,['hrim_worker','hrim_supervisor','hrim_manager','hrim_owner'],true)) $this->back('hotel-inspection-users','error','Please select a valid Role.');
        $status=sanitize_key($_POST['status']??'');
        if(!in_array($status,['active','inactive'],true)) $this->back('hotel-inspection-users','error','Please select a valid Status.');
        $department_ids=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['department_ids']??[])))));
        $valid_department_ids=[];
        foreach($department_ids as $department_id){
            if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_departments WHERE id=%d AND status='active'",$department_id))) $valid_department_ids[]=$department_id;
        }
        if(!$valid_department_ids) $this->back('hotel-inspection-users','error','Please select at least one Department.');
        $view_map=$this->configurable_view_map();
        $valid_view_slugs=array_values(array_map(function($view){ return $view['slug']; },$view_map));
        $allowed_views=array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)($_POST['allowed_views']??[])))));
        $allowed_views=array_values(array_intersect($valid_view_slugs,$allowed_views));
        if(!$allowed_views) $this->back('hotel-inspection-users','error','Please select at least one View to show.');
        if($id){
            $data=['ID'=>$id,'user_email'=>$email,'display_name'=>$display_name];
            if(!empty($_POST['password']))$data['user_pass']=(string)$_POST['password'];
            $r=wp_update_user($data);
            if(is_wp_error($r))$this->back('hotel-inspection-users','error',$r->get_error_message());
            (new WP_User($id))->set_role($role);
            $msg='User updated successfully.';
        }else{
            $id=wp_create_user($username,(string)($_POST['password']??''),$email);
            if(is_wp_error($id))$this->back('hotel-inspection-users','error',$id->get_error_message());
            wp_update_user(['ID'=>$id,'display_name'=>$display_name]);
            (new WP_User($id))->set_role($role);
            $msg='User created successfully.';
        }
        update_user_meta($id,'hrim_user_status',$status);
        update_user_meta($id,'hrim_mobile',$mobile);
        $wpdb->delete($wpdb->prefix.'hrim_user_departments',['user_id'=>$id]);
        foreach($valid_department_ids as $department_id){
            $wpdb->insert($wpdb->prefix.'hrim_user_departments',['user_id'=>$id,'department_id'=>$department_id]);
        }
        update_user_meta($id,'hrim_allowed_views',$allowed_views);
        clean_user_cache($id);
        wp_cache_flush();
        $this->back('hotel-inspection-users','saved',$msg);
    }
    public function update_user_status(){
        $this->guard_manage('hrim_update_user_status_'.absint($_POST['id']??0));
        $id=absint($_POST['id']??0);
        if(!$id || user_can($id,'manage_options')) $this->back('hotel-inspection-users','error','This user status cannot be changed here.');
        if($id===get_current_user_id()) $this->back('hotel-inspection-users','error','You cannot make your own account inactive.');
        $status=sanitize_key($_POST['status']??'active');
        if(!in_array($status,['active','inactive'],true)) $status='active';
        update_user_meta($id,'hrim_user_status',$status);
        clean_user_cache($id);
        wp_cache_flush();
        $this->back('hotel-inspection-users','saved','User status updated successfully.');
    }
    public function delete_user(){ $this->guard_manage('hrim_delete_user'); global $wpdb; $id=absint($_POST['id']); if($id===get_current_user_id())$this->dependency_error('hotel-inspection-users','You cannot delete your own logged-in account.'); $deps=(int)$wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}hrim_inspections WHERE inspector_id=%d)+(SELECT COUNT(*) FROM {$wpdb->prefix}hrim_issues WHERE assigned_to=%d)",$id,$id)); if($deps)$this->dependency_error('hotel-inspection-users','Cannot delete this user because inspections or assigned issues are linked to the account.'); require_once ABSPATH.'wp-admin/includes/user.php'; if(!wp_delete_user($id))$this->dependency_error('hotel-inspection-users','User could not be deleted.'); $this->back('hotel-inspection-users','saved','User deleted successfully.'); }
    public function save_profile(){ if(!is_user_logged_in())wp_die('Permission denied');check_admin_referer('hrim_save_profile');$d=['ID'=>get_current_user_id(),'display_name'=>sanitize_text_field($_POST['display_name'])];if(!empty($_POST['password']))$d['user_pass']=(string)$_POST['password'];wp_update_user($d);$this->back('hotel-inspection-profile','saved','Profile updated successfully.'); }
    public function learn_shortcode(){
        $modules=[
            [
                'title'=>'1. Start with the Dashboard',
                'summary'=>'Understand the overall quality position and the items requiring attention.',
                'steps'=>[
                    ['Review dashboard summary cards and understand each count.','hotel-inspection-dashboard'],
                    ['Read the department-wise issue summary and identify pending work.','hotel-inspection-dashboard'],
                    ['Open Room Score and compare room quality results.','hotel-inspection-room-scores'],
                    ['Open Floor Score and compare floor performance.','hotel-inspection-floor-scores'],
                ],
            ],
            [
                'title'=>'2. Configure the Masters',
                'summary'=>'Create the basic structure used throughout RoomScore.',
                'steps'=>[
                    ['Create or review the Property master.','hotel-inspection-properties'],
                    ['Create floors and assign floor managers.','hotel-inspection-floors'],
                    ['Create room categories.','hotel-inspection-room-categories'],
                    ['Create rooms and map categories and templates.','hotel-inspection-rooms'],
                    ['Create departments responsible for inspections and issues.','hotel-inspection-departments'],
                    ['Create users, select roles and assign departments.','hotel-inspection-users'],
                ],
                'manager_only'=>true,
            ],
            [
                'title'=>'3. Build the Inspection Checklist',
                'summary'=>'Learn how sections, checkpoints and templates work together.',
                'steps'=>[
                    ['Create checklist sections and set their order.','hotel-inspection-checklist-sections'],
                    ['Add checkpoints under the correct sections.','hotel-inspection-sectionwise-checklist'],
                    ['Mark important checkpoints as mandatory.','hotel-inspection-sectionwise-checklist'],
                    ['Create an inspection template and select the required sections.','hotel-inspection-templates'],
                    ['Assign templates through room categories or individual rooms.','hotel-inspection-rooms'],
                ],
                'manager_only'=>true,
            ],
            [
                'title'=>'4. Perform a Room Inspection',
                'summary'=>'Complete the full mobile-friendly room-inspection workflow.',
                'steps'=>[
                    ['Select a room and load its assigned inspection checklist.','perform-room-inspection'],
                    ['Mark checkpoints Yes or No.','perform-room-inspection'],
                    ['Add a photograph to a checkpoint.','perform-room-inspection'],
                    ['Add a short video where evidence is useful.','perform-room-inspection'],
                    ['Enter mandatory remarks for every No response.','perform-room-inspection'],
                    ['Save one section and continue the inspection.','perform-room-inspection'],
                    ['Save an inspection in progress and reopen it.','hotel-inspection-in-progress'],
                    ['Complete and submit a full inspection.','perform-room-inspection'],
                ],
            ],
            [
                'title'=>'5. Review Inspection Results',
                'summary'=>'Learn how completed and incomplete inspections are tracked.',
                'steps'=>[
                    ['Review all inspections currently in progress.','hotel-inspection-in-progress'],
                    ['Open an in-progress inspection and check section completion.','hotel-inspection-in-progress'],
                    ['Open Inspection History and locate a completed inspection.','hotel-inspection-history'],
                    ['Open inspection details and review section and checkpoint results.','hotel-inspection-history'],
                    ['Use search and Excel download in inspection views.','hotel-inspection-history'],
                ],
            ],
            [
                'title'=>'6. Manage Issues',
                'summary'=>'Follow an issue from identification through assignment, resolution and closure.',
                'steps'=>[
                    ['Review issues generated automatically from No responses.','hotel-inspection-issues'],
                    ['Create a manual issue when required.','hotel-inspection-issues'],
                    ['Assign an unassigned issue to the appropriate employee.','hotel-inspection-pending-assign-issues'],
                    ['Set a due date and add assignment remarks.','hotel-inspection-pending-assign-issues'],
                    ['Update an issue as In Progress.','hotel-inspection-pending-issues'],
                    ['Resolve an issue with remarks and evidence.','hotel-inspection-pending-issues'],
                    ['Review resolved issues.','hotel-inspection-resolved-issues'],
                    ['Understand delayed issues and department-level issue visibility.','hotel-inspection-issues'],
                ],
            ],
            [
                'title'=>'7. Use Periodic Tasks',
                'summary'=>'Set up recurring room activities and monitor every cycle.',
                'steps'=>[
                    ['Create a periodic task with applicability, periodicity and start date.','hotel-inspection-add-periodic-task'],
                    ['Select the applicable checklist sections and responsible department.','hotel-inspection-add-periodic-task'],
                    ['Review and edit periodic-task masters.','hotel-inspection-periodic-tasks'],
                    ['Open a scheduled task cycle from Schedule Tasks In Progress.','hotel-inspection-schedule-tasks-in-progress'],
                    ['Complete the periodic task room by room.','hotel-inspection-schedule-tasks-in-progress'],
                    ['Understand Yet to Start, In Progress and Completed status.','hotel-inspection-schedule-tasks-in-progress'],
                    ['Review all periodic-task updates in Schedule Tasks History.','hotel-inspection-schedule-tasks-history'],
                ],
            ],
            [
                'title'=>'8. Manage Occupancy',
                'summary'=>'Record occupancy and keep room availability information current.',
                'steps'=>[
                    ['Add occupancy information for the selected date.','hotel-inspection-add-occupancy'],
                    ['Update the office count where applicable.','hotel-inspection-update-office-count'],
                    ['Review occupancy status room by room.','hotel-inspection-occupancy-check'],
                    ['Use occupancy search and Excel download.','hotel-inspection-occupancy-check'],
                ],
            ],
            [
                'title'=>'9. Reports and Exports',
                'summary'=>'Use built-in reports and Excel exports for management review.',
                'steps'=>[
                    ['Open the Reports view and review available summaries.','hotel-inspection-reports'],
                    ['Use date and other available filters.','hotel-inspection-reports'],
                    ['Download Excel from registers and reports.','hotel-inspection-reports'],
                    ['Compare Room Score, Floor Score and issue reports.','hotel-inspection-reports'],
                ],
            ],
            [
                'title'=>'10. Settings and Maintenance',
                'summary'=>'Understand the administrative tools used to maintain the application.',
                'steps'=>[
                    ['Change the application accent colour.','hotel-inspection-settings'],
                    ['Regenerate missing application pages.','hotel-inspection-settings'],
                    ['Install the checklist library when required.','hotel-inspection-settings'],
                    ['Run Repair Application after structural upgrades or missing-page issues.','hotel-inspection-settings'],
                ],
                'manager_only'=>true,
            ],
            [
                'title'=>'11. Mobile and Daily Usage',
                'summary'=>'Practise the workflow exactly as employees will use it each day.',
                'steps'=>[
                    ['Open RoomScore on a mobile phone and use the responsive menu.','hotel-inspection-dashboard'],
                    ['Perform an inspection using the camera and remarks controls.','perform-room-inspection'],
                    ['Confirm that the left menu keeps its scroll position.','hotel-inspection-dashboard'],
                    ['Log out and sign in again using the RoomScore login form.','hotel-inspection-login'],
                ],
            ],
        ];
        if(!$this->can_manage()){
            $modules=array_values(array_filter($modules,function($module){ return empty($module['manager_only']); }));
        }
        $saved=array_values(array_filter(array_map('sanitize_key',(array)get_user_meta(get_current_user_id(),'hrim_learn_progress',true))));
        $total=0;
        foreach($modules as $module) $total+=count($module['steps']);
        $completed=0;
        foreach($modules as $mi=>$module){ foreach($module['steps'] as $si=>$step){ if(in_array('m'.$mi.'s'.$si,$saved,true)) $completed++; } }
        $percent=$total?round(($completed/$total)*100):0;
        ob_start(); ?>
        <div class="hrim-learn-page" data-total="<?php echo esc_attr($total); ?>">
          <section class="hrim-learn-hero">
            <div>
              <span class="hrim-learn-eyebrow">RoomScore Training</span>
              <h2>Learn Every Feature Step by Step</h2>
              <p>Complete the practical steps below in sequence. When all steps are completed, the user will have practised the complete RoomScore workflow.</p>
            </div>
            <div class="hrim-learn-progress-card">
              <strong><span id="hrimLearnCompleted"><?php echo esc_html($completed); ?></span> / <?php echo esc_html($total); ?></strong>
              <small>Steps completed</small>
              <div class="hrim-learn-progress"><span id="hrimLearnProgressBar" style="width:<?php echo esc_attr($percent); ?>%"></span></div>
              <b id="hrimLearnPercent"><?php echo esc_html($percent); ?>%</b>
            </div>
          </section>
          <div class="hrim-learn-toolbar">
            <span>Tick a step only after completing it inside the application.</span>
            <button type="button" class="hrim-secondary" id="hrimLearnReset">Reset Progress</button>
          </div>
          <div class="hrim-learn-modules">
          <?php foreach($modules as $mi=>$module): ?>
            <section class="hrim-learn-module" style="background:#fff!important;border:1px solid var(--hrim-theme-color,var(--hrim-primary,var(--hrim-accent)))!important;box-shadow:none!important;">
              <button type="button" class="hrim-learn-module-head" style="background:#fff!important;background-color:#fff!important;background-image:none!important;color:#000!important;border:0!important;box-shadow:none!important;filter:none!important;" aria-expanded="<?php echo $mi===0?'true':'false'; ?>">
                <span><strong><?php echo esc_html($module['title']); ?></strong></span>
                <span class="dashicons dashicons-arrow-down-alt2"></span>
              </button>
              <div class="hrim-learn-steps" <?php echo $mi===0?'':'hidden'; ?>>
                <?php foreach($module['steps'] as $si=>$step): $key='m'.$mi.'s'.$si; $done=in_array($key,$saved,true); ?>
                  <div class="hrim-learn-step <?php echo $done?'is-complete':''; ?>">
                    <label>
                      <input type="checkbox" class="hrim-learn-check" value="<?php echo esc_attr($key); ?>" <?php checked($done); ?>>
                      <span class="hrim-learn-check-ui"><span class="dashicons dashicons-yes"></span></span>
                      <span class="hrim-learn-step-text"><?php echo esc_html($step[0]); ?></span>
                    </label>
                    <a class="hrim-learn-open" href="<?php echo esc_url($this->page_url($step[1])); ?>">Open <span class="dashicons dashicons-arrow-right-alt"></span></a>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
          </div>
          <div class="hrim-learn-complete" id="hrimLearnComplete" <?php echo $completed===$total?'':'hidden'; ?>>
            <span class="dashicons dashicons-awards"></span>
            <div><strong>Training Completed</strong><p>You have completed every RoomScore learning step.</p></div>
          </div>
        </div>
        <?php return $this->shell('Learn',ob_get_clean(),'learn');
    }

    public function ajax_save_learn_progress(){
        check_ajax_referer('hrim_ajax','nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>'Login required.'],401);
        $steps=array_values(array_unique(array_filter(array_map('sanitize_key',(array)($_POST['steps']??[])))));
        update_user_meta(get_current_user_id(),'hrim_learn_progress',$steps);
        wp_send_json_success(['saved'=>count($steps)]);
    }

    public function save_meta_settings(){
        $this->guard_manage('hrim_save_meta_settings');
        $enabled=isset($_POST['whatsapp_enabled']);
        $version=trim(sanitize_text_field(wp_unslash($_POST['graph_version']??'v25.0')));
        if(!preg_match('/^v\d+\.\d+$/',$version))$this->back('hotel-inspection-communications','error','Graph API Version must look like v25.0.');
        $phone_id=preg_replace('/\D+/','',wp_unslash($_POST['phone_number_id']??''));
        $waba_id=preg_replace('/\D+/','',wp_unslash($_POST['waba_id']??''));
        $template=sanitize_key(wp_unslash($_POST['template_name']??''));
        $template_without_photo=sanitize_key(wp_unslash($_POST['template_without_photo']??''));
        $summary_template=sanitize_key(wp_unslash($_POST['summary_template']??''));
        $language=preg_replace('/[^A-Za-z0-9_-]/','',wp_unslash($_POST['language_code']??'en_US'));
        $test_mobile=$this->normalize_mobile_number(wp_unslash($_POST['test_mobile']??''));
        $token=trim((string)wp_unslash($_POST['access_token']??''));
        if($enabled && (!$phone_id||!$template||!$template_without_photo||!$summary_template||(!$token&&!get_option('hrim_whatsapp_access_token',''))))$this->back('hotel-inspection-communications','error','Phone Number ID, both New Issue Template Names, Summary Template Name and Access Token are required when WhatsApp notifications are enabled.');
        if($test_mobile && (strlen($test_mobile)<10||strlen($test_mobile)>15))$this->back('hotel-inspection-communications','error','Test Mobile Number is invalid.');
        update_option('hrim_whatsapp_enabled',$enabled?'1':'0',false);
        update_option('hrim_whatsapp_graph_version',$version,false);
        update_option('hrim_whatsapp_phone_number_id',$phone_id,false);
        update_option('hrim_whatsapp_waba_id',$waba_id,false);
        update_option('hrim_whatsapp_issue_template',$template,false);
        update_option('hrim_whatsapp_issue_template_without_photo',$template_without_photo,false);
        update_option('hrim_whatsapp_summary_template',$summary_template,false);
        update_option('hrim_whatsapp_language_code',$language?:'en_US',false);
        update_option('hrim_whatsapp_test_mobile',$test_mobile,false);
        if($token!=='')update_option('hrim_whatsapp_access_token',$token,false);
        $this->back('hotel-inspection-communications','saved','Meta / WhatsApp Business Settings saved successfully.');
    }
    public function test_whatsapp(){
        $this->guard_manage('hrim_test_whatsapp');
        $settings=$this->whatsapp_settings();
        $settings['template_name']=$settings['template_without_photo'];
        $result=$this->send_whatsapp_template($settings['test_mobile'],['#TEST','Test Department','Test Floor / Asset','Test Section','Test Checkpoint','This is a RoomScore WhatsApp setup test.'],$settings);
        update_option('hrim_whatsapp_last_result',['time'=>$this->ist_now_mysql(),'issue_id'=>0,'mobile'=>$settings['test_mobile'],'success'=>!is_wp_error($result),'message'=>is_wp_error($result)?$result->get_error_message():'Test message accepted by Meta.'],false);
        $this->back('hotel-inspection-communications',is_wp_error($result)?'error':'saved',is_wp_error($result)?$result->get_error_message():'Test WhatsApp message sent successfully.');
    }

    public function save_notification_settings(){
        $this->guard_manage('hrim_save_notification_settings');
        $section=sanitize_key($_POST['settings_section']??'');
        if($section==='telegram'){
            update_option('hrim_telegram_enabled',isset($_POST['telegram_enabled'])?'1':'0',false);
            update_option('hrim_telegram_test_chat_id',sanitize_text_field(wp_unslash($_POST['telegram_test_chat_id']??'')),false);
            $token=trim((string)wp_unslash($_POST['telegram_bot_token']??'')); if($token!=='')update_option('hrim_telegram_bot_token',$token,false);
            $this->back('hotel-inspection-communications','saved','Telegram settings saved successfully.');
        }
        $rules=[]; foreach(['pending_issues','upcoming_periodic','scheduled_not_started','scheduled_in_progress'] as $key){$posted=(array)($_POST['rules'][$key]??[]);$rules[$key]=['enabled'=>isset($posted['enabled'])?1:0,'interval_minutes'=>max(5,absint($posted['interval_minutes']??1440)),'days_before'=>max(0,absint($posted['days_before']??3)),'head'=>isset($posted['head'])?1:0,'telegram'=>isset($posted['telegram'])?1:0];}
        update_option('hrim_notifications_enabled',isset($_POST['notifications_enabled'])?'1':'0',false); update_option('hrim_notification_rules',$rules,false);
        if(!wp_next_scheduled('hrim_notification_cron'))wp_schedule_event(time()+60,'hrim_five_minutes','hrim_notification_cron');
        $this->back('hotel-inspection-communications','saved','Notification rules saved successfully.');
    }
    public function test_telegram(){
        $this->guard_manage('hrim_test_telegram'); $tg=$this->telegram_settings(); $result=$this->send_telegram_message($tg['test_chat_id'],'RoomScore Telegram setup test successful.');
        $this->back('hotel-inspection-communications',is_wp_error($result)?'error':'saved',is_wp_error($result)?$result->get_error_message():'Test Telegram message sent successfully.');
    }
    public function run_notifications_now(){ $this->guard_manage('hrim_run_notifications_now'); $this->run_notification_triggers(); $this->back('hotel-inspection-communications','saved','Notification trigger evaluation completed.'); }

    public function save_settings(){
        $this->guard_manage('hrim_save_settings');
        update_option('hrim_accent',sanitize_hex_color($_POST['accent']??'')?:'#2563eb');
        update_option('hrim_show_room_score',isset($_POST['show_room_score'])?'1':'0');
        update_option('hrim_show_floor_score',isset($_POST['show_floor_score'])?'1':'0');
        $this->back('hotel-inspection-settings','saved','Settings updated successfully.');
    }
    public function regenerate_pages(){ $this->guard_manage('hrim_regenerate_pages');HRIM_Activator::create_pages();$this->back('hotel-inspection-settings'); }


    public function install_checklist_library(){
        $this->guard_manage('hrim_install_checklist_library');
        // Minimal safety repair: ensure the section/question tables exist before importing.
        HRIM_Activator::create_tables();
        global $wpdb;
        $sections_table = $wpdb->prefix . 'hrim_sections';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sections_table)) !== $sections_table) {
            $this->dependency_error('hotel-inspection-settings', 'Checklist Sections table could not be created. Please check database CREATE/ALTER permissions.');
        }
        $library=[
            'Basement & Service Areas'=>['Basement floor is clean, dry and free from obstruction','Service passages are adequately illuminated','Drainage channels are clear and free flowing','No water seepage or damp patches are visible','Stored materials are properly stacked and labelled','Restricted areas are secured from unauthorised access','Pest-control signs or activity are not evident','Emergency exits are accessible and clearly marked','Ventilation is adequate and operational','Housekeeping chemicals are stored safely'],
            'Parking & Driveway'=>['Driveway is clean and free from potholes','Vehicle entry and exit lanes are clearly marked','Parking bays are properly marked and unobstructed','Valet parking area is organised','Lighting is adequate during day and night','Speed breakers and directional signs are visible','No oil leakage or standing water is present','Accessible parking spaces are available and clear','Fire access route is unobstructed','Parking security surveillance is operational'],
            'Exterior & Facade'=>['Building facade is clean and well maintained','Hotel signage is illuminated and clearly visible','External walls are free from cracks and stains','Windows and external glass are clean','Landscape and planters are maintained','Entrance canopy is clean and structurally sound','External lighting is functional','Flags and branding elements are in good condition','Rainwater outlets are clear','No loose external fittings are visible'],
            'Security & Gate'=>['Security personnel are present and properly equipped','Visitor and vehicle entry records are maintained','Baggage screening equipment is operational','CCTV cameras cover required entry points','Emergency contact numbers are displayed','Boom barrier or gate system is functioning','Security cabin is clean and organised','Suspicious-item response procedure is available','Staff access control is operational','Night patrol records are maintained'],
            'Main Entrance'=>['Entrance doors are clean and operating smoothly','Entrance mats are clean and properly positioned','Door handles and glass are free from marks','Wheelchair access is clear','Bell desk area is neat and attended','Lighting and signage are functional','No luggage or obstruction blocks the entrance','Umbrella stand is available when required','Entrance fragrance and ambience are acceptable','Air curtain or entrance climate control is operational'],
            'Lobby & Lounge'=>['Lobby floor is clean and polished','Seating furniture is clean and undamaged','Tables and decorative items are dust free','Lobby lighting is functional and suitable','Air conditioning is comfortable','Music volume is appropriate','Fresh flowers or decor are presentable','Public information and signage are current','Power outlets and charging points are safe','Lobby is free from unpleasant odour'],
            'Reception & Front Office'=>['Reception counter is clean and organised','Computers, printers and key systems are operational','Guest registration material is available','Cash and confidential documents are secured','Telephone lines are functioning','Queue area is orderly','Staff grooming and name badges meet standard','Rate and policy information is current','Emergency and important contacts are available','Back-office records are properly filed'],
            'Public Toilets'=>['Toilet entrance and signage are clear','Floor is clean, dry and non-slippery','Toilet bowls and urinals are sanitised','Wash basins and taps are clean and functional','Mirrors are clean and streak free','Soap dispensers are filled','Toilet paper is available','Hand dryer or paper towels are available','Dustbins are clean and not overflowing','Exhaust and odour control are effective','Accessible toilet facilities are usable','Door locks and partitions are functional'],
            'Lifts & Elevators'=>['Lift cabin floor and walls are clean','Control buttons are functional and legible','Door opening and closing are smooth','Emergency alarm and intercom are functional','Floor indicators display correctly','Lift certificate is current and displayed','Capacity signage is visible','Cabin lighting and ventilation are operational','No unusual noise or vibration is present','Lift lobby call buttons are functional'],
            'Staircases & Fire Exits'=>['Staircase steps and landings are clean','Handrails are secure','Emergency lighting is functional','Exit signage is illuminated','Fire doors close properly and are unobstructed','No storage is kept in staircases','Anti-slip strips are intact','Floor numbering is visible','Pressurisation or ventilation is operational where provided','Final exit door opens freely'],
            'Guest Corridors'=>['Corridor carpet or floor is clean','Walls, skirting and ceiling are free from damage','Lighting is fully functional','Room number signs are clear','Emergency exit signs are visible','Fire extinguishers and hose reels are accessible','No housekeeping carts block the passage','Air conditioning and ventilation are comfortable','Artwork and decor are properly aligned','Corridor is free from odour and noise disturbance'],
            'Guest Room Entrance'=>['Room number and signage are clearly visible','Door surface and frame are clean','Door lock and access card function correctly','Safety latch and peephole are operational','Door closer works smoothly','Do-not-disturb and service signs are available','Threshold and entrance floor are clean','No gap or damage is visible around the door','Evacuation plan is displayed behind the door','Door seals provide adequate privacy and noise control'],
            'Bedroom Cleanliness'=>['Floor or carpet is vacuumed and stain free','Walls and ceiling are clean','Corners and under-bed areas are dust free','Windows and glass are clean','Curtains and blinds are clean and aligned','Room is free from insects','Room is free from unpleasant odour','Dustbins are empty and sanitised','High-touch surfaces are disinfected','Balcony or window ledge is clean where applicable'],
            'Bed & Linen'=>['Bed is properly made to hotel standard','Bed linen is clean and stain free','Mattress is clean and in good condition','Mattress protector is fitted correctly','Pillows and pillowcases are fresh','Cushions and runners are properly arranged','Blanket or duvet is clean and undamaged','Bed base and headboard are clean','Extra linen is available as per standard','No hair or foreign material is present'],
            'Furniture & Fixtures'=>['Wardrobe is clean and odour free','Hangers are available in required quantity','Bedside tables are clean and stable','Writing desk and chair are clean','Luggage rack is functional','Drawers open and close smoothly','Mirror is clean and securely fixed','Curtain rods and fittings are secure','Sofa or additional seating is clean','All furniture is free from sharp edges or damage'],
            'Electrical & Lighting'=>['All room lights switch on correctly','Bedside reading lights are functional','Sockets are secure and operational','Master switch operates correctly','No exposed wires or loose fittings are present','Emergency light is functional where provided','Lampshades are clean and undamaged','Light colour and brightness are consistent','USB or charging ports function correctly','Distribution board or access panel is closed and safe'],
            'Air Conditioning & Ventilation'=>['Air conditioner starts and cools properly','Temperature controller responds correctly','Air filter and grille are clean','No abnormal noise or vibration is present','No water leakage or condensation is visible','Fresh-air or ventilation system is operational','Room temperature reaches set point','Remote control is available and working','Exhaust fans operate correctly','Air flow is free from unpleasant odour'],
            'Television & Entertainment'=>['Television powers on and displays clearly','Remote control is available and functional','All subscribed channels are available','Volume and picture controls work','Television screen and cabinet are clean','Channel guide or instructions are available','Casting or smart-TV features work where provided','Cables are concealed and secure','No screen damage or dead pixels are visible','Entertainment accessories are complete'],
            'Telephone & Internet'=>['Room telephone is clean and functional','Reception and emergency numbers connect correctly','Telephone directory is available','Wi-Fi signal strength is adequate','Internet login information is available','LAN port works where provided','Router or access point shows no fault','Wake-up call facility is operational','Charging cables or adapters are available where standard','Communication equipment is free from damage'],
            'Minibar & Refreshments'=>['Minibar refrigerator is clean and cooling','Minibar items match the standard list','All products are within expiry date','Product seals are intact','Price list is current and visible','Tea and coffee supplies are complete','Kettle or coffee machine is clean and functional','Cups, glasses and spoons are clean','Complimentary water is available','Minibar consumption record is ready for use'],
            'Bathroom'=>['Bathroom floor and walls are clean','Wash basin is clean and drains properly','Hot and cold water are available','Shower and hand shower are functional','Water pressure is adequate','Bathtub is clean and undamaged where provided','Mirror is clean and well lit','Bathroom door and lock are functional','Exhaust fan works correctly','No leakage or seepage is visible','Drain is clear and free from odour','Anti-slip mat is clean and available where standard'],
            'Toilet'=>['Toilet bowl is sanitised and stain free','Flush operates correctly','Toilet seat and cover are secure','Bidet spray is functional and leak free','Toilet paper is available and properly placed','Sanitary disposal bag is available','Toilet brush is clean and stored correctly','No blockage or backflow is present','Partition or privacy arrangement is adequate','Area is free from unpleasant odour'],
            'Bathroom Amenities'=>['Bath towels are clean and correctly placed','Hand and face towels are available','Bath mat is clean and dry','Soap and shampoo are replenished','Dental and shaving kits are available as per standard','Shower cap and vanity kit are available','Hair dryer is clean and functional','Tissue box is filled','Amenity containers are clean and sealed','Bathrobe and slippers are available where applicable'],
            'Housekeeping Final Room Check'=>['All checklist sections have been completed','Room setup matches the room category standard','Guest supplies are complete','All maintenance defects are reported','Lost-and-found items have been handled correctly','Room status is updated in the system','Curtains, lights and AC are set to arrival standard','Room door is locked after inspection','Final room photograph is captured if required','Room is ready for guest occupancy'],
            'Kitchen'=>['Food preparation surfaces are clean and sanitised','Raw and cooked food are stored separately','Refrigerator temperatures are within standard','Freezer temperatures are within standard','Food items are labelled and dated','No expired food is present','Chopping boards and knives are colour coded','Exhaust hoods and filters are clean','Floor drains are clear','Pest-control conditions are satisfactory','Staff follow hygiene and protective-wear requirements','Fire suppression and extinguishers are accessible'],
            'Restaurant & Dining'=>['Dining floor and carpets are clean','Tables and chairs are clean and stable','Table linen and settings meet standard','Crockery, cutlery and glassware are spotless','Buffet counters are clean and correctly labelled','Food temperature controls are maintained','Lighting and music are appropriate','Menus are clean and current','Handwash or sanitiser is available','Service stations are organised and stocked'],
            'Banquet & Meeting Rooms'=>['Hall floor, walls and ceiling are clean','Seating and table layout match the event plan','Audio-visual equipment is operational','Microphones and speakers are tested','Stage and podium are clean and safe','Emergency exits remain unobstructed','Lighting scenes work correctly','Air conditioning is comfortable','Washrooms and pre-function areas are ready','Event signage is correct and displayed'],
            'Laundry & Linen Room'=>['Clean and soiled linen are segregated','Laundry machines are clean and operational','Chemical containers are labelled and stored safely','Linen shelves are clean and organised','Ironing and pressing equipment are safe','Laundry floor is dry and non-slippery','Ventilation and exhaust are effective','Fire safety equipment is accessible','Damaged linen is separately recorded','Daily production and issue records are maintained'],
            'Housekeeping Pantry'=>['Pantry is clean and organised','Cleaning chemicals are labelled','Chemicals are stored away from guest amenities','Linen stocks are arranged by type','Housekeeping carts are clean and complete','Vacuum cleaners and machines are operational','Lost-and-found storage is secured','Pantry sink and drain are clean','Pest-control condition is satisfactory','Inventory records are updated'],
            'Engineering & Maintenance'=>['Preventive-maintenance schedule is current','Tools and spare parts are organised','Electrical panels are labelled and closed','Generator records are updated','Boiler and hot-water systems are operational','Pump rooms are clean and dry','Air-conditioning plant readings are normal','Maintenance complaints are tracked','Safety permits and lockout procedures are followed','Critical equipment has service records'],
            'Fire & Life Safety'=>['Fire extinguishers are charged and within validity','Fire hose reels are accessible','Smoke detectors are unobstructed','Fire alarm panel shows normal status','Emergency exits are unlocked and clear','Exit signage and emergency lights work','Sprinkler heads are unobstructed','Fire doors close and latch properly','Evacuation plans are displayed','Fire drill and training records are current','Assembly point signage is visible','Public-address emergency system is operational'],
            'Water, Plumbing & Sewage'=>['Water tanks are covered and secured','Water-quality testing records are current','Pumps operate without leakage','Plumbing lines show no visible leakage','Sewage-treatment area is clean and controlled','Drainage is free flowing','Hot-water temperature is within standard','Water meters and readings are maintained','Backflow prevention is functional where provided','No foul smell is present in service areas'],
            'Swimming Pool'=>['Pool water is clear and free from debris','Chlorine and pH readings are within standard','Pool deck is clean and non-slippery','Depth markings are visible','Life-saving equipment is available','Pool lights and circulation system work','Changing rooms and showers are clean','Pool towels are available','Warning and operating-hours signs are displayed','Pool plant room is maintained'],
            'Gym, Spa & Wellness'=>['Exercise equipment is clean and operational','Safety instructions are displayed','Floor and mirrors are clean','Towels and drinking water are available','Changing rooms and lockers are clean','Spa treatment rooms are sanitised','Steam and sauna operate correctly where provided','Emergency call facility is available','Music, lighting and temperature are appropriate','Consumables are within expiry date'],
            'Staff Areas'=>['Staff entrance and attendance area are orderly','Changing rooms and lockers are clean','Staff toilets and wash areas are hygienic','Canteen is clean and food is safely handled','Notice boards contain current information','Uniform storage is organised','Staff rest area is clean','Drinking water is available','Emergency exits are clear','Personal belongings are secured'],
            'Terrace & Rooftop'=>['Terrace floor is clean and free from standing water','Parapet and railings are secure','Roof drains and rainwater outlets are clear','Water tanks and equipment are secured','Solar panels or rooftop equipment are clean','Access doors are locked when not in use','Lightning protection appears intact','No loose material can fall from the roof','Rooftop lighting is functional','Fire and emergency access is maintained']
        ];
        $sections_created=0; $sections_updated=0; $points_created=0; $points_existing=0; $sort=10;
        foreach($library as $section_name=>$questions){
            $section_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_sections WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) LIMIT 1",$section_name));
            if(!$section_id){
                $ok=$wpdb->insert($wpdb->prefix.'hrim_sections',['name'=>$section_name,'description'=>'BizSkill standard hotel inspection library','sort_order'=>$sort,'status'=>'active']);
                if(!$ok) $this->dependency_error('hotel-inspection-settings','Checklist library could not create section: '.$section_name.'. '.$wpdb->last_error);
                $section_id=(int)$wpdb->insert_id; $sections_created++;
            } else {
                $wpdb->update($wpdb->prefix.'hrim_sections',['status'=>'active'],['id'=>$section_id]); $sections_updated++;
            }
            $point_order=10;
            foreach($questions as $question){
                $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_questions WHERE section_id=%d AND is_library=1 AND LOWER(TRIM(question))=LOWER(TRIM(%s)) LIMIT 1",$section_id,$question));
                if($existing){ $points_existing++; $point_order+=10; continue; }
                $ok=$wpdb->insert($wpdb->prefix.'hrim_questions',['template_id'=>0,'section_id'=>$section_id,'section_name'=>$section_name,'question'=>$question,'question_type'=>'yes_no','required_flag'=>1,'is_library'=>1,'sort_order'=>$point_order]);
                if(!$ok) $this->dependency_error('hotel-inspection-settings','Checklist library could not create a checkpoint under '.$section_name.'. '.$wpdb->last_error);
                $points_created++; $point_order+=10;
            }
            $sort+=10;
        }
        update_option('hrim_checklist_library_version','1.0');
        $this->audit('Checklist Library','install',0,['sections_created'=>$sections_created,'sections_existing'=>$sections_updated,'points_created'=>$points_created,'points_existing'=>$points_existing]);
        $this->back('hotel-inspection-settings','saved',sprintf('Detailed hotel checklist library installed: %d sections created, %d existing sections retained, %d checkpoints created and %d existing checkpoints retained. You can edit, deactivate or delete unused items from Checklist Sections.', $sections_created,$sections_updated,$points_created,$points_existing));
    }

    public function install_dummy_data(){
        $this->guard_manage('hrim_install_dummy_data'); global $wpdb;
        $template_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_templates WHERE name=%s LIMIT 1",'Standard Room Inspection'));
        if(!$template_id){
            $wpdb->insert($wpdb->prefix.'hrim_templates',['name'=>'Standard Room Inspection','description'=>'Dummy room inspection template for housekeeping and room quality checks.','status'=>'active']);
            $template_id=(int)$wpdb->insert_id;
        }
        $questions=[
            ['Room Readiness','Room entrance and door are clean and functioning properly'],['Room Readiness','Floor, walls and ceiling are clean and free from damage'],['Bed & Linen','Bed linen is clean, fresh and properly arranged'],['Bed & Linen','Pillows, cushions and mattress are in good condition'],['Bathroom','Bathroom, washbasin and toilet are clean and sanitised'],['Bathroom','Towels and toiletries are available as per standard'],['Amenities','Lighting, television, telephone and electrical points are working'],['Amenities','Air conditioning is working and set appropriately'],['Safety','Smoke detector, locks and safety equipment are in order'],['Final Check','Room is free from odour and ready for guest occupancy']
        ];
        $section_ids=[];
        foreach($questions as $q){
            if(isset($section_ids[$q[0]])) continue;
            $sid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_sections WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) LIMIT 1",$q[0]));
            if(!$sid){ $wpdb->insert($wpdb->prefix.'hrim_sections',['name'=>$q[0],'description'=>'Standard inspection section','sort_order'=>count($section_ids)+1,'status'=>'active']); $sid=(int)$wpdb->insert_id; }
            $section_ids[$q[0]]=$sid;
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}hrim_template_sections (template_id,section_id,sort_order) VALUES (%d,%d,%d)",$template_id,$sid,count($section_ids)));
        }
        foreach($questions as $i=>$q){
            $sid=$section_ids[$q[0]]??0;
            $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_questions WHERE section_id=%d AND is_library=1 AND LOWER(TRIM(question))=LOWER(TRIM(%s)) LIMIT 1",$sid,$q[1]));
            if(!$exists)$wpdb->insert($wpdb->prefix.'hrim_questions',['template_id'=>0,'section_id'=>$sid,'section_name'=>$q[0],'question'=>$q[1],'question_type'=>'yes_no','required_flag'=>1,'is_library'=>1,'sort_order'=>$i+1]);
        }
        $category_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_room_categories WHERE name=%s LIMIT 1",'Standard Room'));
        if(!$category_id){ $wpdb->insert($wpdb->prefix.'hrim_room_categories',['name'=>'Standard Room','description'=>'Standard guest room category','status'=>'active']); $category_id=(int)$wpdb->insert_id; }
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}hrim_category_templates (category_id,template_id) VALUES (%d,%d)",$category_id,$template_id));
        $floors=$wpdb->get_results("SELECT f.*,h.name hotel_name FROM {$wpdb->prefix}hrim_floors f INNER JOIN {$wpdb->prefix}hrim_hotels h ON h.id=f.hotel_id WHERE LOWER(TRIM(COALESCE(f.status,'active')))='active' AND LOWER(TRIM(COALESCE(h.status,'active')))='active' ORDER BY f.hotel_id,f.sort_order,f.id");
        if(!$floors){
            $floors=$wpdb->get_results("SELECT f.*,h.name hotel_name FROM {$wpdb->prefix}hrim_floors f INNER JOIN {$wpdb->prefix}hrim_hotels h ON h.id=f.hotel_id ORDER BY f.hotel_id,f.sort_order,f.id");
        }
        $created=0; $assigned=0; $relinked=0;
        foreach($floors as $f){
            $prefix=$this->dummy_room_prefix($f->name,$f->sort_order,$f->id);
            for($i=1;$i<=10;$i++){
                $room_no=$prefix.str_pad((string)$i,2,'0',STR_PAD_LEFT);
                $room=$wpdb->get_row($wpdb->prepare("SELECT id,category_id,floor_id FROM {$wpdb->prefix}hrim_rooms WHERE hotel_id=%d AND room_no=%s LIMIT 1",$f->hotel_id,$room_no));
                if($room){ $update=[]; if(empty($room->category_id)){ $update['category_id']=$category_id; $update['category']='Standard Room'; $assigned++; } if((int)$room->floor_id !== (int)$f->id){ $update['floor_id']=$f->id; $relinked++; } if($update)$wpdb->update($wpdb->prefix.'hrim_rooms',$update,['id'=>$room->id]); continue; }
                $ok=$wpdb->insert($wpdb->prefix.'hrim_rooms',['hotel_id'=>$f->hotel_id,'floor_id'=>$f->id,'category_id'=>$category_id,'room_no'=>$room_no,'category'=>'Standard Room','status'=>'active']);
                if($ok)$created++;
            }
        }
        $this->back('hotel-inspection-settings','saved',sprintf('Dummy setup completed for %d floors: template ready, %d rooms created, %d existing rooms assigned and %d rooms relinked.',count($floors),$created,$assigned,$relinked));
    }
    private function dummy_room_prefix($floor_name,$sort_order,$floor_id){
        if(preg_match('/(\d+)/',(string)$floor_name,$m)) return (string)(int)$m[1];
        if(stripos((string)$floor_name,'ground')!==false) return 'G';
        $n=(int)$sort_order; if($n<=0)$n=(int)$floor_id; return (string)$n;
    }

    public function clear_dummy_data(){
        $this->guard_manage('hrim_clear_dummy_data'); global $wpdb;
        $template_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_templates WHERE name=%s LIMIT 1",'Standard Room Inspection'));
        $deleted_rooms=0;
        if($template_id){
            $room_ids=$wpdb->get_col($wpdb->prepare("SELECT r.id FROM {$wpdb->prefix}hrim_rooms r INNER JOIN {$wpdb->prefix}hrim_room_categories c ON c.id=r.category_id INNER JOIN {$wpdb->prefix}hrim_category_templates ct ON ct.category_id=c.id WHERE ct.template_id=%d AND c.name=%s AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}hrim_inspections i WHERE i.room_id=r.id)",$template_id,'Standard Room'));
            foreach($room_ids as $rid){ $x=$wpdb->delete($wpdb->prefix.'hrim_rooms',['id'=>(int)$rid]); if($x)$deleted_rooms++; }
            $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_inspections WHERE template_id=%d",$template_id));
            $linked=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_category_templates WHERE template_id=%d",$template_id));
            if(!$used && !$linked){ $wpdb->delete($wpdb->prefix.'hrim_questions',['template_id'=>$template_id]); $wpdb->delete($wpdb->prefix.'hrim_templates',['id'=>$template_id]); }
        }
        $this->back('hotel-inspection-settings','saved',sprintf('Dummy cleanup completed. %d unused dummy rooms removed.',$deleted_rooms));
    }

    public function repair_application(){
        $this->guard_manage('hrim_repair_application');
        HRIM_Activator::create_roles(); HRIM_Activator::create_tables(); HRIM_Activator::create_pages();
        update_option('hrim_db_version',HRIM_Activator::DB_VERSION);
        $this->back('hotel-inspection-settings','saved','Application tables, roles and pages repaired successfully.');
    }

    public function ajax_get_rooms(){
        check_ajax_referer('hrim_ajax','nonce'); if(!is_user_logged_in())wp_send_json_error(); global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT r.id,r.room_no,f.name floor_name,GROUP_CONCAT(DISTINCT x.template_id ORDER BY x.template_id) template_ids FROM {$wpdb->prefix}hrim_rooms r LEFT JOIN {$wpdb->prefix}hrim_floors f ON f.id=r.floor_id LEFT JOIN (SELECT room_id,template_id FROM {$wpdb->prefix}hrim_room_templates UNION SELECT rr.id room_id,ct.template_id FROM {$wpdb->prefix}hrim_rooms rr INNER JOIN {$wpdb->prefix}hrim_category_templates ct ON ct.category_id=rr.category_id WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}hrim_room_template_exclusions ex WHERE ex.room_id=rr.id AND ex.template_id=ct.template_id)) x ON x.room_id=r.id WHERE r.hotel_id=%d AND r.status='active' GROUP BY r.id ORDER BY f.sort_order,r.room_no",absint($_POST['hotel_id'])));
        wp_send_json_success($rows);
    }
    public function ajax_get_room_checklist(){
        check_ajax_referer('hrim_ajax','nonce');
        if(!is_user_logged_in())wp_send_json_error('Please log in again.');
        global $wpdb;
        $room_id=absint($_POST['room_id']??0);
        $room=$wpdb->get_row($wpdb->prepare("SELECT id,category_id FROM {$wpdb->prefix}hrim_rooms WHERE id=%d AND status='active'",$room_id));
        if(!$room)wp_send_json_error('Invalid room.');
        $template_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT template_id FROM (SELECT template_id FROM {$wpdb->prefix}hrim_room_templates WHERE room_id=%d UNION SELECT ct.template_id FROM {$wpdb->prefix}hrim_category_templates ct WHERE ct.category_id=%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}hrim_room_template_exclusions x WHERE x.room_id=%d AND x.template_id=ct.template_id)) permitted ORDER BY template_id",$room_id,(int)$room->category_id,$room_id)));
        if(!$template_ids)wp_send_json_success([]);
        $ph=implode(',',array_fill(0,count($template_ids),'%d'));
        $templates=$wpdb->get_results($wpdb->prepare("SELECT id,name,description FROM {$wpdb->prefix}hrim_templates WHERE status='active' AND id IN ($ph) ORDER BY name",$template_ids));
        $out=[];
        foreach($templates as $t){
            $questions=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT q.id,%d template_id,q.section_id,s.name section_name,q.question,q.question_type,q.required_flag,q.sort_order FROM {$wpdb->prefix}hrim_template_sections ts INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=ts.section_id AND s.status='active' INNER JOIN {$wpdb->prefix}hrim_questions q ON q.section_id=s.id AND q.is_library=1 LEFT JOIN {$wpdb->prefix}hrim_room_question_exclusions rx ON rx.room_id=%d AND rx.question_id=q.id WHERE ts.template_id=%d AND rx.question_id IS NULL UNION ALL SELECT q.id,%d template_id,q.section_id,s.name section_name,q.question,q.question_type,q.required_flag,q.sort_order FROM {$wpdb->prefix}hrim_room_question_additions a INNER JOIN {$wpdb->prefix}hrim_questions q ON q.id=a.question_id INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=q.section_id WHERE a.room_id=%d AND a.template_id=%d ORDER BY section_name,sort_order,id",$t->id,$room_id,$t->id,$t->id,$room_id,$t->id));
            $out[]=['id'=>(int)$t->id,'name'=>$t->name,'description'=>$t->description,'questions'=>$questions];
        }
        wp_send_json_success($out);
    }

    public function ajax_get_questions(){ check_ajax_referer('hrim_ajax','nonce');if(!is_user_logged_in())wp_send_json_error();global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT q.*,COALESCE(s.name,q.section_name) section_display FROM {$wpdb->prefix}hrim_questions q LEFT JOIN {$wpdb->prefix}hrim_sections s ON s.id=q.section_id INNER JOIN {$wpdb->prefix}hrim_template_sections ts ON ts.section_id=q.section_id WHERE ts.template_id=%d AND q.is_library=1 ORDER BY ts.sort_order,COALESCE(s.sort_order,999999),COALESCE(s.name,q.section_name),q.sort_order,q.id",absint($_POST['template_id'])));wp_send_json_success($rows); }
    public function ajax_upload_video(){
        check_ajax_referer('hrim_ajax','nonce');
        if(!is_user_logged_in()) wp_send_json_error('Login required.');
        if(empty($_FILES['file'])) wp_send_json_error('Video file is required.');
        $duration=floatval($_POST['duration']??0);
        if($duration<=0 || $duration>60.5) wp_send_json_error('Video duration cannot exceed 1 minute.');
        if((int)($_FILES['file']['size']??0)>40*1024*1024) wp_send_json_error('Video size cannot exceed 40 MB.');
        $allowed=['video/mp4','video/quicktime','video/webm'];
        $type=(string)($_FILES['file']['type']??'');
        if($type && !in_array($type,$allowed,true)) wp_send_json_error('Please upload MP4, MOV or WebM video.');
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $id=media_handle_upload('file',0);
        if(is_wp_error($id)) wp_send_json_error($id->get_error_message());
        update_post_meta($id,'_hrim_video_duration',min(60,$duration));
        wp_send_json_success(['id'=>$id,'url'=>wp_get_attachment_url($id),'duration'=>$duration]);
    }
    public function ajax_upload_image(){ check_ajax_referer('hrim_ajax','nonce');if(!is_user_logged_in())wp_send_json_error();require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';$id=media_handle_upload('file',0);if(is_wp_error($id))wp_send_json_error($id->get_error_message());wp_send_json_success(['id'=>$id,'url'=>wp_get_attachment_url($id)]); }
    public function clear_audit_log(){
        $this->guard_manage('hrim_clear_audit_log');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}hrim_audit_log");
        $this->back('hotel-inspection-audit-log','saved','Audit log cleared successfully.');
    }
    private function audit($module,$action,$record_id=0,$details=[]){
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'hrim_audit_log',[
            'user_id'=>get_current_user_id()?:null,
            'module'=>sanitize_text_field($module),
            'action_name'=>sanitize_key($action),
            'record_id'=>$record_id?absint($record_id):null,
            'details'=>wp_json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'ip_address'=>sanitize_text_field($_SERVER['REMOTE_ADDR']??''),
            'user_agent'=>sanitize_textarea_field($_SERVER['HTTP_USER_AGENT']??''),
        ]);
    }

    private function guard_manage($nonce){ if(!$this->can_manage())wp_die('Permission denied');check_admin_referer($nonce); }
    private function issue_back($form,$issue_id,$status,$message){
        $url=add_query_arg([
            $status=>'1',
            'hrim_msg'=>rawurlencode($message),
            'hrim_form'=>sanitize_key($form),
            'hrim_issue_id'=>absint($issue_id),
            'hrim_refresh'=>time(),
        ],$this->page_url('hotel-inspection-issues'));
        wp_safe_redirect($url,303);
        exit;
    }

    private function back($slug,$status='saved',$message='Saved successfully.',$extra_args=[]){
        $args=[
            $status=>'1',
            'hrim_msg'=>rawurlencode($message),
            'hrim_refresh'=>time(),
        ];
        if(is_array($extra_args) && $extra_args){
            foreach($extra_args as $key=>$value){
                $key=sanitize_key($key);
                if($key==='')continue;
                $args[$key]=is_scalar($value)?$value:'';
            }
        }
        $url=add_query_arg($args,$this->page_url($slug));
        wp_safe_redirect($url,303);
        exit;
    }
    private function login_notice(){ return '<script>window.location.replace('.wp_json_encode($this->page_url('hotel-inspection-login')).');</script>'; }
}
