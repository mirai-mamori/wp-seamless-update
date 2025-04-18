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
 * 实现模拟的 A/B 更新过程。
 *
 * @param string $target_theme_slug 要更新的主题的 slug。
 */
function wpsu_perform_seamless_update( $target_theme_slug ) {
    // 设置执行时间限制为5分钟（300秒），避免脚本超时
    @set_time_limit(300);
    
    // 增加详细的开始日志，包含环境信息
    error_log("WP Seamless Update Cron: ====== 开始为主题 $target_theme_slug 执行更新 ======");
    error_log("WP Seamless Update: 环境信息 - PHP版本: " . phpversion() . ", WordPress版本: " . get_bloginfo('version') . ", 内存限制: " . ini_get('memory_limit') . ", 最大执行时间: " . ini_get('max_execution_time') . "秒");
    
    // 清除旧的进度信息并设置初始进度
    wpsu_clear_update_progress($target_theme_slug);
    wpsu_set_update_progress($target_theme_slug, __('Starting update process...', 'wp-seamless-update'), 0);
    
    // 添加错误处理
    try {

    $options = get_option( WPSU_OPTION_NAME, array() );
    $configured_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;
    $update_url = isset( $options['update_url'] ) ? $options['update_url'] : null;
    $backups_to_keep = isset( $options['backups_to_keep'] ) ? absint($options['backups_to_keep']) : WPSU_DEFAULT_BACKUPS_TO_KEEP;    // --- 初始检查 ---
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
    }    if ( $current_internal_version === null ) {
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

    // 临时下载目录
    $temp_dir_base = $uploads_base . WPSU_TEMP_UPDATE_DIR_BASE . '/';
    $temp_dir = $temp_dir_base . $target_theme_slug . '-' . $timestamp . '/';
    error_log("WP Seamless Update Cron: 临时下载目录: $temp_dir");

    // 备份目录
    $backup_dir_base = $uploads_base . WPSU_BACKUP_DIR_BASE . '/';
    $backup_dir = $backup_dir_base . $target_theme_slug . '-' . $timestamp . '/';
    error_log("WP Seamless Update Cron: 备份目录: $backup_dir");

    // 临时目录（更新应用于此处，然后切换）
    $staging_dir = $uploads_base . 'wpsu-staging-' . $target_theme_slug . '-' . $timestamp . '/';
    error_log("WP Seamless Update Cron: 暂存目录: $staging_dir");

    // 清理之前失败尝试中可能遗留的临时目录（可选但是良好做法）
    wpsu_cleanup_temp_dirs($wp_filesystem, $uploads_base, $target_theme_slug);    // 重新获取远程信息以确保它是最新的
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
    
    // 记录文件系统方法
    $fs_method = get_filesystem_method();
    error_log("WP Seamless Update Cron: 文件系统方法: $fs_method");
    
    // 尝试非交互式获取凭据（用于 cron）
    $creds = request_filesystem_credentials( site_url(), '', false, false, null );
    if ( false === $creds ) {
        error_log("WP Seamless Update Cron: 无法获取文件系统凭据（非交互式）。检查wp-config.php中是否定义了FS_METHOD。中止。");
        // 在 cron 中没有文件系统访问权限，无法继续
        wpsu_clear_update_transient($target_theme_slug); // 清除通知，因为更新无法继续
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not get filesystem credentials. Check wp-config.php settings.', 'wp-seamless-update' ) );
        return;
    }

    // 尝试初始化文件系统
    error_log("WP Seamless Update Cron: 尝试使用方法 $fs_method 初始化WP_Filesystem");
    global $wp_filesystem; // Declare global before calling WP_Filesystem

    if ( ! WP_Filesystem( $creds ) ) {
        // WP_Filesystem() failed to initialize, maybe couldn't connect based on creds
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
    }    // --- 第 1 步：下载文件到临时目录 ---
    wpsu_set_update_progress($target_theme_slug, __('Step 1: Preparing to download files...', 'wp-seamless-update'), 15);
    error_log("WP Seamless Update Cron: 第1步 - 开始下载文件到 $temp_dir");

    // --- 新增：确保父目录存在 ---
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
    // --- 结束新增代码 ---

    if ( ! $wp_filesystem->mkdir( $temp_dir, FS_CHMOD_DIR ) ) {
        error_log("WP Seamless Update Cron: 创建临时目录失败: $temp_dir。中止。");
        wpsu_set_update_progress($target_theme_slug, __('Failed to create temporary directory', 'wp-seamless-update'), 100, true);
        update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not create temporary directory.', 'wp-seamless-update' ) );
        return;
    }

    $downloaded_files = array();
    $download_ok = true;
    if ( ! function_exists('download_url') ) { // 确保 download_url 可用
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }
    
    error_log("WP Seamless Update Cron: 总共需要下载 " . count($remote_info->files) . " 个文件");
    wpsu_set_update_progress($target_theme_slug, sprintf(__('Downloading %d files...', 'wp-seamless-update'), count($remote_info->files)), 20);
    
    $file_count = count($remote_info->files);
    $files_downloaded = 0;

    foreach ( $remote_info->files as $file_info ) {
        $relative_path = ltrim( wp_normalize_path($file_info->path), '/' );
        $temp_file_path = trailingslashit($temp_dir) . $relative_path;
        $remote_file_url = $file_info->url;
        $remote_file_hash = $file_info->hash; // 假设是 SHA1

        error_log("WP Seamless Update Cron: 下载文件 $relative_path 从 $remote_file_url");

        // 确保临时目录中父目录存在
        $parent_dir = dirname( $temp_file_path );
        if ( ! $wp_filesystem->is_dir( $parent_dir ) ) {
            if ( ! $wp_filesystem->mkdir( $parent_dir, FS_CHMOD_DIR, true ) ) {
                error_log( "WP Seamless Update Cron: 创建临时目录中的父目录失败: {$parent_dir}。中止下载。" );
                $download_ok = false;
                break;
            }
        }

        $temp_download = download_url( $remote_file_url, 60 ); // 增加超时时间到60秒

        if ( is_wp_error( $temp_download ) ) {
            $error_message = $temp_download->get_error_message();
            error_log( "WP Seamless Update Cron: 下载 {$remote_file_url} 失败。错误: " . $error_message );
            $download_ok = false;
            update_option( 'wpsu_last_check_status_' . $target_theme_slug, 
                sprintf( __( 'Update failed: Could not download %s. Error: %s', 'wp-seamless-update' ), 
                $relative_path, $error_message ) );
            break;
        }

        // 下载后验证哈希（使用与服务器相同的哈希算法 - 此处为 SHA1）
        $downloaded_content = $wp_filesystem->get_contents( $temp_download );
        if ($downloaded_content === false) {
             error_log( "WP Seamless Update Cron: 无法读取下载的临时文件 {$temp_download} 进行哈希验证。中止。" );
             $wp_filesystem->delete( $temp_download ); // 清理失败的下载
             $download_ok = false;
             update_option( 'wpsu_last_check_status_' . $target_theme_slug, 
                sprintf( __( 'Update failed: Could not read downloaded file %s for verification.', 'wp-seamless-update' ), 
                $relative_path ) );
             break;
        }
        $downloaded_hash = sha1( $downloaded_content );

        if ( $downloaded_hash !== $remote_file_hash ) {
            error_log( "WP Seamless Update Cron: 下载文件 {$relative_path} 哈希值不匹配。期望: {$remote_file_hash}, 实际: {$downloaded_hash}。中止。" );
            $wp_filesystem->delete( $temp_download ); // 清理损坏的下载
            $download_ok = false;
            update_option( 'wpsu_last_check_status_' . $target_theme_slug, 
                sprintf( __( 'Update failed: File integrity check failed for %s.', 'wp-seamless-update' ), 
                $relative_path ) );
            break;
        }

        // 将已验证的下载移动到临时目录中的最终位置
        if ( ! $wp_filesystem->move( $temp_download, $temp_file_path, true ) ) {
             error_log( "WP Seamless Update Cron: 无法将验证的下载文件从 {$temp_download} 移动到 {$temp_file_path}。中止。" );
             if ($wp_filesystem->exists($temp_download)) $wp_filesystem->delete( $temp_download ); // 清理
             $download_ok = false;
             update_option( 'wpsu_last_check_status_' . $target_theme_slug, 
                sprintf( __( 'Update failed: Could not move downloaded file %s to temporary location.', 'wp-seamless-update' ), 
                $relative_path ) );
             break;
        }
        $downloaded_files[$relative_path] = $temp_file_path; // 记录
    }    if ( ! $download_ok ) {
        error_log("WP Seamless Update Cron: 下载阶段失败。清理临时目录 $temp_dir。");
        $wp_filesystem->delete( $temp_dir, true );
        wpsu_clear_update_transient($target_theme_slug); // 清除通知，因为更新失败
        wpsu_set_update_progress($target_theme_slug, __('Download failed. Update aborted.', 'wp-seamless-update'), 100, true);
        return;
    }
    error_log("WP Seamless Update Cron: 第1步 - 下载完成。成功下载 " . count($downloaded_files) . " 个文件。");
    wpsu_set_update_progress(
        $target_theme_slug,
        sprintf(__('Successfully downloaded %d files', 'wp-seamless-update'), count($downloaded_files)),
        40
    );    // --- 第 2 步：备份实时主题 ---
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

        error_log("WP Seamless Update Cron: 开始复制主题文件进行备份");        wpsu_set_update_progress($target_theme_slug, __('Creating theme backup...', 'wp-seamless-update'), 50);
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
    }    // --- 第 3 步：通过复制实时主题创建临时目录 ---
    wpsu_set_update_progress($target_theme_slug, __('Step 3: Preparing staging area...', 'wp-seamless-update'), 60);
    error_log("WP Seamless Update Cron: 第3步 - 创建暂存目录 $staging_dir (复制 $theme_root)");
    if ( ! $wp_filesystem->mkdir( $staging_dir, FS_CHMOD_DIR ) ) {
         error_log("WP Seamless Update Cron: 创建暂存目录失败: $staging_dir。中止。");
         if ($backup_dir) $wp_filesystem->delete( $backup_dir, true ); // 清理备份
         $wp_filesystem->delete( $temp_dir, true ); // 清理临时
         wpsu_set_update_progress($target_theme_slug, __('Failed to create staging directory', 'wp-seamless-update'), 100, true);
         update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Update failed: Could not create staging directory.', 'wp-seamless-update' ) );
         return;
    }    wpsu_set_update_progress($target_theme_slug, __('Creating staging copy of theme...', 'wp-seamless-update'), 65);
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

    // --- 第 4 步：将更新（来自临时目录）和删除应用到临时目录 ---
    error_log("WP Seamless Update Cron: 第 4 步 - 将更新应用到临时目录 $staging_dir");
    $apply_ok = true;
    $files_updated_count = 0;
    $files_deleted_count = 0;

    // 获取新版本中预期的文件列表（相对路径）
    $remote_file_paths = array();
    foreach ($remote_info->files as $file_info) {
        $remote_file_paths[] = ltrim( wp_normalize_path($file_info->path), '/' );
    }
    $remote_file_paths_set = array_flip($remote_file_paths); // 用于快速查找

    // 应用已下载的文件（从临时移动到临时目录）
    foreach ($downloaded_files as $relative_path => $temp_file_path) {
        $staging_file_path = trailingslashit($staging_dir) . $relative_path;
        $staging_parent_dir = dirname($staging_file_path);

        // 确保临时目录中父目录存在
        if ( ! $wp_filesystem->is_dir( $staging_parent_dir ) ) {
            if ( ! $wp_filesystem->mkdir( $staging_parent_dir, FS_CHMOD_DIR, true ) ) {
                error_log( "WP Seamless Update Cron: 无法在临时目录中创建父目录: {$staging_parent_dir}。中止应用。" );
                $apply_ok = false;
                break;
            }
        }

        // 将文件从临时移动到临时目录，如果存在则覆盖
        if ( ! $wp_filesystem->move( $temp_file_path, $staging_file_path, true ) ) {
            error_log( "WP Seamless Update Cron: 无法将文件从临时目录 {$temp_file_path} 移动到临时目录 {$staging_file_path}。中止应用。" );
            $apply_ok = false;
            break;
        }
        $files_updated_count++;
        // 不要记录每个文件的移动，太详细了。稍后记录摘要。
    }

    // 如果应用仍然正常，则处理文件删除
    if ($apply_ok) {
        error_log("WP Seamless Update Cron: 检查临时目录中需要删除的文件。");
        $staging_files_list = $wp_filesystem->dirlist( $staging_dir, true, true ); // 递归，包括隐藏文件

        if ($staging_files_list === false) {
             error_log("WP Seamless Update Cron: 无法列出临时目录 $staging_dir 中的文件以进行删除检查。跳过删除操作。");
             // 决定是否关键 - 也许不删除继续？现在，继续但记录它。
        } else {
            // 将 dirlist 结果展平为相对路径
            $local_relative_paths = wpsu_flatten_dirlist($staging_files_list);

            foreach ($local_relative_paths as $local_relative_path) {
                if ( ! isset( $remote_file_paths_set[$local_relative_path] ) ) {
                    // 此本地文件不在远程清单中，删除它
                    $file_to_delete = trailingslashit($staging_dir) . $local_relative_path;
                    if ( $wp_filesystem->exists( $file_to_delete ) ) { // 删除前检查存在性
                        error_log("WP Seamless Update Cron: 删除不在远程清单中的文件/目录: $local_relative_path");
                        if ( ! $wp_filesystem->delete( $file_to_delete, true ) ) { // 递归删除目录
                            error_log("WP Seamless Update Cron: 无法从临时目录删除 $file_to_delete。中止应用。");
                            $apply_ok = false;
                            break; // 失败时停止删除过程
                        }
                        $files_deleted_count++;
                    }
                }
            }
        }
    }

    if ( ! $apply_ok ) {
        error_log("WP Seamless Update Cron: 第 4 步 - 将更新应用到临时目录失败。正在回滚。");
        wpsu_rollback_update( $target_theme_slug, $backup_dir, $theme_root, $staging_dir, $temp_dir ); // 回滚处理清理
        // 回滚函数清除 transient
        return;
    }
    error_log("WP Seamless Update Cron: 第 4 步 - 应用完成。已更新/添加: $files_updated_count, 已删除: $files_deleted_count。");

    // --- 第 5 步：（可选）验证 ---
    // 如果需要，在这里添加检查（例如，检查 style.css 是否存在）

    // --- 第 6 步：原子切换（删除实时，将临时移动到实时）---
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

    // 将临时目录移动到原始主题目录位置
    if ( $switch_ok ) {
        if ( ! $wp_filesystem->move( $staging_dir, $theme_root, true ) ) {
            error_log("WP Seamless Update Cron: 在切换过程中无法将临时目录 $staging_dir 移动到 $theme_root。严重错误。尝试回滚。");
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

    // --- 第 7 步：完成 ---
    error_log("WP Seamless Update Cron: 第 7 步 - 最终更新。");

    // 清除此主题的更新 transient 标记
    wpsu_clear_update_transient($target_theme_slug);

    // 管理备份（删除旧的）
    if ($backup_dir) { // 仅当备份已启用/创建时管理
        wpsu_manage_backups( $target_theme_slug, $backup_dir_base, $backups_to_keep );
    }

    // 清理临时下载目录（临时目录已移动或删除）
    error_log("WP Seamless Update Cron: 清理临时下载目录 $temp_dir。");
    $wp_filesystem->delete( $temp_dir, true );

    update_option( 'wpsu_last_check_status_' . $target_theme_slug, sprintf( __( 'Update successful. Remote Internal Version: %s (Local INT_VERSION should reflect this after update).', 'wp-seamless-update' ), $remote_info->internal_version ) );
    error_log( "WP Seamless Update Cron: 更新成功，主题 {$target_theme_slug}。已应用更新，远程内部版本: {$remote_info->internal_version}。如果更新包含 functions.php， 本地 INT_VERSION 常量现在应匹配。" );
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
    }
}

/**
 * 从备份回滚主题更新。清理临时和临时目录。
 *
 * @param string $theme_slug 主题 slug。
 * @param string|null $backup_dir 备份目录的路径（如果禁用备份，则为 null）。
 * @param string $theme_root 主题目录路径（应该在哪里）。
 * @param string $staging_dir 更新期间使用的临时目录的路径。
 * @param string $temp_dir 临时下载目录的路径。
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

    // 首先清理临时目录和临时目录
    if ( $wp_filesystem->exists( $staging_dir ) ) {
        error_log("WP Seamless Update Rollback: 删除临时目录 $staging_dir。");
        $wp_filesystem->delete( $staging_dir, true );
    }
    if ( $wp_filesystem->exists( $temp_dir ) ) {
        error_log("WP Seamless Update Rollback: 删除临时目录 $temp_dir。");
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
            update_option('wpsu_last_check_status_' . $target_theme_slug, __('Update successful. ', 'wp-seamless-update') . $message, false);
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
        error_log("WP Seamless Update: 获取进度信息 - 主题: $target_theme_slug, 消息: {$progress['message']}, 进度: {$progress['percent']}%, 时间: " . ($progress['time'] ? date('Y-m-d H:i:s', $progress['time']) : 'N/A'));
    }
    
    // 减少超时检测时间到2分钟，更快地发现问题
    if ($progress['time'] > 0 && (time() - $progress['time'] > 120)) {
        // 检测到更新过程已经超过2分钟没有更新进度
        if ($progress['percent'] >= 0 && $progress['percent'] < 100) {
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
