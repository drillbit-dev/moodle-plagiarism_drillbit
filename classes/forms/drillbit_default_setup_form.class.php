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
 * @copyright 2018 drillbit
 * @author    David Winn <dwinn@drillbit.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class drillbit_default_setup_form extends moodleform {

    // Define the form.
    public function definition () {
        global $CFG;

        $mform = $this->_form;

        require_once($CFG->dirroot.'/plagiarism/drillbit/classes/drillbit_view.class.php');

        $drillbitview = new drillbit_view();
        $drillbitview->add_elements_to_settings_form($mform, array(), "activity");

        $this->add_action_buttons(true);
    }
}