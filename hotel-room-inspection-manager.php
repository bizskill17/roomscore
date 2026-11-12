<?php
/**
 * Plugin Name: Room Score
 * Description: Room Score — Hotel Inspection Management System with inspections, issue tracking, dashboards and reporting.
 * Version: 3.20.21
 * Author: BizSkill
 * Author URI: https://bizskill.in
 * Text Domain: hrim
 */
if (!defined('ABSPATH')) exit;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
define('HRIM_VERSION','3.20.21');
define('HRIM_FILE',__FILE__);
define('HRIM_DIR',plugin_dir_path(__FILE__));
define('HRIM_URL',plugin_dir_url(__FILE__));
define('BSPC_SDK_VERSION', '0.1.0-roomscore');
require_once HRIM_DIR.'includes/class-hrim-activator.php';
require_once HRIM_DIR.'includes/class-hrim-core.php';
require_once HRIM_DIR.'includes/bizskill-plugin-client-sdk/src/class-bspc-client.php';
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

function hrim_setup_plugin_licensing_and_updates() {
    if (!class_exists('BSPC_Client')) {
        return;
    }

    BSPC_Client::register([
        'product_name' => 'RoomScore',
        'product_slug' => 'roomscore',
        'plugin_file' => HRIM_FILE,
        'plugin_basename' => 'hotel-room-inspection-manager/hotel-room-inspection-manager.php',
        'version' => HRIM_VERSION,
        'server_url' => 'https://plugins.bizskilledu.com',
        'menu_parent' => 'hrim-launch',
        'menu_title' => 'Licence',
        'settings_capability' => 'manage_options',
    ]);
}

add_action('plugins_loaded', 'hrim_setup_plugin_licensing_and_updates', 20);
