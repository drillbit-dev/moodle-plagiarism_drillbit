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
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/plagiarism/drillbit/lib.php');
require_once($CFG->dirroot . '/plagiarism/drillbit/classes/forms/drillbit_setup_form.class.php');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$PAGE->requires->jquery();
require_login();
admin_externalpage_setup('plagiarismdrillbit');
$context = context_system::instance();
require_capability('moodle/site:config', $context, $USER->id, true, "nopermissions");
global $DB;

$PAGE->set_url(new moodle_url('/plagiarism/drillbit/settings.php'));
$PAGE->set_context($context);
$pagetitle = get_string('settingspagetitle', 'plagiarism_drillbit');
$PAGE->set_title($pagetitle);

$settingsform = new plagiarism_drillbit_setup_form();

// $settingsform->display();

if ($settingsform->is_cancelled()) {
    redirect($CFG->wwwroot . '/admin/category.php?category=plagiarism', 'No changes Done.');
} else if ($settingsformdata = $settingsform->get_data()) {

    foreach ($settingsformdata as $field => $value) {
        $DB->delete_records("config_plugins", array("name" => $field));
    }

    $DB->delete_records("config_plugins", array("plugin" => "plagiarism_drillbit", "name" => "jwt"));
    $DB->delete_records("config_plugins", array("plugin" => "plagiarism_drillbit", "name" => "enabled"));

    $email = "";
    $pass = "";
    $apikey = "";
    $folderid = "";

    foreach ($settingsformdata as $field => $value) {
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
            $folderid = $value;
        }

        if ($field == "plagiarism_drillbit_apikey") {
            $apikey = $value;
        }

        if (!$DB->insert_record('config_plugins', $drillbitconfigfield)) {
            mtrace("errorinserting");
        }
    }

    $jwt = plagiarism_drillbit_get_login_token($email, $pass, $folderid, $apikey);

    $drillbitconfigfield = new stdClass();
    $drillbitconfigfield->value = $jwt;
    $drillbitconfigfield->plugin = 'plagiarism_drillbit';
    $drillbitconfigfield->name = "jwt";

    $drillbitenable = new stdClass();
    $drillbitenable->value = 1;
    $drillbitenable->plugin = 'plagiarism_drillbit';
    $drillbitenable->name = "enabled";

    if (!$DB->insert_record('config_plugins', $drillbitconfigfield)) {
        mtrace("errorinserting");
    }

    if (!$DB->insert_record('config_plugins', $drillbitenable)) {
        mtrace("errorinserting");
    }

    $output = $OUTPUT->notification(get_string('configsavesuccess', 'plagiarism_drillbit'), 'notifysuccess');
}

$plagiarismdrillbitsettings = (array)get_config('plagiarism_drillbit');

$settingsform->set_data($plagiarismdrillbitsettings);

echo $OUTPUT->header();
echo $OUTPUT->heading($pagetitle);
$settingsform->display();

?>
<script>
    $("#id_connection_test").click(function() {
        var api_base_url = $("#id_plagiarism_drillbit_apiurl").val();
        var email = $("#id_plagiarism_drillbit_emailid").val();
        var password = $("#id_plagiarism_drillbit_password").val();
        var folder_id = $("#id_plagiarism_drillbit_folderid").val();
        var api_key = $("#id_plagiarism_drillbit_apikey").val();

        var form_data_json = {
            username: email,
            password: password,
            api_key: api_key,
            submissions_key: folder_id
        };

        form_data_json = JSON.stringify(form_data_json);
        console.log(form_data_json);

        $.ajax({
            type: "POST",
            url: "/plagiarism/drillbit/ajax.php?method=external",
            //dataType: 'json',
            //contentType: 'application/json',
            data: {
                data: form_data_json
            },
            success: function(data) {
                if (data["jwt"]) {
                    var token = data["jwt"];
                    var html = "<b>" + <?php echo json_encode(get_string('connsuccess', 'plagiarism_drillbit')); ?>;
                    html += "</b><br/><p>Access Token => " + token + "</p>";

                    $('#api_conn_result').html(html);
                } else if (data["status"]) {
                    var html = "<b>" + <?php echo json_encode(get_string('connfail', 'plagiarism_drillbit')); ?>;
                    html += "</b><br/><p>Error => " + data["message"] + "</p>";
                    $('#api_conn_result').html(data);
                }

            },
            error: function(err) {
                console.log(err);
                var html = "<b>" + <?php get_string('connfail', 'plagiarism_drillbit'); ?> + "</b><br/><p>Error => " + err + "</p>";
                $('#api_conn_result').html(err);
            }
        });

    });
</script>

<?php
echo $OUTPUT->footer();
