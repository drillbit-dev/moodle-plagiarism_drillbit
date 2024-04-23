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

// use Integrations\PhpSdk\TiiLTI;

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}

require_once($CFG->dirroot . '/plagiarism/drillbit/lib.php');

class drillbit_view
{

    /**
     * Abstracted version of print_header() / header()
     *
     * @param string $url The URL of the page
     * @param string $title Appears at the top of the window
     * @param string $heading Appears at the top of the page
     * @param bool $return If true, return the visible elements of the header instead of echoing them.
     * @return mixed If return=true then string else void
     */
    public function output_header($url, $title = '', $heading = '', $return = false)
    {
        global $PAGE, $OUTPUT;

        $PAGE->set_url($url);
        $PAGE->set_title($title);
        $PAGE->set_heading($heading);

        if ($return) {
            return $OUTPUT->header();
        } else {
            echo $OUTPUT->header();
        }
    }

    /**
     * Prints the tab menu for the plugin settings
     *
     * @param string $currenttab The currect tab to be styled as selected
     */
    public function draw_settings_tab_menu($currenttab, $notice = null)
    {
        global $OUTPUT;

        $tabs = array();
        $tabs[] = new tabobject(
            'drillbitsettings',
            'settings.php',
            get_string('config', 'plagiarism_drillbit'),
            get_string('config', 'plagiarism_drillbit'),
            false
        );
        $tabs[] = new tabobject(
            'drillbitdefaults',
            'settings.php?do=defaults',
            get_string('defaults', 'plagiarism_drillbit'),
            get_string('defaults', 'plagiarism_drillbit'),
            false
        );
        $tabs[] = new tabobject('dbexport', new moodle_url('/plagiarism/drillbit/dbexport.php'), get_string('dbexport', 'plagiarism_drillbit'));
        $tabs[] = new tabobject(
            'apilog',
            'settings.php?do=apilog',
            get_string('logs'),
            get_string('logs'),
            false
        );
        $tabs[] = new tabobject(
            'unlinkusers',
            'settings.php?do=unlinkusers',
            get_string('unlinkusers', 'plagiarism_drillbit'),
            get_string('unlinkusers', 'plagiarism_drillbit'),
            false
        );
        $tabs[] = new tabobject(
            'drillbiterrors',
            'settings.php?do=errors',
            get_string('errors', 'plagiarism_drillbit'),
            get_string('errors', 'plagiarism_drillbit'),
            false
        );
        print_tabs(array($tabs), $currenttab);

        if (!is_null($notice)) {
            echo $OUTPUT->box($notice["message"], 'generalbox boxaligncenter', $notice["type"]);
        }
    }

    /**
     * Due to moodle's internal plugin hooks we can not use our bespoke form class for drillbit
     * settings. This form shows in settings > defaults as well as the activity creation screen.
     *
     * @global type $CFG
     * @param type $plugin_defaults
     * @return type
     */
    public function add_elements_to_settings_form($mform, $course, $location = "activity", $modulename = "", $cmid = 0, $currentrubric = 0)
    {
        global $PAGE, $USER, $DB;

        // Include JS strings
        $PAGE->requires->string_for_js('changerubricwarning', 'plagiarism_drillbit');
        $PAGE->requires->string_for_js('closebutton', 'plagiarism_drillbit');

        $config = plagiarism_plugin_drillbit::plagiarism_drillbit_admin_config();
        $configwarning = '';
        $rubrics = array();

        if ($location == "activity" && $modulename != 'mod_forum') {
            $instructor = new drillbit_user($USER->id, 'Instructor');

            $instructor->join_user_to_class($course->drillbit_cid);

            $rubrics = array(get_string('attachrubric', 'plagiarism_drillbit') => $instructor->get_instructor_rubrics());

            // Get rubrics that are shared on the account.
            $drillbitclass = new drillbit_class($course->id);
            $drillbitclass->sharedrubrics = array();
            $drillbitclass->read_class_from_tii();

            // This will ensure all rubric keys are integers.
            $rubricsnew = array(0 => get_string('norubric', 'plagiarism_drillbit'));
            foreach ($rubrics as $options => $rubriclist) {
                foreach ($rubriclist as $key => $value) {
                    $rubricsnew[$key] = $value;
                }
            }
            $rubricsnew = array($options => $rubricsnew);

            // Merge the arrays, prioritising instructor owned arrays.
            $rubrics = array_merge($rubricsnew, $drillbitclass->sharedrubrics);
        }

        $options = array(0 => get_string('no'), 1 => get_string('yes'));
        $plagiarismdrillbit = new plagiarism_plugin_drillbit();
        $genparams = $plagiarismdrillbit->plagiarism_get_report_gen_speed_params();
        $genoptions = array(
            0 => get_string('genimmediately1', 'plagiarism_drillbit'),
            1 => get_string('genimmediately2', 'plagiarism_drillbit', $genparams),
            2 => get_string('genduedate', 'plagiarism_drillbit')
        );
        $excludetypeoptions = array(
            0 => get_string('no'), 1 => get_string('excludewords', 'plagiarism_drillbit'),
            2 => get_string('excludepercent', 'plagiarism_drillbit')
        );

        if ($location == "defaults") {
            $mform->addElement('header', 'plugin_header', get_string('drillbitdefaults', 'plagiarism_drillbit'));
            $mform->addElement('html', get_string("defaultsdesc", "plagiarism_drillbit"));
        }

        if ($location != "defaults") {
            $mform->addElement('header', 'plugin_header', get_string('drillbitpluginsettings', 'plagiarism_drillbit'));

            // Add in custom Javascript and CSS.
            $PAGE->requires->jquery_plugin('ui');
            $PAGE->requires->js_call_amd('plagiarism_drillbit/peermark', 'peermarkLaunch');
            $PAGE->requires->js_call_amd('plagiarism_drillbit/quickmark', 'quickmarkLaunch');
            $PAGE->requires->js_call_amd('plagiarism_drillbit/rubric', 'rubric');
            $PAGE->requires->js_call_amd('plagiarism_drillbit/refresh_submissions', 'refreshSubmissions');

            // Refresh Grades.
            $refreshgrades = '';
            if ($cmid != 0) {
                // If assignment has submissions then show a refresh grades button.
                $numsubs = $DB->count_records('plagiarism_drillbit_files', array('cm' => $cmid));
                if ($numsubs > 0) {
                    $refreshgrades = html_writer::tag(
                        'div',
                        html_writer::tag(
                            'span',
                            get_string('drillbitrefreshsubmissions', 'plagiarism_drillbit')
                        ),
                        array(
                            'class' => 'plagiarism_drillbit_refresh_grades',
                            'tabindex' => 0,
                            'role' => 'link'
                        )
                    );

                    $refreshgrades .= html_writer::tag(
                        'div',
                        html_writer::tag('span', get_string('drillbitrefreshingsubmissions', 'plagiarism_drillbit')),
                        array('class' => 'plagiarism_drillbit_refreshing_grades')
                    );
                }
            }

            // Quickmark Manager.
            $quickmarkmanagerlink = '';
            if ($config->plagiarism_drillbit_usegrademark) {

                $quickmarkmanagerlink .= html_writer::tag(
                    'a',
                    get_string('launchquickmarkmanager', 'plagiarism_drillbit'),
                    array(
                        'href' => '#',
                        'class' => 'plagiarism_drillbit_quickmark_manager_launch',
                        'id' => 'quickmark_manager_form',
                        'tabindex' => 0
                    )
                );

                $quickmarkmanagerlink = html_writer::tag('div', $quickmarkmanagerlink, array('class' => 'row_quickmark_manager'));
            }

            $usedrillbit = $DB->get_record('plagiarism_drillbit_config', array('cm' => $cmid, 'name' => 'use_drillbit'));

            // Peermark Manager.
            $peermarkmanagerlink = '';
            if (!empty($config->plagiarism_drillbit_enablepeermark) && !empty($usedrillbit->value)) {
                if ($cmid != 0) {
                    $peermarkmanagerlink .= html_writer::tag(
                        'a',
                        get_string('launchpeermarkmanager', 'plagiarism_drillbit'),
                        array(
                            'href' => '#',
                            'class' => 'peermark_manager_launch',
                            'id' => 'peermark_manager_form',
                            'tabindex' => 0
                        )
                    );
                    $peermarkmanagerlink = html_writer::tag('div', $peermarkmanagerlink, array('class' => 'row_peermark_manager'));
                }
            }

            if (!empty($quickmarkmanagerlink) || !empty($peermarkmanagerlink) || !empty($refreshgrades)) {
                $mform->addElement('static', 'static', '', $refreshgrades . $quickmarkmanagerlink . $peermarkmanagerlink);
            }
        }

        $locks = $DB->get_records_sql("SELECT name, value FROM {plagiarism_drillbit_config} WHERE cm IS NULL");

        if (empty($configwarning)) {
            $mform->addElement('select', 'use_drillbit', get_string("usedrillbit", "plagiarism_drillbit"), $options);
            $this->lock($mform, $location, $locks);

            $mform->addElement('select', 'plagiarism_show_student_report', get_string("studentreports", "plagiarism_drillbit"), $options);
            $this->lock($mform, $location, $locks);
            $mform->addHelpButton('plagiarism_show_student_report', 'studentreports', 'plagiarism_drillbit');

            if ($mform->elementExists('submissiondrafts') || $location == 'defaults') {
                $tiidraftoptions = array(
                    0 => get_string("submitondraft", "plagiarism_drillbit"),
                    1 => get_string("submitonfinal", "plagiarism_drillbit")
                );

                $mform->addElement('select', 'plagiarism_draft_submit', get_string("draftsubmit", "plagiarism_drillbit"), $tiidraftoptions);
                $this->lock($mform, $location, $locks);
                $mform->disabledIf('plagiarism_draft_submit', 'submissiondrafts', 'eq', 0);
            }

            $mform->addElement('select', 'plagiarism_allow_non_or_submissions', get_string("allownonor", "plagiarism_drillbit"), $options);
            $this->lock($mform, $location, $locks);
            $mform->addHelpButton('plagiarism_allow_non_or_submissions', 'allownonor', 'plagiarism_drillbit');

            $suboptions = array(
                0 => get_string('norepository', 'plagiarism_drillbit'),
                1 => get_string('standardrepository', 'plagiarism_drillbit')
            );
            switch ($config->plagiarism_drillbit_repositoryoption) {
                case 0; // Standard options.
                    $mform->addElement('select', 'plagiarism_submitpapersto', get_string('submitpapersto', 'plagiarism_drillbit'), $suboptions);
                    $mform->addHelpButton('plagiarism_submitpapersto', 'submitpapersto', 'plagiarism_drillbit');
                    $this->lock($mform, $location, $locks);
                    break;
                case PLAGIARISM_DRILLBIT_ADMIN_REPOSITORY_OPTION_EXPANDED; // Standard options + Allow Instituional Repository.
                    $suboptions[PLAGIARISM_DRILLBIT_SUBMIT_TO_INSTITUTIONAL_REPOSITORY] = get_string('institutionalrepository', 'plagiarism_drillbit');

                    $mform->addElement('select', 'plagiarism_submitpapersto', get_string('submitpapersto', 'plagiarism_drillbit'), $suboptions);
                    $mform->addHelpButton('plagiarism_submitpapersto', 'submitpapersto', 'plagiarism_drillbit');
                    $this->lock($mform, $location, $locks);
                    break;
                case PLAGIARISM_DRILLBIT_ADMIN_REPOSITORY_OPTION_FORCE_STANDARD; // Force Standard Repository.
                    $mform->addElement('hidden', 'plagiarism_submitpapersto', PLAGIARISM_DRILLBIT_SUBMIT_TO_STANDARD_REPOSITORY);
                    $mform->setType('plagiarism_submitpapersto', PARAM_RAW);
                    break;
                case PLAGIARISM_DRILLBIT_ADMIN_REPOSITORY_OPTION_FORCE_NO; // Force No Repository.
                    $mform->addElement('hidden', 'plagiarism_submitpapersto', PLAGIARISM_DRILLBIT_SUBMIT_TO_NO_REPOSITORY);
                    $mform->setType('plagiarism_submitpapersto', PARAM_RAW);
                    break;
                case PLAGIARISM_DRILLBIT_ADMIN_REPOSITORY_OPTION_FORCE_INSTITUTIONAL; // Force Institutional Repository.
                    $mform->addElement('hidden', 'plagiarism_submitpapersto', PLAGIARISM_DRILLBIT_SUBMIT_TO_INSTITUTIONAL_REPOSITORY);
                    $mform->setType('plagiarism_submitpapersto', PARAM_RAW);
                    break;
            }

            $mform->addElement('html', html_writer::tag(
                'div',
                get_string('checkagainstnote', 'plagiarism_drillbit'),
                array('class' => 'drillbit_checkagainstnote')
            ));

            $mform->addElement('select', 'plagiarism_compare_student_papers', get_string("spapercheck", "plagiarism_drillbit"), $options);
            $this->lock($mform, $location, $locks);
            $mform->addHelpButton('plagiarism_compare_student_papers', 'spapercheck', 'plagiarism_drillbit');

            $mform->addElement('select', 'plagiarism_compare_internet', get_string("internetcheck", "plagiarism_drillbit"), $options);
            $this->lock($mform, $location, $locks);
            $mform->addHelpButton('plagiarism_compare_internet', 'internetcheck', 'plagiarism_drillbit');

            $mform->addElement('select', 'plagiarism_compare_journals', get_string("journalcheck", "plagiarism_drillbit"), $options);
            $this->lock($mform, $location, $locks);
            $mform->addHelpButton('plagiarism_compare_journals', 'journalcheck', 'plagiarism_drillbit');

            if (
                $config->plagiarism_drillbit_repositoryoption == PLAGIARISM_DRILLBIT_ADMIN_REPOSITORY_OPTION_EXPANDED ||
                $config->plagiarism_drillbit_repositoryoption == PLAGIARISM_DRILLBIT_ADMIN_REPOSITORY_OPTION_FORCE_INSTITUTIONAL
            ) {
                $mform->addElement(
                    'select',
                    'plagiarism_compare_institution',
                    get_string('compareinstitution', 'plagiarism_drillbit'),
                    $options
                );
                $this->lock($mform, $location, $locks);
            }

            $mform->addElement('select', 'plagiarism_report_gen', get_string("reportgenspeed", "plagiarism_drillbit"), $genoptions);
            $this->lock($mform, $location, $locks);
            $mform->addHelpButton('plagiarism_report_gen', 'reportgenspeed', 'plagiarism_drillbit');

            $mform->addElement('select', 'plagiarism_exclude_biblio', get_string("excludebiblio", "plagiarism_drillbit"), $options);
            $this->lock($mform, $location, $locks);
            $mform->addHelpButton('plagiarism_exclude_biblio', 'excludebiblio', 'plagiarism_drillbit');

            $mform->addElement('select', 'plagiarism_exclude_quoted', get_string("excludequoted", "plagiarism_drillbit"), $options);
            $this->lock($mform, $location, $locks);
            $mform->addHelpButton('plagiarism_exclude_quoted', 'excludequoted', 'plagiarism_drillbit');

            $mform->addElement(
                'select',
                'plagiarism_exclude_matches',
                get_string("excludevalue", "plagiarism_drillbit"),
                $excludetypeoptions
            );
            $this->lock($mform, $location, $locks);
            $mform->addHelpButton('plagiarism_exclude_matches', 'excludevalue', 'plagiarism_drillbit');

            $mform->addElement('text', 'plagiarism_exclude_matches_value', get_string("excludesmallmatchesvalue", "plagiarism_drillbit"));
            $mform->setType('plagiarism_exclude_matches_value', PARAM_INT);
            $mform->addRule('plagiarism_exclude_matches_value', null, 'numeric', null, 'client');
            $mform->disabledIf('plagiarism_exclude_matches_value', 'plagiarism_exclude_matches', 'eq', 0);

            if ($location == 'defaults') {
                $mform->addElement('text', 'plagiarism_locked_message', get_string("locked_message", "plagiarism_drillbit"), 'maxlength="50" size="50"');
                $mform->setType('plagiarism_locked_message', PARAM_TEXT);
                $mform->setDefault('plagiarism_locked_message', get_string("locked_message_default", "plagiarism_drillbit"));
                $mform->addHelpButton('plagiarism_locked_message', 'locked_message', 'plagiarism_drillbit');
            }

            if ($location == "activity" && $modulename != "mod_forum" && $config->plagiarism_drillbit_usegrademark) {
                if (!empty($currentrubric)) {
                    $attachrubricstring = get_string('attachrubric', 'plagiarism_drillbit');
                    if (!isset($rubrics[$attachrubricstring][$currentrubric])) {
                        $rubrics[$attachrubricstring][$currentrubric] = get_string('otherrubric', 'plagiarism_drillbit');
                    }
                }

                $rubricmanagerlink = html_writer::tag(
                    'span',
                    get_string('launchrubricmanager', 'plagiarism_drillbit'),
                    array(
                        'class' => 'rubric_manager_launch',
                        'data-courseid' => $course->id,
                        'data-cmid' => $cmid,
                        'title' => get_string('launchrubricmanager', 'plagiarism_drillbit'),
                        'id' => 'rubric_manager_form',
                        'role' => 'link',
                        'tabindex' => '0'
                    )
                );

                $rubricmanagerlink = html_writer::tag('div', $rubricmanagerlink, array('class' => 'row_rubric_manager'));
                $mform->addElement('selectgroups', 'plagiarism_rubric', get_string('attachrubric', 'plagiarism_drillbit'), $rubrics);
                $mform->addElement('static', 'rubric_link', '', $rubricmanagerlink);
                $mform->setDefault('plagiarism_rubric', '');

                $mform->addElement('hidden', 'rubric_warning_seen', '');
                $mform->setType('rubric_warning_seen', PARAM_RAW);

                $mform->addElement('static', 'rubric_note', '', get_string('attachrubricnote', 'plagiarism_drillbit'));
            } else {
                $mform->addElement('hidden', 'plagiarism_rubric', '');
                $mform->setType('plagiarism_rubric', PARAM_RAW);
            }

            if (!empty($config->plagiarism_drillbit_useerater)) {
                $handbookoptions = array(
                    1 => get_string('erater_handbook_advanced', 'plagiarism_drillbit'),
                    2 => get_string('erater_handbook_highschool', 'plagiarism_drillbit'),
                    3 => get_string('erater_handbook_middleschool', 'plagiarism_drillbit'),
                    4 => get_string('erater_handbook_elementary', 'plagiarism_drillbit'),
                    5 => get_string('erater_handbook_learners', 'plagiarism_drillbit')
                );

                $dictionaryoptions = array(
                    'en_US' => get_string('erater_dictionary_enus', 'plagiarism_drillbit'),
                    'en_GB' => get_string('erater_dictionary_engb', 'plagiarism_drillbit'),
                    'en'    => get_string('erater_dictionary_en', 'plagiarism_drillbit')
                );
                $mform->addElement('select', 'plagiarism_erater', get_string('erater', 'plagiarism_drillbit'), $options);
                $mform->setDefault('plagiarism_erater', 0);

                $mform->addElement(
                    'select',
                    'plagiarism_erater_handbook',
                    get_string('erater_handbook', 'plagiarism_drillbit'),
                    $handbookoptions
                );
                $mform->setDefault('plagiarism_erater_handbook', 2);
                $mform->disabledIf('plagiarism_erater_handbook', 'plagiarism_erater', 'eq', 0);

                $mform->addElement(
                    'select',
                    'plagiarism_erater_dictionary',
                    get_string('erater_dictionary', 'plagiarism_drillbit'),
                    $dictionaryoptions
                );
                $mform->setDefault('plagiarism_erater_dictionary', 'en_US');
                $mform->disabledIf('plagiarism_erater_dictionary', 'plagiarism_erater', 'eq', 0);

                $mform->addElement(
                    'checkbox',
                    'plagiarism_erater_spelling',
                    get_string('erater_categories', 'plagiarism_drillbit'),
                    " " . get_string('erater_spelling', 'plagiarism_drillbit')
                );
                $mform->disabledIf('plagiarism_erater_spelling', 'plagiarism_erater', 'eq', 0);

                $mform->addElement('checkbox', 'plagiarism_erater_grammar', '', " " . get_string('erater_grammar', 'plagiarism_drillbit'));
                $mform->disabledIf('plagiarism_erater_grammar', 'plagiarism_erater', 'eq', 0);

                $mform->addElement('checkbox', 'plagiarism_erater_usage', '', " " . get_string('erater_usage', 'plagiarism_drillbit'));
                $mform->disabledIf('plagiarism_erater_usage', 'plagiarism_erater', 'eq', 0);

                $mform->addElement('checkbox', 'plagiarism_erater_mechanics', '', " " .
                    get_string('erater_mechanics', 'plagiarism_drillbit'));
                $mform->disabledIf('plagiarism_erater_mechanics', 'plagiarism_erater', 'eq', 0);

                $mform->addElement('checkbox', 'plagiarism_erater_style', '', " " . get_string('erater_style', 'plagiarism_drillbit'));
                $mform->disabledIf('plagiarism_erater_style', 'plagiarism_erater', 'eq', 0);
            }

            $mform->addElement('html', html_writer::tag(
                'div',
                get_string('anonblindmarkingnote', 'plagiarism_drillbit'),
                array('class' => 'drillbit_anonblindmarkingnote')
            ));

            if ($config->plagiarism_drillbit_transmatch) {
                $mform->addElement('select', 'plagiarism_transmatch', get_string("transmatch", "plagiarism_drillbit"), $options);
            } else {
                $mform->addElement('hidden', 'plagiarism_transmatch', 0);
            }
            $mform->setType('plagiarism_transmatch', PARAM_INT);

            $mform->addElement('hidden', 'action', "defaults");
            $mform->setType('action', PARAM_RAW);
        } else {
            $mform->addElement('hidden', 'use_drillbit', 0);
            $mform->setType('use_drillbit', PARAM_INT);
        }

        // Disable the form change checker - added in 2.3.2.
        if (is_callable(array($mform, 'disable_form_change_checker'))) {
            $mform->disable_form_change_checker();
        }
    }

    public function show_file_errors_table($page = 0)
    {
        global $CFG, $OUTPUT;

        $limit = 100;
        $offset = $page * $limit;

        $plagiarismplugindrillbit = new plagiarism_plugin_drillbit();
        $filescount = $plagiarismplugindrillbit->get_file_upload_errors(0, 0, true);
        $files = $plagiarismplugindrillbit->get_file_upload_errors($offset, $limit);

        $baseurl = new moodle_url('/plagiarism/drillbit/settings.php', array('do' => 'errors'));
        $pagingbar = $OUTPUT->paging_bar($filescount, $page, $limit, $baseurl);

        // Do the table headers.
        $cells = array();
        $selectall = html_writer::checkbox('errors_select_all', false, false, '', array("class" => "select_all_checkbox"));
        $cells["checkbox"] = new html_table_cell($selectall);
        $cells["id"] = new html_table_cell(get_string('id', 'plagiarism_drillbit'));
        $cells["user"] = new html_table_cell(get_string('student', 'plagiarism_drillbit'));
        $cells["user"]->attributes['class'] = 'left';
        $cells["course"] = new html_table_cell(get_string('course', 'plagiarism_drillbit'));
        $cells["module"] = new html_table_cell(get_string('module', 'plagiarism_drillbit'));
        $cells["file"] = new html_table_cell(get_string('file'));
        $cells["error"] = new html_table_cell(get_string('error'));
        $cells["delete"] = new html_table_cell('&nbsp;');
        $cells["delete"]->attributes['class'] = 'centered_cell';

        $table = new html_table();
        $table->head = $cells;

        $i = 0;
        $rows = array();

        if (count($files) == 0) {
            $cells = array();
            $cells["checkbox"] = new html_table_cell(get_string('semptytable', 'plagiarism_drillbit'));
            $cells["checkbox"]->colspan = 8;
            $cells["checkbox"]->attributes['class'] = 'centered_cell';
            $rows[0] = new html_table_row($cells);
        } else {
            foreach ($files as $k => $v) {
                $cells = array();
                if (!empty($v->moduletype) && $v->moduletype != "forum") {

                    $cm = get_coursemodule_from_id($v->moduletype, $v->cm);

                    $checkbox = html_writer::checkbox('check_' . $k, $k, false, '', array("class" => "errors_checkbox"));
                    $cells["checkbox"] = new html_table_cell($checkbox);

                    $cells["id"] = new html_table_cell($k);
                    $cells["user"] = new html_table_cell($v->firstname . " " . $v->lastname . " (" . $v->email . ")");

                    $courselink = new moodle_url($CFG->wwwroot . '/course/view.php', array('id' => $v->courseid));
                    $cells["course"] = new html_table_cell(html_writer::link(
                        $courselink,
                        $v->coursename,
                        array('title' => $v->coursename)
                    ));

                    $modulelink = new moodle_url($CFG->wwwroot . '/mod/' . $v->moduletype . '/view.php', array('id' => $v->cm));
                    $cells["module"] = new html_table_cell(html_writer::link($modulelink, $cm->name, array('title' => $cm->name)));

                    if ($v->submissiontype == "file") {
                        $fs = get_file_storage();
                        if ($file = $fs->get_file_by_hash($v->identifier)) {
                            $cells["file"] = new html_table_cell(html_writer::link(
                                $CFG->wwwroot . '/pluginfile.php/' .
                                    $file->get_contextid() . '/' . $file->get_component() . '/' . $file->get_filearea() . '/' .
                                    $file->get_itemid() . '/' . $file->get_filename(),
                                $OUTPUT->pix_icon('fileicon', 'open ' . $file->get_filename(), 'plagiarism_drillbit') .
                                    " " . $file->get_filename()
                            ));
                        } else {
                            $cells["file"] = get_string('filedoesnotexist', 'plagiarism_drillbit');
                        }
                    } else {
                        $cells["file"] = str_replace('_', ' ', ucfirst($v->submissiontype));
                    }

                    $errorcode = $v->errorcode;
                    // Deal with legacy error issues.
                    if (is_null($errorcode)) {
                        $errorcode = 0;
                        if ($v->submissiontype == 'file') {
                            if (is_object($file) && $file->get_filesize() > PLAGIARISM_DRILLBIT_MAX_FILE_UPLOAD_SIZE) {
                                $errorcode = 2;
                            }
                        }
                    }

                    // Show error message if there is one.
                    $errormsg = $v->errormsg;
                    if ($errorcode == 0) {
                        $errorstring = (is_null($errormsg)) ? get_string('ppsubmissionerrorseelogs', 'plagiarism_drillbit') : $errormsg;
                    } else {
                        $errorstring = get_string(
                            'errorcode' . $v->errorcode,
                            'plagiarism_drillbit',
                            display_size(PLAGIARISM_DRILLBIT_MAX_FILE_UPLOAD_SIZE)
                        );
                    }
                    $cells["error"] = $errorstring;

                    $fnd = array("\n", "\r");
                    $rep = array('\n', '\r');
                    $string = str_replace($fnd, $rep, get_string('deleteconfirm', 'plagiarism_drillbit'));

                    $attributes["onclick"] = "return confirm('" . $string . "');";
                    $cells["delete"] = new html_table_cell(html_writer::link(
                        $CFG->wwwroot .
                            '/plagiarism/drillbit/settings.php?do=errors&action=deletefile&id=' . $k,
                        $OUTPUT->pix_icon(
                            'delete',
                            get_string('deletesubmission', 'plagiarism_drillbit'),
                            'plagiarism_drillbit'
                        ),
                        $attributes
                    ));
                    $cells["delete"]->attributes['class'] = 'centered_cell';

                    $rows[$i] = new html_table_row($cells);
                    $i++;
                }
            }

            if ($i == 0) {
                $cells = array();
                $cells["checkbox"] = new html_table_cell(get_string('semptytable', 'plagiarism_drillbit'));
                $cells["checkbox"]->colspan = 8;
                $cells["checkbox"]->attributes['class'] = 'centered_cell';
                $rows[0] = new html_table_row($cells);
            } else {
                $table->id = "ppErrors";
            }
        }
        $table->data = $rows;
        $output = html_writer::table($table);

        return $pagingbar . $output . $pagingbar;
    }

    /**
     * This adds a site lock check to the most recently added field
     */
    public function lock($mform, $location, $locks)
    {

        $field = end($mform->_elements)->_attributes['name'];
        if ($location == 'defaults') {
            // If we are on the site config level, show the lock UI.
            $mform->addElement('advcheckbox', $field . '_lock', '', get_string('locked', 'admin'), array('group' => 1));
        } else {

            // If we are at the plugin level, and we are locked then freeze.
            $locked = (isset($locks[$field . '_lock']->value)) ? $locks[$field . '_lock']->value : 0;
            if ($locked) {
                $mform->freeze($field);
                // Show custom message why.
                $msg = $locks['plagiarism_locked_message']->value;
                if ($msg) {
                    $mform->addElement('static', $field . '_why', '', $msg);
                }
            }
        }
    }
}
