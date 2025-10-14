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
 * Scheduled task definitions for the inactive users tool.
 *
 * Declares the plugin's scheduled tasks so Moodle's task runner can
 * execute them at the configured times.
 *
 * @package    tool_inactiveusersgc
 * @category   task
 * @copyright  2025 Waleed ul Hassan <waleed.hassan@catalyst-eu.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\tool_inactiveusersgc\task\process_users',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '1',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
