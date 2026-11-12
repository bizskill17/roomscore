<?php
if (!defined('ABSPATH')) exit;

class HRIM_Activator {
    const DB_VERSION = '3.19.39';

    public static function activate() {
        self::create_roles();
        self::create_tables();
        self::create_pages();
        update_option('hrim_db_version', self::DB_VERSION);
        if (!wp_next_scheduled('hrim_notification_cron')) wp_schedule_event(time()+300, 'hrim_five_minutes', 'hrim_notification_cron');
        flush_rewrite_rules();
    }

    public static function deactivate() { $ts=wp_next_scheduled('hrim_notification_cron'); if($ts) wp_unschedule_event($ts,'hrim_notification_cron'); flush_rewrite_rules(); }

    public static function page_definitions() {
        return [
            'hotel-inspection-login'      => ['Login', '[hrim_login]'],
            'hotel-inspection-dashboard'  => ['Dashboard', '[hrim_dashboard]'],
            'hotel-inspection-room-scores' => ['Room Score', '[hrim_room_scores]'],
            'hotel-inspection-floors'     => ['Floors', '[hrim_floors]'],
            'hotel-inspection-asset-types' => ['Asset Types', '[hrim_asset_types]'],
            'hotel-inspection-rooms'      => ['Assets', '[hrim_rooms]'],
            'hotel-inspection-checklist-sections' => ['Checklist Sections', '[hrim_sections]'],
            'hotel-inspection-sectionwise-checklist' => ['Sectionwise Checklists', '[hrim_sectionwise_checklist]'],
            'perform-room-inspection'     => ['Perform Inspection', '[hrim_inspection_form]'],
            'hotel-inspection-in-progress' => ['Inspection In Progress', '[hrim_inspection_in_progress]'],
            'hotel-inspection-schedule-tasks-in-progress' => ['Due Periodic Tasks', '[hrim_schedule_tasks_in_progress]'],
            'hotel-inspection-schedule-tasks-history' => ['Upcoming Periodic Tasks', '[hrim_schedule_tasks_history]'],
            'hotel-inspection-periodic-tasks' => ['Periodic Task', '[hrim_periodic_tasks]'],
            'hotel-inspection-add-periodic-task' => ['Add Periodic Task', '[hrim_add_periodic_task]'],
            'hotel-inspection-update-periodic-task' => ['Update Periodic Task', '[hrim_update_periodic_task]'],
            'hotel-inspection-add-occupancy' => ['Add Occupancy', '[hrim_add_occupancy]'],
            'hotel-inspection-update-office-count' => ['Update Office Count', '[hrim_update_office_count]'],
            'hotel-inspection-occupancy-check' => ['Occupancy Check', '[hrim_occupancy_check]'],
            'hotel-inspection-history'    => ['Inspection History', '[hrim_history]'],
            'hotel-inspection-add-issue'  => ['Add Issue', '[hrim_add_issue]'],
            'hotel-inspection-issues'     => ['Issues', '[hrim_issues]'],
            'hotel-inspection-pending-assign-issues' => ['Pending Assign Issue', '[hrim_pending_assign_issues]'],
            'hotel-inspection-pending-issues' => ['Pending Issues', '[hrim_pending_issues]'],
            'hotel-inspection-pending-supervision' => ['Pending Supervision', '[hrim_pending_supervision]'],
            'hotel-inspection-resolved-issues' => ['Issue Resolved', '[hrim_resolved_issues]'],
            'hotel-inspection-reports'    => ['Reports', '[hrim_reports]'],
            'hotel-inspection-learn'      => ['Learn', '[hrim_learn]'],
            'hotel-inspection-departments' => ['Departments', '[hrim_departments]'],
            'hotel-inspection-users'      => ['Users', '[hrim_users]'],
            'hotel-inspection-profile'    => ['My Profile', '[hrim_profile]'],
            'hotel-inspection-communications' => ['Communications', '[hrim_communications]'],
            'hotel-inspection-settings'   => ['Settings', '[hrim_settings]'],
            'hotel-inspection-audit-log'  => ['Audit Log', '[hrim_audit_log]'],
        ];
    }

    public static function create_pages() {
        $ids = (array) get_option('hrim_page_ids', []);
        foreach (self::page_definitions() as $slug => $data) {
            $page = get_page_by_path($slug, OBJECT, 'page');
            if (!$page) {
                $id = wp_insert_post([
                    'post_title'   => $data[0],
                    'post_name'    => $slug,
                    'post_content' => $data[1],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'meta_input'   => ['_hrim_app_page' => '1'],
                ]);
            } else {
                $id = $page->ID;
                update_post_meta($id, '_hrim_app_page', '1');
                if (trim($page->post_content) !== $data[1]) {
                    wp_update_post(['ID' => $id, 'post_content' => $data[1]]);
                }
            }
            if (!is_wp_error($id)) $ids[$slug] = (int) $id;
        }
        // The Template master was retired in v3.19.00. Remove its generated
        // application page so it cannot remain accessible after an upgrade.
        $retired_template_page = get_page_by_path('hotel-inspection-templates', OBJECT, 'page');
        if ($retired_template_page && get_post_meta($retired_template_page->ID, '_hrim_app_page', true) === '1') {
            wp_delete_post($retired_template_page->ID, true);
        }
        unset($ids['hotel-inspection-templates']);
        update_option('hrim_page_ids', $ids);
    }

    public static function create_roles() {
        add_role('hrim_owner', 'Owner', [
            'read'=>true, 'upload_files'=>true, 'hrim_manage_all'=>true,
            'hrim_perform_inspections'=>true, 'hrim_manage_issues'=>true, 'hrim_view_reports'=>true,
        ]);
        add_role('hrim_manager', 'Manager', [
            'read'=>true, 'upload_files'=>true, 'hrim_manage_all'=>true,
            'hrim_perform_inspections'=>true, 'hrim_manage_issues'=>true, 'hrim_view_reports'=>true,
        ]);
        add_role('hrim_supervisor', 'Supervisor', [
            'read'=>true, 'upload_files'=>true, 'hrim_perform_inspections'=>true,
            'hrim_manage_issues'=>true, 'hrim_view_reports'=>true,
        ]);
        add_role('hrim_worker', 'Worker', [
            'read'=>true, 'upload_files'=>true, 'hrim_perform_inspections'=>true,
            'hrim_manage_issues'=>true,
        ]);
        // Legacy roles are retained for backward compatibility.
        add_role('hrim_inspector', 'Inspector (Legacy)', [
            'read'=>true, 'upload_files'=>true, 'hrim_perform_inspections'=>true, 'hrim_view_reports'=>true,
        ]);
        add_role('hrim_housekeeping', 'Housekeeping (Legacy)', [
            'read'=>true, 'upload_files'=>true, 'hrim_manage_issues'=>true,
        ]);
        $admin = get_role('administrator');
        if ($admin) foreach (['hrim_manage_all','hrim_perform_inspections','hrim_manage_issues','hrim_view_reports'] as $cap) $admin->add_cap($cap);
    }

    public static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = [];
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_hotels (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,name VARCHAR(190) NOT NULL,address TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_floors (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,hotel_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,floor_manager_id BIGINT UNSIGNED NULL,sort_order INT NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'active',PRIMARY KEY(id),KEY hotel_id(hotel_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_asset_types (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,name VARCHAR(190) NOT NULL,description TEXT NULL,sort_order INT NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY unique_name(name)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_room_categories (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,name VARCHAR(190) NOT NULL,description TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY unique_name(name)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_rooms (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,hotel_id BIGINT UNSIGNED NOT NULL,floor_id BIGINT UNSIGNED NOT NULL,asset_type_id BIGINT UNSIGNED NULL,category_id BIGINT UNSIGNED NULL,template_id BIGINT UNSIGNED NULL,room_no VARCHAR(100) NOT NULL,category VARCHAR(190) NULL,status VARCHAR(30) NOT NULL DEFAULT 'active',PRIMARY KEY(id),KEY hotel_id(hotel_id),KEY floor_id(floor_id),KEY asset_type_id(asset_type_id),KEY category_id(category_id),KEY template_id(template_id),UNIQUE KEY unique_room(hotel_id,room_no)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_category_templates (category_id BIGINT UNSIGNED NOT NULL,template_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(category_id,template_id),KEY template_id(template_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_room_templates (room_id BIGINT UNSIGNED NOT NULL,template_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(room_id,template_id),KEY template_id(template_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_room_sections (room_id BIGINT UNSIGNED NOT NULL,section_id BIGINT UNSIGNED NOT NULL,sort_order INT NOT NULL DEFAULT 0,PRIMARY KEY(room_id,section_id),KEY section_id(section_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_room_template_exclusions (room_id BIGINT UNSIGNED NOT NULL,template_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(room_id,template_id),KEY template_id(template_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_room_question_exclusions (room_id BIGINT UNSIGNED NOT NULL,question_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(room_id,question_id),KEY question_id(question_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_room_question_removals (room_id BIGINT UNSIGNED NOT NULL,question_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(room_id,question_id),KEY question_id(question_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_room_question_additions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,room_id BIGINT UNSIGNED NOT NULL,template_id BIGINT UNSIGNED NOT NULL,question_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(id),UNIQUE KEY room_question(room_id,question_id),KEY room_id(room_id),KEY template_id(template_id),KEY question_id(question_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_templates (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,name VARCHAR(190) NOT NULL,description TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_template_sections (template_id BIGINT UNSIGNED NOT NULL,section_id BIGINT UNSIGNED NOT NULL,sort_order INT NOT NULL DEFAULT 0,PRIMARY KEY(template_id,section_id),KEY section_id(section_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_sections (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,name VARCHAR(190) NOT NULL,department_id BIGINT UNSIGNED NULL,description TEXT NULL,sort_order INT NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY unique_name(name)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_departments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,name VARCHAR(190) NOT NULL,department_head_user_id BIGINT UNSIGNED NULL,description TEXT NULL,sort_order INT NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY unique_name(name),KEY department_head_user_id(department_head_user_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_notification_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,event_key VARCHAR(190) NULL,notification_type VARCHAR(60) NOT NULL,department_id BIGINT UNSIGNED NULL,channel VARCHAR(30) NOT NULL,destination VARCHAR(190) NULL,message LONGTEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',api_response LONGTEXT NULL,attempts INT NOT NULL DEFAULT 0,scheduled_at DATETIME NULL,sent_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY event_key(event_key),KEY notification_type(notification_type),KEY department_id(department_id),KEY status(status),KEY created_at(created_at)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_user_departments (user_id BIGINT UNSIGNED NOT NULL,department_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id,department_id),KEY department_id(department_id)) $c;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_periodic_tasks (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,task_name VARCHAR(190) NOT NULL,asset_type_id BIGINT UNSIGNED NULL,periodicity VARCHAR(30) NOT NULL,start_date DATE NOT NULL,department_id BIGINT UNSIGNED NOT NULL,assigned_to BIGINT UNSIGNED NOT NULL,reminder_value INT NOT NULL DEFAULT 1,reminder_unit VARCHAR(20) NOT NULL DEFAULT 'days',status VARCHAR(20) NOT NULL DEFAULT 'active',created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,PRIMARY KEY(id),KEY asset_type_id(asset_type_id),KEY department_id(department_id),KEY assigned_to(assigned_to),KEY status(status)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_periodic_task_categories (task_id BIGINT UNSIGNED NOT NULL,category_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(task_id,category_id),KEY category_id(category_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_periodic_task_sections (task_id BIGINT UNSIGNED NOT NULL,section_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(task_id,section_id),KEY section_id(section_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_periodic_task_question_exclusions (task_id BIGINT UNSIGNED NOT NULL,question_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(task_id,question_id),KEY question_id(question_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_periodic_task_instances (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,task_id BIGINT UNSIGNED NOT NULL,due_date DATE NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'upcoming',assigned_to BIGINT UNSIGNED NULL,started_at DATETIME NULL,completed_at DATETIME NULL,completed_by BIGINT UNSIGNED NULL,verified_at DATETIME NULL,verified_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,PRIMARY KEY(id),UNIQUE KEY task_due(task_id,due_date),KEY status(status),KEY due_date(due_date)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_periodic_task_rooms (instance_id BIGINT UNSIGNED NOT NULL,room_id BIGINT UNSIGNED NOT NULL,category_id BIGINT UNSIGNED NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',remarks TEXT NULL,photo_attachment_id BIGINT UNSIGNED NULL,completed_at DATETIME NULL,completed_by BIGINT UNSIGNED NULL,updated_at DATETIME NULL,PRIMARY KEY(instance_id,room_id),KEY room_id(room_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_periodic_task_update_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,instance_id BIGINT UNSIGNED NOT NULL,task_id BIGINT UNSIGNED NOT NULL,room_id BIGINT UNSIGNED NOT NULL,category_id BIGINT UNSIGNED NULL,section_id BIGINT UNSIGNED NULL,question_id BIGINT UNSIGNED NULL,answer VARCHAR(10) NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',remarks TEXT NULL,photo_attachment_id BIGINT UNSIGNED NULL,completed_at DATETIME NULL,next_action_date DATE NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY instance_id(instance_id),KEY task_id(task_id),KEY room_id(room_id),KEY question_id(question_id),KEY updated_by(updated_by),KEY updated_at(updated_at)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_periodic_task_answers (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,instance_id BIGINT UNSIGNED NOT NULL,room_id BIGINT UNSIGNED NOT NULL,section_id BIGINT UNSIGNED NOT NULL,question_id BIGINT UNSIGNED NOT NULL,answer VARCHAR(10) NULL,remarks TEXT NULL,photo_attachment_id BIGINT UNSIGNED NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NULL,PRIMARY KEY(id),UNIQUE KEY answer_unique(instance_id,room_id,question_id),KEY instance_id(instance_id),KEY room_id(room_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_questions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,section_id BIGINT UNSIGNED NULL,department_id BIGINT UNSIGNED NULL,section_name VARCHAR(190) NULL,question TEXT NOT NULL,question_type VARCHAR(30) NOT NULL DEFAULT 'yes_no',required_flag TINYINT(1) NOT NULL DEFAULT 0,is_library TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,PRIMARY KEY(id),KEY template_id(template_id),KEY section_id(section_id),KEY department_id(department_id),KEY is_library(is_library)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_inspections (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,hotel_id BIGINT UNSIGNED NOT NULL,floor_id BIGINT UNSIGNED NOT NULL,room_id BIGINT UNSIGNED NOT NULL,template_id BIGINT UNSIGNED NOT NULL,inspector_id BIGINT UNSIGNED NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'completed',overall_score DECIMAL(8,2) NULL,remarks TEXT NULL,attachment_ids TEXT NULL,submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,started_at DATETIME NULL,last_saved_at DATETIME NULL,completed_at DATETIME NULL,draft_batch_id VARCHAR(64) NULL,PRIMARY KEY(id),KEY room_id(room_id),KEY inspector_id(inspector_id),KEY submitted_at(submitted_at),KEY draft_batch_id(draft_batch_id),KEY status(status)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_answers (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,inspection_id BIGINT UNSIGNED NOT NULL,question_id BIGINT UNSIGNED NOT NULL,answer_text LONGTEXT NULL,result_status VARCHAR(20) NULL,remarks TEXT NULL,attachment_ids TEXT NULL,video_attachment_ids TEXT NULL,PRIMARY KEY(id),KEY inspection_id(inspection_id),KEY question_id(question_id)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_issues (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,source VARCHAR(20) NOT NULL DEFAULT 'inspection',inspection_id BIGINT UNSIGNED NULL,hotel_id BIGINT UNSIGNED NULL,floor_id BIGINT UNSIGNED NULL,room_id BIGINT UNSIGNED NULL,section_id BIGINT UNSIGNED NULL,department_id BIGINT UNSIGNED NULL,question_id BIGINT UNSIGNED NULL,title VARCHAR(255) NOT NULL,description TEXT NULL,priority VARCHAR(20) NOT NULL DEFAULT 'medium',status VARCHAR(30) NOT NULL DEFAULT 'open',assigned_to BIGINT UNSIGNED NULL,due_date DATE NULL,reported_by BIGINT UNSIGNED NULL,resolution_remarks TEXT NULL,attachment_ids TEXT NULL,resolution_attachment_ids TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,resolved_at DATETIME NULL,resolved_by BIGINT UNSIGNED NULL,verified_at DATETIME NULL,verified_by BIGINT UNSIGNED NULL,PRIMARY KEY(id),KEY inspection_id(inspection_id),KEY hotel_id(hotel_id),KEY floor_id(floor_id),KEY room_id(room_id),KEY section_id(section_id),KEY status(status),KEY source(source)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_issue_updates (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,issue_id BIGINT UNSIGNED NOT NULL,update_type VARCHAR(30) NOT NULL DEFAULT 'progress',status VARCHAR(30) NULL,remarks TEXT NULL,attachment_ids TEXT NULL,update_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by BIGINT UNSIGNED NULL,assigned_to BIGINT UNSIGNED NULL,due_date DATE NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY issue_id(issue_id),KEY status(status),KEY updated_by(updated_by)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_occupancy_checks (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,hotel_id BIGINT UNSIGNED NOT NULL,check_date DATE NOT NULL,system_count INT NULL,system_updated_by BIGINT UNSIGNED NULL,created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(id),KEY hotel_id(hotel_id),KEY check_date(check_date),KEY system_updated_by(system_updated_by)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_occupancy_check_rooms (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,occupancy_check_id BIGINT UNSIGNED NOT NULL,floor_id BIGINT UNSIGNED NOT NULL,room_id BIGINT UNSIGNED NOT NULL,status VARCHAR(10) NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NULL,created_at DATETIME NOT NULL,PRIMARY KEY(id),UNIQUE KEY check_room(occupancy_check_id,room_id),KEY occupancy_check_id(occupancy_check_id),KEY floor_id(floor_id),KEY room_id(room_id),KEY status(status)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_occupancy_floor_system_counts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,occupancy_check_id BIGINT UNSIGNED NOT NULL,floor_id BIGINT UNSIGNED NOT NULL,system_count INT NOT NULL DEFAULT 0,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY check_floor(occupancy_check_id,floor_id),KEY occupancy_check_id(occupancy_check_id),KEY floor_id(floor_id),KEY updated_by(updated_by)) $c;";
        $sql[] = "CREATE TABLE {$wpdb->prefix}hrim_audit_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NULL,module VARCHAR(80) NOT NULL,action_name VARCHAR(40) NOT NULL,record_id BIGINT UNSIGNED NULL,details LONGTEXT NULL,ip_address VARCHAR(100) NULL,user_agent TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY user_id(user_id),KEY module(module),KEY action_name(action_name),KEY created_at(created_at)) $c;";
        foreach ($sql as $q) dbDelta($q);

        // Convert all existing asset/template assignments into direct asset/section
        // assignments. Legacy tables remain only so historical inspection records
        // can still be read safely; new workflows no longer use templates.
        if (!get_option('hrim_direct_sections_migrated')) {
        $wpdb->query("INSERT IGNORE INTO {$wpdb->prefix}hrim_room_sections (room_id,section_id,sort_order)
            SELECT DISTINCT assigned.room_id,ts.section_id,ts.sort_order
            FROM (
                SELECT room_id,template_id FROM {$wpdb->prefix}hrim_room_templates
                UNION
                SELECT r.id,ct.template_id FROM {$wpdb->prefix}hrim_rooms r
                INNER JOIN {$wpdb->prefix}hrim_category_templates ct ON ct.category_id=r.category_id
                WHERE NOT EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}hrim_room_template_exclusions x
                    WHERE x.room_id=r.id AND x.template_id=ct.template_id
                )
            ) assigned
            INNER JOIN {$wpdb->prefix}hrim_template_sections ts ON ts.template_id=assigned.template_id");
        update_option('hrim_direct_sections_migrated', 1, false);
        }

        // One-time removal of two sections installed by the retired default
        // library. Do this only before the new v2 library has been installed.
        if (get_option('hrim_checklist_library_version', '') !== '2.0' && !get_option('hrim_legacy_default_sections_removed')) {
            $legacy_names = ['Bathroom Amenities', 'Housekeeping Final Room Check'];
            foreach ($legacy_names as $legacy_name) {
                $section_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_sections WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) LIMIT 1", $legacy_name));
                if (!$section_id) continue;
                $question_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_questions WHERE section_id=%d", $section_id)));
                if ($question_ids) {
                    $ids = implode(',', $question_ids);
                    $used_ids = array_map('intval', $wpdb->get_col("SELECT q.id FROM {$wpdb->prefix}hrim_questions q WHERE q.id IN ({$ids}) AND (EXISTS (SELECT 1 FROM {$wpdb->prefix}hrim_answers a WHERE a.question_id=q.id) OR EXISTS (SELECT 1 FROM {$wpdb->prefix}hrim_issues i WHERE i.question_id=q.id) OR EXISTS (SELECT 1 FROM {$wpdb->prefix}hrim_periodic_task_answers pa WHERE pa.question_id=q.id) OR EXISTS (SELECT 1 FROM {$wpdb->prefix}hrim_periodic_task_update_log pl WHERE pl.question_id=q.id))"));
                    $unused_ids = array_values(array_diff($question_ids, $used_ids));
                    $wpdb->query("DELETE FROM {$wpdb->prefix}hrim_room_question_exclusions WHERE question_id IN ({$ids})");
                    $wpdb->query("DELETE FROM {$wpdb->prefix}hrim_room_question_removals WHERE question_id IN ({$ids})");
                    $wpdb->query("DELETE FROM {$wpdb->prefix}hrim_room_question_additions WHERE question_id IN ({$ids})");
                    $wpdb->query("DELETE FROM {$wpdb->prefix}hrim_periodic_task_question_exclusions WHERE question_id IN ({$ids})");
                    if ($unused_ids) $wpdb->query("DELETE FROM {$wpdb->prefix}hrim_questions WHERE id IN (" . implode(',', $unused_ids) . ")");
                    if ($used_ids) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}hrim_questions SET is_library=0,section_id=NULL,template_id=0,section_name=%s WHERE id IN (" . implode(',', $used_ids) . ")", $legacy_name));
                }
                $wpdb->delete($wpdb->prefix . 'hrim_room_sections', ['section_id'=>$section_id]);
                $wpdb->delete($wpdb->prefix . 'hrim_periodic_task_sections', ['section_id'=>$section_id]);
                $wpdb->delete($wpdb->prefix . 'hrim_template_sections', ['section_id'=>$section_id]);
                $wpdb->delete($wpdb->prefix . 'hrim_sections', ['id'=>$section_id]);
            }
            update_option('hrim_legacy_default_sections_removed', 1, false);
        }

        // Floor manager assignment.
        $floors_table = $wpdb->prefix . 'hrim_floors';
        self::ensure_column($floors_table, 'floor_manager_id', 'BIGINT UNSIGNED NULL AFTER name');
        self::ensure_index($floors_table, 'floor_manager_id', 'floor_manager_id');


        // Asset model upgrade. Existing room records remain compatible and become Asset Type: Room.
        $asset_types_table=$wpdb->prefix.'hrim_asset_types';
        self::ensure_column($wpdb->prefix.'hrim_rooms','asset_type_id','BIGINT UNSIGNED NULL AFTER floor_id');
        self::ensure_index($wpdb->prefix.'hrim_rooms','asset_type_id','asset_type_id');
        self::ensure_column($wpdb->prefix.'hrim_periodic_tasks','asset_type_id','BIGINT UNSIGNED NULL AFTER task_name');
        self::ensure_index($wpdb->prefix.'hrim_periodic_tasks','asset_type_id','asset_type_id');
        $room_asset_type=(int)$wpdb->get_var("SELECT id FROM {$asset_types_table} WHERE LOWER(name)='room' LIMIT 1");
        if(!$room_asset_type){$wpdb->insert($asset_types_table,['name'=>'Room','description'=>'Guest room or accommodation unit','sort_order'=>1,'status'=>'active']);$room_asset_type=(int)$wpdb->insert_id;}
        if($room_asset_type)$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}hrim_rooms SET asset_type_id=%d WHERE asset_type_id IS NULL OR asset_type_id=0",$room_asset_type));

        // Explicit schema repair for older installations. Some shared-hosting
        // environments do not reliably add new columns through dbDelta when
        // indexes and legacy table definitions differ.
        self::ensure_column($wpdb->prefix . 'hrim_questions', 'section_id', 'BIGINT UNSIGNED NULL AFTER template_id');
        self::ensure_index($wpdb->prefix . 'hrim_questions', 'section_id', 'section_id');
        self::ensure_column($wpdb->prefix . 'hrim_questions', 'department_id', 'BIGINT UNSIGNED NULL AFTER section_id');
        self::ensure_index($wpdb->prefix . 'hrim_questions', 'department_id', 'department_id');
        self::ensure_column($wpdb->prefix . 'hrim_questions', 'is_library', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER required_flag');
        self::ensure_column($wpdb->prefix . 'hrim_departments', 'department_head_user_id', 'BIGINT UNSIGNED NULL AFTER name');
        self::ensure_column($wpdb->prefix . 'hrim_departments', 'telegram_chat_id', 'VARCHAR(190) NULL AFTER department_head_user_id');
        self::ensure_column($wpdb->prefix . 'hrim_departments', 'notify_head_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER telegram_chat_id");
        self::ensure_column($wpdb->prefix . 'hrim_departments', 'notify_telegram_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_head_enabled");
        self::ensure_index($wpdb->prefix . 'hrim_departments', 'department_head_user_id', 'department_head_user_id');
        self::ensure_column($wpdb->prefix . 'hrim_sections', 'department_id', 'BIGINT UNSIGNED NULL AFTER name');
        self::ensure_index($wpdb->prefix . 'hrim_sections', 'department_id', 'department_id');
        self::ensure_column($wpdb->prefix . 'hrim_answers', 'video_attachment_ids', 'TEXT NULL AFTER attachment_ids');
        self::ensure_index($wpdb->prefix . 'hrim_questions', 'is_library', 'is_library');

        // v3.19.33: Departments are now stored per checkpoint instead of being shown as part of the Section field.
        $wpdb->query("UPDATE {$wpdb->prefix}hrim_questions q INNER JOIN {$wpdb->prefix}hrim_sections s ON s.id=q.section_id SET q.department_id=s.department_id WHERE q.is_library=1 AND (q.department_id IS NULL OR q.department_id=0) AND s.department_id IS NOT NULL AND s.department_id>0");

        // Periodic room-task completion fields for installations upgraded from
        // versions that created the table before room-wise task updates existed.
        $periodic_rooms_table = $wpdb->prefix . 'hrim_periodic_task_rooms';
        self::ensure_column($periodic_rooms_table, 'status', "VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER category_id");
        self::ensure_column($periodic_rooms_table, 'remarks', 'TEXT NULL AFTER status');
        self::ensure_column($periodic_rooms_table, 'photo_attachment_id', 'BIGINT UNSIGNED NULL AFTER remarks');
        self::ensure_column($periodic_rooms_table, 'completed_at', 'DATETIME NULL AFTER photo_attachment_id');
        self::ensure_column($periodic_rooms_table, 'completed_by', 'BIGINT UNSIGNED NULL AFTER completed_at');
        self::ensure_column($periodic_rooms_table, 'updated_at', 'DATETIME NULL AFTER completed_by');
        self::ensure_index($periodic_rooms_table, 'room_id', 'room_id');
        $periodic_answers_table = $wpdb->prefix . 'hrim_periodic_task_answers';
        self::ensure_column($periodic_answers_table, 'photo_attachment_id', 'BIGINT UNSIGNED NULL AFTER remarks');
        $wpdb->query("UPDATE `{$periodic_rooms_table}` SET status='pending' WHERE status IS NULL OR TRIM(status)=''");

        // Inspection draft / in-progress workflow.
        $inspections_table = $wpdb->prefix . 'hrim_inspections';
        self::ensure_column($inspections_table, 'started_at', 'DATETIME NULL AFTER submitted_at');
        self::ensure_column($inspections_table, 'last_saved_at', 'DATETIME NULL AFTER started_at');
        self::ensure_column($inspections_table, 'completed_at', 'DATETIME NULL AFTER last_saved_at');
        self::ensure_column($inspections_table, 'draft_batch_id', 'VARCHAR(64) NULL AFTER completed_at');
        self::ensure_index($inspections_table, 'draft_batch_id', 'draft_batch_id');
        self::ensure_index($inspections_table, 'status', 'status');

        // Issue management upgrade: support both inspection-generated and manual issues.
        $issues_table = $wpdb->prefix . 'hrim_issues';
        self::ensure_column($issues_table, 'source', "VARCHAR(20) NOT NULL DEFAULT 'inspection' AFTER id");
        self::ensure_column($issues_table, 'hotel_id', 'BIGINT UNSIGNED NULL AFTER inspection_id');
        self::ensure_column($issues_table, 'floor_id', 'BIGINT UNSIGNED NULL AFTER hotel_id');
        self::ensure_column($issues_table, 'section_id', 'BIGINT UNSIGNED NULL AFTER room_id');
        self::ensure_column($issues_table, 'department_id', 'BIGINT UNSIGNED NULL AFTER section_id');
        self::ensure_index($issues_table, 'department_id', 'department_id');
        self::ensure_column($issues_table, 'due_date', 'DATE NULL AFTER assigned_to');
        self::ensure_column($issues_table, 'reported_by', 'BIGINT UNSIGNED NULL AFTER due_date');
        self::ensure_column($issues_table, 'resolution_attachment_ids', 'TEXT NULL AFTER attachment_ids');
        self::ensure_column($issues_table, 'resolved_by', 'BIGINT UNSIGNED NULL AFTER resolved_at');
        self::ensure_column($issues_table, 'verified_at', 'DATETIME NULL AFTER resolved_by');
        self::ensure_column($issues_table, 'verified_by', 'BIGINT UNSIGNED NULL AFTER verified_at');
        // Older versions required inspection_id and room_id. Manual issues need both nullable.
        $wpdb->query("ALTER TABLE `{$issues_table}` MODIFY `inspection_id` BIGINT UNSIGNED NULL");
        $wpdb->query("ALTER TABLE `{$issues_table}` MODIFY `room_id` BIGINT UNSIGNED NULL");
        self::ensure_index($issues_table, 'source', 'source');
        self::ensure_index($issues_table, 'hotel_id', 'hotel_id');
        self::ensure_index($issues_table, 'floor_id', 'floor_id');
        self::ensure_index($issues_table, 'section_id', 'section_id');
        $wpdb->query("UPDATE `{$issues_table}` SET source='inspection' WHERE source IS NULL OR source=''");

        // v3.19.80: Backfill unresolved inspection issues to the responsible checkpoint Department Head.
        // This also repairs issues created before automatic Department Head assignment was introduced.
        $wpdb->query("UPDATE `{$issues_table}` x
            LEFT JOIN `{$wpdb->prefix}hrim_questions` q ON q.id=x.question_id
            LEFT JOIN `{$wpdb->prefix}hrim_sections` s ON s.id=COALESCE(NULLIF(x.section_id,0),q.section_id)
            INNER JOIN `{$wpdb->prefix}hrim_departments` d ON d.id=COALESCE(NULLIF(q.department_id,0),NULLIF(x.department_id,0),NULLIF(s.department_id,0))
            INNER JOIN `{$wpdb->users}` hu ON hu.ID=d.department_head_user_id
            SET x.department_id=d.id,
                x.assigned_to=d.department_head_user_id,
                x.status=CASE WHEN x.status IN ('open','reopened') THEN 'assigned' ELSE x.status END
            WHERE x.source='inspection'
              AND (x.assigned_to IS NULL OR x.assigned_to=0)
              AND x.status IN ('open','assigned','reopened','in_progress')
              AND d.status='active'
              AND d.department_head_user_id IS NOT NULL
              AND d.department_head_user_id>0");

        if(!(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hrim_departments")){
            $wpdb->insert($wpdb->prefix.'hrim_departments',['name'=>'General','description'=>'Default department for migrated records','sort_order'=>1,'status'=>'active']);
        }
        $general_department=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}hrim_departments ORDER BY id LIMIT 1");
        if($general_department){
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}hrim_sections SET department_id=%d WHERE department_id IS NULL OR department_id=0",$general_department));
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}hrim_issues SET department_id=%d WHERE department_id IS NULL OR department_id=0",$general_department));
        }

        // Build reusable template-to-section assignments from legacy template checkpoints.
        $legacy_links = $wpdb->get_results("SELECT DISTINCT template_id,section_id FROM {$wpdb->prefix}hrim_questions WHERE template_id>0 AND section_id IS NOT NULL AND section_id>0");
        foreach ($legacy_links as $link) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}hrim_template_sections (template_id,section_id,sort_order) VALUES (%d,%d,0)", $link->template_id, $link->section_id));
        }
        // Mark one canonical reusable checkpoint for each section/question combination.
        $wpdb->query("UPDATE {$wpdb->prefix}hrim_questions SET is_library=0 WHERE template_id>0");
        $legacy_questions = $wpdb->get_results("SELECT id,section_id,question FROM {$wpdb->prefix}hrim_questions WHERE section_id IS NOT NULL ORDER BY id");
        $seen = [];
        foreach ($legacy_questions as $q) {
            $key = (int)$q->section_id . '|' . strtolower(trim(preg_replace('/\s+/', ' ', $q->question)));
            if (!isset($seen[$key])) { $seen[$key]=1; $wpdb->update($wpdb->prefix.'hrim_questions',['is_library'=>1],['id'=>$q->id]); }
        }

        // Migrate legacy free-text section names into the Checklist Sections master.
        $legacy_sections = $wpdb->get_col("SELECT DISTINCT TRIM(section_name) FROM {$wpdb->prefix}hrim_questions WHERE section_name IS NOT NULL AND TRIM(section_name)<>''");
        foreach ($legacy_sections as $section_name) {
            $section_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_sections WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) LIMIT 1", $section_name));
            if (!$section_id) {
                $wpdb->insert($wpdb->prefix.'hrim_sections', ['name'=>$section_name,'description'=>'Migrated from existing checklist points','status'=>'active']);
                $section_id = (int) $wpdb->insert_id;
            }
            if ($section_id) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}hrim_questions SET section_id=%d WHERE (section_id IS NULL OR section_id=0) AND TRIM(section_name)=%s", $section_id, $section_name));
        }

        self::ensure_column($wpdb->prefix . 'hrim_rooms', 'category_id', 'BIGINT UNSIGNED NULL AFTER floor_id');
        self::ensure_column($wpdb->prefix . 'hrim_rooms', 'template_id', 'BIGINT UNSIGNED NULL AFTER category_id');
        self::ensure_column($wpdb->prefix . 'hrim_rooms', 'category', 'VARCHAR(190) NULL AFTER room_no');
        self::ensure_index($wpdb->prefix . 'hrim_rooms', 'category_id', 'category_id');
        self::ensure_index($wpdb->prefix . 'hrim_rooms', 'template_id', 'template_id');

        // Migrate legacy room category text and single-template assignments.
        $legacy_categories = $wpdb->get_col("SELECT DISTINCT TRIM(category) FROM {$wpdb->prefix}hrim_rooms WHERE category IS NOT NULL AND TRIM(category)<>''");
        foreach ($legacy_categories as $name) {
            $category_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hrim_room_categories WHERE name=%s LIMIT 1", $name));
            if (!$category_id) {
                $wpdb->insert($wpdb->prefix.'hrim_room_categories', ['name'=>$name,'description'=>'Migrated from existing rooms','status'=>'active']);
                $category_id = (int) $wpdb->insert_id;
            }
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}hrim_rooms SET category_id=%d WHERE (category_id IS NULL OR category_id=0) AND TRIM(category)=%s", $category_id, $name));
        }
        $legacy_rooms = $wpdb->get_results("SELECT id,template_id FROM {$wpdb->prefix}hrim_rooms WHERE template_id IS NOT NULL AND template_id>0");
        foreach ($legacy_rooms as $room) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}hrim_room_templates (room_id,template_id) VALUES (%d,%d)", $room->id, $room->template_id));
        }
    }

    private static function ensure_column($table, $column, $definition) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            $column
        ));
        if (!$exists) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private static function ensure_index($table, $index_name, $column) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table,
            $index_name
        ));
        if (!$exists) {
            $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`{$column}`)");
        }
    }
}
