<?php

class MNG_Page {
	private $mng_core;

	function __construct($mng_core) {
		$this->mng_core = $mng_core;
	}

	function get_pages($args){
		global $wpdb;

		$where = '';
		if (isset($args['page_title']) && strlen($args['page_title'])) {
			$where.=" AND post_title LIKE '%".mysql_real_escape_string($args['page_title'])."%'";
		}
		if (isset($args['page_content']) && strlen($args['page_content'])) {
			$where.=" AND post_content LIKE '%".mysql_real_escape_string($args['page_content'])."%'";
		}

		$pages_date_from = $args['page_date_from'];
		if (! isset($pages_date_from)) $pages_date_from = '';
		$pages_date_to = $args['page_date_to'];
		if (! isset($pages_date_to)) $pages_date_to = '';
		if (! empty($pages_date_from) && !empty($pages_date_to)) {
			$where.=" AND post_date BETWEEN '".mysql_real_escape_string($pages_date_from)."' AND '".mysql_real_escape_string($pages_date_to)."'";
		} else if(! empty($pages_date_from) && empty($pages_date_to)) {
			$where.=" AND post_date >= '".mysql_real_escape_string($pages_date_from)."'";
		} else if(empty($pages_date_from) && ! empty($pages_date_to)) {
			$where.=" AND post_date <= '".mysql_real_escape_string($pages_date_to)."'";
		}

		$status_array = array();
		$post_statuses = array('publish', 'pending', 'private', 'future', 'draft', 'trash');
		foreach ($args['status'] as $st) {
			if (in_array($st, $post_statuses)) {
				$status_array[] = "'" . $st . "'";
			}
		}
		if (!empty($status_array)) {
			$where.=" AND post_status IN (".implode(",",$status_array).")";
		}

		$limit = (isset($args['limit'])) ? ' LIMIT ' . mysql_real_escape_string($args['limit']) : ' LIMIT 20';
		$sql_query = "$wpdb->posts WHERE post_status!='auto-draft' AND post_status!='inherit' AND post_type='page'".$where." ORDER BY post_date DESC";

		$total = array();
		$pages = array();
		$pages_info = $wpdb->get_results("SELECT * FROM " . $sql_query . $limit);
		$user_info = $this->getUsersIDs();
		$total['total_num'] = count($pages_info);

		foreach ($pages_info as $post_info ) {
			$pages[] = array(
				'post_id' => $post_info->ID,
				'post_title' => $post_info->post_title,
				'post_name' => $post_info->post_name,
				'post_author' => array('author_id' => $post_info->post_author, 'author_name' => $user_info[$post_info->post_author]),
				'post_date' => $post_info->post_date,
				'post_modified' => $post_info->post_modified,
				'post_status' => $post_info->post_status,
				'post_type' => $post_info->post_type,
				'guid' => $post_info->guid,
				'post_password' => $post_info->post_password,
				'ping_status' => $post_info->ping_status,
				'comment_status' => $post_info->comment_status,
				'comment_count' => $post_info->comment_count,
				'post_link' => get_permalink($post_info->ID),
			);
		}

		return array('pages' => $pages, 'total' => $total);
 	}

	private function getUsersIDs() {
		global $wpdb;

		$users_authors=array();
		$users = $wpdb->get_results("SELECT ID as user_id, display_name FROM $wpdb->users WHERE user_status=0");
		foreach ($users as $user_key => $user_val) {
			$users_authors[$user_val->user_id] = $user_val->display_name;
		}
		return $users_authors;
	}
}

?>