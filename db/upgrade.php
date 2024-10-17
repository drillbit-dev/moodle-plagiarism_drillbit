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
 * TODO describe file update
 *
 * @package    plagiarism_drillbit
 * @copyright  2024 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


 function xmldb_plagiarism_drillbit_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager(); // Loads database manager.

    // Define the new column to be added to the table.
    if ($oldversion < 2024011901) { // Use the appropriate version.
        // Define the table where you want to add the column.
        $table = new xmldb_table('plagiarism_drillbit_files');
        
        $aiscore = new xmldb_field('aiscore', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'similarityscore');

        // Define the new column.
        $plagcheck = new xmldb_field('plagcheck', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'identifier');
        
        // Conditionally add the new field if it doesn't exist.
        //Adding aiscore column
        if (!$dbman->field_exists($table, $aiscore)) {
            $dbman->add_field($table, $aiscore);
        }

        //Adding plagiarismcheck column
        if (!$dbman->field_exists($table, $plagcheck)) {
            $dbman->add_field($table, $plagcheck);
        }

        // Update the plugin version to indicate the upgrade was successful.
        upgrade_plugin_savepoint(true, 2024011901, 'plagiarism', 'drillbit');
    }

    return true;
}