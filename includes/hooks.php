<?php
/**
 * 插件的激活、停用和卸载钩子
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * 插件激活钩子
 */
function wpsu_activate() {
    // 目前不需要特殊的激活操作，目录会按需创建
}

/**
 * 插件停用钩子
 */
function wpsu_deactivate() {
    // 移除为所有可能的主题安排的定时任务（更安全）
    $crons = _get_cron_array();
    if ( empty( $crons ) ) {
        return;
    }
    
    foreach ( $crons as $timestamp => $cron ) {
        if ( isset( $cron['wpsu_perform_seamless_update_hook'] ) ) {
            foreach ( $cron['wpsu_perform_seamless_update_hook'] as $hook_id => $hook_args ) {
                wp_unschedule_event( $timestamp, 'wpsu_perform_seamless_update_hook', $hook_args['args'] );
                error_log("WP Seamless Update: Unscheduled cron hook wpsu_perform_seamless_update_hook with args: " . print_r($hook_args['args'], true));
            }
        }
    }
}

/**
 * 添加插件操作链接
 * 
 * 在插件页面的禁用按钮旁边添加一个设置链接
 * 
 * @param array $links 插件操作链接数组
 * @return array 修改后的链接数组
 */
function wpsu_add_plugin_action_links($links) {
    // 获取插件设置页面URL
    $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=' . WPSU_PLUGIN_SLUG)) . '">' . esc_html__('设置', 'wp-seamless-update') . '</a>';
    
    // 将设置链接添加到链接数组的开头
    array_unshift($links, $settings_link);
    
    return $links;
}

// 添加钩子以修改插件操作链接
add_filter('plugin_action_links_' . plugin_basename(WPSU_PLUGIN_FILE), 'wpsu_add_plugin_action_links');
