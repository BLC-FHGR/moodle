<?php

namespace auth_oath2;

defined('MOODLE_INTERNAL') || die();

use core\persistent;

//OIDC data model which contains the data of jwk public key from the Identity Provider / AP
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