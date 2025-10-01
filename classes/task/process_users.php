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

namespace tool_inactiveusersgc\task;

use core\task\scheduled_task;
use tool_inactiveusersgc\local\processor;
/**
 * Scheduled task for processing inactive users.
 *
 * This task is responsible for running the inactive users processor on a schedule.
 * It checks user inactivity against configured thresholds, sends warnings,
 * applies suspensions or deletions when action thresholds are reached, and
 * delivers a summary email report.
 *
 * @package    tool_inactiveusersgc
 * @category   task
 * @copyright  2025 Waleed ul Hassan <waleed.hassan@catalyst-eu.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_users extends scheduled_task {
    /**
     * Returns the human-readable name of the scheduled task.
     *
     * @return string Localised task name
     * @throws \coding_exception
     */
    public function get_name(): string {
        return get_string('task:process', 'tool_inactiveusersgc');
    }
    /**
     * Executes the inactive users processor workflow.
     *
     * Instantiates the processor and runs the full logic:
     * - Detect inactive users.
     * - Send staged notifications.
     * - Action users when thresholds are met.
     * - Generate and send a summary email.
     *
     * @return void
     */
    public function execute(): void {
        $proc = new processor();
        $proc->run();
    }
}
