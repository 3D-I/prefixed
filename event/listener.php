<?php
/**
 *
 * @package prefixed
 * @copyright (c) 2013 David King (imkingdavid)
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace imkingdavid\prefixed\event;

// use imkingdavid\prefixed\core\manager;

/**
 * @ignore
 */
if (!defined('IN_PHPBB'))
{
	exit;
}

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	/**
	 * Database object
	 * @var \phpbb\db\driver
	 */
	protected $db;

	/**
	 * Cache driver object
	 * @var \phpbb\cache\driver\interface
	 */
	protected $cache;

	/**
	 * Template object
	 * @var \phpbb\template
	 */
	protected $template;

	/**
	 * Request object
	 * @var \phpbb\request
	 */
	protected $request;

	/**
	 * User object
	 * @var \phpbb\user
	 */
	protected $user;

	/**
	 * Prefix manager object
	 * @var imkingdavid\prefixed\core\manager
	 */
	protected $manager;

	/**
	 * Table prefix
	 * @var string
	 */
	protected $table_prefix;

	/**
	 * We don't want to run the setup() method twice so we keep track of
	 * whether or not it has been run. This is mainly for the
	 * core.modify_posting_parameters event that is run before core.user_setup
	 * @var bool
	 */
	protected $setup_has_been_run = false;

	/**
	 * See the explanation near the end of manage_prefixes_on_posting()
	 * This variable holds an array with an array of prefix IDs to be
	 * applied and the forum ID, which are passed directly into
	 * manager::set_topic_prefixes() along with the latest topic ID from
	 * the current user.
	 * @var array
	 */
	protected $prefix_queue = [];

	/**
	 * Get subscribed events
	 *
	 * @return array
	 * @static
	 */
	static public function getSubscribedEvents()
	{
		return [
			// phpBB Core Events
			'core.user_setup'					=> 'setup',
			//'core.display_forums_modify_template_vars'	=> 'get_forumlist_topic_prefix',
			'core.viewtopic_modify_page_title'	=> 'get_viewtopic_topic_prefix',
			'core.viewforum_modify_topicrow'	=> 'get_viewforum_topic_prefixes',
			'core.modify_posting_parameters'	=> 'manage_prefixes_on_posting',
			'core.posting_modify_template_vars'	=> [
				'generate_posting_form',
				'handle_prefix_queue',
			],

			// Events added by this extension
			'prefixed.modify_prefix_title'		=> 'get_token_data',
		];
	}

	/**
	 * Set up the environment
	 *
	 * @param Event $event Event object
	 * @return null
	 */
	public function setup($event)
	{
		global $phpbb_container;

		// Keep this from running twice
		if($this->setup_has_been_run === true)
		{
			return;
		}
		$this->setup_has_been_run = true;

		$this->container = $phpbb_container;

		// Let's get our table constants out of the way
		$table_prefix = $this->container->getParameter('core.table_prefix');
		define('PREFIXES_TABLE', $table_prefix . 'topic_prefixes');
		define('PREFIX_INSTANCES_TABLE', $table_prefix . 'topic_prefix_instances');

		$this->user = $this->container->get('user');
		$this->db = $this->container->get('dbal.conn');
		$this->request = $this->container->get('request');
		$this->manager = $this->container->get('prefixed.manager');
	}

	/**
	 * Get the actual data to store in the DB for given tokens
	 *
	 * @param Event $event Event object
	 * @return null
	 */
	public function get_token_data($event)
	{
		$tokens =& $event['token_data'];

		if (strpos($event['title'], '{DATE}') !== false)
		{
			$tokens['DATE'] = $this->container->get('user')->format_date(microtime(true));
		}

		if (strpos($event['title'], '{USERNAME}') !== false)
		{
			$tokens['USERNAME'] = $this->container->get('user')->data['username'];
		}
	}

	/**
	 * Get the form things for the posting form
	 *
	 * @param Event $event Event object
	 * @return null
	 */
	public function generate_posting_form($event)
	{
		$this->user->add_lang_ext('imkingdavid/prefixed', 'prefixed');
		$this->manager->generate_posting_form($this->request->variable('p', 0), $this->request->variable('t', 0), $this->request->variable('f', 0));
	}

	/**
	 * Handle the prefix setting for new topics. See the explanation near the
	 * end of manage_prefixes_on_posting()
	 *
	 * @param Event $event Event object
	 * @return null
	 */
	public function handle_prefix_queue($event)
	{
		var_dump('test');
		$prefix_queue = $this->request->variable('prefix_queue', '');
		if (sizeof($prefix_queue))
		{
			$prefix_queue = json_decode($prefix_queue);
			var_dump($this->prefix_queue);
			$sql = 'SELECT topic_id
				FROM ' . TOPICS_TABLE . '
				WHERE poster_id = ' . (int) $user->data['user_id'] . '
				ORDER BY topic_posted_date DESC';
			$result = $this->db->sql_query($sql);
			$topic_id = $this->db->sql_fetchfield('topic_id');
			$this->db->sql_freeresult();

			if (!$topic_id)
			{
				return;
			}

			$this->manager->set_topic_prefixes($topic_id, $this->prefix_queue[0], $this->prefix_queue[1]);
			$this->prefix_queue = [];
		}
	}

	/**
	 * Perform given actions with given prefix IDs on the posting screen
	 *
	 * @param Event $event Event object
	 * @return null
	 */
	public function manage_prefixes_on_posting($event)
	{
		if (!empty($event['error']))
		{
			return;
		}

		if (!defined('PREFIXES_TABLE'))
		{
			$this->setup($event);
		}

		// Due to jQuery .sortable('serialize') $ids will
		// be a string like: 'prefix[]=4'
		// I need to extract just the number
		$ids = $this->request->variable('prefixes_used', '');
		if (!$event['submit'] || $event['refresh'])
		{
			return;
		}

		if (!empty($ids))
		{
			// If we have no matches, get out!
			// Note that preg_match_all() returns false on failure
			// or the number of matches on success
			// Either way, a 0 or false should be treated the same
			preg_match_all('/(prefix\[\]=(\d)+&?)+/', $ids, $prefix_ids);

			// Otherwise, let's keep moving.
			// When given:
			//
			// string 'prefix[]=4&amp;prefix[]=3' (length=25)
			//
			//
			// preg_match_all() will return an array like this:
			//
			// array (size=3)
			//   0 => 
			//     array (size=2)
			//       0 => string 'prefix[]=4&' (length=11)
			//       1 => string 'prefix[]=3' (length=10)
			//   1 => 
			//     array (size=2)
			//       0 => string 'prefix[]=4&' (length=11)
			//       1 => string 'prefix[]=3' (length=10)
			//   2 => 
			//     array (size=2)
			//       0 => string '4' (length=1)
			//       1 => string '3' (length=1)

			// Therefore, we want to focus on array index 2
			$ids = $prefix_ids[2];
		}
		else
		{
			$ids = [];
		}

		$post_id = (int) $event['post_id'];
		$topic_id = (int) $event['topic_id'];
		$forum_id = (int) $event['forum_id'];

		// If the mode is edit, we need to ensure to that we are working
		// with the first post in the topic
		if ($event['mode'] == 'edit')
		{
			$sql = 'SELECT topic_first_post_id
				FROM ' . TOPICS_TABLE . '
				WHERE topic_id = ' . (int) $event['topic_id'];
			$result = $this->db->sql_query($sql);
			$first_post_id = $this->db->sql_fetchfield('topic_first_post_id');
			$this->db->sql_freeresult($result);

			if ($post_id !== (int) $first_post_id)
			{
				return;
			}
		}

		// The placement of this event in posting.php (at the top) means
		// that when we're posting a new topic, we aren't able to know or
		// figure out the ID of the topic in time to set the prefixes.
		// We'd need an similar event after submit_post() that also has
		// the topic ID.

		// Assuming this listener object instance is shared (i.e. not
		// re-instantiated for every event) I can create a queue parameter
		// to hold the ids and then in generate_posting_form(), which listens
		// for modify_posting_template_vars and so is after submit_post(), I
		// can execute the queue.

		// Definitely not elegant, but it should work for the time being.
		if ($topic_id)
		{
			$this->manager->set_topic_prefixes($topic_id, $ids, $forum_id);
		}
		else
		{
			$this->prefix_queue = [$ids, $forum_id];
		}
	}

	/**
	 * Get the parsed prefix for the current topic, output it to the template
	 * Also gets a plaintext version for the browser page title
	 *
	 * @param Event $event Event object
	 * @return null
	 */
	public function get_viewtopic_topic_prefix($event)
	{
		$event['page_title'] = $this->load_prefixes_topic($event, 'topic_data') . $event['page_title'];
	}

	/**
	 * Get the parsed prefix for each of the topics in the forum row
	 *
	 * @param Event $event Event object
	 * @return null
	 */
	public function get_viewforum_topic_prefixes($event)
	{
		$topic_row = $event['topic_row'];
		$topic_row['TOPIC_PREFIX'] = $this->load_prefixes_topic($event, 'row', '', true);
		$event['topic_row'] = $topic_row;
	}

	/**
	 * Get the parsed prefix for each of the last posts
	 *
	 * @param Event $event Event object
	 * @return null
	 */
	public function get_forumlist_topic_prefix($event)
	{
		$forum_row = $event['forum_row'];
		$forum_row['TOPIC_PREFIX'] = $this->load_prefixes_topic($event, 'row', '', true);
		$event['forum_row'] = $forum_row;

	}

	/**
	 * Helper method that gets the topic prefixes for view(forum/topic) page
	 *
	 * @param Event $event Event object
	 * @param string $array_name Name of the array that contains the topic_id
	 * @param string $block The name of the template block
	 * @return string Plaintext string of topic prefixes
	 */
	protected function load_prefixes_topic($event, $array_name = 'row', $block = 'prefix', $return_parsed = false)
	{
		if (isset($event[$array_name]['topic_id']))
		{
			$topic_id = (int) $event[$array_name]['topic_id'];
		}
		// The following is for if I decide to put the prefix on the last post topic title on forumlist
		// Right now I'm not because I don't want to mess with it
		// else if (isset($event[$array_name]['forum_last_post_id']))
		// {
		// 	// Get the topic ID
		// 	// This results in a looped query, one per forum.
		// 	// As unfortunate as it is, I'm not aware of a way around it
		// 	// besides adding a forum_last_post_topic_id field in the database

		// 	// Ultimately we only want to display the prefix on the topic title
		// 	// Because the last post on the index can be different than the
		// 	// topic title, we don't want to show it if that is the case
		// 	$sql = 'SELECT topic_id
		// 		FROM ' . TOPICS_TABLE . '
		// 		WHERE topic_first_post_id = ' . (int) $event[$array_name]['forum_last_post_id'] . '
		// 			AND topic_last_post_id = ' . (int) $event[$array_name]['forum_last_post_id'];
		// 	$result = $this->db->sql_query($sql);
		// 	$topic_id = (int) $this->db->sql_fetchfield('topic_id');
		// 	$this->db->sql_freeresult($result);
		// }

		if (empty($topic_id))
		{
			return false;
		}

		return $topic_id &&
			$this->manager->count_prefixes() &&
			$this->manager->count_prefix_instances()
		? $this->manager->load_prefixes_topic($topic_id, $block, $return_parsed)
		: '';
	}
}
