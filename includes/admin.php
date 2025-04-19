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
    add_options_page(
        __( 'WP Seamless Update Settings', 'wp-seamless-update' ),
        __( 'Seamless Update', 'wp-seamless-update' ),
        'manage_options',
        WPSU_PLUGIN_SLUG,
        'wpsu_options_page_html'
    );
}
add_action( 'admin_menu', 'wpsu_add_admin_menu' );

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
        'wpsu_target_theme',
        __( 'Target Theme', 'wp-seamless-update' ),
        'wpsu_target_theme_render',
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

    // 目标主题
    if ( isset( $input['target_theme'] ) ) {
        $theme_slug = sanitize_text_field( $input['target_theme'] );
        if ( empty($theme_slug) ) {
             $sanitized_input['target_theme'] = ''; // 允许取消设置
        } else {
            $theme = wp_get_theme( $theme_slug );
            if ( $theme->exists() ) {
                $sanitized_input['target_theme'] = $theme_slug;
            } else {
                 $sanitized_input['target_theme'] = isset( $options['target_theme'] ) ? $options['target_theme'] : '';
                 add_settings_error(WPSU_OPTION_NAME, 'invalid_theme', __('Selected theme does not exist.', 'wp-seamless-update'), 'error');
            }
        }
    } else {
        // 如果复选框未选中或字段不存在，保留旧值或默认为空
        $sanitized_input['target_theme'] = isset( $options['target_theme'] ) ? $options['target_theme'] : '';
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
    echo '<p>' . esc_html__( 'Select the theme you want to manage with seamless updates and provide the URL for the update server.', 'wp-seamless-update' ) . '</p>';
    echo '<p>' . esc_html__( 'The update server URL should point to an endpoint providing a JSON file (e.g., version.json) with `display_version`, `internal_version`, and a `files` manifest (path, hash, url).', 'wp-seamless-update' ) . '</p>';
}

/**
 * 渲染目标主题下拉字段。
 */
function wpsu_target_theme_render() {
    $options = get_option( WPSU_OPTION_NAME, array() );
    $selected_theme = isset( $options['target_theme'] ) ? $options['target_theme'] : '';
    $themes = wp_get_themes();
    ?>
    <select name="<?php echo esc_attr( WPSU_OPTION_NAME ); ?>[target_theme]" id="wpsu-target-theme">
        <option value=""><?php esc_html_e( '-- Select a Theme --', 'wp-seamless-update' ); ?></option>
        <?php foreach ( $themes as $theme_slug => $theme ) : ?>
            <option value="<?php echo esc_attr( $theme_slug ); ?>" <?php selected( $selected_theme, $theme_slug ); ?>>
                <?php echo esc_html( $theme->get( 'Name' ) ); ?> (<?php echo esc_html($theme_slug); ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description"><?php esc_html_e( 'Select the theme to apply seamless updates to.', 'wp-seamless-update' ); ?></p>
    
    <script>
        // 在页面加载完成后运行
        document.addEventListener('DOMContentLoaded', function() {
            // 获取目标主题下拉框
            var themeSelect = document.getElementById('wpsu-target-theme');
            
            // 只有当元素存在时才添加事件监听器
            if(themeSelect) {
                themeSelect.addEventListener('change', function() {
                    var selectedTheme = this.value;
                    if(selectedTheme) {
                        detectSSU_URL(selectedTheme);
                    }
                });
                
                // 如果有预选的值，也执行检测（页面加载时）
                if(themeSelect.value) {
                    detectSSU_URL(themeSelect.value);
                }
            }
            
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
                                // 找到URL字段并设置值
                                var urlField = document.querySelector('input[name="<?php echo esc_attr( WPSU_OPTION_NAME ); ?>[update_url]"]');                                // 检查当前字段值
                                if(urlField) {
                                    var currentUrl = urlField.value;
                                    var ssuUrl = response.data.ssu_url;
                                    // 只有当URL字段为空时才自动填入，不再提示
                                    if(!currentUrl) {
                                        urlField.value = ssuUrl;
                                    }
                                    // 不再弹出确认对话框
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
 * 渲染更新源 URL 文本字段。
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
 * 渲染要保留的备份数字段。
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
    // 检查用户权限
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

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
            <!-- 左侧设置区域 -->
            <div class="wpsu-settings-container">
                <form action="options.php" method="post">
                    <div class="wpsu-card">
                        <div class="wpsu-card-header">
                            <h2><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Theme Update Configuration', 'wp-seamless-update'); ?></h2>
                        </div>
                        <div class="wpsu-card-body">
                            <?php 
                            settings_fields( WPSU_OPTION_GROUP ); // Nonce, action, option_page 字段
                            
                            // 仅显示配置部分的字段
                            global $wp_settings_sections, $wp_settings_fields;
                            $page = WPSU_PLUGIN_SLUG;
                            
                            if (isset($wp_settings_sections[$page]) && isset($wp_settings_sections[$page]['wpsu_settings_section'])) {
                                echo '<div class="wpsu-section-description">';
                                call_user_func($wp_settings_sections[$page]['wpsu_settings_section']['callback']);
                                echo '</div>';
                                
                                if (isset($wp_settings_fields[$page]['wpsu_settings_section'])) {
                                    echo '<table class="form-table" role="presentation">';
                                    do_settings_fields($page, 'wpsu_settings_section');
                                    echo '</table>';
                                }
                            }
                            ?>
                        </div>
                        <div class="wpsu-card-footer">
                            <?php submit_button( __( 'Save Settings', 'wp-seamless-update' ), 'primary', 'submit', false ); ?>
                        </div>
                    </div>
                </form>
                
                <!-- 状态和操作区域 -->
                <div class="wpsu-card wpsu-status-card">
                    <div class="wpsu-card-header">
                        <h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Theme Status & Actions', 'wp-seamless-update'); ?></h2>
                    </div>
                    <div class="wpsu-card-body">
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
                </div>
                
                <!-- 工具与诊断 -->
                <div class="wpsu-card wpsu-tools-card">
                    <div class="wpsu-card-header">
                        <h2><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e('Diagnostics & Tools', 'wp-seamless-update'); ?></h2>
                    </div>
                    <div class="wpsu-card-body">
                        <p class="wpsu-tools-description">
                            <?php esc_html_e('Use these tools to diagnose and troubleshoot the update process.', 'wp-seamless-update'); ?>
                        </p>
                        
                        <?php
                        // 显示文件系统测试工具
                        wpsu_filesystem_test_render();
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- 右侧信息面板 -->
            <div class="wpsu-sidebar">
                <div class="wpsu-card wpsu-help-card">
                    <div class="wpsu-card-header">
                        <h2><span class="dashicons dashicons-info"></span> <?php esc_html_e('Help & Information', 'wp-seamless-update'); ?></h2>
                    </div>
                    <div class="wpsu-card-body">
                        <h3><?php esc_html_e('How It Works', 'wp-seamless-update'); ?></h3>
                        <p><?php esc_html_e('WP Seamless Update enables partial theme updates based on internal version numbers without requiring a full theme update.', 'wp-seamless-update'); ?></p>
                        
                        <h3><?php esc_html_e('Requirements', 'wp-seamless-update'); ?></h3>
                        <ul class="wpsu-requirements-list">
                            <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Active theme with INT_VERSION constant', 'wp-seamless-update'); ?></li>
                            <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Valid update server URL', 'wp-seamless-update'); ?></li>
                            <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Proper file permissions', 'wp-seamless-update'); ?></li>
                        </ul>
                        
                        <?php if ($theme_info && $target_theme_slug): ?>
                        <div class="wpsu-current-theme">
                            <h3><?php esc_html_e('Selected Theme', 'wp-seamless-update'); ?></h3>
                            <div class="wpsu-theme-info">
                                <div class="wpsu-theme-icon">
                                    <?php if ($theme_info->get_screenshot()): ?>
                                        <img src="<?php echo esc_url($theme_info->get_screenshot()); ?>" alt="<?php echo esc_attr($theme_info->get('Name')); ?>" width="100" height="75">
                                    <?php else: ?>
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="wpsu-theme-details">
                                    <h4><?php echo esc_html($theme_info->get('Name')); ?></h4>
                                    <p><?php echo esc_html($theme_info->get('Version')); ?></p>
                                    <p class="wpsu-theme-author"><?php printf(esc_html__('By %s', 'wp-seamless-update'), esc_html($theme_info->get('Author'))); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 新增：更新流程图卡片 -->
                <div class="wpsu-card wpsu-workflow-card">
                    <div class="wpsu-card-header">
                        <h2><span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e('Update Process', 'wp-seamless-update'); ?></h2>
                    </div>
                    <div class="wpsu-card-body">
                        <div class="wpsu-workflow-steps">
                            <div class="wpsu-workflow-step">
                                <div class="wpsu-workflow-step-icon">1</div>
                                <div class="wpsu-workflow-step-content">
                                    <h4><?php esc_html_e('Check', 'wp-seamless-update'); ?></h4>
                                    <p><?php esc_html_e('System checks for available updates by comparing your theme\'s INT_VERSION with the remote version.', 'wp-seamless-update'); ?></p>
                                </div>
                            </div>
                            
                            <div class="wpsu-workflow-step">
                                <div class="wpsu-workflow-step-icon">2</div>
                                <div class="wpsu-workflow-step-content">
                                    <h4><?php esc_html_e('Download', 'wp-seamless-update'); ?></h4>
                                    <p><?php esc_html_e('Modified files are downloaded and verified with their hash values.', 'wp-seamless-update'); ?></p>
                                </div>
                            </div>
                            
                            <div class="wpsu-workflow-step">
                                <div class="wpsu-workflow-step-icon">3</div>
                                <div class="wpsu-workflow-step-content">
                                    <h4><?php esc_html_e('Backup', 'wp-seamless-update'); ?></h4>
                                    <p><?php esc_html_e('Original theme files are backed up before any changes are made.', 'wp-seamless-update'); ?></p>
                                </div>
                            </div>
                            
                            <div class="wpsu-workflow-step">
                                <div class="wpsu-workflow-step-icon">4</div>
                                <div class="wpsu-workflow-step-content">
                                    <h4><?php esc_html_e('Update', 'wp-seamless-update'); ?></h4>
                                    <p><?php esc_html_e('Modified files are applied to your theme with minimal interruption.', 'wp-seamless-update'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style type="text/css">
        /* 现代UI样式 */
        .wpsu-admin-wrap {
            margin: 20px 20px 0 0;
            color: #3c434a;
        }
        .wpsu-page-title {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .wpsu-admin-content {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        .wpsu-settings-container {
            flex: 2;
            max-width: 800px;
        }
        .wpsu-sidebar {
            flex: 1;
            max-width: 350px;
        }
        .wpsu-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .wpsu-card-header {
            border-bottom: 1px solid #f0f0f1;
            padding: 15px;
            background-color: #f8f9fa;
        }
        .wpsu-card-header h2 {
            margin: 0;
            font-size: 16px;
            display: flex;
            align-items: center;
            font-weight: 500;
        }
        .wpsu-card-header h2 .dashicons {
            margin-right: 8px;
        }
        .wpsu-card-body {
            padding: 15px;
        }
        .wpsu-card-footer {
            border-top: 1px solid #f0f0f1;
            padding: 12px 15px;
            background-color: #f8f9fa;
            text-align: right;
        }
        .wpsu-section-description {
            margin-bottom: 15px;
        }
        
        /* 状态显示样式 */
        .wpsu-status-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 15px;
        }
        .wpsu-status-item {
            display: flex;
            flex-direction: column;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .wpsu-status-item-full {
            grid-column: span 2;
        }
        .wpsu-status-label {
            font-weight: 500;
            margin-bottom: 5px;
            color: #646970;
            font-size: 12px;
            text-transform: uppercase;
        }
        .wpsu-status-value {
            font-size: 15px;
            padding: 2px 0;
            color: #2c3338;
        }
        .wpsu-warning {
            color: #b45b00;
        }
        /* 新增：显示错误状态的样式 */
        .wpsu-status-error {
            color: #d63638;
            font-weight: 500;
        }
        /* 新增：错误恢复区域的样式 */
        .wpsu-error-recovery {
            margin-top: 12px;
            padding: 12px;
            background: #fcf0f1;
            border-left: 4px solid #d63638;
            border-radius: 3px;
            font-size: 13px;
        }
        .wpsu-error-recovery .dashicons {
            color: #d63638;
            margin-right: 5px;
            vertical-align: middle;
        }
        .wpsu-error-recovery ul {
            margin: 10px 0 12px 25px;
            list-style-type: disc;
        }
        .wpsu-error-recovery ul li {
            margin-bottom: 5px;
        }
        .wpsu-error-recovery button {
            display: flex;
            align-items: center;
        }
        .wpsu-error-recovery button .dashicons {
            margin-right: 5px;
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
        /* 新增：计划更新信息的样式 */
        .wpsu-update-scheduled-info {
            margin-top: 12px;
            padding: 12px;
            background: #f0f6fc;
            border-left: 4px solid #2271b1;
            border-radius: 3px;
            font-size: 13px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        .wpsu-update-scheduled-info .dashicons {
            color: #2271b1;
            margin-right: 5px;
        }
        .wpsu-update-scheduled-info button {
            display: flex;
            align-items: center;
            margin-left: 10px;
        }
        .wpsu-update-scheduled-info button .dashicons {
            margin-right: 5px;
            font-size: 16px;
            width: 16px;
            height: 16px;
            color: inherit;
        }
        
        /* 操作卡片样式 */
        .wpsu-actions-container {
            display: flex;
            gap: 15px;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        .wpsu-action-card {
            flex: 1;
            min-width: 250px;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            padding: 15px;
            display: flex;
            background: #fff;
            transition: all 0.2s ease;
        }
        .wpsu-action-card:hover {
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .wpsu-action-primary {
            border-left: 4px solid #2271b1;
        }
        .wpsu-action-icon {
            margin-right: 15px;
            color: #646970;
        }
        .wpsu-action-icon .dashicons {
            font-size: 24px;
            width: 24px;
            height: 24px;
        }
        .wpsu-action-content {
            flex: 1;
        }
        .wpsu-action-content h3 {
            margin: 0 0 8px;
            font-size: 15px;
            font-weight: 500;
        }
        .wpsu-action-content p {
            margin: 0 0 10px;
            color: #646970;
        }
        .wpsu-action-controls {
            margin-top: 10px;
        }
        .wpsu-action-controls .button {
            display: flex;
            align-items: center;
        }
        .wpsu-action-controls .button .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
            margin-right: 4px;
        }
        .wpsu-update-scheduled {
            margin-top: 10px;
            font-size: 12px;
            display: flex;
            align-items: center;
            color: #996800;
            background: #fffbe5;
            padding: 6px 8px;
            border-radius: 4px;
        }
        .wpsu-update-scheduled .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
            margin-right: 5px;
        }
        
        /* 工具卡片样式 */
        .wpsu-tool-card {
            display: flex;
            background: #f8f9fa;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .wpsu-tool-icon {
            margin-right: 15px;
            color: #646970;
        }
        .wpsu-tool-icon .dashicons {
            font-size: 24px;
            width: 24px;
            height: 24px;
        }
        .wpsu-tool-content {
            flex: 1;
        }
        .wpsu-tool-content h3 {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 500;
        }
        .wpsu-tool-content p {
            margin: 0 0 10px;
            color: #646970;
        }
        .wpsu-tool-controls .button {
            display: flex;
            align-items: center;
        }
        .wpsu-tool-controls .button .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
            margin-right: 4px;
        }
        
        /* 信息卡片内容样式 */
        .wpsu-help-card h3 {
            margin: 15px 0 8px;
            font-size: 14px;
            font-weight: 500;
        }
        .wpsu-help-card h3:first-child {
            margin-top: 0;
        }
        .wpsu-requirements-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .wpsu-requirements-list li {
            margin-bottom: 6px;
            display: flex;
            align-items: center;
        }
        .wpsu-requirements-list .dashicons {
            margin-right: 5px;
            color: #46b450;
        }
        .wpsu-current-theme {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #f0f0f1;
        }
        .wpsu-theme-info {
            display: flex;
            gap: 12px;
            margin-top: 10px;
        }
        .wpsu-theme-icon {
            width: 80px;
            height: 60px;
            background-color: #f0f0f1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            overflow: hidden;
        }
        .wpsu-theme-icon .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
            color: #646970;
        }
        .wpsu-theme-icon img {
            width: 100%;
            height: auto;
            object-fit: cover;
        }
        .wpsu-theme-details {
            flex: 1;
        }
        .wpsu-theme-details h4 {
            margin: 0 0 4px;
            font-size: 14px;
        }
        .wpsu-theme-details p {
            margin: 0 0 3px;
            color: #646970;
            font-size: 13px;
        }
        
        /* 更新流程图卡片样式 */
        .wpsu-workflow-steps {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .wpsu-workflow-step {
            display: flex;
            align-items: flex-start;
            padding: 10px 0;
            position: relative;
        }
        .wpsu-workflow-step:not(:last-child):after {
            content: '';
            position: absolute;
            left: 15px;
            top: 40px;
            bottom: 0px;
            width: 1px;
            background: #dcdcde;
        }
        .wpsu-workflow-step-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #2271b1;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 12px;
            position: relative;
            z-index: 1;
        }
        .wpsu-workflow-step-content {
            flex: 1;
        }
        .wpsu-workflow-step-content h4 {
            margin: 0 0 5px;
            font-size: 14px;
        }
        .wpsu-workflow-step-content p {
            margin: 0;
            color: #646970;
            font-size: 13px;
        }
        
        /* 进度条样式 */
        .wpsu-progress-container {
            margin: 15px 0;
            background-color: #f0f0f1;
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        .wpsu-progress-bar {
            height: 100%;
            background-color: #2271b1;
            width: 0%;
            transition: width 0.5s ease;
        }
        .notice-error .wpsu-progress-bar {
            background-color: #d63638;
        }
        .notice-success .wpsu-progress-bar {
            background-color: #00a32a;
        }
        .wpsu-progress-message {
            margin: 8px 0;
            font-size: 13px;
            color: #50575e;
        }
        
        /* 通用元素样式 */
        .wpsu-section-divider {
            border-top: 1px solid #f0f0f1;
            margin: 15px 0;
        }
        .wpsu-notice {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        .wpsu-notice .dashicons {
            margin-right: 8px;
        }
        .wpsu-notice-warning {
            background: #fcf9e8;
            color: #996800;
        }
        .wpsu-notice-error {
            background: #fcf0f1;
            color: #b32d2e;
        }
        
        /* 响应式设计 */
        @media screen and (max-width: 1200px) {
            .wpsu-admin-content {
                flex-direction: column;
            }
            .wpsu-settings-container, 
            .wpsu-sidebar {
                max-width: none;
                width: 100%;
            }
        }
        @media screen and (max-width: 782px) {
            .wpsu-status-grid {
                grid-template-columns: 1fr;
            }
            .wpsu-status-item-full {
                grid-column: 1;
            }
            .wpsu-actions-container {
                flex-direction: column;
            }
            .wpsu-action-card {
                width: 100%;
            }
        }
    </style>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
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
                         $status.text('<?php echo esc_js( __( 'AJAX Error:', 'wp-seamless-update' ) ); ?> ' + textStatus + ' - ' + errorThrown).addClass('notice-error');
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
            });            // 执行更新按钮处理程序
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
                       .removeClass('notice-error notice-success notice-warning');                var updateProgressInterval;
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
                                $status.find('.wpsu-progress-message').text('<?php echo esc_js( __( 'Update process is not responding. Check server error logs.', 'wp-seamless-update' ) ); ?>');
                                $status.addClass('notice-error');
                                $button.prop('disabled', false);
                            }
                        }, 15000);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                         $status.text('<?php echo esc_js( __( 'AJAX Error:', 'wp-seamless-update' ) ); ?> ' + textStatus + ' - ' + errorThrown).addClass('notice-error');
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
                         $status.text('<?php echo esc_js( __( 'AJAX Error:', 'wp-seamless-update' ) ); ?> ' + textStatus + ' - ' + errorThrown).addClass('notice-error');
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
