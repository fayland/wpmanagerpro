<?php

/* some of the functions are copied from 'worker' plugin. Thanks. */
class MNG_Core {

	public $is_multisite;
	public $network_admin_install;

	private $action_call;
	private $action_params;

	public $c_user;
	public $post_instance;
	public $stats_instance;
	public $installer_instance;
	public $comment_instance;
    public $links_instance;
    public $user_instance;
    public $backup_instance;

	function __construct() {
		global $mng_plugin_dir, $wpmu_version, $blog_id;

		$this->action_call = null;
		$this->action_params = null;

		if ( function_exists('is_multisite') ) {
			if ( is_multisite() ) {
				$this->is_multisite = $blog_id;
				$this->network_admin_install = get_option('mng_network_admin_install');
			}
		} else if (!empty($wpmu_version)) {
			$this->is_multisite = $blog_id;
			$this->network_admin_install = get_option('mng_network_admin_install');
		} else {
			$this->is_multisite = false;
			$this->network_admin_install = null;
		}

		// admin notices
		if ( ! get_option('_wpmanagerpro_public_key') ){
			if( $this->is_multisite ){
				if( is_network_admin() && $this->network_admin_install == '1'){
					add_action('network_admin_notices', array( &$this, 'network_admin_notice' ));
				} else if( $this->network_admin_install != '1' ){
					$parent_key = $this->get_parent_blog_option('_wpmanagerpro_public_key');
					if (empty($parent_key))
						add_action('admin_notices', array( &$this, 'admin_notice' ));
				}
			} else {
				add_action('admin_notices', array( &$this, 'admin_notice' ));
			}
		}
	}

	/* register action/params on 'mng_parse_request' when setup_theme */
	function register_action_params( $action = false, $params = array() ){
		$action = preg_replace('/[^a-zA-Z0-9\_]+/', '', $action); // validation
		$this->action_call = $action;
		$this->action_params = $params;
	}
	/* call action/params on init */
	function mng_remote_action() {
		if ($this->action_call != null){
			$params = isset($this->action_params) && $this->action_params != null ? $this->action_params : array();

			if (isset($params['username'])) {
				$this->c_user = $this->mng_get_user_info( $params['username'] );
			}

			list($module, $method) = explode('_', $this->action_call, 2);
			if ($module === 'posts') {
				$post_instance = $this->get_post_instance();
				if ($method == 'new_post' || $method == 'delete_post' || $method == 'bulk_action' || $method === 'get_posts' || $method === 'change_status' || $method == '') {
					$return = $post_instance->$method($params['args']);
					mng_response($return);
				}
			} elseif ($module === 'stats') {
				$stats_instance = $this->get_stats_instance();
				if ($method == 'get') {
					mng_response($stats_instance->get($params['args']));
				}
			} elseif ($module === 'installer') {
				$installer_instance = $this->get_installer_instance();
				if ($method === 'do_upgrade' || $method === 'get' || $method === 'edit' || $method == 'install_remote_file') {
					$return = $installer_instance->$method($params['args']);
					mng_response($return);
				}
			} elseif ($module === 'comments') {
				$comment_instance = $this->get_comment_instance();
				if ($method === 'change_status') {
					$return = $comment_instance->change_status($params['args']);
					if ($return) {
						mng_response($this->get_stats_instance()->get_comments(array(), array('numberposts' => 5)));
					} else {
						mng_response('Comment not updated', false);
					}
				} elseif ($method === 'get_comments' || $method === 'reply_comment' || $method === 'bulk_action') {
					$return = $comment_instance->$method($params['args']);
					mng_response($return);
				}
			} elseif ($module === 'links') {
				$links_instance = $this->get_links_instance();
				if ($method === 'get_links' || $method === 'new_link' || $method === 'delete_link' || $method === 'bulk_action') {
					$return = $links_instance->$method($params['args']);
					mng_response($return);
				}
			} elseif ($module === 'users') {
				$user_instance = $this->get_user_instance();
				if ($method === 'get_users' || $method === 'new_user' || $method === 'bulk_action') {
					$params['args']['username'] = $params['username'];
					$return = $user_instance->$method($params['args']);
					mng_response($return);
				}
			} elseif ($module === 'backups') {
				$backup_instance = $this->get_backup_instance();
				if ($method === 'set_backup_task' || $method === 'backup_now' || $method === 'delete_backup' || $method == 'optimize_tables' || $method === 'restore' || $method === 'cleanup') {
					$return = $backup_instance->$method($params['args']);
					mng_response($return);
				}
			} elseif ($module === 'core') {
				if ($method === 'remove_site') {
					$args = $params['args'];
					$deactivate = $args['deactivate'];
					$this->uninstall( $deactivate );
					include_once(ABSPATH . 'wp-admin/includes/plugin.php');
					global $mng_plugin_dir;
					$plugin_slug = "$mng_plugin_dir/wpmanagerpro.php";
					if ($deactivate) {
						deactivate_plugins($plugin_slug, true);
					}
					if (! is_plugin_active($plugin_slug)) {
						mng_response(array(
							'deactivated' => 'Site removed successfully. <br /><br />wpmanagerpro plugin successfully deactivated.'
						), true);
					} else {
						mng_response(array(
							'removed_data' => 'Site removed successfully. <br /><br /><b>wpmanagerpro plugin was not deactivated.</b>'
						), true);
					}
				}
			}

			mng_response('Invalid call.', false);

			// call_user_func($this->action_call, $params);
		}
	}

	function get_post_instance() {
		if (!isset($this->post_instance)) {
			global $mng_plugin_dir;
			require_once("$mng_plugin_dir/class/posts.php");
			$this->post_instance = new MNG_Post($this);
		}
		return $this->post_instance;
	}

	function get_stats_instance() {
		if (!isset($this->stats_instance)) {
			global $mng_plugin_dir;
			require_once("$mng_plugin_dir/class/stats.php");
			$this->stats_instance = new MNG_Stats($this);
		}
		return $this->stats_instance;
	}

	function get_installer_instance() {
		if (!isset($this->installer_instance)) {
			global $mng_plugin_dir;
			require_once("$mng_plugin_dir/class/installer.php");
			$this->installer_instance = new MNG_Installer($this);
		}
		return $this->installer_instance;
	}

	function get_comment_instance() {
		if (!isset($this->comment_instance)) {
			global $mng_plugin_dir;
			require_once("$mng_plugin_dir/class/comments.php");
			$this->comment_instance = new MNG_Comment($this);
		}
		return $this->comment_instance;
	}

	function get_links_instance() {
		if (!isset($this->links_instance)) {
			global $mng_plugin_dir;
			require_once("$mng_plugin_dir/class/links.php");
			$this->links_instance = new MNG_Link($this);
		}
		return $this->links_instance;
	}

	function get_user_instance() {
		if (!isset($this->user_instance)) {
			global $mng_plugin_dir;
			require_once("$mng_plugin_dir/class/user.php");
			$this->user_instance = new MNG_User($this);
		}
		return $this->user_instance;
	}

	function get_backup_instance() {
		if (!isset($this->backup_instance)) {
			global $mng_plugin_dir;
			require_once("$mng_plugin_dir/class/backups.php");
			$this->backup_instance = new MNG_Backup($this);
		}
		return $this->backup_instance;
	}

	/* add site with publickey */
	function mng_add_site($params, $id, $signature) {
		$public_key = $params['public_key'];
		if (! isset($public_key)) {
			mng_response('Invalid parameters received. Please try again.', false);
		}
		if (get_option('_wpmanagerpro_message_id')) {
			mng_response('Handshake not successful. Please deactivate, then activate wpmanagerpro plugin on your site, and re-add this site to your dashboard.', false);
		}

		$action = 'add_site';
		$public_key = base64_decode($public_key);
		$verify = openssl_verify($action . $id . 'wpmanagerpro.com', base64_decode($signature), $public_key);
		if ($verify == 1) {
			add_option('_wpmanagerpro_public_key', base64_encode($public_key));
			add_option('_wpmanagerpro_message_id', $id);

			$stats_instance = $this->get_stats_instance();
			mng_response($stats_instance->get(array()), true);
		} else {
			mng_response('Command not successful. Please try again.', false);
		}
	}
	/* validate request for authentication */
	function authenticate_message($data = false, $signature = false, $message_id = false) {
		if (!$data && !$signature) {
			return array(
				'error' => 'Authentication failed.'
			);
		}

		$current_message = (int) get_option('_wpmanagerpro_message_id');
		if ($current_message >= (int) $message_id)
			return array(
				'error' => 'Invalid message recieved. Deactivate and activate the wpmanagerpro plugin on this site, then re-add it to your wpmanagerpro account.'
			);

		$pl_key = base64_decode(get_option('_wpmanagerpro_public_key'));
		if (! $pl_key) {
			return array(
				'error' => 'Authentication failed. Deactivate and activate the wpmanagerpro plugin on this site, then re-add it to your wpmanagerpro account.'
			);
		}

		$verify = openssl_verify($data, base64_decode($signature), $pl_key);
		if ($verify == 1) {
			update_option('_wpmanagerpro_message_id', $message_id);
			return true;
		} else if ($verify == 0) {
			return array(
				'error' => 'Invalid message signature. Deactivate and activate the wpmanagerpro plugin on this site, then re-add it to your wpmanagerpro account.'
			);
		} else {
			return array(
				'error' => 'Command not successful! Please try again.'
			);
		}
	}

	/**
	 * Automatically logs in when called from Master
	 *
	 */
	function automatic_login($MNG_XFRAME_COOKIE) {
		$where      = isset($_GET['mng_goto']) ? $_GET['mng_goto'] : '';
		$username   = isset($_GET['username']) ? $_GET['username'] : '';
		$auto_login = isset($_GET['wpmanagerpro_auto_login']) ? $_GET['wpmanagerpro_auto_login'] : 0;

		if( !function_exists('is_user_logged_in') )
			include_once( ABSPATH.'wp-includes/pluggable.php' );

		if (( $auto_login && strlen(trim($username)) && !is_user_logged_in() ) || (isset($this->is_multisite) && $this->is_multisite )) {
			$signature  = base64_decode($_GET['signature']);
			$message_id = (int) trim($_GET['message_id']);

			$auth = $this->authenticate_message($where . $message_id . 'wpmanagerpro.com', $signature, $message_id);
			if ($auth === true) {
				if (!headers_sent())
					header('P3P: CP="CAO PSA OUR"');

				if(!defined('MNG_USER_LOGIN'))
					define('MNG_USER_LOGIN', true);

				$siteurl = function_exists('get_site_option') ? get_site_option( 'siteurl' ) : get_option('siteurl');
				$user = $this->mng_get_user_info($username);
				wp_set_current_user($user->ID);

				if(!defined('COOKIEHASH') || (isset($this->is_multisite) && $this->is_multisite) )
					wp_cookie_constants();

				wp_set_auth_cookie($user->ID);
				$this->mng_header($MNG_XFRAME_COOKIE);

				if((isset($this->is_multisite) && $this->is_multisite ) || isset($_REQUEST['mngredirect'])){
					if(function_exists('wp_safe_redirect') && function_exists('admin_url')){
						wp_safe_redirect(admin_url($where));
						exit();
					}
				}
			} else {
				wp_die($auth['error']);
			}
		} elseif( is_user_logged_in() ) {
			$this->mng_header($MNG_XFRAME_COOKIE);
			if(isset($_REQUEST['mngredirect'])){
				if(function_exists('wp_safe_redirect') && function_exists('admin_url')){
					wp_safe_redirect(admin_url($where));
					exit();
				}
			}
		}
	}

	function mng_header($MNG_XFRAME_COOKIE) {
		global $current_user;
		if(!headers_sent()){
			if(isset($current_user->ID))
				$expiration = time() + apply_filters('auth_cookie_expiration', 10800, $current_user->ID, false);
			else
				$expiration = time() + 10800;

			setcookie($MNG_XFRAME_COOKIE, md5($MNG_XFRAME_COOKIE), $expiration, COOKIEPATH, COOKIE_DOMAIN, false, true);
			$_COOKIE[$MNG_XFRAME_COOKIE] = md5($MNG_XFRAME_COOKIE);
		}
	}

	function mng_set_auth_cookie( $auth_cookie ){
		if(!defined('MNG_USER_LOGIN'))
			return false;
		if( !defined('COOKIEHASH') )
			wp_cookie_constants();

		$_COOKIE['wordpress_'.COOKIEHASH] = $auth_cookie;

	}
	function mng_set_logged_in_cookie( $logged_in_cookie ){
		if(!defined('MNG_USER_LOGIN'))
			return false;
		if( !defined('COOKIEHASH') )
			wp_cookie_constants();

		$_COOKIE['wordpress_logged_in_'.COOKIEHASH] = $logged_in_cookie;
	}

	function check_backup_tasks() {
		global $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = false;

		$backup_instance = $this->get_backup_instance();
		$backup_instance->check_backup_tasks();
	}

	/* install/uninstall */
	/**
	 * Plugin install callback function
	 * Check PHP version
	 */
	function install() {
		global $wpdb, $_wp_using_ext_object_cache, $current_user;
		$_wp_using_ext_object_cache = false;

		// delete plugin options, just in case
		if ($this->is_multisite != false) {
			$network_blogs = $wpdb->get_results("select `blog_id`, `site_id` from `{$wpdb->blogs}`");
			if(!empty($network_blogs)){
				if( is_network_admin() ){
					update_option('mng_network_admin_install', 1);
					foreach ($network_blogs as $details){
						if($details->site_id == $details->blog_id)
							update_blog_option($details->blog_id, 'mng_network_admin_install', 1);
						else
							update_blog_option($details->blog_id, 'mng_network_admin_install', -1);

						delete_blog_option($blog_id, '_wpmanagerpro_public_key');
						delete_blog_option($blog_id, '_wpmanagerpro_message_id');
					}
				} else {
					update_option('mng_network_admin_install', -1);
					delete_option('_wpmanagerpro_public_key');
					delete_option('_wpmanagerpro_message_id');
				}
			}
		} else {
			delete_option('_wpmanagerpro_public_key');
			delete_option('_wpmanagerpro_message_id');
		}
	}
	function uninstall( $deactivate = false )
	{
		global $wpdb, $_wp_using_ext_object_cache, $current_user;
		$_wp_using_ext_object_cache = false;

		if ($this->is_multisite != false) {
			$network_blogs = $wpdb->get_col("select `blog_id` from `{$wpdb->blogs}`");
			if(! empty($network_blogs)){
				if( is_network_admin() ){
					if( $deactivate ) {
						delete_option('mng_network_admin_install');
						foreach ($network_blogs as $blog_id){
							delete_blog_option($blog_id, 'mng_network_admin_install');
							delete_blog_option($blog_id, '_wpmanagerpro_public_key');
							delete_blog_option($blog_id, '_wpmanagerpro_message_id');
						}
					}
				} else {
					if( $deactivate )
						delete_option('mng_network_admin_install');

					delete_option('_wpmanagerpro_public_key');
					delete_option('_wpmanagerpro_message_id');
				}
			}
		} else {
			delete_option('_wpmanagerpro_public_key');
			delete_option('_wpmanagerpro_message_id');
		}

		wp_clear_scheduled_hook('mng_backup_tasks_hook');
	}

	/**
	 * Add notice to network admin dashboard for security reasons
	 *
	 */
	function network_admin_notice() {
		echo '<div class="error" style="text-align: center;"><p style="color: red; font-size: 14px; font-weight: bold;">Attention !</p><p>
		Please add this site and your network blogs, with your network administrator username, to your <a target="_blank" href="http://wpmanagerpro.com/">wpmanagerpro.com</a> account now to remove this notice or "Network Deactivate" the wpmanagerpro plugin to avoid security issues.
		</p></div>';
	}

	/**
	 * Add notice to admin dashboard for security reasons
	 *
	 */
	function admin_notice() {
		echo '<div class="error" style="text-align: center;"><p style="color: red; font-size: 14px; font-weight: bold;">Attention !</p><p>
		Please add this site to your <a target="_blank" href="http://wpmanagerpro.com/">wpmanagerpro.com</a> account now. Or deactivate the wpmanagerpro plugin to avoid security issues.
		</p></div>';
	}

	function check_if_user_exists($username = false) {
		global $wpdb;
		if ($username) {
			if( !function_exists('username_exists') )
				include_once(ABSPATH . WPINC . '/registration.php');

			include_once(ABSPATH . 'wp-includes/pluggable.php');

			if (username_exists($username) == null) {
				return false;
			}

			$user = (array) $this->mng_get_user_info( $username );
			if ((isset($user[$wpdb->prefix . 'user_level']) && $user[$wpdb->prefix . 'user_level'] == 10) || isset($user[$wpdb->prefix . 'capabilities']['administrator']) ||
				(isset($user['caps']['administrator']) && $user['caps']['administrator'] == 1)){
				return true;
			}
			return false;
		}
		return false;
	}

	/* get user info */
	function mng_get_user_info( $user_info = false, $info = 'login' ){

		if($user_info === false)
			return false;

		if( strlen( trim( $user_info ) ) == 0)
			return false;


		global $wp_version;
		if (version_compare($wp_version, '3.2.2', '<=') and $info === 'login'){
			return get_userdatabylogin( $user_info );
		} else {
			return get_user_by( $info, $user_info );
		}
	}

	function mng_function_exists($function_callback){

		if(!function_exists($function_callback))
			return false;

		$disabled = explode(', ', @ini_get('disable_functions'));
		if (in_array($function_callback, $disabled))
			return false;

		if (extension_loaded('suhosin')) {
			$suhosin = @ini_get("suhosin.executor.func.blacklist");
			if (empty($suhosin) == false) {
				$suhosin = explode(',', $suhosin);
				$blacklist = array_map('trim', $suhosin);
				$blacklist = array_map('strtolower', $blacklist);
				if(in_array($function_callback, $blacklist))
					return false;
			}
		}
		return true;
	}

	function mng_set_transient($option_name = false, $data = false){

		if (!$option_name || !$data) {
			return false;
		}
		if($this->is_multisite)
			return $this->mng_set_sitemeta_transient($option_name, $data);

		global $wp_version;

		if (version_compare($wp_version, '2.7.9', '<=')) {
			update_option($option_name, $data);
		} else if (version_compare($wp_version, '2.9.9', '<=')) {
			update_option('_transient_' . $option_name, $data);
		} else {
			update_option('_site_transient_' . $option_name, $data);
		}

	}
	function mng_get_transient($option_name)
	{
		if (trim($option_name) == '') {
			return FALSE;
		}
		if($this->is_multisite)
			return $this->mng_get_sitemeta_transient($option_name);

		global $wp_version;

		$transient = array();

		if (version_compare($wp_version, '2.7.9', '<=')) {
			return get_option($option_name);
		} else if (version_compare($wp_version, '2.9.9', '<=')) {
			$transient = get_option('_transient_' . $option_name);
			return apply_filters("transient_".$option_name, $transient);
		} else {
			$transient = get_option('_site_transient_' . $option_name);
			return apply_filters("site_transient_".$option_name, $transient);
		}
	}

	function mng_delete_transient($option_name)
	{
		if (trim($option_name) == '') {
			return FALSE;
		}

		global $wp_version;

		if (version_compare($wp_version, '2.7.9', '<=')) {
			delete_option($option_name);
		} else if (version_compare($wp_version, '2.9.9', '<=')) {
			delete_option('_transient_' . $option_name);
		} else {
			delete_option('_site_transient_' . $option_name);
		}
	}

	function mng_get_sitemeta_transient($option_name){
		global $wpdb;
		$option_name = '_site_transient_'. $option_name;

		$result = $wpdb->get_var( $wpdb->prepare("SELECT `meta_value` FROM `{$wpdb->sitemeta}` WHERE meta_key = %s AND `site_id` = %s", $option_name, $this->is_multisite));
		$result = maybe_unserialize($result);
		return $result;
	}

	function mng_set_sitemeta_transient($option_name, $option_value){
		global $wpdb;
		$option_name = '_site_transient_'. $option_name;

		if($this->mng_get_sitemeta_transient($option_name)){
			$result = $wpdb->update( $wpdb->sitemeta,
				array(
					'meta_value' => maybe_serialize($option_value)
				),
				array(
					'meta_key' => $option_name,
					'site_id' => $this->is_multisite
				)
			);
		}else {
			$result = $wpdb->insert( $wpdb->sitemeta,
				array(
					'meta_key' => $option_name,
					'meta_value' => maybe_serialize($option_value),
					'site_id' => $this->is_multisite
				)
			);
		}
		return $result;
	}

	function is_server_writable(){
		if((!defined('FTP_HOST') || !defined('FTP_USER') || !defined('FTP_PASS')) && (get_filesystem_method(array(), false) != 'direct'))
			return false;
		else
			return true;
	}
}

?>