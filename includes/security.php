<?php
/**
 * Plugin Security Features
 *
 * Provides centralized security checks, validation, and protection functions
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    die;
}

/**
 * Security Helper Class
 * Provides various security checks and validation functions
 */
class WPSU_Security {
    /**
     * Performs permission checks to ensure the current user has the right to perform specific operations
     *
     * @param string $capability Required capability, default is 'manage_options'
     * @param bool $die Whether to terminate execution if no permission, default is true
     * @return bool Whether user has permission
     */
    public static function verify_permissions($capability = 'manage_options', $die = true) {
        if (!current_user_can($capability)) {
            if ($die) {
                wp_die(
                    __('You do not have sufficient permissions to perform this action.', 'wp-seamless-update'),
                    __('Permission Error', 'wp-seamless-update'),
                    array('response' => 403, 'back_link' => true)
                );
            }
            return false;
        }
        return true;
    }

    /**
     * Validates AJAX operation nonce
     *
     * @param string $action Nonce action name
     * @param string $query_arg Parameter name containing the nonce, default is '_wpnonce'
     * @param bool $die Whether to terminate execution on failed validation, default is true
     * @return bool Whether validation succeeded
     */
    public static function verify_ajax_nonce($action, $query_arg = '_wpnonce', $die = true) {
        // Check nonce and record attempt
        $verified = check_ajax_referer($action, $query_arg, $die);
        
        // If nonce validation fails but doesn't terminate, record attempt
        if (!$verified && !$die) {
            self::log_security_event('nonce_verification_failed', array(
                'action' => $action,
                'user_id' => get_current_user_id(),
                'ip' => self::get_user_ip()
            ));
        }
        
        return $verified;
    }

    /**
     * Verifies theme operation permissions (stricter check)
     *
     * @param string $theme_slug Theme identifier
     * @param bool $die Whether to terminate execution if no permission
     * @return bool Whether user has permission
     */
    public static function verify_theme_operation($theme_slug, $die = true) {
        // Basic permission check
        if (!self::verify_permissions('manage_options', $die)) {
            return false;
        }
        
        // Verify if the theme is in the allowed target themes list
        $options = get_option(WPSU_OPTION_NAME, array());
        $target_theme = isset($options['target_theme']) ? sanitize_key($options['target_theme']) : '';
        
        // Ensure string comparison is accurate, perform standardization first
        $theme_slug = sanitize_key($theme_slug);
        
        // Add debug log
        error_log("WP Seamless Update: Verifying theme operation - Requested theme: '$theme_slug', Configured theme: '$target_theme'");
        
        if ($theme_slug !== $target_theme) {
            // Add additional tolerance check, sometimes theme identifiers may have different formats
            // For example, some themes might use a mix of "theme-name" and "theme_name" formats
            if (str_replace('-', '_', $theme_slug) === str_replace('-', '_', $target_theme) ||
                str_replace('_', '-', $theme_slug) === str_replace('_', '-', $target_theme)) {
                // Log format mismatch but essentially identical themes
                error_log("WP Seamless Update: Theme identifiers have different formats but are essentially the same, allowing operation");
                return true;
            }
            
            // Check if it's a Cron call, handle more leniently if so
            if (defined('DOING_CRON') && DOING_CRON) {
                error_log("WP Seamless Update: Continuing execution in CRON environment, even if themes don't exactly match");
                return true;
            }
            
            error_log("WP Seamless Update: Theme operation validation failed - Requested theme: '$theme_slug' doesn't match configured theme: '$target_theme'");
            if ($die) {
                wp_die(
                    sprintf(__('Invalid theme operation request. Requested theme: %s, Configured theme: %s', 'wp-seamless-update'), 
                           $theme_slug, $target_theme),
                    __('Security Error', 'wp-seamless-update'),
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
     * Logs security-related events
     *
     * @param string $event Event type
     * @param array $data Related data
     * @return void
     */
    public static function log_security_event($event, $data = array()) {
        if (empty($event)) {
            return;
        }
        
        // Add timestamp and general information
        $log_data = array_merge(array(
            'timestamp' => current_time('mysql'),
            'event' => $event,
            'ip' => self::get_user_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown'
        ), $data);
        
        // Record to WordPress error log
        error_log('WP Seamless Update Security Event: ' . wp_json_encode($log_data));
        
        // Store event record
        $security_logs = get_option('wpsu_security_logs', array());
        
        // Only keep the most recent 100 logs
        if (count($security_logs) >= 100) {
            array_shift($security_logs);
        }
        
        $security_logs[] = $log_data;
        update_option('wpsu_security_logs', $security_logs);
    }

    /**
     * Gets the user's IP address
     *
     * @return string IP address
     */
    public static function get_user_ip() {
        $ip = '';
        
        // Check various possible IP sources
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Ensure IP format is valid
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    /**
     * Validates if an update URL is safe
     *
     * @param string $url URL to validate
     * @return bool|string Returns sanitized URL if safe, otherwise false
     */
    public static function validate_update_url($url) {
        if (empty($url)) {
            return false;
        }
        
        // Basic URL sanitization
        $url = esc_url_raw(trim($url));
        
        // Check if URL is valid
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Only allow https and http protocols
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, array('https', 'http'), true)) {
            return false;
        }
        
        // Check if URL is in allowed list (if defined)
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
     * Verifies a file's hash value
     *
     * @param string $file_path File path
     * @param string $expected_hash Expected hash value
     * @return bool Whether the hash values match
     */
    public static function verify_file_hash($file_path, $expected_hash) {
        if (!file_exists($file_path)) {
            return false;
        }
        
        $file_hash = md5_file($file_path);
        return $file_hash === $expected_hash;
    }

    /**
     * Safely retrieves remote data
     *
     * @param string $url Remote URL
     * @param array $args Request parameters
     * @return array|WP_Error Request result
     */
    public static function safe_remote_get($url, $args = array()) {
        // Validate URL
        $url = self::validate_update_url($url);
        if (!$url) {
            return new WP_Error('invalid_url', __('Invalid URL', 'wp-seamless-update'));
        }
        
        // Set default parameters
        $default_args = array(
            'timeout' => 30,
            'redirection' => 5,
            'sslverify' => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        );
        
        $args = wp_parse_args($args, $default_args);
        
        // Execute request
        $response = wp_remote_get($url, $args);
        
        // Log request failure
        if (is_wp_error($response)) {
            self::log_security_event('remote_request_failed', array(
                'url' => $url,
                'error' => $response->get_error_message()
            ));
        }
        
        return $response;
    }

    /**
     * Validates the integrity and security of JSON data
     *
     * @param string $json_data JSON formatted data
     * @param array $required_fields List of required fields
     * @return array|false Returns decoded data if validation succeeds, otherwise false
     */
    public static function validate_json_data($json_data, $required_fields = array()) {
        if (empty($json_data)) {
            return false;
        }
        
        // Try to decode JSON
        $data = json_decode($json_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log_security_event('invalid_json_data', array(
                'error' => json_last_error_msg()
            ));
            return false;
        }
        
        // Check required fields
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
 * Define security-related constants
 */
// If allowed update domains are not defined, set default value
if (!defined('WPSU_ALLOWED_UPDATE_DOMAINS')) {
    define('WPSU_ALLOWED_UPDATE_DOMAINS', array());  // Empty array means no domain restrictions
}

// Set maximum allowed number of backups (to prevent storage abuse)
if (!defined('WPSU_MAX_BACKUPS')) {
    define('WPSU_MAX_BACKUPS', 10);
}

// Set file operation timeout
if (!defined('WPSU_FILE_OPERATION_TIMEOUT')) {
    define('WPSU_FILE_OPERATION_TIMEOUT', 60); // seconds
}

// Set log retention time
if (!defined('WPSU_LOG_RETENTION_DAYS')) {
    define('WPSU_LOG_RETENTION_DAYS', 30);
}

/**
 * Initialize security features
 */
function wpsu_security_init() {
    // Clean up expired security logs
    add_action('wpsu_daily_maintenance', 'wpsu_cleanup_security_logs');
    
    // Log important operations
    add_action('switch_theme', 'wpsu_log_theme_switch', 10, 3);
}
add_action('plugins_loaded', 'wpsu_security_init');

/**
 * Clean up expired security logs
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
 * Log theme switch events
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
 * Add daily maintenance hook for periodic cleanup and security checks
 */
if (!wp_next_scheduled('wpsu_daily_maintenance')) {
    wp_schedule_event(time(), 'daily', 'wpsu_daily_maintenance');
}
