<?php
/**
 * Helper functions for WP Seamless Update
 */

// 确保使用正确的翻译函数
function wpsu_get_text($text) {
    return __($text, 'wp-seamless-update');
}

// 确保使用正确的翻译函数（带回显）
function wpsu_echo_text($text) {
    _e($text, 'wp-seamless-update');
}

// 确保所有错误消息和通知也使用文本域
function wpsu_admin_notice($message, $type = 'info') {
    $class = 'notice notice-' . $type;
    printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html__($message, 'wp-seamless-update'));
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

    $temp_base = trailingslashit($uploads_base) . trailingslashit(WPSU_TEMP_UPDATE_DIR_BASE);
    $staging_base = trailingslashit($uploads_base); // 临时目录直接位于上传基础目录中，带前缀

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
                    }                    if ($is_old) {
                        error_log("WP Seamless Update Cleanup: 删除旧的临时/暂存目录: $dir_to_delete");
                        if (!$wp_filesystem->delete($dir_to_delete, true)) {
                            error_log("WP Seamless Update Cleanup: 无法删除旧的临时/暂存目录: $dir_to_delete");
                        }
                    }
                }
            }
        }
    }
}

/**
 * 递归删除目录及其内容
 * 
 * @param string $dir 要删除的目录路径
 * @return bool 删除是否成功
 */
function wpsu_recursive_delete_directory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    
    // 获取WP_Filesystem实例
    $wp_filesystem = wpsu_get_filesystem();
    if ($wp_filesystem) {
        // 使用WP_Filesystem删除目录
        return $wp_filesystem->delete($dir, true);
    } else {
        // 回退到PHP直接删除
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($path)) {
                wpsu_recursive_delete_directory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
}

/**
 * 获取用于临时文件的目录
 * 
 * @return string|bool 临时目录路径或失败时返回false
 */
function wpsu_get_temp_directory() {
    $upload_dir = wp_upload_dir();
    
    if (!empty($upload_dir['error'])) {
        error_log("WP Seamless Update: Error getting upload directory: " . $upload_dir['error']);
        return false;
    }
    
    $temp_dir = $upload_dir['basedir'] . '/wpsu-temp';
    
    // 如果目录不存在，尝试创建它
    if (!file_exists($temp_dir)) {
        if (!wp_mkdir_p($temp_dir)) {
            error_log("WP Seamless Update: Failed to create temp directory: {$temp_dir}");
            return false;
        }
        
        // 保护目录不被公开访问
        $index_file = $temp_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden');
        }
    }
    
    return $temp_dir;
}

/**
 * 获取WP_Filesystem实例
 * 
 * @return WP_Filesystem_Base|false 文件系统实例或失败时返回false
 */
function wpsu_get_filesystem() {
    global $wp_filesystem;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // 初始化文件系统
    if (WP_Filesystem()) {
        return $wp_filesystem;
    }

    error_log("WP Seamless Update: Unable to initialize WP_Filesystem");
    return false;
}

/**
 * 清理指定主题的更新transient中的响应信息
 * 
 * @param string $theme_slug 主题slug
 * @param object $transient 更新transient对象
 * @return object 更新后的transient对象
 */
function wpsu_clear_update_transient_response($theme_slug, $transient) {
    // 确保响应数组存在且包含主题条目
    if (isset($transient->response) && isset($transient->response[$theme_slug])) {
        // 删除此主题的响应条目
        unset($transient->response[$theme_slug]);
    }
    return $transient;
}

/**
 * 刷新前端显示的主题信息
 * 
 * 通过清除所有与主题相关的缓存，确保前端显示的主题信息被更新
 * 同时考虑到第三方缓存插件的存在
 */
function wpsu_refresh_frontend_display() {
    // 清除主题缓存
    wp_cache_delete('theme-roots', 'themes');
    
    // 清除主题文件路径缓存
    $theme_roots = get_theme_roots();
    if (is_array($theme_roots)) {
        foreach ($theme_roots as $theme_dir => $root) {
            wp_cache_delete("theme-{$theme_dir}", 'themes');
        }
    }
    
    // 清除所有主题的元数据缓存
    $all_themes = wp_get_themes();
    if (is_array($all_themes)) {
        foreach ($all_themes as $theme) {
            $theme_slug = $theme->get_stylesheet();
            wp_cache_delete("theme-{$theme_slug}-data", 'themes');
            wp_cache_delete("theme-{$theme_slug}", 'themes');
        }
    }
    
    // 清除更新缓存
    delete_site_transient('update_themes');
    delete_site_transient('theme_roots');
    
    // 刷新WordPress可用更新数据
    wp_update_themes();
    
    // 尝试处理常见的第三方缓存插件
    wpsu_clear_third_party_caches();
    
    // 记录日志
    error_log("WP Seamless Update: Refreshed frontend theme display by clearing all theme caches");
}

/**
 * 尝试清理第三方缓存插件的缓存
 * 
 * 处理常见的缓存插件，以确保前端显示最新的主题信息
 */
function wpsu_clear_third_party_caches() {
    // W3 Total Cache
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        error_log("WP Seamless Update: Cleared W3 Total Cache");
    }
    
    // WP Super Cache
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
        error_log("WP Seamless Update: Cleared WP Super Cache");
    }
    
    // WP Rocket
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
        error_log("WP Seamless Update: Cleared WP Rocket Cache");
    }
    
    // LiteSpeed Cache
    if (class_exists('LiteSpeed\Purge') && method_exists('LiteSpeed\Purge', 'purge_all')) {
        \LiteSpeed\Purge::purge_all();
        error_log("WP Seamless Update: Cleared LiteSpeed Cache");
    }
    
    // Autoptimize
    if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
        \autoptimizeCache::clearall();
        error_log("WP Seamless Update: Cleared Autoptimize Cache");
    }
    
    // 触发一个自定义的动作钩子，允许其他插件清理它们的缓存
    do_action('wpsu_clear_cache');
    
    // 尝试刷新对象缓存
    wp_cache_flush();
}
