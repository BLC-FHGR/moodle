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
defined('MOODLE_INTERNAL') || exit();

/**
 * Unit tests for the subscription class.
<<<<<<< HEAD
 * @since 3.1.1
=======
 * @since 3.2.0
>>>>>>> 9e7c3978895c7cab585c2f5234ca536151d3bef6
 *
 * @package    tool_monitor
 * @category   test
 * @copyright  2016 Jake Dallimore <jrhdallimore@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_monitor_subscription_testcase extends advanced_testcase {

    /**
     * @var \tool_monitor\subscription $subscription object.
     */
    private $subscription;

    /**
     * Test set up.
     */
    public function setUp() {
        $this->resetAfterTest(true);

        // Create the mock subscription.
        $sub = new stdClass();
        $sub->id = 100;
        $sub->name = 'My test rule';
        $sub->courseid = 20;
<<<<<<< HEAD
        $this->subscription = $this->getMock('\tool_monitor\subscription',null, array($sub));
=======
        $mockbuilder = $this->getMockBuilder('\tool_monitor\subscription');
        $mockbuilder->setMethods(null);
        $mockbuilder->setConstructorArgs(array($sub));
        $this->subscription = $mockbuilder->getMock();
>>>>>>> 9e7c3978895c7cab585c2f5234ca536151d3bef6
    }

    /**
     * Test for the magic __isset method.
     */
    public function test_magic_isset() {
        $this->assertEquals(true, isset($this->subscription->name));
        $this->assertEquals(true, isset($this->subscription->courseid));
        $this->assertEquals(false, isset($this->subscription->ruleid));
    }

    /**
     * Test for the magic __get method.
<<<<<<< HEAD
     */
    public function test_magic_get() {
        $this->assertEquals(20, $this->subscription->courseid);
        $this->setExpectedException('coding_exception');
=======
     *
     * @expectedException coding_exception
     */
    public function test_magic_get() {
        $this->assertEquals(20, $this->subscription->courseid);
>>>>>>> 9e7c3978895c7cab585c2f5234ca536151d3bef6
        $this->subscription->ruleid;
    }
}
