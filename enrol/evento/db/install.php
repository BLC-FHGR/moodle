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
 * This file keeps track of upgrades to the evento enrolment plugin
 *
 * @package    enrol_evento
 * @copyright  2017 HTW Chur Roger Barras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


function xmldb_enrol_evento_install() {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/user/profile/definelib.php');
    require_once($CFG->dirroot . '/enrol/evento/locallib.php');

    // Check if user definied field exists
    $uifid = $DB->get_records('user_info_field', array('shortname' => ENROL_EVENTO_UIF_EVENTOID));

    if (empty($uifid)) {
        // Inserts new user_info_data item.
        $item = new \stdClass();
        $item->shortname = ENROL_EVENTO_UIF_EVENTOID;
        $item->name = "Evento ID";
        $item->datatype = 'text';
        $item->description = "<p>Evento ID for enrol_evento plugin</p>";
        $item->descriptionformat = FORMAT_HTML;
        $item->categoryid = 1;
        $item->required = 0;
        $item->locked = 1;
        $item->visible = 0;
        $item->forceunique = 0;
        $item->signup = 0;
        $item->defaultdata = '';
        $item->defaultdataformat = FORMAT_MOODLE;
        $item->param1 = 30;
        $item->param2 = 2048;
        $item->param3 = 0;
        $item->param4 = '';
        $item->param5 = '';

        $profiledef = new profile_define_base();
        $profiledef->define_save($item);
    }

    return true;
}
