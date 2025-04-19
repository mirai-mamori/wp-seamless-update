<?php
/**
 * 更新执行功能
 * 
 * 负责执行实际的主题更新过程
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * 执行无缝主题更新（由 WP Cron 调用）。
 * 实现模拟的 A/B 更新过程，使用单个更新包。
 *
 * @param string $target_theme_slug 要更新的主题的 slug。
 */
function wpsu_perform_seamless_update( $target_theme_slug ) {
    // 设置执行时间限制为5分钟（300秒），避免脚本超时
    @set_time_limit(300);
    
    // 增加详细的开始日志，包含环境信息
    error_log("WP Seamless Update Cron: ====== 开始为主题 $target_theme_slug 执行更新 (使用更新包) ======");
    error_log("WP Seamless Update: 环境信息 - PHP版本: " . phpversion() . ", WordPress版本: " . get_bloginfo('version') . ", 内存限制: " . ini_get('memory_limit') . ", 最大执行时间: " . ini_get('max_execution_time') . "秒");
    
    // 清除旧的进度信息并设置初始进度
    wpsu_clear_update_progress($target_theme_slug);
    wpsu_set_update_progress($target_theme_slug, __('Starting update process (package mode)...', 'wp-seamless-update'), 0);
    
    // 添加错误处理
    try {

    $options = get_option( WPSU_OPTION_NAME, array() );
    $configured_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;
    $update_url = isset( $options['update_url'] ) ? $options['update_url'] : null;
    $backups_to_keep = isset( $options['backups_to_keep'] ) ? absint($options['backups_to_keep']) : WPSU_DEFAULT_BACKUPS_TO_KEEP;
    
    // --- 初始检查 ---
    if ( $target_theme_slug !== $configured_theme_slug || ! $update_url ) {
        error_log("WP Seamless Update Cron: 配置不匹配或缺失，目标主题: $target_theme_slug, 已配置主题: $configured_theme_slug, 更新URL: " . ($update_url ? $update_url : '未设置'));
        wpsu_clear_update_transient($target_theme_slug); // 清除任何可能过时的通知
        wpsu_set_update_progress($target_theme_slug, __('Configuration mismatch or missing', 'wp-seamless-update'), 100, true);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Configuration mismatch or missing.', 'wp-seamless-update' ) );
        return;
    }

    $theme = wp_get_theme( $target_theme_slug );
    if ( ! $theme->exists() ) {
        error_log("WP Seamless Update Cron: 目标主题 $target_theme_slug 未找到。中止更新。");
        wpsu_clear_update_transient($target_theme_slug);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Target theme not found.', 'wp-seamless-update' ) );
        return;
    }
    $theme_root = $theme->get_stylesheet_directory(); // 主题目录路径
    error_log("WP Seamless Update Cron: 主题根目录: $theme_root");

    // --- 从 functions.php 获取内部版本（关键检查）---
    $current_internal_version = null; // 如果找不到则默认为 null
    $active_theme = wp_get_theme(); // 获取当前活动主题

    // 记录当前活动主题信息
    error_log("WP Seamless Update Cron: 当前活动主题: " . $active_theme->get_stylesheet() . ", 目标主题: $target_theme_slug");
    error_log("WP Seamless Update Cron: INT_VERSION常量" . (defined('INT_VERSION') ? "已定义: " . INT_VERSION : "未定义"));

    // 重要：这依赖于主题处于活动状态，并且在 cron 上下文中加载了 functions.php。
    if ( $active_theme->get_stylesheet() === $target_theme_slug && defined('INT_VERSION') ) {
        $current_internal_version = INT_VERSION;
        error_log("WP Seamless Update Cron: 成功读取活动主题 $target_theme_slug 的 INT_VERSION = $current_internal_version");
    }
    if ( $current_internal_version === null ) {
        error_log("WP Seamless Update Cron: 严重错误 - 在cron上下文中无法读取主题 $target_theme_slug 的 INT_VERSION。主题可能未激活或常量未定义。中止更新。");
        wpsu_clear_update_transient($target_theme_slug); // 清除通知，因为更新无法继续
        wpsu_set_update_progress($target_theme_slug, __('Could not read INT_VERSION in theme', 'wp-seamless-update'), 100, true);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not read INT_VERSION in background task. Is the theme active?', 'wp-seamless-update' ) );
        return; // 如果找不到常量，则中止
    }
    // --- 结束获取内部版本 ---

    // --- 准备目录 ---
    $upload_dir_info = wp_upload_dir();
    if ($upload_dir_info['error']) {
        error_log("WP Seamless Update Cron: 无法获取上传目录信息。错误: " . $upload_dir_info['error'] . "。中止。");
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not access upload directory.', 'wp-seamless-update' ) );
        return;
    }
    $uploads_base = trailingslashit($upload_dir_info['basedir']);
    $timestamp = time();

    // 临时下载目录 (现在用于存放下载的包和解压后的文件)
    $temp_dir_base = $uploads_base . WPSU_TEMP_UPDATE_DIR_BASE . '/';
    $temp_dir = $temp_dir_base . $target_theme_slug . '-' . $timestamp . '/'; // 主临时目录
    $temp_package_file = $temp_dir . $target_theme_slug . '-update.zip'; // 下载的包文件路径
    $temp_extract_dir = $temp_dir . 'extracted/'; // 解压目录
    error_log("WP Seamless Update Cron: 临时工作目录: $temp_dir");
    error_log("WP Seamless Update Cron: 临时包文件: $temp_package_file");
    error_log("WP Seamless Update Cron: 临时解压目录: $temp_extract_dir");

    // 备份目录
    $backup_dir_base = $uploads_base . WPSU_BACKUP_DIR_BASE . '/';
    $backup_dir = $backup_dir_base . $target_theme_slug . '-' . $timestamp . '/';
    error_log("WP Seamless Update Cron: 备份目录: $backup_dir");

    // 暂存目录（更新应用于此处，然后切换）
    $staging_dir = $uploads_base . 'wpsu-staging-' . $target_theme_slug . '-' . $timestamp . '/';
    error_log("WP Seamless Update Cron: 暂存目录: $staging_dir");

    // 清理之前失败尝试中可能遗留的临时目录（可选但是良好做法）
    wpsu_cleanup_temp_dirs($wp_filesystem, $uploads_base, $target_theme_slug);

    // 重新获取远程信息以确保它是最新的
    wpsu_set_update_progress($target_theme_slug, __('Checking for updates...', 'wp-seamless-update'), 5);
    error_log("WP Seamless Update Cron: 获取远程版本信息从 $update_url");
    $remote_info = wpsu_fetch_remote_version_info( $update_url );
    if ( ! $remote_info ) {
        error_log("WP Seamless Update Cron: 无法从 $update_url 获取远程信息。中止。");
        wpsu_clear_update_transient($target_theme_slug);
        wpsu_set_update_progress($target_theme_slug, __('Could not fetch remote version info', 'wp-seamless-update'), 100, true);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not fetch remote version info.', 'wp-seamless-update' ) );
        return;
    }
    wpsu_set_update_progress($target_theme_slug, __('Found update information', 'wp-seamless-update'), 10);
    
    // 记录远程版本信息
    error_log("WP Seamless Update Cron: 远程信息 - 显示版本: {$remote_info->display_version}, 内部版本: {$remote_info->internal_version}");
    // --- 新增：检查远程信息是否包含包信息 ---
    if ( ! isset($remote_info->package_url) || ! isset($remote_info->package_hash) || ! isset($remote_info->files) || ! is_array($remote_info->files) ) {
        error_log("WP Seamless Update Cron: 远程信息缺少必要的更新包信息 (package_url, package_hash, files)。中止。");
        wpsu_clear_update_transient($target_theme_slug);
        wpsu_set_update_progress($target_theme_slug, __('Remote info is missing package details', 'wp-seamless-update'), 100, true);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Remote information is incomplete for package update.', 'wp-seamless-update' ) );
        return;
    }
    error_log("WP Seamless Update Cron: 远程包URL: {$remote_info->package_url}");
    error_log("WP Seamless Update Cron: 远程包哈希: {$remote_info->package_hash}");
    error_log("WP Seamless Update Cron: 包内文件数: " . count($remote_info->files));
    // --- 结束新增检查 ---
    
    error_log("WP Seamless Update Cron: 本地信息 - 显示版本: " . $theme->get('Version') . ", 内部版本: $current_internal_version");

    // 在继续之前进行最终版本检查（使用 INT_VERSION）
    if ( version_compare( $remote_info->display_version, $theme->get( 'Version' ), '!=' ) ) {
        error_log("WP Seamless Update Cron: 显示版本不匹配（远程: {$remote_info->display_version}, 本地: " . $theme->get('Version') . "）。中止内部更新。");
        wpsu_clear_update_transient($target_theme_slug);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, sprintf( __( 'Update failed: Display version mismatch (Remote: %s, Local: %s).', 'wp-seamless-update' ), $remote_info->display_version, $theme->get('Version') ) );
        return;
    }
    
    // 将远程内部版本与从 functions.php 读取的 INT_VERSION 进行比较
    if ( version_compare( $remote_info->internal_version, $current_internal_version, '<=' ) ) {
        error_log("WP Seamless Update Cron: 远程内部版本 ({$remote_info->internal_version}) 不比当前 INT_VERSION ($current_internal_version) 新。中止更新。");
        wpsu_clear_update_transient($target_theme_slug);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'No update needed. Remote version is not newer than current version.', 'wp-seamless-update' ) );
        return;
    }
    
    error_log("WP Seamless Update Cron: 版本检查通过，需要更新（远程内部版本: {$remote_info->internal_version} > 本地内部版本: {$current_internal_version}）");

    // --- 初始化 WP_Filesystem ---
    error_log("WP Seamless Update Cron: 开始初始化WP_Filesystem");
    if ( ! function_exists('request_filesystem_credentials') || ! function_exists('WP_Filesystem') ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }
    // --- 新增：确保解压函数可用 ---
    if ( ! function_exists('unzip_file') ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }
    
    // 记录文件系统方法
    $fs_method = get_filesystem_method();
    error_log("WP Seamless Update Cron: 文件系统方法: $fs_method");
    
    // 尝试非交互式获取凭据（用于 cron）
    $creds = request_filesystem_credentials( site_url(), '', false, false, null );
    if ( false === $creds ) {
        error_log("WP Seamless Update Cron: 无法获取文件系统凭据（非交互式）。检查wp-config.php中是否定义了FS_METHOD。中止。");
        wpsu_clear_update_transient($target_theme_slug);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not get filesystem credentials. Check wp-config.php settings.', 'wp-seamless-update' ) );
        return;
    }

    // 尝试初始化文件系统
    error_log("WP Seamless Update Cron: 尝试使用方法 $fs_method 初始化WP_Filesystem");
    global $wp_filesystem; // Declare global before calling WP_Filesystem

    if ( ! WP_Filesystem( $creds ) ) {
        error_log("WP Seamless Update Cron: WP_Filesystem() 返回 false。检查文件系统权限/方法。中止。");
        wpsu_clear_update_transient($target_theme_slug);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not initialize filesystem. Check credentials/permissions.', 'wp-seamless-update' ) );
        return;
    }

    // Add an explicit check for the global variable AFTER calling WP_Filesystem()
    if ( ! $wp_filesystem ) {
         error_log("WP Seamless Update Cron: WP_Filesystem 初始化后全局 \\$wp_filesystem 变量仍未设置。中止。");
         wpsu_clear_update_transient($target_theme_slug);
         update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Filesystem object not available after initialization.', 'wp-seamless-update' ) );
         return;
    }

    // 验证文件系统对主题目录的访问权限
    error_log("WP Seamless Update Cron: 验证文件系统对主题目录的访问权限");
    if ( ! $wp_filesystem->exists($theme_root) || ! $wp_filesystem->is_dir($theme_root) ) {
        error_log("WP Seamless Update Cron: 无法访问主题目录 $theme_root。可能是权限问题。中止更新。");
        wpsu_clear_update_transient($target_theme_slug);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not access theme directory. Check permissions.', 'wp-seamless-update' ) );
        return;
    }
    
    // 测试写入权限
    $test_file = $theme_root . '/.wpsu-write-test-' . time();
    if ( ! $wp_filesystem->put_contents($test_file, 'Writing test') ) {
        error_log("WP Seamless Update Cron: 无法写入主题目录。可能是权限问题。中止更新。");
        wpsu_clear_update_transient($target_theme_slug);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not write to theme directory. Check permissions.', 'wp-seamless-update' ) );
        return;
    } else {
        $wp_filesystem->delete($test_file);
        error_log("WP Seamless Update Cron: 文件系统写入测试成功");
    }

    // --- 第 1 步：下载并解压更新包 ---
    wpsu_set_update_progress($target_theme_slug, __('Step 1: Downloading update package...', 'wp-seamless-update'), 15);
    error_log("WP Seamless Update Cron: 第1步 - 开始下载更新包到 $temp_package_file");

    // --- 确保临时目录存在 ---
    if ( ! $wp_filesystem->is_dir( $temp_dir_base ) ) {
        error_log("WP Seamless Update Cron: 临时目录的父目录 $temp_dir_base 不存在，尝试创建。");
        if ( ! $wp_filesystem->mkdir( $temp_dir_base, FS_CHMOD_DIR, true ) ) { // 使用递归创建
            error_log("WP Seamless Update Cron: 创建临时目录的父目录失败: $temp_dir_base。中止。");
            wpsu_set_update_progress($target_theme_slug, __('Failed to create temporary base directory', 'wp-seamless-update'), 100, true);
            update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not create temporary base directory.', 'wp-seamless-update' ) );
            return;
        }
        error_log("WP Seamless Update Cron: 成功创建临时目录的父目录 $temp_dir_base。");
    }
    if ( ! $wp_filesystem->mkdir( $temp_dir, FS_CHMOD_DIR ) ) {
        error_log("WP Seamless Update Cron: 创建临时工作目录失败: $temp_dir。中止。");
        wpsu_set_update_progress($target_theme_slug, __('Failed to create temporary directory', 'wp-seamless-update'), 100, true);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not create temporary directory.', 'wp-seamless-update' ) );
        return;
    }
    // --- 结束确保目录 ---

    $download_ok = false; // 假设失败
    $package_local_path = null; // 下载的包的本地路径

    if ( ! function_exists('download_url') ) { // 确保 download_url 可用
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }

    error_log("WP Seamless Update Cron: 下载更新包从 {$remote_info->package_url}");
    $package_local_path = download_url( $remote_info->package_url, 120 ); // 增加超时时间到120秒

    if ( is_wp_error( $package_local_path ) ) {
        $error_message = $package_local_path->get_error_message();
        error_log( "WP Seamless Update Cron: 下载更新包 {$remote_info->package_url} 失败。错误: " . $error_message );
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, 
            sprintf( __( 'Update failed: Could not download update package. Error: %s', 'wp-seamless-update' ), 
            $error_message ) );
    } else {
        error_log("WP Seamless Update Cron: 更新包下载成功到: $package_local_path");
        wpsu_set_update_progress($target_theme_slug, __('Verifying package integrity...', 'wp-seamless-update'), 25);

        // 下载后验证哈希 (假设服务器提供的是 SHA1 哈希)
        $package_content = $wp_filesystem->get_contents( $package_local_path );
        if ($package_content === false) {
             error_log( "WP Seamless Update Cron: 无法读取下载的包文件 {$package_local_path} 进行哈希验证。中止。" );
             $wp_filesystem->delete( $package_local_path ); // 清理失败的下载
             update_option( 'wpsu_last_check_status_' . $target_theme_slug, 
                __( 'Update failed: Could not read downloaded package for verification.', 'wp-seamless-update' ) );
        } else {
            $downloaded_hash = sha1( $package_content );
            unset($package_content); // 释放内存

            if ( $downloaded_hash !== $remote_info->package_hash ) {
                error_log( "WP Seamless Update Cron: 下载的包哈希值不匹配。期望: {$remote_info->package_hash}, 实际: {$downloaded_hash}。中止。" );
                $wp_filesystem->delete( $package_local_path ); // 清理损坏的下载
                update_option( 'wpsu_last_check_status_' . $target_theme_slug, 
                    __( 'Update failed: Package integrity check failed.', 'wp-seamless-update' ) );
            } else {
                error_log("WP Seamless Update Cron: 包哈希验证成功。");
                // 将验证后的包移动到我们的临时目录中
                if ( ! $wp_filesystem->move( $package_local_path, $temp_package_file, true ) ) {
                    error_log( "WP Seamless Update Cron: 无法将验证的包从 {$package_local_path} 移动到 {$temp_package_file}。中止。" );
                    if ($wp_filesystem->exists($package_local_path)) $wp_filesystem->delete( $package_local_path ); // 清理
                    update_option( 'wpsu_last_check_status_' . $target_theme_slug, 
                        __( 'Update failed: Could not move downloaded package to temporary location.', 'wp-seamless-update' ) );
                } else {
                    $package_local_path = $temp_package_file; // 更新路径为最终位置
                    error_log("WP Seamless Update Cron: 成功将包移动到 $package_local_path");
                    
                    // --- 解压包 ---
                    wpsu_set_update_progress($target_theme_slug, __('Extracting update package...', 'wp-seamless-update'), 30);
                    error_log("WP Seamless Update Cron: 开始解压包 $package_local_path 到 $temp_extract_dir");
                    
                    // 创建解压目录
                    if ( ! $wp_filesystem->mkdir( $temp_extract_dir, FS_CHMOD_DIR ) ) {
                        error_log("WP Seamless Update Cron: 创建解压目录失败: $temp_extract_dir。中止。");
                        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not create extraction directory.', 'wp-seamless-update' ) );
                    } else {
                        // 使用 WP_Filesystem 解压
                        $unzip_result = unzip_file( $package_local_path, $temp_extract_dir );

                        if ( is_wp_error( $unzip_result ) ) {
                            $error_message = $unzip_result->get_error_message();
                            error_log( "WP Seamless Update Cron: 解压包 {$package_local_path} 失败。错误: " . $error_message );
                            update_option( 'wpsu_last_check_status_' . $target_theme_slug, 
                                sprintf( __( 'Update failed: Could not extract update package. Error: %s', 'wp-seamless-update' ), 
                                $error_message ) );
                        } else {
                            error_log("WP Seamless Update Cron: 包解压成功到 $temp_extract_dir");
                            $download_ok = true; // 只有到这里才算成功
                        }
                    }
                }
            }
        }
    }

    if ( ! $download_ok ) {
        error_log("WP Seamless Update Cron: 下载或解压阶段失败。清理临时目录 $temp_dir。");
        $wp_filesystem->delete( $temp_dir, true ); // 会删除包和可能的解压内容
        wpsu_clear_update_transient($target_theme_slug); // 清除通知，因为更新失败
        wpsu_set_update_progress($target_theme_slug, __('Download or extraction failed. Update aborted.', 'wp-seamless-update'), 100, true);
        return;
    }
    error_log("WP Seamless Update Cron: 第1步 - 下载和解压完成。");
    wpsu_set_update_progress(
        $target_theme_slug,
        __('Update package downloaded and extracted', 'wp-seamless-update'),
        40
    );

    // --- 第 2 步：备份实时主题 ---
    if ($backups_to_keep <= 0) {
         error_log("WP Seamless Update Cron: 第2步 - 备份已禁用。跳过备份。");
         $backup_ok = true; // 如果禁用，则视为成功
         $backup_dir = null; // 稍后没有备份目录可用
         wpsu_set_update_progress($target_theme_slug, __('Step 2: Backup disabled, skipping...', 'wp-seamless-update'), 45);
    } else {
        wpsu_set_update_progress($target_theme_slug, __('Step 2: Creating backup...', 'wp-seamless-update'), 45);
        error_log("WP Seamless Update Cron: 第2步 - 开始备份实时主题 $theme_root 到 $backup_dir");
        if ( ! $wp_filesystem->is_dir( $backup_dir_base ) ) {
            if ( ! $wp_filesystem->mkdir( $backup_dir_base, FS_CHMOD_DIR ) ) {
                 error_log("WP Seamless Update Cron: 创建基础备份目录失败: $backup_dir_base。中止。");
                 $wp_filesystem->delete( $temp_dir, true ); // 清理临时目录
                 update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not create backup directory.', 'wp-seamless-update' ) );
                 return;
            }
        }
        if ( ! $wp_filesystem->mkdir( $backup_dir, FS_CHMOD_DIR ) ) {
            error_log("WP Seamless Update Cron: 创建特定备份目录失败: $backup_dir。中止。");
            $wp_filesystem->delete( $temp_dir, true ); // 清理临时目录
            update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not create specific backup directory.', 'wp-seamless-update' ) );
            return;
        }

        error_log("WP Seamless Update Cron: 开始复制主题文件进行备份");
        wpsu_set_update_progress($target_theme_slug, __('Creating theme backup...', 'wp-seamless-update'), 50);
        $backup_result = copy_dir( $theme_root, $backup_dir ); // 隐式使用 WP_Filesystem

        if ( is_wp_error( $backup_result ) || $backup_result === false ) {
            $error_msg = is_wp_error($backup_result) ? $backup_result->get_error_message() : 'copy_dir returned false';
            error_log("WP Seamless Update Cron: 备份主题到 $backup_dir 失败。错误: $error_msg。中止。");
            $wp_filesystem->delete( $backup_dir, true ); // 清理失败的备份尝试
            $wp_filesystem->delete( $temp_dir, true ); // 清理临时目录
            wpsu_clear_update_transient($target_theme_slug);
            wpsu_set_update_progress($target_theme_slug, sprintf(__('Backup failed: %s', 'wp-seamless-update'), $error_msg), 100, true);
            update_option( 'wpsu_last_check_status_' . $target_theme_slug, sprintf( __( 'Update failed: Could not backup theme. Error: %s', 'wp-seamless-update' ), $error_msg ) );
            return;
        }
        $backup_ok = true;
        error_log("WP Seamless Update Cron: 第2步 - 备份完成。");
        wpsu_set_update_progress($target_theme_slug, __('Backup completed successfully', 'wp-seamless-update'), 55);
    }

    // --- 第 3 步：通过复制实时主题创建暂存目录 ---
    wpsu_set_update_progress($target_theme_slug, __('Step 3: Preparing staging area...', 'wp-seamless-update'), 60);
    error_log("WP Seamless Update Cron: 第3步 - 创建暂存目录 $staging_dir (复制 $theme_root)");
    if ( ! $wp_filesystem->mkdir( $staging_dir, FS_CHMOD_DIR ) ) {
         error_log("WP Seamless Update Cron: 创建暂存目录失败: $staging_dir。中止。");
         if ($backup_dir) $wp_filesystem->delete( $backup_dir, true ); // 清理备份
         $wp_filesystem->delete( $temp_dir, true ); // 清理临时
         wpsu_set_update_progress($target_theme_slug, __('Failed to create staging directory', 'wp-seamless-update'), 100, true);
         update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not create staging directory.', 'wp-seamless-update' ) );
         return;
    }
    wpsu_set_update_progress($target_theme_slug, __('Creating staging copy of theme...', 'wp-seamless-update'), 65);
    $copy_to_staging_result = copy_dir( $theme_root, $staging_dir );
    if ( is_wp_error( $copy_to_staging_result ) || $copy_to_staging_result === false ) {
        $error_msg = is_wp_error($copy_to_staging_result) ? $copy_to_staging_result->get_error_message() : 'copy_dir returned false';
        error_log("WP Seamless Update Cron: 复制实时主题到暂存目录 $staging_dir 失败。错误: $error_msg。中止。");
        $wp_filesystem->delete( $staging_dir, true ); // 清理失败的临时目录
        if ($backup_dir) $wp_filesystem->delete( $backup_dir, true ); // 清理备份
        $wp_filesystem->delete( $temp_dir, true ); // 清理临时
        wpsu_clear_update_transient($target_theme_slug);
        wpsu_set_update_progress($target_theme_slug, sprintf(__('Failed to create staging copy: %s', 'wp-seamless-update'), $error_msg), 100, true);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, sprintf( __( 'Update failed: Could not copy theme to staging directory. Error: %s', 'wp-seamless-update' ), $error_msg ) );
        return;
    }
    error_log("WP Seamless Update Cron: 第3步 - 暂存目录创建完成。");
    wpsu_set_update_progress($target_theme_slug, __('Staging area prepared successfully', 'wp-seamless-update'), 70);

    // --- 第 4 步：将更新（来自解压目录）应用到暂存目录 ---
    wpsu_set_update_progress($target_theme_slug, __('Step 4: Applying updates to staging area...', 'wp-seamless-update'), 75);
    error_log("WP Seamless Update Cron: 第 4 步 - 将更新从 $temp_extract_dir 应用到暂存目录 $staging_dir");
    $apply_ok = true;
    $files_applied_count = 0;

    // 遍历远程信息中列出的包内文件
    foreach ($remote_info->files as $file_info) {
        // 兼容旧格式（对象）和新格式（仅路径字符串）
        $relative_path = is_object($file_info) ? ltrim( wp_normalize_path($file_info->path), '/' ) : ltrim( wp_normalize_path($file_info), '/' );
        
        $source_file = trailingslashit($temp_extract_dir) . $relative_path;
        $dest_file = trailingslashit($staging_dir) . $relative_path;
        $dest_parent_dir = dirname($dest_file);

        // 检查源文件是否存在于解压目录中
        if ( ! $wp_filesystem->exists( $source_file ) ) {
            error_log( "WP Seamless Update Cron: 警告 - 包清单中列出的文件在解压目录中未找到: $relative_path。跳过此文件。" );
            // 可能不是致命错误，但需要记录
            continue; 
        }

        // 确保暂存目录中父目录存在
        if ( ! $wp_filesystem->is_dir( $dest_parent_dir ) ) {
            if ( ! $wp_filesystem->mkdir( $dest_parent_dir, FS_CHMOD_DIR, true ) ) {
                error_log( "WP Seamless Update Cron: 无法在暂存目录中创建父目录: {$dest_parent_dir}。中止应用。" );
                $apply_ok = false;
                break;
            }
        }

        // 将文件从解压目录移动到暂存目录，如果存在则覆盖
        // 注意：这里使用 move 比 copy 更高效，因为解压目录是临时的
        if ( ! $wp_filesystem->move( $source_file, $dest_file, true ) ) {
            error_log( "WP Seamless Update Cron: 无法将文件从解压目录 {$source_file} 移动到暂存目录 {$dest_file}。中止应用。" );
            $apply_ok = false;
            break;
        }
        $files_applied_count++;
        // 不要记录每个文件的移动，太详细了。稍后记录摘要。
    }

    // --- 注意：不再需要删除文件逻辑 ---
    // 因为我们是从一个干净的实时副本开始，并且只覆盖/添加包中的文件。
    // 如果需要完全同步（包括删除），则需要服务器提供完整的最终文件列表，
    // 然后在此处添加一个比较和删除的步骤。当前逻辑是“部分更新”。

    if ( ! $apply_ok ) {
        error_log("WP Seamless Update Cron: 第 4 步 - 将更新应用到暂存目录失败。正在回滚。");
        // 传递 $temp_dir 用于清理，它包含了 $temp_extract_dir 和 $temp_package_file
        wpsu_rollback_update( $target_theme_slug, $backup_dir, $theme_root, $staging_dir, $temp_dir ); 
        // 回滚函数清除 transient
        return;
    }
    error_log("WP Seamless Update Cron: 第 4 步 - 应用完成。已应用/覆盖 $files_applied_count 个文件。");
    wpsu_set_update_progress($target_theme_slug, sprintf(__('Applied %d file updates to staging area', 'wp-seamless-update'), $files_applied_count), 85);

    // --- 第 5 步：（可选）验证 ---
    // 如果需要，在这里添加检查（例如，检查 style.css 是否存在于 $staging_dir）

    // --- 第 6 步：原子切换（删除实时，将暂存移动到实时）---
    wpsu_set_update_progress($target_theme_slug, __('Step 6: Activating the new version...', 'wp-seamless-update'), 90);
    error_log("WP Seamless Update Cron: 第 6 步 - 执行切换: 删除 $theme_root 并将 $staging_dir 移动到 $theme_root");
    $switch_ok = true;

    // 删除原始主题目录
    if ( ! $wp_filesystem->delete( $theme_root, true ) ) {
        // 如果删除失败，可能是权限问题或目录已经不存在？
        if ( $wp_filesystem->exists( $theme_root ) ) {
            error_log("WP Seamless Update Cron: 在切换过程中无法删除原始主题目录 $theme_root。严重错误。尝试回滚。");
            $switch_ok = false;
        } else {
             error_log("WP Seamless Update Cron: 原始主题目录 $theme_root 在移动之前不存在（可能是手动删除的？）。继续移动.");
        }
    }

    // 将暂存目录移动到原始主题目录位置
    if ( $switch_ok ) {
        if ( ! $wp_filesystem->move( $staging_dir, $theme_root, true ) ) {
            error_log("WP Seamless Update Cron: 在切换过程中无法将暂存目录 $staging_dir 移动到 $theme_root。严重错误。尝试回滚。");
            $switch_ok = false;
        }
    }

    if ( ! $switch_ok ) {
        error_log("WP Seamless Update Cron: 第 6 步 - 切换失败。正在回滚。");
        // 尝试回滚 - 如果原始文件已部分删除，这可能会很棘手
        wpsu_rollback_update( $target_theme_slug, $backup_dir, $theme_root, $staging_dir, $temp_dir );
        // 回滚函数清除 transient
        return;
    }
    error_log("WP Seamless Update Cron: 第 6 步 - 切换成功。");
    wpsu_set_update_progress($target_theme_slug, __('New version activated', 'wp-seamless-update'), 95);

    // --- 第 7 步：完成 ---
    wpsu_set_update_progress($target_theme_slug, __('Step 7: Finalizing update...', 'wp-seamless-update'), 98);
    error_log("WP Seamless Update Cron: 第 7 步 - 最终更新。");

    // 清除此主题的更新 transient 标记
    wpsu_clear_update_transient($target_theme_slug);

    // 管理备份（删除旧的）
    if ($backup_dir) { // 仅当备份已启用/创建时管理
        wpsu_manage_backups( $target_theme_slug, $backup_dir_base, $backups_to_keep );
    }

    // 清理临时下载和解压目录
    error_log("WP Seamless Update Cron: 清理临时工作目录 $temp_dir。");
    $wp_filesystem->delete( $temp_dir, true ); // 这会删除包和解压目录

    // 清理可能残留的暂存目录（如果切换成功，它已经被移动了，但以防万一）
    if ($wp_filesystem->exists($staging_dir)) {
        error_log("WP Seamless Update Cron: 清理残留的暂存目录 $staging_dir。");
        $wp_filesystem->delete( $staging_dir, true );
    }

    update_option( 'wpsu_last_check_status_' . $target_theme_slug, sprintf( __( 'Update successful. Remote Internal Version: %s (Local INT_VERSION should reflect this after update).', 'wp-seamless-update' ), $remote_info->internal_version ) );
    wpsu_set_update_progress($target_theme_slug, sprintf(__('Update successful to internal version %s', 'wp-seamless-update'), $remote_info->internal_version), 100);
    error_log( "WP Seamless Update Cron: ====== 更新成功完成，主题 {$target_theme_slug}。已应用更新，远程内部版本: {$remote_info->internal_version}。如果更新包含 functions.php， 本地 INT_VERSION 常量现在应匹配。 ======" );

} catch (Exception $e) {
        // 捕获并记录异常
        $error_message = $e->getMessage();
        $error_trace = $e->getTraceAsString();
        error_log("WP Seamless Update错误: " . $error_message);
        error_log("错误堆栈跟踪: " . $error_trace);
        
        // 设置错误状态
        wpsu_set_update_progress(
            $target_theme_slug,
            sprintf(__('Update failed with error: %s', 'wp-seamless-update'), $error_message),
            100,
            true
        );
        // 尝试清理
        if (isset($wp_filesystem) && $wp_filesystem) {
            if (isset($temp_dir) && $wp_filesystem->exists($temp_dir)) $wp_filesystem->delete($temp_dir, true);
            if (isset($staging_dir) && $wp_filesystem->exists($staging_dir)) $wp_filesystem->delete($staging_dir, true);
            // 保留备份目录以供检查
        }
        wpsu_clear_update_transient($target_theme_slug);

    } catch (Error $e) {
        // PHP 7+ 还支持捕获致命错误
        $error_message = $e->getMessage();
        $error_trace = $e->getTraceAsString();
        error_log("WP Seamless Update致命错误: " . $error_message);
        error_log("错误堆栈跟踪: " . $error_trace);
        
        // 设置错误状态
        wpsu_set_update_progress(
            $target_theme_slug,
            sprintf(__('Update failed with fatal error: %s', 'wp-seamless-update'), $error_message),
            100,
            true
        );
        // 尝试清理
        if (isset($wp_filesystem) && $wp_filesystem) {
            if (isset($temp_dir) && $wp_filesystem->exists($temp_dir)) $wp_filesystem->delete($temp_dir, true);
            if (isset($staging_dir) && $wp_filesystem->exists($staging_dir)) $wp_filesystem->delete($staging_dir, true);
            // 保留备份目录以供检查
        }
        wpsu_clear_update_transient($target_theme_slug);
    }
}

/**
 * 从备份回滚主题更新。清理临时和暂存目录。
 *
 * @param string $theme_slug 主题 slug。
 * @param string|null $backup_dir 备份目录的路径（如果禁用备份，则为 null）。
 * @param string $theme_root 主题目录路径（应该在哪里）。
 * @param string $staging_dir 更新期间使用的暂存目录的路径。
 * @param string $temp_dir 临时工作目录的路径 (包含包和解压内容)。
 */
function wpsu_rollback_update( $theme_slug, $backup_dir, $theme_root, $staging_dir, $temp_dir ) {
    global $wp_filesystem;
    $rollback_status = 'Rollback failed.'; // 默认状态

    error_log("WP Seamless Update Rollback: 开始回滚 $theme_slug。");

    // 确保文件系统可用
    if ( ! $wp_filesystem ) {
         error_log("WP Seamless Update Rollback: $theme_slug 回滚时文件系统未初始化。无法继续。");
         wpsu_clear_update_transient($theme_slug); // 即使回滚在此处失败，也清除通知
         update_option( 'wpsu_last_check_status_' . $theme_slug, __( 'Update failed. Rollback failed (Filesystem unavailable).', 'wp-seamless-update' ) );
         return;
    }

    // 首先清理暂存目录和临时工作目录
    if ( $wp_filesystem->exists( $staging_dir ) ) {
        error_log("WP Seamless Update Rollback: 删除暂存目录 $staging_dir。");
        $wp_filesystem->delete( $staging_dir, true );
    }
    if ( $wp_filesystem->exists( $temp_dir ) ) {
        error_log("WP Seamless Update Rollback: 删除临时工作目录 $temp_dir。");
        $wp_filesystem->delete( $temp_dir, true );
    }

    // 检查备份是否存在（且已启用）
    if ( ! $backup_dir || ! $wp_filesystem->exists( $backup_dir ) || ! $wp_filesystem->is_dir( $backup_dir ) ) {
        error_log("WP Seamless Update Rollback: 备份目录 $backup_dir 未找到或已禁用备份 $theme_slug。无法从备份恢复。");
        // 如果原始主题目录仍然存在，可能保留它？如果它在切换过程中被删除，我们就有麻烦了。
        if (!$wp_filesystem->exists($theme_root)) {
             error_log("WP Seamless Update Rollback: 严重错误 - 原始主题目录 $theme_root 丢失，且没有可用的备份。");
             $rollback_status = __( 'Update failed. CRITICAL: Rollback failed (No backup available, theme directory missing). Manual intervention required.', 'wp-seamless-update' );
        } else {
             error_log("WP Seamless Update Rollback: 原始主题目录 $theme_root 仍然存在。由于没有可用的备份，保持不变。");
             $rollback_status = __( 'Update failed. Rollback skipped (No backup available, original theme directory untouched).', 'wp-seamless-update' );
        }
        wpsu_clear_update_transient($theme_slug);
        update_option( 'wpsu_last_check_status_' . $theme_slug, $rollback_status );
        return;
    }

    // --- 继续从备份恢复 ---
    error_log("WP Seamless Update Rollback: 尝试从备份 $backup_dir 恢复。");

    // 删除可能损坏/不完整的当前主题目录（如果存在）
    if ( $wp_filesystem->exists( $theme_root ) ) {
        error_log("WP Seamless Update Rollback: 在恢复之前删除当前主题目录: $theme_root。");
        if ( ! $wp_filesystem->delete( $theme_root, true ) ) {
            error_log("WP Seamless Update Rollback: 无法在为 $theme_slug 恢复备份之前删除当前主题目录 $theme_root。文件系统可能不一致。");
            // 这很糟糕，文件系统可能处于不一致状态。可能需要手动干预。
            $rollback_status = __( 'Update failed. Rollback failed (Could not delete existing theme directory). Manual intervention required.', 'wp-seamless-update' );
            wpsu_clear_update_transient($theme_slug);
            update_option( 'wpsu_last_check_status_' . $theme_slug, $rollback_status );
            return; // 如果删除失败，不要尝试恢复
        }
         error_log("WP Seamless Update Rollback: 成功删除可能损坏的主题目录 $theme_root。");
    } else {
         error_log("WP Seamless Update Rollback: 当前主题目录 $theme_root 不存在。继续恢复。");
    }

    // 使用 copy_dir 从备份恢复
    error_log("WP Seamless Update Rollback: 从备份 $backup_dir 恢复主题 $theme_slug 到 $theme_root。");
    $restore_result = copy_dir( $backup_dir, $theme_root );

    if ( is_wp_error( $restore_result ) || $restore_result === false ) {
        $error_msg = is_wp_error($restore_result) ? $restore_result->get_error_message() : 'copy_dir returned false';
        error_log("WP Seamless Update Rollback: 无法从备份 $backup_dir 恢复主题 $theme_slug。错误: $error_msg");
        // 主要问题 - 主题可能现在完全丢失。需要手动干预。
        $rollback_status = __( 'Update failed. CRITICAL: Rollback failed during restore from backup. Manual intervention required.', 'wp-seamless-update' );
    } else {
        error_log("WP Seamless Update Rollback: 成功从备份 $backup_dir 恢复主题 $theme_slug。");
        // 保留用于回滚的备份目录以供检查。不要在此处删除它。
        $rollback_status = __( 'Update failed. Successfully rolled back to previous version.', 'wp-seamless-update' );
    }

    // 清除任何更新通知，因为更新失败并已回滚（或尝试）
    wpsu_clear_update_transient($theme_slug);
    update_option( 'wpsu_last_check_status_' . $theme_slug, $rollback_status );
}

/**
 * 设置当前更新进度信息
 *
 * @param string $target_theme_slug 主题slug
 * @param string $message 进度消息
 * @param int $percent 完成百分比（0-100）
 * @param bool $is_error 是否为错误消息
 */
function wpsu_set_update_progress($target_theme_slug, $message, $percent = -1, $is_error = false) {
    $progress = array(
        'message' => $message,
        'percent' => $percent,
        'is_error' => $is_error,
        'time' => time(),
        'memory_usage' => size_format(memory_get_usage(true)), // 添加内存使用情况
        'timestamp' => date('Y-m-d H:i:s')                     // 添加可读时间戳
    );
    
    // 确保进度更新是立即写入数据库的
    $update_result = update_option('wpsu_update_progress_' . $target_theme_slug, $progress, false);
    
    // 详细记录每一步进度
    error_log("WP Seamless Update Progress: $message ($percent%) - Memory: {$progress['memory_usage']} - Time: {$progress['timestamp']} - DB Update: " . ($update_result ? 'success' : 'failed'));
    
    // 同时更新状态选项，以便兼容旧版API
    if ($percent >= 100) {
        if ($is_error) {
            update_option('wpsu_last_check_status_' . $target_theme_slug, sprintf(__('Update failed: %s', 'wp-seamless-update'), $message), false);
        } else {
            // 确保成功消息不包含 "Update in progress"
            $success_message = str_replace(__('Update in progress: ', 'wp-seamless-update'), '', $message);
            update_option('wpsu_last_check_status_' . $target_theme_slug, __('Update successful. ', 'wp-seamless-update') . $success_message, false);
        }
    } else {
        update_option('wpsu_last_check_status_' . $target_theme_slug, __('Update in progress: ', 'wp-seamless-update') . $message, false);
    }
    
    // 尝试强制刷新数据库缓存
    wp_cache_flush();
}

/**
 * 获取当前更新进度信息
 *
 * @param string $target_theme_slug 主题slug
 * @return array 进度信息数组
 */
function wpsu_get_update_progress($target_theme_slug) {
    $default = array(
        'message' => __('No update in progress', 'wp-seamless-update'),
        'percent' => -1,
        'is_error' => false,
        'time' => 0,
        'memory_usage' => '',
        'timestamp' => ''
    );
    
    // 使用 false 作为第三个参数，确保总是从数据库获取最新值，而不是从缓存
    $progress = get_option('wpsu_update_progress_' . $target_theme_slug, $default, false);
    
    // 记录获取的进度信息
    if ($progress['percent'] >= 0) {
        // error_log("WP Seamless Update: 获取进度信息 - 主题: $target_theme_slug, 消息: {$progress['message']}, 进度: {$progress['percent']}%, 时间: " . ($progress['time'] ? date('Y-m-d H:i:s', $progress['time']) : 'N/A'));
    }
    
    // 减少超时检测时间到2分钟，更快地发现问题
    if ($progress['time'] > 0 && (time() - $progress['time'] > 120)) {
        // 检测到更新过程已经超过2分钟没有更新进度
        if ($progress['percent'] >= 0 && $progress['percent'] < 100 && !$progress['is_error']) { // 仅在未完成且非错误状态下标记为超时
            $last_step = $progress['message'];
            error_log("WP Seamless Update: 检测到更新过程可能超时或卡住，最后进度: {$progress['percent']}%, 最后消息: $last_step");
            
            // 设置超时错误状态
            wpsu_set_update_progress(
                $target_theme_slug, 
                sprintf(__('Update process stalled at "%s". Server may have timed out.', 'wp-seamless-update'), $last_step),
                100,
                true
            );
            
            // 尝试获取并返回更新后的进度信息
            wp_cache_flush(); // 确保获取最新值
            return get_option('wpsu_update_progress_' . $target_theme_slug, $default, false);
        }
    }
    
    return $progress;
}

/**
 * 清除更新进度信息
 *
 * @param string $target_theme_slug 主题slug
 */
function wpsu_clear_update_progress($target_theme_slug) {
    delete_option('wpsu_update_progress_' . $target_theme_slug);
}

// --- 函数 wpsu_cleanup_temp_dirs 已移至 helpers.php ---
// [REMOVED DUPLICATE FUNCTION DEFINITION for wpsu_cleanup_temp_dirs]

// --- 函数 wpsu_manage_backups 已移至 helpers.php ---
// [REMOVED DUPLICATE FUNCTION DEFINITION for wpsu_manage_backups]

// --- 函数 wpsu_flatten_dirlist 已移至 helpers.php ---
// [REMOVED DUPLICATE FUNCTION DEFINITION for wpsu_flatten_dirlist]

?>
