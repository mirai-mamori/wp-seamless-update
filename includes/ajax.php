<?php
/**
 * AJAX 处理功能
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * AJAX 处理程序，用于手动更新检查。
 */
function wpsu_ajax_manual_check() {
    error_reporting(0); // Suppress PHP errors/warnings
    check_ajax_referer( 'wpsu_manual_check_nonce', '_ajax_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seamless-update' ) ) );
    }

    $options = get_option( WPSU_OPTION_NAME, array() );
    $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;
    $update_url = isset( $options['update_url'] ) ? $options['update_url'] : null;

    if ( ! $target_theme_slug || ! $update_url ) {
        wp_send_json_error( array( 'message' => __( 'Plugin not configured (Target Theme or Update URL missing).', 'wp-seamless-update' ) ) );
    }

    // 模拟获取 transient（或强制检查）
    delete_site_transient('update_themes'); // 清除 transient 以强制下次加载进行检查
    wp_update_themes(); // 触发更新检查

    // 提供一个通用的成功消息，实际状态将在 WP 检查运行后更新。
    $status_message = __( 'Update check triggered. Status will refresh on the next automatic check or page load.', 'wp-seamless-update' );
    $last_status = get_option( 'wpsu_last_check_status_' . $target_theme_slug, __('N/A', 'wp-seamless-update') );

    wp_send_json_success( array(
        'message' => $status_message,
        'status' => $last_status // 发回最后已知的状态
    ) );
}
add_action( 'wp_ajax_wpsu_manual_check', 'wpsu_ajax_manual_check' );

/**
 * AJAX 处理程序，用于文件系统测试。
 */
function wpsu_ajax_filesystem_test() {
    error_reporting(0); // Suppress PHP errors/warnings
    check_ajax_referer( 'wpsu_filesystem_test_nonce', '_ajax_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seamless-update' ) ) );
    }

    $test_result = array(
        'success' => false,
        'message' => __( 'Could not initialize WP_Filesystem.', 'wp-seamless-update' ),
        'method'  => 'unknown'
    );

    // 确保必要的文件函数已加载
    if ( ! function_exists('request_filesystem_credentials') || ! function_exists('WP_Filesystem') ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }

    // 尝试非交互式获取凭据
    $creds = request_filesystem_credentials( site_url(), '', false, false, null );

    if ( false === $creds ) {
        $test_result['message'] = __( 'Failed to retrieve filesystem credentials non-interactively. This might require direct filesystem access or specific constants (FS_METHOD, FTP_HOST, etc.) defined in wp-config.php for background tasks.', 'wp-seamless-update' );
        wp_send_json_error( $test_result );
        return;
    }

    // 尝试初始化 WP_Filesystem
    if ( ! WP_Filesystem( $creds ) ) {
        $test_result['message'] = __( 'WP_Filesystem() failed to initialize with the retrieved credentials. Check filesystem permissions and ownership.', 'wp-seamless-update' );
        // 尝试确定 WordPress 尝试的方法
        $method = get_filesystem_method();
        $test_result['message'] .= ' ' . sprintf(__( 'Attempted method: %s', 'wp-seamless-update' ), '<code>' . esc_html($method) . '</code>');
        wp_send_json_error( $test_result );
        return;
    }

    // 如果初始化成功
    global $wp_filesystem;
    $method = $wp_filesystem->method;
    $test_result['success'] = true;
    $test_result['method'] = $method;
    $test_result['message'] = sprintf(__( 'Successfully initialized using the \'%s\' method.', 'wp-seamless-update' ), '<code>' . esc_html($method) . '</code>');

    wp_send_json_success( $test_result );
}
add_action( 'wp_ajax_wpsu_filesystem_test', 'wpsu_ajax_filesystem_test' );

/**
 * AJAX 处理程序，用于立即触发安排的更新。
 */
function wpsu_ajax_trigger_update() {
    error_reporting(0); // Suppress PHP errors/warnings
    check_ajax_referer( 'wpsu_trigger_update_nonce', '_ajax_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seamless-update' ) ) );
    }

    $options = get_option( WPSU_OPTION_NAME, array() );
    $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;

    if ( ! $target_theme_slug ) {
        wp_send_json_error( array( 'message' => __( 'Target theme not configured.', 'wp-seamless-update' ) ) );
    }

    // --- Start Output Buffering ---
    ob_start();
    $final_status = __('Execution started, status unknown.', 'wp-seamless-update');
    $execution_success = false;

    try {
        $scheduled_args = array($target_theme_slug);
        $timestamp = wp_next_scheduled('wpsu_perform_seamless_update_hook', $scheduled_args);

        // 修改：即使没有计划任务也允许手动触发更新
        if ( false === $timestamp ) {
            // 检查是否存在远程版本更新
            $update_url = isset( $options['update_url'] ) ? $options['update_url'] : null;
            if ( ! $update_url ) {
                wp_send_json_error( array( 'message' => __( 'Update URL not configured.', 'wp-seamless-update' ) ) );
                return;
            }

            // 直接从远程获取版本信息检查是否需要更新
            $remote_info = wpsu_fetch_remote_version_info( $update_url );
            if ( ! $remote_info ) {
                wp_send_json_error( array( 'message' => __( 'Failed to fetch update information from remote server.', 'wp-seamless-update' ) ) );
                return;
            }

            // 检查主题是否为活动主题且 INT_VERSION 是否已定义
            $active_theme = wp_get_theme();
            if ( $active_theme->get_stylesheet() !== $target_theme_slug ) {
                wp_send_json_error( array( 'message' => __( 'Target theme is not active. Please activate it first.', 'wp-seamless-update' ) ) );
                return;
            }

            if ( !defined('INT_VERSION') ) {
                wp_send_json_error( array( 'message' => __( 'INT_VERSION constant not defined in active theme.', 'wp-seamless-update' ) ) );
                return;
            }

            // 比较版本
            $current_internal_version = INT_VERSION;
            if ( version_compare( $remote_info->internal_version, $current_internal_version, '<=' ) ) {
                // Clean buffer before sending JSON
                ob_end_clean();
                wp_send_json_error( array( 'message' => sprintf( __( 'No update needed. Current version (%s) is up to date or newer than remote version (%s).', 'wp-seamless-update' ), $current_internal_version, $remote_info->internal_version ) ) );
                return;
            }

            // 如果远程版本更新，继续执行更新而不需要计划任务
            error_log( sprintf( "WP Seamless Update: Manually triggering update for %s from version %s to %s (no scheduled task).", $target_theme_slug, $current_internal_version, $remote_info->internal_version ) );
        } else {
            // 如果有计划任务，取消它然后手动执行
            wp_unschedule_event( $timestamp, 'wpsu_perform_seamless_update_hook', $scheduled_args );
            error_log("WP Seamless Update: Manually triggering update for $target_theme_slug (unscheduled original cron).");
        }

        // --- 直接执行更新函数 ---
        // 注意：这在 AJAX 请求中同步运行。
        // 它可能会超时或由于文件系统问题而失败。
        @set_time_limit(300); // Increased timeout

        // 设置执行前的状态，以便在发生PHP错误时有记录
        update_option('wpsu_last_check_status_' . $target_theme_slug, __('Update execution in progress...', 'wp-seamless-update'));
        // 记录开始时间
        $start_time = microtime(true);

        // 确保处理程序文件已加载
        if (!function_exists('wpsu_perform_seamless_update')) {
             require_once(dirname(__FILE__) . '/update-processor.php');
        }

        // 执行更新
        if (function_exists('wpsu_perform_seamless_update')) {
            wpsu_perform_seamless_update($target_theme_slug);
            $execution_success = true; // Assume success if no exception is thrown
        } else {
             throw new Exception('Update processor function wpsu_perform_seamless_update not found.');
        }

        // 记录执行时间
        $execution_time = microtime(true) - $start_time;
        error_log("WP Seamless Update: 更新执行完成，耗时: " . round($execution_time, 2) . " 秒");
        // 获取函数本身设置的状态
        $final_status = get_option( 'wpsu_last_check_status_' . $target_theme_slug, __('Execution finished, status unknown.', 'wp-seamless-update') );

    } catch (Exception $e) {
        error_log("WP Seamless Update: 更新执行失败，错误: " . $e->getMessage());
        $final_status = sprintf(__('Update failed with error: %s', 'wp-seamless-update'), $e->getMessage());
        update_option('wpsu_last_check_status_' . $target_theme_slug, $final_status);
        $execution_success = false;
    } catch (Error $e) { // Catch fatal errors in PHP 7+
        error_log("WP Seamless Update: 更新执行遇到致命错误: " . $e->getMessage());
        $final_status = sprintf(__('Update failed with fatal error: %s', 'wp-seamless-update'), $e->getMessage());
        update_option('wpsu_last_check_status_' . $target_theme_slug, $final_status);
        $execution_success = false;
    }

    // --- End Output Buffering ---
    $stray_output = ob_get_clean();
    if (!empty($stray_output)) {
        error_log("WP Seamless Update: Captured stray output during trigger_update: " . $stray_output);
        // Optionally add stray output to the error message if execution failed
        if (!$execution_success) {
             $final_status .= ' ' . __('(Stray output captured, check PHP error log)', 'wp-seamless-update');
        }
    }

    // --- Send JSON Response --- 
    if ($execution_success) {
         // Check if the status message indicates success explicitly
         $success_indicator = 'Update successful'; // As set in update-processor.php
         if (strpos($final_status, $success_indicator) !== false) {
             wp_send_json_success( array(
                'message' => __( 'Update execution completed.', 'wp-seamless-update' ) . ' ' . __( 'Final Status:', 'wp-seamless-update' ) . ' ' . $final_status,
                'status' => $final_status
            ) );
         } else {
             // It finished without exception, but status doesn't confirm success
             wp_send_json_error( array(
                'message' => __( 'Update execution finished, but final status is unclear or indicates an issue.', 'wp-seamless-update' ) . ' ' . __( 'Final Status:', 'wp-seamless-update' ) . ' ' . $final_status,
                'status' => $final_status
            ) );
         }
    } else {
        // Execution failed (exception caught or success flag not set)
        wp_send_json_error( array(
            'message' => __( 'Update execution failed.', 'wp-seamless-update' ) . ' ' . __( 'Final Status:', 'wp-seamless-update' ) . ' ' . $final_status,
            'status' => $final_status
        ) );
    }
}
add_action( 'wp_ajax_wpsu_trigger_update', 'wpsu_ajax_trigger_update' );

/**
 * 重置错误状态
 */
function wpsu_ajax_reset_error_status() {
    error_reporting(0); // Suppress PHP errors/warnings
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seamless-update' ) ) );
        return;
    }
    
    check_ajax_referer( 'wpsu_reset_error_nonce', '_ajax_nonce' );
    
    $options = get_option( WPSU_OPTION_NAME, array() );
    $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;
    
    if (!$target_theme_slug) {
        wp_send_json_error( array( 'message' => __( 'No target theme configured.', 'wp-seamless-update' ) ) );
        return;
    }
    
    // 重置状态
    update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Status reset by admin.', 'wp-seamless-update' ) );
    
    wp_send_json_success( array(
        'message' => __( 'Error status has been reset.', 'wp-seamless-update' ),
        'status' => __( 'Status reset by admin.', 'wp-seamless-update' )
    ) );
}
add_action( 'wp_ajax_wpsu_reset_error_status', 'wpsu_ajax_reset_error_status' );

/**
 * 取消计划更新
 */
function wpsu_ajax_cancel_scheduled_update() {
    error_reporting(0); // Suppress PHP errors/warnings
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seamless-update' ) ) );
        return;
    }
    
    check_ajax_referer( 'wpsu_cancel_schedule_nonce', '_ajax_nonce' );
    
    $options = get_option( WPSU_OPTION_NAME, array() );
    $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;
    
    if (!$target_theme_slug) {
        wp_send_json_error( array( 'message' => __( 'No target theme configured.', 'wp-seamless-update' ) ) );
        return;
    }
    
    $scheduled_args = array($target_theme_slug);
    $timestamp = wp_next_scheduled('wpsu_perform_seamless_update_hook', $scheduled_args);
    
    if ($timestamp === false) {
        wp_send_json_error( array( 'message' => __( 'No scheduled update found.', 'wp-seamless-update' ) ) );
        return;
    }
    
    // 取消计划任务
    $unschedule_result = wp_clear_scheduled_hook('wpsu_perform_seamless_update_hook', $scheduled_args);
    
    if ($unschedule_result === false) {
        wp_send_json_error( array( 'message' => __( 'Failed to cancel scheduled update.', 'wp-seamless-update' ) ) );
        return;
    }
    
    // 清除通知
    wpsu_clear_update_transient($target_theme_slug);
    
    // 更新状态
    update_option( 'wpsu_last_check_status_' . $target_theme_slug, __( 'Scheduled update cancelled by admin.', 'wp-seamless-update' ) );
    
    wp_send_json_success( array(
        'message' => __( 'Scheduled update has been cancelled.', 'wp-seamless-update' ),
        'status' => __( 'Scheduled update cancelled by admin.', 'wp-seamless-update' )
    ) );
}
add_action( 'wp_ajax_wpsu_cancel_scheduled_update', 'wpsu_ajax_cancel_scheduled_update' );

/**
 * AJAX 处理程序，用于获取更新进度
 */
function wpsu_ajax_get_update_progress() {
    // 关闭可能的PHP通知和警告，防止它们干扰JSON输出
    error_reporting(0);
    
    // 此处不需要nonce验证，因为这只是读取状态
    // 但仍然需要权限检查
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'wp-seamless-update')));
        return;
    }

    $options = get_option(WPSU_OPTION_NAME, array());
    $target_theme_slug = isset($options['target_theme']) ? $options['target_theme'] : null;

    if (!$target_theme_slug) {
        wp_send_json_error(array('message' => __('No target theme configured.', 'wp-seamless-update')));
        return;
    }

    try {
        // 检查函数是否存在
        if (!function_exists('wpsu_get_update_progress')) {
            require_once(dirname(__FILE__) . '/update-processor.php');
            
            // 再次检查，如果还不存在则使用默认值
            if (!function_exists('wpsu_get_update_progress')) {
                $progress = array(
                    'message' => __('Progress tracking function not available', 'wp-seamless-update'),
                    'percent' => 0,
                    'is_error' => false,
                    'time' => time()
                );
                wp_send_json_success($progress);
                return;
            }
        }
        
        // 获取当前更新进度
        $progress = wpsu_get_update_progress($target_theme_slug);
        
        // 返回进度信息
        wp_send_json_success($progress);
    } catch (Exception $e) {
        // 返回错误
        wp_send_json_error(array(
            'message' => sprintf(__('Error getting update progress: %s', 'wp-seamless-update'), $e->getMessage()),
            'percent' => 0,
            'is_error' => true
        ));
    }
}
add_action('wp_ajax_wpsu_get_update_progress', 'wpsu_ajax_get_update_progress');
