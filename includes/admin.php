<?php
/**
 * 管理界面功能
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * 将选项页面添加到菜单。
 */
function wpsu_add_admin_menu() {
    $page_hook = add_options_page(
        __( 'WP Seamless Update Settings', 'wp-seamless-update' ),
        __( 'Seamless Update', 'wp-seamless-update' ),
        'manage_options', // 确保只有管理员可以访问
        WPSU_PLUGIN_SLUG,
        'wpsu_options_page_html'
    );
    
    // 添加页面加载钩子来记录访问
    if ($page_hook) {
        add_action('load-' . $page_hook, 'wpsu_admin_page_load');
    }
}

/**
 * 记录管理页面访问并执行安全检查
 */
function wpsu_admin_page_load() {
    // 记录管理页面访问
    WPSU_Security::log_security_event('admin_page_accessed', array(
        'user_id' => get_current_user_id(),
        'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'unknown'
    ));
    
    // 权限二次验证
    WPSU_Security::verify_permissions('manage_options');
}
add_action( 'admin_menu', 'wpsu_add_admin_menu' );

/**
 * 注册并加载管理页面所需的样式和脚本
 */
function wpsu_admin_enqueue_scripts($hook) {
    // 只在插件设置页面加载
    if ($hook !== 'settings_page_' . WPSU_PLUGIN_SLUG) {
        return;
    }
    
    // 获取CSS文件路径和修改时间作为版本号
    $css_file_path = plugin_dir_path(dirname(__FILE__)) . 'assets/css/admin-style.css';
    $css_version = file_exists($css_file_path) ? filemtime($css_file_path) : '1.0';
    
    // 注册并加载样式表
    wp_register_style(
        'wpsu-admin-styles', 
        plugins_url('/assets/css/admin-style.css', dirname(__FILE__)), 
        array('dashicons'), 
        $css_version
    );
    wp_enqueue_style('wpsu-admin-styles');
}
add_action('admin_enqueue_scripts', 'wpsu_admin_enqueue_scripts');

/**
 * 注册插件设置。
 */
function wpsu_settings_init() {
    register_setting( WPSU_OPTION_GROUP, WPSU_OPTION_NAME, 'wpsu_sanitize_settings' );

    add_settings_section(
        'wpsu_settings_section',
        __( 'Seamless Update Configuration', 'wp-seamless-update' ),
        'wpsu_settings_section_callback',
        WPSU_PLUGIN_SLUG
    );

    add_settings_field(
        'wpsu_active_theme_info',
        __( 'Active Theme', 'wp-seamless-update' ),
        'wpsu_active_theme_info_render',
        WPSU_PLUGIN_SLUG,
        'wpsu_settings_section'
    );

    add_settings_field(
        'wpsu_update_url',
        __( 'Update Source URL', 'wp-seamless-update' ),
        'wpsu_update_url_render',
        WPSU_PLUGIN_SLUG,
        'wpsu_settings_section'
    );

    add_settings_field(
        'wpsu_backups_to_keep',
        __( 'Number of Backups to Keep', 'wp-seamless-update' ),
        'wpsu_backups_to_keep_render',
        WPSU_PLUGIN_SLUG,
        'wpsu_settings_section'
    );

    // 状态和手动操作部分
    add_settings_section(
        'wpsu_status_section',
        __( 'Status & Actions', 'wp-seamless-update' ),
        'wpsu_status_section_callback',
        WPSU_PLUGIN_SLUG
    );

     add_settings_field(
        'wpsu_status_info',
        __( 'Current Status', 'wp-seamless-update' ),
        'wpsu_status_info_render',
        WPSU_PLUGIN_SLUG,
        'wpsu_status_section'
    );

     add_settings_field(
        'wpsu_manual_actions',
        __( 'Manual Actions', 'wp-seamless-update' ),
        'wpsu_manual_actions_render',
        WPSU_PLUGIN_SLUG,
        'wpsu_status_section'
    );

    // 添加文件系统测试字段
    add_settings_field(
        'wpsu_filesystem_test',
        __( 'Filesystem Test', 'wp-seamless-update' ),
        'wpsu_filesystem_test_render',
        WPSU_PLUGIN_SLUG,
        'wpsu_status_section'
    );
}
add_action( 'admin_init', 'wpsu_settings_init' );

/**
 * 清理设置输入。
 *
 * @param array $input 包含所有设置字段作为数组键。
 * @return array 清理后的设置。
 */
function wpsu_sanitize_settings( $input ) {
    $sanitized_input = array();
    $options = get_option( WPSU_OPTION_NAME, array() ); // 确保选项是一个数组

    // 目标主题 - 自动使用当前激活的主题
    $active_theme = wp_get_theme();
    $active_theme_slug = $active_theme->get_stylesheet();
    
    // 始终自动使用当前激活的主题，无论表单提交内容如何
    // 检查主题是否存在
    if ($active_theme->exists()) {
        // 强制设置为当前激活的主题，确保保存时生效
        $sanitized_input['target_theme'] = $active_theme_slug;
        
        // 检查是否支持 INT_VERSION
        if (!defined('INT_VERSION')) {
            add_settings_error(
                WPSU_OPTION_NAME, 
                'theme_no_int_version', 
                __('Warning: The active theme does not define INT_VERSION constant which is required for seamless updates.', 'wp-seamless-update'),
                'warning'
            );
        }
    } else {
        $sanitized_input['target_theme'] = '';
        add_settings_error(
            WPSU_OPTION_NAME, 
            'no_active_theme', 
            __('No active theme detected. Please activate a theme that supports seamless updates.', 'wp-seamless-update'),
            'error'
        );
    }

    // 更新 URL
    if ( isset( $input['update_url'] ) ) {
        $url = esc_url_raw( trim( $input['update_url'] ) );
        if ( empty($url) ) {
            $sanitized_input['update_url'] = ''; // 允许取消设置
        } elseif ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
            $sanitized_input['update_url'] = $url;
        } else {
            $sanitized_input['update_url'] = isset( $options['update_url'] ) ? $options['update_url'] : '';
            add_settings_error(WPSU_OPTION_NAME, 'invalid_url', __('Invalid Update Source URL.', 'wp-seamless-update'), 'error');
        }
    } else {
         $sanitized_input['update_url'] = isset( $options['update_url'] ) ? $options['update_url'] : '';
    }

    // 要保留的备份
    if ( isset( $input['backups_to_keep'] ) ) {
        $backups = absint( $input['backups_to_keep'] );
        $sanitized_input['backups_to_keep'] = ( $backups >= 0 ) ? $backups : WPSU_DEFAULT_BACKUPS_TO_KEEP; // 确保非负
    } else {
         $sanitized_input['backups_to_keep'] = isset( $options['backups_to_keep'] ) ? $options['backups_to_keep'] : WPSU_DEFAULT_BACKUPS_TO_KEEP;
    }

    return $sanitized_input;
}

/**
 * 设置部分回调函数。
 */
function wpsu_settings_section_callback() {
    echo '<p>' . esc_html__( 'The plugin will automatically use your currently active theme for seamless updates. Please provide the URL for the update server.', 'wp-seamless-update' ) . '</p>';
    echo '<p>' . esc_html__( 'The update server URL should point to an endpoint providing a JSON file (e.g., version.json) with `display_version`, `internal_version`, and a `files` manifest (path, hash, url).', 'wp-seamless-update' ) . '</p>';
}

/**
 * 渲染当前激活主题信息，并自动更新设置。
 */
function wpsu_active_theme_info_render() {
    // 获取当前激活的主题
    $active_theme = wp_get_theme();
    $active_theme_slug = $active_theme->get_stylesheet();
    
    // 自动更新选项中的目标主题为当前激活的主题，实现自动保存
    $options = get_option(WPSU_OPTION_NAME, array());
    if (empty($options['target_theme']) || $options['target_theme'] !== $active_theme_slug) {
        $options['target_theme'] = $active_theme_slug;
        update_option(WPSU_OPTION_NAME, $options);
    }
    
    // 显示主题信息
    ?>
    <div class="wpsu-active-theme-info">
        <div><strong><?php echo esc_html($active_theme->get('Name')); ?></strong> (<?php echo esc_html($active_theme_slug); ?>)</div>
        <div class="description"><?php printf(esc_html__('Version: %s', 'wp-seamless-update'), esc_html($active_theme->get('Version'))); ?></div>
        
        <?php 
        // 检查主题是否支持 INT_VERSION 常量
        $supports_int_version = defined('INT_VERSION');
        if ($supports_int_version) {
            echo '<div class="wpsu-supports-int-version"><span class="dashicons dashicons-yes-alt"></span> ' . 
                 sprintf(esc_html__('Supports seamless updates (INT_VERSION: %s)', 'wp-seamless-update'), esc_html(INT_VERSION)) . 
                 '</div>';
        } else {
            echo '<div class="wpsu-warning"><span class="dashicons dashicons-warning"></span> ' . 
                 esc_html__('Theme does not support seamless updates. INT_VERSION constant not found.', 'wp-seamless-update') . 
                 '</div>';
        }
        ?>
    </div>
    <p class="description"><?php esc_html_e('The plugin will automatically use your currently active theme for seamless updates.', 'wp-seamless-update'); ?></p>
    
    <script>
        // 在页面加载完成后运行
        document.addEventListener('DOMContentLoaded', function() {
            // 自动检测当前激活主题的 SSU_URL
            detectSSU_URL('<?php echo esc_js($active_theme_slug); ?>');
            
            // 检测主题中的SSU_URL常量并填充URL字段
            function detectSSU_URL(themeSlug) {
                // 创建一个新的XMLHttpRequest请求
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                
                // 处理响应
                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 400) {                        try {
                            var response = JSON.parse(xhr.responseText);
                            if(response.success && response.data && response.data.ssu_url) {
                                // 找到URL字段并设置值 - 现在查找自动保存的URL输入框
                                var urlField = document.getElementById('wpsu-update-url');
                                if(urlField) {
                                    var currentUrl = urlField.value;
                                    var ssuUrl = response.data.ssu_url;
                                    // 只有当URL字段为空时才自动填入，不再提示
                                    if(!currentUrl) {
                                        urlField.value = ssuUrl;
                                        // 触发输入事件，确保自动保存功能被激活
                                        var inputEvent = new Event('input', { bubbles: true });
                                        urlField.dispatchEvent(inputEvent);
                                    }
                                }
                            }
                        } catch(e) {
                            console.error('解析响应时出错:', e);
                        }
                    }
                };
                
                // 处理错误
                xhr.onerror = function() {
                    console.error('请求失败');
                };
                
                // 发送请求
                xhr.send('action=wpsu_detect_ssu_url&theme_slug=' + encodeURIComponent(themeSlug) + '&_ajax_nonce=<?php echo wp_create_nonce("wpsu_detect_ssu_url_nonce"); ?>');
            }
        });
    </script>
    <?php
}

/**
 * 渲染更新源 URL 文本字段（仅用于显示，已废弃）
 */
function wpsu_update_url_render() {
    $options = get_option( WPSU_OPTION_NAME, array() );
    $update_url = isset( $options['update_url'] ) ? $options['update_url'] : '';
    ?>
    <input type='url' name="<?php echo esc_attr( WPSU_OPTION_NAME ); ?>[update_url]" value="<?php echo esc_url( $update_url ); ?>" class="regular-text" placeholder="https://example.com/updates/theme-info.json">
    <p class="description"><?php esc_html_e( 'URL pointing to the JSON file containing update information.', 'wp-seamless-update' ); ?></p>
    <?php
}

/**
 * 渲染自动保存的更新源 URL 文本字段
 */
function wpsu_update_url_render_autosave() {
    $options = get_option( WPSU_OPTION_NAME, array() );
    $update_url = isset( $options['update_url'] ) ? $options['update_url'] : '';
    ?>
    <input type='url' id="wpsu-update-url" value="<?php echo esc_url( $update_url ); ?>" class="regular-text" placeholder="https://example.com/updates/theme-info.json">
    <p class="description"><?php esc_html_e( 'URL pointing to the JSON file containing update information. Changes are automatically saved.', 'wp-seamless-update' ); ?></p>
    <div id="wpsu-update-url-status" class="wpsu-autosave-status"></div>
    <script>
        jQuery(document).ready(function($) {
            // 为URL字段添加自动保存功能
            var updateUrlTimer;
            $('#wpsu-update-url').on('input', function() {
                clearTimeout(updateUrlTimer);
                var $status = $('#wpsu-update-url-status');
                $status.text('<?php esc_html_e("Typing...", "wp-seamless-update"); ?>').removeClass('success error');
                
                updateUrlTimer = setTimeout(function() {
                    var url = $('#wpsu-update-url').val();
                    $status.text('<?php esc_html_e("Saving...", "wp-seamless-update"); ?>');
                      $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpsu_autosave_setting',
                            _ajax_nonce: '<?php echo wp_create_nonce("wpsu_autosave_nonce"); ?>',
                            setting_name: 'update_url',
                            setting_value: url
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.text('<?php esc_html_e("Saved", "wp-seamless-update"); ?>').addClass('success');
                                
                                // 设置一个变量来跟踪用户是否还在输入
                                var userStillTyping = false;
                                
                                // 创建一个更长的等待时间，之后自动刷新页面
                                var refreshTimeout = setTimeout(function() {
                                    if (!userStillTyping) {
                                        $status.text('<?php esc_html_e("Settings saved, refreshing...", "wp-seamless-update"); ?>');
                                        // 短暂延迟后刷新页面，以便用户看到提示
                                        setTimeout(function() {
                                            window.location.reload();
                                        }, 500);
                                    }
                                }, 3000); // 3秒后如果用户没有继续输入，则刷新页面
                                
                                // 监听输入框，如果用户继续输入，则取消刷新
                                $('#wpsu-update-url').on('input', function() {
                                    userStillTyping = true;
                                    clearTimeout(refreshTimeout); // 取消自动刷新
                                }).one('blur', function() {
                                    // 当用户移出输入框时，如果保存成功且已经稳定，则刷新页面
                                    setTimeout(function() {
                                        if ($status.hasClass('success')) {
                                            $status.text('<?php esc_html_e("Settings saved, refreshing...", "wp-seamless-update"); ?>');
                                            setTimeout(function() {
                                                window.location.reload();
                                            }, 500);
                                        }
                                    }, 1000);
                                });
                                
                                setTimeout(function() {
                                    $status.text('').removeClass('success');
                                }, 2000);
                            } else {
                                $status.text(response.data.message).addClass('error');
                            }
                        },
                        error: function() {
                            $status.text('<?php esc_html_e("Error saving", "wp-seamless-update"); ?>').addClass('error');
                        }
                    });
                }, 1000);
            });
        });
    </script>
    <?php
}

/**
 * 渲染要保留的备份数字段（仅用于显示，已废弃）
 */
function wpsu_backups_to_keep_render() {
    $options = get_option( WPSU_OPTION_NAME, array() );
    $backups_to_keep = isset( $options['backups_to_keep'] ) ? absint($options['backups_to_keep']) : WPSU_DEFAULT_BACKUPS_TO_KEEP;
    ?>
    <input type='number' name="<?php echo esc_attr( WPSU_OPTION_NAME ); ?>[backups_to_keep]" value="<?php echo esc_attr( $backups_to_keep ); ?>" min="0" step="1" class="small-text">
    <p class="description"><?php esc_html_e( 'Number of backups to retain. Set to 0 to disable backups (not recommended).', 'wp-seamless-update' ); ?></p>
    <?php
}

/**
 * 渲染自动保存的备份数量字段
 */
function wpsu_backups_to_keep_render_autosave() {
    $options = get_option( WPSU_OPTION_NAME, array() );
    $backups_to_keep = isset( $options['backups_to_keep'] ) ? absint($options['backups_to_keep']) : WPSU_DEFAULT_BACKUPS_TO_KEEP;
    ?>
    <input type='number' id="wpsu-backups-to-keep" value="<?php echo esc_attr( $backups_to_keep ); ?>" min="0" step="1" class="small-text">
    <p class="description"><?php esc_html_e( 'Number of backups to retain. Set to 0 to disable backups (not recommended). Changes are automatically saved.', 'wp-seamless-update' ); ?></p>
    <div id="wpsu-backups-status" class="wpsu-autosave-status"></div>
    <script>
        jQuery(document).ready(function($) {
            // 为备份数量字段添加自动保存功能
            var backupsTimer;
            $('#wpsu-backups-to-keep').on('input', function() {
                clearTimeout(backupsTimer);
                var $status = $('#wpsu-backups-status');
                $status.text('<?php esc_html_e("Typing...", "wp-seamless-update"); ?>').removeClass('success error');
                
                backupsTimer = setTimeout(function() {
                    var backups = $('#wpsu-backups-to-keep').val();
                    $status.text('<?php esc_html_e("Saving...", "wp-seamless-update"); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpsu_autosave_setting',
                            _ajax_nonce: '<?php echo wp_create_nonce("wpsu_autosave_nonce"); ?>',
                            setting_name: 'backups_to_keep',
                            setting_value: backups
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.text('<?php esc_html_e("Saved", "wp-seamless-update"); ?>').addClass('success');
                                setTimeout(function() {
                                    $status.text('').removeClass('success');
                                }, 2000);
                            } else {
                                $status.text(response.data.message).addClass('error');
                            }
                        },
                        error: function() {
                            $status.text('<?php esc_html_e("Error saving", "wp-seamless-update"); ?>').addClass('error');
                        }
                    });
                }, 1000);
            });
        });
    </script>
    <?php
}

/**
 * 状态部分回调。
 */
function wpsu_status_section_callback() {
    echo '<p>' . esc_html__( 'View the current status of the managed theme and perform manual actions.', 'wp-seamless-update' ) . '</p>';
}

/**
 * 渲染状态信息。
 */
function wpsu_status_info_render() {
    $options = get_option( WPSU_OPTION_NAME, array() );
    $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;

    if ( ! $target_theme_slug ) {
        echo '<div class="wpsu-notice wpsu-notice-warning"><span class="dashicons dashicons-info"></span> ' . esc_html__( 'No target theme selected.', 'wp-seamless-update' ) . '</div>';
        return;
    }

    $theme = wp_get_theme( $target_theme_slug );
    if ( ! $theme->exists() ) {
        echo '<div class="wpsu-notice wpsu-notice-error"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Selected target theme is not installed or accessible.', 'wp-seamless-update' ) . '</div>';
        return;
    }

    $current_display_version = $theme->get( 'Version' );
    $internal_version_display = __('N/A', 'wp-seamless-update');
    $theme_is_active = (wp_get_theme()->get_stylesheet() === $target_theme_slug);

    // 只有当目标主题是活动主题时才尝试读取 INT_VERSION
    if ($theme_is_active) {
        if (defined('INT_VERSION')) {
            $internal_version_display = INT_VERSION;
        } else {
            $internal_version_display = __('Constant INT_VERSION not found in active theme functions.php', 'wp-seamless-update');
        }
    } else {
         $internal_version_display = __('Theme not active, cannot read INT_VERSION', 'wp-seamless-update');
    }

    $last_check_time = get_option( 'wpsu_last_check_time_' . $target_theme_slug, __('Never', 'wp-seamless-update') );
    $last_status = get_option( 'wpsu_last_check_status_' . $target_theme_slug, __('N/A', 'wp-seamless-update') );
    
    // 检查是否存在更新错误或失败状态
    $has_update_error = false;
    $error_class = '';
    if (strpos($last_status, 'failed') !== false || strpos($last_status, 'Failed') !== false || strpos($last_status, 'error') !== false || strpos($last_status, 'Error') !== false) {
        $has_update_error = true;
        $error_class = 'wpsu-status-error';
    }
    
    // 检查当前是否有更新计划
    $update_scheduled = false;
    $scheduled_time = '';
    $scheduled_args = array($target_theme_slug);
    $next_timestamp = wp_next_scheduled('wpsu_perform_seamless_update_hook', $scheduled_args);
    if ($next_timestamp !== false) {
        $update_scheduled = true;
        $scheduled_time = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_timestamp);
    }

    ?>
    <div class="wpsu-status-grid">
        <div class="wpsu-status-item">
            <span class="wpsu-status-label"><?php esc_html_e( 'Theme Name', 'wp-seamless-update' ); ?>:</span>
            <span class="wpsu-status-value"><?php echo esc_html( $theme->get( 'Name' ) ); ?></span>
        </div>
        
        <div class="wpsu-status-item">
            <span class="wpsu-status-label"><?php esc_html_e( 'Display Version', 'wp-seamless-update' ); ?>:</span>
            <span class="wpsu-status-value"><?php echo esc_html( $current_display_version ); ?></span>
        </div>
        
        <div class="wpsu-status-item">
            <span class="wpsu-status-label"><?php esc_html_e( 'Internal Version', 'wp-seamless-update' ); ?>:</span>
            <span class="wpsu-status-value <?php echo (!$theme_is_active || !defined('INT_VERSION')) ? 'wpsu-warning' : ''; ?>">
                <?php echo esc_html( $internal_version_display ); ?>
            </span>
        </div>
        
        <div class="wpsu-status-item">
            <span class="wpsu-status-label"><?php esc_html_e( 'Last Check', 'wp-seamless-update' ); ?>:</span>
            <span class="wpsu-status-value">
                <?php echo esc_html( is_numeric($last_check_time) ? wp_date( get_option('date_format') . ' ' . get_option('time_format'), $last_check_time ) : $last_check_time ); ?>
            </span>
        </div>
        
        <div class="wpsu-status-item wpsu-status-item-full">
            <span class="wpsu-status-label"><?php esc_html_e( 'Status', 'wp-seamless-update' ); ?>:</span>
            <span class="wpsu-status-value <?php echo $error_class; ?>" id="wpsu-last-status"><?php echo esc_html( $last_status ); ?></span>
            
            <?php if ($has_update_error): ?>
            <div class="wpsu-error-recovery">
                <span class="dashicons dashicons-info"></span>
                <?php esc_html_e('Update issues detected. Possible solutions:', 'wp-seamless-update'); ?>
                <ul>
                    <li><?php esc_html_e('Check theme file permissions (should be 644 for files, 755 for directories)', 'wp-seamless-update'); ?></li>
                    <li><?php esc_html_e('Ensure the INT_VERSION constant is properly defined in your theme\'s functions.php', 'wp-seamless-update'); ?></li>
                    <li><?php esc_html_e('Verify the update server is accessible and returns valid data', 'wp-seamless-update'); ?></li>
                    <li><?php esc_html_e('Review PHP error logs for detailed error messages', 'wp-seamless-update'); ?></li>
                </ul>
                <button type="button" id="wpsu-reset-error-button" class="button button-secondary">
                    <span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e('Reset Error Status', 'wp-seamless-update'); ?>
                </button>
            </div>
            <?php endif; ?>
            
            <?php if ($update_scheduled): ?>
            <div class="wpsu-update-scheduled-info">
                <span class="dashicons dashicons-calendar-alt"></span>
                <?php printf(esc_html__('Update scheduled for: %s', 'wp-seamless-update'), esc_html($scheduled_time)); ?>
                <button type="button" id="wpsu-cancel-schedule-button" class="button button-secondary">
                    <span class="dashicons dashicons-no-alt"></span> <?php esc_html_e('Cancel Scheduled Update', 'wp-seamless-update'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * 渲染手动操作按钮。
 */
function wpsu_manual_actions_render() {
     $options = get_option( WPSU_OPTION_NAME, array() );
     $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;
     $can_run_check = !empty($target_theme_slug) && !empty($options['update_url']);
     
     // 检查目标主题当前是否有更新计划 或者 可以手动更新
     $update_scheduled = false;
     $can_trigger_update = false; // 新增：是否可以触发更新的标志
     
     if ($target_theme_slug) {
         // 检查是否有计划任务
         $scheduled_args = array($target_theme_slug);
         $next_timestamp = wp_next_scheduled('wpsu_perform_seamless_update_hook', $scheduled_args);
         $update_scheduled = $next_timestamp !== false;
         
         // 新增：检查主题是否已激活并且有更新URL（这些是手动触发更新的最低要求）
         $theme_is_active = (wp_get_theme()->get_stylesheet() === $target_theme_slug);
         $has_update_url = !empty($options['update_url']);
         $can_trigger_update = $theme_is_active && $has_update_url;
     }

    ?>
    <div class="wpsu-actions-container">
        <div class="wpsu-action-card">
            <div class="wpsu-action-icon">
                <span class="dashicons dashicons-search"></span>
            </div>
            <div class="wpsu-action-content">
                <h3><?php esc_html_e('Check for Updates', 'wp-seamless-update'); ?></h3>
                <p><?php esc_html_e('Manually trigger the update check process.', 'wp-seamless-update'); ?></p>
                <div class="wpsu-action-controls">
                    <button type="button" id="wpsu-manual-check-button" class="button" <?php disabled(!$can_run_check); ?>>
                        <span class="dashicons dashicons-search"></span> <?php esc_html_e('Check Now', 'wp-seamless-update'); ?>
                    </button>
                    <span class="spinner"></span>
                    <p class="description" id="wpsu-manual-check-status"></p>
                </div>
            </div>
        </div>
        
        <div class="wpsu-action-card wpsu-action-primary">
            <div class="wpsu-action-icon">
                <span class="dashicons dashicons-update"></span>
            </div>
            <div class="wpsu-action-content">
                <h3><?php esc_html_e('Execute Update', 'wp-seamless-update'); ?></h3>
                <p><?php esc_html_e('Execute a scheduled or immediate update for your theme.', 'wp-seamless-update'); ?></p>
                <div class="wpsu-action-controls">
                    <button type="button" id="wpsu-trigger-update-button" class="button button-primary" <?php disabled(!($update_scheduled || $can_trigger_update)); ?>>
                        <span class="dashicons dashicons-update"></span> <?php esc_html_e('Execute Update Now', 'wp-seamless-update'); ?>
                    </button>
                    <span class="spinner"></span>
                    <p class="description" id="wpsu-trigger-update-status"></p>
                </div>
                <?php if ($update_scheduled): ?>
                <div class="wpsu-update-scheduled">
                    <span class="dashicons dashicons-clock"></span> 
                    <?php printf(esc_html__('Update scheduled at: %s', 'wp-seamless-update'), esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_timestamp))); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * 渲染文件系统测试按钮。
 */
function wpsu_filesystem_test_render() {
    ?>
    <div class="wpsu-tool-card">
        <div class="wpsu-tool-icon">
            <span class="dashicons dashicons-database-view"></span>
        </div>
        <div class="wpsu-tool-content">
            <h3><?php esc_html_e('Filesystem Access Test', 'wp-seamless-update'); ?></h3>
            <p><?php esc_html_e('Check if WP_Filesystem can be initialized correctly for background operations.', 'wp-seamless-update'); ?></p>
            <div class="wpsu-tool-controls">
                <button type="button" id="wpsu-filesystem-test-button" class="button">
                    <span class="dashicons dashicons-database-view"></span> <?php esc_html_e('Run Test', 'wp-seamless-update'); ?>
                </button>
                <span class="spinner"></span>
                <p class="description" id="wpsu-filesystem-test-status"></p>
            </div>
        </div>
    </div>
    <?php
}

/**
 * 渲染设置页面 HTML。
 */
function wpsu_options_page_html() {
    // 使用安全类进行权限检查，如果无权限则终止执行
    WPSU_Security::verify_permissions('manage_options');
    
    // 创建页面安全nonce
    $admin_nonce = wp_create_nonce('wpsu_admin_nonce');
    
    // 记录页面访问事件
    WPSU_Security::log_security_event('options_page_accessed', array(
        'user_id' => get_current_user_id()
    ));

    // 加载自定义样式
    wp_enqueue_style('dashicons');
    
    settings_errors(WPSU_OPTION_NAME); // 显示设置错误

    // 获取当前的选项和状态
    $options = get_option( WPSU_OPTION_NAME, array() );
    $target_theme_slug = isset( $options['target_theme'] ) ? $options['target_theme'] : null;
    $update_url = isset( $options['update_url'] ) ? $options['update_url'] : '';
    $theme_info = $target_theme_slug ? wp_get_theme($target_theme_slug) : null;

    ?>
    <div class="wrap wpsu-admin-wrap">
        <h1 class="wpsu-page-title">
            <span class="dashicons dashicons-update-alt" style="font-size: 30px; width: 30px; height: 30px; margin-right: 10px;"></span>
            <?php echo esc_html( get_admin_page_title() ); ?>
        </h1>
        
        <div class="wpsu-admin-content">
            <div class="wpsu-settings-container">
                <!-- 信息区：主题信息和更新流程 -->
                <div class="wpsu-dashboard">
                    <!-- 主题信息卡片 -->
                    <div class="wpsu-card wpsu-theme-card">
                        <div class="wpsu-theme-header">
                            <?php 
                            // 获取主题信息
                            $options = get_option(WPSU_OPTION_NAME, array());
                            $target_theme_slug = isset($options['target_theme']) ? $options['target_theme'] : null;
                            $theme_info = $target_theme_slug ? wp_get_theme($target_theme_slug) : null;
                            $supports_int_version = defined('INT_VERSION');
                            ?>
                            
                            <div class="wpsu-theme-screenshot">
                                <?php if ($theme_info && $theme_info->get_screenshot()): ?>
                                    <img src="<?php echo esc_url($theme_info->get_screenshot()); ?>" alt="<?php echo $theme_info && $theme_info->get('Name') ? esc_attr($theme_info->get('Name')) : ''; ?>">
                                <?php else: ?>
                                    <div class="wpsu-no-screenshot">
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="wpsu-theme-badge <?php echo $supports_int_version ? 'wpsu-badge-success' : 'wpsu-badge-warning'; ?>">
                                    <?php if ($supports_int_version): ?>
                                        <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Ready for Updates', 'wp-seamless-update'); ?>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-warning"></span> <?php esc_html_e('INT_VERSION Missing', 'wp-seamless-update'); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="wpsu-theme-meta">
                                <h2>
                                    <?php echo $theme_info ? esc_html($theme_info->get('Name')) : esc_html__('Unknown Theme', 'wp-seamless-update'); ?>
                                </h2>
                                
                                <div class="wpsu-theme-version">
                                    <span class="wpsu-version-label"><?php esc_html_e('Version', 'wp-seamless-update'); ?></span>
                                    <span class="wpsu-version-number"><?php echo $theme_info ? esc_html($theme_info->get('Version')) : ''; ?></span>
                                </div>
                                
                                <?php if ($theme_info && $theme_info->get('Author')): ?>
                                <div class="wpsu-theme-author">
                                    <?php printf(esc_html__('By %s', 'wp-seamless-update'), esc_html($theme_info->get('Author'))); ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="wpsu-theme-int-version">
                                    <?php if (!$supports_int_version): ?>
                                    <div class="wpsu-missing-int">
                                        <span class="dashicons dashicons-warning"></span>
                                        <?php esc_html_e('INT_VERSION constant not defined', 'wp-seamless-update'); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="wpsu-card-content">
                            <!-- 添加隐藏的原始功能以确保SSU_URL自动填充功能正常工作 -->
                            <div class="wpsu-original-functions" style="display: none;">
                                <?php wpsu_active_theme_info_render(); ?>
                            </div>
                            
                            <div class="wpsu-theme-requirements">
                                <h4><?php esc_html_e('Update Requirements', 'wp-seamless-update'); ?></h4>
                                <ul class="wpsu-pill-list">
                                    <li class="<?php echo defined('INT_VERSION') ? 'wpsu-pill-success' : 'wpsu-pill-warning'; ?>">
                                        <span class="dashicons <?php echo defined('INT_VERSION') ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                                        <?php echo defined('INT_VERSION') ? sprintf(esc_html__('INT_VERSION: %s', 'wp-seamless-update'), INT_VERSION) : esc_html__('INT_VERSION Missing', 'wp-seamless-update'); ?>
                                    </li>
                                    <li class="<?php echo !empty($options['update_url']) ? 'wpsu-pill-success' : 'wpsu-pill-warning'; ?>">
                                        <span class="dashicons <?php echo !empty($options['update_url']) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                                        <?php echo !empty($options['update_url']) ? esc_html__('Update URL Set', 'wp-seamless-update') : esc_html__('No Update URL', 'wp-seamless-update'); ?>
                                    </li>
                                    <li class="wpsu-pill-info">
                                        <span class="dashicons dashicons-shield"></span>
                                        <?php printf(esc_html__('Backups: %d', 'wp-seamless-update'), isset($options['backups_to_keep']) ? $options['backups_to_keep'] : 3); ?>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 更新流程卡片 - 简化版 -->
                    <div class="wpsu-card wpsu-update-flow-card">
                        <div class="wpsu-card-header">
                            <h3><span class="dashicons dashicons-update"></span> <?php esc_html_e('How It Works', 'wp-seamless-update'); ?></h3>
                        </div>
                        
                        <div class="wpsu-update-flow-simplified">
                            <div class="wpsu-flow-item">
                                <div class="wpsu-flow-icon">
                                    <span class="dashicons dashicons-search"></span>
                                </div>
                                <div class="wpsu-flow-text">
                                    <h4><?php esc_html_e('Check', 'wp-seamless-update'); ?></h4>
                                </div>
                            </div>
                            
                            <div class="wpsu-flow-arrow">
                                <span class="dashicons dashicons-arrow-right-alt"></span>
                            </div>
                            
                            <div class="wpsu-flow-item">
                                <div class="wpsu-flow-icon">
                                    <span class="dashicons dashicons-download"></span>
                                </div>
                                <div class="wpsu-flow-text">
                                    <h4><?php esc_html_e('Download', 'wp-seamless-update'); ?></h4>
                                </div>
                            </div>
                            
                            <div class="wpsu-flow-arrow">
                                <span class="dashicons dashicons-arrow-right-alt"></span>
                            </div>
                            
                            <div class="wpsu-flow-item">
                                <div class="wpsu-flow-icon">
                                    <span class="dashicons dashicons-backup"></span>
                                </div>
                                <div class="wpsu-flow-text">
                                    <h4><?php esc_html_e('Backup', 'wp-seamless-update'); ?></h4>
                                </div>
                            </div>
                            
                            <div class="wpsu-flow-arrow">
                                <span class="dashicons dashicons-arrow-right-alt"></span>
                            </div>
                            
                            <div class="wpsu-flow-item">
                                <div class="wpsu-flow-icon">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                </div>
                                <div class="wpsu-flow-text">
                                    <h4><?php esc_html_e('Update', 'wp-seamless-update'); ?></h4>
                                </div>
                            </div>
                        </div>
                        
                        <div class="wpsu-flow-description">
                            <p><?php esc_html_e('Seamlessly updates your theme with zero downtime through a secure, automated process.', 'wp-seamless-update'); ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- 配置区：配置设置 -->
                <div class="wpsu-card wpsu-config-card">
                    <div class="wpsu-card-header">
                        <h2><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Update Configuration', 'wp-seamless-update'); ?></h2>
                    </div>
                    <div class="wpsu-card-body">
                        <?php 
                        // 精简说明文字显示
                        global $wp_settings_sections;
                        $page = WPSU_PLUGIN_SLUG;
                        
                        if (isset($wp_settings_sections[$page]) && isset($wp_settings_sections[$page]['wpsu_settings_section'])) {
                            echo '<div class="wpsu-section-description">';
                            call_user_func($wp_settings_sections[$page]['wpsu_settings_section']['callback']);
                            echo '</div>';
                        }
                        ?>
                        
                        <div class="wpsu-config-grid">
                            <div class="wpsu-config-item">
                                <h3><?php esc_html_e('Update Source URL', 'wp-seamless-update'); ?></h3>
                                <?php wpsu_update_url_render_autosave(); ?>
                            </div>
                            
                            <div class="wpsu-config-item">
                                <h3><?php esc_html_e('Number of Backups to Keep', 'wp-seamless-update'); ?></h3>
                                <?php wpsu_backups_to_keep_render_autosave(); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 合并：状态、操作和诊断 -->
                <div class="wpsu-card wpsu-status-and-tools-card">
                    <div class="wpsu-tabs">
                        <div class="wpsu-tab-nav">
                            <button class="wpsu-tab-button active" data-tab="status">
                                <span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Status & Actions', 'wp-seamless-update'); ?>
                            </button>
                            <button class="wpsu-tab-button" data-tab="diagnostics">
                                <span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e('Diagnostics', 'wp-seamless-update'); ?>
                            </button>
                        </div>
                        
                        <div class="wpsu-tab-content">
                            <!-- 状态和操作选项卡 -->
                            <div class="wpsu-tab-pane active" id="wpsu-tab-status">
                                <?php
                                // 显示状态信息
                                wpsu_status_info_render();
                                ?>
                                
                                <div class="wpsu-section-divider"></div>
                                
                                <?php
                                // 显示手动操作
                                wpsu_manual_actions_render();
                                ?>
                            </div>
                            
                            <!-- 诊断工具选项卡 -->
                            <div class="wpsu-tab-pane" id="wpsu-tab-diagnostics">
                                <p class="wpsu-tools-description">
                                    <?php esc_html_e('Use these tools to diagnose and troubleshoot the update process.', 'wp-seamless-update'); ?>
                                </p>
                                
                                <?php
                                // 显示文件系统测试工具
                                wpsu_filesystem_test_render();
                                ?>
                                
                                <div class="wpsu-section-divider"></div>
                                
                                <div class="wpsu-tool-card">
                                    <div class="wpsu-tool-icon">
                                        <span class="dashicons dashicons-info"></span>
                                    </div>
                                    <div class="wpsu-tool-content">
                                        <h3><?php esc_html_e('System Information', 'wp-seamless-update'); ?></h3>
                                        <p><?php esc_html_e('Technical information about your WordPress environment.', 'wp-seamless-update'); ?></p>
                                        
                                        <div class="wpsu-system-info">
                                            <div class="wpsu-system-item">
                                                <span class="wpsu-system-label"><?php esc_html_e('WordPress Version', 'wp-seamless-update'); ?>:</span>
                                                <span class="wpsu-system-value"><?php echo esc_html(get_bloginfo('version')); ?></span>
                                            </div>
                                            
                                            <div class="wpsu-system-item">
                                                <span class="wpsu-system-label"><?php esc_html_e('PHP Version', 'wp-seamless-update'); ?>:</span>
                                                <span class="wpsu-system-value"><?php echo esc_html(phpversion()); ?></span>
                                            </div>
                                            
                                            <div class="wpsu-system-item">
                                                <span class="wpsu-system-label"><?php esc_html_e('File Permissions', 'wp-seamless-update'); ?>:</span>
                                                <span class="wpsu-system-value">
                                                    <?php 
                                                    $uploads_dir = wp_upload_dir();
                                                    echo substr(sprintf('%o', fileperms($uploads_dir['basedir'])), -4);
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // 选项卡功能
            $('.wpsu-tab-button').on('click', function() {
                var targetTab = $(this).data('tab');
                
                // 更新按钮状态
                $('.wpsu-tab-button').removeClass('active');
                $(this).addClass('active');
                
                // 更新内容面板
                $('.wpsu-tab-pane').removeClass('active');
                $('#wpsu-tab-' + targetTab).addClass('active');
            });
            
            // 检查更新按钮处理程序
            $('#wpsu-manual-check-button').on('click', function() {
                var $button = $(this);
                var $spinner = $button.siblings('.spinner');
                var $status = $('#wpsu-manual-check-status');
                var $lastStatus = $('#wpsu-last-status');

                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $status.text('<?php echo esc_js( __( 'Checking for updates...', 'wp-seamless-update' ) ); ?>').removeClass('notice-error notice-success');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpsu_manual_check',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'wpsu_manual_check_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.text(response.data.message).addClass('notice-success');
                            $lastStatus.text(response.data.status || '<?php echo esc_js( __('N/A', 'wp-seamless-update') ); ?>');
                            // 如果状态改变，可能需要刷新页面以显示更新的界面元素
                            if (response.data.refresh) {
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            }
                        } else {
                            $status.text('<?php echo esc_js( __( 'Error:', 'wp-seamless-update' ) ); ?> ' + response.data.message).addClass('notice-error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                         $status.text('<?php esc_js( __( 'AJAX Error:', 'wp-seamless-update' ) ); ?> ' + textStatus + ' - ' + errorThrown).addClass('notice-error');
                    },
                    complete: function() {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        // 几秒钟后自动清除消息
                        setTimeout(function() {
                            $status.text('').removeClass('notice-error notice-success');
                        }, 8000);
                    }
                });
            });
            
            // 执行更新按钮处理程序
            $('#wpsu-trigger-update-button').on('click', function() {
                var $button = $(this);
                var $spinner = $button.siblings('.spinner');
                var $status = $('#wpsu-trigger-update-status');
                var $lastStatus = $('#wpsu-last-status'); // 同时更新主状态
                var progressBarHtml = '<div class="wpsu-progress-container">' + 
                    '<div class="wpsu-progress-bar" style="width: 0%;"></div>' + 
                    '</div>' +
                    '<div class="wpsu-progress-message"></div>';

                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $status.html('<?php echo esc_js( __( 'Executing update...', 'wp-seamless-update' ) ); ?>' + progressBarHtml)
                       .removeClass('notice-error notice-success notice-warning');
                
                var updateProgressInterval;
                var updateProgressStarted = false;
                
                // 轮询更新进度的函数
                function pollUpdateProgress() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpsu_get_update_progress'
                        },
                        success: function(response) {
                            if (response.success) {
                                var percent = response.data.percent || 0;
                                var message = response.data.message || '';
                                var isError = response.data.is_error || false;
                                
                                // 如果有进度数据
                                if (percent >= 0) {
                                    updateProgressStarted = true;
                                    
                                    // 更新进度条
                                    $('.wpsu-progress-bar').css('width', percent + '%');
                                    $('.wpsu-progress-message').text(message);
                                    
                                    // 如果进度完成或失败
                                    if (percent >= 100 || isError) {
                                        clearInterval(updateProgressInterval);
                                        
                                        if (isError) {
                                            $status.addClass('notice-error');
                                        } else {
                                            $status.addClass('notice-success');
                                        }
                                        
                                        // 更新主状态
                                        $lastStatus.text(message);
                                        
                                        // 短暂延迟后重新加载页面
                                        setTimeout(function() {
                                            location.reload();
                                        }, 2000);
                                    }
                                }
                            }
                        }
                    });
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpsu_trigger_update',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'wpsu_trigger_update_nonce' ); ?>'
                    },
                    success: function(response) {
                        // 启动进度轮询
                        updateProgressInterval = setInterval(pollUpdateProgress, 1000);
                        
                        if (!response.success) {
                            $status.find('.wpsu-progress-message').text('<?php echo esc_js( __( 'Error:', 'wp-seamless-update' ) ); ?> ' + response.data.message);
                            $status.addClass('notice-error');
                            // 失败时重新启用按钮
                            $button.prop('disabled', false);
                            clearInterval(updateProgressInterval);
                        }
                        
                        // 15秒后检查是否有进度更新，如果没有，则假定更新过程已经失败
                        setTimeout(function() {
                            if (!updateProgressStarted) {
                                clearInterval(updateProgressInterval);
                                $status.find('.wpsu-progress-message').text('<?php esc_js( __( 'Update process is not responding. Check server error logs.', 'wp-seamless-update' ) ); ?>');
                                $status.addClass('notice-error');
                                $button.prop('disabled', false);
                            }
                        }, 15000);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                         $status.text('<?php esc_html__( 'AJAX Error:', 'wp-seamless-update' ); ?> ' + textStatus + ' - ' + errorThrown).addClass('notice-error');
                         $button.prop('disabled', false); // AJAX 失败时重新启用
                    },
                    complete: function() {
                        $spinner.removeClass('is-active');
                        // 几秒钟后自动清除消息
                        setTimeout(function() {
                            $status.text('').removeClass('notice-error notice-success notice-warning');
                        }, 10000);
                    }
                });
            });

            // 文件系统测试按钮处理程序
            $('#wpsu-filesystem-test-button').on('click', function() {
                var $button = $(this);
                var $spinner = $button.siblings('.spinner');
                var $status = $('#wpsu-filesystem-test-status');

                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $status.text('<?php echo esc_js( __( 'Testing filesystem access...', 'wp-seamless-update' ) ); ?>').removeClass('notice-error notice-success notice-warning');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpsu_filesystem_test',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'wpsu_filesystem_test_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.html('<?php echo esc_js( __( 'Success:', 'wp-seamless-update' ) ); ?> ' + response.data.message).addClass('notice-success');
                        } else {
                             $status.html('<?php echo esc_js( __( 'Failed:', 'wp-seamless-update' ) ); ?> ' + response.data.message).addClass('notice-error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                         $status.text('<?php esc_js( __( 'AJAX Error:', 'wp-seamless-update' ) ); ?> ' + textStatus + ' - ' + errorThrown).addClass('notice-error');
                    },
                    complete: function() {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        // 几秒钟后自动清除消息
                        setTimeout(function() {
                            $status.text('').removeClass('notice-error notice-success notice-warning');
                        }, 10000);
                    }
                });
            });
            
            // 重置错误状态按钮处理程序
            $('#wpsu-reset-error-button').on('click', function() {
                var $button = $(this);
                $button.prop('disabled', true);
                var $lastStatus = $('#wpsu-last-status');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpsu_reset_error_status',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'wpsu_reset_error_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $lastStatus.text(response.data.status || '<?php echo esc_js( __('N/A', 'wp-seamless-update') ); ?>');
                            $lastStatus.removeClass('wpsu-status-error');
                            // 重新加载页面以更新界面
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            alert('<?php echo esc_js( __( 'Error:', 'wp-seamless-update' ) ); ?> ' + response.data.message);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert('<?php echo esc_js( __( 'AJAX Error:', 'wp-seamless-update' ) ); ?> ' + textStatus + ' - ' + errorThrown);
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                    }
                });
            });
            
            // 取消计划更新按钮处理程序
            $('#wpsu-cancel-schedule-button').on('click', function() {
                var $button = $(this);
                $button.prop('disabled', true);
                var $lastStatus = $('#wpsu-last-status');
                
                if (!confirm('<?php echo esc_js( __('Are you sure you want to cancel the scheduled update?', 'wp-seamless-update') ); ?>')) {
                    $button.prop('disabled', false);
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpsu_cancel_scheduled_update',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'wpsu_cancel_schedule_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $lastStatus.text(response.data.status || '<?php echo esc_js( __('N/A', 'wp-seamless-update') ); ?>');
                            // 重新加载页面以更新界面
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            alert('<?php echo esc_js( __( 'Error:', 'wp-seamless-update' ) ); ?> ' + response.data.message);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert('<?php echo esc_js( __( 'AJAX Error:', 'wp-seamless-update' ) ); ?> ' + textStatus + ' - ' + errorThrown);
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                    }
                });
            });
        });
    </script>
<?php
}
