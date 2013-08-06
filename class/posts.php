<?php

class MNG_Post {
	private $mng_core;

	function __construct($mng_core) {
		$this->mng_core = $mng_core;
	}

	function new_post($args) {
		if (! isset($args)) return array('error' => 'invalid arguments.');

		$post_data = array(
			'post_title' => $args['post_title'],
			'post_content' => $args['post_content'],
			'post_status' => 'publish', # FIXME
			'post_author' => $this->mng_core->c_user->ID,
			'post_category' => array(), # FIXME
		);

		$post_id = wp_insert_post($post_data);

		$post_link = get_permalink($post_id);
		return array("success" => "<a href='$post_link' target='_blank'>$post_link</a> is posted.");
	}

	function change_status($args) {
    	global $wpdb;
    	$post_id = $args['post_id'];
    	$status  = $args['status'];
    	$success = false;
    	if (in_array($status, array('draft', 'publish', 'trash'))){
			$sql = "update ".$wpdb->prefix."posts set post_status  = '$status' where ID = '$post_id'";
			$success = $wpdb->query($sql);
    	}
        return $success;
    }

	function get_posts($args) {
		global $wpdb;

		$where = '';
		if (isset($args['post_title']) && strlen($args['post_title'])) {
			$where.=" AND post_title LIKE '%".mysql_real_escape_string($args['post_title'])."%'";
		}
		if (isset($args['post_content']) && strlen($args['post_content'])) {
			$where.=" AND post_content LIKE '%".mysql_real_escape_string($args['post_content'])."%'";
		}

		$posts_date_from = $args['post_date_from'];
		if (! isset($posts_date_from)) $posts_date_from = '';
		$posts_date_to = $args['post_date_to'];
		if (! isset($posts_date_to)) $posts_date_to = '';
		if (! empty($posts_date_from) && !empty($posts_date_to)) {
			$where.=" AND post_date BETWEEN '".mysql_real_escape_string($posts_date_from)."' AND '".mysql_real_escape_string($posts_date_to)."'";
		} else if(! empty($posts_date_from) && empty($posts_date_to)) {
			$where.=" AND post_date >= '".mysql_real_escape_string($posts_date_from)."'";
		} else if(empty($posts_date_from) && ! empty($posts_date_to)) {
			$where.=" AND post_date <= '".mysql_real_escape_string($posts_date_to)."'";
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
		$sql_query = "$wpdb->posts WHERE post_status!='auto-draft' AND post_status!='inherit' AND post_type='post'".$where." ORDER BY post_date DESC";

		$total = array();
		$posts = array();
		$posts_info = $wpdb->get_results("SELECT * FROM " . $sql_query . $limit);
		$user_info = $this->getUsersIDs();
		$post_cats = $this->getPostCats();
		$post_tags = $this->getPostCats('post_tag');
		$total['total_num'] = count($posts_info);

		foreach ($posts_info as $post_info ) {
			$cats = array();
			foreach($post_cats[$post_info->ID] as $cat_array => $cat_array_val) {
				$cats[] = array('name' => $cat_array_val);
			}

			$tags = array();
			if (! empty($post_tags[$post_info->ID])) {
				foreach($post_tags[$post_info->ID] as $tag_array => $tag_array_val) {
					$tags[] = array('name' => $tag_array_val);
				}
			}

			$posts[] = array(
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
				'cats' => $cats,
				'tags' => $tags,
				'post_link' => get_permalink($post_info->ID),
			);
		}

		return array('posts' => $posts, 'total' => $total);
	}

	function delete_post($args) {
		global $wpdb;
		if(!empty($args['post_id']) && !empty($args['action'])) {
			$post_id = (int) $args['post_id'];
			if ($args['action']=='delete') {
				$delete_query = "UPDATE $wpdb->posts SET post_status = 'trash' WHERE ID = " . $post_id;
			} else if($args['action']=='delete_perm'){
				$delete_query = "DELETE FROM $wpdb->posts WHERE ID = " . $post_id;
			} else if($args['action']=='delete_restore'){
				$delete_query = "UPDATE $wpdb->posts SET post_status = 'publish' WHERE ID = " . $post_id;
			}
			$wpdb->get_results($delete_query);
			if ($args['action']=='delete_restore') {
				return array("success" => 'Post restored.');
			} else {
				return array("success" => 'Post deleted.');
			}
		} else {
			return array("error" => 'No ID...');
		}
	}

	function bulk_action($args) {
		global $wpdb;
		$deleteaction = $args['action'];
		if (! isset($deleteaction)) $deleteaction = '';

		if ($deleteaction === 'delete') {
			$delete_query_intro = "DELETE FROM $wpdb->posts WHERE ID = ";
		} elseif ($deleteaction === 'trash') {
			$delete_query_intro = "UPDATE $wpdb->posts SET post_status = 'trash' WHERE ID = ";
		} elseif ($deleteaction === 'draft') {
			$delete_query_intro = "UPDATE $wpdb->posts SET post_status = 'draft' WHERE ID = ";
		} elseif ($deleteaction === 'publish') {
			$delete_query_intro = "UPDATE $wpdb->posts SET post_status = 'publish' WHERE ID = ";
		} else {
			return array("error" => "unknown action.");
		}

		foreach ($args['post_ids'] as $val) {
			if(! empty($val) && is_numeric($val)) {
				$delete_query = $delete_query_intro.$val;
				$wpdb->query($delete_query);
			}
		}

		if ($deleteaction === 'publish') {
			return array("success" => 'Post published.');
		} elseif ($deleteaction === 'draft') {
			return array("success" => 'Post updated to draft.');
		} else {
			return array("success" => 'Post deleted.');
		}

	}

	private function getPostCats($taxonomy = 'category') {
		global $wpdb;

		$cats = $wpdb->get_results("SELECT p.ID AS post_id, $wpdb->terms.name
FROM $wpdb->posts AS p
INNER JOIN $wpdb->term_relationships ON ( p.ID = $wpdb->term_relationships.object_id )
INNER JOIN $wpdb->term_taxonomy ON ( $wpdb->term_relationships.term_taxonomy_id = $wpdb->term_taxonomy.term_taxonomy_id
AND $wpdb->term_taxonomy.taxonomy = '".$taxonomy."' )
INNER JOIN $wpdb->terms ON ( $wpdb->term_taxonomy.term_id = $wpdb->terms.term_id )");

		foreach ( $cats as $post_val ){
			$post_cats[$post_val->post_id][] = $post_val->name;
		}
		return $post_cats;
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