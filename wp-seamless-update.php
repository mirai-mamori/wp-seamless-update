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
 *
 * 安全提示：本插件仅限管理员后台使用，所有操作均需权限校验。请勿赋予低权限用户插件相关操作能力。
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

// 加载常量定义
$const_file = plugin_dir_path( __FILE__ ) . 'includes/constants.php';
if (file_exists($const_file)) {
    require_once $const_file;
} else {
    error_log("WP Seamless Update: 错误 - 缺少常量定义文件: {$const_file}");
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>WP Seamless Update: 错误 - 插件文件不完整，请重新安装插件。</p></div>';
    });
}

// 加载基础类
$base_file = plugin_dir_path( __FILE__ ) . 'includes/class-wpsu-base.php';
if (file_exists($base_file)) {
    require_once $base_file;
} else {
    error_log("WP Seamless Update: 错误 - 缺少基础类文件: {$base_file}");
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>WP Seamless Update: 错误 - 插件文件不完整，请重新安装插件。</p></div>';
    });
}

// 加载模块
$helpers_file = plugin_dir_path( __FILE__ ) . 'includes/helpers.php';
if (file_exists($helpers_file)) {
    require_once $helpers_file;
} else {
    error_log("WP Seamless Update: 错误 - 缺少辅助函数文件: {$helpers_file}");
}

// 加载安全模块（在其他模块之前，确保安全功能优先）
$security_file = plugin_dir_path( __FILE__ ) . 'includes/security.php';
if (file_exists($security_file)) {
    require_once $security_file;
} else {
    error_log("WP Seamless Update: 错误 - 缺少安全模块文件: {$security_file}");
}

$checker_file = plugin_dir_path( __FILE__ ) . 'includes/update-checker.php';
if (file_exists($checker_file)) {
    require_once $checker_file;
} else {
    error_log("WP Seamless Update: 错误 - 缺少更新检查器文件: {$checker_file}");
}

$processor_file = plugin_dir_path( __FILE__ ) . 'includes/update-processor.php';
if (file_exists($processor_file)) {
    require_once $processor_file;
} else {
    error_log("WP Seamless Update: 错误 - 缺少更新处理器文件: {$processor_file}");
}

$ajax_file = plugin_dir_path( __FILE__ ) . 'includes/ajax.php';
if (file_exists($ajax_file)) {
    require_once $ajax_file;
} else {
    error_log("WP Seamless Update: 错误 - 缺少AJAX处理文件: {$ajax_file}");
}

$admin_file = plugin_dir_path( __FILE__ ) . 'includes/admin.php';
if (file_exists($admin_file)) {
    require_once $admin_file;
} else {
    error_log("WP Seamless Update: 错误 - 缺少管理界面文件: {$admin_file}");
}

$hooks_file = plugin_dir_path( __FILE__ ) . 'includes/hooks.php';
if (file_exists($hooks_file)) {
    require_once $hooks_file;
} else {
    error_log("WP Seamless Update: 错误 - 缺少钩子处理文件: {$hooks_file}");
}

// 注册激活、停用钩子
register_activation_hook( __FILE__, 'wpsu_activate' );
register_deactivation_hook( __FILE__, 'wpsu_deactivate' );

// 挂接更新检查钩子
add_filter( 'pre_set_site_transient_update_themes', 'wpsu_check_for_theme_update', 20 );

// 添加无缝更新执行器的定时任务钩子
add_action( 'wpsu_perform_seamless_update_hook', 'wpsu_perform_seamless_update', 10, 1 );

// 初始化插件
$wpsu = WPSU_Base::get_instance();
