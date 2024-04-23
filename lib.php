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
 * @copyright 2021 Drillbit
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
require_once($CFG->dirroot . '/plagiarism/drillbit/locallib.php');
require_once($CFG->dirroot . '/plagiarism/drillbit/classes/drillbit_view.class.php');

define('PLAGIARISM_DRILLBIT_CRON_SUBMISSIONS_LIMIT', 100);
define('PLAGIARISM_DRILLBIT_MAX_FILENAME_LENGTH', 180);
define('PLAGIARISM_DRILLBIT_MAX_FILE_UPLOAD_SIZE', 104857600);
define('PLAGIARISM_DRILLBIT_SHOW_STUDENT_REPORT_ALWAYS', true);


// Get helper methods.
// require_once($CFG->dirroot.'/plagiarism/drillbit/locallib.php').

class plagiarism_plugin_drillbit extends plagiarism_plugin
{
    private static $amdcomponentsloaded = false;

    /**
     * Get the fields to be used in the form to configure each activities drillbit settings.
     *
     * @return array of settings fields.
     */
    public function get_settings_fields()
    {
        return array(
            'use_drillbit', 'plagiarism_show_student_reports', 'plagiarism_exclude_references',
            'plagiarism_exclude_quotes', 'plagiarism_exclude_smallsources'
        );

        array(
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
    public static function get_config_settings($modulename)
    {
        $pluginconfig = get_config('plagiarism_drillbit', 'plagiarism_drillbit_' . $modulename);
        return $pluginconfig;
    }

    /**
     * @return mixed the admin config settings for the plugin
     */
    public static function plagiarism_drillbit_admin_config()
    {
        return get_config('plagiarism_drillbit');
    }

    /**
     * Get the drillbit settings for a module
     *
     * @param int $cmid - the course module id, if this is 0 the default settings will be retrieved
     * @param bool $uselockedvalues - use locked values in place of saved values
     * @return array of drillbit settings for a module
     */
    public function get_settings($cmid = null, $uselockedvalues = true)
    {
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

    public function is_plugin_configured()
    {
        $config = $this->plagiarism_drillbit_admin_config();

        if (
            empty($config->plagiarism_drillbit_emailid) ||
            empty($config->plagiarism_drillbit_apiurl) ||
            empty($config->plagiarism_drillbit_apikey) ||
            !$config->enabled
        ) {
            return false;
        }

        return true;
    }

    public function get_configs()
    {
        return array();
    }

    /**
     * Save the form data associated with the plugin
     *
     * @global type $DB
     * @param object $data the form data to save
     */
    public function save_form_elements($data)
    {
        global $DB;

        $moduledrillbitenabled = $this->get_config_settings('mod_' . $data->modulename);
        if (empty($moduledrillbitenabled)) {
            return;
        }

        $settingsfields = $this->get_settings_fields();
        // Get current values.
        $plagiarismvalues = $this->get_settings($data->coursemodule, false);

        foreach ($settingsfields as $field) {
            if (isset($data->$field)) {
                $optionfield = new stdClass();
                $optionfield->cm = $data->coursemodule;
                $optionfield->name = $field;
                $optionfield->value = $data->$field;

                if (isset($plagiarismvalues[$field])) {
                    $optionfield->id = $DB->get_field(
                        'drillbit_plugin_config',
                        'id',
                        (array('cm' => $data->coursemodule, 'name' => $field))
                    );
                    if ($optionfield->value != 0) {
                        if (!$DB->update_record('drillbit_plugin_config', $optionfield)) {
                            plagiarism_drillbit_print_error('defaultupdateerror', 'plagiarism_drillbit', null, null, __FILE__, __LINE__);
                        }
                    }
                } else {
                    if (!empty($optionfield->cm) && !empty($optionfield->name)) {
                        $optionfield->config_hash = $optionfield->cm . "_" . $optionfield->name;
                        if ($optionfield->value != 0) {
                            if (!$DB->insert_record('drillbit_plugin_config', $optionfield)) {
                                plagiarism_drillbit_print_error('defaultinserterror', 'plagiarism_drillbit', null, null, __FILE__, __LINE__);
                            }
                        }
                    }
                }
            }
        }
    }


    /**
     * Add the Drillbit settings form to an add/edit activity page
     *
     * @param object $mform
     * @param object $context
     * @return type
     */
    public function get_form_elements_module($mform, $context, $modulename = "")
    {
        global $DB, $PAGE, $COURSE;

        static $settingsdisplayed;
        if ($settingsdisplayed) {
            return;
        }

        if (has_capability('plagiarism/drillbit:enable', $context)) {
            // Get Course module id and values.
            $cmid = optional_param('update', null, PARAM_INT);

            // Return no form if the plugin isn't configured.
            if (!$this->is_plugin_configured()) {
                return;
            }

            // Check if plagiarism plugin is enabled for this module if provided.
            if (!empty($modulename)) {
                $moduledrillbitenabled = $this->get_config_settings($modulename);

                if (empty($moduledrillbitenabled)) {
                    return;
                }
            }

            // Get assignment settings, use default settings on assignment creation.
            $plagiarismvalues = $this->get_settings($cmid);

            if (empty($plagiarismvalues["use_drillbit"]) && count($plagiarismvalues) <= 2) {
                $savedvalues = $plagiarismvalues;
                $plagiarismvalues = $this->get_settings(null);

                // Ensure we reuse the saved setting for use Drillbit.
                if (isset($savedvalues["use_drillbit"])) {
                    $plagiarismvalues["use_drillbit"] = $savedvalues["use_drillbit"];
                }
            }

            $plagiarismelements = $this->get_settings_fields();

            $drillbitview = new plagiarism_drillbit_view();
            $plagiarismvalues["plagiarism_rubric"] = (!empty($plagiarismvalues["plagiarism_rubric"])) ? $plagiarismvalues["plagiarism_rubric"] : 0;

            // We don't require the settings form on Moodle 3.3's bulk completion feature.
            if ($PAGE->pagetype != 'course-editbulkcompletion' && $PAGE->pagetype != 'course-editdefaultcompletion') {
                // Create/Edit course in Turnitin and join user to class.
                $course = $COURSE; //$this->get_course_data($cmid, $COURSE->id);
                $drillbitview->add_elements_to_settings_form($mform, $course, "activity", $modulename, $cmid, $plagiarismvalues["plagiarism_rubric"]);
            }
            $settingsdisplayed = true;

            // Disable all plagiarism elements if drillbit is not enabled.
            foreach ($plagiarismelements as $element) {
                if ($element <> 'use_drillbit') { // Ignore this var.
                    $mform->disabledIf($element, 'use_drillbit', 'eq', 0);
                }
            }

            // Check if files have already been submitted and disable exclude biblio and quoted if drillbit is enabled.
            if ($cmid != 0) {
                if ($DB->record_exists('plagiarism_drillbit_files', array('cm' => $cmid))) {
                    $mform->disabledIf('plagiarism_exclude_biblio', 'use_drillbit');
                    $mform->disabledIf('plagiarism_exclude_quoted', 'use_drillbit');
                }
            }

            // Set the default value for each option as the value we have stored.
            foreach ($plagiarismelements as $element) {
                if (isset($plagiarismvalues[$element])) {
                    $mform->setDefault($element, $plagiarismvalues[$element]);
                }
            }
        }
    }

    public function get_links($linkarray)
    {
        global $CFG, $DB, $OUTPUT, $USER;
        $output = "";


        try {
            // Don't show links for certain file types as they won't have been submitted to drillbit.
            if (!empty($linkarray["file"])) {
                $file = $linkarray["file"];
                $filearea = $file->get_filearea();
                $nonsubmittingareas = array("feedback_files", "introattachment");
                if (in_array($filearea, $nonsubmittingareas)) {
                    return $output;
                }
            }

            $component = (!empty($linkarray['component'])) ? $linkarray['component'] : "";

            // Exit if this is a quiz and quizzes are disabled.
            // if ($component == "qtype_essay" && empty($this->get_config_settings('mod_quiz'))) {
            //     return $output;
            // }

            // If this is a quiz, retrieve the cmid
            if ($component == "qtype_essay" && !empty($linkarray['area']) && empty($linkarray['cmid'])) {
                $questions = question_engine::load_questions_usage_by_activity($linkarray['area']);

                // Try to get cm using the questions owning context.
                $context = $questions->get_owning_context();
                if (empty($linkarray['cmid']) && $context->contextlevel == CONTEXT_MODULE) {
                    $linkarray['cmid'] = $context->instanceid;
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
			
			
            static $config;
            if (empty($config)) {
                $config = $this->plagiarism_drillbit_admin_config();
            }

            // Retrieve the plugin settings for this module.
            static $plagiarismsettings = null;
            if (is_null($plagiarismsettings)) {
                $plagiarismsettings = $this->get_settings($linkarray["cmid"]);
            }

            // Is this plugin enabled for this activity type.
            static $moduledrillbitenabled;
            if (empty($moduledrillbitenabled)) {
                $moduledrillbitenabled = $this->get_config_settings('mod_' . $cm->modname);
            }

            // Exit if drillbit is not being used for this module or activity type.
            if (empty($moduledrillbitenabled) || empty($plagiarismsettings['use_drillbit'])) {
                return $output;
            }

            // $ismoduleallowed = get_allowed_modules_for_drillbit($cm->modname);
            // if (!$ismoduleallowed) {
            //     return;
            // }

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
                        $identifier = sha1($linkarray["content"]);
                    } else if ($cm->modname == 'quiz') {
                        $submissiontype = 'quiz_answer';



                        $identifier = sha1($linkarray["content"] . $linkarray["itemid"]);
                    } else if ($cm->modname == 'workshop') {
                        $identifier = sha1($linkarray["content"]);
                    }
                }
                //var_dump($submissiontype);
                // Group submissions where all students have to submit sets userid to 0.
                if ($linkarray['userid'] == 0 && !$istutor) {
                    $linkarray['userid'] = $USER->id;
                }

                $submissionusers = array($linkarray["userid"]);
                if ($cm->modname == 'assign') {
                    $assignment = new assign($context, $cm, null);
                    $group = $assignment->get_submission_group($linkarray["userid"]);

                    if ($group = $assignment->get_submission_group($linkarray["userid"])) {
                        $users = groups_get_members($group->id);
                        $submissionusers = array_keys($users);
                    }
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

                $canprintreport = plagiarism_drillbit_has_access_to_view_report($linkarray["cmid"], $linkarray["userid"]);

                if (!$canprintreport) {
                    return $output;
                }

                if ($plagiarismfile->statuscode == 'queued') {
                    $statusstr =
                        get_string('drillbitstatus', 'plagiarism_drillbit') . ': ' . get_string('queued', 'plagiarism_drillbit');
                    $content = $OUTPUT->pix_icon(
                        'drillbitIcon',
                        $statusstr,
                        'plagiarism_drillbit',
                        array('class' => 'icon_size')
                    ) . $statusstr;

                    $output .= html_writer::tag(
                        'div',
                        $content,
                        array('class' => 'drillbit_status')
                    );
                } else if ($plagiarismfile->statuscode == 'submitted') {
                    $statusstr =
                        get_string('drillbitstatus', 'plagiarism_drillbit') . ': ' . get_string('submitted', 'plagiarism_drillbit');
                    $content = $OUTPUT->pix_icon(
                        'drillbitIcon',
                        $statusstr,
                        'plagiarism_drillbit',
                        array('class' => 'icon_size')
                    ) . $statusstr;

                    $output .= html_writer::tag(
                        'div',
                        $content,
                        array('class' => 'drillbit_status')
                    );
                } else if ($plagiarismfile->statuscode == 'completed') {
                    $statusstr =
                        get_string('drillbitstatus', 'plagiarism_drillbit') . ': ' . get_string('completed', 'plagiarism_drillbit');
                    $score =
                        "&nbsp;&nbsp;<b>" . "Similarity Score: " . $plagiarismfile->similarityscore . " </b>";
                    $content = $OUTPUT->pix_icon(
                        'drillbitIcon',
                        $statusstr,
                        'plagiarism_drillbit',
                        array('class' => 'icon_size')
                    ) . $statusstr . $score;

                    $output .= html_writer::tag(
                        'div',
                        $content,
                        array('class' => 'drillbit_status')
                    );

                    $href = html_writer::tag(
                        'a',
                        get_string('vwreport', 'plagiarism_drillbit'),
                        array(
                            'class' => 'drillbit_report_link',
                            'href' => '../../plagiarism/drillbit/report_file.php?paper_id=' . $plagiarismfile->submissionid,
                            'target' => '_blank'
                        )
                    );

                    $output .= html_writer::div($href, "drillbit_report_link_class");
                } else {
                    $statusstr =
                        get_string('drillbitstatus', 'plagiarism_drillbit') . ': ' . get_string('pending', 'plagiarism_drillbit');
                    $output .= html_writer::tag(
                        'div',
                        $OUTPUT->pix_icon(
                            'drillbitIcon',
                            $statusstr,
                            'plagiarism_drillbit',
                            array('class' => 'icon_size')
                        ) . $statusstr,
                        array('class' => 'drillbit_status')
                    );
                }
            }


            //}
            return $output;
        } catch (\Exception $e) {
            file_put_contents(__DIR__ . '/lib.log', print_r($e, true));
        }
    }

    public function get_file_results($cmid, $userid, $file)
    {
        return array('analyzed' => '', 'score' => '', 'reporturl' => '');
    }

    private function queue_submission_to_drillbit($cm, $author, $submitter, $identifier, $submissiontype, $itemid = 0, $eventtype = null)
    {

        global $CFG, $DB;
        $errorcode = 0;
        $attempt = 0;
        $drillbitsubmissionid = null;
        $updatedd = $DB->get_record('drillbit_plugin_config', ['cm' => $cm->id, 'name' => 'use_drillbit']);
        if ($updatedd->value != 0) {
            // $coursedata = $this->get_course_data($cm->id, $cm->course);

            // Check if file has been submitted before.
            $plagiarismfiles = plagiarism_drillbit_retrieve_successful_submissions($author, $cm->id, $identifier);
            // if (count($plagiarismfiles) > 0) {
            //     return true;
            // }

            $settings = $this->get_settings($cm->id);

            // Get module data.
            $moduledata = $DB->get_record($cm->modname, array('id' => $cm->instance));
            $moduledata->resubmission_allowed = false;

            $userid = $author;

            // Work out submission method.
            // If this file has successfully submitted in the past then break, text content is to be submitted.
            switch ($submissiontype) {
                case 'file':
                case 'text_content':
                    try {
                        // Get file data or prepare text submission.
                        if ($submissiontype == 'file') {
                            $fs = get_file_storage();
                            $file = $fs->get_file_by_hash($identifier);

                            $timemodified = $file->get_timemodified();
                            $filename = $file->get_filename();
                        } else {
                            // Check when text submission was last modified.
                            switch ($cm->modname) {
                                case 'assign':
                                    $moodlesubmission = $DB->get_record(
                                        'assign_submission',
                                        array(
                                            'assignment' => $cm->instance,
                                            'userid' => $userid,
                                            'id' => $itemid
                                        ),
                                        'timemodified'
                                    );
                                    break;
                                case 'workshop':
                                    $moodlesubmission = $DB->get_record(
                                        'workshop_submissions',
                                        array(
                                            'workshopid' => $cm->instance,
                                            'authorid' => $userid
                                        ),
                                        'timemodified'
                                    );
                                    break;
                            }

                            $timemodified = $moodlesubmission->timemodified;
                        }

                        // Get submission method depending on whether there has been a previous submission.
                        $submissionfields = 'id, cm, submissionid, identifier, statuscode, lastmodified, attempt';
                        $typefield = ($CFG->dbtype == "oci") ? " to_char(submissiontype) " : " submissiontype ";

                        // Check if this content/file has been submitted previously.
                        $previoussubmissions = $DB->get_records_select(
                            'plagiarism_drillbit_files',
                            " cm = ? AND userid = ? AND " . $typefield . " = ? AND identifier = ?",
                            array($cm->id, $author, $submissiontype, $identifier),
                            'id',
                            $submissionfields
                        );
                        $previoussubmission = end($previoussubmissions);

                        if ($previoussubmission) {
                            // Don't submit if submission hasn't changed.
                            if (
                                in_array($previoussubmission->statuscode, array("success", "error"))
                                && $timemodified <= $previoussubmission->lastmodified
                            ) {
                                return true;
                            } else if ($moduledata->resubmission_allowed) {
                                // Replace submission in the specific circumstance where Drillbit can accommodate resubmissions.
                                $submissionid = $previoussubmission->id;
                                $this->reset_drillbit_submission($cm, $author, $identifier, $previoussubmission, $submissiontype);
                                $drillbitsubmissionid = $previoussubmission->submissionid;
                            } else {
                                if ($previoussubmission->statuscode != "success") {
                                    $submissionid = $previoussubmission->id;
                                    $this->reset_drillbit_submission($cm, $author, $identifier, $previoussubmission, $submissiontype);
                                } else {
                                    $submissionid = $this->create_new_drillbit_submission($cm, $author, $identifier, $submissiontype);
                                    $drillbitsubmissionid = $previoussubmission->submissionid;
                                }
                            }
                            $attempt = $previoussubmission->attempt;
                        } else {
                            // Check if there is previous submission of different content which we may be able to replace.
                            $typefield = ($CFG->dbtype == "oci") ? " to_char(submissiontype) " : " submissiontype ";
                            if ($previoussubmission = $DB->get_record_select(
                                'plagiarism_drillbit_files',
                                " cm = ? AND userid = ? AND " . $typefield . " = ?",
                                array($cm->id, $author, $submissiontype),
                                'id, cm, submissionid, identifier, statuscode, lastmodified, attempt'
                            )) {

                                $submissionid = $previoussubmission->id;
                                $attempt = $previoussubmission->attempt;
                                // // Delete old text content submissions from Drillbit if resubmissions aren't allowed.
                                // if ($submissiontype == 'text_content' && $settings["plagiarism_report_gen"] == 0 && !is_null($previoussubmission->externalid)) {
                                //     $this->delete_drillbit_submission($cm, $previoussubmission->externalid, $author);
                                // }

                                // Replace submission in the specific circumstance where Drillibit can accomodate resubmissions.
                                if ($moduledata->resubmission_allowed || $submissiontype == 'text_content') {
                                    $this->reset_drillbit_submission($cm, $author, $identifier, $previoussubmission, $submissiontype);
                                    $drillbitsubmissionid = $previoussubmission->submissionid;
                                } else {
                                    $submissionid = $this->create_new_drillbit_submission($cm, $author, $identifier, $submissiontype);
                                }
                            } else {
                                $submissionid = $this->create_new_drillbit_submission($cm, $author, $identifier, $submissiontype);
                            }
                        }

                        // log all variables in case of error
                        file_put_contents(__DIR__ . '/lib.log', print_r(array(
                            'cm' => $cm,
                            'author' => $author,
                            'identifier' => $identifier,
                            'submissiontype' => $submissiontype,
                            'submissionid' => $submissionid,
                            'attempt' => $attempt,
                            'drillbitsubmissionid' => $drillbitsubmissionid,
                            'timemodified' => $timemodified,
                            'filename' => $filename,
                            'file' => $file,
                            'previoussubmissions' => $previoussubmissions,
                            'previoussubmission' => $previoussubmission,
                            'moduledata' => $moduledata,
                            'settings' => $settings,
                            'moodlesubmission' => @$moodlesubmission,
                            'submissionfields' => $submissionfields,
                            'typefield' => $typefield
                        ), true));
                    } catch (\Exception $e) {
                        file_put_contents(__DIR__ . '/lib.log', print_r($e, true));
                    }
                    break;

                case 'forum_post':

                case 'quiz_answer':
                    if ($previoussubmissions = $DB->get_records_select(
                        'plagiarism_drillbit_files',
                        " cm = ? AND userid = ? AND identifier = ? ",
                        array($cm->id, $author, $identifier),
                        'id DESC',
                        'id, cm, identifier, statuscode, attempt',
                        0,
                        1
                    )) {

                        $previoussubmission = current($previoussubmissions);
                        if ($previoussubmission->statuscode == "success") {
                            return true;
                        } else {
                            $submissionid = $previoussubmission->id;
                            $attempt = $previoussubmission->attempt;
                            $this->reset_drillbit_submission($cm, $author, $identifier, $previoussubmission, $submissiontype);
                        }
                    } else {
                        $submissionid = $this->create_new_drillbit_submission($cm, $author, $identifier, $submissiontype);
                    }
                    break;
            }

            // Check file is less than maximum allowed size.
            if ($submissiontype == 'file') {
                if ($file->get_filesize() > PLAGIARISM_DRILLBIT_MAX_FILE_UPLOAD_SIZE) {
                    $errorcode = 2;
                }
            }

            // If applicable, check whether file type is accepted.
            $acceptanyfiletype = (!empty($settings["plagiarism_allow_non_or_submissions"])) ? 1 : 0;
            if (!$acceptanyfiletype && $submissiontype == 'file') {

                if (!plagiarism_drillbit_is_supported_file($filename)) {
                    $errorcode = 4;
                }
            }
        }
        // Save submission as queued or errored if we have an errorcode.
        $statuscode = ($errorcode != 0) ? 'error' : 'queued';
        return $this->save_submission(
            $cm,
            $author,
            $submissionid,
            $identifier,
            $statuscode,
            $drillbitsubmissionid,
            $submitter,
            $itemid,
            $submissiontype,
            $attempt,
            $errorcode
        );
    }

    public function event_handler($eventdata)
    {
        global $DB, $CFG;
        $result = true;


        if (!isset($eventdata["other"])) {
            return $result;
        }

        switch ($eventdata["other"]["modulename"]) {
            case "assign":
                $result = $this->assign_events_handler($eventdata);
                break;
            case "quiz":
                $result = $this->quiz_events_handler($eventdata);
                break;
            case "forum":
                $result = $this->forum_events_handler($eventdata);
                break;
            case "workshop":
                $result = $this->workshop_events_handler($eventdata);
                break;
            default:
                $result = true;
                mtrace("Invalid mod name");
                break;
        }

        return $result;
    }

    /**
     * Handles events for the assign module
     * 
     * @param array $eventdata
     * @return bool
     */
    private function assign_events_handler($eventdata)
    {
        $result = true;
        $pathnamehashes = @$eventdata["other"]["pathnamehashes"];
        $userid = $eventdata["userid"];

        $cm = get_coursemodule_from_id($eventdata['other']['modulename'], $eventdata['contextinstanceid']);


        if (!$cm) {
            return true;
        }

        $context = context_module::instance($cm->id);

        switch ($eventdata["eventtype"]) {
            case "file_uploaded":
                $submissiontype = "file";
                foreach ($pathnamehashes as $identifier) {
                    $fileid = $this->queue_submission_to_drillbit($cm, $userid, $userid, $identifier, $submissiontype, $eventdata['objectid'], $eventdata["eventtype"]);
                }
                break;
            default:
                $result = true;
                break;
        }

        return $result;
    }

    /**
     * Handles events for the quiz module
     * 
     * @param array $eventdata
     * @return bool
     */
    private function quiz_events_handler($eventdata)
    {
        global $DB;

        $result = true;
        $pathnamehashes = $eventdata["other"]["pathnamehashes"];
        $userid = $eventdata["userid"];

        $cm = get_coursemodule_from_instance($eventdata['other']['modulename'], $eventdata['other']['quizid']);



        // Remove the event if the course module no longer exists.
        if (!$cm) {
            return true;
        }

        $context = context_module::instance($cm->id);

        // Get module data.
        $moduledata = $DB->get_record($cm->modname, array('id' => $cm->instance));

        // Set the author and submitter.
        $submitter = $eventdata['userid'];
        $author = (!empty($eventdata['relateduserid'])) ? $eventdata['relateduserid'] : $eventdata['userid'];

        switch ($eventdata["eventtype"]) {
            case "quiz_submitted":
                $attempt = \quiz_attempt::create($eventdata['objectid']);
                foreach ($attempt->get_slots() as $slot) {
                    $qa = $attempt->get_question_attempt($slot);

                    if ($qa->get_question()->get_type_name() != 'essay') {
                        continue;
                    }

                    $eventdata['other']['content'] = $qa->get_response_summary();

                    $identifier = sha1($eventdata['other']['content'] . $slot);

                    // Now implement the submmision
                    $submissiontype = "quiz_answer";
                    $result = $this->queue_submission_to_drillbit($cm, $author, $submitter, $identifier, $submissiontype, $eventdata['objectid'], $eventdata['eventtype']);

                    $files = $qa->get_last_qt_files('attachments', $context->id);
                    foreach ($files as $file) {
                        // Queue file for sending to Drillbit.
                        $identifier = $file->get_pathnamehash();
                        $result = $this->queue_submission_to_drillbit(
                            $cm,
                            $author,
                            $submitter,
                            $identifier,
                            'file',
                            $eventdata['objectid'],
                            $eventdata['eventtype']
                        );
                    }
                }
            default:
                $result = true;
                break;
        }
        return $result;
    }

    /**
     * Handles events for the forum module
     * 
     * @param array $eventdata
     * @return bool
     */
    private function forum_events_handler($eventdata)
    {
        global $DB;

        $result = true;
        $pathnamehashes = $eventdata["other"]["pathnamehashes"];
        $userid = $eventdata["userid"];

        $cm = get_coursemodule_from_id($eventdata['other']['modulename'], $eventdata['contextinstanceid']);


        if (!$cm) {
            return true;
        }

        $context = context_module::instance($cm->id);

        // Get module data.
        $moduledata = $DB->get_record($cm->modname, array('id' => $cm->instance));

        // Set the author and submitter.
        $submitter = $eventdata['userid'];
        $author = (!empty($eventdata['relateduserid'])) ? $eventdata['relateduserid'] : $eventdata['userid'];

        $submissiontype = ($cm->modname == 'forum') ? 'forum_post' : 'text_content';

        switch ($eventdata["eventtype"]) {
            case 'content_uploaded':
            case 'assessable_submitted':
                $identifier = sha1($eventdata['other']['content']);

                $result = $this->queue_submission_to_drillbit($cm, $author, $submitter, $identifier, $submissiontype, $eventdata['objectid'], $eventdata['eventtype']);
                break;
            default:
                $result = true;
                break;
        }

        return $result;
    }

    /**
     * Handles events for the workshop module
     * 
     * @param array $eventdata
     * @return bool
     */
    private function workshop_events_handler($eventdata)
    {
        global $DB;

        $result = true;
        $pathnamehashes = $eventdata["other"]["pathnamehashes"];
        $userid = $eventdata["userid"];

        $cm = get_coursemodule_from_id($eventdata['other']['modulename'], $eventdata['contextinstanceid']);



        if (!$cm) {
            return true;
        }

        $context = context_module::instance($cm->id);

        // Get module data.
        $moduledata = $DB->get_record($cm->modname, array('id' => $cm->instance));

        // Set the author and submitter.
        $submitter = $eventdata['userid'];
        $author = (!empty($eventdata['relateduserid'])) ? $eventdata['relateduserid'] : $eventdata['userid'];

        $submissiontype = 'text_content';

        switch ($eventdata["eventtype"]) {
            case 'content_uploaded':
            case 'assessable_submitted':

                $moodlesubmission = $DB->get_record('workshop_submissions', array('id' => $eventdata['objectid']));
                $eventdata['other']['content'] = $moodlesubmission->content;

                $identifier = sha1($eventdata['other']['content']);

                $result = $this->queue_submission_to_drillbit($cm, $author, $submitter, $identifier, $submissiontype, $eventdata['objectid'], $eventdata['eventtype']);
                break;
            default:
                $result = true;
                break;
        }
        return $result;
    }

    private function create_new_drillbit_submission($cm, $userid, $identifier, $submissiontype, $itemid = 0)
    {
        global $DB;
        $updatedd = $DB->get_record('drillbit_plugin_config', ['cm' => $cm->id, 'name' => 'use_drillbit']);
        if ($updatedd->value != 0) {
            $plagiarismfile = new stdClass();
            $plagiarismfile->cm = $cm->id;
            $plagiarismfile->userid = $userid;
            $plagiarismfile->identifier = $identifier;
            $plagiarismfile->statuscode = "queued";
            $plagiarismfile->similarityscore = null;
            $plagiarismfile->attempt = 0;
            $plagiarismfile->itemid = $itemid;
            // $plagiarismfile->lastmodified = strtotime("now");
            $plagiarismfile->submissiontype = $submissiontype;

            if (!$fileid = $DB->insert_record('plagiarism_drillbit_files', $plagiarismfile)) {
                plagiarism_drillbit_activitylog("Insert record failed (CM: " . $cm->id . ", User: " . $userid . ")", "PP_NEW_SUB");
                $fileid = 0;
            }
        } else {
            $fileid = 0;
        }
        return $fileid;
    }

    private function reset_drillbit_submission($cm, $userid, $identifier, $currentsubmission, $submissiontype)
    {
        global $DB;
        $updatedd = $DB->get_record('drillbit_plugin_config', ['cm' => $cm->id, 'name' => 'use_drillbit']);
        if ($updatedd->value != 0) {
            $plagiarismfile = new stdClass();
            $plagiarismfile->id = $currentsubmission->id;
            $plagiarismfile->identifier = $identifier;
            $plagiarismfile->statuscode = "pending";
            $plagiarismfile->similarityscore = null;
            if ($currentsubmission->statuscode != "error") {
                $plagiarismfile->attempt = 1;
            }
            $plagiarismfile->submissiontype = $submissiontype;
            $plagiarismfile->errormsg = null;
            $plagiarismfile->errorcode = null;

            if (!$DB->update_record('plagiarism_drillbit_files', $plagiarismfile)) {
                plagiarism_drillbit_activitylog("Update record failed (CM: " . $cm->id . ", User: " . $userid . ")", "PP_REPLACE_SUB");
            }
        }
    }

    /**
     * Update an errored submission in the files table.
     */
    public function save_errored_submission($submissionid, $attempt, $errorcode)
    {
        global $DB;

        $plagiarismfile = new stdClass();
        $plagiarismfile->id = $submissionid;
        $plagiarismfile->statuscode = 'error';
        $plagiarismfile->attempt = $attempt + 1;
        $plagiarismfile->errorcode = $errorcode;

        if (!$DB->update_record('plagiarism_drillbit_files', $plagiarismfile)) {
            plagiarism_drillbit_activitylog("Update record failed (Submission: " . $submissionid . ") - ", "PP_UPDATE_SUB_ERROR");
        }

        return true;
    }

    /**
     * Save the submission data to the files table.
     */
    public function save_submission(
        $cm,
        $userid,
        $submissionid,
        $identifier,
        $statuscode,
        $drillbitsubmissionid,
        $submitter,
        $itemid,
        $submissiontype,
        $attempt,
        $errorcode = null,
        $errormsg = null
    ) {
        global $DB;
        $updatedd = $DB->get_record('drillbit_plugin_config', ['cm' => $cm->id, 'name' => 'use_drillbit']);
        if ($updatedd->value != 0) {
            $plagiarismfile = new stdClass();
            if ($submissionid != 0) {
                $plagiarismfile->id = $submissionid;
            }
            $plagiarismfile->cm = $cm->id;
            $plagiarismfile->userid = $userid;
            $plagiarismfile->identifier = $identifier;
            $plagiarismfile->statuscode = $statuscode;
            $plagiarismfile->similarityscore = null;
            $plagiarismfile->submissionid = $drillbitsubmissionid;
            $plagiarismfile->errorcode = (empty($errorcode)) ? null : $errorcode;
            $plagiarismfile->errormsg = (empty($errormsg)) ? null : $errormsg;
            $plagiarismfile->attempt = $attempt + 1;
            $plagiarismfile->transmatch = 0;
            $plagiarismfile->lastmodified = time();
            $plagiarismfile->submissiontype = $submissiontype;
            $plagiarismfile->itemid = $itemid;
            $plagiarismfile->submitter = $submitter;

            if ($submissionid != 0) {
                if (!$DB->update_record('plagiarism_drillbit_files', $plagiarismfile)) {
                    plagiarism_drillbit_activitylog("Update record failed (CM: " . $cm->id . ", User: " . $userid . ") - ", "PP_UPDATE_SUB_ERROR");
                }
            } else {
                if (!$DB->insert_record('plagiarism_drillbit_files', $plagiarismfile)) {
                    plagiarism_drillbit_activitylog("Insert record failed (CM: " . $cm->id . ", User: " . $userid . ") - ", "PP_INSERT_SUB_ERROR");
                }
            }
        }
        return true;
    }

    public function is_tutor($context)
    {
        return has_capability($this->get_tutor_capability(), $context);
    }

    public function get_tutor_capability()
    {
        return 'mod/' . 'assign' . ':grade';
    }

    public function user_enrolled_on_course($context, $userid)
    {
        return has_capability('mod/' . $this->modname . ':submit', $context, $userid);
    }

    public function get_author($itemid)
    {
        global $DB;

        if ($submission = $DB->get_record('assign_submission', array('id' => $itemid), 'userid')) {
            return $submission->userid;
        } else {
            return 0;
        }
    }
}

function get_email_by_user_id($userid)
{
    global $DB;

    if ($submission = $DB->get_record('user', array('id' => $userid), 'email')) {
        return $submission->email;
    } else {
        return 0;
    }
}

// function plagiarism_drillbit_coursemodule_standard_elements($formwrapper, $mform)
// {
//     if (is_drillbit_pulgin_enabled()) {
//         $modulename = $formwrapper->get_current()->modulename;
//         if (get_allowed_modules_for_drillbit($modulename)) {
//             global $DB, $PAGE, $COURSE;
//             $cmid = $formwrapper->get_current()->coursemodule;
//             $drillbitview = new plagiarism_drillbit_view();

//             if ($PAGE->pagetype != 'course-editbulkcompletion' && $PAGE->pagetype != 'course-editdefaultcompletion') {
//                 // Create/Edit course in drillbit and join user to class.
//                 $drillbitview->add_elements_to_settings_form($mform, "", "activity", $modulename, $cmid);
//             }
//         }
//     }
// }

function plagiarism_drillbit_coursemodule_edit_post_actions($data, $course)
{
    if (empty($data)) {
        return;
    }
    $plugindrillbit = new plagiarism_plugin_drillbit();

    $plugindrillbit->save_form_elements($data);

    if (is_drillbit_pulgin_enabled()) {
        if (get_allowed_modules_for_drillbit($data->modulename)) {
            if (!empty($data)) {
                $showstudreports = @$data->plagiarism_show_student_reports;
                $exrefval = @$data->plagiarism_exclude_references;
                $exquoteval = @$data->plagiarism_exclude_quotes;
                $exsmallsourceval = @$data->plagiarism_exclude_smallsources;
                $confighashref = @$data->coursemodule . '_plagiarism_exclude_references';
                $confighashquote = @$data->coursemodule . '_plagiarism_exclude_quotes';
                $confighashsmallsource = @$data->coursemodule . '_plagiarism_exclude_smallsources';
                $configshowstudreports = @$data->coursemodule . '_plagiarism_show_student_reports';
                plagiarism_drillbit_update_cm_post_actions(
                    'plagiarism_show_student_reports',
                    $showstudreports,
                    $configshowstudreports,
                    $data->coursemodule
                );
                plagiarism_drillbit_update_cm_post_actions(
                    'plagiarism_exclude_references',
                    $exrefval,
                    $confighashref,
                    $data->coursemodule
                );
                plagiarism_drillbit_update_cm_post_actions(
                    'plagiarism_exclude_quotes',
                    $exquoteval,
                    $confighashquote,
                    $data->coursemodule
                );
                plagiarism_drillbit_update_cm_post_actions(
                    'plagiarism_exclude_smallsources',
                    $exsmallsourceval,
                    $confighashsmallsource,
                    $data->coursemodule
                );
            }
        }
    }

    return $data;
}

/**
 * Add the Drillbit settings form to an add/edit activity page
 *
 * @param moodleform $formwrapper
 * @param MoodleQuickForm $mform
 * @return type
 */
function plagiarism_drillbit_coursemodule_standard_elements($formwrapper, $mform)
{
    $plugindrillbit = new plagiarism_plugin_drillbit();

    $context = context_course::instance($formwrapper->get_course()->id);

    $plugindrillbit->get_form_elements_module(
        $mform,
        $context,
        isset($formwrapper->get_current()->modulename) ? 'mod_' . $formwrapper->get_current()->modulename : ''
    );
}

function plagiarism_drillbit_update_cm_post_actions($name, $value, $hash, $cm)
{
    global $DB;

    if (empty($name)) {
        return;
    }

    //dd([$hash, $cm, $name, $value]);
    $update = $DB->get_record('drillbit_plugin_config', ['config_hash' => $hash]);
    if ($update) {
        $toupdate = new stdClass();
        $toupdate->id = $update->id;
        $toupdate->value = $value;
        if ($value != 0) {
            $DB->update_record("drillbit_plugin_config", $toupdate);
        }
    } else {
        if (!empty($cm) && !empty($value)) {
            $insert = new stdClass();
            $insert->cm = $cm;
            $insert->name = $name;
            $insert->value = $value;
            $insert->config_hash = $hash;
            if ($value != 0) {
                $DB->insert_record('drillbit_plugin_config', $insert);
            }
        }
    }
}


function plagiarism_drillbit_send_queued_submissions()
{

    $resultcode = plagiarism_drillbit_update_expired_jwt_token();

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

        $folderid = get_config("plagiarism_drillbit", "plagiarism_drillbit_folderid");
        $jwt = get_config("plagiarism_drillbit", "jwt");

        foreach ($queueditems as $queueditem) {
            $errorcode = 0;
            $cm = get_coursemodule_from_id('', $queueditem->cm);
            $settings = plagiarism_drillbit_get_cm_settings($queueditem->cm);
            $updatedd = $DB->get_record('drillbit_plugin_config', ['cm' => $cm->id, 'name' => 'use_drillbit']);
            //$insert->value 
            if ($updatedd->value != 0) {
                // Don't proceed if the course module no longer exists.
                if (empty($cm)) {
                    mtrace('File module not found for submission. Identifier: ' . $queueditem->id);
                    $errorcode = 9;
                    $DB->update_record('plagiarism_drillbit_files', array(
                        'id' => $queueditem->id,
                        'statuscode' => 'error',
                        'lastmodified' => time(),
                    ));
                    continue;
                }

                $modconfig = plagiarism_drillbit_get_cm_settings($queueditem->cm);

                switch ($queueditem->submissiontype) {
                    case 'file':
                        $fs = get_file_storage();
                        $file = $fs->get_file_by_hash($queueditem->identifier);
                        if (!$file) {
                            mtrace('File not found for submission. Identifier: ' . $queueditem->id);
                            $errorcode = 9;
                            break;
                        }

                        $title = $file->get_filename();
                        $filename = $file->get_filename();
                        $mime = $file->get_mimetype();

                        try {
                            $textcontent = $file->get_content();
                        } catch (Exception $e) {
                            mtrace($e);
                            mtrace('File content not found on submission. Identifier: ' . $queueditem->identifier);
                            $errorcode = 9;
                            mark_errored_submission($queueditem, 9, "File content not found on submission");
                        }
                        break;
                    case 'text_content':
                        $mime = "text/plain";
                        switch ($cm->modname) {
                            case 'assign':
                                $moodlesubmission = $DB->get_record('assign_submission', array(
                                    'assignment' => $cm->instance,
                                    'userid' => $queueditem->userid, 'id' => $queueditem->itemid
                                ), 'id');
                                $moodletextsubmission = $DB->get_record(
                                    'assignsubmission_onlinetext',
                                    array('submission' => $moodlesubmission->id),
                                    'onlinetext'
                                );
                                $textcontent = $moodletextsubmission->onlinetext;
                                break;
                            case 'workshop':
                                $moodlesubmission = $DB->get_record(
                                    'workshop_submissions',
                                    array('id' => $queueditem->itemid),
                                    'content'
                                );
                                $textcontent = $moodlesubmission->content;
                                break;
                        }

                        $title = 'onlinetext_' . $queueditem->userid . "_" . $cm->id . "_" . $cm->instance . '.txt';
                        $filename = $title;
                        $textcontent = html_to_text($textcontent);
                        break;

                    case 'forum_post':
                        if (!is_null($queueditem->submissionid)) {
                            $apimethod = ($settings["plagiarism_report_gen"] == 0) ? "createSubmission" : "replaceSubmission";
                        }

                        $forumpost = $DB->get_record_select('forum_posts', " userid = ? AND id = ? ", array($queueditem->userid, $queueditem->itemid));

                        if ($forumpost) {
                            $textcontent = strip_tags($forumpost->message);
                            $title = 'forumpost_' . $queueditem->userid . "_" . $cm->id . "_" . $cm->instance . "_" . $queueditem->itemid . '.txt';
                            $filename = $title;
                        } else {
                            $errorcode = 9;
                        }

                        break;

                    case 'quiz_answer':
                        $mime = "text/plain";
                        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                        try {
                            $attempt = quiz_attempt::create($queueditem->itemid);
                        } catch (Exception $e) {
                            mtrace($e->getMessage());
                            mark_errored_submission($queueditem, 9, "Quiz attempt not found");
                            break;
                        }
                        foreach ($attempt->get_slots() as $slot) {
                            $qa = $attempt->get_question_attempt($slot);
                            if ($queueditem->identifier == sha1($qa->get_response_summary() . $slot)) {
                                $textcontent = $qa->get_response_summary();
                                break;
                            }
                        }

                        if (!empty($textcontent)) {
                            $textcontent = strip_tags($textcontent);
                            $title = 'quizanswer_' . $user->id . "_" . $cm->id . "_" . $cm->instance . "_" . $queueditem->itemid . '.txt';
                            $filename = $title;
                        } else {
                            mark_errored_submission($queueditem, 9, "Quiz answer not found");
                        }

                        break;
                    default:
                        mtrace('Unknown submission type. Identifier: ' . $queueditem->id);
                        break;
                }

                $tempfile = null;
                try {
                    $tempfile = plagiarism_drillbit_tempfile($filename, $filename);
                    $fh = fopen($tempfile, "w");
                    fwrite($fh, $textcontent);
                    fclose($fh);
                } catch (Exception $e) {
                    mtrace($e);
                    mtrace('File content not found on submission. Identifier: ' . $queueditem->identifier);
                }

                if (!$tempfile) {
                    continue;
                }

                $postdata = array();
                $postdata["authorName"] = get_email_by_user_id($queueditem->userid);
                $postdata["title"] = $cm->name;
                //$postdata["assignment_id"] = $folderid;
                $postdata["documentType"] = "thesis";

                if (isset($modconfig["plagiarism_exclude_references"])) {
                    $postdata["ex_ref"] = $modconfig["plagiarism_exclude_references"] == "1" ? "yes" : "no";
                } else {
                    $postdata["ex_ref"] = $pluginsettings["plagiarism_exclude_references"] == "1" ? "yes" : "no";
                }

                if (isset($modconfig["plagiarism_exclude_quotes"])) {
                    $postdata["ex_qts"] = $modconfig["plagiarism_exclude_quotes"] == "1" ? "yes" : "no";
                } else {
                    $postdata["ex_qts"] = $pluginsettings["plagiarism_exclude_quotes"] == "1" ? "yes" : "no";
                }

                if (isset($modconfig["plagiarism_exclude_smallsources"])) {
                    $postdata["ex_ss"] = $modconfig["plagiarism_exclude_smallsources"] == "1" ? "yes" : "no";
                } else {
                    $postdata["ex_ss"] = $pluginsettings["plagiarism_exclude_smallsources"] == "1" ? "yes" : "no";
                }

                $postdata["file"] = curl_file_create($tempfile, $mime, $filename);
                $headers = plagiarism_drillbit_get_file_headers($jwt);
                $url = "https://s1.drillbitplagiarismcheck.com/files/moodle/upload";
                $request = plagiarism_drillbit_call_external_api("POST", $url, $postdata, $headers);
                if ($tempfile) {
                    unlink($tempfile);
                }

                plagiarism_drillbit_update_submissions($request, $queueditem->id);
            }
        }
    } else {
        mtrace("Unable to authenticate against Drillbit API. Please contact Drillbit Support");
    }
}

function mark_errored_submission($queueditem, $errorcode = null, $errormsg = null)
{
    global $DB;

    $newstate = array(
        'id' => $queueditem->id,
        'statuscode' => 'error',
        'lastmodified' => time(),
    );

    if ($errorcode) {
        $newstate['errorcode'] = $errorcode;
    }

    if ($errormsg) {
        $newstate['errormsg'] = $errormsg;
    }

    $DB->update_record('plagiarism_drillbit_files', $newstate);
}

function plagiarism_drillbit_update_reports()
{
    global $DB;
    $resultcode = plagiarism_drillbit_update_expired_jwt_token();
    var_dump($resultcode);
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

            if (empty($callback)) {
                mtrace("Callback url is empty. Forming callback url with known params.");
                $paperid = $queueditem->submissionid;
                $callback = "https://s1.drillbitplagiarismcheck.com/extreme/moodle/submission/$paperid";
            }

            $jwt = get_config("plagiarism_drillbit", "jwt");
            $headers = array("Authorization: Bearer $jwt", "Accept: application/json");

            $request = plagiarism_drillbit_call_external_api("GET", $callback, false, $headers);
            var_dump($jwt, $headers, $callback, $request);
            plagiarism_drillbit_update_submissions($request, $queueditem->id);
        }
    } else {
        mtrace("Unable to authenticate against Drillbit API. Please contact Drillbit Support");
    }
}

function plagiarism_drillbit_has_access_to_view_report($cm, $reportfileuser)
{
    global $USER;
    // $coursemodule = get_coursemodule_from_id('assign', $cm);

    // if (empty($coursemodule)) {
    //     echo get_string('reportfailnocm', 'plagiarism_drillbit');
    //     exit(0);
    // }

    $modulecontext = context_module::instance($cm);
    $hascapability = has_capability('plagiarism/drillbit:viewfullreport', $modulecontext);
    $modconfig = plagiarism_drillbit_get_cm_settings($cm);
    $pluginsettings = plagiarism_drillbit_get_plugin_global_settings();
    $cmsettingsforstudent = false;
    $pluginsettingsforstudent = false;

    if (isset($modconfig["plagiarism_show_student_reports"])) {
        $cmsettingsforstudent = (int)$modconfig["plagiarism_show_student_reports"];
    }

    if (isset($pluginsettings["plagiarism_show_student_reports"])) {
        $pluginsettingsforstudent = (int)$pluginsettings["plagiarism_show_student_reports"];
    }

    if ($hascapability) {
        return true;
    } else if ($USER->id == $reportfileuser) {
        $cmcanviewstudent = false;
        $canviewhisown = false;
        if (!empty($modconfig) && $cmsettingsforstudent) {
            $canviewhisown = true;
        } else if (!empty($pluginsettings) && $pluginsettingsforstudent) {
            if (!$cmcanviewstudent && !empty($modconfig)) {
                $canviewhisown = false;
            } else {
                $canviewhisown = true;
            }
        }
        return $canviewhisown;
    } else {
        return false;
    }

    return $hascapability;
}

function plagiarism_drillbit_get_file_headers($authtoken)
{
    $headers = array(
        "Authorization: Bearer $authtoken",
        'Content-type: multipart/form-data'
    );

    return $headers;
}

function plagiarism_drillbit_update_expired_jwt_token()
{
    global $DB;
    $resultcode = 0;
    $email = "";
    $password = "";
    $apikey = "";
    $folderid = "";
    $existingtoken = plagiarism_drillbit_get_existing_jwt_token();
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

    $pluginsettings = (array)get_config("plagiarism_drillbit");

    $apikey = $pluginsettings["plagiarism_drillbit_apikey"];
    $email = $pluginsettings["plagiarism_drillbit_emailid"];
    $folderid = $pluginsettings["plagiarism_drillbit_folderid"];
    $password = $pluginsettings["plagiarism_drillbit_password"];

    if (empty($email) || empty($password) || empty($apikey)) {
        $resultcode = 0;
    }

    $token = plagiarism_drillbit_get_login_token($email, $password, $apikey);
    if ($token != null) {
        $resultcode = 1;
    }

    if ($resultcode) {
        set_config("jwt", $token, "plagiarism_drillbit");
    }

    return $resultcode;
}

function plagiarism_drillbit_get_existing_jwt_token()
{
    global $DB;
    $jwt = get_config("plagiarism_drillbit", "jwt");
    return $jwt;
}

function plagiarism_drillbit_get_login_token($email, $pass, $apikey)
{
    $loginparams = array();
    $loginparams["username"] = $email;
    $loginparams["password"] = $pass;
    $loginparams["api_key"] = $apikey;
    //$loginparams["submissions_key"] = $folderid;
    if (!empty($loginparams)) {
        $jsonrequest = json_encode($loginparams);

        $url = "https://s1.drillbitplagiarismcheck.com/authentication/authenticate/moodle";

        $request = plagiarism_drillbit_call_external_api("POST", $url, $jsonrequest);

        $response = json_decode($request);

        if (isset($response->token)) {
            return $response->token;
        }
    }
    return null;
}

function plagiarism_drillbit_call_external_api($method, $url, $data = false, $headers = array("content-type:application/json"))
{
    $curl = new curl(array('proxy' => true));
    $curloptions = array();
    $curloptions['CURLOPT_RETURNTRANSFER'] = 1;
    $curloptions['CURLOPT_HTTPAUTH'] = CURLAUTH_BASIC;
    $curloptions['CURLOPT_TIMEOUT'] = 60;

    $curl->setHeader($headers);
    $curl->setopt($curloptions);


    $result = null;
    switch ($method) {
        case "POST":
            $result = $curl->post($url, $data);
            break;
        case "PUT":
            $curloptions['CURLOPT_PUT'] = 1;
            break;
        default:
            $result = $curl->get($url, $data);
    }

    return $result;
}

function plagiarism_drillbit_tempfile($filename, $suffix)
{
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

function plagiarism_drillbit_get_cm_settings($cmid)
{
    global $DB;
    $data = $DB->get_records('drillbit_plugin_config', ['cm' => $cmid]);
    $modsettings = [];
    foreach ($data as $key => $value) {
        $modsettings[$value->name] = $value->value;
    }
    return $modsettings;
}

function plagiarism_drillbit_get_plugin_global_settings()
{
    global $DB;
    $drillbitpluginsettings = (array)get_config('plagiarism_drillbit');
    return $drillbitpluginsettings;
}

function is_drillbit_pulgin_enabled()
{
    return get_config("plagiarism_drillbit", "enabled");
}

function plagiarism_drillbit_is_supported_file($filename, $filetype = null)
{
    $supportedfiletypes = array(
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'rtf' => 'text/rtf',
        'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'html' => 'text/html',
        'dot' => 'application/msword',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'ppt' => 'application/vnd.ms-powerpoint',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel',
        'wpd' => 'application/vnd.wordperfect',
        'ps' => 'application/postscript',
    );

    $fileextension = pathinfo($filename, PATHINFO_EXTENSION);

    if (
        array_key_exists($fileextension, $supportedfiletypes) ||
        in_array($filetype, $supportedfiletypes)
    ) {
        return true;
    } else {
        return false;
    }
}

function plagiarism_drillbit_update_submissions($response, $fileid)
{
    global $DB;
    $responseobj = json_decode($response, true);
    //var_dump($response);
    file_put_contents(__DIR__ . '/update_report.log', print_r($responseobj), FILE_APPEND);
    if (isset($responseobj["submissions"])) {
        $responseobj = $responseobj["submissions"];
    }

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

function get_report_download_uri($paperid, $dkey)
{
    return "https://s1.drillbitplagiarismcheck.com/extreme/moodle/submission/$paperid/$dkey/download";
}
/*
function dd($obj, $json = false)
{
    if (!$json) {
        echo "<pre/>";
    }

    print_r($obj);
    exit(0);
}
*/
/**
 * Log activity / errors
 *
 * @param string $string The string describing the activity
 * @param string $activity The activity prompting the log
 * e.g. PRINT_ERROR (default), API_ERROR, INCLUDE, REQUIRE_ONCE, REQUEST, REDIRECT
 */
function plagiarism_drillbit_activitylog($string, $activity)
{
    global $CFG;

    static $config;
    if (empty($config)) {
        $config = plagiarism_plugin_drillbit::plagiarism_drillbit_admin_config();
    }

    if (isset($config->plagiarism_drillbit_enablediagnostic)) {
        // We only keep 10 log files, delete any additional files.
        $prefix = "activitylog_";

        $dirpath = $CFG->tempdir . "/plagiarism_drillbit/logs";
        if (!file_exists($dirpath)) {
            mkdir($dirpath, 0777, true);
        }
        $dir = opendir($dirpath);
        $files = array();
        while ($entry = readdir($dir)) {
            if (substr(basename($entry), 0, 1) != "." and substr_count(basename($entry), $prefix) > 0) {
                $files[] = basename($entry);
            }
        }
        sort($files);
        for ($i = 0; $i < count($files) - 10; $i++) {
            unlink($dirpath . "/" . $files[$i]);
        }

        // Replace <br> tags with new line character.
        $string = str_replace("<br/>", "\r\n", $string);

        // Write to log file.
        $filepath = $dirpath . "/" . $prefix . gmdate('Y-m-d', time()) . ".txt";
        $file = fopen($filepath, 'a');
        $output = date('Y-m-d H:i:s O') . " (" . $activity . ")" . " - " . $string . "\r\n";
        fwrite($file, $output);
        fclose($file);
    }
}

/**
 * Abstracted version of print_error()
 *
 * @param string $input The error string if module = null otherwise the language string called by get_string()
 * @param string $module The module string
 * @param string $param The parameter to send to use as the $a optional object in get_string()
 * @param string $file The file where the error occured
 * @param string $line The line number where the error occured
 */
function plagiarism_drillbit_print_error(
    $input,
    $module = 'plagiarism_drillbit',
    $link = null,
    $param = null,
    $file = __FILE__,
    $line = __LINE__
) {
    global $CFG;

    plagiarism_drillbit_activitylog($input, "PRINT_ERROR");

    $message = (is_null($module)) ? $input : get_string($input, $module, $param);
    $linkid = optional_param('id', 0, PARAM_INT);

    if (is_null($link)) {
        if (substr_count($_SERVER["PHP_SELF"], "assign/view.php") > 0) {
            $mod = "assign";
        } else if (substr_count($_SERVER["PHP_SELF"], "forum/view.php") > 0) {
            $mod = "forum";
        } else if (substr_count($_SERVER["PHP_SELF"], "workshop/view.php") > 0) {
            $mod = "workshop";
        }
        $link = (!empty($linkid)) ? $CFG->wwwroot . '/' . $mod . '/view.php?id=' . $linkid : $CFG->wwwroot;
    }

    if (basename($file) != "lib.php") {
        $message .= ' (' . basename($file) . ' | ' . $line . ')';
    }

    print_error($input, 'plagiarism_turnitin', $link, $message);
    exit();
}

function get_allowed_modules_for_drillbit($module)
{
    $modules = ['assign', 'quiz', 'forum', 'workshop'];
    return in_array($module, $modules);
}
