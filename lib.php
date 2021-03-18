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
 * @package   plagiarism_drillbit
 * @copyright 2012 iParadigms LLC
 */

use SimpleJWT\JWT;

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}


global $CFG;

require_once($CFG->dirroot . '/plagiarism/drillbit/vendor/autoload.php');
require_once($CFG->dirroot . '/plagiarism/lib.php');
require_once($CFG->dirroot . '/lib/filelib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/plagiarism/drillbit/classes/drillbit_view.class.php');

define('PLAGIARISM_DRILLBIT_CRON_SUBMISSIONS_LIMIT', 100);
define('PLAGIARISM_DRILLBIT_MAX_FILENAME_LENGTH', 180);
define('PLAGIARISM_DRILLBIT_MAX_FILE_UPLOAD_SIZE', 104857600);


// Get helper methods.
// require_once($CFG->dirroot.'/plagiarism/drillbit/locallib.php');

class plagiarism_plugin_drillbit extends plagiarism_plugin
{
    private static $amdcomponentsloaded = false;

    /**
     * Get the fields to be used in the form to configure each activities drillbit settings.
     *
     * @return array of settings fields.
     */
    public function get_settings_fields() {
        return array(
            'use_drillbit', 'plagiarism_show_student_report', 'plagiarism_draft_submit',
            'plagiarism_allow_non_or_submissions', 'plagiarism_submitpapersto', 'plagiarism_compare_student_papers',
            'plagiarism_compare_internet', 'plagiarism_compare_journals', 'plagiarism_report_gen',
            'plagiarism_compare_institution', 'plagiarism_exclude_biblio', 'plagiarism_exclude_quoted',
            'plagiarism_exclude_matches', 'plagiarism_exclude_matches_value', 'plagiarism_rubric', 'plagiarism_erater',
            'plagiarism_erater_handbook', 'plagiarism_erater_dictionary', 'plagiarism_erater_spelling',
            'plagiarism_erater_grammar', 'plagiarism_erater_usage', 'plagiarism_erater_mechanics',
            'plagiarism_erater_style', 'plagiarism_transmatch'
        );
    }

    /**
     * Get the configuration settings for the plagiarism plugin
     *
     * @return mixed if plugin is enabled then an array of config settings is returned or false if not
     */
    public static function get_config_settings($modulename) {
        $pluginconfig = get_config('plagiarism_drillbit', 'plagiarism_drillbit_' . $modulename);
        return $pluginconfig;
    }

    /**
     * @return mixed the admin config settings for the plugin
     */
    public static function plagiarism_drillbit_admin_config() {
        return get_config('plagiarism_drillbit');
    }

    /**
     * Get the drillbit settings for a module
     *
     * @param int $cmid - the course module id, if this is 0 the default settings will be retrieved
     * @param bool $uselockedvalues - use locked values in place of saved values
     * @return array of drillbit settings for a module
     */
    public function get_settings($cmid = null, $uselockedvalues = true) {
        global $DB;
        $defaults = $DB->get_records_menu('drillbit_plugin_config', array('cm' => null),     '', 'name,value');
        $settings = $DB->get_records_menu('drillbit_plugin_config', array('cm' => $cmid), '', 'name,value');

        // Don't overwrite settings with locked values (only relevant on inital module creation).
        if ($uselockedvalues == false) {
            return $settings;
        }

        // Enforce site wide config locking.
        foreach ($defaults as $key => $value) {
            if (substr($key, -5) !== '_lock') {
                continue;
            }
            if ($value != 1) {
                continue;
            }
            $setting = substr($key, 0, -5);
            $settings[$setting] = $defaults[$setting];
        }

        return $settings;
    }

    public function is_plugin_configured() {
        $config = $this->plagiarism_drillbit_admin_config();

        if (
            empty($config->plagiarism_drillbit_accountid) ||
            empty($config->plagiarism_drillbit_apiurl) ||
            empty($config->plagiarism_drillbit_secretkey)
        ) {
            return false;
        }

        return true;
    }

    public function get_configs() {
        return array();
    }

    public function get_links($linkarray) {
        global $CFG, $DB, $OUTPUT, $USER;

        $output = "";
        // return $output;

        // Don't show links for certain file types as they won't have been submitted to drillbit.
        if (!empty($linkarray["file"])) {
            $file = $linkarray["file"];
            $filearea = $file->get_filearea();
            $nonsubmittingareas = array("feedback_files", "introattachment");
            if (in_array($filearea, $nonsubmittingareas)) {
                return $output;
            }
        }

        static $cm;
        static $forum;
        if (empty($cm)) {
            $cm = get_coursemodule_from_id('', $linkarray["cmid"]);

            if ($cm->modname == 'forum') {
                if (!$forum = $DB->get_record("forum", array("id" => $cm->instance))) {
                    print_error('invalidforumid', 'forum');
                }
            }
        }

        static $moduledata;
        if (empty($moduledata)) {
            $moduledata = $DB->get_record($cm->modname, array('id' => $cm->instance));
        }

        static $context;
        if (empty($context)) {
            $context = context_course::instance($cm->course);
        }

        static $istutor;
        if (empty($istutor)) {
            $istutor = $this->is_tutor($context);
        }

        if ((!empty($linkarray["file"]) || !empty($linkarray["content"])) && !empty($linkarray["cmid"])) {

            // $this->load_page_components();

            $identifier = '';
            $itemid = 0;

            // Get File or Content information.
            $submittinguser = $linkarray['userid'];
            if (!empty($linkarray["file"])) {
                $identifier = $file->get_pathnamehash();
                $itemid = $file->get_itemid();
                $submissiontype = 'file';
            } else if (!empty($linkarray["content"])) {
                // Get drillbit text content details.
                $submissiontype = 'text_content';
                if ($cm->modname == 'forum') {
                    $submissiontype = 'forum_post';
                } else if ($cm->modname == 'quiz') {
                    $submissiontype = 'quiz_answer';
                }
                // $content = $moduleobject->set_content($linkarray, $cm);
                // $identifier = sha1($content);
            }

            // Group submissions where all students have to submit sets userid to 0.
            if ($linkarray['userid'] == 0 && !$istutor) {
                $linkarray['userid'] = $USER->id;
            }

            $submissionusers = array($linkarray["userid"]);
            $assignment = new assign($context, $cm, null);
            $group = $assignment->get_submission_group($linkarray["userid"]);

            if ($group = $assignment->get_submission_group($linkarray["userid"])) {
                $users = groups_get_members($group->id);
                $submissionusers = array_keys($users);
            }

            $plagiarismfiles = $DB->get_records(
                'plagiarism_drillbit_files',
                array(
                    'userid' => $linkarray["userid"],
                    'cm' => $linkarray["cmid"], 'identifier' => $identifier
                ),
                'lastmodified DESC',
                '*',
                0,
                1
            );

            $plagiarismfile = current($plagiarismfiles);

            if (empty($plagiarismfile)) {
                return $output;
            }

            if ($plagiarismfile->statuscode == 'queued') {
                $statusstr = get_string('drillbitstatus', 'plagiarism_drillbit') . ': ' . get_string('queued', 'plagiarism_drillbit');
                $content = $OUTPUT->pix_icon('drillbitIcon', $statusstr, 'plagiarism_drillbit', array('class' => 'icon_size')) . $statusstr;

                $output .= html_writer::tag(
                    'div',
                    $content,
                    array('class' => 'drillbit_status')
                );
            } else if ($plagiarismfile->statuscode == 'submitted') {
                $statusstr = get_string('drillbitstatus', 'plagiarism_drillbit') . ': ' . get_string('submitted', 'plagiarism_drillbit');
                $content = $OUTPUT->pix_icon('drillbitIcon', $statusstr, 'plagiarism_drillbit', array('class' => 'icon_size')) . $statusstr;

                $output .= html_writer::tag(
                    'div',
                    $content,
                    array('class' => 'drillbit_status')
                );
            } else if ($plagiarismfile->statuscode == 'completed') {
                $statusstr = get_string('drillbitstatus', 'plagiarism_drillbit') . ': ' . get_string('completed', 'plagiarism_drillbit');
                $score = "&nbsp;&nbsp;<b>" . "Similarity Score: " . $plagiarismfile->similarityscore . " </b>";
                $content = $OUTPUT->pix_icon('drillbitIcon', $statusstr, 'plagiarism_drillbit', array('class' => 'icon_size')) . $statusstr . $score;

                $output .= html_writer::tag(
                    'div',
                    $content,
                    array('class' => 'drillbit_status')
                );

                $href = html_writer::tag(
                    'a',
                    get_string('vwreport', 'plagiarism_drillbit'),
                    array('class' => 'drillbit_report_link', 'href' => '/plagiarism/drillbit/report_file.php?paper_id=' . $plagiarismfile->submissionid, 'target' => '_blank')
                );

                $output .= html_writer::div($href, "drillbit_report_link_class");
            } else {
                $statusstr = get_string('drillbitstatus', 'plagiarism_drillbit') . ': ' . get_string('pending', 'plagiarism_drillbit');
                $output .= html_writer::tag(
                    'div',
                    $OUTPUT->pix_icon('drillbitIcon', $statusstr, 'plagiarism_drillbit', array('class' => 'icon_size')) . $statusstr,
                    array('class' => 'drillbit_status')
                );
            }
        }

        return $output;
    }

    public function get_file_results($cmid, $userid, $file) {
        return array('analyzed' => '', 'score' => '', 'reporturl' => '');
    }

    public function event_handler($eventdata) {
        global $DB, $CFG;
        $result = true;
        $cm = $eventdata["contextinstanceid"];
        $userid = $eventdata["userid"];
        $relateduser = $eventdata["relateduserid"];
        $submissiontype = "file";
        // $cm = get_coursemodule_from_id('mod_assign', $eventdata['contextinstanceid']);
        // print_debug($cm);

        if (isset($eventdata["other"]) && $eventdata["other"]["modulename"] == "assign" && $eventdata["eventtype"] == "file_uploaded") {
            $pathnamehashes = $eventdata["other"]["pathnamehashes"];

            foreach ($pathnamehashes as $identifier) {
                $fileid = $this->create_new_drillbit_submission($cm, $userid, $identifier, $submissiontype, $eventdata['objectid']);
            }
        } else if ($eventdata["other"]["modulename"]) {
            $modname = $eventdata["other"]["modulename"];
            // coming soon
        } else {
            mtrace("Invalid mod name");
            // coming soon
        }

        return $result;
    }

    private function create_new_drillbit_submission($cm, $userid, $identifier, $submissiontype, $itemid = 0) {
        global $DB;

        $plagiarismfile = new stdClass();
        $plagiarismfile->cm = $cm;
        $plagiarismfile->userid = $userid;
        $plagiarismfile->identifier = $identifier;
        $plagiarismfile->statuscode = "queued";
        $plagiarismfile->similarityscore = null;
        $plagiarismfile->attempt = 1;
        $plagiarismfile->itemid = $itemid;
        $plagiarismfile->lastmodified = strtotime("now");
        $plagiarismfile->submissiontype = $submissiontype;

        if (!$fileid = $DB->insert_record('plagiarism_drillbit_files', $plagiarismfile)) {
            // plagiarism_drillbit_activitylog("Insert record failed (CM: ".$cm->id.", User: ".$userid.")", "PP_NEW_SUB");
            $fileid = 0;
        }

        return $fileid;
    }

    public function is_tutor($context) {
        return has_capability($this->get_tutor_capability(), $context);
    }

    public function get_tutor_capability() {
        return 'mod/' . 'assign' . ':grade';
    }

    public function user_enrolled_on_course($context, $userid) {
        return has_capability('mod/' . $this->modname . ':submit', $context, $userid);
    }

    public function get_author($itemid) {
        global $DB;

        if ($submission = $DB->get_record('assign_submission', array('id' => $itemid), 'userid')) {
            return $submission->userid;
        } else {
            return 0;
        }
    }
}

function plagiarism_drillbit_coursemodule_standard_elements($formwrapper, $mform) {
    // Call code to get examplefield from database
    // For example $existing = get_existing($coursemodule);
    // You have to write get_existing.
    global $DB, $PAGE, $COURSE;
    $modulename = $formwrapper->get_current()->modulename;
    // print_r($formwrapper->get_current());
    // exit;
    if ($modulename == 'assign') {
        $drillbitview = new drillbit_view();

        if ($PAGE->pagetype != 'course-editbulkcompletion' && $PAGE->pagetype != 'course-editdefaultcompletion') {
            // Create/Edit course in drillbit and join user to class.
            // $course = $this->get_course_data($cmid, $COURSE->id);
            $drillbitview->add_elements_to_settings_form($mform, "", "activity");
        }
    }
}

function plagiarism_drillbit_coursemodule_edit_post_actions($data, $course) {
    // print_r($data);exit;
}


function plagiarism_drillbit_send_queued_submissions() {

    $resultcode = update_expired_jwt_token();
    if ($resultcode) {
        global $CFG, $DB;

        $queueditems = $DB->get_records_select(
            "plagiarism_drillbit_files",
            "statuscode = 'queued' OR statuscode = 'pending'",
            null,
            '',
            '*',
            0,
            PLAGIARISM_DRILLBIT_CRON_SUBMISSIONS_LIMIT
        );

        $folderid = $DB->get_record("config_plugins", array("plugin" => "plagiarism_drillbit", "name" => "plagiarism_drillbit_folderid"));
        $jwt = $DB->get_record("config_plugins", array("plugin" => "plagiarism_drillbit", "name" => "jwt"));

        foreach ($queueditems as $queueditem) {
            $errorcode = 0;
            $cm = get_coursemodule_from_id('', $queueditem->cm);

            if ($queueditem->submissiontype == 'file') {
                $fs = get_file_storage();
                $file = $fs->get_file_by_hash($queueditem->identifier);

                if (!$file) {
                    // plagiarism_drillbit_activitylog('File not found for submission: '.$queueditem->id, 'PP_NO_FILE');
                    mtrace('File not found for submission. Identifier: ' . $queueditem->id);
                    $errorcode = 9;
                }

                // print_debug($file);
                $title = $file->get_filename();
                $filename = $file->get_filename();
                $mime = $file->get_mimetype();
                // $mime = $file->get_mime();

                $tempfile = null;
                try {
                    $textcontent = $file->get_content();
                    $tempfile = plagiarism_drillbit_tempfile($filename, $filename);
                    $fh = fopen($tempfile, "w");
                    fwrite($fh, $textcontent);
                    fclose($fh);
                } catch (Exception $e) {
                    // plagiarism_drillbit_activitylog('File content not found on submission: '.$queueditem->identifier, 'PP_NO_FILE');
                    mtrace($e);
                    mtrace('File content not found on submission. Identifier: ' . $queueditem->identifier);
                }

                if (!$tempfile) {
                    return;
                }

                $postdata = array();
                $postdata["name"] = $filename;
                $postdata["title"] = $cm->name;
                $postdata["assignment_id"] = $folderid->value;
                $postdata["doc_type"] = "thesis";
                $postdata["file"] = curl_file_create($tempfile, $mime, $filename);

                $headers = get_file_headers($jwt->value);
                // print_debug($headers);
                $url = "https://www.drillbitplagiarismcheck.com/drillbit_new/api/submission";

                $request = CallExternalAPI("POST", $url, $postdata, $headers);

                update_submission_response($request, $queueditem->id);
            }
        }
    } else {
        mtrace("Unable to authenticate against Drillbit API. Please contact Drillbit Support");
    }
}

function plagiarism_drillbit_update_reports() {
    global $DB;
    $resultcode = update_expired_jwt_token();
    if ($resultcode) {
        $queueditems = $DB->get_records_select(
            "plagiarism_drillbit_files",
            "statuscode = 'submitted'",
            null,
            '',
            '*',
            0,
            PLAGIARISM_DRILLBIT_CRON_SUBMISSIONS_LIMIT
        );

        foreach ($queueditems as $queueditem) {
            $errorcode = 0;
            $cm = get_coursemodule_from_id('', $queueditem->cm);
            $callback = $queueditem->callback_url;
            $jwt = $DB->get_record("config_plugins", array("plugin" => "plagiarism_drillbit", "name" => "jwt"));
            $headers = array("Authorization: Bearer $jwt->value", "Accept: application/json");

            $request = CallExternalAPI("GET", $callback, false, $headers);

            update_submission_response($request, $queueditem->id);
        }
    } else {
        mtrace("Unable to authenticate against Drillbit API. Please contact Drillbit Support");
    }
}

function get_file_headers($authtoken) {
    $headers = array(
        "Authorization: Bearer $authtoken",
        'Content-type: multipart/form-data'
    );

    return $headers;
}

function update_expired_jwt_token() {
    global $DB;
    $resultcode = 0;
    $email = "";
    $password = "";
    $apikey = "";
    $folderid = "";
    $existingtoken = get_existing_jwt_token();
    $deserializedtoken = null;
    if (!empty($existingtoken)) {
        try {
            $deserializedtoken = \SimpleJWT\JWT::deserialise($existingtoken);
        } catch (SimpleJWT\InvalidTokenException $e) {
            mtrace("Exception: " . $e->getMessage());
        }
    }

    if (!empty($deserializedtoken)) {
        if (!empty($deserializedtoken["claims"]["exp"])) {
            $expiration = $deserializedtoken["claims"]["exp"];
            if (strtotime("now") < $expiration) {
                $resultcode = 1;
                return $resultcode;
            }
        }
    }

    $pluginsettings = $DB->get_records("config_plugins", array("plugin" => "plagiarism_drillbit"));

    $toupdate = 0;
    foreach ($pluginsettings as $field => $value) {
        if ($value->name == "plagiarism_drillbit_apikey") {
            $apikey = $value->value;
        }

        if ($value->name == "plagiarism_drillbit_emailid") {
            $email = $value->value;
        }

        if ($value->name == "plagiarism_drillbit_folderid") {
            $folderid = $value->value;
        }

        if ($value->name == "plagiarism_drillbit_password") {
            $password = $value->value;
        }

        if ($value->name == "jwt") {
            $toupdate = $value->id;
        }
    }

    if (empty($email) || empty($password) || empty($apikey) || empty($folderid)) {
        $resultcode = 0;
    }
    $token = get_login_token($email, $password, $folderid, $apikey);
    if ($token != null) {
        $resultcode = 1;
    }

    if ($resultcode) {
        $updatejwt = new stdClass();
        $updatejwt->id = $toupdate;
        $updatejwt->value = $token;
        $DB->update_record("config_plugins", $updatejwt);
    }

    return $resultcode;
}

function get_existing_jwt_token() {
    global $DB;
    $jwt = $DB->get_record("config_plugins", array("plugin" => "plagiarism_drillbit", "name" => "jwt"));

    if ($jwt->value) {
        return $jwt->value;
    } else {
        return null;
    }
}

function get_login_token($email, $pass, $folderid, $apikey) {
    $loginparams = array();
    $loginparams["username"] = $email;
    $loginparams["password"] = $pass;
    $loginparams["api_key"] = $apikey;
    $loginparams["submissions_key"] = $folderid;

    $jsonrequest = json_encode($loginparams);

    $url = "https://www.drillbitplagiarismcheck.com/drillbit_new/api/authenticate/moodle";

    $request = CallExternalAPI("POST", $url, $jsonrequest);

    $response = json_decode($request);

    if (isset($response->jwt)) {
        return $response->jwt;
    } else {
        return null;
    }
}

function CallExternalAPI($method, $url, $data = false, $headers = array("content-type:application/json")) {
    $curl = curl_init();

    switch ($method) {
        case "POST":
            curl_setopt($curl, CURLOPT_POST, 1);

            if ($data) { curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
            break;
        case "PUT":
            curl_setopt($curl, CURLOPT_PUT, 1);
            break;
        default:
            if ($data) { $url = sprintf("%s?%s", $url, http_build_query($data));
            }
    }

    // Optional Authentication:
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    $result = curl_exec($curl);
    curl_close($curl);
    return $result;
}


function plagiarism_drillbit_tempfile($filename, $suffix) {
    $filename = str_replace(' ', '_', $filename);
    $filename = clean_param(strip_tags($filename), PARAM_FILE);

    $tempdir = make_temp_directory('plagiarism_drillbit');

    // Get the file extension (if there is one).
    $pathparts = explode('.', $suffix);
    $ext = '';
    if (count($pathparts) > 1) {
        $ext = '.' . array_pop($pathparts);
    }

    $permittedstrlength = PLAGIARISM_DRILLBIT_MAX_FILENAME_LENGTH - mb_strlen($tempdir . DIRECTORY_SEPARATOR, 'UTF-8');
    $extlength = mb_strlen('_' . mt_getrandmax() . $ext, 'UTF-8');
    if ($extlength > $permittedstrlength) {
        // Someone has likely used a long filename or the tempdir path is huge, so preserve the extension if possible.
        $extlength = $permittedstrlength;
    }

    // Shorten the filename as needed, taking the extension into consideration.
    $permittedstrlength -= $extlength;
    $filename = mb_substr($filename, 0, $permittedstrlength, 'UTF-8');

    // Ensure the filename doesn't have any characters that are invalid for the fs.
    $filename = clean_param($filename . mb_substr('_' . mt_rand() . $ext, 0, $extlength, 'UTF-8'), PARAM_FILE);

    $tries = 0;
    do {
        if ($tries == 10) {
            throw new invalid_dataroot_permissions("drillbit plagiarism plugin temporary file cannot be created.");
        }
        $tries++;

        $file = $tempdir . DIRECTORY_SEPARATOR . $filename;
    } while (!touch($file));

    return $file;
}

function print_debug($object) {
    echo "<pre>";
    print_r($object);
    exit;
}

function update_submission_response($response, $fileid) {
    global $DB;
    $responseobj = json_decode($response, true);

    if (isset($responseobj["paper_id"])) {
        mtrace("Updating Submission Response. Received paper id : " . $responseobj["paper_id"]);
        $links = $responseobj["links"];
        $callbackurl = null;
        $downloadurl = null;
        foreach ($links as $link) {
            if ($link["rel"] == "self") {
                $callbackurl = $link["href"];
            }

            if ($link["rel"] == "download-link") {
                $downloadurl = $link["href"];
            }
        }
        $plagiarismfile = new stdClass();
        $plagiarismfile->id = $fileid;
        $plagiarismfile->submissionid = $responseobj["paper_id"];
        $plagiarismfile->lastmodified = strtotime("now");
        $plagiarismfile->dkey = $responseobj["d_key"];
        $plagiarismfile->statuscode = "submitted";

        if ($responseobj["percent"] != "--") {
            $plagiarismfile->statuscode = "completed";
            $plagiarismfile->similarityscore = $responseobj["percent"];
        }
        $plagiarismfile->callback_url = $callbackurl;
        $plagiarismfile->download_url = $downloadurl;
        $DB->update_record('plagiarism_drillbit_files', $plagiarismfile);
    } else {
        if (isset($responseobj["status"])) {
            mtrace("Received Status: " . $responseobj["status"] . "Error: " . $responseobj["message"]);
        }
    }
}
