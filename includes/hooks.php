<?php
/**
 * 插件的激活、停用和卸载钩子
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
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
 * 主题切换钩子
 * 
 * 当用户切换主题时：
 * 1. 如果切换到目标主题，触发更新检查
 * 2. 如果从目标主题切换到其他主题，清理原主题的信息
 */
function wpsu_switch_theme() {
    $options = get_option( WPSU_OPTION_NAME, array() );
    $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;
    
    if (!$target_theme_slug) {
        return; // 插件未配置目标主题
    }
    
    // 获取当前活动主题
    $active_theme = wp_get_theme();
    $active_theme_slug = $active_theme->get_stylesheet();
    
    // 检查切换后的主题是否是目标主题
    if ($active_theme_slug === $target_theme_slug) {
        // 记录主题切换事件
        error_log("WP Seamless Update: Theme switched to target theme: {$target_theme_slug}. Triggering update check.");
        
        // 立即触发一次更新检查
        $transient = get_site_transient('update_themes');
        $result = wpsu_check_for_theme_update($transient);
        set_site_transient('update_themes', $result);
        
        // 记录检查结果状态
        $status_option_key = 'wpsu_last_check_status_' . $target_theme_slug;
        $status = get_option($status_option_key, '');
        error_log("WP Seamless Update: Post-switch update check status: {$status}");    } else {
        // 在WordPress 5.9+中，可以通过$_REQUEST['oldtheme']获取旧主题
        $old_theme = isset($_REQUEST['oldtheme']) ? $_REQUEST['oldtheme'] : '';
        
        // 由于某些情况下可能无法通过$_REQUEST获取到旧主题（如API调用或其他触发方式），
        // 我们使用备选方案：检查目标主题的缓存版本是否存在
        $cached_int_version = get_option('wpsu_cached_int_version_' . $target_theme_slug, false);
        $has_cached_data = ($cached_int_version !== false);
        
        // 如果旧主题是目标主题或者存在目标主题的缓存数据
        if ($old_theme === $target_theme_slug || $has_cached_data) {
            // 从目标主题切换到其他主题，清理原主题信息
            error_log("WP Seamless Update: Theme switched from target theme: {$target_theme_slug} to {$active_theme_slug}. Cleaning up.");
            
            // 无论如何，都需要彻底清理当前目标主题的相关数据
            wpsu_cleanup_theme_data($target_theme_slug, true);
            
            // 总是清除插件设置中的主题和更新源URL
            $options['target_theme'] = '';
            $options['update_url'] = '';
            update_option(WPSU_OPTION_NAME, $options);
            error_log("WP Seamless Update: Cleared plugin settings (theme and update URL) for theme: {$target_theme_slug}");
            
            // 刷新前端显示
            wpsu_refresh_frontend_display();
        }
    }
}

/**
 * 清理主题相关的数据
 * 
 * 当从目标主题切换到其他主题时，清理原主题的缓存和相关数据
 * 
 * @param string $theme_slug 需要清理的主题slug
 * @param bool $thorough_cleanup 是否进行彻底清理（包括额外数据）
 */
function wpsu_cleanup_theme_data($theme_slug, $thorough_cleanup = false) {
    if (empty($theme_slug)) {
        return;
    }
    
    error_log("WP Seamless Update: Begin cleaning up data for theme: {$theme_slug}, thorough mode: " . ($thorough_cleanup ? 'yes' : 'no'));
    
    // 1. 删除基本数据和缓存
    
    // 删除缓存的内部版本号
    delete_option('wpsu_cached_int_version_' . $theme_slug);
    
    // 删除上次检查状态和时间
    delete_option('wpsu_last_check_status_' . $theme_slug);
    delete_option('wpsu_last_check_time_' . $theme_slug);
    
    // 删除更新进度信息
    delete_option('wpsu_update_progress_' . $theme_slug);
    delete_option('wpsu_update_progress_error_' . $theme_slug);
    delete_option('wpsu_update_progress_percentage_' . $theme_slug);
    
    // 2. 取消所有计划的更新任务
    $crons = _get_cron_array();
    $unscheduled = 0;
    
    if (!empty($crons)) {
        foreach ($crons as $timestamp => $cron) {
            if (isset($cron['wpsu_perform_seamless_update_hook'])) {
                foreach ($cron['wpsu_perform_seamless_update_hook'] as $hook_id => $hook_data) {
                    // 检查钩子参数是否包含目标主题
                    if (!empty($hook_data['args']) && in_array($theme_slug, $hook_data['args'])) {
                        wp_unschedule_event($timestamp, 'wpsu_perform_seamless_update_hook', $hook_data['args']);
                        $unscheduled++;
                        error_log("WP Seamless Update: Unscheduled update task for theme: {$theme_slug} at timestamp: {$timestamp}");
                    }
                }
            }
        }
    }
    
    // 以防万一，尝试直接使用wp_next_scheduled进行取消
    $next_scheduled = wp_next_scheduled('wpsu_perform_seamless_update_hook', array($theme_slug));
    if ($next_scheduled) {
        wp_unschedule_event($next_scheduled, 'wpsu_perform_seamless_update_hook', array($theme_slug));
        $unscheduled++;
        error_log("WP Seamless Update: Unscheduled direct update task for theme: {$theme_slug}");
    }
    
    // 3. 清除更新transient中的相关信息
    $transient = get_site_transient('update_themes');
    if (is_object($transient)) {
        $modified = false;
        
        // 清理response数组
        if (isset($transient->response) && isset($transient->response[$theme_slug])) {
            unset($transient->response[$theme_slug]);
            $modified = true;
            error_log("WP Seamless Update: Removed update notification from 'response' for theme: {$theme_slug}");
        }
        
        // 清理checked数组
        if (isset($transient->checked) && isset($transient->checked[$theme_slug])) {
            unset($transient->checked[$theme_slug]);
            $modified = true;
            error_log("WP Seamless Update: Removed version check from 'checked' for theme: {$theme_slug}");
        }
        
        // 清理last_checked时间
        if ($thorough_cleanup && isset($transient->last_checked)) {
            // 在彻底清理模式下，重置last_checked时间，强制WordPress重新检查所有更新
            $transient->last_checked = 0;
            $modified = true;
            error_log("WP Seamless Update: Reset last_checked time in update transient");
        }
        
        // 保存修改后的transient
        if ($modified) {
            set_site_transient('update_themes', $transient);
        }
    }
    
    // 4. 删除临时数据和文件
    
    // 清理下载的临时文件
    $temp_dir = wpsu_get_temp_directory();
    if ($temp_dir) {
        $theme_temp_dir = trailingslashit($temp_dir) . $theme_slug;
        
        // 如果临时目录存在，删除它
        if (is_dir($theme_temp_dir)) {
            wpsu_recursive_delete_directory($theme_temp_dir);
            error_log("WP Seamless Update: Removed temporary directory: {$theme_temp_dir}");
        }
    }
    
    // 5. 清除任何可能的备份记录
    if ($thorough_cleanup) {
        global $wpdb;
        
        // 删除所有与主题相关的备份记录选项
        $backup_options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                'wpsu_backup_%_' . $theme_slug . '%'
            )
        );
        
        if ($backup_options) {
            foreach ($backup_options as $option) {
                delete_option($option->option_name);
                error_log("WP Seamless Update: Removed backup option: {$option->option_name}");
            }
        }
    }
    
    // 6. 强制刷新更新缓存（如果在彻底清理模式下）
    if ($thorough_cleanup) {
        delete_site_transient('update_themes');
        error_log("WP Seamless Update: Deleted entire update_themes transient to force refresh");
    }
    
    error_log("WP Seamless Update: Successfully completed cleanup for theme: {$theme_slug}. Unscheduled tasks: {$unscheduled}");
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
    $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=' . WPSU_PLUGIN_SLUG)) . '">' . esc_html__('Settings', 'wp-seamless-update') . '</a>';
    
    // 将设置链接添加到链接数组的开头
    array_unshift($links, $settings_link);
    
    return $links;
}

// 添加钩子以修改插件操作链接
add_filter('plugin_action_links_' . plugin_basename(WPSU_PLUGIN_FILE), 'wpsu_add_plugin_action_links');

// 添加主题切换钩子
add_action('switch_theme', 'wpsu_switch_theme');
