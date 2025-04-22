<?php
/**
 * 插件安全功能
 *
 * 提供集中的安全检查、验证和防护功能
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * 安全助手类
 * 提供各种安全检查和验证功能
 */
class WPSU_Security {
    /**
     * 执行权限检查，确保当前用户有权执行特定操作
     *
     * @param string $capability 需要的能力，默认为'manage_options'
     * @param bool $die 如果没有权限是否终止执行，默认为true
     * @return bool 是否有权限
     */
    public static function verify_permissions($capability = 'manage_options', $die = true) {
        if (!current_user_can($capability)) {
            if ($die) {
                wp_die(
                    __('您没有足够权限执行此操作。', 'wp-seamless-update'),
                    __('权限错误', 'wp-seamless-update'),
                    array('response' => 403, 'back_link' => true)
                );
            }
            return false;
        }
        return true;
    }

    /**
     * 验证AJAX操作的nonce
     *
     * @param string $action nonce操作名称
     * @param string $query_arg 包含nonce的参数名，默认为'_wpnonce'
     * @param bool $die 验证失败是否终止执行，默认为true
     * @return bool 验证是否成功
     */
    public static function verify_ajax_nonce($action, $query_arg = '_wpnonce', $die = true) {
        // 检查nonce并记录尝试
        $verified = check_ajax_referer($action, $query_arg, $die);
        
        // 如果nonce验证失败但不终止，记录尝试
        if (!$verified && !$die) {
            self::log_security_event('nonce_verification_failed', array(
                'action' => $action,
                'user_id' => get_current_user_id(),
                'ip' => self::get_user_ip()
            ));
        }
        
        return $verified;
    }    /**
     * 验证主题操作权限（更严格的检查）
     *
     * @param string $theme_slug 主题标识符
     * @param bool $die 无权限是否终止执行
     * @return bool 是否有权限
     */
    public static function verify_theme_operation($theme_slug, $die = true) {
        // 基础权限检查
        if (!self::verify_permissions('manage_options', $die)) {
            return false;
        }
        
        // 验证主题是否在允许的目标主题列表中
        $options = get_option(WPSU_OPTION_NAME, array());
        $target_theme = isset($options['target_theme']) ? sanitize_key($options['target_theme']) : '';
        
        // 确保字符串比较是准确的，先进行标准化处理
        $theme_slug = sanitize_key($theme_slug);
        
        // 增加调试日志
        error_log("WP Seamless Update: 验证主题操作 - 请求主题: '$theme_slug', 配置主题: '$target_theme'");
        
        if ($theme_slug !== $target_theme) {
            // 添加额外的宽容检查，有时候主题标识符可能有不同的格式
            // 例如，有些主题可能使用 "theme-name" 和 "theme_name" 的混合形式
            if (str_replace('-', '_', $theme_slug) === str_replace('-', '_', $target_theme) ||
                str_replace('_', '-', $theme_slug) === str_replace('_', '-', $target_theme)) {
                // 日志记录格式不匹配但本质上相同的主题
                error_log("WP Seamless Update: 主题标识符格式不同但本质相同，允许操作");
                return true;
            }
            
            // 判断是否为Cron调用，如果是则更宽松处理
            if (defined('DOING_CRON') && DOING_CRON) {
                error_log("WP Seamless Update: CRON环境下继续执行，即使主题不完全匹配");
                return true;
            }
            
            error_log("WP Seamless Update: 主题操作验证失败 - 请求主题: '$theme_slug' 与配置主题: '$target_theme' 不匹配");
            if ($die) {
                wp_die(
                    sprintf(__('无效的主题操作请求。请求主题: %s, 配置主题: %s', 'wp-seamless-update'), 
                           $theme_slug, $target_theme),
                    __('安全错误', 'wp-seamless-update'),
                    array('response' => 400, 'back_link' => true)
                );
            }
            
            self::log_security_event('invalid_theme_operation', array(
                'requested_theme' => $theme_slug,
                'configured_theme' => $target_theme,
                'user_id' => get_current_user_id()
            ));
            
            return false;
        }
        
        return true;
    }

    /**
     * 记录安全相关事件
     *
     * @param string $event 事件类型
     * @param array $data 相关数据
     * @return void
     */
    public static function log_security_event($event, $data = array()) {
        if (empty($event)) {
            return;
        }
        
        // 添加时间戳和通用信息
        $log_data = array_merge(array(
            'timestamp' => current_time('mysql'),
            'event' => $event,
            'ip' => self::get_user_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown'
        ), $data);
        
        // 记录到WordPress错误日志
        error_log('WP Seamless Update 安全事件: ' . wp_json_encode($log_data));
        
        // 存储事件记录
        $security_logs = get_option('wpsu_security_logs', array());
        
        // 只保留最近的100条日志
        if (count($security_logs) >= 100) {
            array_shift($security_logs);
        }
        
        $security_logs[] = $log_data;
        update_option('wpsu_security_logs', $security_logs);
    }

    /**
     * 获取用户IP地址
     *
     * @return string IP地址
     */
    public static function get_user_ip() {
        $ip = '';
        
        // 检查各种可能的IP源
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // 确保IP格式有效
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    /**
     * 验证更新URL是否安全
     *
     * @param string $url 要验证的URL
     * @return bool|string 如果URL安全则返回清理后的URL，否则返回false
     */
    public static function validate_update_url($url) {
        if (empty($url)) {
            return false;
        }
        
        // 基本URL清理
        $url = esc_url_raw(trim($url));
        
        // 检查URL是否有效
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // 只允许https和http协议
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, array('https', 'http'), true)) {
            return false;
        }
        
        // 检查URL是否在允许名单中（如果有定义）
        $allowed_domains = defined('WPSU_ALLOWED_UPDATE_DOMAINS') ? WPSU_ALLOWED_UPDATE_DOMAINS : array();
        
        if (!empty($allowed_domains)) {
            $host = parse_url($url, PHP_URL_HOST);
            $allowed = false;
            
            foreach ($allowed_domains as $domain) {
                if ($host === $domain || (substr($domain, 0, 1) === '.' && substr($host, -strlen($domain)) === $domain)) {
                    $allowed = true;
                    break;
                }
            }
            
            if (!$allowed) {
                self::log_security_event('unauthorized_update_domain', array(
                    'url' => $url,
                    'host' => $host,
                    'user_id' => get_current_user_id()
                ));
                return false;
            }
        }
        
        return $url;
    }

    /**
     * 验证文件哈希值
     *
     * @param string $file_path 文件路径
     * @param string $expected_hash 预期的哈希值
     * @return bool 哈希值是否匹配
     */
    public static function verify_file_hash($file_path, $expected_hash) {
        if (!file_exists($file_path)) {
            return false;
        }
        
        $file_hash = md5_file($file_path);
        return $file_hash === $expected_hash;
    }

    /**
     * 安全地获取远程数据
     *
     * @param string $url 远程URL
     * @param array $args 请求参数
     * @return array|WP_Error 请求结果
     */
    public static function safe_remote_get($url, $args = array()) {
        // 验证URL
        $url = self::validate_update_url($url);
        if (!$url) {
            return new WP_Error('invalid_url', __('无效的URL', 'wp-seamless-update'));
        }
        
        // 设置默认参数
        $default_args = array(
            'timeout' => 30,
            'redirection' => 5,
            'sslverify' => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        );
        
        $args = wp_parse_args($args, $default_args);
        
        // 执行请求
        $response = wp_remote_get($url, $args);
        
        // 记录请求失败
        if (is_wp_error($response)) {
            self::log_security_event('remote_request_failed', array(
                'url' => $url,
                'error' => $response->get_error_message()
            ));
        }
        
        return $response;
    }

    /**
     * 验证JSON数据的完整性和安全性
     *
     * @param string $json_data JSON格式的数据
     * @param array $required_fields 必需的字段列表
     * @return array|false 验证成功返回解码的数据，否则返回false
     */
    public static function validate_json_data($json_data, $required_fields = array()) {
        if (empty($json_data)) {
            return false;
        }
        
        // 尝试解码JSON
        $data = json_decode($json_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log_security_event('invalid_json_data', array(
                'error' => json_last_error_msg()
            ));
            return false;
        }
        
        // 检查必需字段
        if (!empty($required_fields)) {
            foreach ($required_fields as $field) {
                if (!isset($data[$field])) {
                    self::log_security_event('missing_required_json_field', array(
                        'field' => $field
                    ));
                    return false;
                }
            }
        }
        
        return $data;
    }
}

/**
 * 定义安全相关常量
 */
// 如果未定义允许的更新域名，设置默认值
if (!defined('WPSU_ALLOWED_UPDATE_DOMAINS')) {
    define('WPSU_ALLOWED_UPDATE_DOMAINS', array());  // 空数组表示不限制域名
}

// 设置最大允许的备份数量上限（防止滥用存储空间）
if (!defined('WPSU_MAX_BACKUPS')) {
    define('WPSU_MAX_BACKUPS', 10);
}

// 设置文件操作超时时间
if (!defined('WPSU_FILE_OPERATION_TIMEOUT')) {
    define('WPSU_FILE_OPERATION_TIMEOUT', 60); // 秒
}

// 设置日志保留时间
if (!defined('WPSU_LOG_RETENTION_DAYS')) {
    define('WPSU_LOG_RETENTION_DAYS', 30);
}

/**
 * 初始化安全功能
 */
function wpsu_security_init() {
    // 清理过期安全日志
    add_action('wpsu_daily_maintenance', 'wpsu_cleanup_security_logs');
    
    // 记录重要操作
    add_action('switch_theme', 'wpsu_log_theme_switch', 10, 3);
}
add_action('plugins_loaded', 'wpsu_security_init');

/**
 * 清理过期的安全日志
 */
function wpsu_cleanup_security_logs() {
    $logs = get_option('wpsu_security_logs', array());
    $retention_days = defined('WPSU_LOG_RETENTION_DAYS') ? WPSU_LOG_RETENTION_DAYS : 30;
    $cutoff_time = strtotime('-' . $retention_days . ' days');
    $filtered_logs = array();
    
    foreach ($logs as $log) {
        $log_time = strtotime($log['timestamp']);
        if ($log_time > $cutoff_time) {
            $filtered_logs[] = $log;
        }
    }
    
    if (count($logs) !== count($filtered_logs)) {
        update_option('wpsu_security_logs', $filtered_logs);
    }
}

/**
 * 记录主题切换事件
 */
function wpsu_log_theme_switch($new_theme_name, $new_theme, $old_theme) {
    $old_theme_name = $old_theme ? $old_theme->get('Name') : 'unknown';
    $old_theme_slug = $old_theme ? $old_theme->get_stylesheet() : 'unknown';
    
    WPSU_Security::log_security_event('theme_switched', array(
        'old_theme' => array(
            'name' => $old_theme_name,
            'slug' => $old_theme_slug
        ),
        'new_theme' => array(
            'name' => $new_theme_name,
            'slug' => $new_theme->get_stylesheet()
        ),
        'user_id' => get_current_user_id()
    ));
}

/**
 * 增加每日维护钩子，用于定期清理和安全检查
 */
if (!wp_next_scheduled('wpsu_daily_maintenance')) {
    wp_schedule_event(time(), 'daily', 'wpsu_daily_maintenance');
}
