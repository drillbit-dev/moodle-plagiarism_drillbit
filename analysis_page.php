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
 * TODO describe file analysis_page
 *
 * @package    plagiarism_drillbit
 * @copyright  2024 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


 require('../../config.php');
 require_once($CFG->dirroot . '/plagiarism/drillbit/lib.php');
 
 require_login();
 
 $paper_id = required_param('paper_id', PARAM_INT);
 $url = new moodle_url('/plagiarism/drillbit/analysis_page.php', []);
 $PAGE->set_url($url);
 $PAGE->set_context(context_system::instance());
 
 $PAGE->set_heading($SITE->fullname);
 echo $OUTPUT->header();
 
 $analysis_page_url = get_analysis_page_download_uri($paper_id);
 
 $jwt = plagiarism_drillbit_get_existing_jwt_token();
 
 $analysis_page_url_final = plagiarism_drillbit_call_external_api("GET",$analysis_page_url);
 // echo $analysis_page_url_final;
 echo "<h1> Moodle Plagiarism plugin </h1>";
 // echo $paper_id;
 echo $jwt;
 // echo $analysis_page_url_final;
 // echo "<button class='analysis_page_button' id='analysis_page'> View Analysis Page</button";
 echo "<h3> Loading ........... </h3>";
 
 // echo $analysis_page_url_final;
 redirect($analysis_page_url_final);
 echo "<button class='analysis_page_button' id='analysis_page'> View Analysis Page </button>";
 
 ?>
 <style>
     .analysis_page_button {
         display: inline-block;
         padding: 10px 20px;
         background-color: #4CAF50; /* Green */
         border: none;
         color: white;
         text-align: center;
         text-decoration: none;
         font-size: 16px;
         margin: 4px 2px;
         cursor: pointer;
         border-radius: 8px;
     }
 </style>
 <script>
     let url = "<?php echo $analysis_page_url_final ?>"
     document.getElementById("analysis_page").onclick = function(){
         window.location.href = url
     }
 </script>