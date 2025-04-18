<?php
/**
 * 插件的辅助函数
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * 帮助函数，将 WP_Filesystem::dirlist 输出展平为相对路径。
 *
 * @param array $dirlist 来自 $wp_filesystem->dirlist() 的输出。
 * @param string $prefix 内部用于递归。
 * @return array 相对文件路径数组。
 */
function wpsu_flatten_dirlist( $dirlist, $prefix = '' ) {
    $files = array();
    if ( ! is_array( $dirlist ) ) {
        return $files;
    }
    foreach ( $dirlist as $name => $details ) {
        $relative_path = $prefix . $name;
        if ( isset( $details['files'] ) && is_array( $details['files'] ) ) {
            // 这是一个目录，递归
            $files = array_merge( $files, wpsu_flatten_dirlist( $details['files'], $relative_path . '/' ) );
        } else {
            // 这是一个文件
            $files[] = $relative_path;
        }
    }
    return $files;
}

/**
 * 清理剩余的临时更新和临时目录。
 *
 * @param WP_Filesystem_Base $wp_filesystem 初始化的 WP_Filesystem 对象。
 * @param string $uploads_base 基本上传目录路径。
 * @param string $theme_slug 要清理的主题 slug。
 */
function wpsu_cleanup_temp_dirs($wp_filesystem, $uploads_base, $theme_slug) {
    // Add check for $wp_filesystem
    if ( ! $wp_filesystem ) {
        error_log("WP Seamless Update Cleanup: WP_Filesystem object is not available. Cannot cleanup temp dirs.");
        return;
    }

    $temp_base = $uploads_base . WPSU_TEMP_UPDATE_DIR_BASE . '/';
    $staging_base = $uploads_base; // 临时目录直接位于上传基础目录中，带前缀

    $patterns = array(
        $temp_base . $theme_slug . '-*',
        $staging_base . 'wpsu-staging-' . $theme_slug . '-*'
    );

    foreach ($patterns as $pattern) {
        $base_dir = dirname($pattern);
        $search_pattern = basename($pattern);

        if (!$wp_filesystem->is_dir($base_dir)) continue;

        $items = $wp_filesystem->dirlist($base_dir);
        if (is_array($items)) {
            foreach ($items as $name => $details) {
                if ($details['type'] === 'd' && fnmatch($search_pattern, $name)) {
                    $dir_to_delete = trailingslashit($base_dir) . $name;
                    error_log("WP Seamless Update Cleanup: Deleting leftover directory: $dir_to_delete");
                    $wp_filesystem->delete($dir_to_delete, true);
                }
            }
        }
    }
}

/**
 * 清理主题备份，只保留指定数量。
 *
 * @param string $theme_slug 主题 slug。
 * @param string $backup_dir_base 备份的基本路径 (例如： wp-content/uploads/wpsu-backups/)。
 * @param int $backups_to_keep 要保留的备份数量。
 */
function wpsu_manage_backups( $theme_slug, $backup_dir_base, $backups_to_keep ) {
    global $wp_filesystem;
    if ( ! $wp_filesystem || $backups_to_keep <= 0 ) {
        return; // 文件系统未就绪或备份已禁用
    }

    error_log("WP Seamless Update: Managing backups for $theme_slug in $backup_dir_base, keeping $backups_to_keep.");

    if ( ! $wp_filesystem->is_dir( $backup_dir_base ) ) {
        error_log("WP Seamless Update: Base backup directory $backup_dir_base does not exist. Cannot manage backups.");
        return;
    }

    $all_backups = $wp_filesystem->dirlist( $backup_dir_base );
    if ( ! is_array( $all_backups ) ) {
         error_log("WP Seamless Update: Failed to list contents of backup directory $backup_dir_base.");
        return;
    }

    $theme_backups = array();
    $prefix = $theme_slug . '-';

    foreach ( $all_backups as $name => $details ) {
        // 检查是否是目录且与主题 slug 前缀匹配
        if ( $details['type'] === 'd' && strpos( $name, $prefix ) === 0 ) {
            // 提取时间戳
            $timestamp_str = substr( $name, strlen( $prefix ) );
            if ( is_numeric( $timestamp_str ) ) {
                $theme_backups[ intval( $timestamp_str ) ] = $name; // 存储 timestamp => dirname
            }
        }
    }

    if ( count( $theme_backups ) <= $backups_to_keep ) {
        error_log("WP Seamless Update: Found " . count( $theme_backups ) . " backups for $theme_slug, which is within the limit of $backups_to_keep. No old backups to delete.");
        return; // 无需删除任何内容
    }

    // 按时间戳排序（最旧的在前面）
    ksort( $theme_backups );

    $backups_to_delete_count = count( $theme_backups ) - $backups_to_keep;
    error_log("WP Seamless Update: Found " . count( $theme_backups ) . " backups for $theme_slug. Need to delete $backups_to_delete_count oldest backups.");

    $deleted_count = 0;
    foreach ( $theme_backups as $timestamp => $dir_name ) {
        if ( $deleted_count >= $backups_to_delete_count ) {
            break; // 已删除足够数量
        }

        $dir_to_delete = trailingslashit( $backup_dir_base ) . $dir_name;
        error_log("WP Seamless Update: Deleting old backup directory: $dir_to_delete");
        if ( ! $wp_filesystem->delete( $dir_to_delete, true ) ) {
            error_log("WP Seamless Update: Failed to delete old backup directory: $dir_to_delete");
            // 记录错误但继续尝试删除其他
        } else {
            $deleted_count++;
        }
    }
    error_log("WP Seamless Update: Finished managing backups for $theme_slug. Deleted $deleted_count old backups.");
}

/**
 * 辅助函数，通过引用清除主题的更新通知。
 *
 * @param string $theme_slug 主题 slug。
 * @param object|null $transient transient 对象（如果提供的话，通过引用传递）。
 */
function wpsu_clear_update_transient_response($theme_slug, &$transient = null) {
     $needs_set = false;
     if ($transient === null) {
        $transient = get_site_transient('update_themes');
        $needs_set = true; // 我们获取了它，所以如果更改，我们需要设置回来
     }

    if ( $transient && isset($transient->response) && is_array($transient->response) && isset($transient->response[$theme_slug]) ) {
        unset($transient->response[$theme_slug]);
        error_log("WP Seamless Update: Cleared update transient response entry for theme $theme_slug.");
        if ($needs_set) {
             set_site_transient('update_themes', $transient);
        }
    }
}

/**
 * 辅助函数，通过获取和设置来清除更新 transient *response* 条目。
 *
 * @param string $theme_slug 主题 slug。
 */
function wpsu_clear_update_transient($theme_slug) {
    wpsu_clear_update_transient_response($theme_slug); // 传递 null 以获取/设置
}
