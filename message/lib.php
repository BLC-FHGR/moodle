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
 * Library functions for messaging
 *
 * @package   core_message
 * @copyright 2008 Luis Rodrigues
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->libdir.'/eventslib.php');

define('MESSAGE_SHORTLENGTH', 300);

define('MESSAGE_HISTORY_ALL', 1);

define('MESSAGE_SEARCH_MAX_RESULTS', 200);

define('MESSAGE_TYPE_NOTIFICATION', 'notification');
define('MESSAGE_TYPE_MESSAGE', 'message');

/**
 * Define contants for messaging default settings population. For unambiguity of
 * plugin developer intentions we use 4-bit value (LSB numbering):
 * bit 0 - whether to send message when user is loggedin (MESSAGE_DEFAULT_LOGGEDIN)
 * bit 1 - whether to send message when user is loggedoff (MESSAGE_DEFAULT_LOGGEDOFF)
 * bit 2..3 - messaging permission (MESSAGE_DISALLOWED|MESSAGE_PERMITTED|MESSAGE_FORCED)
 *
 * MESSAGE_PERMITTED_MASK contains the mask we use to distinguish permission setting
 */

define('MESSAGE_DEFAULT_LOGGEDIN', 0x01); // 0001
define('MESSAGE_DEFAULT_LOGGEDOFF', 0x02); // 0010

define('MESSAGE_DISALLOWED', 0x04); // 0100
define('MESSAGE_PERMITTED', 0x08); // 1000
define('MESSAGE_FORCED', 0x0c); // 1100

define('MESSAGE_PERMITTED_MASK', 0x0c); // 1100

/**
 * Set default value for default outputs permitted setting
 */
define('MESSAGE_DEFAULT_PERMITTED', 'permitted');

/**
 * Set default values for polling.
 */
define('MESSAGE_DEFAULT_MIN_POLL_IN_SECONDS', 10);
define('MESSAGE_DEFAULT_MAX_POLL_IN_SECONDS', 2 * MINSECS);
define('MESSAGE_DEFAULT_TIMEOUT_POLL_IN_SECONDS', 5 * MINSECS);

/**
 * Returns the count of unread messages for user. Either from a specific user or from all users.
 *
 * @param object $user1 the first user. Defaults to $USER
 * @param object $user2 the second user. If null this function will count all of user 1's unread messages.
 * @return int the count of $user1's unread messages
 */
function message_count_unread_messages($user1=null, $user2=null) {
    global $USER, $DB;

    if (empty($user1)) {
        $user1 = $USER;
    }

    $sql = "SELECT COUNT(m.id)
              FROM {messages} m
        INNER JOIN {message_conversations} mc
                ON mc.id = m.conversationid
        INNER JOIN {message_conversation_members} mcm
                ON mcm.conversationid = mc.id
         LEFT JOIN {message_user_actions} mua
                ON (mua.messageid = m.id AND mua.userid = ? AND (mua.action = ? OR mua.action = ?))
             WHERE mua.id is NULL
               AND mcm.userid = ?";
    $params = [$user1->id, \core_message\api::MESSAGE_ACTION_DELETED, \core_message\api::MESSAGE_ACTION_READ,  $user1->id];

    if (!empty($user2)) {
        $sql .= " AND m.useridfrom = ?";
        $params[] = $user2->id;
    }

    return $DB->count_records_sql($sql, $params);
}

/**
 * Try to guess how to convert the message to html.
 *
 * @access private
 *
 * @param stdClass $message
 * @param bool $forcetexttohtml
 * @return string html fragment
 */
function message_format_message_text($message, $forcetexttohtml = false) {
    // Note: this is a very nasty hack that tries to work around the weird messaging rules and design.

    $options = new stdClass();
    $options->para = false;
    $options->blanktarget = true;

    $format = $message->fullmessageformat;

    if (strval($message->smallmessage) !== '') {
        if (!empty($message->notification)) {
            if (strval($message->fullmessagehtml) !== '' or strval($message->fullmessage) !== '') {
                $format = FORMAT_PLAIN;
            }
        }
        $messagetext = $message->smallmessage;

    } else if ($message->fullmessageformat == FORMAT_HTML) {
        if (strval($message->fullmessagehtml) !== '') {
            $messagetext = $message->fullmessagehtml;
        } else {
            $messagetext = $message->fullmessage;
            $format = FORMAT_MOODLE;
        }

    } else {
        if (strval($message->fullmessage) !== '') {
            $messagetext = $message->fullmessage;
        } else {
            $messagetext = $message->fullmessagehtml;
            $format = FORMAT_HTML;
        }
    }

    if ($forcetexttohtml) {
        // This is a crazy hack, why not set proper format when creating the notifications?
        if ($format === FORMAT_PLAIN) {
            $format = FORMAT_MOODLE;
        }
    }
    return format_text($messagetext, $format, $options);
}

/**
 * Add the selected user as a contact for the current user
 *
 * @param int $contactid the ID of the user to add as a contact
 * @param int $blocked 1 if you wish to block the contact
 * @param int $userid the user ID of the user we want to add the contact for, defaults to current user if not specified.
 * @return bool/int false if the $contactid isnt a valid user id. True if no changes made.
 *                  Otherwise returns the result of update_record() or insert_record()
 */
function message_add_contact($contactid, $blocked = 0, $userid = 0) {
    global $USER, $DB;

    if (!$DB->record_exists('user', array('id' => $contactid))) { // invalid userid
        return false;
    }

    if (empty($userid)) {
        $userid = $USER->id;
    }

    // Check if a record already exists as we may be changing blocking status.
    if (($contact = $DB->get_record('message_contacts', array('userid' => $userid, 'contactid' => $contactid))) !== false) {
        // Check if blocking status has been changed.
        if ($contact->blocked != $blocked) {
            $contact->blocked = $blocked;
            $DB->update_record('message_contacts', $contact);

            if ($blocked == 1) {
                // Trigger event for blocking a contact.
                $event = \core\event\message_contact_blocked::create(array(
                    'objectid' => $contact->id,
                    'userid' => $contact->userid,
                    'relateduserid' => $contact->contactid,
                    'context'  => context_user::instance($contact->userid)
                ));
                $event->add_record_snapshot('message_contacts', $contact);
                $event->trigger();
            } else {
                // Trigger event for unblocking a contact.
                $event = \core\event\message_contact_unblocked::create(array(
                    'objectid' => $contact->id,
                    'userid' => $contact->userid,
                    'relateduserid' => $contact->contactid,
                    'context'  => context_user::instance($contact->userid)
                ));
                $event->add_record_snapshot('message_contacts', $contact);
                $event->trigger();
            }

            return true;
        } else {
            // No change to blocking status.
            return true;
        }

    } else {
        // New contact record.
        $contact = new stdClass();
        $contact->userid = $userid;
        $contact->contactid = $contactid;
        $contact->blocked = $blocked;
        $contact->id = $DB->insert_record('message_contacts', $contact);

        $eventparams = array(
            'objectid' => $contact->id,
            'userid' => $contact->userid,
            'relateduserid' => $contact->contactid,
            'context'  => context_user::instance($contact->userid)
        );

        if ($blocked) {
            $event = \core\event\message_contact_blocked::create($eventparams);
        } else {
            $event = \core\event\message_contact_added::create($eventparams);
        }
        // Trigger event.
        $event->trigger();

        return true;
    }
}

/**
 * remove a contact
 *
 * @param int $contactid the user ID of the contact to remove
 * @param int $userid the user ID of the user we want to remove the contacts for, defaults to current user if not specified.
 * @return bool returns the result of delete_records()
 */
function message_remove_contact($contactid, $userid = 0) {
    global $USER, $DB;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if ($contact = $DB->get_record('message_contacts', array('userid' => $userid, 'contactid' => $contactid))) {
        $DB->delete_records('message_contacts', array('id' => $contact->id));

        // Trigger event for removing a contact.
        $event = \core\event\message_contact_removed::create(array(
            'objectid' => $contact->id,
            'userid' => $contact->userid,
            'relateduserid' => $contact->contactid,
            'context'  => context_user::instance($contact->userid)
        ));
        $event->add_record_snapshot('message_contacts', $contact);
        $event->trigger();

        return true;
    }

    return false;
}

/**
 * Unblock a contact. Note that this reverts the previously blocked user back to a non-contact.
 *
 * @param int $contactid the user ID of the contact to unblock
 * @param int $userid the user ID of the user we want to unblock the contact for, defaults to current user
 *  if not specified.
 * @return bool returns the result of delete_records()
 */
function message_unblock_contact($contactid, $userid = 0) {
    return message_add_contact($contactid, 0, $userid);
}

/**
 * Block a user.
 *
 * @param int $contactid the user ID of the user to block
 * @param int $userid the user ID of the user we want to unblock the contact for, defaults to current user
 *  if not specified.
 * @return bool
 */
function message_block_contact($contactid, $userid = 0) {
    return message_add_contact($contactid, 1, $userid);
}

/**
 * Load a user's contact record
 *
 * @param int $contactid the user ID of the user whose contact record you want
 * @return array message contacts
 */
<<<<<<< HEAD
function message_count_blocked_users($user1=null) {
    global $USER, $DB;

    if (empty($user1)) {
        $user1 = $USER;
    }

    $sql = "SELECT count(mc.id)
            FROM {message_contacts} mc
            WHERE mc.userid = :userid AND mc.blocked = 1";
    $params = array('userid' => $user1->id);

    return $DB->count_records_sql($sql, $params);
}

/**
 * Print the search form and search results if a search has been performed
 *
 * @param  boolean $advancedsearch show basic or advanced search form
 * @param  object $user1 the current user
 * @return boolean true if a search was performed
 */
function message_print_search($advancedsearch = false, $user1=null) {
    $frm = data_submitted();

    $doingsearch = false;
    if ($frm) {
        if (confirm_sesskey()) {
            $doingsearch = !empty($frm->combinedsubmit) || !empty($frm->keywords) || (!empty($frm->personsubmit) and !empty($frm->name));
        } else {
            $frm = false;
        }
    }

    if (!empty($frm->combinedsearch)) {
        $combinedsearchstring = $frm->combinedsearch;
    } else {
        //$combinedsearchstring = get_string('searchcombined','message').'...';
        $combinedsearchstring = '';
    }

    if ($doingsearch) {
        if ($advancedsearch) {

            $messagesearch = '';
            if (!empty($frm->keywords)) {
                $messagesearch = $frm->keywords;
            }
            $personsearch = '';
            if (!empty($frm->name)) {
                $personsearch = $frm->name;
            }
            include('search_advanced.html');
        } else {
            include('search.html');
        }

        $showicontext = false;
        message_print_search_results($frm, $showicontext, $user1);

        return true;
    } else {

        if ($advancedsearch) {
            $personsearch = $messagesearch = '';
            include('search_advanced.html');
        } else {
            include('search.html');
        }
        return false;
    }
}

/**
 * Get the users recent conversations meaning all the people they've recently
 * sent or received a message from plus the most recent message sent to or received from each other user
 *
 * @param object $user the current user
 * @param int $limitfrom can be used for paging
 * @param int $limitto can be used for paging
 * @return array
 */
function message_get_recent_conversations($user, $limitfrom=0, $limitto=100) {
    global $DB;

    $userfields = user_picture::fields('otheruser', array('lastaccess'));

    // This query retrieves the most recent message received from or sent to
    // seach other user.
    //
    // If two messages have the same timecreated, we take the one with the
    // larger id.
    //
    // There is a separate query for read and unread messages as they are stored
    // in different tables. They were originally retrieved in one query but it
    // was so large that it was difficult to be confident in its correctness.
    $uniquefield = $DB->sql_concat('message.useridfrom', "'-'", 'message.useridto');
    $sql = "SELECT $uniquefield, $userfields,
                   message.id as mid, message.notification, message.smallmessage, message.fullmessage,
                   message.fullmessagehtml, message.fullmessageformat, message.timecreated,
                   contact.id as contactlistid, contact.blocked
              FROM {message_read} message
              JOIN (
                        SELECT MAX(id) AS messageid,
                               matchedmessage.useridto,
                               matchedmessage.useridfrom
                         FROM {message_read} matchedmessage
                   INNER JOIN (
                               SELECT MAX(recentmessages.timecreated) timecreated,
                                      recentmessages.useridfrom,
                                      recentmessages.useridto
                                 FROM {message_read} recentmessages
                                WHERE (
                                      (recentmessages.useridfrom = :userid1 AND recentmessages.timeuserfromdeleted = 0) OR
                                      (recentmessages.useridto = :userid2   AND recentmessages.timeusertodeleted = 0)
                                      )
                             GROUP BY recentmessages.useridfrom, recentmessages.useridto
                              ) recent ON matchedmessage.useridto     = recent.useridto
                           AND matchedmessage.useridfrom   = recent.useridfrom
                           AND matchedmessage.timecreated  = recent.timecreated
                           WHERE (
                                 (matchedmessage.useridfrom = :userid6 AND matchedmessage.timeuserfromdeleted = 0) OR
                                 (matchedmessage.useridto = :userid7   AND matchedmessage.timeusertodeleted = 0)
                                 )
                      GROUP BY matchedmessage.useridto, matchedmessage.useridfrom
                   ) messagesubset ON messagesubset.messageid = message.id
              JOIN {user} otheruser ON (message.useridfrom = :userid4 AND message.useridto = otheruser.id)
                OR (message.useridto   = :userid5 AND message.useridfrom   = otheruser.id)
         LEFT JOIN {message_contacts} contact ON contact.userid  = :userid3 AND contact.contactid = otheruser.id
             WHERE otheruser.deleted = 0 AND message.notification = 0
          ORDER BY message.timecreated DESC";
    $params = array(
            'userid1' => $user->id,
            'userid2' => $user->id,
            'userid3' => $user->id,
            'userid4' => $user->id,
            'userid5' => $user->id,
            'userid6' => $user->id,
            'userid7' => $user->id
        );
    $read = $DB->get_records_sql($sql, $params, $limitfrom, $limitto);

    // We want to get the messages that have not been read. These are stored in the 'message' table. It is the
    // exact same query as the one above, except for the table we are querying. So, simply replace references to
    // the 'message_read' table with the 'message' table.
    $sql = str_replace('{message_read}', '{message}', $sql);
    $unread = $DB->get_records_sql($sql, $params, $limitfrom, $limitto);

    // Union the 2 result sets together looking for the message with the most
    // recent timecreated for each other user.
    // $conversation->id (the array key) is the other user's ID.
    $conversations = array();
    $conversation_arrays = array($unread, $read);
    foreach ($conversation_arrays as $conversation_array) {
        foreach ($conversation_array as $conversation) {
            if (!isset($conversations[$conversation->id])) {
                $conversations[$conversation->id] = $conversation;
            } else {
                $current = $conversations[$conversation->id];
                if ($current->timecreated < $conversation->timecreated) {
                    $conversations[$conversation->id] = $conversation;
                } else if ($current->timecreated == $conversation->timecreated) {
                    if ($current->mid < $conversation->mid) {
                        $conversations[$conversation->id] = $conversation;
                    }
                }
            }
        }
    }

    // Sort the conversations by $conversation->timecreated, newest to oldest
    // There may be multiple conversations with the same timecreated
    // The conversations array contains both read and unread messages (different tables) so sorting by ID won't work
    $result = core_collator::asort_objects_by_property($conversations, 'timecreated', core_collator::SORT_NUMERIC);
    $conversations = array_reverse($conversations);

    return $conversations;
}

/**
 * Get the users recent event notifications
 *
 * @param object $user the current user
 * @param int $limitfrom can be used for paging
 * @param int $limitto can be used for paging
 * @return array
 */
function message_get_recent_notifications($user, $limitfrom=0, $limitto=100) {
    global $DB;

    $userfields = user_picture::fields('u', array('lastaccess'));
    $sql = "SELECT mr.id AS message_read_id, $userfields, mr.notification, mr.smallmessage, mr.fullmessage, mr.fullmessagehtml, mr.fullmessageformat, mr.timecreated as timecreated, mr.contexturl, mr.contexturlname
              FROM {message_read} mr
                   JOIN {user} u ON u.id=mr.useridfrom
             WHERE mr.useridto = :userid1 AND u.deleted = '0' AND mr.notification = :notification
             ORDER BY mr.timecreated DESC";
    $params = array('userid1' => $user->id, 'notification' => 1);

    $notifications =  $DB->get_records_sql($sql, $params, $limitfrom, $limitto);
    return $notifications;
}

/**
 * Print the user's recent conversations
 *
 * @param stdClass $user the current user
 * @param bool $showicontext flag indicating whether or not to show text next to the action icons
 */
function message_print_recent_conversations($user1 = null, $showicontext = false, $showactionlinks = true) {
    global $USER;

    echo html_writer::start_tag('p', array('class' => 'heading'));
    echo get_string('mostrecentconversations', 'message');
    echo html_writer::end_tag('p');

    if (empty($user1)) {
        $user1 = $USER;
    }

    $conversations = message_get_recent_conversations($user1);

    // Attach context url information to create the "View this conversation" type links
    foreach($conversations as $conversation) {
        $conversation->contexturl = new moodle_url("/message/index.php?user1={$user1->id}&user2={$conversation->id}");
        $conversation->contexturlname = get_string('thisconversation', 'message');
    }

    $showotheruser = true;
    message_print_recent_messages_table($conversations, $user1, $showotheruser, $showicontext, false, $showactionlinks);
}

/**
 * Print the user's recent notifications
 *
 * @param stdClass $user the current user
 */
function message_print_recent_notifications($user=null) {
    global $USER;

    echo html_writer::start_tag('p', array('class' => 'heading'));
    echo get_string('mostrecentnotifications', 'message');
    echo html_writer::end_tag('p');

    if (empty($user)) {
        $user = $USER;
    }

    $notifications = message_get_recent_notifications($user);

    $showicontext = false;
    $showotheruser = false;
    message_print_recent_messages_table($notifications, $user, $showotheruser, $showicontext, true);
}

/**
 * Print a list of recent messages
 *
 * @access private
 *
 * @param array $messages the messages to display
 * @param stdClass $user the current user
 * @param bool $showotheruser display information on the other user?
 * @param bool $showicontext show text next to the action icons?
 * @param bool $forcetexttohtml Force text to go through @see text_to_html() via @see format_text()
 * @param bool $showactionlinks
 * @return void
 */
function message_print_recent_messages_table($messages, $user = null, $showotheruser = true, $showicontext = false, $forcetexttohtml = false, $showactionlinks = true) {
    global $OUTPUT;
    static $dateformat;

    if (empty($dateformat)) {
        $dateformat = get_string('strftimedatetimeshort');
    }

    echo html_writer::start_tag('div', array('class' => 'messagerecent'));
    foreach ($messages as $message) {
        echo html_writer::start_tag('div', array('class' => 'singlemessage'));

        if ($showotheruser) {
            $strcontact = $strblock = $strhistory = null;

            if ($showactionlinks) {
                if ( $message->contactlistid )  {
                    if ($message->blocked == 0) { // The other user isn't blocked.
                        $strcontact = message_contact_link($message->id, 'remove', true, null, $showicontext);
                        $strblock   = message_contact_link($message->id, 'block', true, null, $showicontext);
                    } else { // The other user is blocked.
                        $strcontact = message_contact_link($message->id, 'add', true, null, $showicontext);
                        $strblock   = message_contact_link($message->id, 'unblock', true, null, $showicontext);
                    }
                } else {
                    $strcontact = message_contact_link($message->id, 'add', true, null, $showicontext);
                    $strblock   = message_contact_link($message->id, 'block', true, null, $showicontext);
                }

                //should we show just the icon or icon and text?
                $histicontext = 'icon';
                if ($showicontext) {
                    $histicontext = 'both';
                }
                $strhistory = message_history_link($user->id, $message->id, true, '', '', $histicontext);
            }
            echo html_writer::start_tag('span', array('class' => 'otheruser'));

            echo html_writer::start_tag('span', array('class' => 'pix'));
            echo $OUTPUT->user_picture($message, array('size' => 20, 'courseid' => SITEID));
            echo html_writer::end_tag('span');

            echo html_writer::start_tag('span', array('class' => 'contact'));

            $link = new moodle_url("/message/index.php?user1={$user->id}&user2=$message->id");
            $action = null;
            echo $OUTPUT->action_link($link, fullname($message), $action, array('title' => get_string('sendmessageto', 'message', fullname($message))));

            echo html_writer::end_tag('span');//end contact

            if ($showactionlinks) {
                echo $strcontact.$strblock.$strhistory;
            }
            echo html_writer::end_tag('span');//end otheruser
        }

        $messagetext = message_format_message_text($message, $forcetexttohtml);

        echo html_writer::tag('span', userdate($message->timecreated, $dateformat), array('class' => 'messagedate'));
        echo html_writer::tag('span', $messagetext, array('class' => 'themessage'));
        echo message_format_contexturl($message);
        echo html_writer::end_tag('div');//end singlemessage
    }
    echo html_writer::end_tag('div');//end messagerecent
}

/**
 * Try to guess how to convert the message to html.
 *
 * @access private
 *
 * @param stdClass $message
 * @param bool $forcetexttohtml
 * @return string html fragment
 */
function message_format_message_text($message, $forcetexttohtml = false) {
    // Note: this is a very nasty hack that tries to work around the weird messaging rules and design.

    $options = new stdClass();
    $options->para = false;
    $options->blanktarget = true;

    $format = $message->fullmessageformat;

    if (strval($message->smallmessage) !== '') {
        if ($message->notification == 1) {
            if (strval($message->fullmessagehtml) !== '' or strval($message->fullmessage) !== '') {
                $format = FORMAT_PLAIN;
            }
        }
        $messagetext = $message->smallmessage;

    } else if ($message->fullmessageformat == FORMAT_HTML) {
        if (strval($message->fullmessagehtml) !== '') {
            $messagetext = $message->fullmessagehtml;
        } else {
            $messagetext = $message->fullmessage;
            $format = FORMAT_MOODLE;
        }

    } else {
        if (strval($message->fullmessage) !== '') {
            $messagetext = $message->fullmessage;
        } else {
            $messagetext = $message->fullmessagehtml;
            $format = FORMAT_HTML;
        }
    }

    if ($forcetexttohtml) {
        // This is a crazy hack, why not set proper format when creating the notifications?
        if ($format === FORMAT_PLAIN) {
            $format = FORMAT_MOODLE;
        }
    }
    return format_text($messagetext, $format, $options);
}

/**
 * Add the selected user as a contact for the current user
 *
 * @param int $contactid the ID of the user to add as a contact
 * @param int $blocked 1 if you wish to block the contact
 * @return bool/int false if the $contactid isnt a valid user id. True if no changes made.
 *                  Otherwise returns the result of update_record() or insert_record()
 */
function message_add_contact($contactid, $blocked=0) {
    global $USER, $DB;

    if (!$DB->record_exists('user', array('id' => $contactid))) { // invalid userid
        return false;
    }

    // Check if a record already exists as we may be changing blocking status.
    if (($contact = $DB->get_record('message_contacts', array('userid' => $USER->id, 'contactid' => $contactid))) !== false) {
        // Check if blocking status has been changed.
        if ($contact->blocked != $blocked) {
            $contact->blocked = $blocked;
            $DB->update_record('message_contacts', $contact);

            if ($blocked == 1) {
                // Trigger event for blocking a contact.
                $event = \core\event\message_contact_blocked::create(array(
                    'objectid' => $contact->id,
                    'userid' => $contact->userid,
                    'relateduserid' => $contact->contactid,
                    'context'  => context_user::instance($contact->userid)
                ));
                $event->add_record_snapshot('message_contacts', $contact);
                $event->trigger();
            } else {
                // Trigger event for unblocking a contact.
                $event = \core\event\message_contact_unblocked::create(array(
                    'objectid' => $contact->id,
                    'userid' => $contact->userid,
                    'relateduserid' => $contact->contactid,
                    'context'  => context_user::instance($contact->userid)
                ));
                $event->add_record_snapshot('message_contacts', $contact);
                $event->trigger();
            }

            return true;
        } else {
            // No change to blocking status.
            return true;
        }

    } else {
        // New contact record.
        $contact = new stdClass();
        $contact->userid = $USER->id;
        $contact->contactid = $contactid;
        $contact->blocked = $blocked;
        $contact->id = $DB->insert_record('message_contacts', $contact);

        $eventparams = array(
            'objectid' => $contact->id,
            'userid' => $contact->userid,
            'relateduserid' => $contact->contactid,
            'context'  => context_user::instance($contact->userid)
        );

        if ($blocked) {
            $event = \core\event\message_contact_blocked::create($eventparams);
        } else {
            $event = \core\event\message_contact_added::create($eventparams);
        }
        // Trigger event.
        $event->trigger();

        return true;
    }
}

/**
 * remove a contact
 *
 * @param int $contactid the user ID of the contact to remove
 * @return bool returns the result of delete_records()
 */
function message_remove_contact($contactid) {
    global $USER, $DB;

    if ($contact = $DB->get_record('message_contacts', array('userid' => $USER->id, 'contactid' => $contactid))) {
        $DB->delete_records('message_contacts', array('id' => $contact->id));

        // Trigger event for removing a contact.
        $event = \core\event\message_contact_removed::create(array(
            'objectid' => $contact->id,
            'userid' => $contact->userid,
            'relateduserid' => $contact->contactid,
            'context'  => context_user::instance($contact->userid)
        ));
        $event->add_record_snapshot('message_contacts', $contact);
        $event->trigger();

        return true;
    }

    return false;
}

/**
 * Unblock a contact. Note that this reverts the previously blocked user back to a non-contact.
 *
 * @param int $contactid the user ID of the contact to unblock
 * @return bool returns the result of delete_records()
 */
function message_unblock_contact($contactid) {
    return message_add_contact($contactid, 0);
}

/**
 * Block a user.
 *
 * @param int $contactid the user ID of the user to block
 * @return bool
 */
function message_block_contact($contactid) {
    return message_add_contact($contactid, 1);
}

/**
 * Checks if a user can delete a message.
 *
 * @param stdClass $message the message to delete
 * @param string $userid the user id of who we want to delete the message for (this may be done by the admin
 *  but will still seem as if it was by the user)
 * @return bool Returns true if a user can delete the message, false otherwise.
 */
function message_can_delete_message($message, $userid) {
    global $USER;

    if ($message->useridfrom == $userid) {
        $userdeleting = 'useridfrom';
    } else if ($message->useridto == $userid) {
        $userdeleting = 'useridto';
    } else {
        return false;
    }

    $systemcontext = context_system::instance();

    // Let's check if the user is allowed to delete this message.
    if (has_capability('moodle/site:deleteanymessage', $systemcontext) ||
        ((has_capability('moodle/site:deleteownmessage', $systemcontext) &&
            $USER->id == $message->$userdeleting))) {
        return true;
    }

    return false;
}

/**
 * Deletes a message.
 *
 * This function does not verify any permissions.
 *
 * @param stdClass $message the message to delete
 * @param string $userid the user id of who we want to delete the message for (this may be done by the admin
 *  but will still seem as if it was by the user)
 * @return bool
 */
function message_delete_message($message, $userid) {
    global $DB;

    // The column we want to alter.
    if ($message->useridfrom == $userid) {
        $coltimedeleted = 'timeuserfromdeleted';
    } else if ($message->useridto == $userid) {
        $coltimedeleted = 'timeusertodeleted';
    } else {
        return false;
    }

    // Don't update it if it's already been deleted.
    if ($message->$coltimedeleted > 0) {
        return false;
    }

    // Get the table we want to update.
    if (isset($message->timeread)) {
        $messagetable = 'message_read';
    } else {
        $messagetable = 'message';
    }

    // Mark the message as deleted.
    $updatemessage = new stdClass();
    $updatemessage->id = $message->id;
    $updatemessage->$coltimedeleted = time();
    $success = $DB->update_record($messagetable, $updatemessage);

    if ($success) {
        // Trigger event for deleting a message.
        \core\event\message_deleted::create_from_ids($message->useridfrom, $message->useridto,
            $userid, $messagetable, $message->id)->trigger();
    }

    return $success;
}

/**
 * Load a user's contact record
 *
 * @param int $contactid the user ID of the user whose contact record you want
 * @return array message contacts
 */
function message_get_contact($contactid) {
    global $USER, $DB;
    return $DB->get_record('message_contacts', array('userid' => $USER->id, 'contactid' => $contactid));
}

/**
 * Print the results of a message search
 *
 * @param mixed $frm submitted form data
 * @param bool $showicontext show text next to action icons?
 * @param object $currentuser the current user
 * @return void
 */
function message_print_search_results($frm, $showicontext=false, $currentuser=null) {
    global $USER, $DB, $OUTPUT;

    if (empty($currentuser)) {
        $currentuser = $USER;
    }

    echo html_writer::start_tag('div', array('class' => 'mdl-left'));

    $personsearch = false;
    $personsearchstring = null;
    if (!empty($frm->personsubmit) and !empty($frm->name)) {
        $personsearch = true;
        $personsearchstring = $frm->name;
    } else if (!empty($frm->combinedsubmit) and !empty($frm->combinedsearch)) {
        $personsearch = true;
        $personsearchstring = $frm->combinedsearch;
    }

    // Search for person.
    if ($personsearch) {
        if (optional_param('mycourses', 0, PARAM_BOOL)) {
            $users = array();
            $mycourses = enrol_get_my_courses('id');
            $mycoursesids = array();
            foreach ($mycourses as $mycourse) {
                $mycoursesids[] = $mycourse->id;
            }
            $susers = message_search_users($mycoursesids, $personsearchstring);
            foreach ($susers as $suser) {
                $users[$suser->id] = $suser;
            }
        } else {
            $users = message_search_users(SITEID, $personsearchstring);
        }

        if (!empty($users)) {
            echo html_writer::start_tag('p', array('class' => 'heading searchresultcount'));
            echo get_string('userssearchresults', 'message', count($users));
            echo html_writer::end_tag('p');

            echo html_writer::start_tag('table', array('class' => 'messagesearchresults'));
            foreach ($users as $user) {

                if ( $user->contactlistid )  {
                    if ($user->blocked == 0) { // User is not blocked.
                        $strcontact = message_contact_link($user->id, 'remove', true, null, $showicontext);
                        $strblock   = message_contact_link($user->id, 'block', true, null, $showicontext);
                    } else { // blocked
                        $strcontact = message_contact_link($user->id, 'add', true, null, $showicontext);
                        $strblock   = message_contact_link($user->id, 'unblock', true, null, $showicontext);
                    }
                } else {
                    $strcontact = message_contact_link($user->id, 'add', true, null, $showicontext);
                    $strblock   = message_contact_link($user->id, 'block', true, null, $showicontext);
                }

                // Should we show just the icon or icon and text?
                $histicontext = 'icon';
                if ($showicontext) {
                    $histicontext = 'both';
                }
                $strhistory = message_history_link($USER->id, $user->id, true, '', '', $histicontext);

                echo html_writer::start_tag('tr');

                echo html_writer::start_tag('td', array('class' => 'pix'));
                echo $OUTPUT->user_picture($user, array('size' => 20, 'courseid' => SITEID));
                echo html_writer::end_tag('td');

                echo html_writer::start_tag('td',array('class' => 'contact'));
                $action = null;
                $link = new moodle_url("/message/index.php?id=$user->id");
                echo $OUTPUT->action_link($link, fullname($user), $action, array('title' => get_string('sendmessageto', 'message', fullname($user))));
                echo html_writer::end_tag('td');

                echo html_writer::tag('td', $strcontact, array('class' => 'link'));
                echo html_writer::tag('td', $strblock, array('class' => 'link'));
                echo html_writer::tag('td', $strhistory, array('class' => 'link'));

                echo html_writer::end_tag('tr');
            }
            echo html_writer::end_tag('table');

        } else {
            echo html_writer::start_tag('p', array('class' => 'heading searchresultcount'));
            echo get_string('userssearchresults', 'message', 0).'<br /><br />';
            echo html_writer::end_tag('p');
        }
    }

    // search messages for keywords
    $messagesearch = false;
    $messagesearchstring = null;
    if (!empty($frm->keywords)) {
        $messagesearch = true;
        $messagesearchstring = clean_text(trim($frm->keywords));
    } else if (!empty($frm->combinedsubmit) and !empty($frm->combinedsearch)) {
        $messagesearch = true;
        $messagesearchstring = clean_text(trim($frm->combinedsearch));
    }

    if ($messagesearch) {
        if ($messagesearchstring) {
            $keywords = explode(' ', $messagesearchstring);
        } else {
            $keywords = array();
        }
        $tome     = false;
        $fromme   = false;
        $courseid = 'none';

        if (empty($frm->keywordsoption)) {
            $frm->keywordsoption = 'allmine';
        }

        switch ($frm->keywordsoption) {
            case 'tome':
                $tome   = true;
                break;
            case 'fromme':
                $fromme = true;
                break;
            case 'allmine':
                $tome   = true;
                $fromme = true;
                break;
            case 'allusers':
                $courseid = SITEID;
                break;
            case 'courseusers':
                $courseid = $frm->courseid;
                break;
            default:
                $tome   = true;
                $fromme = true;
        }

        if (($messages = message_search($keywords, $fromme, $tome, $courseid)) !== false) {

            // Get a list of contacts.
            if (($contacts = $DB->get_records('message_contacts', array('userid' => $USER->id), '', 'contactid, blocked') ) === false) {
                $contacts = array();
            }

            // Print heading with number of results.
            echo html_writer::start_tag('p', array('class' => 'heading searchresultcount'));
            $countresults = count($messages);
            if ($countresults == MESSAGE_SEARCH_MAX_RESULTS) {
                echo get_string('keywordssearchresultstoomany', 'message', $countresults).' ("'.s($messagesearchstring).'")';
            } else {
                echo get_string('keywordssearchresults', 'message', $countresults);
            }
            echo html_writer::end_tag('p');

            // Print table headings.
            echo html_writer::start_tag('table', array('class' => 'messagesearchresults', 'cellspacing' => '0'));

            $headertdstart = html_writer::start_tag('td', array('class' => 'messagesearchresultscol'));
            $headertdend   = html_writer::end_tag('td');
            echo html_writer::start_tag('tr');
            echo $headertdstart.get_string('from').$headertdend;
            echo $headertdstart.get_string('to').$headertdend;
            echo $headertdstart.get_string('message', 'message').$headertdend;
            echo $headertdstart.get_string('timesent', 'message').$headertdend;
            echo html_writer::end_tag('tr');

            $blockedcount = 0;
            $dateformat = get_string('strftimedatetimeshort');
            $strcontext = get_string('context', 'message');
            foreach ($messages as $message) {

                // Ignore messages to and from blocked users unless $frm->includeblocked is set.
                if (!optional_param('includeblocked', 0, PARAM_BOOL) and (
                      ( isset($contacts[$message->useridfrom]) and ($contacts[$message->useridfrom]->blocked == 1)) or
                      ( isset($contacts[$message->useridto]  ) and ($contacts[$message->useridto]->blocked   == 1))
                                                )
                   ) {
                    $blockedcount ++;
                    continue;
                }

                // Load user-to record.
                if ($message->useridto !== $USER->id) {
                    $userto = core_user::get_user($message->useridto);
                    if ($userto === false) {
                        $userto = core_user::get_noreply_user();
                    }
                    $tocontact = (array_key_exists($message->useridto, $contacts) and
                                    ($contacts[$message->useridto]->blocked == 0) );
                    $toblocked = (array_key_exists($message->useridto, $contacts) and
                                    ($contacts[$message->useridto]->blocked == 1) );
                } else {
                    $userto = false;
                    $tocontact = false;
                    $toblocked = false;
                }

                // Load user-from record.
                if ($message->useridfrom !== $USER->id) {
                    $userfrom = core_user::get_user($message->useridfrom);
                    if ($userfrom === false) {
                        $userfrom = core_user::get_noreply_user();
                    }
                    $fromcontact = (array_key_exists($message->useridfrom, $contacts) and
                                    ($contacts[$message->useridfrom]->blocked == 0) );
                    $fromblocked = (array_key_exists($message->useridfrom, $contacts) and
                                    ($contacts[$message->useridfrom]->blocked == 1) );
                } else {
                    $userfrom = false;
                    $fromcontact = false;
                    $fromblocked = false;
                }

                // Find date string for this message.
                $date = usergetdate($message->timecreated);
                $datestring = $date['year'].$date['mon'].$date['mday'];

                // Print out message row.
                echo html_writer::start_tag('tr', array('valign' => 'top'));

                echo html_writer::start_tag('td', array('class' => 'contact'));
                message_print_user($userfrom, $fromcontact, $fromblocked, $showicontext);
                echo html_writer::end_tag('td');

                echo html_writer::start_tag('td', array('class' => 'contact'));
                message_print_user($userto, $tocontact, $toblocked, $showicontext);
                echo html_writer::end_tag('td');

                echo html_writer::start_tag('td', array('class' => 'summary'));
                echo message_get_fragment($message->smallmessage, $keywords);
                echo html_writer::start_tag('div', array('class' => 'link'));

                // If the user clicks the context link display message sender on the left.
                // EXCEPT if the current user is in the conversation. Current user == always on the left.
                $leftsideuserid = $rightsideuserid = null;
                if ($currentuser->id == $message->useridto) {
                    $leftsideuserid = $message->useridto;
                    $rightsideuserid = $message->useridfrom;
                } else {
                    $leftsideuserid = $message->useridfrom;
                    $rightsideuserid = $message->useridto;
                }
                message_history_link($leftsideuserid, $rightsideuserid, false,
                                     $messagesearchstring, 'm'.$message->id, $strcontext);
                echo html_writer::end_tag('div');
                echo html_writer::end_tag('td');

                echo html_writer::tag('td', userdate($message->timecreated, $dateformat), array('class' => 'date'));

                echo html_writer::end_tag('tr');
            }


            if ($blockedcount > 0) {
                echo html_writer::start_tag('tr');
                echo html_writer::tag('td', get_string('blockedmessages', 'message', $blockedcount), array('colspan' => 4, 'align' => 'center'));
                echo html_writer::end_tag('tr');
            }
            echo html_writer::end_tag('table');

        } else {
            echo html_writer::tag('p', get_string('keywordssearchresults', 'message', 0), array('class' => 'heading'));
        }
    }

    if (!$personsearch && !$messagesearch) {
        //they didn't enter any search terms
        echo $OUTPUT->notification(get_string('emptysearchstring', 'message'));
    }

    echo html_writer::end_tag('div');
}

/**
 * Print information on a user. Used when printing search results.
 *
 * @param object/bool $user the user to display or false if you just want $USER
 * @param bool $iscontact is the user being displayed a contact?
 * @param bool $isblocked is the user being displayed blocked?
 * @param bool $includeicontext include text next to the action icons?
 * @return void
 */
function message_print_user ($user=false, $iscontact=false, $isblocked=false, $includeicontext=false) {
    global $USER, $OUTPUT;

    $userpictureparams = array('size' => 20, 'courseid' => SITEID);

    if ($user === false) {
        echo $OUTPUT->user_picture($USER, $userpictureparams);
    } else if (core_user::is_real_user($user->id)) {
        echo $OUTPUT->user_picture($user, $userpictureparams);

        $link = new moodle_url("/message/index.php?id=$user->id");
        echo $OUTPUT->action_link($link, fullname($user), null, array('title' =>
                get_string('sendmessageto', 'message', fullname($user))));

        $return = false;
        $script = null;
        if ($iscontact) {
            message_contact_link($user->id, 'remove', $return, $script, $includeicontext);
        } else {
            message_contact_link($user->id, 'add', $return, $script, $includeicontext);
        }

        if ($isblocked) {
            message_contact_link($user->id, 'unblock', $return, $script, $includeicontext);
        } else {
            message_contact_link($user->id, 'block', $return, $script, $includeicontext);
        }
    } else {
        // If not real user, then don't show any links.
        $userpictureparams['link'] = false;
        // Stock profile picture should be displayed.
        echo $OUTPUT->user_picture($user, $userpictureparams);
    }
}

/**
 * Print a message contact link
 *
 * @param int $userid the ID of the user to apply to action to
 * @param string $linktype can be add, remove, block or unblock
 * @param bool $return if true return the link as a string. If false echo the link.
 * @param string $script the URL to send the user to when the link is clicked. If null, the current page.
 * @param bool $text include text next to the icons?
 * @param bool $icon include a graphical icon?
 * @return string  if $return is true otherwise bool
 */
function message_contact_link($userid, $linktype='add', $return=false, $script=null, $text=false, $icon=true) {
    global $OUTPUT, $PAGE;

    //hold onto the strings as we're probably creating a bunch of links
    static $str;

    if (empty($script)) {
        //strip off previous action params like 'removecontact'
        $script = message_remove_url_params($PAGE->url);
    }

    if (empty($str->blockcontact)) {
       $str = new stdClass();
       $str->blockcontact   =  get_string('blockcontact', 'message');
       $str->unblockcontact =  get_string('unblockcontact', 'message');
       $str->removecontact  =  get_string('removecontact', 'message');
       $str->addcontact     =  get_string('addcontact', 'message');
    }

    $command = $linktype.'contact';
    $string  = $str->{$command};

    $safealttext = s($string);

    $safestring = '';
    if (!empty($text)) {
        $safestring = $safealttext;
    }

    $img = '';
    if ($icon) {
        $iconpath = null;
        switch ($linktype) {
            case 'block':
                $iconpath = 't/block';
                break;
            case 'unblock':
                $iconpath = 't/unblock';
                break;
            case 'remove':
                $iconpath = 't/removecontact';
                break;
            case 'add':
            default:
                $iconpath = 't/addcontact';
        }

        $img = '<img src="'.$OUTPUT->pix_url($iconpath).'" class="iconsmall" alt="'.$safealttext.'" />';
    }

    $output = '<span class="'.$linktype.'contact">'.
              '<a href="'.$script.'&amp;'.$command.'='.$userid.
              '&amp;sesskey='.sesskey().'" title="'.$safealttext.'">'.
              $img.
              $safestring.'</a></span>';

    if ($return) {
        return $output;
    } else {
        echo $output;
        return true;
    }
}

/**
 * echo or return a link to take the user to the full message history between themselves and another user
 *
 * @param int $userid1 the ID of the user displayed on the left (usually the current user)
 * @param int $userid2 the ID of the other user
 * @param bool $return true to return the link as a string. False to echo the link.
 * @param string $keywords any keywords to highlight in the message history
 * @param string $position anchor name to jump to within the message history
 * @param string $linktext optionally specify the link text
 * @return string|bool. Returns a string if $return is true. Otherwise returns a boolean.
 */
function message_history_link($userid1, $userid2, $return=false, $keywords='', $position='', $linktext='') {
    global $OUTPUT, $PAGE;
    static $strmessagehistory;

    if (empty($strmessagehistory)) {
        $strmessagehistory = get_string('messagehistory', 'message');
    }

    if ($position) {
        $position = "#$position";
    }
    if ($keywords) {
        $keywords = "&search=".urlencode($keywords);
    }

    if ($linktext == 'icon') {  // Icon only
        $fulllink = '<img src="'.$OUTPUT->pix_url('t/messages') . '" class="iconsmall" alt="'.$strmessagehistory.'" />';
    } else if ($linktext == 'both') {  // Icon and standard name
        $fulllink = '<img src="'.$OUTPUT->pix_url('t/messages') . '" class="iconsmall" alt="" />';
        $fulllink .= '&nbsp;'.$strmessagehistory;
    } else if ($linktext) {    // Custom name
        $fulllink = $linktext;
    } else {                   // Standard name only
        $fulllink = $strmessagehistory;
    }

    $popupoptions = array(
            'height' => 500,
            'width' => 500,
            'menubar' => false,
            'location' => false,
            'status' => true,
            'scrollbars' => true,
            'resizable' => true);

    $link = new moodle_url('/message/index.php?history='.MESSAGE_HISTORY_ALL."&user1=$userid1&user2=$userid2$keywords$position");
    if ($PAGE->url && $PAGE->url->get_param('viewing')) {
        $link->param('viewing', $PAGE->url->get_param('viewing'));
    }
    $action = null;
    $str = $OUTPUT->action_link($link, $fulllink, $action, array('title' => $strmessagehistory));

    $str = '<span class="history">'.$str.'</span>';

    if ($return) {
        return $str;
    } else {
        echo $str;
        return true;
    }
}


/**
 * Search through course users.
 *
 * If $courseids contains the site course then this function searches
 * through all undeleted and confirmed users.
 *
 * @param int|array $courseids Course ID or array of course IDs.
 * @param string $searchtext the text to search for.
 * @param string $sort the column name to order by.
 * @param string|array $exceptions comma separated list or array of user IDs to exclude.
 * @return array An array of {@link $USER} records.
 */
function message_search_users($courseids, $searchtext, $sort='', $exceptions='') {
    global $CFG, $USER, $DB;

    // Basic validation to ensure that the parameter $courseids is not an empty array or an empty value.
    if (!$courseids) {
        $courseids = array(SITEID);
    }

    // Allow an integer to be passed.
    if (!is_array($courseids)) {
        $courseids = array($courseids);
    }

    $fullname = $DB->sql_fullname();
    $ufields = user_picture::fields('u');

    if (!empty($sort)) {
        $order = ' ORDER BY '. $sort;
    } else {
        $order = '';
    }

    $params = array(
        'userid' => $USER->id,
        'query' => "%$searchtext%"
    );

    if (empty($exceptions)) {
        $exceptions = array();
    } else if (!empty($exceptions) && is_string($exceptions)) {
        $exceptions = explode(',', $exceptions);
    }

    // Ignore self and guest account.
    $exceptions[] = $USER->id;
    $exceptions[] = $CFG->siteguest;

    // Exclude exceptions from the search result.
    list($except, $params_except) = $DB->get_in_or_equal($exceptions, SQL_PARAMS_NAMED, 'param', false);
    $except = ' AND u.id ' . $except;
    $params = array_merge($params_except, $params);

    if (in_array(SITEID, $courseids)) {
        // Search on site level.
        return $DB->get_records_sql("SELECT $ufields, mc.id as contactlistid, mc.blocked
                                       FROM {user} u
                                       LEFT JOIN {message_contacts} mc
                                            ON mc.contactid = u.id AND mc.userid = :userid
                                      WHERE u.deleted = '0' AND u.confirmed = '1'
                                            AND (".$DB->sql_like($fullname, ':query', false).")
                                            $except
                                     $order", $params);
    } else {
        // Search in courses.

        // Getting the context IDs or each course.
        $contextids = array();
        foreach ($courseids as $courseid) {
            $context = context_course::instance($courseid);
            $contextids = array_merge($contextids, $context->get_parent_context_ids(true));
        }
        list($contextwhere, $contextparams) = $DB->get_in_or_equal(array_unique($contextids), SQL_PARAMS_NAMED, 'context');
        $params = array_merge($params, $contextparams);

        // Everyone who has a role assignment in this course or higher.
        // TODO: add enabled enrolment join here (skodak)
        $users = $DB->get_records_sql("SELECT DISTINCT $ufields, mc.id as contactlistid, mc.blocked
                                         FROM {user} u
                                         JOIN {role_assignments} ra ON ra.userid = u.id
                                         LEFT JOIN {message_contacts} mc
                                              ON mc.contactid = u.id AND mc.userid = :userid
                                        WHERE u.deleted = '0' AND u.confirmed = '1'
                                              AND (".$DB->sql_like($fullname, ':query', false).")
                                              AND ra.contextid $contextwhere
                                              $except
                                       $order", $params);

        return $users;
    }
}

/**
 * Search a user's messages
 *
 * Returns a list of posts found using an array of search terms
 * eg   word  +word -word
 *
 * @param array $searchterms an array of search terms (strings)
 * @param bool $fromme include messages from the user?
 * @param bool $tome include messages to the user?
 * @param mixed $courseid SITEID for admins searching all messages. Other behaviour not yet implemented
 * @param int $userid the user ID of the current user
 * @return mixed An array of messages or false if no matching messages were found
 */
function message_search($searchterms, $fromme=true, $tome=true, $courseid='none', $userid=0) {
    global $CFG, $USER, $DB;

    // If user is searching all messages check they are allowed to before doing anything else.
    if ($courseid == SITEID && !has_capability('moodle/site:readallmessages', context_system::instance())) {
        print_error('accessdenied','admin');
    }

    // If no userid sent then assume current user.
    if ($userid == 0) $userid = $USER->id;

    // Some differences in SQL syntax.
    if ($DB->sql_regex_supported()) {
        $REGEXP    = $DB->sql_regex(true);
        $NOTREGEXP = $DB->sql_regex(false);
    }

    $searchcond = array();
    $params = array();
    $i = 0;

    // Preprocess search terms to check whether we have at least 1 eligible search term.
    // If we do we can drop words around it like 'a'.
    $dropshortwords = false;
    foreach ($searchterms as $searchterm) {
        if (strlen($searchterm) >= 2) {
            $dropshortwords = true;
        }
    }

    foreach ($searchterms as $searchterm) {
        $i++;

        $NOT = false; // Initially we aren't going to perform NOT LIKE searches, only MSSQL and Oracle.

        if ($dropshortwords && strlen($searchterm) < 2) {
            continue;
        }
        // Under Oracle and MSSQL, trim the + and - operators and perform simpler LIKE search.
        if (!$DB->sql_regex_supported()) {
            if (substr($searchterm, 0, 1) == '-') {
                $NOT = true;
            }
            $searchterm = trim($searchterm, '+-');
        }

        if (substr($searchterm,0,1) == "+") {
            $searchterm = substr($searchterm,1);
            $searchterm = preg_quote($searchterm, '|');
            $searchcond[] = "m.fullmessage $REGEXP :ss$i";
            $params['ss'.$i] = "(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)";

        } else if (substr($searchterm,0,1) == "-") {
            $searchterm = substr($searchterm,1);
            $searchterm = preg_quote($searchterm, '|');
            $searchcond[] = "m.fullmessage $NOTREGEXP :ss$i";
            $params['ss'.$i] = "(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)";

        } else {
            $searchcond[] = $DB->sql_like("m.fullmessage", ":ss$i", false, true, $NOT);
            $params['ss'.$i] = "%$searchterm%";
        }
    }

    if (empty($searchcond)) {
        $searchcond = " ".$DB->sql_like('m.fullmessage', ':ss1', false);
        $params['ss1'] = "%";
    } else {
        $searchcond = implode(" AND ", $searchcond);
    }

    // There are several possibilities
    // 1. courseid = SITEID : The admin is searching messages by all users
    // 2. courseid = ??     : A teacher is searching messages by users in
    //                        one of their courses - currently disabled
    // 3. courseid = none   : User is searching their own messages;
    //    a.  Messages from user
    //    b.  Messages to user
    //    c.  Messages to and from user

    if ($fromme && $tome) {
        $searchcond .= " AND ((useridto = :useridto AND timeusertodeleted = 0) OR
            (useridfrom = :useridfrom AND timeuserfromdeleted = 0))";
        $params['useridto'] = $userid;
        $params['useridfrom'] = $userid;
    } else if ($fromme) {
        $searchcond .= " AND (useridfrom = :useridfrom AND timeuserfromdeleted = 0)";
        $params['useridfrom'] = $userid;
    } else if ($tome) {
        $searchcond .= " AND (useridto = :useridto AND timeusertodeleted = 0)";
        $params['useridto'] = $userid;
    }
    if ($courseid == SITEID) { // Admin is searching all messages.
        $m_read   = $DB->get_records_sql("SELECT m.id, m.useridto, m.useridfrom, m.smallmessage, m.fullmessage, m.timecreated
                                            FROM {message_read} m
                                           WHERE $searchcond", $params, 0, MESSAGE_SEARCH_MAX_RESULTS);
        $m_unread = $DB->get_records_sql("SELECT m.id, m.useridto, m.useridfrom, m.smallmessage, m.fullmessage, m.timecreated
                                            FROM {message} m
                                           WHERE $searchcond", $params, 0, MESSAGE_SEARCH_MAX_RESULTS);

    } else if ($courseid !== 'none') {
        // This has not been implemented due to security concerns.
        $m_read   = array();
        $m_unread = array();

    } else {

        if ($fromme and $tome) {
            $searchcond .= " AND (m.useridfrom=:userid1 OR m.useridto=:userid2)";
            $params['userid1'] = $userid;
            $params['userid2'] = $userid;

        } else if ($fromme) {
            $searchcond .= " AND m.useridfrom=:userid";
            $params['userid'] = $userid;

        } else if ($tome) {
            $searchcond .= " AND m.useridto=:userid";
            $params['userid'] = $userid;
        }

        $m_read   = $DB->get_records_sql("SELECT m.id, m.useridto, m.useridfrom, m.smallmessage, m.fullmessage, m.timecreated
                                            FROM {message_read} m
                                           WHERE $searchcond", $params, 0, MESSAGE_SEARCH_MAX_RESULTS);
        $m_unread = $DB->get_records_sql("SELECT m.id, m.useridto, m.useridfrom, m.smallmessage, m.fullmessage, m.timecreated
                                            FROM {message} m
                                           WHERE $searchcond", $params, 0, MESSAGE_SEARCH_MAX_RESULTS);

    }

    /// The keys may be duplicated in $m_read and $m_unread so we can't
    /// do a simple concatenation
    $messages = array();
    foreach ($m_read as $m) {
        $messages[] = $m;
    }
    foreach ($m_unread as $m) {
        $messages[] = $m;
    }

    return (empty($messages)) ? false : $messages;
}

/**
 * Given a message object that we already know has a long message
 * this function truncates the message nicely to the first
 * sane place between $CFG->forum_longpost and $CFG->forum_shortpost
 *
 * @param string $message the message
 * @param int $minlength the minimum length to trim the message to
 * @return string the shortened message
 */
function message_shorten_message($message, $minlength = 0) {
    $i = 0;
    $tag = false;
    $length = strlen($message);
    $count = 0;
    $stopzone = false;
    $truncate = 0;
    if ($minlength == 0) $minlength = MESSAGE_SHORTLENGTH;


    for ($i=0; $i<$length; $i++) {
        $char = $message[$i];

        switch ($char) {
            case "<":
                $tag = true;
                break;
            case ">":
                $tag = false;
                break;
            default:
                if (!$tag) {
                    if ($stopzone) {
                        if ($char == '.' or $char == ' ') {
                            $truncate = $i+1;
                            break 2;
                        }
                    }
                    $count++;
                }
                break;
        }
        if (!$stopzone) {
            if ($count > $minlength) {
                $stopzone = true;
            }
        }
    }

    if (!$truncate) {
        $truncate = $i;
    }

    return substr($message, 0, $truncate);
}


/**
 * Given a string and an array of keywords, this function looks
 * for the first keyword in the string, and then chops out a
 * small section from the text that shows that word in context.
 *
 * @param string $message the text to search
 * @param array $keywords array of keywords to find
 */
function message_get_fragment($message, $keywords) {

    $fullsize = 160;
    $halfsize = (int)($fullsize/2);

    $message = strip_tags($message);

    foreach ($keywords as $keyword) {  // Just get the first one
        if ($keyword !== '') {
            break;
        }
    }
    if (empty($keyword)) {   // None found, so just return start of message
        return message_shorten_message($message, 30);
    }

    $leadin = $leadout = '';

/// Find the start of the fragment
    $start = 0;
    $length = strlen($message);

    $pos = strpos($message, $keyword);
    if ($pos > $halfsize) {
        $start = $pos - $halfsize;
        $leadin = '...';
    }
/// Find the end of the fragment
    $end = $start + $fullsize;
    if ($end > $length) {
        $end = $length;
    } else {
        $leadout = '...';
    }

/// Pull out the fragment and format it

    $fragment = substr($message, $start, $end - $start);
    $fragment = $leadin.highlight(implode(' ',$keywords), $fragment).$leadout;
    return $fragment;
=======
function message_get_contact($contactid) {
    global $USER, $DB;
    return $DB->get_record('message_contacts', array('userid' => $USER->id, 'contactid' => $contactid));
>>>>>>> 9e7c3978895c7cab585c2f5234ca536151d3bef6
}

/**
 * Search through course users.
 *
 * If $courseids contains the site course then this function searches
 * through all undeleted and confirmed users.
 *
 * @param int|array $courseids Course ID or array of course IDs.
 * @param string $searchtext the text to search for.
 * @param string $sort the column name to order by.
 * @param string|array $exceptions comma separated list or array of user IDs to exclude.
 * @return array An array of {@link $USER} records.
 */
function message_search_users($courseids, $searchtext, $sort='', $exceptions='') {
    global $CFG, $USER, $DB;

    // Basic validation to ensure that the parameter $courseids is not an empty array or an empty value.
    if (!$courseids) {
        $courseids = array(SITEID);
    }

    // Allow an integer to be passed.
    if (!is_array($courseids)) {
        $courseids = array($courseids);
    }

    $fullname = $DB->sql_fullname();
    $ufields = user_picture::fields('u');

    if (!empty($sort)) {
        $order = ' ORDER BY '. $sort;
    } else {
        $order = '';
    }

    $params = array(
        'userid' => $USER->id,
        'query' => "%$searchtext%"
    );

    if (empty($exceptions)) {
        $exceptions = array();
    } else if (!empty($exceptions) && is_string($exceptions)) {
        $exceptions = explode(',', $exceptions);
    }

    // Ignore self and guest account.
    $exceptions[] = $USER->id;
    $exceptions[] = $CFG->siteguest;

    // Exclude exceptions from the search result.
    list($except, $params_except) = $DB->get_in_or_equal($exceptions, SQL_PARAMS_NAMED, 'param', false);
    $except = ' AND u.id ' . $except;
    $params = array_merge($params_except, $params);

    if (in_array(SITEID, $courseids)) {
        // Search on site level.
        return $DB->get_records_sql("SELECT $ufields, mc.id as contactlistid, mc.blocked
                                       FROM {user} u
                                       LEFT JOIN {message_contacts} mc
                                            ON mc.contactid = u.id AND mc.userid = :userid
                                      WHERE u.deleted = '0' AND u.confirmed = '1'
                                            AND (".$DB->sql_like($fullname, ':query', false).")
                                            $except
                                     $order", $params);
    } else {
        // Search in courses.

        // Getting the context IDs or each course.
        $contextids = array();
        foreach ($courseids as $courseid) {
            $context = context_course::instance($courseid);
            $contextids = array_merge($contextids, $context->get_parent_context_ids(true));
        }
        list($contextwhere, $contextparams) = $DB->get_in_or_equal(array_unique($contextids), SQL_PARAMS_NAMED, 'context');
        $params = array_merge($params, $contextparams);

        // Everyone who has a role assignment in this course or higher.
        // TODO: add enabled enrolment join here (skodak)
        $users = $DB->get_records_sql("SELECT DISTINCT $ufields, mc.id as contactlistid, mc.blocked
                                         FROM {user} u
                                         JOIN {role_assignments} ra ON ra.userid = u.id
                                         LEFT JOIN {message_contacts} mc
                                              ON mc.contactid = u.id AND mc.userid = :userid
                                        WHERE u.deleted = '0' AND u.confirmed = '1'
                                              AND (".$DB->sql_like($fullname, ':query', false).")
                                              AND ra.contextid $contextwhere
                                              $except
                                       $order", $params);

        return $users;
    }
}

/**
 * Format a message for display in the message history
 *
 * @param object $message the message object
 * @param string $format optional date format
 * @param string $keywords keywords to highlight
 * @param string $class CSS class to apply to the div around the message
 * @return string the formatted message
 */
function message_format_message($message, $format='', $keywords='', $class='other') {

    static $dateformat;

    //if we haven't previously set the date format or they've supplied a new one
    if ( empty($dateformat) || (!empty($format) && $dateformat != $format) ) {
        if ($format) {
            $dateformat = $format;
        } else {
            $dateformat = get_string('strftimedatetimeshort');
        }
    }
    $time = userdate($message->timecreated, $dateformat);

    $messagetext = message_format_message_text($message, false);

    if ($keywords) {
        $messagetext = highlight($keywords, $messagetext);
    }

    $messagetext .= message_format_contexturl($message);

    $messagetext = clean_text($messagetext, FORMAT_HTML);

    return <<<TEMPLATE
<div class='message $class'>
    <a name="m{$message->id}"></a>
    <span class="message-meta"><span class="time">$time</span></span>: <span class="text">$messagetext</span>
</div>
TEMPLATE;
}

/**
 * Format a the context url and context url name of a message for display
 *
 * @param object $message the message object
 * @return string the formatted string
 */
function message_format_contexturl($message) {
    $s = null;

    if (!empty($message->contexturl)) {
        $displaytext = null;
        if (!empty($message->contexturlname)) {
            $displaytext= $message->contexturlname;
        } else {
            $displaytext= $message->contexturl;
        }
        $s .= html_writer::start_tag('div',array('class' => 'messagecontext'));
            $s .= get_string('view').': '.html_writer::tag('a', $displaytext, array('href' => $message->contexturl));
        $s .= html_writer::end_tag('div');
    }

    return $s;
}

/**
 * Send a message from one user to another. Will be delivered according to the message recipients messaging preferences
 *
 * @param object $userfrom the message sender
 * @param object $userto the message recipient
 * @param string $message the message
 * @param int $format message format such as FORMAT_PLAIN or FORMAT_HTML
 * @return int|false the ID of the new message or false
 */
function message_post_message($userfrom, $userto, $message, $format) {
    global $SITE, $CFG, $USER;

    $eventdata = new \core\message\message();
    $eventdata->courseid         = 1;
    $eventdata->component        = 'moodle';
    $eventdata->name             = 'instantmessage';
    $eventdata->userfrom         = $userfrom;
    $eventdata->userto           = $userto;

    //using string manager directly so that strings in the message will be in the message recipients language rather than the senders
    $eventdata->subject          = get_string_manager()->get_string('unreadnewmessage', 'message', fullname($userfrom), $userto->lang);

    if ($format == FORMAT_HTML) {
        $eventdata->fullmessagehtml  = $message;
        //some message processors may revert to sending plain text even if html is supplied
        //so we keep both plain and html versions if we're intending to send html
        $eventdata->fullmessage = html_to_text($eventdata->fullmessagehtml);
    } else {
        $eventdata->fullmessage      = $message;
        $eventdata->fullmessagehtml  = '';
    }

    $eventdata->fullmessageformat = $format;
    $eventdata->smallmessage     = $message;//store the message unfiltered. Clean up on output.

    $s = new stdClass();
    $s->sitename = format_string($SITE->shortname, true, array('context' => context_course::instance(SITEID)));
    $s->url = $CFG->wwwroot.'/message/index.php?user='.$userto->id.'&id='.$userfrom->id;

    $emailtagline = get_string_manager()->get_string('emailtagline', 'message', $s, $userto->lang);
    if (!empty($eventdata->fullmessage)) {
        $eventdata->fullmessage .= "\n\n---------------------------------------------------------------------\n".$emailtagline;
    }
    if (!empty($eventdata->fullmessagehtml)) {
        $eventdata->fullmessagehtml .= "<br /><br />---------------------------------------------------------------------<br />".$emailtagline;
    }

    $eventdata->timecreated     = time();
    $eventdata->notification    = 0;
    return message_send($eventdata);
}

/**
 * Get all message processors, validate corresponding plugin existance and
 * system configuration
 *
 * @param bool $ready only return ready-to-use processors
 * @param bool $reset Reset list of message processors (used in unit tests)
 * @param bool $resetonly Just reset, then exit
 * @return mixed $processors array of objects containing information on message processors
 */
function get_message_processors($ready = false, $reset = false, $resetonly = false) {
    global $DB, $CFG;

    static $processors;
    if ($reset) {
        $processors = array();

        if ($resetonly) {
            return $processors;
        }
    }

    if (empty($processors)) {
        // Get all processors, ensure the name column is the first so it will be the array key
        $processors = $DB->get_records('message_processors', null, 'name DESC', 'name, id, enabled');
        foreach ($processors as &$processor){
            $processor = \core_message\api::get_processed_processor_object($processor);
        }
    }
    if ($ready) {
        // Filter out enabled and system_configured processors
        $readyprocessors = $processors;
        foreach ($readyprocessors as $readyprocessor) {
            if (!($readyprocessor->enabled && $readyprocessor->configured)) {
                unset($readyprocessors[$readyprocessor->name]);
            }
        }
        return $readyprocessors;
    }

    return $processors;
}

/**
 * Get all message providers, validate their plugin existance and
 * system configuration
 *
 * @return mixed $processors array of objects containing information on message processors
 */
function get_message_providers() {
    global $CFG, $DB;

    $pluginman = core_plugin_manager::instance();

    $providers = $DB->get_records('message_providers', null, 'name');

    // Remove all the providers whose plugins are disabled or don't exist
    foreach ($providers as $providerid => $provider) {
        $plugin = $pluginman->get_plugin_info($provider->component);
        if ($plugin) {
            if ($plugin->get_status() === core_plugin_manager::PLUGIN_STATUS_MISSING) {
                unset($providers[$providerid]);   // Plugins does not exist
                continue;
            }
            if ($plugin->is_enabled() === false) {
                unset($providers[$providerid]);   // Plugin disabled
                continue;
            }
        }
    }
    return $providers;
}

/**
 * Get an instance of the message_output class for one of the output plugins.
 * @param string $type the message output type. E.g. 'email' or 'jabber'.
 * @return message_output message_output the requested class.
 */
function get_message_processor($type) {
    global $CFG;

    // Note, we cannot use the get_message_processors function here, becaues this
    // code is called during install after installing each messaging plugin, and
    // get_message_processors caches the list of installed plugins.

    $processorfile = $CFG->dirroot . "/message/output/{$type}/message_output_{$type}.php";
    if (!is_readable($processorfile)) {
        throw new coding_exception('Unknown message processor type ' . $type);
    }

    include_once($processorfile);

    $processclass = 'message_output_' . $type;
    if (!class_exists($processclass)) {
        throw new coding_exception('Message processor ' . $type .
                ' does not define the right class');
    }

    return new $processclass();
}

/**
 * Get messaging outputs default (site) preferences
 *
 * @return object $processors object containing information on message processors
 */
function get_message_output_default_preferences() {
    return get_config('message');
}

/**
 * Translate message default settings from binary value to the array of string
 * representing the settings to be stored. Also validate the provided value and
 * use default if it is malformed.
 *
 * @param  int    $plugindefault Default setting suggested by plugin
 * @param  string $processorname The name of processor
 * @return array  $settings array of strings in the order: $permitted, $loggedin, $loggedoff.
 */
function translate_message_default_setting($plugindefault, $processorname) {
    // Preset translation arrays
    $permittedvalues = array(
        0x04 => 'disallowed',
        0x08 => 'permitted',
        0x0c => 'forced',
    );

    $loggedinstatusvalues = array(
        0x00 => null, // use null if loggedin/loggedoff is not defined
        0x01 => 'loggedin',
        0x02 => 'loggedoff',
    );

    // define the default setting
    $processor = get_message_processor($processorname);
    $default = $processor->get_default_messaging_settings();

    // Validate the value. It should not exceed the maximum size
    if (!is_int($plugindefault) || ($plugindefault > 0x0f)) {
        debugging(get_string('errortranslatingdefault', 'message'));
        $plugindefault = $default;
    }
    // Use plugin default setting of 'permitted' is 0
    if (!($plugindefault & MESSAGE_PERMITTED_MASK)) {
        $plugindefault = $default;
    }

    $permitted = $permittedvalues[$plugindefault & MESSAGE_PERMITTED_MASK];
    $loggedin = $loggedoff = null;

    if (($plugindefault & MESSAGE_PERMITTED_MASK) == MESSAGE_PERMITTED) {
        $loggedin = $loggedinstatusvalues[$plugindefault & MESSAGE_DEFAULT_LOGGEDIN];
        $loggedoff = $loggedinstatusvalues[$plugindefault & MESSAGE_DEFAULT_LOGGEDOFF];
    }

    return array($permitted, $loggedin, $loggedoff);
}

/**
 * Get messages sent or/and received by the specified users.
 * Please note that this function return deleted messages too.
 *
 * @param  int      $useridto       the user id who received the message
 * @param  int      $useridfrom     the user id who sent the message. -10 or -20 for no-reply or support user
 * @param  int      $notifications  1 for retrieving notifications, 0 for messages, -1 for both
 * @param  bool     $read           true for retrieving read messages, false for unread
 * @param  string   $sort           the column name to order by including optionally direction
 * @param  int      $limitfrom      limit from
 * @param  int      $limitnum       limit num
 * @return external_description
 * @since  2.8
 */
function message_get_messages($useridto, $useridfrom = 0, $notifications = -1, $read = true,
                                $sort = 'mr.timecreated DESC', $limitfrom = 0, $limitnum = 0) {
    global $DB;

    // If the 'useridto' value is empty then we are going to retrieve messages sent by the useridfrom to any user.
    if (empty($useridto)) {
        $userfields = get_all_user_name_fields(true, 'u', '', 'userto');
    } else {
        $userfields = get_all_user_name_fields(true, 'u', '', 'userfrom');
    }

    // Create the SQL we will be using.
    $messagesql = "SELECT mr.*, $userfields, 0 as notification, '' as contexturl, '' as contexturlname,
                          mua.timecreated as timeusertodeleted, mua2.timecreated as timeread,
                          mua3.timecreated as timeuserfromdeleted
                     FROM {messages} mr
               INNER JOIN {message_conversations} mc
                       ON mc.id = mr.conversationid
               INNER JOIN {message_conversation_members} mcm
                       ON mcm.conversationid = mc.id ";

    $notificationsql = "SELECT mr.*, $userfields, 1 as notification
                          FROM {notifications} mr ";

    $messagejoinsql = "LEFT JOIN {message_user_actions} mua
                              ON (mua.messageid = mr.id AND mua.userid = mcm.userid AND mua.action = ?)
                       LEFT JOIN {message_user_actions} mua2
                              ON (mua2.messageid = mr.id AND mua2.userid = mcm.userid AND mua2.action = ?)
                       LEFT JOIN {message_user_actions} mua3
                              ON (mua3.messageid = mr.id AND mua3.userid = mr.useridfrom AND mua3.action = ?)";
    $messagejoinparams = [\core_message\api::MESSAGE_ACTION_DELETED, \core_message\api::MESSAGE_ACTION_READ,
        \core_message\api::MESSAGE_ACTION_DELETED];
    $notificationsparams = [];

    // If the 'useridto' value is empty then we are going to retrieve messages sent by the useridfrom to any user.
    if (empty($useridto)) {
        // Create the messaging query and params.
        $messagesql .= "INNER JOIN {user} u
                                ON u.id = mcm.userid
                                $messagejoinsql
                             WHERE mr.useridfrom = ?
                               AND mr.useridfrom != mcm.userid
                               AND u.deleted = 0 ";
        $messageparams = array_merge($messagejoinparams, [$useridfrom]);

        // Create the notifications query and params.
        $notificationsql .= "INNER JOIN {user} u
                                     ON u.id = mr.useridto
                                  WHERE mr.useridfrom = ?
                                    AND u.deleted = 0 ";
        $notificationsparams[] = $useridfrom;
    } else {
        // Create the messaging query and params.
        // Left join because useridfrom may be -10 or -20 (no-reply and support users).
        $messagesql .= "LEFT JOIN {user} u
                               ON u.id = mr.useridfrom
                               $messagejoinsql
                            WHERE mcm.userid = ?
                              AND mr.useridfrom != mcm.userid
                              AND u.deleted = 0 ";
        $messageparams = array_merge($messagejoinparams, [$useridto]);
        if (!empty($useridfrom)) {
            $messagesql .= " AND mr.useridfrom = ? ";
            $messageparams[] = $useridfrom;
        }

        // Create the notifications query and params.
        // Left join because useridfrom may be -10 or -20 (no-reply and support users).
        $notificationsql .= "LEFT JOIN {user} u
                                    ON (u.id = mr.useridfrom AND u.deleted = 0)
                                 WHERE mr.useridto = ? ";
        $notificationsparams[] = $useridto;
        if (!empty($useridfrom)) {
            $notificationsql .= " AND mr.useridfrom = ? ";
            $notificationsparams[] = $useridfrom;
        }
    }
    if ($read) {
        $notificationsql .= "AND mr.timeread IS NOT NULL ";
    } else {
        $notificationsql .= "AND mr.timeread IS NULL ";
    }
    $messagesql .= "ORDER BY $sort";
    $notificationsql .= "ORDER BY $sort";

    // Handle messages if needed.
    if ($notifications === -1 || $notifications === 0) {
        $messages = $DB->get_records_sql($messagesql, $messageparams, $limitfrom, $limitnum);
        // Get rid of the messages that have either been read or not read depending on the value of $read.
        $messages = array_filter($messages, function ($message) use ($read) {
            if ($read) {
                return !is_null($message->timeread);
            }

            return is_null($message->timeread);
        });
    }

    // All.
    if ($notifications === -1) {
        return array_merge($messages, $DB->get_records_sql($notificationsql, $notificationsparams, $limitfrom, $limitnum));
    } else if ($notifications === 1) { // Just notifications.
        return $DB->get_records_sql($notificationsql, $notificationsparams, $limitfrom, $limitnum);
    }

    // Just messages.
    return $messages;
}

/**
 * Handles displaying processor settings in a fragment.
 *
 * @param array $args
 * @return bool|string
 * @throws moodle_exception
 */
function message_output_fragment_processor_settings($args = []) {
    global $PAGE;

    if (!isset($args['type'])) {
        throw new moodle_exception('Must provide a processor type');
    }

    if (!isset($args['userid'])) {
        throw new moodle_exception('Must provide a userid');
    }

    $type = $args['type'];
    $userid = $args['userid'];

    $user = core_user::get_user($userid, '*', MUST_EXIST);
    $processor = get_message_processor($type);
    $providers = message_get_providers_for_user($userid);
    $processorwrapper = new stdClass();
    $processorwrapper->object = $processor;
    $preferences = \core_message\api::get_all_message_preferences([$processorwrapper], $providers, $user);

    $processoroutput = new \core_message\output\preferences\processor($processor, $preferences, $user, $type);
    $renderer = $PAGE->get_renderer('core', 'message');

    return $renderer->render_from_template('core_message/preferences_processor', $processoroutput->export_for_template($renderer));
}

/**
 * Checks if current user is allowed to edit messaging preferences of another user
 *
 * @param stdClass $user user whose preferences we are updating
 * @return bool
 */
function core_message_can_edit_message_profile($user) {
    global $USER;
    if ($user->id == $USER->id) {
        return has_capability('moodle/user:editownmessageprofile', context_system::instance());
    } else {
        $personalcontext = context_user::instance($user->id);
        if (!has_capability('moodle/user:editmessageprofile', $personalcontext)) {
            return false;
        }
        if (isguestuser($user)) {
            return false;
        }
        // No editing of admins by non-admins.
        if (is_siteadmin($user) and !is_siteadmin($USER)) {
            return false;
        }
        return true;
    }
}

/**
 * Implements callback user_preferences, whitelists preferences that users are allowed to update directly
 *
 * Used in {@see core_user::fill_preferences_cache()}, see also {@see useredit_update_user_preference()}
 *
 * @return array
 */
function core_message_user_preferences() {

    $preferences = [];
    $preferences['message_blocknoncontacts'] = array('type' => PARAM_INT, 'null' => NULL_NOT_ALLOWED, 'default' => 0,
        'choices' => array(0, 1));
    $preferences['/^message_provider_([\w\d_]*)_logged(in|off)$/'] = array('isregex' => true, 'type' => PARAM_NOTAGS,
        'null' => NULL_NOT_ALLOWED, 'default' => 'none',
        'permissioncallback' => function ($user, $preferencename) {
            global $CFG;
            require_once($CFG->libdir.'/messagelib.php');
            if (core_message_can_edit_message_profile($user) &&
                    preg_match('/^message_provider_([\w\d_]*)_logged(in|off)$/', $preferencename, $matches)) {
                $providers = message_get_providers_for_user($user->id);
                foreach ($providers as $provider) {
                    if ($matches[1] === $provider->component . '_' . $provider->name) {
                       return true;
                    }
                }
            }
            return false;
        },
        'cleancallback' => function ($value, $preferencename) {
            if ($value === 'none' || empty($value)) {
                return 'none';
            }
            $parts = explode('/,/', $value);
            $processors = array_keys(get_message_processors());
            array_filter($parts, function($v) use ($processors) {return in_array($v, $processors);});
            return $parts ? join(',', $parts) : 'none';
        });
    return $preferences;
}
