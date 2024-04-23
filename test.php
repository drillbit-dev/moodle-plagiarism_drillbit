<?php

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

global $CFG, $DB;



require_once($CFG->dirroot . '/plagiarism/drillbit/lib.php');
try {
    if ($argv[1] == 'update_reports') {
        plagiarism_drillbit_update_reports();
    } else if ($argv[1] == 'send_submissions') {
        plagiarism_drillbit_send_queued_submissions();
    }
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/test.log', print_r($e, true));
}
