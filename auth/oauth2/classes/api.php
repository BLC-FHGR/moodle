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
 * Class for loading/storing oauth2 linked logins from the DB.
 *
 * @package    auth_oauth2
 * @copyright  2017 Damyon Wiese
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace auth_oauth2;

require_once($CFG->dirroot . '/auth/oauth2/vendor/autoload.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/editlib.php');
require_once($CFG->dirroot . '/webservice/lib.php');
require_once($CFG->dirroot . '/lib/accesslib.php');

use Jose\Object\JWK;
use Jose\Loader;

use context_user;
use stdClass;
use moodle_exception;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Static list of api methods for auth oauth2 configuration.
 *
 * @package    auth_oauth2
 * @copyright  2017 Damyon Wiese
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {

    /**
     * Remove all linked logins that are using issuers that have been deleted.
     *
     * @param int $issuerid The issuer id of the issuer to check, or false to check all (defaults to all)
     * @return boolean
     */
    public static function clean_orphaned_linked_logins($issuerid = false) {
        return linked_login::delete_orphaned($issuerid);
    }

    /**
     * List linked logins
     *
     * Requires auth/oauth2:managelinkedlogins capability at the user context.
     *
     * @param int $userid (defaults to $USER->id)
     * @return boolean
     */
    public static function get_linked_logins($userid = false) {
        global $USER;

        if ($userid === false) {
            $userid = $USER->id;
        }

        if (\core\session\manager::is_loggedinas()) {
            throw new moodle_exception('notwhileloggedinas', 'auth_oauth2');
        }

        $context = context_user::instance($userid);
        require_capability('auth/oauth2:managelinkedlogins', $context);

        return linked_login::get_records(['userid' => $userid, 'confirmtoken' => '']);
    }

    /**
     * See if there is a match for this username and issuer in the linked_login table.
     *
     * @param string $username as returned from an oauth client.
     * @param \core\oauth2\issuer $issuer
     * @return stdClass User record if found.
     */
    public static function match_username_to_user($username, $issuer) {
        $params = [
            'issuerid' => $issuer->get('id'),
            'username' => $username
        ];
        $result = linked_login::get_record($params);

        if ($result) {
            $user = \core_user::get_user($result->get('userid'));
            if (!empty($user) && !$user->deleted) {
                return $result;
            }
        }
        return false;
    }

    /**
     * Link a login to this account.
     *
     * Requires auth/oauth2:managelinkedlogins capability at the user context.
     *
     * @param array $userinfo as returned from an oauth client.
     * @param \core\oauth2\issuer $issuer
     * @param int $userid (defaults to $USER->id)
     * @param bool $skippermissions During signup we need to set this before the user is setup for capability checks.
     * @return bool
     */
    public static function link_login($userinfo, $issuer, $userid = false, $skippermissions = false) {
        global $USER;

        if ($userid === false) {
            $userid = $USER->id;
        }

        if (linked_login::has_existing_issuer_match($issuer, $userinfo['username'])) {
            throw new moodle_exception('alreadylinked', 'auth_oauth2');
        }

        if (\core\session\manager::is_loggedinas()) {
            throw new moodle_exception('notwhileloggedinas', 'auth_oauth2');
        }

        $context = context_user::instance($userid);
        if (!$skippermissions) {
            require_capability('auth/oauth2:managelinkedlogins', $context);
        }

        $record = new stdClass();
        $record->issuerid = $issuer->get('id');
        $record->username = $userinfo['username'];
        $record->userid = $userid;
        $existing = linked_login::get_record((array)$record);
        if ($existing) {
            $existing->set('confirmtoken', '');
            $existing->update();
            return $existing;
        }
        $record->email = $userinfo['email'];
        $record->confirmtoken = '';
        $record->confirmtokenexpires = 0;
        $linkedlogin = new linked_login(0, $record);
        return $linkedlogin->create();
    }

    /**
     * Send an email with a link to confirm linking this account.
     *
     * @param array $userinfo as returned from an oauth client.
     * @param \core\oauth2\issuer $issuer
     * @param int $userid (defaults to $USER->id)
     * @return bool
     */
    public static function send_confirm_link_login_email($userinfo, $issuer, $userid) {
        $record = new stdClass();
        $record->issuerid = $issuer->get('id');
        $record->username = $userinfo['username'];
        $record->userid = $userid;
        if (linked_login::has_existing_issuer_match($issuer, $userinfo['username'])) {
            throw new moodle_exception('alreadylinked', 'auth_oauth2');
        }
        $record->email = $userinfo['email'];
        $record->confirmtoken = random_string(32);
        $expires = new \DateTime('NOW');
        $expires->add(new \DateInterval('PT30M'));
        $record->confirmtokenexpires = $expires->getTimestamp();

        $linkedlogin = new linked_login(0, $record);
        $linkedlogin->create();

        // Construct the email.
        $site = get_site();
        $supportuser = \core_user::get_support_user();
        $user = get_complete_user_data('id', $userid);

        $data = new stdClass();
        $data->fullname = fullname($user);
        $data->sitename  = format_string($site->fullname);
        $data->admin     = generate_email_signoff();
        $data->issuername = format_string($issuer->get('name'));
        $data->linkedemail = format_string($linkedlogin->get('email'));

        $subject = get_string('confirmlinkedloginemailsubject', 'auth_oauth2', format_string($site->fullname));

        $params = [
            'token' => $linkedlogin->get('confirmtoken'),
            'userid' => $userid,
            'username' => $userinfo['username'],
            'issuerid' => $issuer->get('id'),
        ];
        $confirmationurl = new moodle_url('/auth/oauth2/confirm-linkedlogin.php', $params);

        $data->link = $confirmationurl->out(false);
        $message = get_string('confirmlinkedloginemail', 'auth_oauth2', $data);

        $data->link = $confirmationurl->out();
        $messagehtml = text_to_html(get_string('confirmlinkedloginemail', 'auth_oauth2', $data), false, false, true);

        $user->mailformat = 1;  // Always send HTML version as well.

        // Directly email rather than using the messaging system to ensure its not routed to a popup or jabber.
        return email_to_user($user, $supportuser, $subject, $message, $messagehtml);
    }

    /**
     * Look for a waiting confirmation token, and if we find a match - confirm it.
     *
     * @param int $userid
     * @param string $username
     * @param int $issuerid
     * @param string $token
     * @return boolean True if we linked.
     */
    public static function confirm_link_login($userid, $username, $issuerid, $token) {
        if (empty($token) || empty($userid) || empty($issuerid) || empty($username)) {
            return false;
        }
        $params = [
            'userid' => $userid,
            'username' => $username,
            'issuerid' => $issuerid,
            'confirmtoken' => $token,
        ];

        $login = linked_login::get_record($params);
        if (empty($login)) {
            return false;
        }
        $expires = $login->get('confirmtokenexpires');
        if (time() > $expires) {
            $login->delete();
            return;
        }
        $login->set('confirmtokenexpires', 0);
        $login->set('confirmtoken', '');
        $login->update();
        return true;
    }

    /**
     * Create an account with a linked login that is already confirmed.
     *
     * @param array $userinfo as returned from an oauth client.
     * @param \core\oauth2\issuer $issuer
     * @return bool
     */
    public static function create_new_confirmed_account($userinfo, $issuer) {
        global $CFG, $DB;
        require_once($CFG->dirroot.'/user/profile/lib.php');
        require_once($CFG->dirroot.'/user/lib.php');

        $user = new stdClass();
        $user->username = $userinfo['username'];
        $user->email = $userinfo['email'];
        $user->auth = 'oauth2';
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->lastname = isset($userinfo['lastname']) ? $userinfo['lastname'] : '';
        $user->firstname = isset($userinfo['firstname']) ? $userinfo['firstname'] : '';
        $user->url = isset($userinfo['url']) ? $userinfo['url'] : '';
        $user->alternatename = isset($userinfo['alternatename']) ? $userinfo['alternatename'] : '';
        $user->secret = random_string(15);

        $user->password = '';
        // This user is confirmed.
        $user->confirmed = 1;

        $user->id = user_create_user($user, false, true);

        // The linked account is pre-confirmed.
        $record = new stdClass();
        $record->issuerid = $issuer->get('id');
        $record->username = $userinfo['username'];
        $record->userid = $user->id;
        $record->email = $userinfo['email'];
        $record->confirmtoken = '';
        $record->confirmtokenexpires = 0;

        $linkedlogin = new linked_login(0, $record);
        $linkedlogin->create();

        return $user;
    }

    /**
     * Send an email with a link to confirm creating this account.
     *
     * @param array $userinfo as returned from an oauth client.
     * @param \core\oauth2\issuer $issuer
     * @param int $userid (defaults to $USER->id)
     * @return bool
     */
    public static function send_confirm_account_email($userinfo, $issuer) {
        global $CFG, $DB;
        require_once($CFG->dirroot.'/user/profile/lib.php');
        require_once($CFG->dirroot.'/user/lib.php');

        if (linked_login::has_existing_issuer_match($issuer, $userinfo['username'])) {
            throw new moodle_exception('alreadylinked', 'auth_oauth2');
        }

        $user = new stdClass();
        $user->username = $userinfo['username'];
        $user->email = $userinfo['email'];
        $user->auth = 'oauth2';
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->lastname = isset($userinfo['lastname']) ? $userinfo['lastname'] : '';
        $user->firstname = isset($userinfo['firstname']) ? $userinfo['firstname'] : '';
        $user->url = isset($userinfo['url']) ? $userinfo['url'] : '';
        $user->alternatename = isset($userinfo['alternatename']) ? $userinfo['alternatename'] : '';
        $user->secret = random_string(15);

        $user->password = '';
        // This user is not confirmed.
        $user->confirmed = 0;

        $user->id = user_create_user($user, false, true);

        // The linked account is pre-confirmed.
        $record = new stdClass();
        $record->issuerid = $issuer->get('id');
        $record->username = $userinfo['username'];
        $record->userid = $user->id;
        $record->email = $userinfo['email'];
        $record->confirmtoken = '';
        $record->confirmtokenexpires = 0;

        $linkedlogin = new linked_login(0, $record);
        $linkedlogin->create();

        // Construct the email.
        $site = get_site();
        $supportuser = \core_user::get_support_user();
        $user = get_complete_user_data('id', $user->id);

        $data = new stdClass();
        $data->fullname = fullname($user);
        $data->sitename  = format_string($site->fullname);
        $data->admin     = generate_email_signoff();

        $subject = get_string('confirmaccountemailsubject', 'auth_oauth2', format_string($site->fullname));

        $params = [
            'token' => $user->secret,
            'username' => $userinfo['username']
        ];
        $confirmationurl = new moodle_url('/auth/oauth2/confirm-account.php', $params);

        $data->link = $confirmationurl->out(false);
        $message = get_string('confirmaccountemail', 'auth_oauth2', $data);

        $data->link = $confirmationurl->out();
        $messagehtml = text_to_html(get_string('confirmaccountemail', 'auth_oauth2', $data), false, false, true);

        $user->mailformat = 1;  // Always send HTML version as well.

        // Directly email rather than using the messaging system to ensure its not routed to a popup or jabber.
        email_to_user($user, $supportuser, $subject, $message, $messagehtml);
        return $user;
    }

    /**
     * Delete linked login
     *
     * Requires auth/oauth2:managelinkedlogins capability at the user context.
     *
     * @param int $linkedloginid
     * @return boolean
     */
    public static function delete_linked_login($linkedloginid) {
        $login = new linked_login($linkedloginid);
        $userid = $login->get('userid');

        if (\core\session\manager::is_loggedinas()) {
            throw new moodle_exception('notwhileloggedinas', 'auth_oauth2');
        }

        $context = context_user::instance($userid);
        require_capability('auth/oauth2:managelinkedlogins', $context);

        $login->delete();
    }

    /**
     * Delete linked logins for a user.
     *
     * @param \core\event\user_deleted $event
     * @return boolean
     */
    public static function user_deleted(\core\event\user_deleted $event) {
        global $DB;

        $userid = $event->objectid;

        return $DB->delete_records(linked_login::TABLE, ['userid' => $userid]);
    }

    /**
     * Is the plugin enabled.
     *
     * @return bool
     */
    public static function is_enabled() {
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('auth_oauth2');
        return $plugininfo->is_enabled();
    }

    /**
     * OIDC function to verify the incoming JWT assertion from the OIDC Provider
     * return true when successful and false when verification failed
     * 
     * @param string $JWT (Json Web Token String)
     * @param int $issuerid
     * @return bool 
     */

    public static function verifyAssertion($JWT, $issuerid){
        if (!isset($issuerid)) {
            return false;
        }
        error_log('OIDC Dev :: Verifying assertion for issuer = ' . $issuerid);
        //validate ID Token
            
            //first get the jwks stored in the database to check
            $oidc_key = new oidc_idp_key();
            $keys = oidc_idp_key::get_records(['ap_id' => $issuerid]);
            error_log("OIDC Dev :: validating ... keys for issuer : " . count($keys) );

            //split the id token by '.'
            $jwt = explode('.' , $JWT);
            error_log("OIDC Dev :: validating ... jwt parts : " . count($jwt) );
            
            if (count($jwt) == 3) {
                //token in JWS format
                error_log("OIDC Dev:: jws header = " . base64_decode( $jwt[0]) );
                error_log("OIDC Dev:: jws payload = " . base64_decode( $jwt[1]) );
                $header = json_decode(base64_decode( $jwt[0]) );
                $payload = json_decode(base64_decode( $jwt[1]) );

                //check aud
                // $payload->
                error_log("OIDC Dev:: jws aud = " . $payload->aud);

                //check iss if the same as the registered one
                $issuer_obj = new \core\oauth2\issuer($issuerid);
                $issuer_obj->read();
                //error_log("OIDC Dev:: payload iss =" . $payload->iss );
                //error_log("OIDC Dev:: registered iss =" . $issuer_obj->get('baseurl') ); pay attention with the slash

                if( $payload->aud !== $issuer_obj->get('clientid') ){
                    error_log("OIDC Dev:: validate unsuccessful = invalid aud");
                    return false;
                } elseif ($payload->iss !== $issuer_obj->get('baseurl') ) {
                    error_log("OIDC Dev:: validate unsuccessful = invalid issuer") ;
                    return false;
                } elseif ( isset($payload->iat) && $payload->iat >= time() + 10 ) {
                    error_log("OIDC Dev:: validate unsuccessful = invalid iat :: iat =  " . $payload->iat . " :: time = " . time() );
                    return false;
                } elseif ( isset($payload->exp) && $payload->exp <= time() ) {
                    error_log("OIDC Dev:: validate unsuccessful = invalid exp ") ;
                    return false;
                } elseif ( isset($payload->nbf) && $payload->nbf > time() ){
                    error_log("OIDC Dev:: validate unsuccessful = invalid nbf ") ;
                    return false;
                } elseif (! isset($payload->sub)) {
                    error_log("OIDC Dev:: validate unsuccessful = no sub found ") ;
                    return false;
                } else {
                    //verify the signature with the keys using jose framework
                    foreach($keys as $key){
                        if($header->kid === $key->get('keyid')){

                            //convert the saved json format of jwk into a JWK object
                            $jwk_json = json_decode($key->get('jwk'), true );
                            $jwk = new JWK($jwk_json);

                            $loader = new Loader();
                            $input = $JWT;
                            try{
                                $jws = $loader->loadAndVerifySignatureUsingKey(
                                    $input,
                                    $jwk,
                                    ['RS256'],
                                    $signature_index
                                );
                                error_log("OIDC Dev:: Validate SUCCESSFUL : " . ($jws->getSignature($signature_index))->getSignature() ) ;
                                return true;
                            } catch (Throwable $e) {
                                error_log("OIDC Dev :: Validate unsuccessful - Verification failed");
                                return false;
                            }
                            
                        }
                    }
                    error_log("OIDC Dev:: validate unsuccessful = key is not found inside the database!");
                    return false;
                } 
            } 

    }

    /**
     * Checking the assertion from the add before moodle forward the assertion into the OIDC provider
     * 
     * @param string $assertion from the mobile app
     * @return bool
     */
    public static function proveAssertion($assertion){
        GLOBAL $DB;
        //check if the assertion is in a valid format JWS/JWE
        $assertionsArray = explode('.',$assertion);
        $counter = count($assertionsArray);
        error_log('OIDC Dev :: proveAssertion start = ' . $assertion);
        if(!($counter === 5 || $counter === 3)) {
            http_response_code(403);
            return false;
        }

        //check if the audience is right
        $jose_header = json_decode(base64_decode($assertionsArray[$counter === 5 ? 0 : 1]));
        error_log('OIDC Dev :: proveAssertion jose_header = '. json_encode($jose_header));
        if(empty($jose_header)){
            http_response_code(403);
            return false;
        }
        //check if audience registered as an Identity Provider already inside moodle
        $aud = $jose_header->aud;
        
        //$count = \core\oauth2\issuer::count_records([$DB->sql_compare_text('baseurl') => $aud]);
        $records = $DB->get_records_sql('SELECT * FROM moodledevjul.mdl_oauth2_issuer WHERE ' . $DB->sql_compare_text('baseurl') . ' = "' . $aud . '";');
        error_log('OIDC Dev :: proveAssertion jose aud claims = ' . $jose_header->aud);
        if(count($records) <= 0){
            http_response_code(403);
            return false;
        }
        error_log('OIDC Dev :: proveAssertion =  TRUE');
        return true;
    }

    /**
     * Handling the response from OIDC provider
     * 
     * @param object $response response package
     */
    public static function handleToken($response){
        error_log('OIDC Dev :: handleToken !!');
        if(!isset($response->access_token) || !isset($response->id_token) ) {
            return false;
        }  
        error_log('OIDC Dev :: handleToken before process Assertion');
        if(empty($userClaimsData = self::processAssertion($response->id_token)) ){
            throw new moodle_exception('empty user claims');
        }
        
        if(! $user = self::handleUserData($userClaimsData)) {
            throw new moodle_exception('empty user from HandleUser');
        }
        error_log('OIDC Dev :: after handleUserData, user = ' . json_encode($user));
        
        
        // Store token for revocation and other stuff
        //$token = self::pick_keys($response, ['access_token', "refresh_token"]);
        
        $expires = 3600;
        
        if ( isset($response->expires_in)) {
            $expires = $response->expires_in;
        }

        $now = time();
        $exp = $now + $expires;

        //$token['expires'] = $exp;

        //$target = $this->m
        

        //issue moodle token for the app
        $moodle_token = self::grantInternalToken($user->id, $exp);
        
        $cliToken = self::pick_keys($response, ['access_token', 'refresh_token', 'expires_in']);
        $cliToken = array_merge($cliToken, ["token_type" => "Bearer", "api_key" => $moodle_token]);
        error_log('OIDC Dev :: end of handleToken, cliToken = ' . json_encode($cliToken));
        
        
        header('Content-Type: application/json;charset=utf-8');
        echo json_encode($cliToken);

        return true;


    }


    private static function processAssertion($token){
        $loader = new Loader();
        $jwt = $loader->load($token);
        
        //TODO :: JWE Support
        //self::decryptJWE($token);
        
        //get standard claims
        $standardClaims = self::getStandardClaims();
        $userClaims = [];
        foreach($standardClaims as $claim){
            if($jwt->hasClaim($claim) && $cdata = $jwt->getClaim($claim)) {
                $userClaims[$claim] = $cdata;
            }
        }
        error_log('OIDC Dev :: end of processAssertion, claims = ' . json_encode($userClaims));
        return $userClaims;
       
    }
    /**
     * handle the user's info data from id token
     * for moodle's user (update account or create a new account)
     * 
     * @param associative array of claims
     * @return object $user
     */
    private static function handleUserData($userClaims){
        global $DB, $USER;

        //create or update the user
        $username = $userClaims['sub'];

        if ($user = $DB->get_record_sql('SELECT * FROM {user} where username = ?', [$username])) {
            //user existed, update user
            $user = self::handleAttributeMap($user, $userClaims);
            error_log('OIDC Dev :: after handleMap first condition');
            user_update_user($user, false, false);
            $user = $DB->get_record('user', array('id' => $user['id']));

        } else {
            //create new user
            $user = self::handleAttributeMap([], $userClaims);
            error_log('OIDC Dev :: after handleMap second condition');
            $user['id'] = user_create_user($user, false, false);

            if ($user['id'] > 0) {
                //Moodle wants additional profile setups
                $usercontext = context_user::instance($user['id']);

                //Update preferences
                useredit_update_user_preference($user);

                if (!empty($CFG->usetags)) {
                    useredit_update_interests($user, $user['interests']);
                }
                //Update mail bounces
                useredit_update_bounces($user, $user);

                //Update forum track preference
                useredit_update_trackforums($user, $user);

                //loggin for user variable
                error_log('OIDC Dev :: handleUser() => user is ' . implode(',', $user));

                //For profile_save_data() user needs to be an object, now it is still in array
                $userObject = json_decode(json_encode((object) $user), FALSE);

                //Save custom profile fields data
                profile_save_data($userObject);

                //Reload from DB
                $user = $DB->get_record('user', array('id' => $user['id']));

                //Allow Moodle components to respond to the new user
                \core\event\user_created::create_from_userid($user->id)->trigger();

            }
        }
        $USER = $user;
        return $user;

    }

    /**
     * Main function to the mapping process
     * between OIDC claims and moodle user's data
     */
    private static function handleAttributeMap($user, $claims){
        global $CFG;

        $user = (array) $user;
        $claims = (array) $claims;
        error_log('OIDC Dev :: handleAttrMap() $user = ' . json_encode($user));
        if (! array_key_exists('id', $user)){
            //new user
            $user['timecreated'] = $user['firstaccess'] = $user['lastaccess'] = time();
            $user['confirmed'] = 1;
            $user['policyagreed'] = 1;
            $user['suspended'] = 0;
            $user['mnethostid'] = $CFG->mnet_localhost_id;
            $user['interests'] = '';
            $user['password'] = AUTH_PASSWORD_NOT_CACHED;
        }

        $user['deleted'] = 0;
        $user['username'] = $claims['sub'];
        //get attribute map
        // moodle value => claim
        $map = self::getMapping();
        $didUpdate = false;

        foreach($map as $moodleKey => $claimKey){
            //iterate through the whole map

            $cs = $claims;
            //handling nested address claim (e.g address.city)
            if( strpos($claimKey, ".") !== false) {
                list($pkey, $claimkey) = explode('.', $claimKey);
                if(array_key_exists($pkey, $cs)) {
                    $cs = $cs[$pkey];
                }
            }

            if (!empty($cs) && !empty($claimKey) &&
                array_key_exists($claimKey, $cs) &&
                (!array_key_exists($moodleKey, $user) || $user[$moodleKey] != $cs[$claimKey])) {
                    error_log('OIDC Dev ::  in foreach $cs = ' . json_encode($cs));
                    $user[$moodleKey] = $cs[$claimKey];
                    $didUpdate = true;
                }
        }
        //automatic mark the updated time
        if($didUpdate) {
            $user['timemodified'] = time();
        }
        return $user;
    }

    private static function grantInternalToken($userid, $expires) {
        //Internal tokens from the OAuth perspective are tokens issued by the
        //service, whereas moodle cosiders tokens that are not used as
        //sessions as external
        global $DB;

        $service = $DB->get_record_sql("SELECT id from {external_services} where shortname = 'moodle_mobile_app'");

        // one problem here is that the token will not work with the service
       // endpoints.
       // this means that OAuth2 tokens need to be either scoped OR assigned
       // to all service endpoints.
       // scoping means that we need to know upfront, which services the
       // client requests.
       // in the case of multiple scopings, ONE token needs to be assignable to
       // several services. However, Moodle's external tokens don't support
       // this.
       // Eitherway, in moodle there is no way for doing this dynamically. This
       // means that one needs to assign ALL moodle service endpoints to the
       // OAuth "service". A cron job could take all active services and
       // assign all their endpoints to the OAuth2 service.
       // a saner way would be to allow OAuth2's token management to hook into
       // the external token handling and let OAuth2's scoping handle the job.

       if (empty($service)) {
           return null;
       } else {
           $token = external_generate_token(EXTERNAL_TOKEN_PERMANENT,
                                            $service,
                                            $userid,
                                            \context_system::instance(),
                                            $expires
                                        );
            return $token;
       }

    }

    /**
     *  Get standard supported claims from OIDC Provider for the mapping process
     * 
     *  @return array of string claims
     */
    private static function getStandardClaims() {

        return array(
            "sub", "email", "given_name", "family_name", "middle_name",
            "nickname", "preferred_username", "profile", "picture", "website",
            "email_verified", "gender", "birthdate", "zoneinfo", "locale",
            "phone_number", "phone_number_verified", "updated_at",
            "address.street_address", "address.city", "address.locality",
            "address.country", "address.postal_code", "address.region"
        );

    }

    private static function getMapping() {
        //Default mapping
        //moodle => id_token claims
        return array(
            "email" => "email",
            "firstname" => "given_name",
            "lastname" => "family_name",
            "idnumber" => "",
            "icq" => "",
            "skype" => "",
            "yahoo" => "",
            "aim" => "",
            "msn" => "",
            "phone1" => "phone_number",
            "phone2" => "",
            "institution" => "",
            "departement" => "",
            "address" => "address.street_address",
            "city" => "address.locality",
            "country" => "address.country",
            "lang" => "locale",
            "url" => "website",
            "middlename" => "middle_name",
            "firstnamephonetic" => "",
            "lastnamephonetic" => "",
            "alternatename" => "nickname"
        );
        
    }

    /**
     * reduces the provided array, so it contains only the provided keys.
     * 
     * If invalid parameters are provided, function returns an empty array
     * 
     * The result is an array containing only the provided keys. If a key is missing
     * or empty in the data, then it won't be present in the result set.
     * 
     * @param array|object $data - where the keys are picked from
     * @param string|array $keys - which keys to pick
     * @return array
     */
    private static function pick_keys($data, $keys) {
        $keys = self::str2array($keys);
        $data = self::obj2array($data);
        if (empty($data) || empty($keys)) {
            return [];
        }
        return array_filter($data, function($v, $k) use ($keys) {
            return (!empty($v) && in_array($k, $keys));
        }, ARRAY_FILTER_USE_BOTH);
    }

    private static function has_key($data, $key) {
        $data = obj2array($data);
        return (is_string($key) &&
                strlen($key) &&
                array_key_exists($key, $data) &&
                !empty($data[$key]));
    }

    private static function verify_keys($data, $keys, $errMessage="") {
        $keys = str2array($keys);
        $data = obj2array($data);
        if (empty($data) && empty($keys))
            return true;
        if (empty($data))
            return false;
        $res = pick_keys($data, $keys);
        $retval = (count($res) == count($keys));
        if (!$retval && strlen($errMessage)) {
            throw new Exception($errMessage);
        }
        return $retval;
    }

    private static function ensure_array($data) {
        if (is_array($data))
            return $data;
        return [];
    }

    private static function obj2array($data) {
        if (is_object($data))
            $data = (array) $data;
        return self::ensure_array($data);
    }

    private static function str2Array($data) {
        if (is_string($data) && strlen($data))
            $data = [$data];
        return self::ensure_array($data);
    }

}
