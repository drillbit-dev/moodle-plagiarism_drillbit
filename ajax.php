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

require_once(__DIR__ . '/../../config.php');

require_once(__DIR__ . '/lib.php');

require_login();
header("Content-Type: application/json; charset=UTF-8");

if (!is_siteadmin()) {
    $result["status"] = false;
    $result["message"] = get_string('unauthorizedtestconn', 'plagiarism_drillbit');
    echo json_encode($result);
    exit(0);
}

$method = required_param('method', PARAM_ALPHAEXT);
$data = required_param("data", PARAM_RAW);

if ($method === "external") {
    $datatopost = $data;
    $url = "https://www.drillbitplagiarismcheck.com/drillbit_new/api/authenticate/moodle";
    if ($datatopost) {
        $result = plagiarism_drillbit_call_external_api("POST", $url, $datatopost, array("content-type:application/json"));
        echo $result;
    }
} else {
    $status = [];
    $status["status"] = false;
    $status["message"] = "No method specified";
    echo json_encode($status);
}
