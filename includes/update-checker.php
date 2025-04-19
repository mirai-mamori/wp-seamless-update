<?php
/**
 * 更新检查相关功能
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * 检查主题更新。
 * 挂接到 WordPress 更新检查过程。
 *
 * @param object $transient 更新 transient 对象。
 * @return object 修改后的 transient 对象。
 */
function wpsu_check_for_theme_update( $transient ) {
    // 检查 transient 是否有 'checked' 属性
    if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
        return $transient; // 没有可检查的内容
    }

    $options = get_option( WPSU_OPTION_NAME, array() );
    $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;
    $update_url = isset( $options['update_url'] ) ? $options['update_url'] : null;
    $status_option_key = 'wpsu_last_check_status_' . $target_theme_slug;
    $time_option_key = 'wpsu_last_check_time_' . $target_theme_slug;

    update_option( $time_option_key, time() ); // 记录检查时间

    if ( ! $target_theme_slug || ! $update_url ) {
        update_option( $status_option_key, __( 'Plugin not configured.', 'wp-seamless-update' ) );
        return $transient;
    }

    // 检查目标主题是否实际安装并被 WP 检查
    if ( ! array_key_exists( $target_theme_slug, $transient->checked ) ) {
         update_option( $status_option_key, __( 'Target theme not found or not checked by WordPress.', 'wp-seamless-update' ) );
        return $transient; // 目标主题未安装或 WP 未检查它
    }

    $current_theme = wp_get_theme( $target_theme_slug ); // 重新验证存在，以防万一
    if ( ! $current_theme->exists() ) {
        update_option( $status_option_key, __( 'Target theme does not exist.', 'wp-seamless-update' ) );
        return $transient;
    }

    $current_display_version = $transient->checked[ $target_theme_slug ]; // 使用 WP 检查过的版本

    // --- 从 functions.php 获取内部版本（如果可能）---
    $current_internal_version = 'N/A'; // 默认显示值
    $current_internal_version_for_compare = 0; // 默认比较值
    // 正确检查目标主题是否是当前活动主题
    $active_theme = wp_get_theme(); // 获取当前活动的主题对象
    $theme_is_active = ($active_theme->get_stylesheet() === $target_theme_slug);

    if ( $theme_is_active && defined('INT_VERSION') ) {
        // 仅当目标主题是当前活动主题且常量已定义时读取
        $current_internal_version = INT_VERSION;
        $current_internal_version_for_compare = $current_internal_version; // 使用实际值进行比较
    } else {
         // 记录在预期情况下无法读取常量的情况（主题处于活动状态）
         if ($theme_is_active) {
            error_log("WP Seamless Update Check: Constant INT_VERSION not defined in active theme ($target_theme_slug) functions.php during check.");
            update_option( $status_option_key, __( 'Could not read INT_VERSION from active theme functions.php.', 'wp-seamless-update' ) );
         } else {
             error_log("WP Seamless Update Check: Target theme ($target_theme_slug) is not active. Cannot read INT_VERSION.");
             update_option( $status_option_key, __( 'Target theme not active, cannot read INT_VERSION.', 'wp-seamless-update' ) );
         }
         // 如果常量不可用，无法继续进行内部版本检查
         wpsu_clear_update_transient_response($target_theme_slug, $transient); // 清除任何过时通知
         return $transient;
    }
    // --- 结束获取内部版本 ---

    // 从远程服务器获取版本信息
    $remote_info = wpsu_fetch_remote_version_info( $update_url );

    if ( ! $remote_info ) {
        update_option( $status_option_key, __( 'Failed to fetch or parse remote update info.', 'wp-seamless-update' ) );
        return $transient;
    }

    // --- 版本比较逻辑 ---
    // 1. 比较显示版本
    if ( version_compare( $remote_info->display_version, $current_display_version, '>' ) ) {
        update_option( $status_option_key, sprintf( __( 'Standard update available (Display Version %s > %s). Letting WP handle.', 'wp-seamless-update' ), $remote_info->display_version, $current_display_version ) );
        // 让标准 WP 更新处理这个。不添加我们的自定义更新信息。
        return $transient;
    }

    // 2. 如果显示版本匹配，检查内部版本
    if ( version_compare( $remote_info->display_version, $current_display_version, '==' ) ) {
        // 使用 $current_internal_version_for_compare 进行比较
        if ( version_compare( $remote_info->internal_version, $current_internal_version_for_compare, '>' ) ) {            // 内部版本较新，触发无缝更新过程。
            update_option( $status_option_key, sprintf( __( 'Internal update needed (Remote Internal: %s > Local INT_VERSION: %s). Scheduling update.', 'wp-seamless-update' ), $remote_info->internal_version, $current_internal_version ) );            // 如果尚未安排，安排 cron 作业
            if ( ! wp_next_scheduled( 'wpsu_perform_seamless_update_hook', array( $target_theme_slug ) ) ) {
                $scheduled = wp_schedule_single_event( time() + 60, 'wpsu_perform_seamless_update_hook', array( $target_theme_slug ) );
                if ($scheduled) {
                    error_log( sprintf( 'WP Seamless Update: Successfully scheduled cron job for theme %s internal update to version %s.', $target_theme_slug, $remote_info->internal_version ) );
                } else {
                    error_log( sprintf( 'WP Seamless Update: FAILED to schedule cron job for theme %s internal update to version %s.', $target_theme_slug, $remote_info->internal_version ) );
                }
                
                // 检查 WP Cron 是否已启用
                if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                    error_log('WP Seamless Update: WARNING - WP Cron is disabled (DISABLE_WP_CRON is set to true). Updates will not run automatically.');
                }
            } else {
                 error_log( sprintf( 'WP Seamless Update: Cron job for theme %s internal update already scheduled.', $target_theme_slug ) );
                 
                 // 输出下一个计划的时间以便诊断
                 $next_scheduled = wp_next_scheduled( 'wpsu_perform_seamless_update_hook', array( $target_theme_slug ) );
                 error_log( sprintf( 'WP Seamless Update: Next scheduled run for %s: %s (Unix: %d)', $target_theme_slug, date('Y-m-d H:i:s', $next_scheduled), $next_scheduled) );
            }

            // 将信息添加到 transient 以在 WP 更新屏幕中显示
            $update_data = array(
                'theme'       => $target_theme_slug,
                // 在通知中显示 functions.php 中的 INT_VERSION
                'new_version' => $current_display_version . ' (' . sprintf( __( 'Internal Update Available: %s', 'wp-seamless-update' ), $remote_info->internal_version ) . ')',
                'url'         => $current_theme->get( 'ThemeURI' ), // 或提供来自 remote_info 的更新日志 URL（如果可用）
                'package'     => '', // 重要：没有标准的包 URL 以防止 WP 默认下载
                'requires'    => $current_theme->get( 'RequiresWP' ),
                'requires_php'=> $current_theme->get( 'RequiresPHP' ),
                'wpsu_internal_update' => true, // 自定义标志
                'wpsu_current_internal_version' => $current_internal_version // 传递当前版本供参考
            );

            // 确保响应数组存在
            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
                $transient->response = array();
            }
            $transient->response[ $target_theme_slug ] = (object) $update_data;
            error_log( sprintf( 'WP Seamless Update: Added update notice for theme %s internal update to version %s.', $target_theme_slug, $remote_info->internal_version ) );

        } else {
             // 版本匹配，无需更新
             update_option( $status_option_key, sprintf( __( 'Theme is up to date (Display: %s, INT_VERSION: %s).', 'wp-seamless-update' ), $current_display_version, $current_internal_version ) );
             // 确保如果版本现在匹配，则不存在过时的更新通知
             wpsu_clear_update_transient_response($target_theme_slug, $transient);
        }
    } else {
        // 远程显示版本较旧？记录这种不寻常的情况。
        update_option( $status_option_key, sprintf( __( 'Remote display version (%s) is older than local (%s). No action taken.', 'wp-seamless-update' ), $remote_info->display_version, $current_display_version ) );
        error_log( sprintf( 'WP Seamless Update: Remote display version %s is older than local %s for theme %s.', $remote_info->display_version, $current_display_version, $target_theme_slug ) );
        wpsu_clear_update_transient_response($target_theme_slug, $transient);
    }

    return $transient;
}

/**
 * 从远程更新服务器获取版本信息。
 *
 * @param string $update_url 获取版本信息的 URL。
 * @return object|false 解码的 JSON 对象或失败时返回 false。
 */
function wpsu_fetch_remote_version_info( $update_url ) {
    // 记录尝试获取更新的URL，以便于调试
    error_log( sprintf( 'WP Seamless Update: Attempting to fetch update info from %s', $update_url ) );
    
    // 增加超时时间到30秒，以应对慢速连接
    $response = wp_remote_get( $update_url, array(
        'timeout' => 30, // 增加超时时间
        'sslverify' => true,
        'headers' => array( // 防止缓存问题
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        )
    ));

    if ( is_wp_error( $response ) ) {
        $error_message = $response->get_error_message();
        $error_code = $response->get_error_code();
        error_log( sprintf( 'WP Seamless Update: Failed to fetch update info from %s - Error Code: %s, Message: %s', 
            $update_url, $error_code, $error_message ) );
        return false;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    if ( $response_code !== 200 ) {
        $response_message = wp_remote_retrieve_response_message( $response );
        error_log( sprintf( 'WP Seamless Update: Failed to fetch update info from %s - HTTP Status: %s %s', 
            $update_url, $response_code, $response_message ) );
        return false;
    }

    $body = wp_remote_retrieve_body( $response );
    
    // 记录返回的数据长度，以便调试
    $body_length = strlen($body);
    error_log( sprintf( 'WP Seamless Update: Received %d bytes from %s', $body_length, $update_url ) );
    
    // 如果内容为空，直接返回错误
    if (empty($body)) {
        error_log( 'WP Seamless Update: Empty response received from update URL' );
        return false;
    }
    
    // 尝试解码JSON
    $data = json_decode( $body );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        $json_error = json_last_error_msg();
        error_log( sprintf( 'WP Seamless Update: Failed to decode JSON from %s - JSON Error: %s', $update_url, $json_error ) );
        
        // 记录一些返回内容，帮助调试 (只记录前200个字符，避免日志过大)
        $sample = substr($body, 0, 200);
        error_log( sprintf( 'WP Seamless Update: Response sample: %s', $sample ) );
        
        return false;
    }    // 基本验证（仅验证版本字段，让 update-processor 处理其他字段验证）
    if ( ! isset( $data->display_version ) || ! isset( $data->internal_version ) ) {
        error_log( sprintf( 'WP Seamless Update: Invalid JSON structure from %s. Missing required version fields.', $update_url ) );
        return false;
    }
    
    // 记录更多信息以帮助调试
    error_log( sprintf( 'WP Seamless Update: Successfully parsed update info: display_version=%s, internal_version=%s', 
        $data->display_version, $data->internal_version ) );
    
    // 不再严格验证文件条目和其他字段，让 update-processor 决定如何处理它们
    // 仅记录下是否存在可能在后续步骤中需要的字段
    if ( ! isset( $data->package_url ) || ! isset( $data->package_hash ) ) {
        error_log( 'WP Seamless Update: Warning - JSON lacks package_url or package_hash fields needed for package-based updates' );
    }
    
    if ( ! isset( $data->files ) || !is_array($data->files) ) {
        error_log( 'WP Seamless Update: Warning - JSON lacks files array needed for updating' );
    } elseif ( count($data->files) == 0 ) {
        error_log( 'WP Seamless Update: Warning - JSON contains empty files array' );
    } else {
        error_log( sprintf( 'WP Seamless Update: JSON contains %d file entries', count($data->files) ) );
    }

    return $data;
}
