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

use Integrations\PhpSdk\TiiLTI;

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}

require_once($CFG->dirroot.'/plagiarism/drillbit/lib.php');

class drillbit_view { 

    public function output_header($url, $title = '', $heading = '', $return = false) {
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

    public function add_elements_to_settings_form($mform, $course, $location = "activity", $modulename = "", $cmid = 0, $currentrubric = 0) {
        if ($location == "activity" && $modulename != 'mod_forum') {
            $mform->addElement('header', 'plagiarism_drillbit_plugin_default_settings', get_string('drillbitplugindefaultsettings', 'plagiarism_drillbit'));
            $mform->setExpanded('plagiarism_drillbit_plugin_default_settings');

            $options = array(0 => get_string('no', 'plagiarism_drillbit'), 1 => get_string('yes', 'plagiarism_drillbit'));
            
            $excludeReferencesSelect = $mform->addElement('select', 'plagiarism_exclude_references', get_string("excludereferences", "plagiarism_drillbit"), $options);
            $excludeReferencesSelect->setSelected('0');

            $excludeQuotesSelect = $mform->addElement('select', 'plagiarism_exclude_quotes', get_string("excludequotes", "plagiarism_drillbit"), $options);
            $excludeQuotesSelect->setSelected('1');

            $excludeSmallSources = $mform->addElement('select', 'plagiarism_exclude_smallsources', get_string("excludesmallsources", "plagiarism_drillbit"), $options);
            $excludeSmallSources->setSelected('0');
        }
    }
}