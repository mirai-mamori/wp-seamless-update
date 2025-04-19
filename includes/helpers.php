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
 * 将 WP_Filesystem::dirlist() 的递归结果展平成相对路径列表。
 *
 * @param array $dirlist dirlist() 返回的数组。
 * @param string $prefix 当前路径前缀（用于递归）。
 * @return array 包含相对文件路径的扁平数组。
 */
function wpsu_flatten_dirlist($dirlist, $prefix = '') {
    $paths = [];
    if (!is_array($dirlist)) {
        return $paths;
    }
    foreach ($dirlist as $name => $details) {
        $current_path = $prefix ? trailingslashit($prefix) . $name : $name;
        if ($details['type'] === 'f') { // 文件
            $paths[] = $current_path;
        } elseif ($details['type'] === 'd') { // 目录
            if (isset($details['files']) && is_array($details['files'])) {
                $paths = array_merge($paths, wpsu_flatten_dirlist($details['files'], $current_path));
            }
        }
    }
    return $paths;
}

/**
 * 清理剩余的临时更新和临时目录。
 *
 * @param WP_Filesystem_Base $wp_filesystem 初始化的 WP_Filesystem 对象。
 * @param string $uploads_base 基本上传目录路径。
 * @param string $theme_slug 要清理的主题 slug。
 */
function wpsu_cleanup_temp_dirs($wp_filesystem, $uploads_base, $target_theme_slug) {
    // Add check for $wp_filesystem
    if ( ! $wp_filesystem ) {
        error_log("WP Seamless Update Cleanup: WP_Filesystem object is not available. Cannot cleanup temp dirs.");
        return;
    }

    $temp_base = $uploads_base . WPSU_TEMP_UPDATE_DIR_BASE . '/';
    $staging_base = $uploads_base; // 临时目录直接位于上传基础目录中，带前缀

    $patterns = array(
        $temp_base . $target_theme_slug . '-*',
        $staging_base . 'wpsu-staging-' . $target_theme_slug . '-*'
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
                    $is_old = false;
                    // 通过目录名解析时间戳
                    $parts = explode('-', $name);
                    $timestamp = end($parts);
                    // 增加检查确保 $timestamp 是数字并且大于0，避免无效时间戳
                    if (is_numeric($timestamp) && intval($timestamp) > 0 && (time() - intval($timestamp) > 3600)) {
                        $is_old = true;
                    } elseif (!is_numeric($timestamp) || intval($timestamp) <= 0) {
                        // 如果目录名不包含有效时间戳，也视为旧目录进行清理，防止累积无效目录
                        error_log("WP Seamless Update Cleanup: 发现无效或无时间戳的目录，标记为旧目录: $dir_to_delete");
                        $is_old = true;
                    }


                    if ($is_old) {
                        error_log("WP Seamless Update Cleanup: 删除旧的临时/暂存目录: $dir_to_delete");
                        $wp_filesystem->delete($dir_to_delete, true);
                    }
                }
            }
        }
    }
}

/**
 * 管理指定主题的备份目录，只保留指定数量的最新备份。
 *
 * @global WP_Filesystem_Base $wp_filesystem
 * @param string $theme_slug 主题 slug。
 * @param string $backup_dir_base 基础备份目录路径。
 * @param int $backups_to_keep 要保留的备份数量。
 */
function wpsu_manage_backups( $theme_slug, $backup_dir_base, $backups_to_keep ) {
    global $wp_filesystem;
    if ( ! $wp_filesystem || $backups_to_keep <= 0 || ! $wp_filesystem->is_dir( $backup_dir_base ) ) {
        return;
    }

    error_log("WP Seamless Update Cleanup: 管理备份目录 $backup_dir_base, 保留 $backups_to_keep 个备份。");

    $backups = [];
    $dir_list = $wp_filesystem->dirlist( $backup_dir_base, false, false ); // 非递归

    if ( is_array( $dir_list ) ) {
        foreach ( $dir_list as $item ) {
            // 检查是否是目录，并且名称以 "themeslug-" 开头
            if ( $item['type'] === 'd' && strpos( $item['name'], $theme_slug . '-' ) === 0 ) {
                // 尝试从目录名中提取时间戳
                $parts = explode( '-', $item['name'] );
                $timestamp = end( $parts );
                if ( is_numeric( $timestamp ) ) {
                    $backups[ intval( $timestamp ) ] = trailingslashit( $backup_dir_base ) . $item['name'];
                }
            }
        }
    }

    if ( count( $backups ) > $backups_to_keep ) {
        krsort( $backups ); // 按时间戳降序排序（最新的在前）
        $backups_to_delete = array_slice( $backups, $backups_to_keep ); // 获取需要删除的旧备份

        foreach ( $backups_to_delete as $timestamp => $dir_path ) {
            error_log( "WP Seamless Update Cleanup: 删除旧备份目录: $dir_path (Timestamp: $timestamp)" );
            $wp_filesystem->delete( $dir_path, true );
        }
    } else {
        error_log("WP Seamless Update Cleanup: 当前备份数量 (" . count($backups) . ") 未超过限制 ($backups_to_keep)。无需删除。");
    }
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
