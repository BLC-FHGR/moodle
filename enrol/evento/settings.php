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
 * Evento enrolment plugin settings and presets.
 *
 * @package    enrol_evento
 * @copyright  2017 HTW Chur Roger Barras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {

    // --- general settings -----------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_evento_settings', '', get_string('pluginname_desc', 'enrol_evento')));

    $settings->add(new admin_setting_configtext('enrol_evento/accounttype',
        new lang_string('accounttype', 'enrol_evento'), new lang_string('accounttype_desc', 'enrol_evento'), 'shibboleth', PARAM_TEXT));

    $settings->add(new admin_setting_configtext('enrol_evento/evenrolmentstate',
        new lang_string('evenrolmentstate', 'enrol_evento'), new lang_string('evenrolmentstate_desc', 'enrol_evento'),
             '20208, 20215, 20225, 20270, 20275, 20281, 20282, 20284, 20510, 20520, 20545, ' .
             '21300, 21310, 21330, 21350, 21352, 21355, 21380', PARAM_TEXT, 100));

    // --- enrol instance defaults ----------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_evento_defaults',
        get_string('enrolinstancedefaults', 'enrol_evento'), get_string('enrolinstancedefaults_desc', 'enrol_evento')));

    $settings->add(new admin_setting_configcheckbox('enrol_evento/enrolteachers',
        get_string('enrolteachers', 'enrol_evento'),
        get_string('enrolteachers_help', 'enrol_evento'), 1));
}
