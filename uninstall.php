<?php
/**
 * WP Seamless Update 卸载文件
 *
 * 当插件被删除时，此文件会被 WordPress 自动执行
 * 
 * @package WP_Seamless_Update
 */

// 如果没有由 WordPress 直接调用，则退出
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 加载必要的常量
require_once dirname(__FILE__) . '/includes/constants.php';

// 初始化错误日志，记录详细的卸载过程
error_log("WP Seamless Update: Starting plugin uninstall process...");

// 处理多站点情况
if ( is_multisite() ) {
    global $wpdb;
    
    // 获取所有站点
    $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
    
    if ( $blog_ids ) {
        foreach ( $blog_ids as $blog_id ) {
            switch_to_blog( $blog_id );
            wpsu_uninstall_single_site();
            restore_current_blog();
        }
    }
} else {
    wpsu_uninstall_single_site();
}

error_log("WP Seamless Update: Uninstall process completed successfully");

/**
 * 单站点的卸载清理逻辑
 */
function wpsu_uninstall_single_site() {
    global $wpdb;
    
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
        
        // 删除更新进度选项
        delete_option( 'wpsu_update_progress_' . $target_theme_slug );
        
        error_log("WP Seamless Update: Deleted status, time and progress options for theme: $target_theme_slug");
        
        // 清理可能存在的其他与主题相关的选项
        $like_options = $wpdb->get_results( $wpdb->prepare( 
            "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
            "%wpsu%{$target_theme_slug}%" 
        ) );
        
        if ( !empty($like_options) ) {
            foreach ( $like_options as $option ) {
                delete_option( $option->option_name );
                error_log("WP Seamless Update: Deleted additional option: {$option->option_name}");
            }
        }
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
    
    // 清理transient缓存
    delete_site_transient('update_themes'); // 强制刷新主题更新缓存
    error_log("WP Seamless Update: Cleared update_themes site transient cache");
    
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
                $temp_base_dir = trailingslashit($uploads_base) . trailingslashit(WPSU_TEMP_UPDATE_DIR_BASE);
                if ($wp_filesystem->exists($temp_base_dir)) {
                    $deleted = $wp_filesystem->delete($temp_base_dir, true);
                    error_log("WP Seamless Update: " . ($deleted ? "Successfully deleted" : "Failed to delete") . " temp directory: $temp_base_dir");
                }
                
                // 清理备份目录
                $backup_base_dir = trailingslashit($uploads_base) . trailingslashit(WPSU_BACKUP_DIR_BASE);
                if ($wp_filesystem->exists($backup_base_dir)) {
                    $deleted = $wp_filesystem->delete($backup_base_dir, true);
                    error_log("WP Seamless Update: " . ($deleted ? "Successfully deleted" : "Failed to delete") . " backup directory: $backup_base_dir");
                }
                
                // 尝试使用PHP原生函数清理可能残留的暂存目录
                $wpsu_staging_pattern = $uploads_base . 'wpsu-staging-*';
                $staging_dirs = glob($wpsu_staging_pattern, GLOB_ONLYDIR);
                if (!empty($staging_dirs)) {
                    foreach ($staging_dirs as $dir) {
                        try {
                            // 使用递归函数删除目录及其内容
                            if (is_dir($dir)) {
                                wpsu_recursive_rmdir($dir);
                                error_log("WP Seamless Update: Tried PHP native deletion for staging directory: $dir");
                            }
                        } catch (Exception $ex) {
                            error_log("WP Seamless Update: Failed to delete staging directory using PHP native functions: " . $ex->getMessage());
                        }
                    }
                }
                
                // 尝试使用WP_Filesystem删除暂存目录
                if ($target_theme_slug) {
                    $staging_pattern = trailingslashit($uploads_base) . 'wpsu-staging-' . $target_theme_slug . '-*';
                    $base_dir = dirname($staging_pattern);
                    $search_pattern = basename($staging_pattern);
                    
                    if ($wp_filesystem->is_dir($base_dir)) {
                        $items = $wp_filesystem->dirlist($base_dir);
                        if (is_array($items)) {
                            foreach ($items as $name => $details) {
                                if ($details['type'] === 'd' && fnmatch($search_pattern, $name)) {
                                    $dir_to_delete = trailingslashit($base_dir) . $name;
                                    $deleted = $wp_filesystem->delete($dir_to_delete, true);
                                    error_log("WP Seamless Update: " . ($deleted ? "Successfully deleted" : "Failed to delete") . " specific staging directory: $dir_to_delete");
                                }
                            }
                        }
                    }
                }
                
                // 尝试清理所有其他可能的与插件相关的临时文件 (使用PHP原生函数和WP_Filesystem双重保障)
                $glob_patterns = array(
                    $uploads_base . '.wpsu-*',
                    $uploads_base . 'wpsu-*'
                );
                
                foreach ($glob_patterns as $pattern) {
                    // 尝试使用PHP原生函数删除
                    $files = glob($pattern);
                    if (!empty($files)) {
                        foreach ($files as $file) {
                            try {
                                if (is_dir($file)) {
                                    wpsu_recursive_rmdir($file);
                                    error_log("WP Seamless Update: Attempted to delete directory using PHP native functions: $file");
                                } else if (is_file($file)) {
                                    @unlink($file);
                                    error_log("WP Seamless Update: Attempted to delete file using PHP native functions: $file");
                                }
                            } catch (Exception $ex) {
                                error_log("WP Seamless Update: Failed to delete using PHP native functions: " . $ex->getMessage());
                            }
                            
                            // 再尝试使用WP_Filesystem
                            if ($wp_filesystem->exists($file)) {
                                $is_dir = $wp_filesystem->is_dir($file);
                                $deleted = $wp_filesystem->delete($file, $is_dir);
                                $type = $is_dir ? "directory" : "file";
                                error_log("WP Seamless Update: " . ($deleted ? "Successfully deleted" : "Failed to delete") . " {$type}: {$file}");
                            }
                        }
                    }
                }
            } else {
                error_log("WP Seamless Update: Error getting upload directory: " . $upload_dir_info['error']);
            }
        } else {
            error_log("WP Seamless Update: Could not initialize WP_Filesystem during uninstall");
            
            // 如果无法初始化WP_Filesystem，尝试使用PHP原生函数删除目录
            try {
                $upload_dir_info = wp_upload_dir();
                if (empty($upload_dir_info['error'])) {
                    $uploads_base = trailingslashit($upload_dir_info['basedir']);
                    
                    // 尝试删除临时目录
                    $temp_base_dir = $uploads_base . WPSU_TEMP_UPDATE_DIR_BASE;
                    if (is_dir($temp_base_dir)) {
                        wpsu_recursive_rmdir($temp_base_dir);
                        error_log("WP Seamless Update: Attempted to delete temp dir using PHP native functions: $temp_base_dir");
                    }
                    
                    // 尝试删除备份目录
                    $backup_base_dir = $uploads_base . WPSU_BACKUP_DIR_BASE;
                    if (is_dir($backup_base_dir)) {
                        wpsu_recursive_rmdir($backup_base_dir);
                        error_log("WP Seamless Update: Attempted to delete backup dir using PHP native functions: $backup_base_dir");
                    }
                }
            } catch (Exception $ex) {
                error_log("WP Seamless Update: Failed in native PHP deletion: " . $ex->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("WP Seamless Update: Exception during file cleanup: " . $e->getMessage());
    }
    
    error_log("WP Seamless Update: Uninstall process completed");
}

/**
 * 递归删除目录及其内容的辅助函数
 * 
 * @param string $dir 要删除的目录路径
 * @return bool 是否成功删除
 */
function wpsu_recursive_rmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir. DIRECTORY_SEPARATOR . $object) && !is_link($dir . DIRECTORY_SEPARATOR . $object)) {
                    wpsu_recursive_rmdir($dir . DIRECTORY_SEPARATOR . $object);
                } else {
                    @unlink($dir . DIRECTORY_SEPARATOR . $object);
                }
            }
        }
        @rmdir($dir);
        return true;
    }
    return false;
}
