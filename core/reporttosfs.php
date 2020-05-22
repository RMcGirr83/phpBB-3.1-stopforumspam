<?php
/**
*
* Stop forum Spam extension for the phpBB Forum Software package.
*
* @copyright (c) 2015 Rich McGirr (RMcGirr83)
* @license GNU General Public License, version 2 (GPL-2.0)
*
*/

namespace rmcgirr83\stopforumspam\core;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use phpbb\exception\http_exception;

class reporttosfs
{
	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var ContainerInterface */
	protected $container;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\log\log */
	protected $log;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\user */
	protected $user;

	/* @var \rmcgirr83\stopforumspam\core\sfsgroups */
	protected $sfsgroups;

	/* @var \rmcgirr83\stopforumspam\core\sfsapi */
	protected $sfsapi;

	public function __construct(
			\phpbb\auth\auth $auth,
			\phpbb\config\config $config,
			ContainerInterface $container,
			\phpbb\db\driver\driver_interface $db,
			\phpbb\log\log $log,
			\phpbb\request\request $request,
			\phpbb\user $user,
			\rmcgirr83\stopforumspam\core\sfsgroups $sfsgroups,
			\rmcgirr83\stopforumspam\core\sfsapi $sfsapi)
	{
		$this->auth = $auth;
		$this->config = $config;
		$this->container = $container;
		$this->db = $db;
		$this->log = $log;
		$this->request = $request;
		$this->user = $user;
		$this->sfsgroups = $sfsgroups;
		$this->sfsapi = $sfsapi;
	}

	/*
	 * reporttosfs
	 * @param 	$username 	username from forum inputs
	 * @param	$userip		userip
	 * @param	$useremail	useremail
	 * @param	$postid		postid of the post
	 * @param	$posterid	posterid that made the post
	 * @return 	json response
	*/
	public function reporttosfs($postid, $posterid)
	{
		$postid = (int) $postid;
		$posterid = (int) $posterid;

		$this->user->add_lang_ext('rmcgirr83/stopforumspam', 'stopforumspam');

		// don't allow banning of anonymous user
		if ($posterid == ANONYMOUS)
		{
			throw new http_exception(403, 'CANNOT_BAN_ANONYMOUS');
		}

		// post id must be greater than 0
		if ($postid <= 0)
		{
			throw new http_exception(403, 'POST_NOT_EXIST');
		}

		$username = $userip = $sfs_reported = $useremail = $forumid = '';

		$sql = 'SELECT p.sfs_reported, p.poster_ip, p.forum_id, p.post_username, u.user_email
			FROM ' . POSTS_TABLE . ' p
			LEFT JOIN ' . USERS_TABLE . ' u on p.poster_id = u.user_id
			WHERE p.post_id = ' . $postid . ' AND p.poster_id = ' . $posterid;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		// info must exist
		if (!$row)
		{
			throw new http_exception(403, 'INFO_NOT_FOUND');
		}

		$username = $row['post_username'];
		$userip = $row['poster_ip'];
		$useremail = $row['user_email'];
		$forumid = (int) $row['forum_id'];
		$sfs_reported = (int) $row['sfs_reported'];

		// ensure the IP is something other than 127.0.0.1 which can happen if the anonymised extension is installed
		if ($userip == '127.0.0.1')
		{
			throw new http_exception(403, 'SFS_ANONYMIZED_IP');
		}
		if ($sfs_reported)
		{
			throw new http_exception(403, 'SFS_REPORTED');
		}

		if (empty($this->config['allow_sfs']) || empty($this->config['sfs_api_key']) || empty($useremail) || empty($userip))
		{
			return false;
		}

		$admins_mods = $this->sfsgroups->getadminsmods($forumid);

		// only allow this via ajax calls
		if ($this->request->is_ajax() && in_array($this->user->data['user_id'], $admins_mods) && !in_array($posterid, $admins_mods))
		{
			$response = $this->sfsapi->sfsapi('add', $username, $userip, $useremail, $this->config['sfs_api_key']);

			if (!$response)
			{
				$data = array(
					'MESSAGE_TITLE'	=> $this->user->lang('ERROR'),
					'MESSAGE_TEXT'	=> $this->user->lang('SFS_ERROR_MESSAGE'),
					'success'	=> false,
				);
				return new JsonResponse($data);
			}

			// Report the uhmmm reported?
			if ($this->config['sfs_notify'])
			{
				$this->check_report($postid);
			}

			$sql = 'UPDATE ' . POSTS_TABLE . '
				SET sfs_reported = 1
				WHERE post_id = ' . (int) $postid;
			$this->db->sql_query($sql);

			$sfs_username = $this->user->lang('SFS_USERNAME_STOPPED', $username);

			$this->sfsapi->sfs_ban('user', $username);

			$this->log->add('mod', $this->user->data['user_id'], $this->user->ip, 'LOG_SFS_REPORTED', false, array($sfs_username, 'forum_id' => $this->forumid, 'topic_id' => $this->topicid, 'post_id'  => $postid));

			$data = array(
				'MESSAGE_TITLE'	=> $this->user->lang('SUCCESS'),
				'MESSAGE_TEXT'	=> $this->user->lang('SFS_SUCCESS_MESSAGE'),
				'success'	=> true,
				'postid'	=> $postid,
			);
			return new JsonResponse($data);
		}
		throw new http_exception(403, 'CANNOT_BAN_ADMINS_MODS');
	}

	/*
	 * check_report
	 * @param 	$postid 	postid from the report to sfs
	 * @return 	null
	*/
	private function check_report($postid)
	{
		$sql = 'SELECT t.*, p.*
			FROM ' . POSTS_TABLE . ' p, ' . TOPICS_TABLE . ' t
			WHERE p.post_id = ' . (int) $postid . '
				AND p.topic_id = t.topic_id';
		$result = $this->db->sql_query($sql);
		$report_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$report_data)
		{
			$data = array(
				'MESSAGE_TITLE'	=> $this->user->lang('ERROR'),
				'MESSAGE_TEXT'	=> $this->user->lang('POST_NOT_EXIST'),
				'success'	=> false,
			);
			return new JsonResponse($data);
		}

		// if the post isn't reported, then report it
		if (!$report_data['post_reported'])
		{
			$report_name = 'other';
			$report_text = $this->user->lang('SFS_WAS_REPORTED');

			$sql = 'SELECT *
				FROM ' . REPORTS_REASONS_TABLE . "
				WHERE reason_title = '" . $this->db->sql_escape($report_name). "'";
			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			$phpbb_notifications = $this->container->get('phpbb.report.handlers.report_handler_post');
			$phpbb_notifications->add_report($postid, $row['reason_id'], $report_text, 0);
		}
	}
}
