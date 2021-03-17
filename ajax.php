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

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/../../config.php');

require_once(__DIR__ . '/lib.php');

require_login();

$method = required_param('method', PARAM_ALPHAEXT);
$url = required_param("url", PARAM_RAW);
$data = required_param("data", PARAM_RAW);

header("Content-Type: application/json; charset=UTF-8");

if ($method === "external") {
    $dataToPost = $data;
    $URL = $url;
    if ($dataToPost) {
        $result = CallExternalAPI("POST", $URL, $dataToPost, array("content-type:application/json"));
        // $decodedResult = json_decode($result);
        echo $result;
    }
} else {
    $status = [];
    $status["status"] = false;
    $status["message"] = "No method specified";
    echo json_encode($status);
}

// else if ($method == "submission") {
//     plagiarism_drillbit_send_queued_submissions();
// } else if ($method == "update_submission") {
//     plagiarism_drillbit_update_reports();
// } 
