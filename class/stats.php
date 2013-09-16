<?php

class MNG_Stats {
	private $mng_core;
	function __construct($mng_core) {
		$this->mng_core = $mng_core;
	}

    function get($params) {
        global $wpdb, $mng_plugin_dir;

        include_once(ABSPATH . 'wp-includes/update.php');
        include_once(ABSPATH . '/wp-admin/includes/update.php');

        if (empty($params)) {
            // as default?
            $params = array(
                'refresh' => 'transient',
                'item_filter' => array(
                    'get_stats' => array(
                        array('updates', array('plugins' => true, 'themes' => true )),
                        array('core_update', array('core' => true )),
                        array('posts', array('numberposts' => 5 )),
                        array('drafts', array('numberposts' => 5 )),
                        array('scheduled', array('numberposts' => 5 )),
                        array('hit_counter'),
                        array('comments', array('numberposts' => 5 )),
                        array('backups'),
                        array('cleanup', array(
                                'overhead' => array(),
                                'revisions' => array('num_to_keep' => 'r_5'),
                                'spam' => array(),
                            )
                        ),
                    ),
                )
            );
        }

        $mng_core = $this->mng_core;

        if ($params['refresh'] == 'transient') {
            $current = $mng_core->mng_get_transient('update_core');
            if (isset($current->last_checked) || get_option('mng_forcerefresh')) {
                update_option('mng_forcerefresh', false);
                if (time() - $current->last_checked > 7200) {
                    @wp_version_check();
                    @wp_update_plugins();
                    @wp_update_themes();
                }
            }
        }

        global $wpdb, $mng_plugin_dir, $wp_version, $wp_local_package;

        $cstats = $this->mng_parse_action_params($params);
        if ($mng_core->is_multisite) {
            $cstats = $this->get_multisite($cstats);
        }

        $stats = array();
        $stats['stats']           = $cstats;
        $stats['email']           = get_option('admin_email');
        $stats['content_path']    = WP_CONTENT_DIR;
        $stats['wpmanagerpro_path']      = $mng_plugin_dir;
        $stats['wpmanagerpro_version']   = WPMANAGERPRO_VERSION;
        $stats['site_title']      = get_bloginfo('name');
        $stats['site_tagline']    = get_bloginfo('description');
        $stats['db_name']         = $this->get_active_db();
        $stats['site_home']       = get_option('home');
        $stats['admin_url']       = admin_url();
        $stats['wp_multisite']    = $this->mng_core->is_multisite;
        $stats['network_install'] = $this->mng_core->network_admin_install;
        $stats['wordpress_version']     = $wp_version;
        $stats['wordpress_locale_pckg'] = $wp_local_package;
        $stats['php_version']           = phpversion();
        $stats['mysql_version']         = $wpdb->db_version();

        if ($this->mng_core->is_multisite) {
            $details = get_blog_details($this->mng_core->is_multisite);
            if (isset($details->site_id)) {
                $details = get_blog_details($details->site_id);
                if (isset($details->siteurl))
                    $stats['network_parent'] = $details->siteurl;
            }
        }

        if (!function_exists('get_filesystem_method'))
            include_once(ABSPATH . 'wp-admin/includes/file.php');
        $mmode = get_option('mng_maintenace_mode');
        if (!empty($mmode) && isset($mmode['active']) && $mmode['active'] == true) {
            $stats['maintenance'] = true;
        }
        $stats['writable'] = $mng_core->is_server_writable();

        return $stats;
    }

    function get_multisite($stats = array()) {
        global $current_user, $wpdb;
        $user_blogs    = get_blogs_of_user($current_user->ID);
        $network_blogs = $wpdb->get_results("select `blog_id`, `site_id` from `{$wpdb->blogs}`");
        if ($this->mng_core->network_admin_install == '1' && is_super_admin()) {
            if (!empty($network_blogs)) {
                $blogs = array();
                foreach ($network_blogs as $details) {
                    if ($details->site_id == $details->blog_id)
                        continue;
                    else {
                        $data = get_blog_details($details->blog_id);
                        if (in_array($details->blog_id, array_keys($user_blogs)))
                            $stats['network_blogs'][] = $data->siteurl;
                        else {
                            $user = get_users(array(
                                'blog_id' => $details->blog_id,
                                'number' => 1
                            ));
                            if (!empty($user))
                                $stats['other_blogs'][$data->siteurl] = $user[0]->user_login;
                        }
                    }
                }
            }
        }
        return $stats;
    }

    function mng_parse_action_params($params = null){
		$return = array();
		$_item_filter = array( 'core_update', 'hit_counter', 'comments', 'backups', 'posts', 'drafts', 'scheduled', 'updates', 'errors', 'cleanup' );
		if( isset($params['item_filter']) && !empty($params['item_filter'])){
			foreach($params['item_filter'] as $_items){
				if(!empty($_items)){
					foreach($_items as $_item){
						if(isset($_item[0]) && in_array($_item[0], $_item_filter)){
							$_item[1] = isset($_item[1]) ? $_item[1] : array();
							$return = call_user_func(array( &$this, 'get_'.$_item[0]), $return, $_item[1]);
						}
					}
				}
			}
		}
		return $return;
	}

    function get_core_update($stats, $options = array()) {
        global $wp_version;

        if (isset($options['core']) && $options['core']) {
        	$mng_core = $this->mng_core;
            $core = $mng_core->mng_get_transient('update_core');
            if (isset($core->updates) && !empty($core->updates)) {
                $current_transient = $core->updates[0];
                if ($current_transient->response == "development" || version_compare($wp_version, $current_transient->current, '<')) {
                    $current_transient->current_version = $wp_version;
                    $stats['core_updates'] = $current_transient;
                } else
                    $stats['core_updates'] = false;
            } else
                $stats['core_updates'] = false;
        }
        return $stats;
    }

    function get_hit_counter($stats, $options = array()) {
        // Check if there are no hits on last key date
        $mng_user_hits = get_option('mng_user_hit_count');
        if (is_array($mng_user_hits)) {
            end($mng_user_hits);
            $last_key_date = key($mng_user_hits);
            $current_date  = date('Y-m-d');
            if ($last_key_date != $curent_date)
                $this->set_hit_count(true);
        }

        $stats['hit_counter'] = get_option('mng_user_hit_count');
        return $stats;
    }

    function get_comments($stats, $options = array()) {
        $nposts  = isset($options['numberposts']) ? (int) $options['numberposts'] : 20;
        $trimlen = isset($options['trimcontent']) ? (int) $options['trimcontent'] : 200;

        if ($nposts) {
            $comments = get_comments('status=hold&number=' . $nposts);
            if (!empty($comments)) {
                $rtn_comments = array();
                foreach ($comments as $comment) {
                    $commented_post = get_post($comment->comment_post_ID);
                    $tmp = array(
                        'comment_ID' => $comment->comment_ID,
                        'comment_post_ID' => $comment->comment_post_ID,
                        'post_url' => get_permalink($comment->comment_post_ID),
                        'post_title' => $commented_post->post_title,
                        'comment_content' => $this->trim_content($comment->comment_content, $trimlen),
                        'comment_author' => $comment->comment_author,
                        'comment_approved' => $comment->comment_approved,
                        'comment_date' => $comment->comment_date,
                    );
                    $rtn_comments[] = $tmp;
                }
                $stats['comments']['pending'] = $rtn_comments;
            }

            $comments = get_comments('status=approve&number=' . $nposts);
            if (!empty($comments)) {
                $rtn_comments = array();
                foreach ($comments as $comment) {
                    $commented_post = get_post($comment->comment_post_ID);
                    $tmp = array(
                        'comment_ID' => $comment->comment_ID,
                        'comment_post_ID' => $comment->comment_post_ID,
                        'post_url' => get_permalink($comment->comment_post_ID),
                        'post_title' => $commented_post->post_title,
                        'comment_content' => $this->trim_content($comment->comment_content, $trimlen),
                        'comment_author' => $comment->comment_author,
                        'comment_approved' => $comment->comment_approved,
                        'comment_date' => $comment->comment_date,
                    );
                    $rtn_comments[] = $tmp;
                }
                $stats['comments']['approved'] = $rtn_comments;
            }
        }
        return $stats;
    }

    function get_backups($stats, $options = array()) {
        $mng_core = $this->mng_core;
        $stats['mng_backups']      = $mng_core->get_backup_instance()->get_backup_stats();
        $stats['mng_next_backups'] = $mng_core->get_backup_instance()->get_next_schedules();

        return $stats;
    }

    function get_posts($stats, $options = array()) {
        $nposts = isset($options['numberposts']) ? (int) $options['numberposts'] : 20;

        if ($nposts) {
            $posts        = get_posts('post_status=publish&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $recent_posts = array();
            if (!empty($posts)) {
                foreach ($posts as $id => $recent_post) {
                    $recent                   = array();
                    $recent['post_permalink'] = get_permalink($recent_post->ID);
                    $recent['ID']             = $recent_post->ID;
                    $recent['post_date']      = $recent_post->post_date;
                    $recent['post_title']     = $recent_post->post_title;
                    $recent['post_type']      = $recent_post->post_type;
                    $recent['comment_count']  = (int) $recent_post->comment_count;
                    $recent_posts[]           = $recent;
                }
            }

            $posts                  = get_pages('post_status=publish&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $recent_pages_published = array();
            if (!empty($posts)) {
                foreach ((array) $posts as $id => $recent_page_published) {
                    $recent                   = array();
                    $recent['post_permalink'] = get_permalink($recent_page_published->ID);
                    $recent['post_type']      = $recent_page_published->post_type;
                    $recent['ID']             = $recent_page_published->ID;
                    $recent['post_date']      = $recent_page_published->post_date;
                    $recent['post_title']     = $recent_page_published->post_title;

                    $recent_posts[] = $recent;
                }
            }
            if (!empty($recent_posts)) {
                usort($recent_posts, array(
                    $this,
                    'cmp_posts_wpmanagerpro'
                ));
                $stats['posts'] = array_slice($recent_posts, 0, $nposts);
            }
        }
        return $stats;
    }

    function get_drafts($stats, $options = array()) {
        $nposts = isset($options['numberposts']) ? (int) $options['numberposts'] : 20;

        if ($nposts) {
            $drafts        = get_posts('post_status=draft&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $recent_drafts = array();
            if (!empty($drafts)) {
                foreach ($drafts as $id => $recent_draft) {
                    $recent                   = array();
                    $recent['post_permalink'] = get_permalink($recent_draft->ID);
                    $recent['ID']             = $recent_draft->ID;
                    $recent['post_date']      = $recent_draft->post_date;
                    $recent['post_title']     = $recent_draft->post_title;
                    $recent['post_type']      = $recent_draft->post_type;
                    $recent_drafts[] = $recent;
                }
            }
            $drafts              = get_pages('post_status=draft&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $recent_pages_drafts = array();
            if (!empty($drafts)) {
                foreach ((array) $drafts as $id => $recent_pages_draft) {
                    $recent                   = array();
                    $recent['post_permalink'] = get_permalink($recent_pages_draft->ID);
                    $recent['post_type']      = $recent_pages_draft->post_type;
                    $recent['ID']             = $recent_pages_draft->ID;
                    $recent['post_date']      = $recent_pages_draft->post_date;
                    $recent['post_title']     = $recent_pages_draft->post_title;
                    $recent_drafts[] = $recent;
                }
            }
            if (!empty($recent_drafts)) {
                usort($recent_drafts, array(
                    $this,
                    'cmp_posts_wpmanagerpro'
                ));
                $stats['drafts'] = array_slice($recent_drafts, 0, $nposts);
            }
        }
        return $stats;
    }

    function get_scheduled($stats, $options = array()) {
        $nposts = isset($options['numberposts']) ? (int) $options['numberposts'] : 20;

        if ($nposts) {
            $scheduled       = get_posts('post_status=future&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $scheduled_posts = array();
            if (!empty($scheduled)) {
                foreach ($scheduled as $id => $scheduled) {
                    $recent                   = array();
                    $recent['post_permalink'] = get_permalink($scheduled->ID);
                    $recent['ID']             = $scheduled->ID;
                    $recent['post_date']      = $scheduled->post_date;
                    $recent['post_title']     = $scheduled->post_title;
                    $recent['post_type']      = $scheduled->post_type;
                    $scheduled_posts[]        = $recent;
                }
            }
            $scheduled           = get_pages('post_status=future&numberposts=' . $nposts . '&orderby=post_date&order=desc');
            $recent_pages_drafts = array();
            if (!empty($scheduled)) {
                foreach ((array) $scheduled as $id => $scheduled) {
                    $recent                   = array();
                    $recent['post_permalink'] = get_permalink($scheduled->ID);
                    $recent['post_type']      = $scheduled->post_type;
                    $recent['ID']             = $scheduled->ID;
                    $recent['post_date']      = $scheduled->post_date;
                    $recent['post_title']     = $scheduled->post_title;
                    $scheduled_posts[] = $recent;
                }
            }
            if (!empty($scheduled_posts)) {
                usort($scheduled_posts, array(
                    $this,
                    'cmp_posts_wpmanagerpro'
                ));
                $stats['scheduled'] = array_slice($scheduled_posts, 0, $nposts);
            }
        }
        return $stats;
    }

    // once it's not wpmanagerpro call, we set hit_count++
    function set_hit_count($fix_count = false) {
    	$mng_core = $this->mng_core;

        $uptime_robot = array(
            "74.86.158.106",
            "74.86.179.130",
            "74.86.179.131",
            "46.137.190.132",
            "122.248.234.23",
            "74.86.158.107",
            "80.241.208.196",
        ); // don't let uptime robot to affect visit count

        if ($fix_count || (! is_admin() && ! $this->is_bot() && !isset($_GET['doing_wp_cron']) && !in_array($_SERVER['REMOTE_ADDR'], $uptime_robot))) {
            $date           = date('Y-m-d');
            $user_hit_count = (array) get_option('mng_user_hit_count');
            if (! $user_hit_count) {
                $user_hit_count[$date] = array($_SERVER['REMOTE_ADDR'] => 1);

            } else {
                if (! (isset($user_hit_count[$date]) && is_array($user_hit_count[$date]))) {
                    $user_hit_count[$date] = array();
                }
                if (! $fix_count) {
                    if (! isset($user_hit_count[$date][$_SERVER['REMOTE_ADDR']])) {
                        $user_hit_count[$date][$_SERVER['REMOTE_ADDR']] = 0;
                    }
                    $user_hit_count[$date][$_SERVER['REMOTE_ADDR']] = ((int) $user_hit_count[$date][$_SERVER['REMOTE_ADDR']]) + 1;
                }

                if (count($user_hit_count) > 3) { # less days so that the data is not so big
                    @array_shift($user_hit_count);
                }
            }
            update_option('mng_user_hit_count', $user_hit_count);
        }
    }

    function get_updates($stats, $options = array()) {
        $upgrades = false;

        $installer_instance = $this->mng_core->get_installer_instance();
        if (isset($options['themes']) && $options['themes']) {
            $upgrades = $installer_instance->get_upgradable_themes();
            if (!empty($upgrades)) {
                $stats['upgradable_themes'] = $upgrades;
                $upgrades                   = false;
            }
        }

        if (isset($options['plugins']) && $options['plugins']) {
            $upgrades = $installer_instance->get_upgradable_plugins();
            if (!empty($upgrades)) {
                $stats['upgradable_plugins'] = $upgrades;
                $upgrades                    = false;
            }
        }

        return $stats;
    }

    function get_errors($stats, $options = array()) {
        $period    = isset($options['days']) ? (int) $options['days'] * 86400 : 86400;
        $maxerrors = isset($options['max']) ? (int) $options['max'] : 20;
        $errors    = array();
        if (isset($options['get']) && $options['get'] == true) {
            if (function_exists('ini_get')) {
                $logpath = ini_get('error_log');
                if (!empty($logpath) && file_exists($logpath)) {
                    $logfile = @fopen($logpath, 'r');
                    if ($logfile && filesize($logpath) > 0) {
                        $maxlines = 1;
                        $linesize = -4096;
                        $lines    = array();
                        $line     = true;
                        while ($line !== false) {
                            if (fseek($logfile, ($maxlines * $linesize), SEEK_END) !== -1) {
                                $maxlines++;
                                if ($line) {
                                    $line = fread($logfile, ($linesize * -1)) . $line;

                                    foreach ((array) preg_split("/(\r|\n|\r\n)/U", $line) as $l) {
                                        preg_match('/\[(.*)\]/Ui', $l, $match);
                                        if (!empty($match)) {
                                            $key = str_replace($match[0], '', $l);
                                            if (!isset($errors[$key])) {
                                                $errors[$key] = 1;
                                            } else {
                                                $errors[$key] = $errors[$key] + 1;
                                            }

                                            if ((strtotime($match[1]) < ((int) time() - $period)) || count($errors) >= $maxerrors) {
                                                $line = false;
                                                break;
                                            }
                                        }
                                    }
                                }
                            } else
                                break;
                        }
                    }
                    if (!empty($errors)) {
                        $stats['errors']  = $errors;
                        $stats['logpath'] = $logpath;
                        $stats['logsize'] = @filesize($logpath);
                    }
                }
            }
        }

        return $stats;
    }

    /* cleanup starts */
    function get_cleanup($stats, $options = array()) {
        $stats['num_revisions']     = $this->mng_num_revisions($options['revisions']);
        $stats['overhead']          = $this->mng_handle_overhead(false);
        $stats['num_spam_comments'] = $this->mng_num_spam_comments();
        return $stats;
    }
    function mng_num_revisions($filter) {
        global $wpdb;
        $sql = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'";
        $num_revisions = $wpdb->get_var($sql);
        if(isset($filter['num_to_keep']) && !empty($filter['num_to_keep'])){
            $num_rev = str_replace("r_","",$filter['num_to_keep']);
            if($num_revisions < $num_rev){
                return 0;
            }
            return ($num_revisions - $num_rev);
        } else {
            return $num_revisions;
        }
    }
    function mng_handle_overhead($clear = false) {
        global $wpdb;

        $tot_data   = 0;
        $tot_idx    = 0;
        $tot_all    = 0;
        $query      = 'SHOW TABLE STATUS';
        $tables     = $wpdb->get_results($query, ARRAY_A);
        $total_gain = 0;
        $table_string = '';
        foreach ($tables as $table) {
            if (isset($table['Engine']) && in_array($table['Engine'], array(
                'MyISAM',
                'ISAM',
                'HEAP',
                'MEMORY',
                'ARCHIVE'
            ))) {
                if ($wpdb->base_prefix != $wpdb->prefix) {
                    if (preg_match('/^' . $wpdb->prefix . '*/Ui', $table['Name'])) {
                        if ($table['Data_free'] > 0) {
                            $total_gain += $table['Data_free'] / 1024;
                            $table_string .= $table['Name'] . ",";
                        }
                    }
                } else if (preg_match('/^' . $wpdb->prefix . '[0-9]{1,20}_*/Ui', $table['Name'])) {
                    continue;
                } else {
                    if ($table['Data_free'] > 0) {
                        $total_gain += $table['Data_free'] / 1024;
                        $table_string .= $table['Name'] . ",";
                    }
                }
            } elseif (isset($table['Engine']) && $table['Engine'] == 'InnoDB') {
                //$total_gain +=  $table['Data_free'] > 100*1024*1024 ? $table['Data_free'] / 1024 : 0;
            }
        }

        if ($clear) {
            $table_string = substr($table_string, 0, strlen($table_string) - 1); //remove last ,
            $table_string = rtrim($table_string);
            $query = "OPTIMIZE TABLE $table_string";
            $optimize = $wpdb->query($query);
            return $optimize === FALSE ? false : true;
        } else
            return round($total_gain, 3);
    }
    function mng_num_spam_comments() {
        global $wpdb;
        $sql       = "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'";
        $num_spams = $wpdb->get_var($sql);
        return $num_spams;
    }

    function cleanup_delete($revision_filter = array()) {
        if (empty($revision_filter)) {
            $revision_filter = array(
                'overhead' => array(),
                'revisions' => array('num_to_keep' => 'r_5'),
                'spam' => array(),
            );
        }

        $params_array = explode('_', $params['actions']);
        $return_array = array();

        foreach ($params_array as $param) {
            switch ($param) {
                case 'revision':
                    if ($this->mng_delete_all_revisions($revision_filter['revisions'])) {
                        $return_array['revision'] = 'OK';
                    } else {
                        $return_array['revision_error'] = 'OK, nothing to do';
                    }
                    break;
                case 'overhead':
                    if ($this->mng_handle_overhead(true)) {
                        $return_array['overhead'] = 'OK';
                    } else {
                        $return_array['overhead_error'] = 'OK, nothing to do';
                    }
                    break;
                case 'comment':
                    if ($this->mng_delete_spam_comments()) {
                        $return_array['comment'] = 'OK';
                    } else {
                        $return_array['comment_error'] = 'OK, nothing to do';
                    }
                    break;
                default:
                    break;
            }

        }

        unset($params);
        mng_response($return_array, true);
    }

    function mng_delete_all_revisions($filter) {
        global $wpdb;
        $where = '';
        if(isset($filter['num_to_keep']) && !empty($filter['num_to_keep'])){
            $num_rev = str_replace("r_","",$filter['num_to_keep']);
            $select_posts = "SELECT ID FROM $wpdb->posts WHERE post_type = 'revision' ORDER BY post_date DESC LIMIT ".$num_rev;
            $select_posts_res = $wpdb->get_results($select_posts);
            $notin = '';
            $n = 0;
            foreach($select_posts_res as $keep_post){
                $notin.=$keep_post->ID;
                $n++;
                if(count($select_posts_res)>$n){
                    $notin.=',';
                }
            }
            $where = " AND a.ID NOT IN (".$notin.")";
        }

        $sql       = "DELETE a,b,c FROM $wpdb->posts a LEFT JOIN $wpdb->term_relationships b ON (a.ID = b.object_id) LEFT JOIN $wpdb->postmeta c ON (a.ID = c.post_id) WHERE a.post_type = 'revision'".$where;
        $revisions = $wpdb->query($sql);
        return $revisions;
    }

    function mng_delete_spam_comments() {
        global $wpdb;
        $spams = 1;
        $total = 0;
        while ($spams) {
            $sql   = "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam' LIMIT 200";
            $spams = $wpdb->query($sql);
            $total += $spams;
            if ($spams)
                usleep(100000);
        }
        return $total;
    }

    /* cleanup ends */

    function get_active_db() {
        global $wpdb;
        $sql = 'SELECT DATABASE() as db_name';

        $sqlresult = $wpdb->get_row($sql);
        $active_db = $sqlresult->db_name;

        return $active_db;
    }

    function cmp_posts_wpmanagerpro($a, $b) {
        return ($a->post_date < $b->post_date);
    }

    function trim_content($content = '', $length = 200) {
        if (function_exists('mb_strlen') && function_exists('mb_substr'))
            $content = (mb_strlen($content) > ($length + 3)) ? mb_substr($content, 0, $length) . '...' : $content;
        else
            $content = (strlen($content) > ($length + 3)) ? substr($content, 0, $length) . '...' : $content;

        return $content;
    }

    function is_bot() {
        $agent = $_SERVER['HTTP_USER_AGENT'];
        if ($agent == '')
            return false;

        $bot_list = array(
            "Teoma",
            "alexa",
            "froogle",
            "Gigabot",
            "inktomi",
            "looksmart",
            "URL_Spider_SQL",
            "Firefly",
            "NationalDirectory",
            "Ask Jeeves",
            "TECNOSEEK",
            "InfoSeek",
            "WebFindBot",
            "girafabot",
            "crawler",
            "www.galaxy.com",
            "Googlebot",
            "Scooter",
            "Slurp",
            "msnbot",
            "appie",
            "FAST",
            "WebBug",
            "Spade",
            "ZyBorg",
            "rabaz",
            "Baiduspider",
            "Feedfetcher-Google",
            "TechnoratiSnoop",
            "Rankivabot",
            "Mediapartners-Google",
            "Sogou web spider",
            "WebAlta Crawler",
            "aolserver",
            "wpmanagerpro.com", // uptime bot
        );

        foreach ($bot_list as $bot)
            if (strpos($agent, $bot) !== false)
                return true;

        return false;
    }
}

?>