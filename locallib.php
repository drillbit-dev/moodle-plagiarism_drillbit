<?php

/**
 * Retrieve previously made successful submissions that match passed in parameters. This
 * avoids resubmitting them to Drillbit.
 *
 * @param $author
 * @param $cmid
 * @param $identifier
 * @return $plagiarismfiles - an array of succesfully submitted submissions
 */
function plagiarism_drillbit_retrieve_successful_submissions($author, $cmid, $identifier)
{
    global $CFG, $DB;

    // Check if the same answer has been submitted previously. Remove if so.
    list($insql, $inparams) = $DB->get_in_or_equal(array('success', 'queued'), SQL_PARAMS_QM, 'param', false);
    $typefield = ($CFG->dbtype == "oci") ? " to_char(statuscode) " : " statuscode ";

    $plagiarismfiles = $DB->get_records_select(
        "plagiarism_drillbit_files",
        " userid = ? AND cm = ? AND identifier = ? AND " . $typefield . " " . $insql,
        array_merge(array($author, $cmid, $identifier), $inparams)
    );

    return $plagiarismfiles;
}
