<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * PHPUnit tests for the inactive users processor plugin.
 *
 * @package    tool_inactiveusersgc
 * @copyright  2025 onwards Catalyst IT {@link http://www.catalyst-eu.net/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Waleed ul hassan <waleed.hassan@catalyst-eu.net>
 */
namespace tool_inactiveusersgc;
use tool_inactiveusersgc\local\processor;

/**
 * Test cases for the inactive users processor.
 *
 * This class verifies that the scheduled task, notifications, and user
 * actions (warnings, suspensions/deletions) work as expected under different
 * inactivity scenarios.
 *
 * @coversDefaultClass \tool_inactiveusersgc\local\processor
 * @package           tool_inactiveusersgc
 * @group             tool_inactiveusersgc
 */
final class processor_test extends advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Verify that the processor correctly determines the inactivity stage.
     *
     * This test uses Reflection to call the protected method {@see processor::determine_stage()}.
     * A user who has been inactive for 70 days is expected to fall into the "first warning"
     * stage, given the configured thresholds (first=60, second=300, final=364, action=365).
     *
     * @covers ::determine_stage
     * @throws ReflectionException If the reflection on the method fails.
     * @return void
     */
    public function test_inactivity_calculation_and_stage(): void {
        set_config('firstdays', 60, 'tool_inactiveusersgc');
        set_config('seconddays', 300, 'tool_inactiveusersgc');
        set_config('finaldays', 364, 'tool_inactiveusersgc');
        set_config('actiondays', 365, 'tool_inactiveusersgc');

        $proc = new processor();
        $method = new ReflectionMethod(processor::class, 'determine_stage');

        $stage = $method->invoke($proc, 70, (object)[
            'firstdays' => 60, 'seconddays' => 300, 'finaldays' => 364, 'actiondays' => 365,
        ]);
        $this->assertEquals(1, $stage);
    }
}
