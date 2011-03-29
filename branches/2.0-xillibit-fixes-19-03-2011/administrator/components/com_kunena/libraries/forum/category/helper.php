<?php
/**
 * @version $Id$
 * Kunena Component
 * @package Kunena
 *
 * @Copyright (C) 2008 - 2011 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

kimport ('kunena.error');
kimport ('kunena.user');
kimport ('kunena.forum.category');

/**
 * Kunena Forum Category Helper Class
 */
class KunenaForumCategoryHelper {
	// Global for every instance
	protected static $_instances = false;
	protected static $_tree = array ();
	protected static $_names = array ();

	// Static class
	private function __construct() {}

	/**
	 * Returns the global KunenaForumCategory object, only creating it if it doesn't already exist.
	 *
	 * @access	public
	 * @param	int	$id		The category to load - Can be only an integer.
	 * @return	KunenaForumCategory	The Category object.
	 * @since	1.6
	 */
	static public function get($identifier = null, $reload = false) {
		if (self::$_instances === false) {
			self::loadCategories();
		}

		if ($identifier instanceof KunenaForumCategory) {
			return $identifier;
		}
		$id = intval ( $identifier );
		if ($id < 1)
			return new KunenaForumCategory ();

		if ($reload || empty ( self::$_instances [$id] )) {
			self::$_instances [$id] = new KunenaForumCategory ( $id );
		}

		return self::$_instances [$id];
	}

	static public function getSubscriptions($user = null) {
		$user = KunenaUserHelper::get($user);
		$db = JFactory::getDBO ();
		$query = "SELECT category_id FROM #__kunena_user_categories WHERE user_id={$db->Quote($user->userid)} AND subscribed=1";
		$db->setQuery ( $query );
		$subscribed = (array) $db->loadResultArray ();
		if (KunenaError::checkDatabaseError()) return;
		return KunenaForumCategoryHelper::getCategories($subscribed);
	}

	static public function getNewTopics($catids) {
		$session = KunenaFactory::getSession ();
		$readlist = $session->readtopics;
		$prevCheck = $session->lasttime;
		$categories = self::getCategories($catids);
		$catlist = array();
		foreach ($categories as $category) {
			$catlist += $category->getChannels();
			$catlist += $category->getChildren();
		}
		if (empty($catlist)) return;
		$catlist = implode(',', array_keys($catlist));
		$db = JFactory::getDBO ();
		$query = "SELECT DISTINCT(category_id), COUNT(*) AS new
			FROM #__kunena_topics
			WHERE category_id IN ($catlist) AND hold='0' AND last_post_time>{$db->Quote($prevCheck)} AND id NOT IN ({$readlist})
			GROUP BY category_id";
		$db->setQuery ( $query );
		$newlist = (array) $db->loadObjectList ('category_id');
		if (KunenaError::checkDatabaseError()) return;
		if (empty($newlist)) return;
		$new = array();
		foreach ($newlist AS $id=>$item) {
			$new[$id] = (int) $item->new;
		}
		foreach ($categories as $category) {
			$channels = $category->getChannels();
			$channels += $category->getChildren();
			$category->getNewCount(array_sum(array_intersect_key($new, $channels)));
		}
	}

	static public function getCategoriesByAccess($accesstype='joomla', $groupids = false) {
		if (self::$_instances === false) {
			self::loadCategories();
		}

		if ($groupids === false) {
			// Continue
		} elseif (is_array ($groupids) ) {
			$groupids = array_unique($groupids);
		} else {
			$groupids = array(intval($groupids));
		}

		$list = array ();
		foreach ( self::$_instances as $instance ) {
			if ($instance->accesstype == $accesstype && ($groupids===false || in_array($instance->access, $groupids))) {
				$list [$instance->id] = $instance;
			}
		}

		return $list;
	}

	static public function getCategories($ids = false, $reverse = false, $authorise='read') {
		if (self::$_instances === false) {
			self::loadCategories();
		}

		if ($ids === false) {
			$ids = array_keys(self::$_instances);
		} elseif (is_array ($ids) ) {
			$ids = array_unique($ids);
		} else {
			$ids = array(intval($ids));
		}

		$list = array ();
		if (!$reverse) {
			foreach ( $ids as $id ) {
				if (isset(self::$_instances [$id]) && self::$_instances [$id]->authorise($authorise, null, true)) {
					$list [$id] = self::$_instances [$id];
				}
			}
		} else {
			foreach ( self::$_instances as $category ) {
				if (!in_array($category->id, $ids) && $category->authorise($authorise, null, true)) {
					$list [$category->id] = $category;
				}
			}
		}

		return $list;
	}

	static public function getParents($id = 0, $levels = 100, $params = array()) {
		if (self::$_instances === false) {
			self::loadCategories();
		}
		$unpublished = isset($params['unpublished']) ? (bool) $params['unpublished'] : 0;
		$action = isset($params['action']) ? (string) $params['action'] : 'read';

		if (!isset(self::$_instances [$id]) || !self::$_instances [$id]->authorise($action, null, true)) return array();
		$list = array ();
		$parent = self::$_instances [$id]->parent_id;
		while ($parent && $levels--) {
			if (!isset(self::$_instances [$parent])) return array();
			if (!$unpublished && !self::$_instances [$parent]->published) return array();
			$list[$parent] = self::$_instances [$parent];

			$parent = self::$_instances [$parent]->parent_id;
		}
		return array_reverse($list, true);
	}

	static public function getChildren($parents = 0, $levels = 0, $params = array()) {
		if (self::$_instances === false) {
			self::loadCategories();
		}

		$ordering = isset($params['ordering']) ? (string) $params['ordering'] : 'ordering';
		$direction = isset($params['direction']) ? (int) $params['direction'] : 1;
		$search = isset($params['search']) ? (string) $params['search'] : '';
		$unpublished = isset($params['unpublished']) ? (bool) $params['unpublished'] : 0;
		$action = isset($params['action']) ? (string) $params['action'] : 'read';
		$selected = isset($params['selected']) ? (int) $params['selected'] : 0;

		if (!is_array($parents))
			$parents = array($parents);

		$list = array ();
		foreach ( $parents as $parent ) {
			if ($parent instanceof KunenaForumCategory) {
				$parent = $parent->id;
			}
			if (! isset ( self::$_tree [$parent] ))
				continue;
			$cats = self::$_tree [$parent];
			switch ($ordering) {
				case 'catid' :
					if ($direction > 0)
						ksort ( $cats );
					else
						krsort ( $cats );
					break;
				case 'name' :
					if ($direction > 0)
						uksort ( $cats, array (__CLASS__, 'compareByNameAsc' ) );
					else
						uksort ( $cats, array (__CLASS__, 'compareByNameDesc' ) );
					break;
				case 'ordering' :
				default :
					if ($direction < 0)
						$cats = array_reverse ( $cats, true );
			}

			foreach ( $cats as $id => $children ) {
				if (! isset ( self::$_instances [$id] ))
					continue;
				if (! $unpublished && ! self::$_instances [$id]->published)
					continue;
				if ($id == $selected)
					continue;
				$clist = array ();
				if ($levels && ! empty ( $children )) {
					$clist = self::getChildren ( $id, $levels - 1, $params );
				}
				if (empty ( $clist ) && ! self::$_instances [$id]->authorise ( $action, null, true ))
					continue;
				if (! empty ( $clist ) || ! $search || intval ( $search ) == $id || JString::stristr ( self::$_instances [$id]->name, ( string ) $search )) {
					$list [$id] = self::$_instances [$id];
					$list += $clist;
				}
			}
		}
		return $list;
	}

	static public function getCategoryTree($parent = 0) {
		if (self::$_instances === false) {
			self::loadCategories();
		}
		if ($parent === false) {
			return self::$_tree;
		}
		return isset(self::$_tree[$parent]) ? self::$_tree[$parent] : array();
	}

	static public function recount() {
		$db = JFactory::getDBO ();

		// Update category post count and last post info on categories which have published topics
		$query = "UPDATE #__kunena_categories AS c
			INNER JOIN (
				SELECT category_id AS id, COUNT(*) AS numTopics, SUM(posts) AS numPosts, MAX(id) AS last_topic_id
				FROM #__kunena_topics
				WHERE hold=0 AND moved_id=0
				GROUP BY category_id
			) AS r ON r.id=c.id
			INNER JOIN #__kunena_topics AS tt ON tt.id=r.last_topic_id
			SET c.numTopics = r.numTopics,
				c.numPosts = r.numPosts,
				c.last_topic_id=r.last_topic_id,
				c.last_topic_subject = tt.subject,
				c.last_post_id = tt.last_post_id,
				c.last_post_time = tt.last_post_time,
				c.last_post_userid = tt.last_post_userid,
				c.last_post_message = tt.last_post_message,
				c.last_post_guest_name = tt.last_post_guest_name";
		$db->setQuery ( $query );
		$db->query ();
		if (KunenaError::checkDatabaseError ())
			return false;
		$rows = $db->getAffectedRows ();

		// Update categories which have no published topics
		$query = "UPDATE #__kunena_categories AS c
			LEFT JOIN #__kunena_topics AS tt ON c.id=tt.category_id
			SET c.numTopics=0,
				c.numPosts=0,
				c.last_topic_id=0,
				c.last_topic_subject='',
				c.last_post_id=0,
				c.last_post_time=0,
				c.last_post_userid=0,
				c.last_post_message='',
				c.last_post_guest_name=''
			WHERE tt.id IS NULL";
		$db->setQuery ( $query );
		$db->query ();
		if (KunenaError::checkDatabaseError ())
			return false;
		$rows += $db->getAffectedRows ();

		if ($rows) {
			// If something changed, clean our cache
			$cache = JFactory::getCache('com_kunena', 'output');
			$cache->clean('categories');
		}
		return $rows;
	}

	// Internal functions:

	static protected function loadCategories() {
		// FIXME: caching has still some issues - will disable it for now
/*		$cache = JFactory::getCache('com_kunena', 'output');
		$data = $cache->get('instances', 'com_kunena.categories');
		if ($data !== false) {
			list(self::$_instances, self::$_tree, self::$_names) = unserialize($data);
			return;
		}
*/
		$db = JFactory::getDBO ();
		$query = "SELECT * FROM #__kunena_categories ORDER BY ordering, name";
		$db->setQuery ( $query );
		$results = (array) $db->loadAssocList ();
		KunenaError::checkDatabaseError ();

		self::$_instances = array();
		foreach ( $results as $category ) {
			$instance = new KunenaForumCategory ();
			$instance->bind ( $category );
			$instance->exists (true);
			self::$_instances [(int)$instance->id] = $instance;

			if (!isset(self::$_tree [(int)$instance->id])) {
				self::$_tree [(int)$instance->id] = array();
			}
			self::$_tree [(int)$instance->parent_id][(int)$instance->id] = &self::$_tree [(int)$instance->id];
			self::$_names [(int)$instance->id] = $instance->name;
		}
		unset ($results);

		// TODO: remove this by adding level and section into table
		$heap = array(0);
		while (($parent = array_shift($heap)) !== null) {
			foreach (self::$_tree [$parent] as $id=>$children) {
				if (!empty($children)) array_push($heap, $id);
				self::$_instances [$id]->level = $parent ? self::$_instances [$parent]->level+1 : 0;
				self::$_instances [$id]->section = !self::$_instances [$id]->level;
			}
		}
//		$cache->store(serialize(array(self::$_instances, self::$_tree, self::$_names)), 'instances', 'com_kunena.categories');
	}

	static public function compareByNameAsc($a, $b) {
		if (!isset(self::$_instances[$a]) || !isset(self::$_instances[$b])) return 0;
		return JString::strcasecmp(self::$_instances[$a]->name, self::$_instances[$b]->name);
	}

	static public function compareByNameDesc($a, $b) {
		if (!isset(self::$_instances[$a]) || !isset(self::$_instances[$b])) return 0;
		return JString::strcasecmp(self::$_instances[$b]->name, self::$_instances[$a]->name);
	}
}