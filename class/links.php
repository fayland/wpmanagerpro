<?php

class MNG_Link {
	private $mng_core;

	function __construct($mng_core) {
		$this->mng_core = $mng_core;
	}

	function new_link($args) {
		$url = $args['url'];
		$name = $args['name'];
		$description = $args['description'];
		$link_target = $args['link_target'];
		$link_category = $args['link_category'];

    	$params['link_url'] = esc_url(esc_html($url));
		$params['link_name'] = esc_html($name);
		$params['link_id'] = '';
		$params['link_description'] = $description;
		$params['link_target'] = $link_target;
		$params['link_category'] = array();

		// Add Link category
		if (is_array($link_category) && !empty($link_category)){
			$terms = get_terms('link_category',array('hide_empty' => 0));
			if($terms){
				foreach($terms as $term){
					if(in_array($term->name,$link_category)){
						$params['link_category'][] = $term->term_id;
						$link_category = $this->remove_element($link_category, $term->name);
					}
				}
			}
			if(!empty($link_category)){
				foreach($link_category as $linkkey => $linkval){
					if(!empty($linkval)){
						$link = wp_insert_term($linkval,'link_category');

						if(isset($link['term_id']) && !empty($link['term_id'])){
							$params['link_category'][] = $link['term_id'];
						}
					}
				}
			}
		}

		// Add Link Owner
		$params['link_owner'] = $this->mng_core->c_user->ID;

		if(!function_exists('wp_insert_link'))
			include_once (ABSPATH . 'wp-admin/includes/bookmark.php');

		$is_success = wp_insert_link($params);
		return $is_success ? array('success' => 'Link added.') : array('error' => 'Failed to add link.');
    }

	function remove_element($arr, $val){
		foreach ($arr as $key => $value){
			if ($value == $val){
				unset($arr[$key]);
			}
		}
		return $arr = array_values($arr);
	}

	function get_links($args){
		global $wpdb;

		$where='';
		$filter_links = $args['filter_links'];
		if (!empty($filter_links)) {
			$where.=" AND (link_name LIKE '%".mysql_real_escape_string($filter_links)."%' OR link_url LIKE '%".mysql_real_escape_string($filter_links)."%')";
		}

		$linkcats = $this->getLinkCats();
		$sql_query = "$wpdb->links WHERE 1 ".$where;

		$links_total = $wpdb->get_results("SELECT count(*) as total_links FROM ".$sql_query);
		$total=$links_total[0]->total_links;

		$query_links = $wpdb->get_results("SELECT link_id, link_url, link_name, link_target, link_visible, link_rating, link_rel FROM ".$sql_query." ORDER BY link_name ASC LIMIT 500");
		$links = array();
		foreach ( $query_links as $link_info ) {
			$link_cat = $linkcats[$link_info->link_id];
			$cats = array();
			foreach($link_cat as $catkey=>$catval)
			{
				$cats[] = $catval;
			}

			$links[$link_info->link_id] = array(
				"link_url" => $link_info->link_url,
				"link_name" => $link_info->link_name,
				"link_target" => $link_info->link_target,
				"link_visible" => $link_info->link_visible,
				"link_rating" => $link_info->link_rating,
				"link_rel" => $link_info->link_rel,
				"link_cats" => $cats
			);
		}

		return array('links' => $links, 'total' => $total);
	}

	function getLinkCats($taxonomy = 'link_category')
	{
		global $wpdb;

		$cats = $wpdb->get_results("SELECT l.link_id, $wpdb->terms.name
FROM $wpdb->links AS l
INNER JOIN $wpdb->term_relationships ON ( l.link_id = $wpdb->term_relationships.object_id )
INNER JOIN $wpdb->term_taxonomy ON ( $wpdb->term_relationships.term_taxonomy_id = $wpdb->term_taxonomy.term_taxonomy_id
AND $wpdb->term_taxonomy.taxonomy = '".$taxonomy."' )
INNER JOIN $wpdb->terms ON ( $wpdb->term_taxonomy.term_id = $wpdb->terms.term_id )");

		foreach ( $cats as $post_val ) {
			$post_cats[$post_val->link_id][] = $post_val->name;
		}

		return $post_cats;
	}

	function delete_link($args){
		global $wpdb;

		if(!empty($args['link_id'])) {
			$delete_query = "DELETE FROM $wpdb->links WHERE link_id = ".$args['link_id'];
			$wpdb->get_results($delete_query);

			return array('success' => 'Link deleted.');
		} else {
			return array('error' => 'No ID...');
		}
	}

	function bulk_action($args){
		global $wpdb;

		$link_ids = $args['link_ids'];
		$delete_query_intro = "DELETE FROM $wpdb->links WHERE link_id = ";
		foreach ($link_ids as $val) {
			$delete_query = $delete_query_intro.$val;
			$wpdb->query($delete_query);
		}
		return array("success" => "Link deleted");
	}
}

?>