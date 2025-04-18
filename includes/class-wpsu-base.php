<?php
/**
 * 基础类文件
 *
 * 提供插件的核心功能和基础设施
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
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
     */
    private function __construct() {
        // 加载国际化
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
    }
    
    /**
     * 加载插件的文本域
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'wp-seamless-update', false, dirname( plugin_basename( dirname( __FILE__ ) ) ) . '/languages' );
    }
}
