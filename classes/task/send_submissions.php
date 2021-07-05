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
 * Send queued submissions to drillbit.
 *
 * @package    plagiarism_drillbit
 * @copyright  2021 drillbit
 * @author     kavimukil <kavimukil.a@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_drillbit\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Send queued submissions to drillbit.
 */
class send_submissions extends \core\task\scheduled_task
{

    public function get_name() {
        return get_string('sendqueuedsubmissions', 'plagiarism_drillbit');
    }

    public function execute() {
        global $CFG;
        mtrace("Drillbit Submission Started .....");
        require_once($CFG->dirroot . '/plagiarism/drillbit/lib.php');
        plagiarism_drillbit_send_queued_submissions();
        mtrace("Drillbit Submission Ended .....");
    }
}
