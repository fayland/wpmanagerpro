<?php

class MNG_Installer {
	private $mng_core;

	function __construct($mng_core) {
		$this->mng_core = $mng_core;

		@set_time_limit(600);
		@include_once(ABSPATH . 'wp-admin/includes/file.php');
        @include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        @include_once(ABSPATH . 'wp-admin/includes/theme.php');
        @include_once(ABSPATH . 'wp-admin/includes/misc.php');
        @include_once(ABSPATH . 'wp-admin/includes/template.php');
        @include_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');

		global $wp_filesystem;
        if (!$wp_filesystem)
            WP_Filesystem();
	}

	function mng_maintenance_mode($enable = false, $maintenance_message = '') {
        global $wp_filesystem;

        $maintenance_message .= '<?php $upgrading = ' . time() . '; ?>';
        $file = $wp_filesystem->abspath() . '.wpmanagerpro.maintenance';
        if ($enable) {
            $wp_filesystem->delete($file);
            $wp_filesystem->put_contents($file, $maintenance_message, FS_CHMOD_FILE);
        } else {
            $wp_filesystem->delete($file);
        }
    }

    function install_remote_file($params) {
        global $wp_filesystem;

        $package = $params['package'];
        $type    = $params['type'];
        $clear_destination = $params['clear_destination'];
        $activate = $params['activate'];

        if (!isset($package) || empty($package))
            return array(
                'error' => '<p>No files received. Internal error.</p>'
            );

        if (defined('WP_INSTALLING') && file_exists(ABSPATH . '.wpmanagerpro.maintenance'))
            return array(
                'error' => '<p>Site under maintanace.</p>'
            );

        $upgrader_skin = new WP_Upgrader_Skin();
        $upgrader_skin->done_header = true;

        $upgrader          = new WP_Upgrader($upgrader_skin);
        $destination       = $type == 'themes' ? WP_CONTENT_DIR . '/themes' : WP_PLUGIN_DIR;
        $clear_destination = isset($clear_destination) ? $clear_destination : false;

        foreach ($package as $package_url) {
            $key                = basename($package_url);
            $install_info[$key] = @$upgrader->run(array(
                'package' => $package_url,
                'destination' => $destination,
                'clear_destination' => $clear_destination, // Do not overwrite files.
                'clear_working' => true,
                'hook_extra' => array()
            ));
        }

        if ($activate) {
            if ($type == 'plugins') {
                $all_plugins = get_plugins();
                foreach ($all_plugins as $plugin_slug => $plugin) {
                    $plugin_dir = preg_split('/\//', $plugin_slug);
                    foreach ($install_info as $key => $install) {
                        if (!$install || is_wp_error($install))
                            continue;
                        if ($install['destination_name'] == $plugin_dir[0]) {
                            $install_info[$key]['activated'] = activate_plugin($plugin_slug, '', false);
                        }
                    }
                }
            } else if (count($install_info) == 1) {
                global $wp_themes;
                include_once(ABSPATH . 'wp-includes/theme.php');

                $wp_themes = null;
                unset($wp_themes); //prevent theme data caching
                if(function_exists('wp_get_themes')){
	                $all_themes = wp_get_themes();
	                foreach ($all_themes as $theme_name => $theme_data) {
	                    foreach ($install_info as $key => $install) {
	                        if (!$install || is_wp_error($install))
	                            continue;

	                        if ($theme_data->Template == $install['destination_name']) {
	                            $install_info[$key]['activated'] = switch_theme($theme_data->Template, $theme_data->Stylesheet);
	                        }
	                    }
	                }
                }else{
	                $all_themes = get_themes();
	                foreach ($all_themes as $theme_name => $theme_data) {
	                    foreach ($install_info as $key => $install) {
	                        if (!$install || is_wp_error($install))
	                            continue;

	                        if ($theme_data['Template'] == $install['destination_name']) {
	                            $install_info[$key]['activated'] = switch_theme($theme_data['Template'], $theme_data['Stylesheet']);
	                        }
	                    }
	                }
                }
            }
        }
        ob_clean();
        $this->mng_maintenance_mode(false);
        return $install_info;
    }

    function do_upgrade($params = null) {
		if ($params == null || empty($params))
            return array(
                'failed' => 'No upgrades passed.'
            );

        if (! $this->mng_core->is_server_writable()) {
            return array(
                'error' => 'Failed, please <a target="_blank" href="http://blog.wpmanagerpro.com/missing-ftp-information-why-do-my-pluginsthemes-fail-to-update/">add FTP details</a>'
            );
        }

        $params = isset($params['upgrades_all']) ? $params['upgrades_all'] : $params;

        $core_upgrade    = isset($params['wp_upgrade']) ? $params['wp_upgrade'] : array();
        $upgrade_plugins = isset($params['upgrade_plugins']) ? $params['upgrade_plugins'] : array();
        $upgrade_themes  = isset($params['upgrade_themes']) ? $params['upgrade_themes'] : array();

        $upgrades         = array();
        if (!empty($core_upgrade)) {
            $upgrades['core'] = $this->upgrade_core($core_upgrade);
        }

        if (!empty($upgrade_plugins)) {
            $plugin_files = array();
            foreach ($upgrade_plugins as $plugin) {
                if (isset($plugin->file))
                    $plugin_files[$plugin->file] = $plugin->old_version;
            }
            if (!empty($plugin_files))
                $upgrades['plugins'] = $this->upgrade_plugins($plugin_files);

        }

        if (!empty($upgrade_themes)) {
            $theme_temps = array();
            foreach ($upgrade_themes as $theme) {
                if (isset($theme['theme_tmp']))
                    $theme_temps[] = $theme['theme_tmp'];
            }

            if (!empty($theme_temps))
                $upgrades['themes'] = $this->upgrade_themes($theme_temps);

        }

        ob_clean();
        $this->mng_maintenance_mode(false);
        return $upgrades;
    }

    /**
     * Upgrades WordPress locally
     *
     */
    function upgrade_core($current)
    {
        ob_start();
        if (!function_exists('wp_version_check'))
            include_once(ABSPATH . '/wp-admin/includes/update.php');

        @wp_version_check();

        $current_update = false;
        ob_end_flush();
        ob_end_clean();
        $core = $this->mng_core->mng_get_transient('update_core');

        if (isset($core->updates) && !empty($core->updates)) {
            $updates = $core->updates[0];
            $updated = $core->updates[0];
            if (!isset($updated->response) || $updated->response == 'latest')
                return array(
                    'upgraded' => ' updated'
                );

            if ($updated->response == "development" && $current->response == "upgrade") {
                return array(
                    'upgraded' => '<font color="#900">Unexpected error. Please upgrade manually.</font>'
                );
            } else if ($updated->response == $current->response || ($updated->response == "upgrade" && $current->response == "development")) {
                if ($updated->locale != $current->locale) {
                    foreach ($updates as $update) {
                        if ($update->locale == $current->locale) {
                            $current_update = $update;
                            break;
                        }
                    }
                    if ($current_update == false)
                        return array(
                            'error' => ' Localization mismatch. Try again.'
                        );
                } else {
                    $current_update = $updated;
                }
            } else
                return array(
                    'error' => ' Transient mismatch. Try again.'
                );
        } else
            return array(
                'error' => ' Refresh transient failed. Try again.'
            );
        if ($current_update != false) {
            global $wp_filesystem, $wp_version;

            if (version_compare($wp_version, '3.1.9', '>')) {
                $core   = new Core_Upgrader();
                $result = $core->upgrade($current_update);
                $this->mng_maintenance_mode(false);
                if (is_wp_error($result)) {
                    return array(
                        'error' => $this->mng_get_error($result)
                    );
                } else
                    return array(
                        'upgraded' => ' updated'
                    );

            } else {
                if (!class_exists('WP_Upgrader')) {
                    include_once(ABSPATH . 'wp-admin/includes/update.php');
                    if (function_exists('wp_update_core')) {
                        $result = wp_update_core($current_update);
                        if (is_wp_error($result)) {
                            return array(
                                'error' => $this->mng_get_error($result)
                            );
                        } else
                            return array(
                                'upgraded' => ' updated'
                            );
                    }
                }

                if (class_exists('WP_Upgrader')) {
                    $upgrader_skin              = new WP_Upgrader_Skin();
                    $upgrader_skin->done_header = true;

                    $upgrader = new WP_Upgrader($upgrader_skin);

                    // Is an update available?
                    if (!isset($current_update->response) || $current_update->response == 'latest')
                        return array(
                            'upgraded' => ' updated'
                        );

                    $res = $upgrader->fs_connect(array(
                        ABSPATH,
                        WP_CONTENT_DIR
                    ));
                    if (is_wp_error($res))
                        return array(
                            'error' => $this->mng_get_error($res)
                        );

                    $wp_dir = trailingslashit($wp_filesystem->abspath());

                    $core_package = false;
                    if (isset($current_update->package) && !empty($current_update->package))
                        $core_package = $current_update->package;
                    elseif (isset($current_update->packages->full) && !empty($current_update->packages->full))
                        $core_package = $current_update->packages->full;

                    $download = $upgrader->download_package($core_package);
                    if (is_wp_error($download))
                        return array(
                            'error' => $this->mng_get_error($download)
                        );

                    $working_dir = $upgrader->unpack_package($download);
                    if (is_wp_error($working_dir))
                        return array(
                            'error' => $this->mng_get_error($working_dir)
                        );

                    if (!$wp_filesystem->copy($working_dir . '/wordpress/wp-admin/includes/update-core.php', $wp_dir . 'wp-admin/includes/update-core.php', true)) {
                        $wp_filesystem->delete($working_dir, true);
                        return array(
                            'error' => 'Unable to move update files.'
                        );
                    }

                    $wp_filesystem->chmod($wp_dir . 'wp-admin/includes/update-core.php', FS_CHMOD_FILE);

                    require(ABSPATH . 'wp-admin/includes/update-core.php');


                    $update_core = update_core($working_dir, $wp_dir);
                    ob_end_clean();

                    $this->mng_maintenance_mode(false);
                    if (is_wp_error($update_core))
                        return array(
                            'error' => $this->mng_get_error($update_core)
                        );
                    ob_end_flush();
                    return array(
                        'upgraded' => 'updated'
                    );
                } else {
                    return array(
                        'error' => 'failed'
                    );
                }
            }
        } else {
            return array(
                'error' => 'failed'
            );
        }
    }

    function upgrade_plugins($plugins = false) {
        if (!$plugins || empty($plugins))
            return array(
                'error' => 'No plugin files for upgrade.'
            );

		$current = $this->mng_core->mng_get_transient('update_plugins');
		$versions = array();
		if(!empty($current)){
			foreach($plugins as $plugin => $data){
				if(isset($current->checked[$plugin])){
					$versions[$current->checked[$plugin]] = $plugin;
				}
			}
		}
        $return = array();
        if (class_exists('Plugin_Upgrader') && class_exists('Bulk_Plugin_Upgrader_Skin')) {
            $upgrader = new Plugin_Upgrader(new Bulk_Plugin_Upgrader_Skin(compact('nonce', 'url')));
            $result   = $upgrader->bulk_upgrade(array_keys($plugins));
			if (!function_exists('wp_update_plugins'))
                include_once(ABSPATH . 'wp-includes/update.php');

            @wp_update_plugins();
			$current = $this->mng_core->mng_get_transient('update_plugins');
			if (!empty($result)) {
                foreach ($result as $plugin_slug => $plugin_info) {
                    if (!$plugin_info || is_wp_error($plugin_info)) {
                        $return[$plugin_slug] = $this->mng_get_error($plugin_info);
                    } else {
						if(!empty($result[$plugin_slug]) || (isset($current->checked[$plugin_slug]) && version_compare(array_search($plugin_slug, $versions), $current->checked[$plugin_slug], '<') == true)){
							$return[$plugin_slug] = 1;
						} else {
							update_option('mng_forcerefresh', true);
							$return[$plugin_slug] = 'Could not refresh upgrade transients, please reload website data';
						}
                    }
                }
                ob_end_clean();
                return array(
                    'upgraded' => $return
                );
            } else
                return array(
                    'error' => 'Upgrade failed.'
                );
        } else {
            ob_end_clean();
            return array(
                'error' => 'WordPress update required first.'
            );
        }
    }

    function upgrade_themes($themes = false) {
        if (!$themes || empty($themes))
            return array(
                'error' => 'No theme files for upgrade.'
            );

		$current = $this->mng_core->mng_get_transient('update_themes');
		$versions = array();
		if(!empty($current)){
			foreach($themes as $theme){
				if(isset($current->checked[$theme])){
					$versions[$current->checked[$theme]] = $theme;
				}
			}
		}
		if (class_exists('Theme_Upgrader') && class_exists('Bulk_Theme_Upgrader_Skin')) {
			$upgrader = new Theme_Upgrader(new Bulk_Theme_Upgrader_Skin(compact('title', 'nonce', 'url', 'theme')));
            $result = $upgrader->bulk_upgrade($themes);

			if (!function_exists('wp_update_themes'))
                include_once(ABSPATH . 'wp-includes/update.php');

            @wp_update_themes();
			$current = $this->mng_core->mng_get_transient('update_themes');
			$return = array();
            if (!empty($result)) {
                foreach ($result as $theme_tmp => $theme_info) {
					 if (is_wp_error($theme_info) || empty($theme_info)) {
                        $return[$theme_tmp] = $this->mng_get_error($theme_info);
                    } else {
						if(!empty($result[$theme_tmp]) || (isset($current->checked[$theme_tmp]) && version_compare(array_search($theme_tmp, $versions), $current->checked[$theme_tmp], '<') == true)){
							$return[$theme_tmp] = 1;
						} else {
							update_option('mng_forcerefresh', true);
							$return[$theme_tmp] = 'Could not refresh upgrade transients, please reload website data';
						}
                    }
                }
                return array(
                    'upgraded' => $return
                );
            } else
                return array(
                    'error' => 'Upgrade failed.'
                );
        } else {
            ob_end_clean();
            return array(
                'error' => 'WordPress update required first'
            );
        }
    }

    /* called from stats.php */
    function get_upgradable_plugins( $filter = array() ) {
        $current = $this->mng_core->mng_get_transient('update_plugins');
        $upgradable_plugins = array();
        if (! empty($current->response)) {
            foreach ($current->response as $plugin_path => $plugin_data) {
                if ($plugin_path == 'wpmanagerpro/wpmanagerpro.php') continue;

                $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_path);
				if(isset($data['Name']) && in_array($data['Name'], $filter))
					continue;

                if (strlen($data['Name']) > 0 && strlen($data['Version']) > 0) {
                    $current->response[$plugin_path]->name        = $data['Name'];
                    $current->response[$plugin_path]->old_version = $data['Version'];
                    $current->response[$plugin_path]->file        = $plugin_path;
                    unset($current->response[$plugin_path]->upgrade_notice);
                    $upgradable_plugins[]                         = $current->response[$plugin_path];
                }
            }
            return $upgradable_plugins;
        } else
            return array();
    }

    function get_upgradable_themes( $filter = array() )
    {
        if (function_exists('wp_get_themes')){
        	$all_themes     = wp_get_themes();
        	$upgrade_themes = array();

	        $current = $this->mng_core->mng_get_transient('update_themes');
	        if (!empty($current->response)) {
				foreach ((array) $all_themes as $theme_template => $theme_data) {
					if(isset($theme_data->{'Parent Theme'}) && !empty($theme_data->{'Parent Theme'}))
						continue;

					if(isset($theme_data->Name) && in_array($theme_data->Name, $filter))
						continue;

					foreach ($current->response as $current_themes => $theme) {
	                    if ($theme_data->Template == $current_themes) {
	                        if (strlen($theme_data->Name) > 0 && strlen($theme_data->Version) > 0) {
	                            $current->response[$current_themes]['name']        = $theme_data->Name;
	                            $current->response[$current_themes]['old_version'] = $theme_data->Version;
	                            $current->response[$current_themes]['theme_tmp']   = $theme_data->Template;
	                            $upgrade_themes[] = $current->response[$current_themes];
	                        }
	                    }
	                }
	            }
	        }
        }else{
	        $all_themes     = get_themes();
	        $upgrade_themes = array();
	        $current = $this->mng_core->mng_get_transient('update_themes');
	        if (!empty($current->response)) {
				foreach ((array) $all_themes as $theme_template => $theme_data) {
					if(isset($theme_data['Parent Theme']) && !empty($theme_data['Parent Theme']))
						continue;

					if(isset($theme_data['Name']) && in_array($theme_data['Name'], $filter))
						continue;

					foreach ($current->response as $current_themes => $theme) {
	                    if ($theme_data['Template'] == $current_themes) {
	                        if (strlen($theme_data['Name']) > 0 && strlen($theme_data['Version']) > 0) {
	                            $current->response[$current_themes]['name']        = $theme_data['Name'];
	                            $current->response[$current_themes]['old_version'] = $theme_data['Version'];
	                            $current->response[$current_themes]['theme_tmp']   = $theme_data['Template'];
	                            $upgrade_themes[] = $current->response[$current_themes];
	                        }
	                    }
	                }
	            }
	        }
        }

        return $upgrade_themes;
    }

    function get($args) {
        if (empty($args))
            return false;

        //Args: $items('plugins,'themes'), $type (active || inactive), $search(name string)

        $return = array();
        if (is_array($args['items']) && in_array('plugins', $args['items'])) {
            $return['plugins'] = $this->get_plugins($args);
        }
        if (is_array($args['items']) && in_array('themes', $args['items'])) {
            $return['themes'] = $this->get_themes($args);
        }

        return $return;
    }

    function get_plugins($args) {
        if (empty($args))
            return false;

        $search = $args['search'];

        $all_plugins = get_plugins();
        $plugins     = array(
            'active' => array(),
            'inactive' => array()
        );
        if (is_array($all_plugins) && !empty($all_plugins)) {
            $activated_plugins = get_option('active_plugins');
            if (!$activated_plugins)
                $activated_plugins = array();

            $br_a = 0;
            $br_i = 0;
            foreach ($all_plugins as $path => $plugin) {
                if ($plugin['Name'] != 'wpmanagerpro') {
                    if (in_array($path, $activated_plugins)) {
                        $plugins['active'][$br_a]['path'] = $path;
                        $plugins['active'][$br_a]['name'] = strip_tags($plugin['Name']);
						$plugins['active'][$br_a]['version'] = $plugin['Version'];
                        $br_a++;
                    }

                    if (!in_array($path, $activated_plugins)) {
                        $plugins['inactive'][$br_i]['path'] = $path;
                        $plugins['inactive'][$br_i]['name'] = strip_tags($plugin['Name']);
						$plugins['inactive'][$br_i]['version'] = $plugin['Version'];
                        $br_i++;
                    }

                }

                if ($search) {
                    foreach ($plugins['active'] as $k => $plugin) {
                        if (!stristr($plugin['name'], $search)) {
                            unset($plugins['active'][$k]);
                        }
                    }

                    foreach ($plugins['inactive'] as $k => $plugin) {
                        if (!stristr($plugin['name'], $search)) {
                            unset($plugins['inactive'][$k]);
                        }
                    }
                }
            }
        }

        return $plugins;
    }

    function get_themes($args) {
        if (empty($args))
            return false;

        $search = $args['search'];

        if (!function_exists('wp_get_themes')) {
            include_once(ABSPATH . WPINC . '/theme.php');
        }
        if(function_exists('wp_get_themes')){
	        $all_themes = wp_get_themes();
	        $themes     = array(
	            'active' => array(),
	            'inactive' => array()
	        );

	        if (is_array($all_themes) && !empty($all_themes)) {
	            $current_theme = get_current_theme();

	            $br_a = 0;
	            $br_i = 0;
	            foreach ($all_themes as $theme_name => $theme) {
	                if ($current_theme == strip_tags($theme->Name)) {
	                    $themes['active'][$br_a]['path']       = $theme->Template;
	                    $themes['active'][$br_a]['name']       = strip_tags($theme->Name);
						$themes['active'][$br_a]['version']    = $theme->Version;
	                    $themes['active'][$br_a]['stylesheet'] = $theme->Stylesheet;
	                    $br_a++;
	                }

	                if ($current_theme != strip_tags($theme->Name)) {
	                    $themes['inactive'][$br_i]['path']       = $theme->Template;
	                    $themes['inactive'][$br_i]['name']       = strip_tags($theme->Name);
						$themes['inactive'][$br_i]['version']    = $theme->Version;
	                    $themes['inactive'][$br_i]['stylesheet'] = $theme->Stylesheet;
	                    $br_i++;
	                }

	            }

	            if ($search) {
	                foreach ($themes['active'] as $k => $theme) {
	                    if (!stristr($theme['name'], $search)) {
	                        unset($themes['active'][$k]);
	                    }
	                }

	                foreach ($themes['inactive'] as $k => $theme) {
	                    if (!stristr($theme['name'], $search)) {
	                        unset($themes['inactive'][$k]);
	                    }
	                }
	            }
	        }
	    }else{
		   	$all_themes = get_themes();
	        $themes     = array(
	            'active' => array(),
	            'inactive' => array()
	        );

	        if (is_array($all_themes) && !empty($all_themes)) {
	            $current_theme = get_current_theme();

	            $br_a = 0;
	            $br_i = 0;
	            foreach ($all_themes as $theme_name => $theme) {
	                if ($current_theme == $theme_name) {
	                    $themes['active'][$br_a]['path']       = $theme['Template'];
	                    $themes['active'][$br_a]['name']       = strip_tags($theme['Name']);
						$themes['active'][$br_a]['version']    = $theme['Version'];
	                    $themes['active'][$br_a]['stylesheet'] = $theme['Stylesheet'];
	                    $br_a++;
	                }

	                if ($current_theme != $theme_name) {
	                    $themes['inactive'][$br_i]['path']       = $theme['Template'];
	                    $themes['inactive'][$br_i]['name']       = strip_tags($theme['Name']);
						$themes['inactive'][$br_i]['version']    = $theme['Version'];
	                    $themes['inactive'][$br_i]['stylesheet'] = $theme['Stylesheet'];
	                    $br_i++;
	                }

	            }

	            if ($search) {
	                foreach ($themes['active'] as $k => $theme) {
	                    if (!stristr($theme['name'], $search)) {
	                        unset($themes['active'][$k]);
	                    }
	                }

	                foreach ($themes['inactive'] as $k => $theme) {
	                    if (!stristr($theme['name'], $search)) {
	                        unset($themes['inactive'][$k]);
	                    }
	                }
	            }
	        }

	    }

        return $themes;
    }

    function edit($args) {
    	$type = $args['type'];
        $return = array();
        if ($type == 'plugins') {
            $return['plugins'] = $this->edit_plugins($args);
        } elseif ($type == 'themes') {
            $return['themes'] = $this->edit_themes($args);
        }
        return $return;
    }

    function edit_plugins($args) {
    	$items = $args['items'];
        $items_edit_action = $args['action'];

        $return = array();
        foreach ($items as $item) {
            switch ($items_edit_action) {
                case 'activate':
                    $result = activate_plugin($item['path']);
                    break;
                case 'deactivate':
                    $result = deactivate_plugins(array(
                        $item['path']
                    ));
                    break;
                case 'delete':
                    $result = delete_plugins(array(
                        $item['path']
                    ));
                    break;
                default:
                    break;
            }

            if (is_wp_error($result)) {
                $result = array(
                    'error' => $result->get_error_message()
                );
            } elseif ($result === false) {
                $result = array(
                    'error' => "Failed to perform action."
                );
            } else {
                $result = "OK";
            }
            $return[$item['path']] = $result;
        }

        return $return;
    }

    function edit_themes($args) {
        $items = $args['items'];
        $items_edit_action = $args['items_edit_action'];

        $return = array();
        foreach ($items as $item) {
            switch ($items_edit_action) {
                case 'activate':
                    switch_theme($item['path'], $item['stylesheet']);
                    break;
                case 'delete':
                    $result = delete_theme($item['path']);
                    break;
                default:
                    break;
            }

            if (is_wp_error($result)) {
                $result = array(
                    'error' => $result->get_error_message()
                );
            } elseif ($result === false) {
                $result = array(
                    'error' => "Failed to perform action."
                );
            } else {
                $result = "OK";
            }
            $return[$item['name']] = $result;
        }

        return $return;
    }

    function mmb_get_error($error_object) {
        if (!is_wp_error($error_object)) {
            return $error_object != '' ? $error_object : '';
        } else {
            $errors = array();
			if(!empty($error_object->error_data))  {
				foreach ($error_object->error_data as $error_key => $error_string) {
					$errors[] = str_replace('_', ' ', ucfirst($error_key)) . ': ' . $error_string;
				}
			} elseif (!empty($error_object->errors)){
				foreach ($error_object->errors as $error_key => $err) {
					$errors[] = 'Error: '.str_replace('_', ' ', strtolower($error_key));
				}
			}
            return implode('<br />', $errors);
        }
    }
}

?>