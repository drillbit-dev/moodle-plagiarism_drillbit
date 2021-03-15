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
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/plagiarism/drillbit/lib.php');
require_once($CFG->dirroot . '/plagiarism/drillbit/classes/forms/drillbit_setup_form.class.php');

$PAGE->requires->jquery();
require_login();
admin_externalpage_setup('plagiarismdrillbit');
$context = context_system::instance();
require_capability('moodle/site:config', $context, $USER->id, true, "nopermissions");
global $DB;

$PAGE->set_url(new moodle_url('/plagiarism/drillbit/settings.php'));
$PAGE->set_context($context);
$pageTitle = "Drillbit Plagiarism Settings";
$PAGE->set_title($pageTitle);

$settingsForm = new drillbit_setup_form();

//$settingsForm->display();

if ($settingsForm->is_cancelled()) {
    redirect($CFG->wwwroot . '/admin/category.php?category=plagiarism', 'No changes Done.');
} else if ($settingsFormData = $settingsForm->get_data()) {

    foreach ($settingsFormData as $field => $value) {
        $DB->delete_records("config_plugins", array("name" => $field));
    }

    $DB->delete_records("config_plugins", array("plugin" => "plagiarism_drillbit", "name" => "jwt"));
    $DB->delete_records("config_plugins", array("plugin" => "plagiarism_drillbit", "name" => "enabled"));

    $email = "";
    $pass = "";
    $api_key = "";
    $folder_id = "";

    foreach ($settingsFormData as $field => $value) {
        $drillbitconfigfield = new stdClass();
        $drillbitconfigfield->value = $value;
        $drillbitconfigfield->plugin = 'plagiarism_drillbit';
        $drillbitconfigfield->name = $field;

        if ($field == "plagiarism_drillbit_emailid") {
            $email = $value;
        }

        if ($field == "plagiarism_drillbit_password") {
            $pass = $value;
        }
        if ($field == "plagiarism_drillbit_folderid") {
            $folder_id = $value;
        }

        if ($field == "plagiarism_drillbit_apikey") {
            $api_key = $value;
        }

        if (!$DB->insert_record('config_plugins', $drillbitconfigfield)) {
            error("errorinserting");
        }
    }

    $jwt = get_login_token($email, $pass, $folder_id, $api_key);

    $drillbitconfigfield = new stdClass();
    $drillbitconfigfield->value = $jwt;
    $drillbitconfigfield->plugin = 'plagiarism_drillbit';
    $drillbitconfigfield->name = "jwt";

    $drillbitenable = new stdClass();
    $drillbitenable->value = 1;
    $drillbitenable->plugin = 'plagiarism_drillbit';
    $drillbitenable->name = "enabled";

    if (!$DB->insert_record('config_plugins', $drillbitconfigfield)) {
        error("errorinserting");
    }

    if (!$DB->insert_record('config_plugins', $drillbitenable)) {
        error("errorinserting");
    }

    $output = $OUTPUT->notification(get_string('configsavesuccess', 'plagiarism_drillbit'), 'notifysuccess');
}

$plagiarism_drillbit_settings = (array)get_config('plagiarism_drillbit');
// print_r($plagiarism_drillbit_settings);exit;
$settingsForm->set_data($plagiarism_drillbit_settings);

echo $OUTPUT->header();
echo $OUTPUT->heading($pageTitle);
$settingsForm->display();

?>
<script>
    $("#id_connection_test").click(function() {

        console.log("click called");
        var apiBaseUrl = $("#id_plagiarism_drillbit_apiurl").val();
        var email = $("#id_plagiarism_drillbit_emailid").val();
        var password = $("#id_plagiarism_drillbit_password").val();

        var formDataJson = {
            username: email,
            password: password
        };

        formDataJson = JSON.stringify(formDataJson);

        $.ajax({
            type: "POST",
            url: "/plagiarism/drillbit/ajax.php?method=external",
            //dataType: 'json',
            //contentType: 'application/json',
            data: {
                data: formDataJson,
                url: apiBaseUrl + "/drillbit_new/api/authenticate"
            },
            success: function(data) {
                if (data["jwt"]) {
                    var token = data["jwt"];
                    var html = "<b>Connection test successfull</b><br/><p>Access Token => " + token + "</p>";

                    $('#api_conn_result').html(html);
                } else if (data["status"]) {
                    var html = "<b>Connection test failed</b><br/><p>Error => " + data["message"] + "</p>";
                    $('#api_conn_result').html(data);
                }

            },
            error: function(err) {
                var html = "<b>Connection test failed</b><br/><p>Error => " + err + "</p>";
                $('#api_conn_result').html(data);
            }
        });

    });
</script>

<?php
echo $OUTPUT->footer();
