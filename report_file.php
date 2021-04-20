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
        if ($drillbitfile) {
            $hasaccess = has_access_to_view_report($drillbitfile->cm, $drillbitfile->userid);
            // print_r(json_encode($hasaccess));exit;
            if (!$hasaccess) {
                echo get_string('reportfailnoaccess', 'plagiarism_drillbit');
                exit(0);
            }

            $jwt = plagiarism_drillbit_get_existing_jwt_token();
            $headers = array(
                "Authorization: Bearer $jwt"
            );

            $response = plagiarism_drillbit_call_external_api("GET", $drillbitfile->download_url, false, $headers);
            header('Content-Description: File Transfer');
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename=' . $paperid . '_' . microtime() . '.pdf');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . filesize($response));
            ob_clean();
            flush();
            echo $response;
            flush();
        }
    } else {
        echo get_string('reportfailgeneric', 'plagiarism_drillbit');
        exit();
    }
} else {
    echo get_string('reportfailgeneric', 'plagiarism_drillbit');
    exit();
}

function has_access_to_view_report($cm, $reportfileuser) {
    global $USER;
    $coursemodule = get_coursemodule_from_id('assign', $cm);

    if (empty($coursemodule)) {
        echo get_string('reportfailnocm', 'plagiarism_drillbit');
        exit(0);
    }

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
