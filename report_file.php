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

use core\update\validator;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

$paperid = required_param('paper_id', PARAM_INT);

if ($paperid != null) {
    $resultcode = plagiarism_drillbit_update_expired_jwt_token();
    if ($resultcode) {
        global $DB;
        $drillbitfile = $DB->get_record("plagiarism_drillbit_files", array("submissionid" => $paperid));
        //print_r($drillbitfile);exit;
        if ($drillbitfile) {
            $hasaccess = plagiarism_drillbit_has_access_to_view_report($drillbitfile->cm, $drillbitfile->userid);
            if (!$hasaccess) {
                echo get_string('reportfailnoaccess', 'plagiarism_drillbit');
                exit(0);
            }

            $jwt = plagiarism_drillbit_get_existing_jwt_token();
            $headers = array(
                "Authorization: Bearer $jwt"
            );

            $reportdownlink = get_report_download_uri($paperid, $drillbitfile->dkey);


            if (empty($reportdownlink)) {
                echo "<center><h3>Unable to get relevant submission report. Please contact Administrator.</h3></center>";
                exit(0);
            }

            $response = plagiarism_drillbit_call_external_api("GET", $reportdownlink, false, $headers);

            send_content_uncached($response, $paperid . '_' . microtime() . '.pdf');
        }
    } else {
        echo get_string('reportfailgeneric', 'plagiarism_drillbit');
        exit();
    }
} else {
    echo get_string('reportfailgeneric', 'plagiarism_drillbit');
    exit();
}
