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
 * Evento enrolment plugin local library file.
 *
 * @package    enrol_evento
 * @copyright  2017 HTW Chur Roger Barras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/enrol/locallib.php');

/**
 * Name of the user info field for the eventoid
 */
define('ENROL_EVENTO_UIF_EVENTOID', 'eventoid');


/**
 * Class definition for the whole syncronisation process.
 *
 */
class enrol_evento_user_sync{

    // trace reference to null_progress_trace
    protected $trace;
    // evento WS reference to local_evento_evento_service
    protected $eventoservice;
    // Plugin configuration.
    protected $config;
    // array of valid evento state id to enrol students
    protected $enrolstateids;
    // reference to the plugin
    protected $plugin;
    // start timestamp for the enrolment
    protected $timestart;
    // end timestamp for the enrolment
    protected $timeend;
    // array of enrolled user ids for each iteration
    protected $entolledusersids;
    // roleid for the teacher enrolment
    protected $eteacherroleid;
    // roleid for the student enrolment
    protected $studentroleid;
    // soapfaultcodes for stop execution
    protected $stopsoapfaultcodes = array('HTTP', 'soapenv:Server', 'Server');
    // array of all active ad-useraccounts
    protected $allactiveadaccounts = array();

    /**
     * Constructor
     *
     */
    public function __construct() {
        global $DB;
        $this->config = get_config('enrol_evento');
        $this->plugin = enrol_get_plugin('evento');
        $this->eventoservice = new local_evento_evento_service();
        // Set up a student and a teacher role for use in some tests.
        $this->eteacherroleid = $DB->get_field('role', 'id', array('shortname' => 'editingteacher'));
        $this->studentroleid = $DB->get_field('role', 'id', array('shortname' => 'student'));
        // Valid Evento enrolmentstate for student enrolment.
        $this->enrolstateids = explode(",", preg_replace('/\s+/', '', $this->config->evenrolmentstate));
    }

    /**
     * Sync all evento course links.
     *
     * @param progress_trace $trace
     * @param int $courseid one course, empty means all
     * @return int 0 means ok, 1 means error, 2 means plugin disabled
     */
    public function user_sync(progress_trace $trace, $courseid = null) {
        global $CFG, $DB, $USER;

        try {
            // Init.
            $this->trace = $trace;
            if (!enrol_is_enabled('evento')) {
                $this->trace->output("Evento plugin not enabled");
                $this->trace->finished();
                return 2;
            }

            // Unfortunately this may take a long time, execution can be interrupted safely here.
            core_php_time_limit::raise();
            raise_memory_limit(MEMORY_HUGE);

            if (!$this->eventoservice->init_call()) {
                // webservice not available
                $this->trace->output("Evento webservice not available");
                return 2;
            }

            $this->trace->output('Starting evento enrolment synchronisation...');

            // Init the time start and end for new enrolments.
            $now = time();
            $this->timestart = make_timestamp(date('Y', $now), date('m', $now), date('d', $now), 0, 0, 0);
            $this->timeend = 0;
            $instances = array();

            $params = array('now1' => $now, 'now2' => $now, 'now3' => $now, 'now4' => $now, 'courselevel' => CONTEXT_COURSE, 'enabled' => ENROL_INSTANCE_ENABLED);
            $coursesql = "";
            if (!empty($courseid)) {
                $coursesql = "AND e.courseid = :courseid";
                $params['courseid'] = $courseid;
            }
            // Selects all active enrolments in unfinished courses.
            // No enrolment if course enddate is reached or the course is hidden.
            $sql = "SELECT e.*, c.idnumber, cx.id AS contextid
                    FROM {enrol} e
                    JOIN {context} cx ON (cx.instanceid = e.courseid AND cx.contextlevel = :courselevel)
                    JOIN {course} c ON (c.id = e.courseid AND
                                    (((c.enddate = 0 OR (c.enddate > 0 AND c.enddate >= :now1)) AND c.visible = 1)
                                    OR (c.startdate >= :now2)))
                    WHERE e.enrol = 'evento' AND (e.enrolenddate = 0 OR (e.enrolenddate > 0 AND e.enrolenddate >= :now3))
                        AND (e.enrolstartdate = 0 OR (e.enrolstartdate > 0 AND e.enrolstartdate <= :now4))
                        AND e.status = :enabled
                        $coursesql";
            $rs = $DB->get_recordset_sql($sql, $params);

            // Iterate over each evento enrol instance.
            foreach ($rs as $ce) {
                try {
                    if (empty($instances[$ce->id])) {
                        $instances[$ce->id] = $DB->get_record('enrol', array('id' => $ce->id));
                    }
                    $instance = $instances[$ce->id];

                    // Get event id and data.
                    if (empty($instance->customtext1)) {
                        $anlassnbr = trim($ce->idnumber);
                    } else {
                        $anlassnbr = trim($instance->customtext1);
                    }

                    // Nothing to sync?
                    if (empty($anlassnbr)) {
                        debugging("No 'anlassnummer' set for courseid: {$ce->courseid}", DEBUG_DEVELOPER);
                        continue;
                    }

                    // Timestamps for enrolemnts.
                    $this->timestart = $ce->enrolstartdate;
                    $this->timeend = $ce->enrolenddate;
                    // Array of ids of active enrolled users.
                    $this->entolledusersids = array();

                    $event = $this->eventoservice->get_event_by_number($anlassnbr);
                    if (empty($event)) {
                        debugging("No Evento event found for idnumber: {$anlassnbr}", DEBUG_DEVELOPER);
                        continue;
                    }

                    // Get event participants enrolments.
                    $enrolments = $this->eventoservice->get_enrolments_by_eventid($event->idAnlass);
                    $enrolments = to_array($enrolments);

                    // Enrol students.
                    foreach ($enrolments as $ee) {
                        try {
                            $this->update_student_enrolment($ee->idPerson, $ee->iDPAStatus, $instance);
                        } catch (SoapFault $fault) {
                            debugging("Soapfault : ". $fault->__toString());
                            $this->trace->output("...user enrolment synchronisation aborted unexpected with a soapfault"
                                                 . " during sync of the enrolment with evento personid: {$ee->idPerson} ; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                            if (in_array($fault->faultcode, $this->stopsoapfaultcodes)) {
                                // Stop execution.
                                $this->trace->finished();
                                return 1;
                            }
                        } catch (Exception $ex) {
                            debugging("Enrolment sync of user evento personid: {$ee->idPerson}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}"
                                    . " aborted with error: ". $ex->getMessage());
                            $this->trace->output("...user enrolment synchronisation aborted unexpected during sync of enrolment"
                                                . " with evento personid: {$ee->idPerson}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                        } catch (Throwable $ex) {
                            debugging("Enrolment sync of user evento personid: {$ee->idPerson}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}"
                                    . " aborted with error: ". $ex->getMessage());
                            $this->trace->output("...user enrolment synchronisation aborted unexpected during sync of enrolment"
                                                . " with evento personid: {$ee->idPerson}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                        }
                    }

                    // Enrol teachers.
                    $eventteachers = array();
                    if (isset($event->array_EventoAnlassLeitung)) {
                        $eventteachers = to_array($event->array_EventoAnlassLeitung);
                    }
                    // Enrol teachers allowed?
                    if ($instance->customint1 == 1 || is_null($instance->customint1)) {
                        foreach ($eventteachers as $teacher) {
                            try {
                                $this->enrol_teacher($teacher->anlassLtgIdPerson, $instance);
                            } catch (SoapFault $fault) {
                                debugging("Soapfault : ". $fault->__toString());
                                $this->trace->output("...user enrolment synchronisation aborted unexpected with a soapfault during sync of the enrolment"
                                                    . " with evento personid: {$teacher->anlassLtgIdPerson}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                                if (in_array($fault->faultcode, $this->stopsoapfaultcodes)) {
                                    // Stop execution.
                                    $this->trace->finished();
                                    return 1;
                                }
                            } catch (Exception $ex) {
                                debugging("Enrolemnt sync of user evento personid: {$teacher->anlassLtgIdPerson}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}"
                                        . " aborted with error: ". $ex->getMessage());
                                $this->trace->output("...user enrolment synchronisation aborted unexpected during sync of enrolment"
                                                    . " with evento personid: {$teacher->anlassLtgIdPerson}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                            } catch (Throwable $ex) {
                                debugging("Enrolemnt sync of user evento personid: {$teacher->anlassLtgIdPerson}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}"
                                        . " aborted with error: ". $ex->getMessage());
                                $this->trace->output("...user enrolment synchronisation aborted unexpected during sync of enrolment"
                                                    . " with evento personid: {$teacher->anlassLtgIdPerson}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                            }
                        }
                    }

                    // Suspend users that are already enrolled in moodle, but not anymore in evento.
                    // Get moodle enrolments.
                    $allenrolledusers = array();
                    $allenrolledusers = $DB->get_records('user_enrolments', array('enrolid' => $ce->id, 'status' => ENROL_USER_ACTIVE), 'userid', 'userid');
                    if (!empty($allenrolledusers)) {
                        // Suspend only, if there are enrolments in evento. Or there are teachers in the module.
                        if (!empty($enrolments) || !empty($eventteachers)) {

                            foreach ($allenrolledusers as $enrolleduser) {
                                try {
                                    // Check, if the user is not enrolled by this task.
                                    if (!in_array($enrolleduser->userid, $this->entolledusersids)) {
                                        // Workaround for if evento id for a user changed in evento but not yet in the Active Directory.
                                        // Not suspending users, if there is no AD account available, there might be a data inconsistency,
                                        // This will still suspend disabled accounts.
                                        $aduser = to_array($this->get_ad_user($this->get_eventoid_by_userid($enrolleduser->userid)));
                                        if ((!empty($aduser)) && (count($aduser) >= 1)) {
                                            // Suspend User of available AD account.
                                            $this->plugin->update_user_enrol($instance, $enrolleduser->userid, ENROL_USER_SUSPENDED);
                                            $this->trace->output("suspending expired user {$enrolleduser->userid} in course {$instance->courseid}", 1);
                                        } else {
                                            $this->trace->output("warning: not suspending expired user {$enrolleduser->userid} in course {$instance->courseid} " .
                                                                "because no response of the Ad Service for this user", 1);
                                        }
                                    }
                                } catch (Exception $ex) {
                                    debugging("Error durring suspending of user with id: {$enrolleduser->userid}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}"
                                            . " aborted with error: ". $ex->getMessage());
                                    $this->trace->output("...user enrolment synchronisation aborted unexpected during suspending with userid:"
                                                        . " {$enrolleduser->userid}eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                                } catch (Throwable $ex) {
                                    debugging("Error durring suspending of user with id: {$enrolleduser->userid}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}"
                                            . " aborted with error: ". $ex->getMessage());
                                    $this->trace->output("...user enrolment synchronisation aborted unexpected during suspending"
                                                        . " with userid: {$enrolleduser->userid}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                                }
                            }
                        } else {
                            debugging("not processing suspending, because no evento enrolments gotten for evento.idAnlass: {$event->idAnlass}; courseid: {$ce->courseid}");
                            $this->trace->output("...not processing suspending, because no evento enrollments gotten for"
                                            . " evento.idAnlass: {$event->idAnlass}; courseid: {$ce->courseid}");
                        }
                    }

                    // Finally sync groups if option is set
                    if (isset($ce->customint2) && ($ce->customint2 > 0)) {
                        require_once("{$CFG->dirroot}/group/lib.php");
                        $affectedusers = groups_sync_with_enrolment('evento', $ce->courseid);
                        foreach ($affectedusers['removed'] as $gm) {
                            $this->trace->output("removing user from group: $gm->userid ==> $gm->courseid - $gm->groupname");
                        }
                        foreach ($affectedusers['added'] as $ue) {
                            $this->trace->output("adding user to group: $ue->userid ==> $ue->courseid - $ue->groupname");
                        }
                    }

                } catch (SoapFault $fault) {
                    debugging("Soapfault : ". $fault->__toString());
                    $this->trace->output("...user enrolment synchronisation aborted unexpected with a soapfault during "
                                        . "sync of enrol instance id: {$ce->id}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                    if (in_array($fault->faultcode, $this->stopsoapfaultcodes)) {
                        // Stop execution.
                        $this->trace->finished();
                        return 1;
                    }
                } catch (Exception $ex) {
                    debugging("Instance with id {$ce->id}; eventnr.:{$anlassnbr} aborted with error: ". $ex->getMessage());
                    $this->trace->output("...user enrolment synchronisation aborted unexpected during sync of enrol instance id:"
                                        . " {$ce->id}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                } catch (Throwable $ex) {
                    debugging("Instance with id {$ce->id}; eventnr.:{$anlassnbr} aborted with error: ". $ex->getMessage());
                    $this->trace->output("...user enrolment synchronisation aborted unexpected during sync of enrol instance id:"
                                        . " {$ce->id}; eventnr.:{$anlassnbr}; courseid: {$ce->courseid}");
                }
            }
            $rs->close();
            unset($instances);

            $this->trace->output('...user enrolment synchronisation finished.');
        } catch (SoapFault $fault) {
            debugging("Error Soapfault: ". $fault->__toString());
            $this->trace->output("...user enrolment synchronisation aborted unexpected with a soapfault");
            $this->trace->finished();
            return 1;
        } catch (Exeption $ex) {
            debugging("Error: ". $ex->getMessage());
            $this->trace->output('...user enrolment synchronisation aborted unexpected');
            $this->trace->finished();
            return 1;
        } catch (Throwable $ex) {
            debugging("Error: ". $ex->getMessage());
            $this->trace->output('...user enrolment synchronisation aborted unexpected');
            $this->trace->finished();
            return 1;
        }
        $this->trace->finished();
        return 0;
    }

    /**
     * Checks and enrols a student by an evento enrolment dataset
     * thorws Exceptions on failure
     *
     * @param int $eventopersonid evento person id (idPerson)
     * @param int $eventoenrolstate evento state of the enrolment (iDPAStatus)
     * @param obj $instance evento enrolment dataset
     */
    protected function update_student_enrolment($eventopersonid, $eventoenrolstate, $instance) {
        // Check enrolment state to enrol or suspend.
        if (in_array($eventoenrolstate, $this->enrolstateids)) {
            // Enrol.
            // Get or create the moodle user, throws exception on failure.
            $u = $this->get_user($eventopersonid);
            $this->plugin->enrol_user($instance, $u->id, $this->studentroleid, $this->timestart, $this->timeend, ENROL_USER_ACTIVE);
            $this->entolledusersids[] = $u->id;
            $this->trace->output("enroling user {$u->id} in course {$instance->courseid} as a student", 1);
        }
    }

    /**
     * Enrol a teacher, thorws Exceptions on failure
     *
     * @param string $eventopersonid evento idPerson
     * @param obj $instance evento enrolment dataset
     */
    protected function enrol_teacher($eventopersonid, $instance) {
        // Get or create the moodle user, throws exception on failure.
        $u = $this->get_user($eventopersonid, false);
        // Enrol.
        $this->plugin->enrol_user($instance, $u->id, $this->eteacherroleid, $this->timestart, $this->timeend, ENROL_USER_ACTIVE);
        $this->entolledusersids[] = $u->id;
        $this->trace->output("enroling user {$u->id} in course {$instance->courseid} as an editingteacher", 1);
    }

    /**
     * Get an aduser by evento personid
     * default is the student account
     *
     * @param string $eventopersonid evento idPerson
     * @param bool $isstudent optional; get the student account (default), otherwise you will get lecturer or employee accounts
     *                        ; set null if you like to get all accounts
     * @param array of ad users
     */
    protected function get_ad_user($eventopersonid, $isstudent=null) {
        $result = null;
        if (empty($this->allactiveadaccounts)) {
            $this->allactiveadaccounts = $this->eventoservice->get_all_ad_accounts(true);
        }
        // Filter ad-users.
        if (isset($eventopersonid)) {
            // Filter personid.
            $result = array_filter($this->allactiveadaccounts,
                                function ($var) use ($eventopersonid) {
                                    return (($var->idPerson == $eventopersonid));
                                }
            );
            // Filter student, lecturer or employee.
            if (isset($isstudent)) {
                $result = array_filter($result,
                                    function ($var) use ($isstudent) {
                                        if ($isstudent) {
                                            $isstudentaccount = '1';
                                        } else {
                                            $isstudentaccount = '0';
                                        }
                                        return (($var->isStudentAccount == $isstudentaccount));
                                    }
                );
            }
        }

        return $result;
    }

    /**
     * Obtains the moodle user by an evento id
     *
     * @param int $eventopersonid
     * @param bool $isstudent get the student account
     * @param int $username
     * @return a fieldset object for the user
     */
    protected function get_user($eventopersonid, $isstudent=true, $username=null) {
        global $DB, $CFG;

        // Get the Active Directory User by evento ID.
        $adusers = to_array($this->get_ad_user($eventopersonid, $isstudent));
        if (!empty($adusers)) {
            if (count($adusers) == 1) {
                $aduser = reset($adusers);
            } else if (count($adusers) > 1) {
                throw new moodle_exception('toomanyadusersfound', 'local_evento', '',
                    $eventopersonid, " Got too many Active Directory accounts for {$eventopersonid}");
            }
        } else {
            throw new moodle_exception('cannotfindaduser', 'local_evento', '', $eventopersonid,
                "No Active Directory account for {$eventopersonid}");
        }
        // Get moodle users by the user eventoid field.
        $ul = $this->get_users_by_eventoid($eventopersonid);
        if (!empty($ul)) {
            if (count($ul) == 1) {
                // Only one user, so take this.
                $u = reset($ul);
                // check if the user matches with the aduser
                $shibbolethid = $this->eventoservice->sid_to_shibbolethid($aduser->objectSid);
                if ($u->username != $shibbolethid) {
                    $u = null; // not the same useraccount
                }
            }
        }

        // Get the moodle user by the username.
        if (!isset($u)) {
            $shibbolethid = $this->eventoservice->sid_to_shibbolethid($aduser->objectSid);
            $u = $this->get_user_by_username($shibbolethid);
            if (isset($u)) {
                $this->set_user_eventoid($u->id, $eventopersonid);
            }
        }

        // Create a user.
        if (!isset($u)) {
            require_once($CFG->dirroot . "/user/lib.php");

            // Get person details from evento.
            $person = $this->eventoservice->get_person_by_id($eventopersonid);
            $username = $shibbolethid;
            $email = $person->personeMail;
            $firstname = $person->personVorname;
            $lastname = $person->personNachname;

            $usernew = new stdClass();
            $usernew->auth = $this->config->accounttype;
            $usernew->username = $username;
            $usernew->email = $email;
            $usernew->firstname = $firstname;
            $usernew->lastname = $lastname;

            $usernew->confirmed = 1;
            $usernew->interests = "";
            // Moodle wants more for valid users.
            $usernew->timecreated = time();

            $usernew->mnethostid = $CFG->mnet_localhost_id; // Always local user.
            $usernew->password = AUTH_PASSWORD_NOT_CACHED;  // Because of Shibboleth.

            // Finally create the user.
            $usernew->id = user_create_user($usernew, false, false);

            $this->set_user_eventoid($usernew->id, $eventopersonid);
            $u = $DB->get_record('user', array('id' => $usernew->id));
            debugging("user created with username: {$usernew->username}", DEBUG_DEVELOPER);
        }

        return $u;
    }

    /**
     * Obtains a list of users by an eventoid if it is set.
     *
     * @param string $eventoid
     * @return an array of fieldset objects for the user
     */
    protected function get_users_by_eventoid($eventoid) {
        global $DB;

        $sql = 'SELECT u.*
            FROM {user} u
            INNER JOIN {user_info_data} uid ON uid.userid = u.id
            INNER JOIN {user_info_field} uif ON uid.fieldid = uif.id
            WHERE uif.shortname = :eventoidshortname
            AND uid.data = :eventoid';

        $sqlparams = array('eventoidshortname' => ENROL_EVENTO_UIF_EVENTOID, 'eventoid' => (string)$eventoid);

        $userlist = $DB->get_records_sql($sql, $sqlparams);

        return $userlist;
    }

    /**
     * Obtains the evento id of a user, if it is set
     *
     * @param int $userid
     * @return string eventoid of the user (person)
     */
    protected function get_eventoid_by_userid($userid) {
        global $DB;
        $result = null;

        $sql = 'SELECT u.id, uid.data
            FROM {user} u
            INNER JOIN {user_info_data} uid ON uid.userid = u.id
            INNER JOIN {user_info_field} uif ON uid.fieldid = uif.id
            WHERE uif.shortname = :eventoidshortname
            AND u.id = :userid';

        $sqlparams = array('eventoidshortname' => ENROL_EVENTO_UIF_EVENTOID, 'userid' => (int)$userid);

        $user = $DB->get_record_sql($sql, $sqlparams);
        if (isset($user)) {
            $result = $user->data;
        }

        return $result;
    }

    /**
     * Obtains the user by username (shibbolethid).
     *
     * @param string $username
     * @return a fieldset object for the user
     */
    protected function get_user_by_username($username) {
        global $DB;

        $user = $DB->get_record('user', array('username' => $username));

        return ($user == false) ? null : $user;
    }

    /**
     * Sets or inserts the user defined field eventoid, if it exists.
     *
     * @param int $userid
     * @param int $eventoid
     * @return bool true if set successfully
     */
    protected function set_user_eventoid($userid, $eventoid) {
        global $DB;

        $returnvalue = false;

        // Gets an existing user info data eventoid.
        $sql = 'SELECT uid.id
            FROM {user_info_data} uid
            INNER JOIN {user_info_field} uif ON uid.fieldid = uif.id
            WHERE uif.shortname = :eventoid
            AND uid.userid = :userid';

        $sqlparams = array('eventoid' => ENROL_EVENTO_UIF_EVENTOID, 'userid' => $userid);

        $uid = $DB->get_field_sql($sql, $sqlparams);

        if ($uid) {
            // Update.
            $returnvalue = $DB->set_field('user_info_data', 'data', $eventoid, array('id' => $uid));
        } else {
            // Insert.
            // Gets an existing user info field for eventoid.
            $sql = 'SELECT uif.id
                FROM {user_info_field} uif
                WHERE uif.shortname = :eventoid';

            $sqlparams = array('eventoid' => ENROL_EVENTO_UIF_EVENTOID);

            $uifid = $DB->get_field_sql($sql, $sqlparams);

            if ($uifid) {
                // Inserts new user_info_data item.
                $item = new \stdClass();
                $item->userid = $userid;
                $item->fieldid = $uifid;
                $item->data = (string)$eventoid;
                $item->dataformat = 0;

                $uiditem = $DB->insert_record('user_info_data', $item);
                if ($uiditem) {
                    $returnvalue = true;
                }
            }
        }
        return $returnvalue;
    }

}


/**
 * Create an array if the value is not already one.
 *
 * @param var $value
 * @return array of the $value
 */
function to_array($value) {
    $returnarray = array();
    if (is_array($value)) {
        $returnarray = $value;
    } else if (!is_null($value)) {
        $returnarray[0] = $value;
    }
    return $returnarray;
}

/**
 * Create a new group
 *
 * @param int $courseid
 * @param string $newgroupname
 */
function enrol_evento_create_new_group($courseid, $newgroupname) {
    global $DB, $CFG;

    require_once($CFG->dirroot.'/group/lib.php');

    $a = new stdClass();
    $a->name = $newgroupname;
    $a->increment = '';
    $inc = 1;
    $groupname = trim(get_string('defaultgroupnametext', 'enrol_evento', $a));
    // Check to see if the group name already exists in this course. Add an incremented number if it does.
    while ($DB->record_exists('groups', array('name' => $groupname, 'courseid' => $courseid))) {
        $a->increment = '(' . (++$inc) . ')';
        $groupname = trim(get_string('defaultgroupnametext', 'enrol_evento', $a));
    }
    // Create a new group for the course meta sync.
    $groupdata = new stdClass();
    $groupdata->courseid = $courseid;
    $groupdata->name = $groupname;
    $groupid = groups_create_group($groupdata);

    return $groupid;
}

/**
 * Create a new grouping
 *
 * @param int $courseid
 * @param string $newgroupingname
 */
function enrol_evento_create_new_grouping($courseid, $newgroupingname) {
    global $DB, $CFG;

    require_once($CFG->dirroot.'/group/lib.php');

    $a = new stdClass();
    $a->name = $newgroupingname;
    $a->increment = '';
    $inc = 1;
    $groupingname = trim(get_string('defaultgroupnametext', 'enrol_evento', $a));
    // Check to see if the grouping name already exists in this course. Add an incremented number if it does.
    while ($DB->record_exists('groupings', array('name' => $groupingname, 'courseid' => $courseid))) {
        $a->increment = '(' . (++$inc) . ')';
        $groupingname = trim(get_string('defaultgroupnametext', 'enrol_evento', $a));
    }
    // Create a new group for the course meta sync.
    $groupingdata = new stdClass();
    $groupingdata->courseid = $courseid;
    $groupingdata->name = $groupingname;
    $groupingid = groups_create_grouping($groupingdata);

    return $groupingid;
}
