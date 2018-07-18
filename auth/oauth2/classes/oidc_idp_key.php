<?php

namespace auth_oauth2;

defined('MOODLE_INTERNAL') || die();
//require_once('../../lib/classes/persistent.php');
//require_once $CFG->dirroot.'/lib/classes/persistent.php';

use core\persistent;

class oidc_idp_key extends persistent {

    const TABLE = 'auth_oauth2_idp_key';

    protected static function define_properties() {
        return array(
            
            'keyid' => array(
                'type' => PARAM_RAW
            ),
            'ap_id' => array(
                'type' => PARAM_RAW
            ),
            'jwk' => array(
                'type' => PARAM_TEXT
            )
        );
    }


    


}