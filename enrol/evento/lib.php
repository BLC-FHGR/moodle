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
 * Evento enrolment plugin main library file.
 *
 * @package    enrol_evento
 * @copyright  2017 HTW Chur Roger Barras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * ENROL_EVENTO_CREATE_GROUP constant for automatically creating a group.
 */
define('ENROL_EVENTO_CREATE_GROUP', -1);

/**
 * ENROL_EVENTO_CREATE_GROUPING constant for automatically creating a grouping.
 */
define('ENROL_EVENTO_CREATE_GROUPING', -1);


class enrol_evento_plugin extends enrol_plugin {

    protected $lasternoller = null;
    protected $lasternollerinstanceid = 0;


    public function roles_protected() {
        // Users may tweak the roles later.
        return false;
    }

    public function allow_enrol(stdClass $instance) {
        // Users with enrol cap may unenrol other users manually manually.
        return false;
    }

    public function allow_unenrol(stdClass $instance) {
        // Users with unenrol cap may unenrol other users manually manually.
        return false;
    }

    public function allow_manage(stdClass $instance) {
        // Users with manage cap may tweak period and status.
        return false;
    }

    /**
     * Add new instance of enrol plugin.
     * @param object $course
     * @param array $fields instance fields
     * @return int id of last instance, null if can not be created
     */
    public function add_instance($course, array $fields = null) {
        global $CFG;

        require_once("$CFG->dirroot/enrol/evento/locallib.php");
        require_once($CFG->dirroot.'/group/lib.php');
        $groupingid = null;
        $groupid = null;

        // New grouping.
        if (!empty($fields['customint3']) && $fields['customint3'] == ENROL_EVENTO_CREATE_GROUPING
            && !empty($fields['customint2']) && $fields['customint2'] == ENROL_EVENTO_CREATE_GROUP) {
            $context = context_course::instance($course->id);
            require_capability('moodle/course:managegroups', $context);
            if (empty($fields['customtext3'])) {
                $newgroupingname = $this->get_default_groupingname($data);
            } else {
                $newgroupingname = $fields['customtext3'];
            }
            $groupingid = enrol_evento_create_new_grouping($course->id, $newgroupingname);
            $fields['customint3'] = $groupingid;
        }

        // New group.
        if (!empty($fields['customint2']) && $fields['customint2'] == ENROL_EVENTO_CREATE_GROUP) {
            $context = context_course::instance($course->id);
            require_capability('moodle/course:managegroups', $context);

            if (empty($fields['customtext2'])) {
                $newgroupname = $this->get_default_groupname((object) $fields);
            } else {
                $newgroupname = $fields['customtext2'];
            }
            $groupid = enrol_evento_create_new_group($course->id, $newgroupname);
            $fields['customint2'] = $groupid;
        }

        // Assign new Group to new Grouping.
        if (isset($groupingid) && isset($groupid)) {
            groups_assign_grouping($groupingid, $groupid);
        }

        $result = parent::add_instance($course, $fields);

        return $result;
    }

    /**
     * Return true if we can add a new instance to this course.
     *
     * @param int $courseid
     * @return boolean
     */
    public function can_add_instance($courseid) {
        global $DB;

        return true;
    }

    /**
     * Add new instance of enrol plugin with default settings.
     * @param stdClass $course
     * @return int id of new instance, null if can not be created
     */
    public function add_default_instance($course) {
        $fields = $this->get_instance_defaults();

        return $this->add_instance($course, $fields);
    }

    /**
     * Checks if an instance exists with the same eventnumber
     *
     * @param stdClass $course
     * @param string evento event number "anlassnummer"
     * @return boolean
     */
    public function instance_exists_by_eventnumber($course, $idnumber) {
        global $DB;

        $result = false;
        $where = "courseid = :courseid AND enrol = :enrol AND UPPER(" . $DB->sql_compare_text('customtext1', 100) . ") = UPPER(:customtext1) ";
        // check if standard instance is set
        if ($course->idnumber == $idnumber) {
            if ($DB->record_exists_select('enrol', $where, array('courseid' => $course->id, 'enrol' => $this->get_name(), 'customtext1' => ""))) {
                $result = true;
            } else {
                $result = $DB->record_exists_select('enrol', $where, array('courseid' => $course->id, 'enrol' => $this->get_name(), 'customtext1' => $idnumber));
            }
        } else {
            $result = $DB->record_exists_select('enrol', $where, array('courseid' => $course->id, 'enrol' => $this->get_name(), 'customtext1' => $idnumber));
        }
        return $result;
    }

    /**
     * Return a default name for a new group
     *
     * @param stdClass $data instance fields
     * @return string
     */
    private function get_default_groupname($data) {
        // Is the new group name empty set to the name or to the alternative evento number
        // or to the plugin name
        if (!empty($data->name)) {
            $newgroupname = $data->name;
        } else if (!empty($data->customtext1)) {
            // Evento event number.
            $newgroupname = $data->customtext1;
        } else {
            // Course plugin name
            $newgroupname = get_string('pluginname', 'enrol_evento');
        }

        return $newgroupname;
    }

    /**
     * Return a default name for a new grouping
     *
     * @param stdClass $data instance fields
     * @return string
     */
    private function get_default_groupingname($data) {
        // At the moment the same like the default groupname.
        return $this->get_default_groupname($data);
    }

    /**
     * Returns defaults for new instances.
     * @return array
     */
    public function get_instance_defaults() {
        $fields = array();
        $fields['name']            = get_string('pluginname', 'enrol_evento');
        $fields['status']          = ENROL_INSTANCE_ENABLED;
        // Option Enrol teachers.
        $fields['customint1']      = $this->get_config('enrolteachers');
        // Evento "Anlassnummer".
        $fields['customtext1']     = "";
        // Init new group name.
        $fields['customtext2']     = $fields['name'];
        // Init new grouping name.
        $fields['customtext3']     = $fields['name'];

        return $fields;
    }

    /**
     * Sets the custom course number in the fields.
     * @param array of enrolment fields
     * @param string custom evento event number
     * @return array
     */
    public function set_custom_coursenumber($fields, $customcoursenumber) {
        $fields['customtext1']     = $customcoursenumber;
        return $fields;
    }

    /**
     * Update instance of enrol plugin.
     * @param stdClass $instance
     * @param stdClass $data modified instance fields
     * @return boolean
     */
    public function update_instance($instance, $data) {
        global $CFG;

        require_once("$CFG->dirroot/enrol/evento/locallib.php");
        require_once($CFG->dirroot.'/group/lib.php');
        $groupingid = null;
        $groupid = null;

        // New grouping.
        if (!empty($data->customint3) && $data->customint3 == ENROL_EVENTO_CREATE_GROUPING
            && !empty($data->customint2) && $data->customint2 == ENROL_EVENTO_CREATE_GROUP) {
            $context = context_course::instance($instance->courseid);
            require_capability('moodle/course:managegroups', $context);
            if (empty($data->customtext3)) {
                $newgroupingname = $this->get_default_groupingname($data);
            } else {
                $newgroupingname = $data->customtext3;
            }
            $groupingid = enrol_evento_create_new_grouping($instance->courseid, $newgroupingname);
            $data->customint3 = $groupingid;
        }

        // New group.
        if (!empty($data->customint2) && $data->customint2 == ENROL_EVENTO_CREATE_GROUP) {
            $context = context_course::instance($instance->courseid);
            require_capability('moodle/course:managegroups', $context);
            if (empty($data->customtext2)) {
                $newgroupname = $this->get_default_groupname($data);
            } else {
                $newgroupname = $data->customtext2;
            }
            $groupid = enrol_evento_create_new_group($instance->courseid, $newgroupname);
            $data->customint2 = $groupid;
        }

        // Assign new group to grouping.
        if (isset($groupid) && ($data->customint3 > 0)) {
            groups_assign_grouping($data->customint3, $groupid);
        }

        $result = parent::update_instance($instance, $data);

        return $result;
    }

    /**
     * Enrol cron support.
     * @return void
     */
    public function cron() {

        $trace = new null_progress_trace();
        $this->sync($trace, null);

    }

    /**
     * Sync all evento course links.
     *
     * @param progress_trace $trace
     * @param int $courseid one course, empty mean all
     * @return int 0 means ok, 1 means error, 2 means plugin disabled
     */
    public function sync(progress_trace $trace, $courseid = null) {
        global $CFG;

        require_once("$CFG->dirroot/enrol/evento/locallib.php");
        $syncstart = microtime(true);
        $usersync = new enrol_evento_user_sync();

        $result = $usersync->user_sync($trace, $courseid);
        $syncend = microtime(true);
        $synctime = $syncend - $syncstart;
        $debugmessage = "Evento enrolment user syncronisation process time: {$synctime}";
        debugging($debugmessage, DEBUG_DEVELOPER);
        $trace->output($debugmessage);
        $trace->finished();

        return $result;
    }

    /**
     * Forces synchronisation of user enrolments.
     *
     * This is important especially for external enrol plugins,
     * this function is called for all enabled enrol plugins
     * right after every user login.
     *
     * @param object $user user record
     * @return void
     */
    public function sync_user_enrolments($user) {
        // Probably better no sync durring login.
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        $instanceid = $this->add_instance($course, (array)$data);
        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $oldinstancestatus
     * @param int $userid
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        global $DB;

        $ue = $DB->get_record('user_enrolments', array('enrolid' => $instance->id, 'userid' => $userid));
        $enrol = false;
        if ($ue and $ue->status == ENROL_USER_ACTIVE) {
            // We do not want to restrict current active enrolments, let's kind of merge the times only.
            // This prevents some teacher lockouts too.
            if ($data->status == ENROL_USER_ACTIVE) {
                if ($data->timestart > $ue->timestart) {
                    $data->timestart = $ue->timestart;
                    $enrol = true;
                }

                if ($data->timeend == 0) {
                    if ($ue->timeend != 0) {
                        $enrol = true;
                    }
                } else if ($ue->timeend == 0) {
                    $data->timeend = 0;
                } else if ($data->timeend < $ue->timeend) {
                    $data->timeend = $ue->timeend;
                    $enrol = true;
                }
            }
        } else {
            if ($instance->status == ENROL_INSTANCE_ENABLED and $oldinstancestatus != ENROL_INSTANCE_ENABLED) {
                // Make sure that user enrolments are not activated accidentally,
                // we do it only here because it is not expected that enrolments are migrated to other plugins.
                $data->status = ENROL_USER_SUSPENDED;
            }
            $enrol = true;
        }

        if ($enrol) {
            $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, $data->status);
        }
    }

    /**
     * Restore role assignment.
     *
     * @param stdClass $instance
     * @param int $roleid
     * @param int $userid
     * @param int $contextid
     */
    public function restore_role_assignment($instance, $roleid, $userid, $contextid) {
        // This is necessary only because we may migrate other types to this instance,
        // we do not use component in manual or self enrol.
        role_assign($roleid, $userid, $contextid, '', 0);
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param object $instance
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/evento:config', $context);
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/evento:config', $context);
    }

    /**
     * We are a good plugin and don't invent our own UI/validation code path.
     *
     * @return boolean
     */
    public function use_standard_editing_ui() {
        return true;
    }

    /**
     * Return an array of valid options for the groups.
     *
     * @param context $coursecontext
     * @return array
     */
    protected function get_group_options($coursecontext) {
        $groups = array(0 => get_string('none'));
        $courseid = $coursecontext->instanceid;
        if (has_capability('moodle/course:managegroups', $coursecontext)) {
            $groups[ENROL_EVENTO_CREATE_GROUP] = get_string('creategroup', 'enrol_evento');
        }
        foreach (groups_get_all_groups($courseid) as $group) {
            $groups[$group->id] = format_string($group->name, true, array('context' => $coursecontext));
        }
        return $groups;
    }

    /**
     * Return an array of valid options for the groupings.
     *
     * @param context $coursecontext
     * @return array
     */
    protected function get_groupings_options($coursecontext) {
        $groupings = array(0 => get_string('none'));
        $courseid = $coursecontext->instanceid;
        if (has_capability('moodle/course:managegroups', $coursecontext)) {
            $groupings[ENROL_EVENTO_CREATE_GROUPING] = get_string('creategrouping', 'enrol_evento');
        }
        foreach (groups_get_all_groupings($courseid) as $grouping) {
            $groupings[$grouping->id] = format_string($grouping->name, true, array('context' => $coursecontext));
        }
        return $groupings;
    }

    /**
     * Add elements to the edit instance form.
     *
     * @param stdClass $instance
     * @param MoodleQuickForm $mform
     * @param context $context
     * @return bool
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context) {
        global $CFG;

        $config = get_config('enrol_evento');
        $groups = $this->get_group_options($context);
        $groupings = $this->get_groupings_options($context);
        $course  = get_course($instance->courseid);

        // Instance name.
        $nameattribs = array('maxlength' => '255', 'size' => '40');
        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'), $nameattribs);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setDefault('name', get_string('pluginname', 'enrol_evento'));
        if (empty($instance->name)) {
            $instance->name = get_string('pluginname', 'enrol_evento');
        }

        // Custom evento eventnumber.
        $options = array('size' => '40', 'maxlength' => '100');
        $mform->addElement('text', 'customtext1', get_string('customcoursenumber', 'enrol_evento'), $options);
        $mform->setType('customtext1', PARAM_TEXT);
        $mform->addRule('customtext1', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');
        $mform->addHelpButton('customtext1', 'customcoursenumber', 'enrol_evento');
        $mform->setDefault('customtext1', $course->idnumber);
        if (empty($instance->customtext1)) {
            $instance->customtext1 = $course->idnumber;
        }

        // Enrol teachers.
        $mform->addElement('advcheckbox', 'customint1', get_string('enrolteachers', 'enrol_evento'), '',
                array('optional' => true, 'group' => null), array(0, 1));
        $mform->addHelpButton('customint1', 'enrolteachers', 'enrol_evento');
        $mform->setDefault('customint1', $config->enrolteachers);

        // Group.
        $groupgroup = array();
        $groupgroup[] =& $mform->createElement('select', 'customint2', get_string('addtogroup', 'enrol_evento'), $groups);

        // New group name.
        $options = array('size' => '30', 'maxlength' => '100', '');
        $groupgroup[] =& $mform->createElement('text', 'customtext2', get_string('newgroupname', 'enrol_evento'), $options);
        $mform->addGroup($groupgroup, 'groupgroup',  get_string('addtogroup', 'enrol_evento'),
            array('&nbsp;&nbsp;&nbsp;' . get_string('newgroupname', 'enrol_evento')), false);
        $mform->setType('customtext2', PARAM_TEXT);
        $mform->disabledIf('customtext2', 'customint2', 'neq', ENROL_EVENTO_CREATE_GROUP);
        $mform->addGroupRule('groupgroup', array(
            'customtext2' => array(
                array(get_string('maximumchars', '', 100), 'maxlength', 100, 'client')
            )
        ));
        $mform->addHelpButton('groupgroup', 'addtogroup', 'enrol_evento');

        // Grouping.
        $groupgrouping = array();
        $groupgrouping[] =& $mform->createElement('select', 'customint3', get_string('addtogrouping', 'enrol_evento'), $groupings);

        // New grouping name.
        $options = array('size' => '30', 'maxlength' => '100', '');
        $groupgrouping[] =& $mform->createElement('text', 'customtext3', get_string('newgroupingname', 'enrol_evento'), $options);
        $mform->addGroup($groupgrouping, 'groupgrouping',  get_string('addtogrouping', 'enrol_evento'),
            array('&nbsp;&nbsp;&nbsp;' . get_string('newgroupingname', 'enrol_evento')), false);
        $mform->setType('customtext3', PARAM_TEXT);
        $mform->disabledIf('customtext3', 'customint3', 'neq', ENROL_EVENTO_CREATE_GROUPING);
        $mform->addGroupRule('groupgrouping', array(
            'customtext3' => array(
                array(get_string('maximumchars', '', 100), 'maxlength', 100, 'client')
            )
        ));
        $mform->addHelpButton('groupgrouping', 'addtogrouping', 'enrol_evento');
        // Disable Grouping if not set

        $mform->disabledIf('customint3', 'customint2', 'neq', ENROL_EVENTO_CREATE_GROUP);
        $mform->disabledIf('customtext3', 'customint2', 'neq', ENROL_EVENTO_CREATE_GROUP);
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @param object $instance The instance loaded from the DB
     * @param context $context The context of the instance we are editing
     * @return array of "element_name"=>"error_description" if there are errors,
     *         or an empty array if everything is OK.
     * @return void
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        $errors = array();

        // Todo settings validation.

        return $errors;
    }

}
