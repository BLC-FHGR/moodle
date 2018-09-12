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
 * Class for loading/storing issuers from the DB.
 *
 * @package    auth_oauth2
 * @copyright  2018 Julius Saputra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace auth_oauth2;

defined('MOODLE_INTERNAL') || die();

use core\persistent;

/**
 * Class for loading/storing issuers from the DB.
 *
 * @package    auth_oauth2
 * @copyright  2018 Julius Saputra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oidc_idp_key extends persistent {
    /**
     * Name for the database table.
     */
    const TABLE = 'auth_oauth2_idp_key';

    /**
     * Define the Table column with the specific value type.
     */
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