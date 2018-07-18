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
 * Open ID authentication. This file is a simple login entry point for OAuth identity providers.
 *
 * @package auth_oauth2
 * @copyright 2017 Damyon Wiese
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

require_once('../../config.php');
require_once(__DIR__ . '/classes/oidc_idp_key.php'); //OIDC :: issuer - jwk data model
require_once('../../lib/filelib.php'); //OIDC :: curl wrapper class
//require_once( __DIR__ . "/vendor/autoload.php"); //OIDC jose classes

use auth_oauth2\oidc_idp_key; //data

//global $DB; //oidc test

$issuerid = required_param('id', PARAM_INT);
$wantsurl = new moodle_url(optional_param('wantsurl', NULL, PARAM_URL));
$oauth2code = optional_param('oauth2code', NULL, PARAM_TEXT);
//$passthru = optional_param()
require_sesskey();

if (!\auth_oauth2\api::is_enabled()) {
    throw new \moodle_exception('notenabled', 'auth_oauth2');
}

error_log('OIDC Dev:: inserting db : count records = ' . ($jwk[0])->get('id') ) ;
*/

$issuer = new \core\oauth2\issuer($issuerid);
error_log('OIDC Dev:: LOGIN PHP : issuer name : ' . $issuer->get('name') . ' || wantsurl =  '.$wantsurl);
foreach($_GET as $key => $value){
    //error_log("OIDC Dev :: param =" . $key . " : " . $value . "<br />\r\n");
  }
//error_log('OIDC Dev:: param : ' . print_r($_POST));
//OIDC Service
if( strpos($issuer->get('scopessupported'), 'openid') !== false ) {
    if(!isset($oauth2code)){
        //First ertry, after login button pressed

        //Check if there any key existed in DB(oidc_manager) for this Identity Provider (IdP)
        $endpoint = new \core\oauth2\endpoint();
        $discovery_ep = $endpoint::get_record(['issuerid' => $issuerid, 'name' => 'discovery_endpoint' ]);
        $discovery_url = $discovery_ep->get('url');
        error_log("OIDC Dev :: dicovery points = " . $discovery_url);

        //send request to the discovery endpoint
        $curl_client = new curl();
        $json_response = $curl_client->get($discovery_url);
        $response = json_decode($json_response);

        error_log("OIDC Dev :: dicovery response = " . $json_response );

        //send request to get the jwk from jwk endpoint
        $json_response = $curl_client->get($response->jwks_uri);
        $response = json_decode($json_response);
        $keys = $response->keys;
        //error_log("OIDC Dev :: keys = " . count($keys));

        //checking every key if it's existed in the database already or not yet
        foreach ($keys as $key) {
            if($key->use === "sig"){
                //first check if the key with same kid already exists
                $persistent = new oidc_idp_key();
                $count = $persistent::count_records(['keyid' => $key->kid , 'ap_id' => $issuerid]);

                //error_log("OIDC Dev:: count_records = " . $count);
                if ($count > 0){
                    //error_log("OIDC Dev :: key is already existed, id = " . $key->kid );
                    continue; //skip if the key already in the database
                }

                //save the key in the database, since it's new
                $data = new stdClass();
                $data->keyid = $key->kid;
                $data->ap_id = $issuerid;
                $data->jwk = json_encode($key);

                $persistent = new oidc_idp_key(0, $data);
                $created = $persistent->create();
                //error_log("OIDC Dev :: record id = " . $created->get('id') );
            }
        }
    }
}

$returnparams = ['wantsurl' => $wantsurl, 'sesskey' => sesskey(), 'id' => $issuerid];
$returnurl = new moodle_url('/auth/oauth2/login.php', $returnparams);

$client = \core\oauth2\api::get_user_oauth_client($issuer, $returnurl);
error_log('OIDC Dev:: login.php : client = ' . isset($client));
if ($client) {
    if (!$client->is_logged_in()) {
        error_log('OIDC Dev:: login.php : before redirect to login url');
        redirect($client->get_login_url());
    }
    error_log("OIDC Dev :: after is_logged_in()");
    $auth = new \auth_oauth2\auth();
    $auth->complete_login($client, $wantsurl);
} else {
    throw new moodle_exception('Could not get an OAuth client.');
}
