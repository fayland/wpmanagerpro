<?php

if( !function_exists( 'mng_parse_request' )) {
	function mng_parse_request(){
		global $current_user, $mng_core, $wp_db_version, $wpmu_version, $_wp_using_ext_object_cache;

		if (! isset($HTTP_RAW_POST_DATA)) {
			$HTTP_RAW_POST_DATA = file_get_contents('php://input');
		}
		if (! (strlen($HTTP_RAW_POST_DATA) > 0 && substr($HTTP_RAW_POST_DATA, 0, 13) === "wpmanagerpro=")) {
			$mng_core->get_stats_instance()->set_hit_count();
			return;
		}
		$HTTP_RAW_POST_DATA = str_replace("wpmanagerpro=", "", $HTTP_RAW_POST_DATA);

		ob_start();

		$data = base64_decode($HTTP_RAW_POST_DATA);
		$data = @unserialize($data);

		$action = $data['action'];
		if (! isset($action)) {
			mng_response('Invalid call with unknown action.', false);
		}

		$_wp_using_ext_object_cache = false;
		@set_time_limit(600);

		$params = $data['params'];
		$id     = $data['id'];
		$signature = $data['signature'];

		if (! $mng_core->check_if_user_exists($params['username']))
			mng_response('Username <b>' . $params['username'] . '</b> does not have administrator capabilities. Please check the Admin username.', false);

		if ($action === 'add_site') {
			$mng_core->mng_add_site($params, $id, $signature);
		}

		$auth = $mng_core->authenticate_message($action . $id . 'wpmanagerpro.com', $signature, $id);
		if ($auth === true) {
			if(isset($params['username']) && !is_user_logged_in()){
				$user = function_exists('get_user_by') ? get_user_by('login', $params['username']) : get_userdatabylogin( $params['username'] );
				wp_set_current_user($user->ID);
			}

			/* in case database upgrade required, do database backup and perform upgrade ( wordpress wp_upgrade() function ) */
			if( strlen(trim($wp_db_version)) && !defined('ACX_PLUGIN_DIR') ){
				if ( get_option('db_version') != $wp_db_version ) {
					/* in multisite network, please update database manualy */
					if (empty($wpmu_version) || (function_exists('is_multisite') && !is_multisite())){
						if( ! function_exists('wp_upgrade'))
							include_once(ABSPATH.'wp-admin/includes/upgrade.php');

						ob_clean();
						@wp_upgrade();
						@do_action('after_db_upgrade');
						ob_end_clean();
					}
				}
			}

			$mng_core->register_action_params($action, $params);
		} else {
			mng_response($auth['error'], false);
		}
		ob_end_clean();
	}
}

/* Main response function */
if( !function_exists ( 'mng_response' )) {

	function mng_response($response = false, $success = true)
	{
		$return = array();

		if ((is_array($response) && empty($response)) || (!is_array($response) && strlen($response) == 0)) {
			$return['error'] = 'Empty response.';
		} else if (is_array($response) && (array_key_exists('error', $response) || array_key_exists('success', $response))) {
			if (array_key_exists('error', $response)) $return['error'] = $response['error'];
			if (array_key_exists('success', $response)) $return['success'] = $response['success'];
		} else if ($success) {
			$return['success'] = $response;
		} else {
			$return['error'] = $response;
		}

		if( !headers_sent() ){
			header('HTTP/1.0 200 OK');
			header('Content-Type: text/plain');
		}
		exit("<wpmanagerpro>" . base64_encode(serialize($return))."</wpmanagerpro>");
	}
}

?>