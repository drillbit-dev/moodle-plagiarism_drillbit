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

defined('MOODLE_INTERNAL') || die();

$observers = array (
     array(
        'eventname' => '\assignsubmission_file\event\assessable_uploaded',
        'callback'  => 'plagiarism_drillbit_observer::assignsubmission_file_uploaded'
    ),
    array(
        'eventname' => '\mod_assign\event\submission_status_updated',
        'callback'  => 'plagiarism_drillbit_observer::assignsubmission_db_update'
    ),
    array(
        'eventname' => '\assignsubmission_onlinetext\event\assessable_uploaded',
        'callback'  => 'plagiarism_drillbit_observer::assignsubmission_onlinetext_uploaded'
    ),
    array(
        'eventname' => '\mod_workshop\event\assessable_uploaded',
        'callback'  => 'plagiarism_drillbit_observer::workshop_file_uploaded'
    ),
    array(
        'eventname' => '\mod_forum\event\assessable_uploaded',
        'callback'  => 'plagiarism_drillbit_observer::forum_file_uploaded'
    ),
    array(
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => 'plagiarism_drillbit_observer::assignsubmission_submitted'
    ),
    array(
        'eventname' => '\mod_coursework\event\assessable_uploaded',
        'callback'  => 'plagiarism_drillbit_observer::coursework_submitted'
    ),
    array(
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => 'plagiarism_drillbit_observer::quiz_submitted'
    ),
    array(
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => 'plagiarism_drillbit_observer::course_module_deleted'
    ),
    array(
        'eventname' => '\core\event\course_reset_ended',
        'callback'  => 'plagiarism_plugin_drillbit::course_reset',
        'includefile' => 'plagiarism/drillbit/lib.php'
    )
);