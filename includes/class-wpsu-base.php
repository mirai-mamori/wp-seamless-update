<?php
/**
 * 基础类文件
 *
 * 提供插件的核心功能和基础设施
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    die;
}

/**
 * 无缝更新插件基础类
 */
class WPSU_Base {
    /**
     * 插件实例
     *
     * @var WPSU_Base 单例实例
     */
    private static $instance = null;
    
    /**
     * 获取（并初始化，如果需要）插件实例
     *
     * @return WPSU_Base 插件实例
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 初始化插件钩子
     */    private function __construct() {
        // 加载国际化
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        
        // 添加自定义的更新检查间隔
        add_filter( 'cron_schedules', array( $this, 'add_custom_cron_interval' ) );
        
        // 注册自定义的更新检查事件
        add_action( 'init', array( $this, 'register_custom_update_check' ) );
        
        // 绑定执行更新检查的函数
        add_action( 'wpsu_custom_update_check', array( $this, 'trigger_theme_update_check' ) );
    }
    
    /**
     * 加载插件的文本域
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'wp-seamless-update', false, dirname( plugin_basename( dirname( __FILE__ ) ) ) . '/languages' );
    }
    
    /**
     * 添加2小时的自定义定时任务间隔
     *
     * @param array $schedules 现有的WordPress计划任务间隔
     * @return array 修改后的计划任务间隔
     */
    public function add_custom_cron_interval( $schedules ) {
        $schedules['wpsu_two_hours'] = array(
            'interval' => 2 * HOUR_IN_SECONDS,
            'display'  => __( '每2小时一次', 'wp-seamless-update' )
        );
        return $schedules;
    }
    
    /**
     * 注册自定义的更新检查事件
     */
    public function register_custom_update_check() {
        if ( ! wp_next_scheduled( 'wpsu_custom_update_check' ) ) {
            wp_schedule_event( time(), 'wpsu_two_hours', 'wpsu_custom_update_check' );
            error_log( 'WP Seamless Update: 已注册每2小时执行一次的更新检查事件' );
        }
    }
    
    /**
     * 触发主题更新检查
     */
    public function trigger_theme_update_check() {
        error_log( 'WP Seamless Update: 执行定时更新检查...' );
        delete_site_transient( 'update_themes' );
        wp_update_themes();
    }
}
