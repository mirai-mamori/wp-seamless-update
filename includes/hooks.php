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
 * 插件卸载钩子
 */
function wpsu_uninstall() {
    // 检查卸载过程是否有效且由 WordPress 调用
    if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        exit;
    }
    
    // 初始化错误日志，记录详细的卸载过程
    error_log("WP Seamless Update: Starting plugin uninstall process...");

    // --- 清理逻辑 ---

    // 获取插件选项以找到目标主题 slug
    $options = get_option( WPSU_OPTION_NAME );
    $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;

    error_log("WP Seamless Update: Target theme slug found: " . ($target_theme_slug ? $target_theme_slug : 'none'));

    // 1. 删除插件设置
    delete_option( WPSU_OPTION_NAME );
    error_log("WP Seamless Update: Deleted plugin main options");

    // 2. 如果设置了目标主题，清理相关数据
    if ( $target_theme_slug ) {
        // 删除最后检查状态和时间选项
        delete_option( 'wpsu_last_check_status_' . $target_theme_slug );
        delete_option( 'wpsu_last_check_time_' . $target_theme_slug );
        error_log("WP Seamless Update: Deleted status and time options for theme: $target_theme_slug");
    }

    // 3. 清除此特定主题的计划定时任务
    if ($target_theme_slug) {
        wp_clear_scheduled_hook( 'wpsu_perform_seamless_update_hook', array( $target_theme_slug ) );
        error_log("WP Seamless Update: Cleared scheduled hooks for theme: $target_theme_slug");
    } else {
        // 如果没有特定主题，清除所有可能的wpsu_perform_seamless_update_hook钩子
        wp_clear_scheduled_hook( 'wpsu_perform_seamless_update_hook' );
        error_log("WP Seamless Update: Cleared all scheduled hooks for update");
    }
    
    // 4. 清理临时文件和备份文件
    try {
        // 确保文件系统可用
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        WP_Filesystem();
        global $wp_filesystem;
        
        if ($wp_filesystem) {
            $upload_dir_info = wp_upload_dir();
            if (empty($upload_dir_info['error'])) {
                $uploads_base = trailingslashit($upload_dir_info['basedir']);
                
                // 清理临时下载目录
                $temp_base_dir = $uploads_base . WPSU_TEMP_UPDATE_DIR_BASE;
                if ($wp_filesystem->exists($temp_base_dir)) {
                    $deleted = $wp_filesystem->delete($temp_base_dir, true);
                    error_log("WP Seamless Update: " . ($deleted ? "Successfully deleted" : "Failed to delete") . " temp directory: $temp_base_dir");
                }
                
                // 清理备份目录
                $backup_base_dir = $uploads_base . WPSU_BACKUP_DIR_BASE;
                if ($wp_filesystem->exists($backup_base_dir)) {
                    $deleted = $wp_filesystem->delete($backup_base_dir, true);
                    error_log("WP Seamless Update: " . ($deleted ? "Successfully deleted" : "Failed to delete") . " backup directory: $backup_base_dir");
                }
                
                // 清理任何可能的临时目录
                if ($target_theme_slug) {
                    $staging_pattern = $uploads_base . 'wpsu-staging-' . $target_theme_slug . '-*';
                    $base_dir = dirname($staging_pattern);
                    $search_pattern = basename($staging_pattern);
                    
                    if ($wp_filesystem->is_dir($base_dir)) {
                        $items = $wp_filesystem->dirlist($base_dir);
                        if (is_array($items)) {
                            foreach ($items as $name => $details) {
                                if ($details['type'] === 'd' && fnmatch($search_pattern, $name)) {
                                    $dir_to_delete = trailingslashit($base_dir) . $name;
                                    $deleted = $wp_filesystem->delete($dir_to_delete, true);
                                    error_log("WP Seamless Update: " . ($deleted ? "Successfully deleted" : "Failed to delete") . " staging directory: $dir_to_delete");
                                }
                            }
                        }
                    }
                }
            } else {
                error_log("WP Seamless Update: Error getting upload directory: " . $upload_dir_info['error']);
            }
        } else {
            error_log("WP Seamless Update: Could not initialize WP_Filesystem during uninstall");
        }
    } catch (Exception $e) {
        error_log("WP Seamless Update: Exception during file cleanup: " . $e->getMessage());
    }
    
    error_log("WP Seamless Update: Uninstall process completed");
}
