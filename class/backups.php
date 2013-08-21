<?php

if (basename($_SERVER['SCRIPT_FILENAME']) == "backups.php"):
    echo "Sorry but you cannot browse this file directly!";
    exit;
endif;
define('MNG_BACKUP_DIR', WP_CONTENT_DIR . '/wpmanagerpro/backups');
define('MNG_DB_DIR', MNG_BACKUP_DIR . '/mng_db');

$zip_errors = array(
    'No error',
    'No error',
    'Unexpected end of zip file',
    'A generic error in the zipfile format was detected.',
    'zip was unable to allocate itself memory',
    'A severe error in the zipfile format was detected',
    'Entry too large to be split with zipsplit',
    'Invalid comment format',
    'zip -T failed or out of memory',
    'The user aborted zip prematurely',
    'zip encountered an error while using a temp file',
    'Read or seek error',
    'zip has nothing to do',
    'Missing or empty zip file',
    'Error writing to a file',
    'zip was unable to create a file to write to',
    'bad command line parameters',
    'no error',
    'zip could not open a specified file to read'
);

class MNG_Backup {
	private $mng_core;

    var $site_name;
    var $statuses;
    var $tasks;

    /**
     * Initializes site_name, statuses, and tasks attributes.
     *
     * @return	void
     */
    function __construct($mng_core) {
		$this->mng_core = $mng_core;

        $this->site_name = str_replace(array(
            "_", "/", "~"
        ), array(
            "", "-", "-"
        ), rtrim($this->remove_http(get_bloginfo('url')), "/"));
        $this->statuses  = array(
            'db_dump' => 1,
            'db_zip' => 2,
            'files_zip' => 3,
            'dropbox' => 5,
            'ftp' => 6,
            'email' => 7,
        	'google_drive' => 8,
            'finished' => 100
        );
    }

    /**
     * Checks if scheduled task is ready for execution,
     * run by wp_schedule_event( time(), 'tenminutes', 'mng_backup_tasks_hook' );
     */
    function check_backup_tasks() {
        // no tasks
        $tasks = get_option('mng_backup_tasks');
        if (! (isset($tasks) && ! empty($tasks))){
            wp_clear_scheduled_hook('mng_backup_tasks_hook');
            exit;
        }

        foreach ($tasks as $task_id => $setting) {
            if (isset($setting['task_args']['next']) && $setting['task_args']['next'] < time()) {
                if ($setting['task_args']['url'] && $setting['task_args']['site_id']) {
                    //Check orphan task
                    $check_data = array(
                        'task_id' => $task_id,
                        'site_key' => $setting['task_args']['site_id'],
                    );

                    if (isset($setting['task_args']['account_info']['google_drive']['google_drive_token'])) {
                        $check_data['google_drive_refresh_token'] = true;
                    }

                    $check = $this->validate_task($check_data, $setting['task_args']['url']);
                    if($check == 'paused' || $check == 'deleted'){
                        continue;
                    }

                    $potential_token = isset($check['google_drive_token']) ? $check['google_drive_token'] : false;
                    if ($potential_token) {
                        $tasks[$task_id]['task_args']['account_info']['google_drive']['google_drive_token'] = $potential_token;
                        $setting['task_args']['account_info']['google_drive']['google_drive_token'] = $potential_token;
                    }
                }

                $update = array(
                    'task_id' => $task_id,
                    'args' => $setting['task_args'],
                    'time' => time(),
                );

                // Update task with next schedule
                $this->set_backup_task($update);

                $result = $this->backup($setting['task_args'], $task_id);
                $error  = '';

                if (is_array($result) && array_key_exists('error', $result)) {
                    $error = $result;
                    $this->set_backup_task(array(
                        'task_id' => $task_id,
                        'args' => $setting['task_args'],
                        'error' => $error
                    ));
                } else {
                    if (@count($setting['task_args']['account_info'])) {
                        $updated_tasks = get_option('mng_backup_tasks');
                        $this->remote_backup_now(array('task_id' => $task_id));
                    }
                }

                break; // Only one backup per cron
            }
        }
    }

    /**
     * Checks if scheduled backup tasks should be executed.
     *
     * @param   array   $args           arguments passed to function
     * [task_id] -> id of backup task
     * [$site_key] -> hash key of backup task
     * [google_drive_refresh_token] ->  should be Google Drive token be refreshed, true if it is remote destination of task
     * @param   string  $url            url where validate task
     * @return  string|array|boolean
     */
    function validate_task($args, $url) {
        if (!class_exists('WP_Http')) {
            include_once(ABSPATH . WPINC . '/class-http.php');
        }

        $params         = array('timeout'=>100);
        $params['body'] = $args;
        $result         = wp_remote_post($url, $params);

        if (is_array($result) && $result['body']) {
            $response = unserialize($result['body']);
            if ($response['message'] == 'delete_task') {
                $tasks = get_option('mng_backup_tasks');
                unset($tasks[$args['task_id']]);
                update_option('mng_backup_tasks', $tasks);
                $this->cleanup();
                return 'deleted';
            } elseif ($response['message'] == 'pause_task') {
                return 'paused';
            } elseif ($response['message'] == 'do_task') {
                return $response;
            }
        }

        return false;
    }

    /**
     * Sets backup task defined
     */
    function set_backup_task($params) {
        if (empty($params)) return false;

        // $params => [$task_id, $args, $error]

    	// Make sure backup cron job is set
    	if (! wp_next_scheduled('mng_backup_tasks_hook')) {
			wp_schedule_event( time(), 'tenminutes', 'mng_backup_tasks_hook' );
		}

        $before = get_option('mng_backup_tasks');
        if (!$before || empty($before))
            $before = array();

        $task_id = $params['task_id'];
        $args = $params['args'];
        $error = $params['error'];
        $time = $params['time'];

        $run_now = $args['run_now'];
        unset($args['run_now']);

        if (isset($args['remove'])) {
            unset($before[$task_id]);
            $return = array(
                'removed' => true
            );
        } else {
            if (isset($params['account_info']) && is_array($params['account_info'])) {
                $args['account_info'] = $params['account_info'];
            }

            $before[$task_id]['task_args'] = $args;
            if (strlen($args['schedule']))
                $before[$task_id]['task_args']['next'] = $this->schedule_next($args['type'], $args['schedule']);

            $return = $before[$task_id];
        }

        // Update with error
        if (isset($error)) {
            if (is_array($error)) {
                $before[$task_id]['task_results'][count($before[$task_id]['task_results']) - 1]['error'] = $error['error'];
            } else {
                $before[$task_id]['task_results'][count($before[$task_id]['task_results'])]['error'] = $error;
            }
        }

        if (isset($time) && $time) { // set next result time before backup
            if (is_array($before[$task_id]['task_results'])) {
                $before[$task_id]['task_results'] = array_values($before[$task_id]['task_results']);
            }
            $before[$task_id]['task_results'][count($before[$task_id]['task_results'])]['time'] = $time;
        }

        update_option('mng_backup_tasks', $tasks);

        if ($run_now) {
        	$result = $this->backup($args, $task_id);
            if (is_array($result) && array_key_exists('error', $result)) {
            	$return = $result;
            } else {
                $tasks = get_option('mng_backup_tasks');
                $return = $tasks[$task_id];
            }
        }
        return $return;
    }



    /**
     * Runs backup task invoked from wpmanagerpro.
     */
    function task_now($task_id, $google_drive_token = false) {
        $settings = get_option('mng_backup_tasks');
		if ($google_drive_token) {
			$settings[$task_id]['task_args']['account_info']['google_drive']['google_drive_token'] = $google_drive_token;
		}

    	if(!array_key_exists($task_id, $settings)){
    	 	return array('error' => $task_id . " does not exist.");
    	} else {
    	 	$setting = $settings[$task_id];
    	}

		$this->set_backup_task(array(
			'task_id' => $task_id,
			'args' => $settings[$task_id]['task_args'],
			'time' => time()
		));

		//Run backup
		$result = $this->backup($setting['task_args'], $task_id);

		//Check for error
		if (is_array($result) && array_key_exists('error', $result)) {
			$this->set_backup_task(array(
				'task_id' => $task_id,
				'args' => $settings[$task_id]['task_args'],
				'error' => $result
			));
			return $result;
		} else {
			return $this->get_backup_stats();
		}
    }

    /**
     * Backup a full wordpress instance, including a database dump, which is placed in mng_db dir in root folder.
     * All backups are compressed by zip and placed in wp-content/wpmanagerpro/backups folder.
     *
     * @param	string					$args			arguments passed
     * [type] -> db, full
     * [what] -> daily, weekly, monthly
     * [account_info] -> remote destinations ftp, dropbox, google_drive with their parameters
     * [include] -> array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     * [exclude] -> array of files of folders to exclude, relative to site's root
     * @param	bool|string[optional]	$task_id		the id of backup task
     * @return	bool|array								false if $args are missing, array with error if error has occured, ture if is successful
     */
    function backup($args, $task_id = false) {
        if (!$args || empty($args))
            return false;

        $account_info = $args['account_info'];
        $type = $args['type'];
        $what = $args['what'];
        $include = $args['include'];
        $exclude = $args['exclude'];
        $optimize_tables = $args['optimize_tables'];

		if (!empty($account_info)) {
			$found = false;
			$destinations = array('ftp', 'dropbox', 'google_drive');
			foreach($destinations as $dest) {
				$found = $found || (isset($account_info[$dest]));
			}
			if (! $found) {
				$error_message = 'Remote destination is not supported, please update your client plugin.';
				return array(
					'error' => $error_message
				);
			}
		}

        // Try increase memory limit	and execution time
      	$this->set_memory();

        // Remove old backup(s)
        $removed = $this->remove_old_backups($task_id);
        if (is_array($removed) && isset($removed['error'])) {
        	$error_message = $removed['error'];
        	return $removed;
        }

        $new_file_path = MNG_BACKUP_DIR;
        if (!file_exists($new_file_path)) {
            if (! mkdir($new_file_path, 0755, true)) {
            	$error_message = 'Permission denied, make sure you have write permission to wp-content folder.';
            	return array(
	            	'error' => $error_message
	            );
            }
        }

        @file_put_contents($new_file_path . '/index.php', ''); //safe

        // Prepare .zip file name
        $hash        = md5(time());
        $label       = $type ? $type : 'manual';
        $backup_file = $new_file_path . '/' . $this->site_name . '_' . $label . '_' . $what . '_' . date('Y-m-d') . '_' . $hash . '.zip';
        $backup_url  = WP_CONTENT_URL . '/wpmanagerpro/backups/' . $this->site_name . '_' . $label . '_' . $what . '_' . date('Y-m-d') . '_' . $hash . '.zip';

        $begin_compress = microtime(true);

        //Optimize tables?
        if (isset($optimize_tables) && !empty($optimize_tables)) {
            $this->optimize_tables();
        }

        //What to backup - db or full?
        if (trim($what) == 'db') {
            $db_backup = $this->backup_db_compress($task_id, $backup_file);
            if (is_array($db_backup) && array_key_exists('error', $db_backup)) {
            	$error_message = $db_backup['error'];
            	return array(
            		'error' => $error_message
            	);
            }
        } elseif (trim($what) == 'full') {
            if (!$exclude) {
            	$exclude = array();
            }
            if (!$include) {
            	$include = array();
            }
        	$content_backup = $this->backup_full($task_id, $backup_file, $exclude, $include);
            if (is_array($content_backup) && array_key_exists('error', $content_backup)) {
            	$error_message = $content_backup['error'];
            	return array(
                    'error' => $error_message
                );
            }
        }

        $end_compress = microtime(true);

        // Update backup info
        if ($task_id) {
            //backup task (scheduled)
            $tasks     = get_option('mng_backup_tasks');
            $paths     = array();
            $size      = ceil(filesize($backup_file) / 1024);
            $duration  = round($end_compress - $begin_compress, 2);

            if ($size > 1000) {
                $paths['size'] = ceil($size / 1024) . "mb";
            } else {
                $paths['size'] = $size . 'kb';
            }

            $paths['duration'] = $duration . 's';
            $paths['server'] = array(
                'file_path' => $backup_file,
                'file_url' => $backup_url
            );

            if (isset($tasks[$task_id]['task_args']['account_info']['ftp'])) {
                $paths['ftp'] = basename($backup_url);
            }
            if (isset($tasks[$task_id]['task_args']['account_info']['dropbox'])) {
                $paths['dropbox'] = basename($backup_url);
            }
            if (isset($tasks[$task_id]['task_args']['account_info']['google_drive'])) {
            	$paths['google_drive'] = basename($backup_url);
            }

            $temp          = $tasks[$task_id]['task_results'];
            $temp          = array_values($temp);
            $paths['time'] = time();

            if ($tasks[$task_id]['task_args']['run_now']) {
                $paths['status']        = $temp[count($temp) - 1]['status'];
                $temp[count($temp) - 1] = $paths;
                unset($tasks[$task_id]['task_args']['run_now']);
            } else {
                $temp[count($temp)] = $paths;
            }

            $tasks[$tasks]['task_results'] = $temp;
            update_option('mng_backup_tasks', $tasks);
        }

        // If there are not remote destination, set up task status to finished
        if (@count($tasks[$task_id]['task_args']['account_info']) == 0) {
        	$this->update_status($task_id, $this->statuses['finished'], true);
        }

        return true;
    }

    /**
     * Backup a full wordpress instance, including a database dump, which is placed in mng_db dir in root folder.
     * All backups are compressed by zip and placed in wp-content/wpmanagerpro/backups folder.
     *
     * @param	string			$task_id		the id of backup task
     * @param	string			$backup_file	relative path to file which backup is stored
     * @param	array[optional]	$exclude		the list of files and folders, which are excluded from backup (default: array())
     * @param	array[optional]	$include		the list of folders in wordpress root which are included to backup, expect wp-admin, wp-content, wp-includes, which are default (default: array())
     * @return	bool|array						true if backup is successful, or an array with error message if is failed
     */
    function backup_full($task_id, $backup_file, $exclude = array(), $include = array()) {
    	$this->update_status($task_id, $this->statuses['db_dump']);
        $db_result = $this->backup_db();

        if ($db_result == false) {
            return array(
                'error' => 'Failed to backup database.'
            );
        } else if (is_array($db_result) && isset($db_result['error'])) {
            return array(
                'error' => $db_result['error']
            );
        }

        $this->update_status($task_id, $this->statuses['db_dump'], true);
        $this->update_status($task_id, $this->statuses['db_zip']);

        @file_put_contents(MNG_BACKUP_DIR.'/mng_db/index.php', '');
        $zip_db_result = $this->zip_backup_db($task_id, $backup_file);

        if (!$zip_db_result) {
        	$zip_archive_db_result = false;
        	if (class_exists("ZipArchive")) {
        		$zip_archive_db_result = $this->zip_archive_backup_db($task_id, $db_result, $backup_file);
			}

			if (!$zip_archive_db_result) {
				$pclzip_db_result = $this->pclzip_backup_db($task_id, $backup_file);
				if (!$pclzip_db_result) {
					@unlink(MNG_BACKUP_DIR.'/mng_db/index.php');
					@unlink($db_result);
					@rmdir(MNG_DB_DIR);

                    if($archive->error_code!=''){
                        $archive->error_code = 'pclZip error ('.$archive->error_code . '): .';
                    }
					return array(
						'error' => 'Failed to zip database. ' . $archive->error_code . $archive->error_string
					);
				}
			}
        }

        @unlink(MNG_BACKUP_DIR.'/mng_db/index.php');
        @unlink($db_result);
        @rmdir(MNG_DB_DIR);

        $remove = array(
        	trim(basename(WP_CONTENT_DIR)) . "/wpmanagerpro/backups",
        	trim(basename(WP_CONTENT_DIR)) . "/" . md5('wpmanagerpro') . "/mng_backups"
        );
        $exclude = array_merge($exclude, $remove);

        $this->update_status($task_id, $this->statuses['db_zip'], true);
        $this->update_status($task_id, $this->statuses['files_zip']);

        $zip_result = $this->zip_backup($task_id, $backup_file, $exclude, $include);

        if (isset($zip_result['error'])) {
        	return $zip_result;
        }

        if (!$zip_result) {
        	$zip_archive_result = false;
        	if (class_exists("ZipArchive")) {
        		$zip_archive_result = $this->zip_archive_backup($task_id, $backup_file, $exclude, $include);
        	}

        	if (!$zip_archive_result) {
        		$pclzip_result = $this->pclzip_backup($task_id, $backup_file, $exclude, $include);
        		if (!$pclzip_result) {
        			@unlink(MNG_BACKUP_DIR.'/mng_db/index.php');
        			@unlink($db_result);
        			@rmdir(MNG_DB_DIR);

        			if (!$pclzip_result) {
        				@unlink($backup_file);
        				return array(
        					'error' => 'Failed to zip files. pclZip error (' . $archive->error_code . '): .' . $archive->error_string
        				);
        			}
        		}
        	}
        }

        // Reconnect
        $this->wpdb_reconnect();
        $this->update_status($task_id, $this->statuses['files_zip'], true);
        return true;
    }

    /**
     * Zipping database dump and index.php in folder mng_db by system zip command, requires zip installed on OS.
     */
    function zip_backup_db($task_id, $backup_file) {
        $tasks = get_option('mng_backup_tasks');
    	$disable_comp = $tasks[$task_id]['task_args']['disable_comp'];
    	$comp_level   = $disable_comp ? '-0' : '-1';
    	$zip = $this->get_zip();
    	//Add database file
    	chdir(MNG_BACKUP_DIR);
    	$command = "$zip -q -r $comp_level $backup_file 'mng_db'";

    	ob_start();
    	$result = $this->mng_exec($command);
    	ob_get_clean();

    	return $result;
    }

    /**
     * Zipping database dump and index.php in folder mng_db by ZipArchive class, requires php zip extension.
     */
    function zip_archive_backup_db($task_id, $db_result, $backup_file) {
        $task = get_option('mng_backup_tasks');
    	$zip = new ZipArchive();
    	$result = $zip->open($backup_file, ZIPARCHIVE::OVERWRITE); // Tries to open $backup_file for acrhiving
    	if ($result === true) {
    		$result = $result && $zip->addFile(MNG_BACKUP_DIR.'/mng_db/index.php', "mng_db/index.php"); // Tries to add mng_db/index.php to $backup_file
    		$result = $result && $zip->addFile($db_result, "mng_db/" . basename($db_result)); // Tries to add db dump form mng_db dir to $backup_file
    		$result = $result && $zip->close(); // Tries to close $backup_file
    	} else {
    		$result = false;
    	}

    	return $result; // true if $backup_file iz zipped successfully, false if error is occured in zip process
    }

    /**
     * Zipping database dump and index.php in folder mng_db by PclZip library.
     */
    function pclzip_backup_db($task_id, $backup_file) {
        $tasks = get_option('mng_backup_tasks');
    	$disable_comp = $tasks[$task_id]['task_args']['disable_comp'];
    	define('PCLZIP_TEMPORARY_DIR', MNG_BACKUP_DIR . '/');
    	require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
    	$zip = new PclZip($backup_file);

    	if ($disable_comp) {
    		$result = $zip->add(MNG_BACKUP_DIR, PCLZIP_OPT_REMOVE_PATH, MNG_BACKUP_DIR, PCLZIP_OPT_NO_COMPRESSION) !== 0;
    	} else {
    		$result = $zip->add(MNG_BACKUP_DIR, PCLZIP_OPT_REMOVE_PATH, MNG_BACKUP_DIR) !== 0;
    	}

    	return $result;
    }

    /**
     * Zipping whole site root folder and append to backup file with database dump
     * by system zip command, requires zip installed on OS.
     *
     * @param 	string 	$task_id		the id of backup task
     * @param 	string 	$backup_file	absolute path to zip file
     * @param	array	$exclude		array of files of folders to exclude, relative to site's root
     * @param	array	$include		array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     * @return 	array|bool				true if successful or an array with error message if not
     */
    function zip_backup($task_id, $backup_file, $exclude, $include) {
    	global $zip_errors;
    	$sys = substr(PHP_OS, 0, 3);

    	//Exclude paths
    	$exclude_data = "-x";

    	$exclude_file_data = '';

    	// TODO: Prevent to $exclude include blank string '', beacuse zip 12 error will be occured.
    	if (!empty($exclude)) {
    		foreach ($exclude as $data) {
    			if (is_dir(ABSPATH . $data)) {
    				if ($sys == 'WIN')
    					$exclude_data .= " $data/*.*";
    				else
    					$exclude_data .= " '$data/*'";
    			} else {
    				if ($sys == 'WIN'){
    					if(file_exists(ABSPATH . $data)){
    						$exclude_data .= " $data";
    						$exclude_file_data .= " $data";
    					}
    				} else {
    					if(file_exists(ABSPATH . $data)){
    						$exclude_data .= " '$data'";
    						$exclude_file_data .= " '$data'";
    					}
    				}
    			}
    		}
    	}

    	if($exclude_file_data){
    		$exclude_file_data = "-x".$exclude_file_data;
    	}

    	//Include paths by default
    	$add = array(
    		trim(WPINC),
    		trim(basename(WP_CONTENT_DIR)),
    		"wp-admin"
    	);

    	$include_data = ". -i";
    	foreach ($add as $data) {
    		if ($sys == 'WIN')
    			$include_data .= " $data/*.*";
    		else
    			$include_data .= " '$data/*'";
    	}

    	//Additional includes?
    	if (!empty($include)) {
    		foreach ($include as $data) {
    			if ($data) {
    				if ($sys == 'WIN')
    					$include_data .= " $data/*.*";
    				else
    					$include_data .= " '$data/*'";
    			}
    		}
    	}

        $task = get_option('mng_backup_tasks');
    	$disable_comp = $tasks[$task_id]['task_args']['disable_comp'];
    	$comp_level   = $disable_comp ? '-0' : '-1';
    	$zip = $this->get_zip();
    	chdir(ABSPATH);
    	ob_start();
    	$command  = "$zip -q -j $comp_level $backup_file .* * $exclude_file_data";
    	$result_f = $this->mng_exec($command, false, true);
    	if (!$result_f || $result_f == 18) { // disregard permissions error, file can't be accessed
    		$command  = "$zip -q -r $comp_level $backup_file $include_data $exclude_data";
    		$result_d = $this->mng_exec($command, false, true);
    		if ($result_d && $result_d != 18) {
    			@unlink($backup_file);
    			if ($result_d > 0 && $result_d < 18)
    				return array(
    					'error' => 'Failed to archive files (' . $zip_errors[$result_d] . ') .'
    				);
    			else {
    				if ($result_d === -1) return false;
    				return array(
    					'error' => 'Failed to archive files.'
    				);
    			}
    		}
    	} else {
    		return false;
    	}

    	ob_get_clean();

    	return true;
    }

    /**
     * Zipping whole site root folder and append to backup file with database dump
     * by ZipArchive class, requires php zip extension.
     */
    function zip_archive_backup($task_id, $backup_file, $exclude, $include, $overwrite = false) {
		$filelist = $this->get_backup_files($exclude, $include);
        $tasks = get_option('mng_backup_tasks');

		$zip = new ZipArchive();
		if ($overwrite) {
			$result = $zip->open($backup_file, ZipArchive::OVERWRITE); // Tries to open $backup_file for acrhiving
		} else {
			$result = $zip->open($backup_file); // Tries to open $backup_file for acrhiving
		}
		if ($result === true) {
			foreach ($filelist as $file) {
				$result = $result && $zip->addFile($file, sprintf("%s", str_replace(ABSPATH, '', $file))); // Tries to add a new file to $backup_file
			}
			$result = $result && $zip->close(); // Tries to close $backup_file
		} else {
			$result = false;
		}

		return $result; // true if $backup_file iz zipped successfully, false if error is occured in zip process
    }

    /**
     * Zipping whole site root folder and append to backup file with database dump
     * by PclZip library.
     */
    function pclzip_backup($task_id, $backup_file, $exclude, $include) {
    	define('PCLZIP_TEMPORARY_DIR', MNG_BACKUP_DIR . '/');
	    require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
	    $zip = new PclZip($backup_file);
    	$add = array(
    		trim(WPINC),
    		trim(basename(WP_CONTENT_DIR)),
    		"wp-admin"
    	);

    	$include_data = array();
    	if (!empty($include)) {
    		foreach ($include as $data) {
    			if ($data && file_exists(ABSPATH . $data))
    				$include_data[] = ABSPATH . $data . '/';
    		}
    	}
    	$include_data = array_merge($add, $include_data);

    	if ($handle = opendir(ABSPATH)) {
    		while (false !== ($file = readdir($handle))) {
    			if ($file != "." && $file != ".." && !is_dir($file) && file_exists(ABSPATH . $file)) {
    				$include_data[] = ABSPATH . $file;
    			}
    		}
    		closedir($handle);
    	}

        $tasks = get_option('mng_backup_tasks');
    	$disable_comp = $tasks[$task_id]['task_args']['disable_comp'];

    	if ($disable_comp) {
    		$result = $zip->add($include_data, PCLZIP_OPT_REMOVE_PATH, ABSPATH, PCLZIP_OPT_NO_COMPRESSION) !== 0;
    	} else {
    		$result = $zip->add($include_data, PCLZIP_OPT_REMOVE_PATH, ABSPATH) !== 0;
    	}

    	$exclude_data = array();
    	if (!empty($exclude)) {
    		foreach ($exclude as $data) {
    			if (file_exists(ABSPATH . $data)) {
	    			if (is_dir(ABSPATH . $data))
	    				$exclude_data[] = $data . '/';
	    			else
	    				$exclude_data[] = $data;
    			}
    		}
    	}
    	$result = $result && $zip->delete(PCLZIP_OPT_BY_NAME, $exclude_data);

    	return $result;
    }

    /**
     * Gets an array of relative paths of all files in site root recursively.
     * By default, there are all files from root folder, all files from folders wp-admin, wp-content, wp-includes recursively.
     * Parameter $include adds other folders from site root, and excludes any file or folder by relative path to site's root.
     */
    function get_backup_files($exclude, $include) {
    	$add = array(
    		trim(WPINC),
    		trim(basename(WP_CONTENT_DIR)),
    		"wp-admin"
    	);

    	$include = array_merge($add, $include);

	    $filelist = array();
	    if ($handle = opendir(ABSPATH)) {
	    	while (false !== ($file = readdir($handle))) {
				if (is_dir($file) && file_exists(ABSPATH . $file) && !(in_array($file, $include))) {
	    			$exclude[] = $file;
	    		}
	    	}
	    	closedir($handle);
	    }

    	$filelist = get_all_files_from_dir(ABSPATH, $exclude);

    	return $filelist;
    }

    /**
     * Backup a database dump of WordPress site.
     * All backups are compressed by zip and placed in wp-content/wpmanagerpro/backups folder.
     *
     * @param	string		$task_id			the id of backup task, which backup is done
     * @param	string		$backup_file		relative path to file which backup is stored
     * @return	bool|array						true if backup is successful, or an array with error message if is failed
     */
    function backup_db_compress($task_id, $backup_file) {
    	$this->update_status($task_id, $this->statuses['db_dump']);
    	$db_result = $this->backup_db();

    	if ($db_result == false) {
    		return array(
    			'error' => 'Failed to backup database.'
    		);
    	} else if (is_array($db_result) && isset($db_result['error'])) {
    		return array(
    			'error' => $db_result['error']
    		);
    	}

    	$this->update_status($task_id, $this->statuses['db_dump'], true);
    	$this->update_status($task_id, $this->statuses['db_zip']);
    	@file_put_contents(MNG_BACKUP_DIR.'/mng_db/index.php', '');
    	$zip_db_result = $this->zip_backup_db($task_id, $backup_file);

    	if (!$zip_db_result) {
    		$zip_archive_db_result = false;
    		if (class_exists("ZipArchive")) {
    			$zip_archive_db_result = $this->zip_archive_backup_db($task_id, $db_result, $backup_file);
    		}

    		if (!$zip_archive_db_result) {
    			$pclzip_db_result = $this->pclzip_backup_db($task_id, $backup_file);
    			if (!$pclzip_db_result) {
    				@unlink(MNG_BACKUP_DIR.'/mng_db/index.php');
    				@unlink($db_result);
    				@rmdir(MNG_DB_DIR);

    				return array(
    					'error' => 'Failed to zip database. pclZip error (' . $archive->error_code . '): .' . $archive->error_string
    				);
    			}
    		}
    	}

    	@unlink(MNG_BACKUP_DIR.'/mng_db/index.php');
    	@unlink($db_result);
    	@rmdir(MNG_DB_DIR);

    	$this->update_status($task_id, $this->statuses['db_zip'], true);

    	return true;
    }

    /**
     * Creates database dump and places it in mng_db folder in site's root.
     * This function dispatches if OS mysql command does not work calls a php alternative.
     *
     * @return	string|array	path to dump file if successful, or an array with error message if is failed
     */
    function backup_db() {
        $db_folder = MNG_DB_DIR . '/';
        if (!file_exists($db_folder)) {
            if (!mkdir($db_folder, 0755, true))
                return array(
                    'error' => 'Error creating database backup folder (' . $db_folder . '). Make sure you have corrrect write permissions.'
                );
        }

        $file   = $db_folder . DB_NAME . '.sql';
        $result = $this->backup_db_dump($file); // try mysqldump always then fallback to php dump
        return $result;
    }

    /**
     * Creates database dump by system mysql command.
     *
     * @param 	string	$file	absolute path to file in which dump should be placed
     * @return	string|array	path to dump file if successful, or an array with error message if is failed
     */
    function backup_db_dump($file) {
        global $wpdb;
        $paths   = $this->check_mysql_paths();
        $brace   = (substr(PHP_OS, 0, 3) == 'WIN') ? '"' : '';
        $command = $brace . $paths['mysqldump'] . $brace . ' --force --host="' . DB_HOST . '" --user="' . DB_USER . '" --password="' . DB_PASSWORD . '" --add-drop-table --skip-lock-tables "' . DB_NAME . '" > ' . $brace . $file . $brace;
        ob_start();
        $result = $this->mng_exec($command);
        ob_get_clean();

        if (!$result) { // Fallback to php
            $result = $this->backup_db_php($file);
            return $result;
        }

        if (filesize($file) == 0 || !is_file($file) || !$result) {
            @unlink($file);
            return false;
        } else {
            return $file;
        }
    }

    /**
     * Creates database dump by php functions.
     *
     * @param 	string	$file	absolute path to file in which dump should be placed
     * @return	string|array	path to dump file if successful, or an array with error message if is failed
     */
	function backup_db_php($file) {
        global $wpdb;
        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        foreach ($tables as $table) {
            //drop existing table
            $dump_data    = "DROP TABLE IF EXISTS $table[0];";
            file_put_contents($file, $dump_data, FILE_APPEND);
            //create table
            $create_table = $wpdb->get_row("SHOW CREATE TABLE $table[0]", ARRAY_N);
            $dump_data = "\n\n" . $create_table[1] . ";\n\n";
            file_put_contents($file, $dump_data, FILE_APPEND);

            $count = $wpdb->get_var("SELECT count(*) FROM $table[0]");
            if ($count > 100)
                $count = ceil($count / 100);
            else if ($count > 0)
                $count = 1;

            for ($i = 0; $i < $count; $i++) {
                $low_limit = $i * 100;
                $qry       = "SELECT * FROM $table[0] LIMIT $low_limit, 100";
                $rows      = $wpdb->get_results($qry, ARRAY_A);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        //insert single row
                        $dump_data = "INSERT INTO $table[0] VALUES(";
                        $num_values = count($row);
                        $j          = 1;
                        foreach ($row as $value) {
                            $value = addslashes($value);
                            $value = preg_replace("/\n/Ui", "\\n", $value);
                            $num_values == $j ? $dump_data .= "'" . $value . "'" : $dump_data .= "'" . $value . "', ";
                            $j++;
                            unset($value);
                        }
                        $dump_data .= ");\n";
                        file_put_contents($file, $dump_data, FILE_APPEND);
                    }
                }
            }
            $dump_data = "\n\n\n";
            file_put_contents($file, $dump_data, FILE_APPEND);

            unset($rows);
            unset($dump_data);
        }

        if (filesize($file) == 0 || !is_file($file)) {
            @unlink($file);
            return array(
                'error' => 'Database backup failed. Try to enable MySQL dump on your server.'
            );
        }

        return $file;
    }

    /**
     * Restores full WordPress site or database only form backup zip file.
     *
     * @param	array		array of arguments passed to backup restore
     * [task_id] -> id of backup task
     * [result_id] -> id of baskup task result, which should be restored
     * [google_drive_token] -> json of Google Drive token, if it is remote destination
     * @return	bool|array	true if successful, or an array with error message if is failed
     */
    function restore($args) {
        global $wpdb;
        if (empty($args)) {
            return false;
        }

        $tasks = get_option('mng_backup_tasks');
        extract($args);
        if (isset($google_drive_token)) {
        	$tasks[$task_id]['task_args']['account_info']['google_drive']['google_drive_token'] = $google_drive_token;
        }
        $this->set_memory();

        $unlink_file = true; //Delete file after restore

        // Detect source
        if ($backup_url) {
            //This is for clone (overwrite)
            include_once ABSPATH . 'wp-admin/includes/file.php';
            $backup_file = download_url($backup_url);
            if (is_wp_error($backup_file)) {
                return array(
                    'error' => 'Unable to download backup file ('.$backup_file->get_error_message().')'
                );
            }
            $what = 'full';
        } else {
            $tasks = get_option('mng_backup_tasks');
            $task  = $tasks[$task_id];
            if (isset($task['task_results'][$result_id]['server'])) {
                $backup_file = $task['task_results'][$result_id]['server']['file_path'];
                $unlink_file = false; //Don't delete file if stored on server
            } elseif (isset($task['task_results'][$result_id]['ftp'])) {
                $ftp_file            = $task['task_results'][$result_id]['ftp'];
                $args                = $task['task_args']['account_info']['ftp'];
                $args['backup_file'] = $ftp_file;
                $backup_file         = $this->get_ftp_backup($args);

                if ($backup_file == false) {
                    return array(
                        'error' => 'Failed to download file from FTP.'
                    );
                }
            } elseif(isset($task['task_results'][$result_id]['dropbox'])){
            	$dropbox_file        = $task['task_results'][$result_id]['dropbox'];
                $args                = $task['task_args']['account_info']['dropbox'];
                $args['backup_file'] = $dropbox_file;
                $backup_file         = $this->get_dropbox_backup($args);

                if ($backup_file == false) {
                    return array(
                        'error' => 'Failed to download file from Dropbox.'
                    );
                }
            } elseif (isset($task['task_results'][$result_id]['google_drive'])) {
                $google_drive_file   = $task['task_results'][$result_id]['google_drive'];
                $args                = $task['task_args']['account_info']['google_drive'];
                $args['backup_file'] = $google_drive_file;
                $backup_file         = $this->get_google_drive_backup($args);

                if (is_array($backup_file) && isset($backup_file['error'])) {
                	return array(
                		'error' => 'Failed to download file from Google Drive, reason: ' . $backup_file['error']
                	);
                } elseif ($backup_file == false) {
                    return array(
                        'error' => 'Failed to download file from Google Drive.'
                    );
                }
            }

            $what = $tasks[$task_id]['task_args']['what'];
        }

        $this->wpdb_reconnect();

        if ($backup_file && file_exists($backup_file)) {
            if ($overwrite) {
                //Keep old db credentials before overwrite
                if (!copy(ABSPATH . 'wp-config.php', ABSPATH . 'mwp-temp-wp-config.php')) {
                    @unlink($backup_file);
                    return array(
                        'error' => 'Error creating wp-config. Please check your write permissions.'
                    );
                }

                $db_host     = DB_HOST;
                $db_user     = DB_USER;
                $db_password = DB_PASSWORD;
                $home        = rtrim(get_option('home'), "/");
                $site_url    = get_option('site_url');

                $clone_options                       = array();
                if (trim($clone_from_url) || trim($mng_clone)) {
                    $clone_options['_wpmanagerpro_public_key'] = get_option('_wpmanagerpro_public_key');
                    $clone_options['_wpmanagerpro_message_id'] = get_option('_wpmanagerpro_message_id');
                }
                $clone_options['upload_path'] = get_option('upload_path');
                $clone_options['upload_url_path'] = get_option('upload_url_path');

                $clone_options['mng_backup_tasks'] = serialize(get_option('mng_backup_tasks'));
                $clone_options['mng_notifications'] = serialize(get_option('mng_notifications'));
                $clone_options['mng_pageview_alerts'] = serialize(get_option('mng_pageview_alerts'));
            } else {
            	$restore_options                       = array();
            	$restore_options['mng_notifications'] = get_option('mng_notifications');
            	$restore_options['mng_pageview_alerts'] = get_option('mng_pageview_alerts');
            	$restore_options['user_hit_count'] = get_option('user_hit_count');
            }

            chdir(ABSPATH);
            $unzip   = $this->get_unzip();
            $command = "$unzip -o $backup_file";
            ob_start();
            $result = $this->mng_exec($command);
            ob_get_clean();

            if (!$result) { //fallback to pclzip
                define('PCLZIP_TEMPORARY_DIR', MNG_BACKUP_DIR . '/');
                require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
                $archive = new PclZip($backup_file);
                $result  = $archive->extract(PCLZIP_OPT_PATH, ABSPATH, PCLZIP_OPT_REPLACE_NEWER);
            }

            if ($unlink_file) {
                @unlink($backup_file);
            }

            if (!$result) {
                return array(
                    'error' => 'Failed to unzip files. pclZip error (' . $archive->error_code . '): .' . $archive->error_string
                );
            }

            $db_result = $this->restore_db();

            if (!$db_result) {
                return array(
                    'error' => 'Error restoring database.'
                );
            } else if(is_array($db_result) && isset($db_result['error'])){
            		return array(
                    'error' => $db_result['error']
                );
            }

        } else {
            return array(
                'error' => 'Error restoring. Cannot find backup file.'
            );
        }

        $this->wpdb_reconnect();

        //Replace options and content urls
        if ($overwrite) {
            //Get New Table prefix
            $new_table_prefix = trim($this->get_table_prefix());
            //Retrieve old wp_config
            @unlink(ABSPATH . 'wp-config.php');
            //Replace table prefix
            $lines = file(ABSPATH . 'mwp-temp-wp-config.php');

            foreach ($lines as $line) {
                if (strstr($line, '$table_prefix')) {
                    $line = '$table_prefix = "' . $new_table_prefix . '";' . PHP_EOL;
                }
                file_put_contents(ABSPATH . 'wp-config.php', $line, FILE_APPEND);
            }

            @unlink(ABSPATH . 'mwp-temp-wp-config.php');

            //Replace options
            $query = "SELECT option_value FROM " . $new_table_prefix . "options WHERE option_name = 'home'";
            $old   = $wpdb->get_var($query);
            $old   = rtrim($old, "/");
            $query = "UPDATE " . $new_table_prefix . "options SET option_value = %s WHERE option_name = 'home'";
            $wpdb->query($wpdb->prepare($query, $home));
            $query = "UPDATE " . $new_table_prefix . "options  SET option_value = %s WHERE option_name = 'siteurl'";
            $wpdb->query($wpdb->prepare($query, $home));
            //Replace content urls
            $regexp1 = 'src="(.*)$old(.*)"';
            $regexp2 = 'href="(.*)$old(.*)"';
            $query = "UPDATE " . $new_table_prefix . "posts SET post_content = REPLACE (post_content, %s,%s) WHERE post_content REGEXP %s OR post_content REGEXP %s";
            $wpdb->query($wpdb->prepare($query, array($old, $home, $regexp1, $regexp2)));

            if (trim($new_password)) {
                $new_password = wp_hash_password($new_password);
            }
            if (!trim($clone_from_url) && !trim($mng_clone)) {
                if ($new_user && $new_password) {
                    $query = "UPDATE " . $new_table_prefix . "users SET user_login = %s, user_pass = %s WHERE user_login = %s";
                    $wpdb->query($wpdb->prepare($query, $new_user, $new_password, $old_user));
                }
            } else {
                if ($clone_from_url) {
                    if ($new_user && $new_password) {
                        $query = "UPDATE " . $new_table_prefix . "users SET user_pass = %s WHERE user_login = %s";
                        $wpdb->query($wpdb->prepare($query, $new_password, $new_user));
                    }
                }

                if ($mng_clone) {
                    if ($admin_email) {
                        //Clean Install
                        $query = "UPDATE " . $new_table_prefix . "options SET option_value = %s WHERE option_name = 'admin_email'";
                        $wpdb->query($wpdb->prepare($query, $admin_email));
                        $query     = "SELECT * FROM " . $new_table_prefix . "users LIMIT 1";
                        $temp_user = $wpdb->get_row($query);
                        if (!empty($temp_user)) {
                            $query = "UPDATE " . $new_table_prefix . "users SET user_email=%s, user_login = %s, user_pass = %s WHERE user_login = %s";
                            $wpdb->query($wpdb->prepare($query, $admin_email, $new_user, $new_password, $temp_user->user_login));
                        }

                    }
                }
            }

            if (is_array($clone_options) && !empty($clone_options)) {
                foreach ($clone_options as $key => $option) {
                    if (!empty($key)) {
                        $query = "SELECT option_value FROM " . $new_table_prefix . "options WHERE option_name = %s";
                        $res   = $wpdb->get_var($wpdb->prepare($query, $key));
                        if ($res == false) {
                            $query = "INSERT INTO " . $new_table_prefix . "options  (option_value,option_name) VALUES(%s,%s)";
                            $wpdb->query($wpdb->prepare($query, $option, $key));
                        } else {
                            $query = "UPDATE " . $new_table_prefix . "options  SET option_value = %s WHERE option_name = %s";
                            $wpdb->query($wpdb->prepare($query, $option, $key));
                        }
                    }
                }
            }

            //Remove hit count
            $query = "DELETE FROM " . $new_table_prefix . "options WHERE option_name = 'user_hit_count'";
           	$wpdb->query($query);

            //Check for .htaccess permalinks update
            $this->replace_htaccess($home);
        } else {
        	//restore options
            if (is_array($restore_options) && !empty($restore_options)) {
                foreach ($restore_options as $key => $option) {
                	update_option($key, $option);
                }
            }
        }

        return true;
    }

    /**
     * This function dispathces database restoring between mysql system command and php functions.
     * If system command fails, it calls the php alternative.
     *
     * @return	bool|array	true if successful, array with error message if not
     */
    function restore_db() {
        global $wpdb;
        $paths     = $this->check_mysql_paths();
        $file_path = ABSPATH . 'mng_db';
        @chmod($file_path,0755);
        $file_name = glob($file_path . '/*.sql');
        $file_name = $file_name[0];

        if(!$file_name){
        	return array('error' => 'Cannot access database file.');
        }

        $brace     = (substr(PHP_OS, 0, 3) == 'WIN') ? '"' : '';
        $command   = $brace . $paths['mysql'] . $brace . ' --host="' . DB_HOST . '" --user="' . DB_USER . '" --password="' . DB_PASSWORD . '" --default-character-set="utf8" ' . DB_NAME . ' < ' . $brace . $file_name . $brace;

        ob_start();
        $result = $this->mng_exec($command);
        ob_get_clean();
        if (!$result) {
            //try php
            $this->restore_db_php($file_name);
        }

        @unlink($file_name);
        return true;
    }

    /**
     * Restores database dump by php functions.
     *
     * @param 	string	$file_name	relative path to database dump, which should be restored
     * @return	bool				is successful or not
     */
    function restore_db_php($file_name) {
        global $wpdb;
        $current_query = '';
        // Read in entire file
        $lines         = file($file_name);
        // Loop through each line
        foreach ($lines as $line) {
            // Skip it if it's a comment
            if (substr($line, 0, 2) == '--' || $line == '')
                continue;

            // Add this line to the current query
            $current_query .= $line;
            // If it has a semicolon at the end, it's the end of the query
            if (substr(trim($line), -1, 1) == ';') {
                // Perform the query
                $result = $wpdb->query($current_query);
                if ($result === false)
                    return false;
                // Reset temp variable to empty
                $current_query = '';
            }
        }

        @unlink($file_name);
        return true;
    }

    /**
     * Retruns table_prefix for this WordPress installation.
     * It is used by restore.
     *
     * @return 	string	table prefix from wp-config.php file, (default: wp_)
     */
    function get_table_prefix() {
        $lines = file(ABSPATH . 'wp-config.php');
        foreach ($lines as $line) {
            if (strstr($line, '$table_prefix')) {
                $pattern = "/(\'|\")[^(\'|\")]*/";
                preg_match($pattern, $line, $matches);
                $prefix = substr($matches[0], 1);
                return $prefix;
                break;
            }
        }
        return 'wp_'; //default
    }

    /**
     * Change all tables to InnoDB engine, and executes mysql OPTIMIZE TABLE for each table.
     *
     * @return 	bool	optimized successfully or not
     */
    function optimize_tables() {
        global $wpdb;
        $query  = 'SHOW TABLES';
        $tables = $wpdb->get_results($query, ARRAY_A);
        foreach ($tables as $table) {
            if (in_array($table['Engine'], array(
                'MyISAM',
                'ISAM',
                'HEAP',
                'MEMORY',
                'ARCHIVE'
            )))
                $table_string .= $table['Name'] . ",";
            elseif ($table['Engine'] == 'InnoDB') {
                $optimize = $wpdb->query("ALTER TABLE {$table['Name']} ENGINE=InnoDB");
            }
        }

        $table_string = rtrim($table_string);
        $optimize     = $wpdb->query("OPTIMIZE TABLE $table_string");

        return $optimize ? true : false;
    }

    /**
     * Returns mysql and mysql dump command path on OS.
     *
     * @return 	array	array with system mysql and mysqldump command, blank if does not exist
     */
    function check_mysql_paths() {
        global $wpdb;
        $paths = array(
            'mysql' => '',
            'mysqldump' => ''
        );
        if (substr(PHP_OS, 0, 3) == 'WIN') {
            $mysql_install = $wpdb->get_row("SHOW VARIABLES LIKE 'basedir'");
            if ($mysql_install) {
                $install_path       = str_replace('\\', '/', $mysql_install->Value);
                $paths['mysql']     = $install_path . 'bin/mysql.exe';
                $paths['mysqldump'] = $install_path . 'bin/mysqldump.exe';
            } else {
                $paths['mysql']     = 'mysql.exe';
                $paths['mysqldump'] = 'mysqldump.exe';
            }
        } else {
            $paths['mysql'] = $this->mng_exec('which mysql', true);
            if (empty($paths['mysql']))
                $paths['mysql'] = 'mysql'; // try anyway

            $paths['mysqldump'] = $this->mng_exec('which mysqldump', true);
            if (empty($paths['mysqldump']))
                $paths['mysqldump'] = 'mysqldump'; // try anyway
        }

        return $paths;
    }

    /**
     * Check if exec, system, passthru functions exist
     *
     * @return 	string|bool	exec if exists, then system, then passthru, then false if no one exist
     */
    function check_sys() {
        if ($this->mng_core->mng_function_exists('exec'))
            return 'exec';

        if ($this->mng_core->mng_function_exists('system'))
            return 'system';

        if ($this->mng_core->mng_function_exists('passhtru'))
            return 'passthru';

        return false;
    }

    /**
     * Executes an external system command.
     *
     * @param 	string 			$command	external command to execute
     * @param 	bool[optional] 	$string		return as a system output string (default: false)
     * @param 	bool[optional] 	$rawreturn	return as a status of executed command
     * @return 	bool|int|string				output depends on parameters $string and $rawreturn, -1 if no one execute function is enabled
     */
    function mng_exec($command, $string = false, $rawreturn = false) {
        if ($command == '')
            return false;

        if ($this->mng_core->mng_function_exists('exec')) {
            $log = @exec($command, $output, $return);
            if ($string)
                return $log;
            if ($rawreturn)
                return $return;

            return $return ? false : true;
        } elseif ($this->mng_core->mng_function_exists('system')) {
            $log = @system($command, $return);
            if ($string)
                return $log;

            if ($rawreturn)
                return $return;

            return $return ? false : true;
        } elseif ($this->mng_core->mng_function_exists('passthru') && !$string) {
            $log = passthru($command, $return);
            if ($rawreturn)
                return $return;

            return $return ? false : true;
        }

        if ($rawreturn)
        	return -1;

        return false;
    }

    /**
     * Returns a path to system command for zip execution.
     *
     * @return	string	command for zip execution
     */
    function get_zip() {
        $zip = $this->mng_exec('which zip', true);
        if (!$zip)
            $zip = "zip";
        return $zip;
    }

    /**
     * Returns a path to system command for unzip execution.
     *
     * @return	string	command for unzip execution
     */
    function get_unzip() {
        $unzip = $this->mng_exec('which unzip', true);
        if (!$unzip)
            $unzip = "unzip";
        return $unzip;
    }

    /**
     * Returns all important information of system status.
     *
     * @return	mixed	associative array with information of server OS, php version, is backup folder writable, execute function, zip and unzip command, execution time, memory limit and path to error log if exists
     */
    function check_backup_compat() {
    	$reqs = array();
        if (strpos($_SERVER['DOCUMENT_ROOT'], '/') === 0) {
            $reqs['Server OS']['status'] = 'Linux (or compatible)';
            $reqs['Server OS']['pass']   = true;
        } else {
            $reqs['Server OS']['status'] = 'Windows';
            $reqs['Server OS']['pass']   = true;
            $pass                        = false;
        }
        $reqs['PHP Version']['status'] = phpversion();
        if ((float) phpversion() >= 5.1) {
            $reqs['PHP Version']['pass'] = true;
        } else {
            $reqs['PHP Version']['pass'] = false;
            $pass                        = false;
        }

        if (is_writable(WP_CONTENT_DIR)) {
            $reqs['Backup Folder']['status'] = "writable";
            $reqs['Backup Folder']['pass']   = true;
        } else {
            $reqs['Backup Folder']['status'] = "not writable";
            $reqs['Backup Folder']['pass']   = false;
        }

        $file_path = MNG_BACKUP_DIR;
        $reqs['Backup Folder']['status'] .= ' (' . $file_path . ')';

        if ($func = $this->check_sys()) {
            $reqs['Execute Function']['status'] = $func;
            $reqs['Execute Function']['pass']   = true;
        } else {
            $reqs['Execute Function']['status'] = "not found";
            $reqs['Execute Function']['info']   = "(will try PHP replacement)";
            $reqs['Execute Function']['pass']   = false;
        }

        $reqs['Zip']['status'] = $this->get_zip();
        $reqs['Zip']['pass'] = true;
        $reqs['Unzip']['status'] = $this->get_unzip();
        $reqs['Unzip']['pass'] = true;

        $paths = $this->check_mysql_paths();

        if (!empty($paths['mysqldump'])) {
            $reqs['MySQL Dump']['status'] = $paths['mysqldump'];
            $reqs['MySQL Dump']['pass']   = true;
        } else {
            $reqs['MySQL Dump']['status'] = "not found";
            $reqs['MySQL Dump']['info']   = "(will try PHP replacement)";
            $reqs['MySQL Dump']['pass']   = false;
        }

        $exec_time                        = ini_get('max_execution_time');
        $reqs['Execution time']['status'] = $exec_time ? $exec_time . "s" : 'unknown';
        $reqs['Execution time']['pass']   = true;

        $mem_limit                      = ini_get('memory_limit');
        $reqs['Memory limit']['status'] = $mem_limit ? $mem_limit : 'unknown';
        $reqs['Memory limit']['pass']   = true;

        $changed = $this->set_memory();
        if($changed['execution_time']){
        	$exec_time                        = ini_get('max_execution_time');
        	$reqs['Execution time']['status'] .= $exec_time ? ' (will try '.$exec_time . 's)' : ' (unknown)';
        }
        if($changed['memory_limit']){
        	$mem_limit                      = ini_get('memory_limit');
        	$reqs['Memory limit']['status'] .= $mem_limit ? ' (will try '.$mem_limit.')' : ' (unknown)';
        }

        return $reqs;
    }

    /**
     * Uploads backup file from server to remote ftp server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [ftp_username] -> ftp username on remote server
     * [ftp_password] -> ftp password on remote server
     * [ftp_hostname] -> ftp hostname of remote host
     * [ftp_remote_folder] -> folder on remote site which backup file should be upload to
     * [ftp_site_folder] -> subfolder with site name in ftp_remote_folder which backup file should be upload to
     * [ftp_passive] -> passive mode or not
     * [ftp_ssl] -> ssl or not
     * [ftp_port] -> number of port for ssl protocol
     * [backup_file] -> absolute path of backup file on local server
     * @return 	bool|array		true is successful, array with error message if not
     */
    function ftp_backup($args) {
        extract($args);

        $port = $ftp_port ? $ftp_port : 21; //default port is 21
        if ($ftp_ssl) {
            if (function_exists('ftp_ssl_connect')) {
                $conn_id = ftp_ssl_connect($ftp_hostname,$port);
                if ($conn_id === false) {
                	return array(
                			'error' => 'Failed to connect to ' . $ftp_hostname,
                			'partial' => 1
                	);
                }
            } else {
                return array(
                    'error' => 'FTPS disabled: Please enable ftp_ssl_connect in PHP',
                    'partial' => 1
                );
            }
        } else {
            if (function_exists('ftp_connect')) {
                $conn_id = ftp_connect($ftp_hostname,$port);
                if ($conn_id === false) {
                    return array(
                        'error' => 'Failed to connect to ' . $ftp_hostname,
                        'partial' => 1
                    );
                }
            } else {
                return array(
                    'error' => 'FTP disabled: Please enable ftp_connect in PHP',
                    'partial' => 1
                );
            }
        }
        $login = @ftp_login($conn_id, $ftp_username, $ftp_password);
        if ($login === false) {
            return array(
                'error' => 'FTP login failed for ' . $ftp_username . ', ' . $ftp_password,
                'partial' => 1
            );
        }

        if($ftp_passive){
			@ftp_pasv($conn_id,true);
		}

        @ftp_mkdir($conn_id, $ftp_remote_folder);
        if ($ftp_site_folder) {
            $ftp_remote_folder .= '/' . $this->site_name;
        }
        @ftp_mkdir($conn_id, $ftp_remote_folder);

        $upload = @ftp_put($conn_id, $ftp_remote_folder . '/' . basename($backup_file), $backup_file, FTP_BINARY);

        if ($upload === false) { //Try ascii
            $upload = @ftp_put($conn_id, $ftp_remote_folder . '/' . basename($backup_file), $backup_file, FTP_ASCII);
        }
        @ftp_close($conn_id);

        if ($upload === false) {
            return array(
                'error' => 'Failed to upload file to FTP. Please check your specified path.',
                'partial' => 1
            );
        }

        return true;
    }

    /**
     * Deletes backup file from remote ftp server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [ftp_username] -> ftp username on remote server
     * [ftp_password] -> ftp password on remote server
     * [ftp_hostname] -> ftp hostname of remote host
     * [ftp_remote_folder] -> folder on remote site which backup file should be deleted from
     * [ftp_site_folder] -> subfolder with site name in ftp_remote_folder which backup file should be deleted from
     * [backup_file] -> absolute path of backup file on local server
     * @return 	void
     */
    function remove_ftp_backup($args) {
        extract($args);

        $port = $ftp_port ? $ftp_port : 21; //default port is 21
        if ($ftp_ssl && function_exists('ftp_ssl_connect')) {
            $conn_id = ftp_ssl_connect($ftp_hostname,$port);
        } else if (function_exists('ftp_connect')) {
            $conn_id = ftp_connect($ftp_hostname,$port);
        }

        if ($conn_id) {
            $login = @ftp_login($conn_id, $ftp_username, $ftp_password);
            if ($ftp_site_folder)
                $ftp_remote_folder .= '/' . $this->site_name;

            if($ftp_passive){
				@ftp_pasv($conn_id,true);
			}

            $delete = ftp_delete($conn_id, $ftp_remote_folder . '/' . $backup_file);

            ftp_close($conn_id);
        }
    }

    /**
     * Downloads backup file from server from remote ftp server to root folder on local server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [ftp_username] -> ftp username on remote server
     * [ftp_password] -> ftp password on remote server
     * [ftp_hostname] -> ftp hostname of remote host
     * [ftp_remote_folder] -> folder on remote site which backup file should be downloaded from
     * [ftp_site_folder] -> subfolder with site name in ftp_remote_folder which backup file should be downloaded from
     * [backup_file] -> absolute path of backup file on local server
     * @return 	string|array	absolute path to downloaded file is successful, array with error message if not
     */
    function get_ftp_backup($args) {
        extract($args);

        $port = $ftp_port ? $ftp_port : 21; //default port is 21
        if ($ftp_ssl && function_exists('ftp_ssl_connect')) {
            $conn_id = ftp_ssl_connect($ftp_hostname,$port);

        } else if (function_exists('ftp_connect')) {
            $conn_id = ftp_connect($ftp_hostname,$port);
            if ($conn_id === false) {
                return false;
            }
        }
        $login = @ftp_login($conn_id, $ftp_username, $ftp_password);
        if ($login === false) {
            return false;
        }

        if ($ftp_site_folder)
            $ftp_remote_folder .= '/' . $this->site_name;

        if($ftp_passive){
			@ftp_pasv($conn_id,true);
		}

        $temp = ABSPATH . 'mng_temp_backup.zip';
        $get  = ftp_get($conn_id, $temp, $ftp_remote_folder . '/' . $backup_file, FTP_BINARY);
        if ($get === false) {
            return false;
        }

        ftp_close($conn_id);

        return $temp;
    }

    /**
     * Uploads backup file from server to Dropbox.
     *
     * @param 	array 	$args	arguments passed to the function
     * [consumer_key] -> consumer key of wpmanagerpro Dropbox application
     * [consumer_secret] -> consumer secret of wpmanagerpro Dropbox application
     * [oauth_token] -> oauth token of user on wpmanagerpro Dropbox application
     * [oauth_token_secret] -> oauth token secret of user on wpmanagerpro Dropbox application
     * [dropbox_destination] -> folder on user's Dropbox account which backup file should be upload to
     * [dropbox_site_folder] -> subfolder with site name in dropbox_destination which backup file should be upload to
     * [backup_file] -> absolute path of backup file on local server
     * @return 	bool|array		true is successful, array with error message if not
     */
    function dropbox_backup($args) {
        extract($args);

        global $mng_plugin_dir;
        require_once $mng_plugin_dir . '/lib/dropbox.php';

        $dropbox = new Dropbox($consumer_key, $consumer_secret);
        $dropbox->setOAuthTokens($oauth_token, $oauth_token_secret);

        if ($dropbox_site_folder == true)
        	$dropbox_destination .= '/' . $this->site_name . '/' . basename($backup_file);
        else
        	$dropbox_destination .= '/' . basename($backup_file);

        try {
        	$dropbox->upload($backup_file, $dropbox_destination, true);
        } catch (Exception $e) {
        	return array(
        		'error' => $e->getMessage(),
        		'partial' => 1
        	);
        }

        return true;
    }

    /**
     * Deletes backup file from Dropbox to root folder on local server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [consumer_key] -> consumer key of wpmanagerpro Dropbox application
     * [consumer_secret] -> consumer secret of wpmanagerpro Dropbox application
     * [oauth_token] -> oauth token of user on wpmanagerpro Dropbox application
     * [oauth_token_secret] -> oauth token secret of user on wpmanagerpro Dropbox application
     * [dropbox_destination] -> folder on user's Dropbox account which backup file should be downloaded from
     * [dropbox_site_folder] -> subfolder with site name in dropbox_destination which backup file should be downloaded from
     * [backup_file] -> absolute path of backup file on local server
     * @return 	void
     */
    function remove_dropbox_backup($args) {
    	extract($args);

        global $mng_plugin_dir;
        require_once $mng_plugin_dir . '/lib/dropbox.php';

        $dropbox = new Dropbox($consumer_key, $consumer_secret);
        $dropbox->setOAuthTokens($oauth_token, $oauth_token_secret);

        if ($dropbox_site_folder == true)
        	$dropbox_destination .= '/' . $this->site_name;

    	try {
    		$dropbox->fileopsDelete($dropbox_destination . '/' . $backup_file);
    	} catch (Exception $e) {
    		/*return array(
    			'error' => $e->getMessage(),
    			'partial' => 1
    		);*/
    	}

    	//return true;
	}

	/**
	 * Downloads backup file from Dropbox to root folder on local server.
	 *
	 * @param 	array 	$args	arguments passed to the function
	 * [consumer_key] -> consumer key of wpmanagerpro Dropbox application
	 * [consumer_secret] -> consumer secret of wpmanagerpro Dropbox application
	 * [oauth_token] -> oauth token of user on wpmanagerpro Dropbox application
	 * [oauth_token_secret] -> oauth token secret of user on wpmanagerpro Dropbox application
	 * [dropbox_destination] -> folder on user's Dropbox account which backup file should be deleted from
	 * [dropbox_site_folder] -> subfolder with site name in dropbox_destination which backup file should be deleted from
	 * [backup_file] -> absolute path of backup file on local server
	 * @return 	bool|array		absolute path to downloaded file is successful, array with error message if not
	 */
	function get_dropbox_backup($args) {
  		extract($args);

  		global $mng_plugin_dir;
  		require_once $mng_plugin_dir . '/lib/dropbox.php';

  		$dropbox = new Dropbox($consumer_key, $consumer_secret);
        $dropbox->setOAuthTokens($oauth_token, $oauth_token_secret);

        if ($dropbox_site_folder == true)
        	$dropbox_destination .= '/' . $this->site_name;

  		$temp = ABSPATH . 'mng_temp_backup.zip';

  		try {
  			$file = $dropbox->download($dropbox_destination.'/'.$backup_file);
  			$handle = @fopen($temp, 'w');
			$result = fwrite($handle,$file);
			fclose($handle);

			if($result)
				return $temp;
			else
				return false;
  		} catch (Exception $e) {
  			return array(
  				'error' => $e->getMessage(),
  				'partial' => 1
  			);
  		}
	}

    /**
     * Uploads backup file from server to Google Drive.
     *
     * @param 	array 	$args	arguments passed to the function
     * [google_drive_token] -> user's Google drive token in json form
     * [google_drive_directory] -> folder on user's Google Drive account which backup file should be upload to
     * [google_drive_site_folder] -> subfolder with site name in google_drive_directory which backup file should be upload to
     * [backup_file] -> absolute path of backup file on local server
     * @return 	bool|array		true is successful, array with error message if not
     */
    function google_drive_backup($args) {
    	extract($args);

    	global $mng_plugin_dir;
    	require_once("$mng_plugin_dir/lib/google-api-client/Google_Client.php");
    	require_once("$mng_plugin_dir/lib/google-api-client/contrib/Google_DriveService.php");

    	$gdrive_client = new Google_Client();
	    $gdrive_client->setUseObjects(true);
	    $gdrive_client->setAccessToken($google_drive_token);

    	$gdrive_service = new Google_DriveService($gdrive_client);

    	try {
	    	$about = $gdrive_service->about->get();
	    	$root_folder_id = $about->getRootFolderId();
    	} catch (Exception $e) {
    		return array(
    			'error' => $e->getMessage(),
    		);
    	}

    	try {
	    	$list_files = $gdrive_service->files->listFiles(array("q"=>"title='$google_drive_directory' and '$root_folder_id' in parents and trashed = false"));
	    	$files = $list_files->getItems();
    	} catch (Exception $e) {
    		return array(
    			'error' => $e->getMessage(),
    		);
    	}
    	if (isset($files[0])) {
    		$wpmanagerpro_folder = $files[0];
    	}

    	if (!isset($wpmanagerpro_folder)) {
    		try {
	    		$_wpmanagerpro_folder = new Google_DriveFile();
	    		$_wpmanagerpro_folder->setTitle($google_drive_directory);
	    		$_wpmanagerpro_folder->setMimeType('application/vnd.google-apps.folder');

	    		if ($root_folder_id != null) {
	    			$parent = new Google_ParentReference();
	    			$parent->setId($root_folder_id);
	    			$_wpmanagerpro_folder->setParents(array($parent));
	    		}

    			$wpmanagerpro_folder = $gdrive_service->files->insert($_wpmanagerpro_folder, array());
    		} catch (Exception $e) {
    			return array(
	    			'error' => $e->getMessage(),
	    		);
    		}
    	}

    	if ($google_drive_site_folder) {
    		try {
	    		$subfolder_title = $this->site_name;
	    		$wpmanagerpro_folder_id = $wpmanagerpro_folder->getId();
	    		$list_files = $gdrive_service->files->listFiles(array("q"=>"title='$subfolder_title' and '$wpmanagerpro_folder_id' in parents and trashed = false"));
	    		$files = $list_files->getItems();
    		} catch (Exception $e) {
    			return array(
    				'error' => $e->getMessage(),
    			);
    		}
    		if (isset($files[0])) {
    			$backup_folder = $files[0];
    		} else {
    			try {
	    			$_backup_folder = new Google_DriveFile();
	    			$_backup_folder->setTitle($subfolder_title);
	    			$_backup_folder->setMimeType('application/vnd.google-apps.folder');

	    			if (isset($wpmanagerpro_folder)) {
	    				$_backup_folder->setParents(array($wpmanagerpro_folder));
	    			}

    				$backup_folder = $gdrive_service->files->insert($_backup_folder, array());
    			} catch (Exception $e) {
    				return array(
		    			'error' => $e->getMessage(),
		    		);
    			}
    		}
    	} else {
    		$backup_folder = $wpmanagerpro_folder;
    	}

    	$file_path = explode('/', $backup_file);
    	$new_file = new Google_DriveFile();
    	$new_file->setTitle(end($file_path));
    	$new_file->setDescription('Backup file of site: ' . $this->site_name . '.');

    	if ($backup_folder != null) {
    		$new_file->setParents(array($backup_folder));
    	}

    	$tries = 1;

    	while($tries <= 2) {
	    	try {
	    		$data = file_get_contents($backup_file);

	    		$createdFile = $gdrive_service->files->insert($new_file, array(
	    			'data' => $data,
	    		));

	    		break;
	    	} catch (Exception $e) {
	    		if ($e->getCode() >= 500 && $e->getCode() <= 504 && $mng_gdrive_upload_tries <= 2) {
	    			sleep(2);
	    			$tries++;
	    		} else {
	    			return array(
	    				'error' => $e->getMessage(),
	    			);
	    		}
	    	}
    	}

    	return true;
    }

    /**
     * Deletes backup file from Google Drive.
     *
     * @param 	array 	$args	arguments passed to the function
     * [google_drive_token] -> user's Google drive token in json form
     * [google_drive_directory] -> folder on user's Google Drive account which backup file should be deleted from
     * [google_drive_site_folder] -> subfolder with site name in google_drive_directory which backup file should be deleted from
     * [backup_file] -> absolute path of backup file on local server
     * @return	void
     */
    function remove_google_drive_backup($args) {
    	extract($args);

    	global $mng_plugin_dir;
    	require_once("$mng_plugin_dir/lib/google-api-client/Google_Client.php");
    	require_once("$mng_plugin_dir/lib/google-api-client/contrib/Google_DriveService.php");

    	try {
	    	$gdrive_client = new Google_Client();
	    	$gdrive_client->setUseObjects(true);
	    	$gdrive_client->setAccessToken($google_drive_token);
    	} catch (Exception $e) {
    		/*eturn array(
    			'error' => $e->getMessage(),
    		);*/
    	}

    	$gdrive_service = new Google_DriveService($gdrive_client);

    	try {
	    	$about = $gdrive_service->about->get();
	    	$root_folder_id = $about->getRootFolderId();
    	} catch (Exception $e) {
    		/*return array(
    			'error' => $e->getMessage(),
    		);*/
    	}

    	try {
	    	$list_files = $gdrive_service->files->listFiles(array("q"=>"title='$google_drive_directory' and '$root_folder_id' in parents and trashed = false"));
	    	$files = $list_files->getItems();
    	} catch (Exception $e) {
    		/*return array(
    			'error' => $e->getMessage(),
    		);*/
    	}
    	if (isset($files[0])) {
    		$wpmanagerpro_folder = $files[0];
    	} else {
    		/*return array(
    			'error' => "This file does not exist.",
    		);*/
    	}

    	if ($google_drive_site_folder) {
    		try {
	    		$subfolder_title = $this->site_name;
	    		$wpmanagerpro_folder_id = $wpmanagerpro_folder->getId();
	    		$list_files = $gdrive_service->files->listFiles(array("q"=>"title='$subfolder_title' and '$wpmanagerpro_folder_id' in parents and trashed = false"));
	    		$files = $list_files->getItems();
    		} catch (Exception $e) {
    			/*return array(
    				'error' => $e->getMessage(),
    			);*/
    		}
    		if (isset($files[0])) {
    			$backup_folder = $files[0];
    		}
    	} else {
    		$backup_folder = $wpmanagerpro_folder;
    	}

    	if (isset($backup_folder)) {
    		try {
	    		$backup_folder_id = $backup_folder->getId();
	    		$list_files = $gdrive_service->files->listFiles(array("q"=>"title='$backup_file' and '$backup_folder_id' in parents and trashed = false"));
	    		$files = $list_files->getItems();;
    		} catch (Exception $e) {
    			/*return array(
		    		'error' => $e->getMessage(),
		    	);*/
    		}
    		if (isset($files[0])) {
    			try {
    				$gdrive_service->files->delete($files[0]->getId());
    			} catch (Exception $e) {
    				/*return array(
		    			'error' => $e->getMessage(),
		    		);*/
    			}
    		} else {
    			/*return array(
	    			'error' => "This file does not exist.",
	    		);*/
    		}
    	} else {
    		/*return array(
    			'error' => "This file does not exist.",
    		);*/
    	}

    	//return true;
    }

    /**
     * Downloads backup file from Google Drive to root folder on local server.
     *
     * @param 	array 	$args	arguments passed to the function
     * [google_drive_token] -> user's Google drive token in json form
     * [google_drive_directory] -> folder on user's Google Drive account which backup file should be downloaded from
     * [google_drive_site_folder] -> subfolder with site name in google_drive_directory which backup file should be downloaded from
     * [backup_file] -> absolute path of backup file on local server
     * @return	bool|array		absolute path to downloaded file is successful, array with error message if not
     */
    function get_google_drive_backup($args) {
    	extract($args);

    	global $mng_plugin_dir;
    	require_once("$mng_plugin_dir/lib/google-api-client/Google_Client.php");
    	require_once("$mng_plugin_dir/lib/google-api-client/contrib/Google_DriveService.php");

    	try {
	    	$gdrive_client = new Google_Client();
	    	$gdrive_client->setUseObjects(true);
	    	$gdrive_client->setAccessToken($google_drive_token);
    	} catch (Exception $e) {
			return array(
    			'error' => $e->getMessage(),
    		);
    	}

    	$gdrive_service = new Google_DriveService($gdrive_client);

    	try {
	    	$about = $gdrive_service->about->get();
	    	$root_folder_id = $about->getRootFolderId();
    	} catch (Exception $e) {
			return array(
    			'error' => $e->getMessage(),
    		);
    	}

    	try {
    		$list_files = $gdrive_service->files->listFiles(array("q"=>"title='$google_drive_directory' and '$root_folder_id' in parents and trashed = false"));
    		$files = $list_files->getItems();
    	} catch (Exception $e) {
    		return array(
    			'error' => $e->getMessage(),
    		);
    	}
    	if (isset($files[0])) {
    		$wpmanagerpro_folder = $files[0];
    	} else {
    		return array(
	    		'error' => "This file does not exist.",
	    	);
    	}

    	if ($google_drive_site_folder) {
    		try {
	    		$subfolder_title = $this->site_name;
	    		$wpmanagerpro_folder_id = $wpmanagerpro_folder->getId();
	    		$list_files = $gdrive_service->files->listFiles(array("q"=>"title='$subfolder_title' and '$wpmanagerpro_folder_id' in parents and trashed = false"));
	    		$files = $list_files->getItems();
    		} catch (Exception $e) {
    			return array(
    				'error' => $e->getMessage(),
    			);
    		}
    		if (isset($files[0])) {
    			$backup_folder = $files[0];
    		}
    	} else {
    		$backup_folder = $wpmanagerpro_folder;
    	}

    	if (isset($backup_folder)) {
    		try {
	    		$backup_folder_id = $backup_folder->getId();
	    		$list_files = $gdrive_service->files->listFiles(array("q"=>"title='$backup_file' and '$backup_folder_id' in parents and trashed = false"));
	    		$files = $list_files->getItems();
    		} catch (Exception $e) {
    			return array(
    				'error' => $e->getMessage(),
    			);
    		}
    		if (isset($files[0])) {
    			try {
    				$download_url = $files[0]->getDownloadUrl();
					if ($download_url) {
						$request = new Google_HttpRequest($download_url, 'GET', null, null);
						$http_request = Google_Client::$io->authenticatedRequest($request);
						if ($http_request->getResponseHttpCode() == 200) {
							$stream = $http_request->getResponseBody();
							$local_destination = ABSPATH . 'mng_temp_backup.zip';
							$handle = @fopen($local_destination, 'w+');
							$result = fwrite($handle, $stream);
							fclose($handle);
							if($result)
								return $local_destination;
							else
								return array(
									'error' => "Write permission error.",
								);
						} else {
							return array(
				    			'error' => "This file does not exist.",
				    		);
						}
					} else {
						return array(
			    			'error' => "This file does not exist.",
			    		);
					}
    			} catch (Exception $e) {
    				return array(
		    			'error' => $e->getMessage(),
		    		);
    			}
    		} else {
    			return array(
	    			'error' => "This file does not exist.",
	    		);
    		}
    	} else {
    		return array(
	    		'error' => "This file does not exist.",
	    	);
    	}

    	return false;
    }

    /**
     * Schedules the next execution of some backup task.
     *
     * @param 	string 	$type		daily, weekly or monthly
     * @param 	string 	$schedule	format: task_time (if daily), task_time|task_day (if weekly), task_time|task_date (if monthly)
     * @return 	bool|int			timestamp if sucessful, false if not
     */
	function schedule_next($type, $schedule) {
        $schedule = explode("|", $schedule);

		if (empty($schedule))
            return false;
        switch ($type) {
            case 'daily':
                if (isset($schedule[1]) && $schedule[1]) {
                    $delay_time = $schedule[1] * 60;
                }

                $current_hour  = date("H");
                $schedule_hour = $schedule[0];
                if ($current_hour >= $schedule_hour)
                    $time = mktime($schedule_hour, 0, 0, date("m"), date("d") + 1, date("Y"));
                else
                    $time = mktime($schedule_hour, 0, 0, date("m"), date("d"), date("Y"));
                break;

            case 'weekly':
                if (isset($schedule[2]) && $schedule[2]) {
                    $delay_time = $schedule[2] * 60;
                }
                $current_weekday  = date('w');
                $schedule_weekday = $schedule[1];
                $current_hour     = date("H");
                $schedule_hour    = $schedule[0];

                if ($current_weekday > $schedule_weekday)
                    $weekday_offset = 7 - ($week_day - $task_schedule[1]);
                else
                    $weekday_offset = $schedule_weekday - $current_weekday;

                if (!$weekday_offset) { //today is scheduled weekday
                    if ($current_hour >= $schedule_hour)
                        $time = mktime($schedule_hour, 0, 0, date("m"), date("d") + 7, date("Y"));
                    else
                        $time = mktime($schedule_hour, 0, 0, date("m"), date("d"), date("Y"));
                } else {
                    $time = mktime($schedule_hour, 0, 0, date("m"), date("d") + $weekday_offset, date("Y"));
                }
                break;

            case 'monthly':
                if (isset($schedule[2]) && $schedule[2]) {
                    $delay_time = $schedule[2] * 60;
                }
                $current_monthday  = date('j');
                $schedule_monthday = $schedule[1];
                $current_hour      = date("H");
                $schedule_hour     = $schedule[0];

                if ($current_monthday > $schedule_monthday) {
                    $time = mktime($schedule_hour, 0, 0, date("m") + 1, $schedule_monthday, date("Y"));
                } else if ($current_monthday < $schedule_monthday) {
                    $time = mktime($schedule_hour, 0, 0, date("m"), $schedule_monthday, date("Y"));
                } else if ($current_monthday == $schedule_monthday) {
                    if ($current_hour >= $schedule_hour)
                        $time = mktime($schedule_hour, 0, 0, date("m") + 1, $schedule_monthday, date("Y"));
                    else
                        $time = mktime($schedule_hour, 0, 0, date("m"), $schedule_monthday, date("Y"));
                    break;
                }

                break;

            default:
                break;
        }

        if (isset($delay_time) && $delay_time) {
            $time += $delay_time;
        }

        return $time;
    }

    /**
     * Parse task arguments for info.
     *
     * @return mixed	associative array with stats for every backup task or error if backup is manually deleted on server
     */
    function get_backup_stats() {
        $stats = array();
        $tasks = get_option('mng_backup_tasks');
        if (is_array($tasks) && !empty($tasks)) {
            foreach ($tasks as $task_id => $info) {
                if (is_array($info['task_results']) && !empty($info['task_results'])) {
                    foreach ($info['task_results'] as $key => $result) {
                        if (isset($result['server']) && !isset($result['error'])) {
                            if (isset($result['server']['file_path']) && $info['task_args']['keep_backup_on_server']) {
	                        	if (!file_exists($result['server']['file_path'])) {
	                                $info['task_results'][$key]['error'] = 'Backup created but manually removed from server.';
	                            }
                            }
                        }
                    }
                }
                if (is_array($info['task_results']))
                	$stats[$task_id] = array_values($info['task_results']);
            }
        }
        return $stats;
    }

    /**
     * Returns all backup tasks with information when the next schedule will be.
     *
     * @return	mixed	associative array with timestamp with next schedule for every backup task
     */
    function get_next_schedules() {
        $stats = array();
        $tasks = get_option('mng_backup_tasks');
        if (is_array($tasks) && !empty($tasks)) {
            foreach ($tasks as $task_id => $info) {
                $stats[$task_id] = isset($info['task_args']['next']) ? $info['task_args']['next'] : array();
            }
        }
        return $stats;
    }

    /**
     * Deletes all old backups from local server.
     * It depends on configuration (Number of backups to keep).
     *
     * @param	string 	$task_id	id of backup task
     * @return	bool|void			true if there are backups for deletion, void if not
     */
    function remove_old_backups($task_id) {
        //Check for previous failed backups first
        $this->cleanup();

        //Remove by limit
        $backups = get_option('mng_backup_tasks');
        if ($backups[$task_id]['task_args']['run_now']) {
            $num = 0;
        } else {
            $num = 1;
        }

        if ((count($backups[$task_id]['task_results']) - $num) >= $backups[$task_id]['task_args']['limit']) {
            //how many to remove ?
            $remove_num = (count($backups[$task_id]['task_results']) - $num - $backups[$task_id]['task_args']['limit']) + 1;
            for ($i = 0; $i < $remove_num; $i++) {
                //Remove from the server
                if (isset($backups[$task_id]['task_results'][$i]['server'])) {
                    @unlink($backups[$task_id]['task_results'][$i]['server']['file_path']);
                }

                //Remove from ftp
                if (isset($backups[$task_id]['task_results'][$i]['ftp']) && isset($backups[$task_id]['task_args']['account_info']['ftp'])) {
                    $ftp_file            = $backups[$task_id]['task_results'][$i]['ftp'];
                    $args                = $backups[$task_id]['task_args']['account_info']['ftp'];
                    $args['backup_file'] = $ftp_file;
                    $this->remove_ftp_backup($args);
                }

                if (isset($backups[$task_id]['task_results'][$i]['dropbox']) && isset($backups[$task_id]['task_args']['account_info']['dropbox'])) {
                    //To do: dropbox remove
                    $dropbox_file        = $backups[$task_id]['task_results'][$i]['dropbox'];
                    $args                = $backups[$task_id]['task_args']['account_info']['dropbox'];
                    $args['backup_file'] = $dropbox_file;
                	$this->remove_dropbox_backup($args);
                }

                if (isset($backups[$task_id]['task_results'][$i]['google_drive']) && isset($backups[$task_id]['task_args']['account_info']['google_drive'])) {
                	$google_drive_file   = $backups[$task_id]['task_results'][$i]['google_drive'];
                	$args                = $backups[$task_id]['task_args']['account_info']['google_drive'];
                	$args['backup_file'] = $google_drive_file;
                	$this->remove_google_drive_backup($args);
                }

                //Remove database backup info
                unset($backups[$task_id]['task_results'][$i]);
            } //end foreach

            if (is_array($backups[$task_id]['task_results']))
            	$backups[$task_id]['task_results'] = array_values($backups[$task_id]['task_results']);
            else
            	$backups[$task_id]['task_results']=array();

            update_option('mng_backup_tasks', $tasks);

            return true;
        }
    }

    /**
     * Deletes specified backup.
     *
     * @param	array	$args	arguments passed to function
     * [task_id] -> id of backup task
     * [result_id] -> id of baskup task result, which should be restored
     * [google_drive_token] -> json of Google Drive token, if it is remote destination
     * @return	bool			true if successful, false if not
     */
    function delete_backup($args) {
        if (empty($args))
            return false;
        extract($args);
        $tasks = get_option('mng_backup_tasks');
        if (isset($google_drive_token)) {
        	$tasks[$task_id]['task_args']['account_info']['google_drive']['google_drive_token'] = $google_drive_token;
        }

        $tasks   = get_option('mng_backup_tasks');
        $task    = $tasks[$task_id];
        $backups = $task['task_results'];
        $backup  = $backups[$result_id];

        if (isset($backup['server'])) {
            @unlink($backup['server']['file_path']);
        }

        //Remove from ftp
        if (isset($backup['ftp'])) {
            $ftp_file            = $backup['ftp'];
            $args                = $tasks[$task_id]['task_args']['account_info']['ftp'];
            $args['backup_file'] = $ftp_file;
            $this->remove_ftp_backup($args);
        }

        if (isset($backup['dropbox'])) {
        	$dropbox_file        = $backup['dropbox'];
            $args                = $tasks[$task_id]['task_args']['account_info']['dropbox'];
            $args['backup_file'] = $dropbox_file;
            $this->remove_dropbox_backup($args);
        }

        if (isset($backup['google_drive'])) {
        	$google_drive_file          = $backup['google_drive'];
        	$args                       = $tasks[$task_id]['task_args']['account_info']['google_drive'];
        	$args['backup_file']        = $google_drive_file;
        	$this->remove_google_drive_backup($args);
        }

        unset($backups[$result_id]);

        if (count($backups)) {
            $tasks[$task_id]['task_results'] = $backups;
        } else {
            unset($tasks[$task_id]['task_results']);
        }

        update_option('mng_backup_tasks', $tasks);
        //update_option('mng_backup_tasks', $tasks);
        return true;
    }

    /**
     * Deletes all unneeded files produced by backup process.
     *
     * @return	array	array of deleted files
     */
    function cleanup() {
        $tasks             = get_option('mng_backup_tasks');
        $backup_folder     = WP_CONTENT_DIR . '/' . md5('wpmanagerpro') . '/mng_backups/';
        $backup_folder_new = MNG_BACKUP_DIR . '/';
        $files             = glob($backup_folder . "*");
        $new               = glob($backup_folder_new . "*");

        // Failed db files first
        $db_folder = MNG_DB_DIR . '/';
        $db_files  = glob($db_folder . "*");
        if (is_array($db_files) && !empty($db_files)) {
            foreach ($db_files as $file) {
                @unlink($file);
            }
			@unlink(MNG_BACKUP_DIR.'/mng_db/index.php');
            @rmdir(MNG_DB_DIR);
        }

        // clean_old folder?
        if ((isset($files[0]) && basename($files[0]) == 'index.php' && count($files) == 1) || (empty($files))) {
            if (!empty($files)) {
        		foreach ($files as $file) {
                	@unlink($file);
            	}
            }
            @rmdir(WP_CONTENT_DIR . '/' . md5('wpmanagerpro') . '/mng_backups');
            @rmdir(WP_CONTENT_DIR . '/' . md5('wpmanagerpro'));
        }

        if (!empty($new)) {
        	foreach ($new as $b) {
            	$files[] = $b;
        	}
        }
        $deleted = array();

        if (is_array($files) && count($files)) {
            $results = array();
            if (!empty($tasks)) {
                foreach ((array) $tasks as $task) {
                    if (isset($task['task_results']) && count($task['task_results'])) {
                        foreach ($task['task_results'] as $backup) {
                            if (isset($backup['server'])) {
                                $results[] = $backup['server']['file_path'];
                            }
                        }
                    }
                }
            }

            $num_deleted = 0;
            foreach ($files as $file) {
                if (!in_array($file, $results) && basename($file) != 'index.php') {
                    @unlink($file);
                    $deleted[] = basename($file);
                    $num_deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Uploads to remote destination in the second step.
     */
    function remote_backup_now($args) {
		$this->set_memory();

        $task_id = $args['task_id'];

        $tasks = get_option('mng_backup_tasks');
        $task  = $tasks[$task_id];
        $account_info = $task['task_args']['account_info'];
        $results = $task['task_results'];
        if (is_array($results) && count($results)) {
            $backup_file = $results[count($results) - 1]['server']['file_path'];
        }

        if ($backup_file && file_exists($backup_file)) {
            // FTP, Dropbox or Google Drive
            if (isset($account_info['ftp']) && !empty($account_info['ftp'])) {
            	$this->update_status($task_id, $this->statuses['ftp']);
            	$account_info['ftp']['backup_file'] = $backup_file;
                $return = $this->ftp_backup($account_info['ftp']);
                $this->wpdb_reconnect();

                if (!(is_array($return) && isset($return['error']))) {
                	$this->update_status($task_id, $this->statuses['ftp'], true);
                	$this->update_status($task_id, $this->statuses['finished'], true);
                }
            }

            if (isset($account_info['dropbox']) && !empty($account_info['dropbox'])) {
            	$this->update_status($task_id, $this->statuses['dropbox']);
            	$account_info['dropbox']['backup_file'] = $backup_file;
                $return = $this->dropbox_backup($account_info['dropbox']);
                $this->wpdb_reconnect();

                if (!(is_array($return) && isset($return['error']))) {
                	$this->update_status($task_id, $this->statuses['dropbox'], true);
                	$this->update_status($task_id, $this->statuses['finished'], true);
                }
            }

            if (isset($account_info['google_drive']) && !empty($account_info['google_drive'])) {
            	$this->update_status($task_id, $this->statuses['google_drive']);
            	$account_info['google_drive']['backup_file'] = $backup_file;
            	$return = $this->google_drive_backup($account_info['google_drive']);
            	$this->wpdb_reconnect();

            	if (!(is_array($return) && isset($return['error']))) {
            		$this->update_status($task_id, $this->statuses['google_drive'], true);
            		$this->update_status($task_id, $this->statuses['finished'], true);
            	}
            }

            @file_put_contents(MNG_BACKUP_DIR.'/mng_db/index.php', '');
            $keep_backup_on_server = $task['task_args']['keep_backup_on_server'];
            if ($return == true && ! $keep_backup_on_server) {
                $tasks = get_option('mng_backup_tasks');
                @unlink($backup_file);
                unset($tasks[$task_id]['task_results'][count($tasks[$task_id]['task_results']) - 1]['server']);
                update_option('mng_backup_tasks', $tasks);
            }
        } else {
            $return = array(
                'error' => 'Backup file not found on your server. Please try again.'
            );
        }

        return $return;
    }



    /**
     * Updates status of backup task.
     * Positive number if completed, negative if not.
     * (
     * 0 - Backup started,
     * 1 - DB dump,
     * 2 - DB ZIP,
     * 3 - Files ZIP,
     * 5 - Dropbox,
     * 6 - FTP,
     * 7 - Email,
     * 8 - Google Drive,
     * 100 - Finished
     * )
     */
    function update_status($task_id, $status, $completed = false) {
        $tasks = get_option('mng_backup_tasks');
        $run_now = $tasks[$task_id]['task_args']['run_now'];
        if ($run_now) return;

        $index = count($tasks[$task_id]['task_results']) - 1;
        if (!is_array($tasks[$task_id]['task_results'][$index]['status'])) {
            $tasks[$task_id]['task_results'][$index]['status'] = array();
        }
        if (!$completed) {
            $tasks[$task_id]['task_results'][$index]['status'][] = (int) $status * (-1);
        } else {
            $status_index                                                       = count($tasks[$task_id]['task_results'][$index]['status']) - 1;
            $tasks[$task_id]['task_results'][$index]['status'][$status_index] = abs($tasks[$task_id]['task_results'][$index]['status'][$status_index]);
        }

        update_option('mng_backup_tasks', $tasks);
    }

    /**
     * Reconnects to database to avoid timeout problem after ZIP files.
     *
     * @return void
     */
    function wpdb_reconnect() {
    	global $wpdb;

      	if(class_exists('wpdb') && function_exists('wp_set_wpdb_vars')){
      		@mysql_close($wpdb->dbh);
        	$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        	wp_set_wpdb_vars();
      	}
    }

    /**
     * Replaces .htaccess file in process of restoring WordPress site.
     *
     * @param 	string 	$url	url of current site
     * @return	void
     */
	function replace_htaccess($url) {
	    $file = @file_get_contents(ABSPATH.'.htaccess');
	    if ($file && strlen($file)) {
	        $args    = parse_url($url);
	        $string  = rtrim($args['path'], "/");
	        $regex   = "/BEGIN WordPress(.*?)RewriteBase(.*?)\n(.*?)RewriteRule \.(.*?)index\.php(.*?)END WordPress/sm";
	        $replace = "BEGIN WordPress$1RewriteBase " . $string . "/ \n$3RewriteRule . " . $string . "/index.php$5END WordPress";
	        $file    = preg_replace($regex, $replace, $file);
	        @file_put_contents(ABSPATH.'.htaccess', $file);
	    }
	}

    /**
     * Tries to increase memory limit to 384M and execution time to 600s.
     */
    function set_memory() {
        $changed = array('execution_time' => 0, 'memory_limit' => 0);

        $memory_limit = trim(ini_get('memory_limit'));
        $last = strtolower(substr($memory_limit, -1));

        if($last == 'g')
            $memory_limit = ((int) $memory_limit)*1024;
        else if($last == 'm')
            $memory_limit = (int) $memory_limit;
        if($last == 'k')
            $memory_limit = ((int) $memory_limit)/1024;

        if ( $memory_limit < 384 )  {
            @ini_set('memory_limit', '384M');
            $changed['memory_limit'] = 1;
        }

        if ( (int) @ini_get('max_execution_time') < 600 ) {
            @set_time_limit(600); //ten minutes
            $changed['execution_time'] = 1;
        }

        return $changed;
    }
}

if(!function_exists('get_all_files_from_dir')) {
	/**
	 * Get all files in directory
	 *
	 * @param 	string 	$path 		Relative or absolute path to folder
	 * @param 	array 	$exclude 	List of excluded files or folders, relative to $path
	 * @return 	array 				List of all files in folder $path, exclude all files in $exclude array
	 */
	function get_all_files_from_dir($path, $exclude = array()) {
		if ($path[strlen($path) - 1] === "/") $path = substr($path, 0, -1);
		global $directory_tree, $ignore_array;
		$directory_tree = array();
		foreach ($exclude as $file) {
			if (!in_array($file, array('.', '..'))) {
				if ($file[0] === "/") $path = substr($file, 1);
				$ignore_array[] = "$path/$file";
			}
		}
		get_all_files_from_dir_recursive($path);
		return $directory_tree;
	}
}

if (!function_exists('get_all_files_from_dir_recursive')) {
	/**
	 * Get all files in directory,
	 * wrapped function which writes in global variable
	 * and exclued files or folders are read from global variable
	 *
	 * @param 	string 	$path 	Relative or absolute path to folder
	 * @return 	void
	 */
	function get_all_files_from_dir_recursive($path) {
		if ($path[strlen($path) - 1] === "/") $path = substr($path, 0, -1);
		global $directory_tree, $ignore_array;
		$directory_tree_temp = array();
		$dh = @opendir($path);

		while (false !== ($file = @readdir($dh))) {
			if (!in_array($file, array('.', '..'))) {
				if (!in_array("$path/$file", $ignore_array)) {
					if (!is_dir("$path/$file")) {
						$directory_tree[] = "$path/$file";
					} else {
						get_all_files_from_dir_recursive("$path/$file");
					}
				}
			}
		}
		@closedir($dh);
	}
}

?>