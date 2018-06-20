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
 * MOODLE VERSION INFORMATION
 *
 * This file defines the current version of the core Moodle code being used.
 * This is compared against the values stored in the database to determine
 * whether upgrades should be performed (see lib/db/*.php)
 *
 * @package    core
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

<<<<<<< HEAD
$version  = 2016052301.04;              // 20160523      = branching date YYYYMMDD - do not modify!
                                        //         RR    = release increments - 00 in DEV branches.
                                        //           .XX = incremental changes.

$release  = '3.1.1+ (Build: 20160804)'; // Human-friendly version name
=======
$version  = 2018061400.00;              // YYYYMMDD      = weekly release date of this DEV branch.
                                        //         RR    = release increments - 00 in DEV branches.
                                        //           .XX = incremental changes.

$release  = '3.6dev (Build: 20180614)'; // Human-friendly version name
>>>>>>> 9e7c3978895c7cab585c2f5234ca536151d3bef6

$branch   = '36';                       // This version's branch.
$maturity = MATURITY_ALPHA;             // This version's maturity level.
