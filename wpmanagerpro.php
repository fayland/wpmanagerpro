<?php
/*
Plugin Name: wpmanagerpro
Plugin URI: http://wpmanagerpro.com/
Description: Manage Multiple WordPress sites from one dashboard. Visit <a href="http://wpmanagerpro.com">wpmanagerpro.com</a> to sign up.
Author: fayland
Version: 1.0.1
Author URI: http://wpmanagerpro.com
*/

if (basename($_SERVER['SCRIPT_FILENAME']) == "wpmanagerpro.php"):
	exit;
endif;

if (version_compare(PHP_VERSION, '5.0.0', '<')) // min version 5 supported
	exit("<p>wpmanagerpro plugin requires PHP 5 or higher.</p>");

if(! defined('WPMANAGERPRO_VERSION'))
	define('WPMANAGERPRO_VERSION', '1.0.1');

if ( ! defined('MNG_XFRAME_COOKIE')) {
	$siteurl = function_exists( 'get_site_option' ) ? get_site_option( 'siteurl' ) : get_option( 'siteurl' );
	define('MNG_XFRAME_COOKIE', $xframe = 'wordpress_' . md5($siteurl) . '_wpmanagerpro_xframe');
}

global $wpdb, $wp_version;

global $mng_core, $mng_plugin_dir, $mng_plugin_url;
$mng_plugin_dir = WP_PLUGIN_DIR . '/' . basename(dirname(__FILE__));
$mng_plugin_url = WP_PLUGIN_URL . '/' . basename(dirname(__FILE__));

require_once("$mng_plugin_dir/functions.php");
require_once("$mng_plugin_dir/class/core.php");
$mng_core = new MNG_Core();

## parse request comment and authentication
add_action('setup_theme', 'mng_parse_request'); // functions.php
add_action('init', array( $mng_core, 'mng_remote_action'), 9999); // action is registered at mng_parse_request
add_action('set_auth_cookie', array( $mng_core, 'mng_set_auth_cookie'));
add_action('set_logged_in_cookie', array( $mng_core, 'mng_set_logged_in_cookie'));

if (isset($_GET['wpmanagerpro_auto_login']))
	$mng_core->automatic_login(MNG_XFRAME_COOKIE);
if(isset($_COOKIE[MNG_XFRAME_COOKIE]) ){
	remove_action( 'admin_init', 'send_frame_options_header');
	remove_action( 'login_init', 'send_frame_options_header');
}

## for install/mng_core
register_activation_hook( __FILE__ , array( $mng_core, 'install' ));
register_deactivation_hook(__FILE__, array( $mng_core, 'uninstall' ));
