<?php
/**
 * Plugin Name:       WP Seamless Update
 * Plugin URI:        https://docs.fuukei.org/
 * Description:       Implements seamless updates for a selected theme using partial file updates based on an internal version, with simulated A/B partitioning.
 * Version:           1.0.0
 * Author:            Hitomi
 * Author URI:        https://kiseki.blog/
 * License:           GPL-3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       wp-seamless-update
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// 修正加载文本域函数
function wpsu_load_textdomain() {
    // 获取插件基础名称
    $plugin_rel_path = basename( dirname( __FILE__ ) ) . '/languages';
    
    // 使用WordPress标准方法加载文本域
    load_plugin_textdomain( 
        'wp-seamless-update', 
        false, 
        $plugin_rel_path 
    );
    
}

// 使用正确的钩子加载文本域 - 同时使用init钩子确保完全加载
add_action( 'plugins_loaded', 'wpsu_load_textdomain', 5 );
add_action( 'init', 'wpsu_load_textdomain', 5 );

// 加载常量定义
require_once plugin_dir_path( __FILE__ ) . 'includes/constants.php';

// 加载基础类
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpsu-base.php';

// 加载模块
require_once plugin_dir_path( __FILE__ ) . 'includes/helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/update-checker.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/update-processor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ajax.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/hooks.php';

// 注册激活、停用钩子
register_activation_hook( __FILE__, 'wpsu_activate' );
register_deactivation_hook( __FILE__, 'wpsu_deactivate' );

// 挂接更新检查钩子
add_filter( 'pre_set_site_transient_update_themes', 'wpsu_check_for_theme_update', 20 );

// 添加无缝更新执行器的定时任务钩子
add_action( 'wpsu_perform_seamless_update_hook', 'wpsu_perform_seamless_update', 10, 1 );

// 初始化插件
$wpsu = WPSU_Base::get_instance();
