<?php
/**
 * MyBB 1.2
 * Copyright � 2007 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybboard.com
 * License: http://www.mybboard.com/license.php
 *
 * $Id$
 */
 
// Board Name: punBB 1.2

class Convert_punbb extends Converter {

	/**
	 * String of the bulletin board name
	 *
	 * @var string
	 */
	var $bbname = "punBB 1.2";
	
	/**
	 * Array of all of the modules
	 *
	 * @var array
	 */
	var $modules = array("db_configuration" => array("name" => "Database Configuration",
									  "dependencies" => ""),
						 "import_usergroups" => array("name" => "Import punBB 1.2 Usergroups",
									  "dependencies" => "db_configuration"),
						 "import_categories" => array("name" => "Import punBB 1.2 Categories",
									  "dependencies" => "db_configuration"),
						 "import_forums" => array("name" => "Import punBB 1.2 Forums",
									  "dependencies" => "db_configuration,import_categories"),
						 "import_forumperms" => array("name" => "Import punBB 1.2 Forum Permissions",
									  "dependencies" => "db_configuration,import_forums"),
						 "import_threads" => array("name" => "Import punBB 1.2 Threads",
									  "dependencies" => "db_configuration,import_forums"),
						 "import_posts" => array("name" => "Import punBB 1.2 Posts",
									  "dependencies" => "db_configuration,import_threads"),
						 "import_users" => array("name" => "Import punBB 1.2 Users",
									  "dependencies" => "db_configuration,import_usergroups"),
						 "import_settings" => array("name" => "Import punBB 1.2 Settings",
									  "dependencies" => "db_configuration"),
						);

	function punbb_db_connect()
	{
		global $import_session;

		// TEMPORARY
		if($import_session['old_db_engine'] != "mysql" && $import_session['old_db_engine'] != "mysqli")
		{
			require_once MYBB_ROOT."inc/db_{$import_session['old_db_engine']}.php";
		}
		$this->old_db = new databaseEngine;

		$this->old_db->connect($import_session['old_db_host'], $import_session['old_db_user'], $import_session['old_db_pass'], 0, true);
		$this->old_db->select_db($import_session['old_db_name']);
		$this->old_db->set_table_prefix($import_session['old_tbl_prefix']);

		define('PUNBB_TABLE_PREFIX', $import_session['old_tbl_prefix']);
	}

	function db_configuration()
	{
		global $mybb, $output, $import_session, $db, $dboptions, $dbengines, $dbhost, $dbuser, $dbname, $tableprefix;

		// Just posted back to this form?
		if($mybb->input['dbengine'])
		{
			if(!file_exists(MYBB_ROOT."inc/db_{$mybb->input['dbengine']}.php"))
			{
				$errors[] = 'You have selected an invalid database engine. Please make your selection from the list below.';
			}
			else
			{
				// Attempt to connect to the db
				// TEMPORARY
				if($mybb->input['dbengine'] != "mysql" && $mybb->input['dbengine'] != "mysqli")
				{
					require_once MYBB_ROOT."inc/db_{$mybb->input['dbengine']}.php";
				}
				$this->old_db = new databaseEngine;
				$this->old_db->error_reporting = 0;

				$connection = $this->old_db->connect($mybb->input['dbhost'], $mybb->input['dbuser'], $mybb->input['dbpass'], 0, true);
				if(!$connection)
				{
					$errors[]  = "Could not connect to the database server at '{$mybb->input['dbhost']} with the supplied username and password. Are you sure the hostname and user details are correct?";
				}

				// Select the database
				$dbselect = $this->old_db->select_db($mybb->input['dbname']);
				if(!$dbselect)
				{
					$errors[] = "Could not select the database '{$mybb->input['dbname']}'. Are you sure it exists and the specified username and password have access to it?";
				}

				// Need to check if punBB is actually installed here
				$this->old_db->set_table_prefix($mybb->input['tableprefix']);
				if(!$this->old_db->table_exists("users"))
				{
					$errors[] = "The punBB table '{$mybb->input['tableprefix']}users' could not be found in database '{$mybb->input['dbname']}'.  Please ensure punBB exists at this database and with this table prefix.";
				}

				// No errors? Save import DB info and then return finished
				if(!is_array($errors))
				{
					$import_session['old_db_engine'] = $mybb->input['dbengine'];
					$import_session['old_db_host'] = $mybb->input['dbhost'];
					$import_session['old_db_user'] = $mybb->input['dbuser'];
					$import_session['old_db_pass'] = $mybb->input['dbpass'];
					$import_session['old_db_name'] = $mybb->input['dbname'];
					$import_session['old_tbl_prefix'] = $mybb->input['tableprefix'];
					
					// Create temporary import data fields
					create_import_fields();
					
					return "finished";
				}
			}
		}

		$output->print_header("punBB Database Configuration");

		// Check for errors
		if(is_array($errors))
		{
			$error_list = error_list($errors);
			echo "<div class=\"error\">
			      <h3>Error</h3>
				  <p>There seems to be one or more errors with the database configuration information that you supplied:</p>
				  {$error_list}
				  <p>Once the above are corrected, continue with the conversion.</p>
				  </div>";
			$dbhost = $mybb->input['dbhost'];
			$dbuser = $mybb->input['dbuser'];
			$dbname = $mybb->input['dbname'];
			$tableprefix = $mybb->input['tableprefix'];
		}
		else
		{
			echo "<p>Please enter the database details for your current installation of punBB.</p>";
			$dbhost = 'localhost';
			$tableprefix = '';
			$dbuser = '';
			$dbname = '';
		}

		if(function_exists('mysqli_connect'))
		{
			$dboptions['mysqli'] = 'MySQL Improved';
		}
		
		if(function_exists('mysql_connect'))
		{
			$dboptions['mysql'] = 'MySQL';
		}

		foreach($dboptions as $dbfile => $dbtype)
		{
			$dbengines .= "<option value=\"{$dbfile}\">{$dbtype}</option>";
		}

		$output->print_database_details_table("punBB");
		
		$output->print_footer();
	}
	
	function import_users()
	{
		global $mybb, $output, $import_session, $db;
		
		$this->punbb_db_connect();
		
		// Get number of members
		if(!isset($import_session['total_members']))
		{
			$query = $this->old_db->simple_select("users", "COUNT(*) as count");
			$import_session['total_members'] = $this->old_db->fetch_field($query, 'count');
		}
		
		if(!isset($import_session['avatar_directory']))
		{
			$query = $this->old_db->simple_select("config", "conf_value, conf_name", "conf_name = 'o_avatars_dir' OR conf_name = 'o_base_url'");
			if($this->old_db->fetch_field($query, 'conf_name') == 'o_avatar_dir')
			{
				$import_session['avatar_directory'] = $this->old_db->fetch_field($query, 'conf_value');
			}
			else
			{
				$import_session['main_directory'] = $this->old_db->fetch_field($query, 'conf_value');
			}
			
		}

		if($import_session['start_users'])
		{
			// If there are more users to do, continue, or else, move onto next module
			if($import_session['total_members'] - $import_session['start_users'] <= 0)
			{
				$import_session['disabled'][] = 'import_users';
				return "finished";
			}
		}
		
		$output->print_header($this->modules[$import_session['module']]['name']);
		
		// Get number of users per screen from form
		if(isset($mybb->input['users_per_screen']))
		{
			$import_session['users_per_screen'] = intval($mybb->input['users_per_screen']);
		}
		
		if($import_session['users_per_screen'] == 0)
		{
			$import_session['start_users'] = 0;
			echo "<p>Please select how many users to import at a time:</p>
<p><input type=\"text\" name=\"users_per_screen\" value=\"100\" /></p>";
			$import_session['autorefresh'] = "";
echo "<p>Do you want to automically continue to the next step until it's finished?:</p>
<p><input type=\"radio\" name=\"autorefresh\" value=\"yes\" checked=\"checked\" /> Yes <input type=\"radio\" name=\"autorefresh\" value=\"no\" /> No</p>";
			$output->print_footer($import_session['module'], 'module', 1);
		}
		else
		{
			// A bit of stats to show the progress of the current import
			echo "There are ".($import_session['total_members']-$import_session['start_users'])." users left to import and ".round((($import_session['total_members']-$import_session['start_users'])/$import_session['users_per_screen']))." pages left at a rate of {$import_session['users_per_screen']} per page.<br /><br />";
			
			// Count the total number of users so we can generate a unique id if we have a duplicate user
			$query = $db->simple_select("users", "COUNT(*) as totalusers");
			$total_users = $db->fetch_field($query, "totalusers");
			
			// Get members
			$query = $this->old_db->simple_select("users", "*", "username != 'Guest'", array('limit_start' => $import_session['start_users'], 'limit' => $import_session['users_per_screen']));

			while($user = $this->old_db->fetch_array($query))
			{
				++$total_users;
					
				$query1 = $db->simple_select("users", "username,email,uid", " LOWER(username)='".$db->escape_string(my_strtolower($user['username']))."'");
				$duplicate_user = $db->fetch_array($query1);
				if($duplicate_user['username'] && my_strtolower($user['email']) == my_strtolower($duplicate_user['email']))
				{
					echo "Merging user #{$user['id']} with user #{$duplicate_user['uid']}... ";
					$db->update_query("users", array('import_uid' => $user['ID_MEMBER']), "uid = '{$duplicate_user['uid']}'");
					echo "done.<br />";
					
					continue;
				}
				else if($duplicate_user['username'])
				{					
					$import_user['username'] = $duplicate_user['username']."_vb3_import".$total_users;
				}
				
				echo "Adding user #{$user['id']}... ";
				
				// PunBB values
				$insert_user['usergroup'] = $this->get_group_id($user['id'], true);
				$insert_user['additionalgroups'] = $this->get_group_id($user['id']);
				$insert_user['displaygroup'] = $this->get_group_id($user['id'], true);
				$insert_user['import_usergroup'] = $this->get_group_id($user['id'], true, true);
				$insert_user['import_additionalgroups'] = $this->get_group_id($user['id'], false, true);
				$insert_user['import_displaygroup'] = $user['group_id'];
				$insert_user['import_uid'] = $user['id'];
				$insert_user['username'] = $user['username'];
				$insert_user['email'] = $user['email'];
				$insert_user['regdate'] = $user['registered'];
				$insert_user['postnum'] = $user['num_posts'];
				$insert_user['lastactive'] = $user['last_visit'];
				$insert_user['lastvisit'] = $user['last_visit'];
				$insert_user['website'] = $user['url'];
				$insert_user['showsigs'] = int_to_yesno($user['show_sig']);
				$insert_user['showavatars'] = int_to_yesno($user['show_avatars']);
				$insert_user['timezone'] = str_replace(array('.0', '.00'), array('', ''), $user['timezone']);
				if($user['use_avatar'] == '1')
				{
					$extensions = array('.gif', '.jpg', '.png');
					foreach($extensions as $key => $extension)
					{
						if(file_exists($import_session['main_directory'].'/'.$import_session['avatar_directory'].'/'.$user['id'].$extension))
						{
							list($width, $height) = @getimagesize($import_session['main_directory'].'/'.$import_session['avatar_directory'].'/'.$user['id'].$extension);
							$insert_user['avatardimensions'] = $width.'x'.$height;
							$insert_user['avatartype'] = 'upload';
							$insert_user['avatar'] = $user['id'].$extension;
						}
					}
				}
				else
				{
					$insert_user['avatardimensions'] = '';
					$insert_user['avatartype'] = '';
					$insert_user['avatar'] = '';
				}
				$insert_user['lastpost'] = $user['last_post'];				
				$insert_user['icq'] = $user['icq'];
				$insert_user['aim'] = $user['aim'];
				$insert_user['yahoo'] = $user['yahoo'];
				$insert_user['msn'] = $user['msn'];
				$insert_user['hideemail'] = int_to_yesno($user['email_setting']);
				$insert_user['allownotices'] = int_to_yesno($user['notify_with_post']);
				$insert_user['regip'] = $user['registration_ip'];
				
				// Default values
				$insert_user['invisible'] = 'no';
				$insert_user['birthday'] = '';
				$insert_user['emailnotify'] = 'yes';
				$insert_user['receivepms'] = 'yes';
				$insert_user['pmpopup'] = 'yes';
				$insert_user['pmnotify'] = 'yes';
				$insert_user['remember'] = 'yes';
				$insert_user['showquickreply'] = 'yes';
				$insert_user['ppp'] = 0;
				$insert_user['tpp'] = 0;
				$insert_user['daysprune'] = 0;
				$insert_user['timeformat'] = 'd M';
				$insert_user['dst'] = 'no';
				$insert_user['buddylist'] = '';
				$insert_user['ignorelist'] = '';
				$insert_user['style'] = 0;
				$insert_user['away'] = 'no';
				$insert_user['awaydate'] = 0;
				$insert_user['returndate'] = 0;
				$insert_user['referrer'] = 0;
				$insert_user['reputation'] = 0;
				$insert_user['timeonline'] = 0;
				$insert_user['totalpms'] = 0;
				$insert_user['unreadpms'] = 0;
				$insert_user['pmfolders'] = '1**Inbox$%%$2**Sent Items$%%$3**Drafts$%%$4**Trash Can';
				$insert_user['signature'] = '';
				$insert_user['notepad'] = '';
				$uid = $this->insert_user($insert_user);

				echo "done.<br />\n";
			}
			
			if($this->old_db->num_rows($query) == 0)
			{
				echo "There are no users to import. Please press next to continue.";
				define('BACK_BUTTON', false);
			}
		}
		$import_session['start_users'] += $import_session['users_per_screen'];
		$output->print_footer();
	}

	function import_categories()
	{
		global $mybb, $output, $import_session, $db;

		$this->punbb_db_connect();

		// Get number of categories
		if(!isset($import_session['total_cats']))
		{
			$query = $this->old_db->simple_select("categories", "COUNT(*) as count");
			$import_session['total_cats'] = $this->old_db->fetch_field($query, 'count');				
		}

		if($import_session['start_cats'])
		{
			// If there are more categories to do, continue, or else, move onto next module
			if($import_session['total_cats'] - $import_session['start_cats'] <= 0)
			{
				$import_session['disabled'][] = 'import_categories';
				return "finished";
			}
		}

		$output->print_header($this->modules[$import_session['module']]['name']);

		// Get number of categories per screen from form
		if(isset($mybb->input['cats_per_screen']))
		{
			$import_session['cats_per_screen'] = intval($mybb->input['cats_per_screen']);
		}

		if(empty($import_session['cats_per_screen']))
		{
			$import_session['start_cats'] = 0;
			echo "<p>Please select how many categories to import at a time:</p>
<p><input type=\"text\" name=\"cats_per_screen\" value=\"100\" /></p>";
			$import_session['autorefresh'] = "";
echo "<p>Do you want to automically continue to the next step until it's finished?:</p>
<p><input type=\"radio\" name=\"autorefresh\" value=\"yes\" checked=\"checked\" /> Yes <input type=\"radio\" name=\"autorefresh\" value=\"no\" /> No</p>";
			$output->print_footer($import_session['module'], 'module', 1);
		}
		else
		{
			// A bit of stats to show the progress of the current import
			echo "There are ".($import_session['total_cats']-$import_session['start_cats'])." categories left to import and ".round((($import_session['total_cats']-$import_session['start_cats'])/$import_session['cats_per_screen']))." pages left at a rate of {$import_session['cats_per_screen']} per page.<br /><br />";
			
			$query = $this->old_db->simple_select("categories", "*", "", array('limit_start' => $import_session['start_cats'], 'limit' => $import_session['cats_per_screen']));
			while($cat = $this->old_db->fetch_array($query))
			{
				echo "Inserting category #{$cat['id']}... ";
				
				// punBB Values
				$insert_forum['import_fid'] = (-1 * intval($cat['id']));
				$insert_forum['name'] = $cat['cat_name'];
				$insert_forum['disporder'] = $cat['disp_position'];
				
				// Default values
				$insert_forum['description'] = '';
				$insert_forum['linkto'] = '';
				$insert_forum['type'] = 'c';
				$insert_forum['pid'] = '0';
				$insert_forum['parentlist'] = '';
				$insert_forum['active'] = 'yes';
				$insert_forum['open'] = 'yes';
				$insert_forum['threads'] = 0;
				$insert_forum['posts'] = 0;
				$insert_forum['lastpost'] = 0;
				$insert_forum['lastposteruid'] = 0;
				$insert_forum['lastposttid'] = 0;
				$insert_forum['lastpostsubject'] = '';
				$insert_forum['allowhtml'] = 'no';
				$insert_forum['allowmycode'] = 'yes';
				$insert_forum['allowsmilies'] = 'yes';
				$insert_forum['allowimgcode'] = 'yes';
				$insert_forum['allowpicons'] = 'yes';
				$insert_forum['allowtratings'] = 'yes';
				$insert_forum['status'] = 1;
				$insert_forum['usepostcounts'] = 'yes';
				$insert_forum['password'] = '';
				$insert_forum['showinjump'] = 'yes';
				$insert_forum['modposts'] = 'no';
				$insert_forum['modthreads'] = 'no';
				$insert_forum['modattachments'] = 'no';
				$insert_forum['style'] = 0;
				$insert_forum['overridestyle'] = 'no';
				$insert_forum['rulestype'] = 0;
				$insert_forum['rules'] = '';
				$insert_forum['unapprovedthreads'] = 0;
				$insert_forum['unapprovedposts'] = 0;
				$insert_forum['defaultdatecut'] = 0;
				$insert_forum['defaultsortby'] = '';
				$insert_forum['defaultsortorder'] = '';
	
				$fid = $this->insert_forum($insert_forum);
				
				// Update parent list.
				$update_array = array('parentlist' => $fid);
				$db->update_query("forums", $update_array, "fid = '{$fid}'");
				
				echo "done.<br />\n";			
			}
			
			if($this->old_db->num_rows($query) == 0)
			{
				echo "There are no categories to import. Please press next to continue.";
				define('BACK_BUTTON', false);
			}
		}			
		$import_session['start_cats'] += $import_session['cats_per_screen'];
		$output->print_footer();
	}
	
	function import_forums()
	{
		global $mybb, $output, $import_session, $db;

		$this->punbb_db_connect();

		// Get number of forums
		if(!isset($import_session['total_forums']))
		{
			$query = $this->old_db->simple_select("forums", "COUNT(*) as count");
			$import_session['total_forums'] = $this->old_db->fetch_field($query, 'count');
		}

		if($import_session['start_forums'])
		{
			// If there are more forums to do, continue, or else, move onto next module
			if($import_session['total_forums'] - $import_session['start_forums'] <= 0)
			{
				$import_session['disabled'][] = 'import_forums';
				return "finished";
			}
		}
		
		$output->print_header($this->modules[$import_session['module']]['name']);

		// Get number of forums per screen from form
		if(isset($mybb->input['forums_per_screen']))
		{
			$import_session['forums_per_screen'] = intval($mybb->input['forums_per_screen']);
		}
		
		if(empty($import_session['forums_per_screen']))
		{
			$import_session['start_forums'] = 0;
			echo "<p>Please select how many forums to import at a time:</p>
<p><input type=\"text\" name=\"forums_per_screen\" value=\"100\" /></p>";
			$import_session['autorefresh'] = "";
echo "<p>Do you want to automically continue to the next step until it's finished?:</p>
<p><input type=\"radio\" name=\"autorefresh\" value=\"yes\" checked=\"checked\" /> Yes <input type=\"radio\" name=\"autorefresh\" value=\"no\" /> No</p>";
			$output->print_footer($import_session['module'], 'module', 1);
		}
		else
		{
			// A bit of stats to show the progress of the current import
			echo "There are ".($import_session['total_forums']-$import_session['start_forums'])." forums left to import and ".round((($import_session['total_forums']-$import_session['start_forums'])/$import_session['forums_per_screen']))." pages left at a rate of {$import_session['forums_per_screen']} per page.<br /><br />";
			
			$query = $this->old_db->simple_select("forums", "*", "", array('limit_start' => $import_session['start_forums'], 'limit' => $import_session['forums_per_screen']));
			while($forum = $this->old_db->fetch_array($query))
			{
				echo "Inserting forum #{$forum['id']}... ";

				// Values from punBB
				$insert_forum['import_fid'] = intval($forum['id']);
				$insert_forum['name'] = $forum['forum_name'];
				$insert_forum['description'] = $forum['forum_desc'];
				$insert_forum['pid'] = $this->get_import_fid((-1) * $forum['cat_id']);
				$insert_forum['disporder'] = $forum['disp_position'];
				$insert_forum['threads'] = $forum['num_topics'];
				$insert_forum['posts'] = $forum['num_posts'];
				$insert_forum['linkto'] = $forum['redirect_url'];
				$insert_forum['lastpost'] = $forum['last_post'];
				$insert_forum['parentlist'] = $forum['cat_id'];
				$insert_forum['defaultsortby'] = $forum['sort_by'];
				
				$last_post = $this->get_last_post($forum['id']);
				$insert_forum['lastposter'] = $last_post['post']['poster'];
				$insert_forum['lastposttid'] = $last_post['post']['id'];
				$insert_forum['lastposteruid'] = $last_post['post']['poster_id'];				
				$insert_forum['lastpostsubject'] = $last_post['thread']['subject'];
				
				// Default values				
				$insert_forum['type'] = 'f';				
				$insert_forum['active'] = 'yes';
				$insert_forum['open'] = 'yes';				
				$insert_forum['allowhtml'] = 'no';
				$insert_forum['allowmycode'] = 'yes';
				$insert_forum['allowsmilies'] = 'yes';
				$insert_forum['allowimgcode'] = 'yes';
				$insert_forum['allowpicons'] = 'yes';
				$insert_forum['allowtratings'] = 'yes';
				$insert_forum['status'] = 1;
				$insert_forum['password'] = '';
				$insert_forum['showinjump'] = 'yes';
				$insert_forum['modposts'] = 'no';
				$insert_forum['modthreads'] = 'no';
				$insert_forum['modattachments'] = 'no';
				$insert_forum['style'] = 0;
				$insert_forum['overridestyle'] = 'no';
				$insert_forum['rulestype'] = 0;
				$insert_forum['rules'] = '';
				$insert_forum['unapprovedthreads'] = 0;
				$insert_forum['unapprovedposts'] = 0;
				$insert_forum['defaultdatecut'] = 0;
				$insert_forum['defaultsortorder'] = '';
				$insert_forum['usepostcounts'] = 'yes';
	
				$fid = $this->insert_forum($insert_forum);
				
				// Update parent list.
				$update_array = array('parentlist' => $insert_forum['pid'].','.$fid);
				$db->update_query("forums", $update_array, "fid = {$fid}");
				
				echo "done.<br />\n";			
			}
			
			if($this->old_db->num_rows($query) == 0)
			{
				echo "There are no forums to import. Please press next to continue.";
				define('BACK_BUTTON', false);
			}
		}
		$import_session['start_forums'] += $import_session['forums_per_screen'];
		$output->print_footer();	
	}
	
	function import_forumperms()
	{
		global $mybb, $output, $import_session, $db;

		$this->ipb_db_connect();

		// Get number of threads
		if(!isset($import_session['total_forumperms']))
		{
			$query = $this->old_db->simple_select("forum_perms", "COUNT(*) as count");
			$import_session['total_forumperms'] = $this->old_db->fetch_field($query, 'count');				
		}

		if($import_session['start_forumperms'])
		{
			// If there are more threads to do, continue, or else, move onto next module
			if($import_session['total_forumperms'] - $import_session['start_forumperms'] <= 0)
			{
				$import_session['disabled'][] = 'import_forumperms';
				return "finished";
			}
		}
		
		$output->print_header($this->modules[$import_session['module']]['name']);

		// Get number of threads per screen from form
		if(isset($mybb->input['forumperms_per_screen']))
		{
			$import_session['forumperms_per_screen'] = intval($mybb->input['forumperms_per_screen']);
		}
		
		if(empty($import_session['forumperms_per_screen']))
		{
			$import_session['start_forumperms'] = 0;
			echo "<p>Please select how many forum permissions to import at a time:</p>
<p><input type=\"text\" name=\"forumperms_per_screen\" value=\"100\" /></p>";
			$import_session['autorefresh'] = "";
echo "<p>Do you want to automically continue to the next step until it's finished?:</p>
<p><input type=\"radio\" name=\"autorefresh\" value=\"yes\" checked=\"checked\" /> Yes <input type=\"radio\" name=\"autorefresh\" value=\"no\" /> No</p>";
			$output->print_footer($import_session['module'], 'module', 1);
		}
		else
		{
			// A bit of stats to show the progress of the current import
			echo "There are ".($import_session['total_forumperms']-$import_session['start_forumperms'])." forum permissions left to import and ".round((($import_session['total_forumperms']-$import_session['start_forumperms'])/$import_session['forumperms_per_screen']))." forum permissions left at a rate of {$import_session['forumperms_per_screen']} per page.<br /><br />";
			
			$query = $this->old_db->simple_select("forum_perms", "*", "", array('limit_start' => $import_session['start_forumperms'], 'limit' => $import_session['forumperms_per_screen']));
			while($perm = $this->old_db->fetch_array($query))
			{
				echo "Inserting permission for forum #{$perm['forum_id']}... ";
				
				$insert_perm['fid'] = $this->get_import_fid($perm['forum_id']);
				$insert_perm['canratethreads'] = "yes";
				$insert_perm['caneditposts'] = "yes";
				$insert_perm['candeleteposts'] = "yes";
				$insert_perm['candeletethreads'] = "yes";
				$insert_perm['caneditattachments'] = "yes";
				$insert_perm['canpostpolls'] = "yes";
				$insert_perm['canvotepolls'] = "yes";
				$insert_perm['cansearch'] = "yes";
				$insert_perm['gid'] = $this->get_group_id($perm['group_id']);
				$insert_perm['canpostthreads'] = int_to_yesno($perm['post_topics']);
				$insert_perm['canpostreplys'] = int_to_yesno($perm['post_replies']);
				$insert_perm['candlattachments'] = "yes";
				$insert_perm['canpostattachments'] = "yes";
				$insert_perm['canviewthreads'] = "yes";
				$insert_perm['canview'] = int_to_yesno($perm['read_forum']);
				
				$this->insert_forumpermission($insert_perm);
			
				echo "done.<br />\n";
			}
			
			if($this->old_db->num_rows($query) == 0)
			{
				echo "There are no forum permissions to import. Please press next to continue.";
				define('BACK_BUTTON', false);
			}
		}
		$import_session['start_forumperms'] += $import_session['forumperms_per_screen'];
		$output->print_footer();
	}
	
	function import_threads()
	{
		global $mybb, $output, $import_session, $db;

		$this->punbb_db_connect();

		// Get number of threads
		if(!isset($import_session['total_threads']))
		{
			$query = $this->old_db->simple_select("topics", "COUNT(*) as count");
			$import_session['total_threads'] = $this->old_db->fetch_field($query, 'count');				
		}

		if($import_session['start_threads'])
		{
			// If there are more threads to do, continue, or else, move onto next module
			if($import_session['total_threads'] - $import_session['start_threads'] <= 0)
			{
				$import_session['disabled'][] = 'import_threads';
				return "finished";
			}
		}
		
		$output->print_header($this->modules[$import_session['module']]['name']);

		// Get number of threads per screen from form
		if(isset($mybb->input['threads_per_screen']))
		{
			$import_session['threads_per_screen'] = intval($mybb->input['threads_per_screen']);
		}
		
		if(empty($import_session['threads_per_screen']))
		{
			$import_session['start_threads'] = 0;
			echo "<p>Please select how many threads to import at a time:</p>
<p><input type=\"text\" name=\"threads_per_screen\" value=\"100\" /></p>";
			$import_session['autorefresh'] = "";
echo "<p>Do you want to automically continue to the next step until it's finished?:</p>
<p><input type=\"radio\" name=\"autorefresh\" value=\"yes\" checked=\"checked\" /> Yes <input type=\"radio\" name=\"autorefresh\" value=\"no\" /> No</p>";
			$output->print_footer($import_session['module'], 'module', 1);
		}
		else
		{
			// A bit of stats to show the progress of the current import
			echo "There are ".($import_session['total_threads']-$import_session['start_threads'])." threads left to import and ".round((($import_session['total_threads']-$import_session['start_threads'])/$import_session['threads_per_screen']))." threads left at a rate of {$import_session['threads_per_screen']} per page.<br /><br />";

			$query = $this->old_db->simple_select("topics", "*", "", array('limit_start' => $import_session['start_threads'], 'limit' => $import_session['threads_per_screen']));
			while($thread = $this->old_db->fetch_array($query))
			{
				echo "Inserting thread #{$thread['id']}... ";
				
				$insert_thread['import_tid'] = $thread['id'];
				$insert_thread['sticky'] = $thread['sticky'];
				$insert_thread['fid'] = $this->get_import_fid($thread['forum_id']);
				//$insert_thread['firstpost'] = $thread['topic_first_post_id'];	// To do			
				$insert_thread['icon'] = 0;
				$insert_thread['dateline'] = $thread['posted'];
				$insert_thread['subject'] = $thread['subject'];
				
				$user = $this->get_user($thread['poster']);
				
				$insert_thread['poll'] = 0;
				$insert_thread['uid'] = $this->get_import_uid($user['id']);
				$insert_thread['import_uid'] = $user['id'];
				$insert_thread['views'] = $thread['num_views'];
				$insert_thread['replies'] = $thread['num_replies'];
				$insert_thread['closed'] = int_to_yesno($thread['closed']);
				if($insert_thread['closed'] == "no")
				{
					$insert_thread['closed'] = '';
				}
				
				$insert_thread['totalratings'] = 0;
				$insert_thread['notes'] = '';
				$insert_thread['visible'] = 1;
				$insert_thread['unapprovedposts'] = 0;
				$insert_thread['numratings'] = 0;
				$insert_thread['attachmentcount'] = 0;				
				$insert_thread['lastpost'] = $thread['last_post'];
				$insert_thread['lastposter'] = $thread['last_poster'];
				$insert_thread['username'] = $thread['poster'];
				
				$lastpost_user = $this->get_user($thread['last_poster']);				
				$insert_thread['lastposteruid'] = $this->get_import_uid($lastpost_user['id']);
				
				$this->insert_thread($insert_thread);
				echo "done.<br />\n";			
			}
			
			if($this->old_db->num_rows($query) == 0)
			{
				echo "There are no threads to import. Please press next to continue.";
				define('BACK_BUTTON', false);
			}
		}
		$import_session['start_threads'] += $import_session['threads_per_screen'];
		$output->print_footer();
	}
	
	function import_posts()
	{
		global $mybb, $output, $import_session, $db;

		$this->punbb_db_connect();

		// Get number of posts
		if(!isset($import_session['total_posts']))
		{
			$query = $this->old_db->simple_select("posts", "COUNT(*) as count");
			$import_session['total_posts'] = $this->old_db->fetch_field($query, 'count');				
		}

		if($import_session['start_posts'])
		{
			// If there are more posts to do, continue, or else, move onto next module
			if($import_session['total_posts'] - $import_session['start_posts'] <= 0)
			{
				$import_session['disabled'][] = 'import_posts';
				return "finished";
			}
		}

		$output->print_header($this->modules[$import_session['module']]['name']);

		// Get number of posts per screen from form
		if(isset($mybb->input['posts_per_screen']))
		{
			$import_session['posts_per_screen'] = intval($mybb->input['posts_per_screen']);
		}
		
		if(empty($import_session['posts_per_screen']))
		{
			$import_session['start_posts'] = 0;
			echo "<p>Please select how many posts to import at a time:</p>
<p><input type=\"text\" name=\"posts_per_screen\" value=\"100\" /></p>";
			$import_session['autorefresh'] = "";
echo "<p>Do you want to automically continue to the next step until it's finished?:</p>
<p><input type=\"radio\" name=\"autorefresh\" value=\"yes\" checked=\"checked\" /> Yes <input type=\"radio\" name=\"autorefresh\" value=\"no\" /> No</p>";
			$output->print_footer($import_session['module'], 'module', 1);
		}
		else
		{	
			// A bit of stats to show the progress of the current import
			echo "There are ".($import_session['total_posts']-$import_session['start_posts'])." posts left to import and ".round((($import_session['total_posts']-$import_session['start_posts'])/$import_session['posts_per_screen']))." pages left at a rate of {$import_session['posts_per_screen']} per page.<br /><br />";

			$query = $this->old_db->simple_select("posts", "*", "", array('limit_start' => $import_session['start_posts'], 'limit' => $import_session['posts_per_screen']));
			while($post = $this->old_db->fetch_array($query))
			{
				echo "Inserting post #{$post['id']}... ";

				$insert_post['import_pid'] = $post['id'];
				$insert_post['tid'] = $this->get_import_tid($post['topic_id']);

				// Find if this is the first post in thread
				$query1 = $db->simple_select("threads", "firstpost", "tid='{$insert_post['tid']}'");
				$first_post = $db->fetch_field($query1, "firstpost");

				// Make the replyto the first post of thread unless it is the first post
				if($first_post == $post['post_id'])
				{
					$insert_post['replyto'] = 0;
				}
				else
				{
					$insert_post['replyto'] = $first_post;
				}

				$query2 = $db->simple_select("threads", "*", "tid='".$this->get_import_tid($post['topic_id'])."'");
				$thread = $db->fetch_array($query2);
				$insert_post['subject'] = $thread['subject'];

				// Get Username
				$topic_poster = $this->get_user($post['poster_id']);
				$post['username'] = $topic_poster['username'];
				
				// Check usernames for guests
				if($post['username'] == 'NULL')
				{
					$post['username'] = 'Guest';
				}

				$insert_post['fid'] = $this->get_import_fid($thread['fid']);
				$insert_post['icon'] = 0;
				$insert_post['uid'] = $this->get_import_uid($post['poster_id']);
				$insert_post['import_uid'] = $post['poster_id'];
				$insert_post['username'] = $post['poster'];
				$insert_post['dateline'] = $post['posted'];
				$insert_post['message'] = $post['message'];
				$insert_post['ipaddress'] = $post['poster_ip'];
				$insert_post['includesig'] = 'yes';
				$insert_post['smilieoff'] = int_to_yesno($post['hide_smilies']);
				if($post['edited'] != 0)
				{
					$user = $this->get_user($post['edited_by']);
					$insert_post['edituid'] = $user['id'];
					$insert_post['edittime'] = $post['edited'];
				}
				else
				{	
					$insert_post['edituid'] = 0;
					$insert_post['edittime'] = 0;
				}
				$insert_post['visible'] = 1;
				$insert_post['posthash'] = '';

				$pid = $this->insert_post($insert_post);
				
				// Update thread count
				update_thread_count($insert_post['tid']);
				
				echo "done.<br />\n";
			}
			
			if($this->old_db->num_rows($query) == 0)
			{
				echo "There are no posts to import. Please press next to continue.";
				define('BACK_BUTTON', false);
			}
		}
		$import_session['start_posts'] += $import_session['posts_per_screen'];
		$output->print_footer();
	}
	
	function import_usergroups()
	{
		global $mybb, $output, $import_session, $db;

		$this->punbb_db_connect();

		// Get number of usergroups
		if(!isset($import_session['total_usergroups']))
		{
			$query = $this->old_db->simple_select("groups", "COUNT(*) as count");
			$import_session['total_usergroups'] = $this->old_db->fetch_field($query, 'count');
		}

		if($import_session['start_usergroups'])
		{
			// If there are more usergroups to do, continue, or else, move onto next module
			if($import_session['total_usergroups'] - $import_session['start_usergroups'] <= 0)
			{
				$import_session['disabled'][] = 'import_usergroups';
				return "finished";
			}
		}

		$output->print_header($this->modules[$import_session['module']]['name']);

		// Get number of posts per screen from form
		if(isset($mybb->input['usergroups_per_screen']))
		{
			$import_session['usergroups_per_screen'] = intval($mybb->input['usergroups_per_screen']);
		}
		
		if(empty($import_session['usergroups_per_screen']))
		{
			$import_session['start_usergroups'] = 0;
			echo "<p>Please select how many usergroups to import at a time:</p>
<p><input type=\"text\" name=\"usergroups_per_screen\" value=\"100\" /></p>";
			$import_session['autorefresh'] = "";
echo "<p>Do you want to automically continue to the next step until it's finished?:</p>
<p><input type=\"radio\" name=\"autorefresh\" value=\"yes\" checked=\"checked\" /> Yes <input type=\"radio\" name=\"autorefresh\" value=\"no\" /> No</p>";
			$output->print_footer($import_session['module'], 'module', 1);
		}
		else
		{
			// A bit of stats to show the progress of the current import
			echo "There are ".($import_session['total_usergroups']-$import_session['start_usergroups'])." usergroups left to import and ".round((($import_session['total_usergroups']-$import_session['start_usergroups'])/$import_session['usergroups_per_screen']))." pages left at a rate of {$import_session['usergroups_per_screen']} per page.<br /><br />";
			
			// Get only non-staff groups.
			$query = $this->old_db->simple_select("groups", "*", "g_id > 3", array('limit_start' => $import_session['start_usergroups'], 'limit' => $import_session['usergroups_per_screen']));
			while($group = $this->old_db->fetch_array($query))
			{
				if($usergroup['g_title'] == 'Administrators' || $usergroup['g_title'] == 'Moderators' || $usergroup['g_title'] == 'Guest' || $usergroup['g_title'] == 'Members')
				{
					continue;
				}

				echo "Inserting group #{$group['g_id']} as a custom usergroup...";
				
				// PunBB Values
				$insert_group['import_gid'] = $group['g_id'];				
				$insert_group['title'] = $group['g_title'];				
				$insert_group['canview'] = int_to_yesno($group['g_read_board']);				
				$insert_group['canpostthreads'] = int_to_yesno($usergroup['g_post_topics']);
				$insert_group['canpostreplys'] = int_to_yesno($usergroup['g_post_replies']);				
				$insert_group['caneditposts'] = int_to_yesno($usergroup['g_edit_posts']);
				$insert_group['candeleteposts'] = int_to_yesno($usergroup['g_delete_posts']);
				$insert_group['candeletethreads'] = int_to_yesno($usergroup['g_delete_topics']);				
				$insert_group['cansearch'] = int_to_yesno($usergroup['g_search']);
				$insert_group['canviewmemberlist'] = int_to_yesno($usergroup['g_search_users']);				

				// Default values
				$insert_group['caneditattachments'] = 'yes';
				$insert_group['canpostpolls'] = 'yes';
				$insert_group['canvotepolls'] = 'yes';
				$insert_group['canpostattachments'] = 'yes';
				$insert_group['canratethreads'] = 'yes';
				$insert_group['canviewthreads'] = 'yes';
				$insert_group['canviewprofiles'] = 'yes';
				$insert_group['candlattachments'] = 'yes';
				$insert_group['description'] = '';
				$insert_group['namestyle'] = '{username}';
				$insert_group['type'] = 2;
				$insert_group['stars'] = 0;
				$insert_group['starimage'] = 'images/star.gif';
				$insert_group['image'] = '';
				$insert_group['disporder'] = 0;
				$insert_group['isbannedgroup'] = 'no';				
				$insert_group['canusepms'] = 'yes';
				$insert_group['cansendpms'] = 'yes';
				$insert_group['cantrackpms'] = 'yes';
				$insert_group['candenypmreceipts'] = 'yes';
				$insert_group['pmquota'] = '0';
				$insert_group['maxpmrecipients'] = '5';
				$insert_group['cansendemail'] = 'yes';
				$insert_group['canviewcalendar'] = 'yes';
				$insert_group['canaddpublicevents'] = 'yes';
				$insert_group['canaddprivateevents'] = 'yes';
				$insert_group['canviewonline'] = 'yes';
				$insert_group['canviewwolinvis'] = 'no';
				$insert_group['canviewonlineips'] = 'no';
				$insert_group['cancp'] = 'no';
				$insert_group['issupermod'] = 'no';				
				$insert_group['canusercp'] = 'yes';
				$insert_group['canuploadavatars'] = 'yes';
				$insert_group['canratemembers'] = 'yes';
				$insert_group['canchangename'] = 'no';
				$insert_group['showforumteam'] = 'no';
				$insert_group['usereputationsystem'] = 'yes';
				$insert_group['cangivereputations'] = 'yes';
				$insert_group['reputationpower'] = '1';
				$insert_group['maxreputationsday'] = '5';
				$insert_group['candisplaygroup'] = 'yes';
				$insert_group['attachquota'] = '0';
				$insert_group['cancustomtitle'] = 'yes';

				$gid = $this->insert_usergroup($insert_group);

				// Restore connections
				$db->update_query("users", array('usergroup' => $gid), "import_usergroup = '{$group['group_id']}' OR import_displaygroup = '{$group['group_id']}'");

				$this->import_gids = null; // Force cache refresh

				echo "done.<br />\n";
			}
			
			if($this->old_db->num_rows($query) == 0)
			{
				echo "There are no usergroups to import. Please press next to continue.";
				define('BACK_BUTTON', false);
			}
		}
		$import_session['start_usergroups'] += $import_session['usergroups_per_screen'];
		$output->print_footer();
	}
	
	function import_settings()
	{
		global $mybb, $output, $import_session, $db;

		$this->punbb_db_connect();

		// What settings do we need to get and what is their MyBB equivalent?
		$settings_array = array(
			"o_board_title" => "bbname",
			"o_server_timezone" => "timezoneoffset",
			"o_time_format" => "timeformat",
			"o_date_format" => "dateformat",
			"o_timeout_online" => "wolcutoffmins",
			"o_show_version" => "showvernum",
			"o_smilies_sig" => "sigsmilies",
			"o_disp_topics_default" => "threadsperpage",
			"o_disp_posts_default" => "postsperpage",
			"o_quickpost" => "quickreply",
			"o_users_online" => "showwol",
			"o_show_dot" => "dotfolders",
			"o_gzip" => "gzipoutput",
			"o_avatars_dir" => "avataruploadpath",
			"o_avatars_height" => "maxavatardims",
			"o_avatars_width" => "maxavatardims",
			"o_avatars_size" => "avatarsize",
			"o_webmaster_email" => "adminemail",
			/* To be used at a later date
			"o_smtp_host" => "",
			"o_smtp_user" => "",
			"o_smtp_pass" => "", */
			"o_regs_allow" => "disableregs",
			"o_regs_verify" => "regtype",
			"o_maintenance" => "boardclosed",
			"o_maintenance_message" => "boardclosed_reason",
			"p_sig_bbcode" => "sigmycode",
			"p_sig_img_tag" => "sigimgcode",
			"p_sig_length" => "siglength"
		);
		$settings = "'".implode("','", array_keys($settings_array))."'";
		$int_to_yes_no = array(
			"o_show_version" => 1,
			"o_smilies_sig" => 1,
			"o_quickpost" => 1,
			"o_users_online" => 1,
			"o_show_dot" => 1,
			"o_gzip" => 1,
			"o_regs_allow" => 0,
			"o_maintenance" => 1,
			"p_sig_bbcode" => 1,
			"p_sig_img_tag" => 1
		);

		// Get number of settings
		if(!isset($import_session['total_settings']))
		{
			$query = $this->old_db->simple_select("config", "COUNT(*) as count", "conf_name IN({$settings})");
			$import_session['total_settings'] = $this->old_db->fetch_field($query, 'count');			
		}

		if($import_session['start_settings'])
		{
			// If there are more settings to do, continue, or else, move onto next module
			if($import_session['total_settings'] - $import_session['start_settings'] <= 0)
			{
				$import_session['disabled'][] = 'import_settings';
				rebuildsettings();
				return "finished";
			}
		}
		
		$output->print_header($this->modules[$import_session['module']]['name']);

		// Get number of settings per screen from form
		if(isset($mybb->input['settings_per_screen']))
		{
			$import_session['settings_per_screen'] = intval($mybb->input['settings_per_screen']);
		}

		if(empty($import_session['settings_per_screen']))
		{
			$import_session['start_settings'] = 0;
			echo "<p>Please select how many settings to modify at a time:</p>
<p><input type=\"text\" name=\"settings_per_screen\" value=\"200\" /></p>";
			$import_session['autorefresh'] = "";
echo "<p>Do you want to automically continue to the next step until it's finished?:</p>
<p><input type=\"radio\" name=\"autorefresh\" value=\"yes\" checked=\"checked\" /> Yes <input type=\"radio\" name=\"autorefresh\" value=\"no\" /> No</p>";
			$output->print_footer($import_session['module'], 'module', 1);
		}
		else
		{
			// A bit of stats to show the progress of the current import
			echo "There are ".($import_session['total_settings']-$import_session['start_settings'])." settings left to import and ".round((($import_session['total_settings']-$import_session['start_settings'])/$import_session['settings_per_screen']))." pages left at a rate of {$import_session['settings_per_screen']} per page.<br /><br />";

			$query = $this->old_db->simple_select("config", "conf_name, conf_value", "conf_name IN({$settings})", array('limit_start' => $import_session['start_settings'], 'limit' => $import_session['settings_per_screen']));
			while($setting = $this->old_db->fetch_array($query))
			{
				// punBB 1 values
				$name = $settings_array[$setting['conf_name']];
				$value = $setting['conf_value'];
				
				echo "Updating setting ".$setting['conf_name'])." from the punBB database to {$name} in the MyBB database... ";
				
				if($setting['conf_name'] == "o_timeout_online")
				{
					$value = ceil($value / 60);
				}
				
				if($setting['conf_name'] == "o_server_timezone")
				{
					$value = str_replace(".5", "", $value);
				}
				
				if($setting['conf_name'] == "o_avatars_width")
				{
					$avatar_setting = $value."x";
					echo "done.<br />\n";
					continue;
				}
				
				if($setting['conf_name'] == "o_avatars_height")
				{
					$value = $avatar_setting.$value;
					unset($avatar_setting);
				}
				
				if($setting['conf_name'] == "o_avatars_size")
				{
					$value = ceil($value / 1024);
				}
				
				if($setting['conf_name'] == "o_regs_verify")
				{
					if($value == 0)
					{
						$value = "randompass";
					}
					else
					{
						$value = "verify";
					}
				}
				
				if($setting['conf_name'] == "o_quickpost")
				{
					if($value == 0)
					{
						$value = "off";
					}
					else
					{
						$value = "on";
					}
				}
				
				if(($value == 0 || $value == 1) && isset($int_to_yes_no[$setting['conf_name']]))
				{
					$value = int_to_yes_no($value, $int_to_yes_no[$setting['conf_name']]);
				}
				
				$this->update_setting($name, $value);
				
				echo "done.<br />\n";
			}
			
			if($this->old_db->num_rows($query) == 0)
			{
				echo "There are no settings to update. Please press next to continue.";
				define('BACK_BUTTON', false);
			}
		}
		$import_session['start_settings'] += $import_session['settings_per_screen'];
		$output->print_footer();
	}
	
	/**
	 * Get a post from the punBB database
	 *
	 * @param int Post ID
	 * @return array The post
	 */
	function get_post($pid)
	{
		$query = $this->old_db->simple_select("posts", "*", "id = '{$pid}'");
		
		return $this->old_db->fetch_array($query);
	}
	
	/**
	 * Get a user from the punBB database
	 *
	 * @param string Username
	 * @return array If the uid is 0, returns an array of username as Guest.  Otherwise returns the user
	 */
	function get_user($username)
	{		
		if(empty($username))
		{
			return array(
				'username' => 'Guest',
				'id' => 0,
			);
		}
	
		$query = $this->old_db->simple_select("users", "id, username", "username = '{$username}'", array('limit' => 1));
		
		return $this->old_db->fetch_array($query);
	}
	
	/**
	 * Gets the time of the last post of a user from the punBB database
	 *
	 * @param int Forum ID
	 * @return array Post
	 */
	function get_last_post($fid)
	{
		$query = $this->old_db->simple_select("topics", "*", "forum_id = '{$fid}'", array('order_by' => 'posted', 'order_dir' => 'DESC', 'limit' => 1));
		$thread = $this->old_db->fetch_array($query);
		
		$query = $this->old_db->simple_select("posts", "*", "topic_id = '{$thread['id']}'", array('order_by' => 'posted', 'order_dir' => 'DESC', 'limit' => 1));
		return array(
			'post' => $this->old_db->fetch_array($query),
			'thread' => $thread
		);
	}
	
	/**
	 * Convert a punBB group ID into a MyBB group ID
	 *
	 * @param int Group ID
	 * @param boolean single group or multiple?
	 * @param boolean original group values?
	 * @return mixed group id(s)
	 */
	function get_group_id($gid, $not_multiple=false, $orig=false)
	{
		$settings = array();
		if($not_multiple == false)
		{
			$query = $this->old_db->simple_select("groups", "COUNT(*) as rows", "g_id = '{$gid}'");
			$settings = array('limit_start' => '1', 'limit' => $this->old_db->fetch_field($query, 'rows'));
		}
		
		$query = $this->old_db->simple_select("groups", "*", "g_id = '{$gid}'", $settings);
		
		$comma = $group = '';
		while($punbbgroup = $this->old_db->fetch_array($query))
		{
			if($orig == true)
			{
				$group .= $punbbgroup['g_id'].$comma;
			}
			else
			{
				$group .= $comma;
				switch($punbbgroup['g_id'])
				{
					case 1: // Administrator
						$group .= 4;
						break;
					case 2:
						$group .= 6;
						break;
					case 4:
						$group .= 2;
						break;	
					default:
						$gid = $this->get_import_gid($punbbgroup['g_id']);
						if($gid > 0)
						{
							// If there is an associated custom group...
							$group .= $gid;
						}
						else
						{
							// The lot
							$group .= 2;
						}
				}			
			}
			$comma = ',';

			if(!$query)
			{
				return 2; // Return regular registered user.
			}

			return $group;
		}
	}	
}

?>