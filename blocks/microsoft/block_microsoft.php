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
 * Block Microsoft block
 * @package block_microsoft
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft, Inc. (http://microsoft.com/)
 */

use local_o365\feature\usergroups\utils;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/blocks/microsoft/lib.php');
require_once($CFG->dirroot.'/auth/oidc/lib.php');
require_once($CFG->dirroot.'/local/o365/lib.php');

/**
 * Microsoft Block.
 */
class block_microsoft extends block_base {
    /**
     * Initialize plugin.
     */
    public function init() {
        $this->title = get_string('microsoft', 'block_microsoft');
        $this->globalconfig = get_config('block_microsoft');
    }

    /**
     * Whether the block has settings.
     *
     * @return bool Has settings or not.
     */
    public function has_config() {
        return true;
    }

    /**
     * Get the content of the block.
     *
     * @return stdClass|stdObject|null
     */
    public function get_content() {
        global $USER, $DB;

        if (!isloggedin()) {
            return null;
        }

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        try {
            $o365connected = \local_o365\utils::is_o365_connected($USER->id);
            if ($o365connected === true) {
                $this->content->text .= $this->get_user_content_connected();
            } else {
                $connection = $DB->get_record('local_o365_connections', ['muserid' => $USER->id]);
                if (!empty($connection) && (auth_oidc_connectioncapability($USER->id, 'connect') ||
                        local_o365_connectioncapability($USER->id, 'link'))) {
                    $uselogin = (!empty($connection->uselogin)) ? true : false;
                    $this->content->text .= $this->get_user_content_matched($connection->aadupn, $uselogin);
                } else {
                    $this->content->text .= $this->get_user_content_not_connected();
                }
            }

            $this->content->text .= $this->get_course_content();
        } catch (\Exception $e) {
            $this->content->text = $e->getMessage();
        }

        return $this->content;
    }

    /**
     * Get block content for an unconnected but matched user.
     *
     * @param string $o365account The o365 account the user was matched to.
     * @param bool $uselogin Whether the match includes login change.
     * @return string Block content about user.
     */
    protected function get_user_content_matched($o365account, $uselogin = false) {
        $html = '';

        $langmatched = get_string('o365matched_title', 'block_microsoft');
        $html .= '<h5>' . $langmatched . '</h5>';

        $langmatcheddesc = get_string('o365matched_desc', 'block_microsoft', $o365account);
        $html .= '<p>' . $langmatcheddesc . '</p>';

        $langlogin = get_string('logintoo365', 'block_microsoft');
        $html .= '<p>' . get_string('o365matched_complete_authreq', 'block_microsoft') . '</p>';

        if ($uselogin === true) {
            $html .= '<p>' . html_writer::link(new moodle_url('/local/o365/ucp.php'), $langlogin) . '</p>';
        } else {
            $html .= '<p>' . html_writer::link(new moodle_url('/local/o365/ucp.php?action=connecttoken'), $langlogin) . '</p>';
        }

        return $html;
    }

    /**
     * Get contents of the course section in the block.
     *
     * @return string
     */
    protected function get_course_content() {
        global $COURSE;

        if ($COURSE->id == SITEID) {
            return '';
        }

        $courseid = $COURSE->id;
        $groupsenabled = utils::is_enabled();
        $iscoursecontext = $this->page->context instanceof \context_course && $this->page->context->instanceid !== SITEID;
        $courseisgroupenabled = utils::course_is_group_enabled($courseid);
        $config = (array)get_config('block_microsoft');

        $html = '';
        $items = [];

        $courseheaderdisplayed = false;
        $o365record = null;

        if (has_capability('local/o365:teamowner', $this->page->context)) {

            $courseheaderdisplayed = true;
        }

        if (!empty($config['settings_showcoursegroup'])) {
            list($courseheader, $o365record) = $this->get_course_header_and_o365object($courseid);
            $html .= $courseheader;

            // Link to course sync options.
            if (has_capability('local/o365:teamowner', $this->page->context)) {
                $createteams = get_config('local_o365', 'createteams');
                $allowedmanageteamsyncpercourse = get_config('local_o365', 'createteams_per_course');
                if ($createteams == 'oncustom' && $allowedmanageteamsyncpercourse) {
                    $configuresyncurl = new moodle_url('/blocks/microsoft/configure_sync.php',
                        ['course' => $courseid]);
                    $items[] = html_writer::link($configuresyncurl, get_string('configure_sync', 'block_microsoft'),
                        ['class' => 'servicelink block_microsoft_sync']);
                }
            }
        }

        if ($iscoursecontext && $groupsenabled && $courseisgroupenabled) {
            $canmanage = (has_capability('local/o365:managegroups', $this->page->context) === true) ? true : false;
            $canview = (is_enrolled($this->page->context)
              && has_capability('local/o365:viewgroups', $this->page->context)) ? true : false;

            if ($canmanage === true || $canview === true) {
                if (!empty($config['settings_showcoursegroup'])) {
                    if (!$courseheaderdisplayed) {
                        list($courseheader, $o365record) = $this->get_course_header_and_o365object($courseid);
                        $html .= $courseheader;
                    }

                    if ($o365record) {
                        // Links to course features.
                        $groupurls = utils::get_group_urls($courseid, 0);
                        foreach (['team', 'conversations', 'onedrive', 'calendar', 'notebook'] as $feature) {
                            if (!isset($groupurls[$feature])) {
                                continue;
                            }

                            if ($feature == 'team' && !utils::course_is_group_feature_enabled($courseid, 'team')) {
                                continue;
                            }

                            $url = new moodle_url($groupurls[$feature]);
                            $resourcename = get_string('course_feature_' . $feature, 'block_microsoft');
                            $items[] = html_writer::link($url, $resourcename,
                                ['target' => '_blank', 'class' => 'servicelink block_microsoft_' . $feature]);
                        }

                        // Link to course reset options.
                        if (has_capability('moodle/course:reset', $this->page->context)) {
                            switch (get_config('local_o365', 'course_reset_teams')) {
                                case TEAMS_GROUP_COURSE_RESET_SITE_SETTING_PER_COURSE:
                                    // Allow user to configure reset actions.
                                    $configurereseturl = new moodle_url('/blocks/microsoft/configure_reset.php',
                                        ['course' => $courseid]);
                                    if (utils::course_is_group_feature_enabled($courseid, 'team')) {
                                        $items[] = html_writer::link($configurereseturl,
                                            get_string('configure_reset_team', 'block_microsoft'),
                                            ['class' => 'servicelink block_microsoft_reset']);
                                    } else {
                                        $items[] = html_writer::link($configurereseturl,
                                            get_string('configure_reset_group', 'block_microsoft'),
                                            ['class' => 'servicelink block_microsoft_reset']);
                                    }

                                    break;
                                case TEAMS_GROUP_COURSE_RESET_SITE_SETTING_DISCONNECT_AND_CREATE_NEW:
                                    // Force archive, show notification.
                                    if (utils::course_is_group_feature_enabled($courseid, 'team')) {
                                        $items[] = html_writer::span(get_string('course_reset_disconnect_team', 'block_microsoft'),
                                            'servicelink block_microsoft_reset');
                                    } else {
                                        $items[] = html_writer::span(get_string('course_reset_disconnect_group', 'block_microsoft'),
                                            'servicelink block_microsoft_reset');
                                    }

                                    break;
                                default:
                                    // Force do nothing, show notification.
                                    if (utils::course_is_group_feature_enabled($courseid, 'team')) {
                                        $items[] = html_writer::span(get_string('course_reset_do_nothing_team', 'block_microsoft'),
                                            'servicelink block_microsoft_reset');
                                    } else {
                                        $items[] = html_writer::span(get_string('course_reset_do_nothing_group', 'block_microsoft'),
                                            'servicelink block_microsoft_reset');
                                    }
                            }
                        }
                    }
                }
            }
        }

        return $html . html_writer::alist($items);
    }

    /**
     * Return the course header text and o365_object record for the course with the given ID.
     *
     * @param int $courseid
     *
     * @return array
     */
    private function get_course_header_and_o365object(int $courseid) {
        global $DB;

        $o365record = null;

        if (utils::course_is_group_feature_enabled($courseid, 'team')) {
            if ($o365record = $DB->get_record('local_o365_objects',
                ['type' => 'group', 'subtype' => 'courseteam', 'moodleid' => $courseid])) {
                // The course is configured to be connected to a Team, and is connected.
                $html = html_writer::tag('h5', get_string('course_connected_to_team', 'block_microsoft'));
            } else {
                // The course is configured to be connected to a Team, but the Team cannot be found.
                $html = html_writer::tag('h5', get_string('course_connected_to_team_missing', 'block_microsoft'));
            }
        } else if (utils::course_is_group_enabled($courseid)) {
            if ($o365record = $DB->get_record('local_o365_objects',
                ['type' => 'group', 'subtype' => 'course', 'moodleid' => $courseid])) {
                // The course is configured to be connected to a group, and is connected.
                $html = html_writer::tag('h5', get_string('course_connected_to_group', 'block_microsoft'));
            } else {
                // The course is configured to be connected to a group, but the group cannot be found.
                $html = html_writer::tag('h5', get_string('course_connected_to_group_missing', 'block_microsoft'));
            }
        } else {
            $html = html_writer::tag('h5', get_string('course_not_connected', 'block_microsoft'));
        }

        return [$html, $o365record];
    }

    /**
     * Get content for a connected user.
     *
     * @return string Block content.
     */
    protected function get_user_content_connected() {
        global $DB, $CFG, $SESSION, $USER, $OUTPUT;
        $o365config = get_config('local_o365');
        $html = '';

        $aadsync = get_config('local_o365', 'aadsync');
        $aadsync = array_flip(explode(',', $aadsync));
        // Only profile sync once for each session.
        if (empty($SESSION->block_microsoft_profilesync) &&
            (isset($aadsync['photosynconlogin']) || isset($aadsync['tzsynconlogin']))) {
            $this->page->requires->jquery();
            $this->page->requires->js('/blocks/microsoft/js/microsoft.js');
            $this->page->requires->js_init_call('microsoft_update_profile', array($CFG->wwwroot));
        }

        $user = $DB->get_record('user', array('id' => $USER->id));
        $langconnected = get_string('o365connected', 'block_microsoft', $user);
        $html .= '<h5>'.$langconnected.'</h5>';

        $odburl = get_config('local_o365', 'odburl');
        $o365object = $DB->get_record('local_o365_objects', ['type' => 'user', 'moodleid' => $USER->id]);
        if (!empty($o365object) && !empty($o365object->metadata)) {
            $metadata = json_decode($o365object->metadata, true);
            if (!empty($metadata['odburl'])) {
                $odburl = $metadata['odburl'];
            }
        }

        if (!empty($odburl) && !empty($this->globalconfig->settings_showmydelve)) {
            if (!empty($o365object)) {
                $delveurl = 'https://'.$odburl.'/_layouts/15/me.aspx?u='.$o365object->objectid.'&v=work';
            }
        }

        if (!empty($user->picture)) {
            $html .= '<div class="profilepicture">';
            $picturehtml = $OUTPUT->user_picture($user, array('size' => 100, 'class' => 'block_microsoft_profile'));
            $profileurl = new moodle_url('/user/profile.php', ['id' => $USER->id]);
            if (!empty($delveurl)) {
                // If "My Delve" is enabled, clicking the user picture should take you to their Delve page.
                $picturehtml = str_replace($profileurl->out(), $delveurl, $picturehtml);
            }

            $html .= $picturehtml;
            $html .= '</div>';
        }

        $items = [];

        $userupn = \local_o365\utils::get_o365_upn($USER->id);

        if ($this->page->context instanceof \context_course && $this->page->context->instanceid !== SITEID) {
            // Course SharePoint Site.
            if (!empty($this->globalconfig->settings_showcoursespsite) && !empty($o365config->sharepointlink)) {
                $sharepointstr = get_string('linksharepoint', 'block_microsoft');
                $coursespsite = $DB->get_record('local_o365_coursespsite', ['courseid' => $this->page->context->instanceid]);
                if (!empty($coursespsite)) {
                    $spsite = \local_o365\rest\sharepoint::get_tokenresource();
                    if (!empty($spsite)) {
                        $spurl = $spsite.'/'.$coursespsite->siteurl;
                        $spattrs = ['class' => 'servicelink block_microsoft_sharepoint', 'target' => '_blank'];
                        $items[] = html_writer::link($spurl, $sharepointstr, $spattrs);
                        $items[] = '<hr/>';
                    }
                }
            }
        }

        // My Delve URL.
        if (!empty($delveurl)) {
            $delveattrs = ['class' => 'servicelink block_microsoft_delve', 'target' => '_blank'];
            $delvestr = get_string('linkmydelve', 'block_microsoft');
            $items[] = html_writer::link($delveurl, $delvestr, $delveattrs);
        }

        // My email.
        if (!empty($this->globalconfig->settings_showemail)) {
            $emailurl = 'https://outlook.office365.com/';
            $emailattrs = ['class' => 'servicelink block_microsoft_outlook', 'target' => '_blank'];
            $emailstr = get_string('linkemail', 'block_microsoft');
            $items[] = html_writer::link($emailurl, $emailstr, $emailattrs);
        }

        // My Forms URL.
        if (!empty($this->globalconfig->settings_showmyforms)) {
            $formsattrs = ['class' => 'servicelink block_microsoft_forms', 'target' => '_blank'];
            $formsstr = get_string('linkmyforms', 'block_microsoft');
            $formsurl = get_string('settings_showmyforms_default', 'block_microsoft');
            if (!empty($odburl)) {
                $items[] = html_writer::link($formsurl, $formsstr, $formsattrs);
            }
        }

        // My OneNote Notebook.
        $items[] = $this->render_onenote();

        // My OneDrive.
        if (!empty($this->globalconfig->settings_showonedrive)) {
            $odbattrs = [
                'target' => '_blank',
                'class' => 'servicelink block_microsoft_onedrive',
            ];
            $stronedrive = get_string('linkonedrive', 'block_microsoft');
            if (!empty($odburl)) {
                $items[] = html_writer::link('https://'.$odburl, $stronedrive, $odbattrs);
            }
        }

        // Microsoft Stream.
        if (!empty($this->globalconfig->settings_showmsstream)) {
            $streamurl = 'https://web.microsoftstream.com/?noSignUpCheck=1';
            $streamattrs = ['target' => '_blank', 'class' => 'servicelink block_microsoft_msstream'];
            $items[] = html_writer::link($streamurl, get_string('linkmsstream', 'block_microsoft'), $streamattrs);
        }

        // Microsoft Teams.
        if (!empty($this->globalconfig->settings_showmsteams)) {
            $teamsurl = 'https://teams.microsoft.com/_';
            $teamsattrs = ['target' => '_blank', 'class' => 'servicelink block_microsoft_msteams'];
            $items[] = html_writer::link($teamsurl, get_string('linkmsteams', 'block_microsoft'), $teamsattrs);
        }

        // My Sways.
        if (!empty($this->globalconfig->settings_showsways) && !empty($userupn)) {
            $swayurl = 'https://www.sway.com/my?auth_pvr=OrgId&auth_upn='.$userupn;
            $swayattrs = ['target' => '_blank', 'class' => 'servicelink block_microsoft_sway'];
            $items[] = html_writer::link($swayurl, get_string('linksways', 'block_microsoft'), $swayattrs);
        }

        // Configure Outlook Sync.
        if (!empty($this->globalconfig->settings_showoutlooksync)) {
            $outlookurl = new moodle_url('/local/o365/ucp.php?action=calendar');
            $outlookstr = get_string('linkoutlook', 'block_microsoft');
            $items[] = html_writer::link($outlookurl, $outlookstr, ['class' => 'servicelink block_microsoft_outlook']);
        }

        // Preferences.
        if (!empty($this->globalconfig->settings_showpreferences)) {
            $prefsurl = new moodle_url('/local/o365/ucp.php');
            $prefsstr = get_string('linkprefs', 'block_microsoft');
            $items[] = html_writer::link($prefsurl, $prefsstr, ['class' => 'servicelink block_microsoft_preferences']);
        }

        if (auth_oidc_connectioncapability($USER->id, 'connect') === true
          || auth_oidc_connectioncapability($USER->id, 'disconnect') === true
          || local_o365_connectioncapability($USER->id, 'link')
          || local_o365_connectioncapability($USER->id, 'unlink')) {
            if (!empty($this->globalconfig->settings_showmanageo365conection)) {
                $connecturl = new moodle_url('/local/o365/ucp.php', ['action' => 'connection']);
                $connectstr = get_string('linkconnection', 'block_microsoft');
                $items[] = html_writer::link($connecturl, $connectstr, ['class' => 'servicelink block_microsoft_connection']);
            }
        }

        // Download Microsoft 365.
        $downloadlinks = $this->get_content_o365download();
        foreach ($downloadlinks as $link) {
            $items[] = $link;
        }

        $html .= html_writer::alist($items);

        return $html;
    }

    /**
     * Get block content for unconnected users.
     *
     * @return string Block content.
     */
    protected function get_user_content_not_connected() {
        global $USER;

        $html = html_writer::tag('h5', get_string('notconnected', 'block_microsoft'));

        $connecturl = new moodle_url('/local/o365/ucp.php');
        $connectstr = get_string('connecttoo365', 'block_microsoft');

        $items = [];

        if (auth_oidc_connectioncapability($USER->id, 'connect') === true || local_o365_connectioncapability($USER->id, 'link')) {
            if (!empty($this->globalconfig->settings_showo365connect)) {
                $items[] = html_writer::link($connecturl, $connectstr, ['class' => 'servicelink block_microsoft_connection']);
            }
        }

        $items[] = $this->render_onenote();

        $downloadlinks = $this->get_content_o365download();
        foreach ($downloadlinks as $link) {
            $items[] = $link;
        }

        $html .= html_writer::alist($items);
        return $html;
    }

    /**
     * Get Microsoft 365 download links (if enabled).
     *
     * @return array Array of download link HTML, or empty array if download links disabled.
     */
    protected function get_content_o365download() {
        if (empty($this->globalconfig->showo365download)) {
            return [];
        }

        $url = get_config('block_microsoft', 'settings_geto365link');
        $str = get_string('geto365', 'block_microsoft');
        return [
            html_writer::link($url, $str, ['class' => 'servicelink block_microsoft_downloado365', 'target' => '_blank']),
        ];
    }

    /**
     * Get the user's Moodle OneNote Notebook.
     *
     * @param \local_onenote\api\base $onenoteapi A constructed OneNote API to use.
     * @return array Array of information about the user's OneNote notebook used for Moodle.
     */
    protected function get_onenote_notebook(\local_onenote\api\base $onenoteapi) {
        $moodlenotebook = null;
        for ($i = 0; $i < 2; $i++) {
            $notebooks = $onenoteapi->get_items_list('');
            if (!empty($notebooks)) {
                $notebookname = get_string('notebookname', 'block_microsoft');
                foreach ($notebooks as $notebook) {
                    if ($notebook['title'] == $notebookname) {
                        $moodlenotebook = $notebook;
                        break;
                    }
                }
            }
            if (empty($moodlenotebook)) {
                $onenoteapi->sync_notebook_data();
            } else {
                break;
            }
        }
        return $moodlenotebook;
    }

    /**
     * Render OneNote section of the block.
     *
     * @return string HTML for the rendered OneNote section of the block.
     */
    protected function render_onenote() {
        global $USER;

        if (empty($this->globalconfig->settings_showonenotenotebook)) {
            return '';
        }

        if (!class_exists('\local_onenote\api\base')) {
            $url = new moodle_url('https://www.office.com/launch/onenote');
            $stropennotebook = get_string('linkonenote', 'block_microsoft');
            $linkattrs = [
                'onclick' => 'window.open(this.href,\'_blank\'); return false;',
                'class' => 'servicelink block_microsoft_onenote',
            ];
            return html_writer::link($url->out(false), $stropennotebook, $linkattrs);
        }

        $action = optional_param('action', '', PARAM_TEXT);
        try {
            $onenoteapi = \local_onenote\api\base::getinstance();
            $output = '';
            if ($onenoteapi->is_logged_in()) {
                // Add the "save to onenote" button if we are on an assignment page.
                $onassignpage = ($this->page->cm
                  && $this->page->cm->modname == 'assign'
                  && $action == 'editsubmission') ? true : false;
                if ($onassignpage === true && $onenoteapi->is_student($this->page->cm->id, $USER->id)) {
                    $workstr = get_string('workonthis', 'block_microsoft');
                    $output .= $onenoteapi->render_action_button($workstr, $this->page->cm->id).'<br /><br />';
                }
                // Find moodle notebook, create if not found.
                $moodlenotebook = null;

                $cache = cache::make('block_microsoft', 'onenotenotebook');
                $moodlenotebook = $cache->get($USER->id);
                if (empty($moodlenotebook)) {
                    $moodlenotebook = $this->get_onenote_notebook($onenoteapi);
                    $result = $cache->set($USER->id, $moodlenotebook);
                }

                if (!empty($moodlenotebook)) {
                    $url = new moodle_url($moodlenotebook['url']);
                    $stropennotebook = get_string('linkonenote', 'block_microsoft');
                    $linkattrs = [
                        'onclick' => 'window.open(this.href,\'_blank\'); return false;',
                        'class' => 'servicelink block_microsoft_onenote',
                    ];
                    $output .= html_writer::link($url->out(false), $stropennotebook, $linkattrs);
                } else {
                    $output .= get_string('error_nomoodlenotebook', 'block_microsoft');
                }
            } else {
                if (\local_o365\utils::is_configured_msaccount()) {
                    $output .= $this->render_signin_widget($onenoteapi->get_login_url());
                }
            }
            return $output;
        } catch (\Exception $e) {
            if (class_exists('\local_o365\utils')) {
                \local_o365\utils::debug($e->getMessage(), 'block_microsoft', $e);
            }
            return '<span class="block_microsoft_onenote servicelink">'.get_string('linkonenote_unavailable', 'block_microsoft')
                    .'<br /><small>'.get_string('contactadmin', 'block_microsoft').'</small></span>';
        }
    }

    /**
     * Get the HTML for the sign in button for an MS account.
     * @param url $loginurl url for sign in button
     * @return string HTML containing the sign in widget.
     */
    public function render_signin_widget($loginurl) {
        $loginstr = get_string('msalogin', 'block_microsoft');

        $attrs = [
            'onclick' =>
              'window.open(this.href,\'mywin\',\'left=20,top=20,width=500,height=500,toolbar=1,resizable=0\'); return false;',
            'class' => 'servicelink block_microsoft_msasignin'
        ];
        return html_writer::link($loginurl, $loginstr, $attrs);
    }
}
