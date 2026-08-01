<?php
/**
 * Plugin Name: Room Score
 * Description: Room Score — Hotel Inspection Management System with inspections, issue tracking, dashboards and reporting.
 * Version: 3.16.27
 * Author: BizSkill
 * Author URI: https://bizskill.in
 * Text Domain: hrim
 */
if (!defined('ABSPATH')) exit;
define('HRIM_VERSION','3.16.27');
define('HRIM_FILE',__FILE__);
define('HRIM_DIR',plugin_dir_path(__FILE__));
define('HRIM_URL',plugin_dir_url(__FILE__));
require_once HRIM_DIR.'includes/class-hrim-activator.php';
require_once HRIM_DIR.'includes/class-hrim-core.php';
register_activation_hook(__FILE__,['HRIM_Activator','activate']);
register_deactivation_hook(__FILE__,['HRIM_Activator','deactivate']);
add_action('plugins_loaded',function(){
    $current_app_name = (string) get_option('hrim_app_name', '');
    if ($current_app_name === '' || $current_app_name === 'Hotel Inspection') {
        update_option('hrim_app_name', 'Room Score');
    }
    if (get_option('hrim_db_version') !== HRIM_Activator::DB_VERSION) {
        HRIM_Activator::create_roles();
        HRIM_Activator::create_tables();
        HRIM_Activator::create_pages();
        update_option('hrim_db_version', HRIM_Activator::DB_VERSION);
    }
    HRIM_Core::instance();
});
