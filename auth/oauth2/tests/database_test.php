<?php


class auth_oauth2_database_testcase extends advanced_testcase{


    public function test_access_database() {
        global $DB;
        //$this->resetAfterTest(true);
        $record = new stdClass();
        $record->keyid = 'abcdef';
        $record->apid = 'thisisapid';
        $record->jwk = 'jwkwkwkwkwkwkwkw';
        $inserted_id = $DB->insert_record('auth_oauth2_idp_key', $record, false);


        //For log function
        $tmp = 'test_access_database() LOG: inserted_id is ';
        fwrite(STDERR, print_r($tmp . print_r($inserted_id,TRUE) , TRUE));
    }
    
}