<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains the definition for the library class for forum submission plugin
 *
 * This class provides all the functionality for the new assign module.
 *
 * @package assignsubmission_forum
 * @copyright 2012 NetSpot {@link http://www.netspot.com.au}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
// File area for online text submission assignment.
define('ASSIGNSUBMISSION_FORUM_FILEAREA', 'submissions_forum');

/**
 * library class for forum submission plugin extending submission plugin base class
 *
 * @package assignsubmission_forum
 * @copyright 2012 NetSpot {@link http://www.netspot.com.au}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_forum extends assign_submission_plugin {

    /**
     * Get the name of the online text submission plugin
     * @return string
     */
    public function get_name() {
        return get_string('forum', 'assignsubmission_forum');
    }


    /**
     * Get forum submission information from the database
     *
     * @param  int $submissionid
     * @return mixed
     */
    private function get_forum_submission($submissionid) {
        global $DB;

        return $DB->get_record('assignsubmission_forum', array('submission' => $submissionid));
    }

    /**
     * Get the settings for forum submission plugin
     *
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform) {
        global $COURSE, $DB;

        $forums = $DB->get_records('forum', array('course' => $COURSE->id));
        $options = [];
        foreach ($forums as $forum) {
            if (!($forum->type == "news")) {
                $options[$forum->id] = $forum->name;
            }
        }
        $settings[] = array('type' => 'select',
            'name' => 'forumtoeval',
            'description' => get_string('forumtoevaldescr', 'assignsubmission_forum'),
            'options' => $options);

        $name = get_string('forumtoeval', 'assignsubmission_forum');
        $mform->addElement('select', 'assignsubmission_forum_forumtoeval', $name, $options);

        $setforumid = $this->get_config('forumid');
        // Set default if forum is set and still exists.
        if (!is_bool($setforumid) && array_key_exists($setforumid, $options)) {
            $mform->setDefault('assignsubmission_forum_forumtoeval', $setforumid);
        }

        $mform->addHelpButton('assignsubmission_forum_forumtoeval',
            'forumtoeval',
            'assignsubmission_forum');
        $mform->disabledIf('assignsubmission_forum_forumtoeval',
            'assignsubmission_forum_enabled',
            'notchecked');

        if (!$options) {
            // Hidden element added to disable forum submission checkbox on empty $options.
            $mform->addElement('hidden', 'forums_available', !!$options);
            $mform->setType('forums_available', PARAM_BOOL);
            $mform->disabledIf('assignsubmission_forum_enabled', 'forums_available', 'neq', 1);
            // Set checkbox to unchecked (in case forum selected, all forums deleted, and then editing).
            $mform->setDefault('assignsubmission_forum_enabled', 0);
        }
    }

    /**
     * Save the settings for forum submission plugin
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {
        $this->set_config('forumid', $data->assignsubmission_forum_forumtoeval);

        return true;
    }

    /**
     * Add form elements for settings
     *
     * @param mixed $submission can be null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return true if elements were added to the form
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {
        global $DB, $USER, $CFG, $COURSE;

        // Forum functions we can use.
        require_once($CFG->dirroot.'/mod/forum/lib.php');

        // Forum we are evaluating.
        $forumid = $this->get_config('forumid');
        $forum = $DB->get_record('forum', array('id' => $forumid), '*', MUST_EXIST);

        // Course the forum is in.
        $course = $DB->get_record('course', array('id' => $forum->course), '*', MUST_EXIST);

        // Course modeule lookup from instance.
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        $posts = $this->forum_get_user_posts($forumid, $data->userid);

        $posttext = '';
        if (!$posts) {
            $posttext = get_string('default_post', 'assignsubmission_forum');
        } else {
            foreach ($posts as $post) {
                $discussion = $DB->get_record('forum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
                $posttext .= $this->forum_print_post($post, $discussion, $forum, $cm, $course,
                    false, false, false, false, false, false, false, "", "", null, true, null, true);
            }
        }
        $data->forum = $posttext;

        if (!isset($data->forum)) {
            $data->forum = $posttext;
        }
        if (!isset($data->forumformat)) {
            $data->forumformat = editors_get_preferred_format();
        }

        if ($submission) {
            $forumsubmission = $this->get_forum_submission($submission->id);
            if ($forumsubmission) {
                $data->forum = $forumsubmission->forum;
                $data->forumformat = $forumsubmission->onlineformat;
            }

        }

        $mform->addElement('static', 'description', 'Forum Posts: ', $posttext);

        return true;
    }

    /**
     * Editor format options
     *
     * @return array
     */
    private function get_edit_options() {
        $editoroptions = array(
            'noclean' => false,
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => $this->assignment->get_course()->maxbytes,
            'context' => $this->assignment->get_context(),
            'return_types' => FILE_INTERNAL | FILE_EXTERNAL
        );
        return $editoroptions;
    }

    /**
     * Save data to the database and trigger plagiarism plugin,
     * if enabled, to scan the uploaded content via events trigger
     *
     * @param stdClass $submission
     * @param stdClass $data
     * @return bool
     */
    public function save(stdClass $submission, stdClass $data) {
        global $USER, $DB, $CFG;

        // Forum functions we can use.
        require_once($CFG->dirroot.'/mod/forum/lib.php');

        // Forum we are evaluating.
        $forumid = $this->get_config('forumid');
        $forum = $DB->get_record('forum', array('id' => $forumid), '*', MUST_EXIST);

        // Course the forum is in.
        $course = $DB->get_record('course', array('id' => $forum->course), '*', MUST_EXIST);

        // Course modeule lookup from instance.
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        $posts = $this->forum_get_user_posts($forumid, $data->userid);

        $posttext = '';
        if (!$posts) {
            $posttext = get_string('default_post', 'assignsubmission_forum');
        } else {
            foreach ($posts as $post) {
                $discussion = $DB->get_record('forum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
                $posttext .= $this->forum_print_post($post, $discussion, $forum, $cm, $course,
                    false, false, false, false, false, false, false, "", "", null, true, null, true);
            }
        }

        $forumsubmission = $this->get_forum_submission($submission->id);

        if ($forumsubmission) {

            $forumsubmission->forum = $posttext;
            $forumsubmission->onlineformat = 1;
            $params['objectid'] = $forumsubmission->id;
            $updatestatus = $DB->update_record('assignsubmission_forum', $forumsubmission);
            return $updatestatus;
        } else {

            $forumsubmission = new stdClass();
            $forumsubmission->forum = $posttext;
            $forumsubmission->onlineformat = 1;

            $forumsubmission->submission = $submission->id;
            $forumsubmission->assignment = $this->assignment->get_instance()->id;
            $forumsubmission->id = $DB->insert_record('assignsubmission_forum', $forumsubmission);
            return $forumsubmission->id > 0;
        }
    }

    /**
     * Return a list of the text fields that can be imported/exported by this plugin
     *
     * @return array An array of field names and descriptions. (name=>description, ...)
     */
    public function get_editor_fields() {
        return array('forum' => get_string('pluginname', 'assignsubmission_comments'));
    }

    /**
     * Get the saved text content from the editor
     *
     * @param string $name
     * @param int $submissionid
     * @return string
     */
    public function get_editor_text($name, $submissionid) {
        if ($name == 'forum') {
            $forumsubmission = $this->get_forum_submission($submissionid);
            if ($forumsubmission) {
                return $forumsubmission->forum;
            }
        }

        return '';
    }

    /**
     * Get the content format for the editor
     *
     * @param string $name
     * @param int $submissionid
     * @return int
     */
    public function get_editor_format($name, $submissionid) {
        if ($name == 'forum') {
            $forumsubmission = $this->get_forum_submission($submissionid);
            if ($forumsubmission) {
                return $forumsubmission->onlineformat;
            }
        }

        return 0;
    }


    /**
     * Display Summary
     *
     * @param stdClass $submission
     * @param bool $showviewlink - If the summary has been truncated set this to true
     * @return string
     */
    public function view_summary(stdClass $submission, & $showviewlink) {
        global $CFG;

        $forumsubmission = $this->get_forum_submission($submission->id);
        // Always show the view link.
        $showviewlink = true;

        if ($forumsubmission) {
            $text = $forumsubmission->forum;

            $shorttext = shorten_text($text, 140);
            $plagiarismlinks = '';

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir . '/plagiarismlib.php');

                $plagiarismlinks .= plagiarism_get_links(array('userid' => $submission->userid,
                    'content' => trim($text),
                    'cmid' => $this->assignment->get_course_module()->id,
                    'course' => $this->assignment->get_course()->id,
                    'assignment' => $submission->assignment));
            }

            return $shorttext . $plagiarismlinks;

        }
        return '';
    }

    /**
     * Produce a list of files suitable for export that represent this submission.
     *
     * @param stdClass $submission - For this is the submission data
     * @param stdClass $user - This is the user record for this submission
     * @return array - return an array of files indexed by filename
     */
    public function get_files(stdClass $submission, stdClass $user) {
        global $DB;

        $files = array();
        $forumsubmission = $this->get_forum_submission($submission->id);

        if ($forumsubmission) {
            $finaltext = $this->assignment->download_rewrite_pluginfile_urls($forumsubmission->forum, $user, $this);
            $formattedtext = format_text($finaltext,
                $forumsubmission->onlineformat,
                array('context' => $this->assignment->get_context()));
            $head = '<head><meta charset="UTF-8"></head>';
            $submissioncontent = '<!DOCTYPE html><html>' . $head . '<body>'. $formattedtext . '</body></html>';

            $filename = get_string('forumfilename', 'assignsubmission_forum');
            $files[$filename] = array($submissioncontent);

            $fs = get_file_storage();

            $fsfiles = $fs->get_area_files($this->assignment->get_context()->id,
                'assignsubmission_forum',
                ASSIGNSUBMISSION_FORUM_FILEAREA,
                $submission->id,
                'timemodified',
                false);

            foreach ($fsfiles as $file) {
                $files[$file->get_filename()] = $file;
            }
        }

        return $files;
    }

    /**
     * Display the saved text content from the editor in the view table
     *
     * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission) {
        $result = '';

        $forumsubmission = $this->get_forum_submission($submission->id);

        if ($forumsubmission) {

            // Render for portfolio API.
            $result .= $this->assignment->render_editor_content(ASSIGNSUBMISSION_FORUM_FILEAREA,
                $forumsubmission->submission,
                $this->get_type(),
                'forum',
                'assignsubmission_forum');

        }

        return $result;
    }

    /**
     * Return true if this plugin can upgrade an old Moodle 2.2 assignment of this type and version.
     *
     * @param string $type old assignment subtype
     * @param int $version old assignment version
     * @return bool True if upgrade is possible
     */
    public function can_upgrade($type, $version) {
        return false;
    }

    /**
     * The assignment has been deleted - cleanup
     *
     * @return bool
     */
    public function delete_instance() {
        global $DB;
        $DB->delete_records('assignsubmission_forum',
            array('assignment' => $this->assignment->get_instance()->id));

        return true;
    }

    /**
     * No text is set for this plugin
     *
     * @param stdClass $submission
     * @return bool
     */
    public function is_empty(stdClass $submission) {
        $forumsubmission = $this->get_forum_submission($submission->id);

        return empty($forumsubmission->forum);
    }

    /**
     * Copy the student's submission from a previous submission. Used when a student opts to base their resubmission
     * on the last submission.
     * @param stdClass $sourcesubmission
     * @param stdClass $destsubmission
     */
    public function copy_submission(stdClass $sourcesubmission, stdClass $destsubmission) {
        global $DB;

        // Copy the assignsubmission_forum record.
        $forumsubmission = $this->get_forum_submission($sourcesubmission->id);
        if ($forumsubmission) {
            unset($forumsubmission->id);
            $forumsubmission->submission = $destsubmission->id;
            $DB->insert_record('assignsubmission_forum', $forumsubmission);
        }
        return true;
    }

    /**
     * Compare word count of forum submission to word limit, and return result.
     *
     * @param string $submissiontext forum submission text from editor
     * @return string Error message if limit is enabled and exceeded, otherwise null
     */
    public function check_word_count($submissiontext) {
        global $OUTPUT;

        $wordlimitenabled = $this->get_config('wordlimitenabled');
        $wordlimit = $this->get_config('wordlimit');

        if ($wordlimitenabled == 0) {
            return null;
        }

        // Count words and compare to limit.
        $wordcount = count_words($submissiontext);
        if ($wordcount <= $wordlimit) {
            return null;
        } else {
            $errormsg = get_string('wordlimitexceeded', 'assignsubmission_forum',
                array('limit' => $wordlimit, 'count' => $wordcount));
            return $OUTPUT->error_text($errormsg);
        }
    }

    /**
     * Get all the posts for a user in a specific forum discussion suitable for forum_print_post
     *
     * @global object
     * @global object
     * @uses CONTEXT_MODULE
     * @return array
     */
    public function forum_get_user_posts($forumid, $userid) {
        global $CFG, $DB;

        $timedsql = "";
        $params = array($forumid, $userid);

        if (!empty($CFG->forum_enabletimedposts)) {
            $cm = get_coursemodule_from_instance('forum', $forumid);
            if (!has_capability('mod/forum:viewhiddentimedposts' , context_module::instance($cm->id))) {
                $now = time();
                $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
                $params[] = $now;
                $params[] = $now;
            }
        }

        $allnames = get_all_user_name_fields(true, 'u');
        return $DB->get_records_sql("SELECT p.*, d.forum, $allnames, u.email, u.picture, u.imagealt
                              FROM {forum} f
                                   JOIN {forum_discussions} d ON d.forum = f.id
                                   JOIN {forum_posts} p       ON p.discussion = d.id
                                   JOIN {user} u              ON u.id = p.userid
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql
                          ORDER BY p.discussion DESC, p.id ASC", $params);
    }

    /**
     * Print a forum post
     *
     * @global object
     * @global object
     * @uses FORUM_MODE_THREADED
     * @uses PORTFOLIO_FORMAT_PLAINHTML
     * @uses PORTFOLIO_FORMAT_FILE
     * @uses PORTFOLIO_FORMAT_RICHHTML
     * @uses PORTFOLIO_ADD_TEXT_LINK
     * @uses CONTEXT_MODULE
     * @param object $post The post to print.
     * @param object $discussion
     * @param object $forum
     * @param object $cm
     * @param object $course
     * @param boolean $ownpost Whether this post belongs to the current user.
     * @param boolean $reply Whether to print a 'reply' link at the bottom of the message.
     * @param boolean $edit Whether to print a 'edit' link at the bottom of the message.
     * @param boolean $delete Whether to print a 'delete' link at the bottom of the message.
     * @param boolean $split Whether to print a 'split' link at the bottom of the message.
     * @param boolean $export Whether to print a 'export to...' link at the bottom of the message.
     * @param boolean $link Just print a shortened version of the post as a link to the full post.
     * @param string $footer Extra stuff to print after the message.
     * @param string $highlight Space-separated list of terms to highlight.
     * @param int $post_read true, false or -99. If we already know whether this user
     *          has read this post, pass that in, otherwise, pass in -99, and this
     *          function will work it out.
     * @param boolean $dummyifcantsee When forum_user_can_see_post says that
     *          the current user can't see this post, if this argument is true
     *          (the default) then print a dummy 'you can't see this post' post.
     *          If false, don't output anything at all.
     * @param bool|null $istracked
     * @return void
     */
    private function forum_print_post($post, $discussion, $forum, &$cm, $course, $ownpost=false, $reply=false,
                                      $edit=false, $delete=false, $split=false, $export=false, $link=false,
                              $footer="", $highlight="", $postisread=null, $dummyifcantsee=true, $istracked=null, $return=false) {
        global $USER, $CFG, $OUTPUT;

        require_once($CFG->libdir . '/filelib.php');

        // String cache.
        static $str;

        $modcontext = context_module::instance($cm->id);

        $post->course = $course->id;
        $post->forum  = $forum->id;
        $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php',
            $modcontext->id, 'mod_forum', 'post', $post->id);
        if (!empty($CFG->enableplagiarism)) {
            require_once($CFG->libdir.'/plagiarismlib.php');
            $post->message .= plagiarism_get_links(array('userid' => $post->userid,
                'content' => $post->message,
                'cmid' => $cm->id,
                'course' => $post->course,
                'forum' => $post->forum));
        }

        // Caching.
        if (!isset($cm->cache)) {
            $cm->cache = new stdClass;
        }

        if (!isset($cm->cache->caps)) {
            $cm->cache->caps = array();
            $cm->cache->caps['mod/forum:viewdiscussion']   = has_capability('mod/forum:viewdiscussion', $modcontext);
            $cm->cache->caps['moodle/site:viewfullnames']  = has_capability('moodle/site:viewfullnames', $modcontext);
            $cm->cache->caps['mod/forum:editanypost']      = has_capability('mod/forum:editanypost', $modcontext);
            $cm->cache->caps['mod/forum:splitdiscussions'] = has_capability('mod/forum:splitdiscussions', $modcontext);
            $cm->cache->caps['mod/forum:deleteownpost']    = has_capability('mod/forum:deleteownpost', $modcontext);
            $cm->cache->caps['mod/forum:deleteanypost']    = has_capability('mod/forum:deleteanypost', $modcontext);
            $cm->cache->caps['mod/forum:viewanyrating']    = has_capability('mod/forum:viewanyrating', $modcontext);
            $cm->cache->caps['mod/forum:exportpost']       = has_capability('mod/forum:exportpost', $modcontext);
            $cm->cache->caps['mod/forum:exportownpost']    = has_capability('mod/forum:exportownpost', $modcontext);
        }

        if (!isset($cm->uservisible)) {
            $cm->uservisible = \core_availability\info_module::is_user_visible($cm, 0, false);
        }

        if ($istracked && is_null($postisread)) {
            $postisread = forum_tp_is_post_read($USER->id, $post);
        }

        if (!forum_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            $output = '';
            if (!$dummyifcantsee) {
                if ($return) {
                    return $output;
                }
                echo $output;
                return;
            }
            $output .= html_writer::tag('a', '', array('id' => 'p'.$post->id));
            $output .= html_writer::start_tag('div', array('class' => 'forumpost clearfix',
                'role' => 'region',
                'aria-label' => get_string('hiddenforumpost', 'forum')));
            $output .= html_writer::start_tag('div', array('class' => 'row header'));
            $output .= html_writer::tag('div', '', array('class' => 'left picture'));
            if ($post->parent) {
                $output .= html_writer::start_tag('div', array('class' => 'topic'));
            } else {
                $output .= html_writer::start_tag('div', array('class' => 'topic starter'));
            }
            $output .= html_writer::tag('div', get_string('forumsubjecthidden', 'forum'), array('class' => 'subject',
                'role' => 'header')); // Subject.
            $output .= html_writer::tag('div', get_string('forumauthorhidden', 'forum'), array('class' => 'author',
                'role' => 'header')); // Author.
            $output .= html_writer::end_tag('div');
            $output .= html_writer::end_tag('div'); // Row.
            $output .= html_writer::start_tag('div', array('class' => 'row'));
            $output .= html_writer::tag('div', '&nbsp;', array('class' => 'left side')); // Groups.
            $output .= html_writer::tag('div', get_string('forumbodyhidden', 'forum'), array('class' => 'content')); // Content.
            $output .= html_writer::end_tag('div'); // Row.
            $output .= html_writer::end_tag('div'); // Forumpost.

            if ($return) {
                return $output;
            }
            echo $output;
            return;
        }

        if (empty($str)) {
            $str = new stdClass;
            $str->edit         = get_string('edit', 'forum');
            $str->delete       = get_string('delete', 'forum');
            $str->reply        = get_string('reply', 'forum');
            $str->parent       = get_string('parent', 'forum');
            $str->pruneheading = get_string('pruneheading', 'forum');
            $str->prune        = get_string('prune', 'forum');
            $str->displaymode     = get_user_preferences('forum_displaymode', $CFG->forum_displaymode);
            $str->markread     = get_string('markread', 'forum');
            $str->markunread   = get_string('markunread', 'forum');
        }

        $discussionlink = new moodle_url('/mod/forum/discuss.php', array('d' => $post->discussion));

        // Build an object that represents the posting user.
        $postuser = new stdClass;
        $postuserfields = explode(',', user_picture::fields());
        $postuser = username_load_fields_from_object($postuser, $post, null, $postuserfields);
        $postuser->id = $post->userid;
        $postuser->fullname    = fullname($postuser, $cm->cache->caps['moodle/site:viewfullnames']);
        $postuser->profilelink = new moodle_url('/user/view.php', array('id' => $post->userid, 'course' => $course->id));

        // Prepare the groups the posting user belongs to.
        if (isset($cm->cache->usersgroups)) {
            $groups = array();
            if (isset($cm->cache->usersgroups[$post->userid])) {
                foreach ($cm->cache->usersgroups[$post->userid] as $gid) {
                    $groups[$gid] = $cm->cache->groups[$gid];
                }
            }
        } else {
            $groups = groups_get_all_groups($course->id, $post->userid, $cm->groupingid);
        }

        // Prepare the attachements for the post, files then images.
        list($attachments, $attachedimages) = forum_print_attachments($post, $cm, 'separateimages');

        // Determine if we need to shorten this post.
        $shortenpost = ($link && (strlen(strip_tags($post->message)) > $CFG->forum_longpost));

        // Prepare an array of commands.
        $commands = array();

        // SPECIAL CASE: The front page can display a news item post to non-logged in users.
        // Don't display the mark read / unread controls in this case.
        if ($istracked && $CFG->forum_usermarksread && isloggedin()) {
            $url = new moodle_url($discussionlink, array('postid' => $post->id, 'mark' => 'unread'));
            $text = $str->markunread;
            if (!$postisread) {
                $url->param('mark', 'read');
                $text = $str->markread;
            }
            if ($str->displaymode == FORUM_MODE_THREADED) {
                $url->param('parent', $post->parent);
            } else {
                $url->set_anchor('p'.$post->id);
            }
            $commands[] = array('url' => $url, 'text' => $text);
        }

        // Zoom in to the parent specifically.
        if ($post->parent) {
            $url = new moodle_url($discussionlink);
            if ($str->displaymode == FORUM_MODE_THREADED) {
                $url->param('parent', $post->parent);
            } else {
                $url->set_anchor('p'.$post->parent);
            }
            $commands[] = array('url' => $url, 'text' => $str->parent);
        }

        // Hack for allow to edit news posts those are not displayed yet until they are displayed.
        $age = time() - $post->created;
        if (!$post->parent && $forum->type == 'news' && $discussion->timestart > time()) {
            $age = 0;
        }

        if ($forum->type == 'single' and $discussion->firstpost == $post->id and $edit) {
            if (has_capability('moodle/course:manageactivities', $modcontext)) {
                // The first post in single simple is the forum description.
                $commands[] = array('url' => new moodle_url('/course/modedit.php',
                    array('update' => $cm->id, 'sesskey' => sesskey(), 'return' => 1)), 'text' => $str->edit);
            }
        } else if ($edit && (($ownpost && $age < $CFG->maxeditingtime) || $cm->cache->caps['mod/forum:editanypost'])) {
            $commands[] = array('url' => new moodle_url('/mod/forum/post.php', array('edit' => $post->id)), 'text' => $str->edit);
        }

        if ($split && $cm->cache->caps['mod/forum:splitdiscussions'] && $post->parent && $forum->type != 'single') {
            $commands[] = array('url' => new moodle_url('/mod/forum/post.php',
                array('prune' => $post->id)), 'text' => $str->prune, 'title' => $str->pruneheading);
        }

        if ($delete && (($ownpost && $age < $CFG->maxeditingtime && $cm->cache->caps['mod/forum:deleteownpost']) ||
            $cm->cache->caps['mod/forum:deleteanypost'])) {
            $commands[] = array('url' => new moodle_url('/mod/forum/post.php',
                array('delete' => $post->id)), 'text' => $str->delete);
        }

        if ($reply) {
            $commands[] = array('url' => new moodle_url('/mod/forum/post.php#mformforum',
                array('reply' => $post->id)), 'text' => $str->reply);
        }

        if ($export && ($CFG->enableportfolios && ($cm->cache->caps['mod/forum:exportpost']
                || ($ownpost && $cm->cache->caps['mod/forum:exportownpost'])))) {
            $p = array('postid' => $post->id);
            require_once($CFG->libdir.'/portfoliolib.php');
            $button = new portfolio_add_button();
            $button->set_callback_options('forum_portfolio_caller', array('postid' => $post->id), 'mod_forum');
            if (empty($attachments)) {
                $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
            } else {
                $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
            }

            $porfoliohtml = $button->to_html(PORTFOLIO_ADD_TEXT_LINK);
            if (!empty($porfoliohtml)) {
                $commands[] = $porfoliohtml;
            }
        }
        // Finished building commands.
        // Begin output.

        $output  = '';

        if ($istracked) {
            if ($postisread) {
                $forumpostclass = ' read';
            } else {
                $forumpostclass = ' unread';
                $output .= html_writer::tag('a', '', array('name' => 'unread'));
            }
        } else {
            // Ignore trackign status if not tracked or tracked param missing.
            $forumpostclass = '';
        }

        $topicclass = '';
        if (empty($post->parent)) {
            $topicclass = ' firstpost starter';
        }

        $postbyuser = new stdClass;
        $postbyuser->post = $post->subject;
        $postbyuser->user = $postuser->fullname;
        $discussionbyuser = get_string('postbyuser', 'forum', $postbyuser);
        $output .= html_writer::tag('a', '', array('id' => 'p'.$post->id));
        $output .= html_writer::start_tag('div', array('class' => 'forumpost clearfix'.$forumpostclass.$topicclass,
            'role' => 'region',
            'aria-label' => $discussionbyuser));
        $output .= html_writer::start_tag('div', array('class' => 'row header clearfix'));
        $output .= html_writer::start_tag('div', array('class' => 'left picture'));
        $output .= $OUTPUT->user_picture($postuser, array('courseid' => $course->id));
        $output .= html_writer::end_tag('div');

        $output .= html_writer::start_tag('div', array('class' => 'topic'.$topicclass));

        $postsubject = $post->subject;
        if (empty($post->subjectnoformat)) {
            $postsubject = format_string($postsubject);
        }
        $output .= html_writer::tag('div', $postsubject, array('class' => 'subject',
            'role' => 'heading',
            'aria-level' => '2'));

        $by = new stdClass();
        $by->name = html_writer::link($postuser->profilelink, $postuser->fullname);
        $by->date = userdate($post->modified);
        $output .= html_writer::tag('div', get_string('bynameondate', 'forum', $by), array('class' => 'author',
            'role' => 'heading',
            'aria-level' => '2'));

        $output .= html_writer::end_tag('div'); // Topic.
        $output .= html_writer::end_tag('div'); // Row.

        $output .= html_writer::start_tag('div', array('class' => 'row maincontent clearfix'));
        $output .= html_writer::start_tag('div', array('class' => 'left'));

        $groupoutput = '';
        if ($groups) {
            $groupoutput = print_group_picture($groups, $course->id, false, true, true);
        }
        if (empty($groupoutput)) {
            $groupoutput = '&nbsp;';
        }
        $output .= html_writer::tag('div', $groupoutput, array('class' => 'grouppictures'));

        $output .= html_writer::end_tag('div'); // Left side.
        $output .= html_writer::start_tag('div', array('class' => 'no-overflow'));
        $output .= html_writer::start_tag('div', array('class' => 'content'));

        $options = new stdClass;
        $options->para    = false;
        $options->trusted = $post->messagetrust;
        $options->context = $modcontext;
        $options->filter = false;

        if ($shortenpost) {
            // Prepare shortened version by filtering the text then shortening it.
            $postclass    = 'shortenedpost';
            $postcontent  = format_text($post->message, $post->messageformat, $options);
            $postcontent  = shorten_text($postcontent, $CFG->forum_shortpost);
            $postcontent .= html_writer::link($discussionlink, get_string('readtherest', 'forum'));
            $postcontent .= html_writer::tag('div', '('.get_string('numwords', 'moodle', count_words($post->message)).')',
                array('class' => 'post-word-count'));
        } else {
            // Prepare whole post.
            $postclass    = 'fullpost';
            $postcontent  = format_text($post->message, $post->messageformat, $options, $course->id);
            if (!empty($highlight)) {
                $postcontent = highlight($highlight, $postcontent);
            }
            if (!empty($forum->displaywordcount)) {
                $postcontent .= html_writer::tag('div', get_string('numwords', 'moodle', count_words($post->message)),
                    array('class' => 'post-word-count'));
            }
            $postcontent .= html_writer::tag('div', $attachedimages, array('class' => 'attachedimages'));
        }

        // Output the post content.
        $output .= html_writer::tag('div', $postcontent, array('class' => 'posting '.$postclass));
        $output .= html_writer::end_tag('div'); // Content.
        $output .= html_writer::end_tag('div'); // Content mask.
        $output .= html_writer::end_tag('div'); // Row.

        $output .= html_writer::start_tag('div', array('class' => 'row side'));
        $output .= html_writer::tag('div', '&nbsp;', array('class' => 'left'));
        $output .= html_writer::start_tag('div', array('class' => 'options clearfix'));

        if (!empty($attachments)) {
            $output .= html_writer::tag('div', $attachments, array('class' => 'attachments'));
        }

        // Output ratings.
        if (!empty($post->rating)) {
            $output .= html_writer::tag('div', $OUTPUT->render($post->rating), array('class' => 'forum-post-rating'));
        }

        // Output the commands.
        $commandhtml = array();
        foreach ($commands as $command) {
            if (is_array($command)) {
                $commandhtml[] = html_writer::link($command['url'], $command['text']);
            } else {
                $commandhtml[] = $command;
            }
        }
        $output .= html_writer::tag('div', implode(' | ', $commandhtml), array('class' => 'commands'));

        // Output link to post if required.
        if ($link && forum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext)) {
            if ($post->replies == 1) {
                $replystring = get_string('repliesone', 'forum', $post->replies);
            } else {
                $replystring = get_string('repliesmany', 'forum', $post->replies);
            }

            $output .= html_writer::start_tag('div', array('class' => 'link'));
            $output .= html_writer::link($discussionlink, get_string('discussthistopic', 'forum'));
            $output .= '&nbsp;('.$replystring.')';
            $output .= html_writer::end_tag('div'); // Link.
        }

        // Output footer if required.
        if ($footer) {
            $output .= html_writer::tag('div', $footer, array('class' => 'footer'));
        }

        // Close remaining open divs.
        $output .= html_writer::end_tag('div'); // Content.
        $output .= html_writer::end_tag('div'); // Row.
        $output .= html_writer::end_tag('div'); // Forumpost.

        // Mark the forum post as read if required.
        if ($istracked && !$CFG->forum_usermarksread && !$postisread) {
            forum_tp_mark_post_read($USER->id, $post, $forum->id);
        }

        if ($return) {
            return $output;
        }
        echo $output;
        return;
    }

}


