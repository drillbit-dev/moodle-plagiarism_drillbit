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
 * Plugin setup form for plagiarism_drillbit component
 *
 * @package   plagiarism_drillbit
 * @copyright 2018 Drillbit
 * @author    Kavimukil <kavimukil.a@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot.'/plagiarism/drillbit/lib.php');
require_once($CFG->libdir."/formslib.php");

class plagiarism_drillbit_setup_form extends moodleform {
    public function definition() {
        global $DB, $CFG;

        $mform = $this->_form;

        $mform->disable_form_change_checker();

        $mform->addElement('header', 'config', get_string('drillbitconfig', 'plagiarism_drillbit'));
        $mform->addElement('html', get_string('drillbitexplain', 'plagiarism_drillbit').'</br></br>');

        // Loop through all modules that support Plagiarism.
        $mods = array_keys(core_component::get_plugin_list('mod'));
        foreach ($mods as $mod) {
            if (plugin_supports('mod', $mod, FEATURE_PLAGIARISM)) {
                if ($mod == "assign") {
                    $mform->addElement('advcheckbox',
                    'plagiarism_drillbit_mod_'.$mod,
                    get_string('usedrillbit_mod', 'plagiarism_drillbit', ucfirst($mod)),
                    '',
                    null,
                    array(0, 1)
                    );
                }
            }
        }

        $mform->addElement('header', 'plagiarism_drillbit',
        get_string('drillbitaccountconfig', 'plagiarism_drillbit'));
        $mform->setExpanded('plagiarism_drillbit');

        $mform->addElement('text', 'plagiarism_drillbit_emailid',
        get_string('drillbitemailid', 'plagiarism_drillbit'));
        $mform->setType('plagiarism_drillbit_emailid', PARAM_TEXT);

        $mform->addElement('passwordunmask', 'plagiarism_drillbit_password',
        get_string('drillbitpassword', 'plagiarism_drillbit'));

        $mform->addElement('text', 'plagiarism_drillbit_folderid',
        get_string('drillbitfolderid', 'plagiarism_drillbit'));
        $mform->setType('plagiarism_drillbit_folderid', PARAM_TEXT);

        $mform->addElement('text', 'plagiarism_drillbit_apikey',
        get_string('drillbitapikey', 'plagiarism_drillbit'));
        $mform->setType('plagiarism_drillbit_apikey', PARAM_TEXT);

        $options = array(
            'https://www.drillbitplagiarismcheck.com' => 'https://www.drillbitplagiarismcheck.com',
            'https://api.drillbit.com' => 'https://api.drillbit.com',
        );

        // Set $CFG->turnitinqa and add URLs to $CFG->turnitinqaurls array in config.php file for testing other environments.
        if (!empty($CFG->turnitinqa)) {
            foreach ($CFG->turnitinqaurls as $url) {
                $options[$url] = $url;
            }
        }

        $mform->addElement('select', 'plagiarism_drillbit_apiurl', get_string('drillbitapiurl', 'plagiarism_drillbit'), $options);

        $mform->addElement('button', 'connection_test', get_string("connecttest", 'plagiarism_drillbit'));

        $mform->addElement('html', '<div id="api_conn_result" class="api_conn_result"></div>');
        $mform->addElement('header', 'plagiarism_drillbit_plugin_default_settings',
        get_string('drillbitplugindefaultsettings', 'plagiarism_drillbit'));
        $mform->setExpanded('plagiarism_drillbit_plugin_default_settings');
        $options = array(0 => get_string('no', 'plagiarism_drillbit'),
        1 => get_string('yes', 'plagiarism_drillbit'));
        $excludereferencesselect = $mform->addElement('select', 'plagiarism_exclude_references',
        get_string("excludereferences", "plagiarism_drillbit"), $options);
        $excludereferencesselect->setSelected('0');
        $excludequotesselect = $mform->addElement('select', 'plagiarism_exclude_quotes',
        get_string("excludequotes", "plagiarism_drillbit"), $options);
        $excludequotesselect->setSelected('1');

        $excludesmallsources = $mform->addElement('select', 'plagiarism_exclude_smallsources',
         get_string("excludesmallsources", "plagiarism_drillbit"), $options);
        $excludesmallsources->setSelected('0');
        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        return array();
    }
}