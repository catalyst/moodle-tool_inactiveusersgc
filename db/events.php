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
 * Event observers definition for the inactive users tool.
 *
 * Lists the Moodle events that this plugin observes and maps them to
 * callback methods, e.g. clearing inactivity tracking when a user logs in.
 *
 * @package    tool_inactiveusersgc
 * @category   event
 * @copyright  2025 Waleed ul Hassan <waleed.hassan@catalyst-eu.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\core\event\user_loggedin',
        'callback'    => '\tool_inactiveusersgc\observer::user_loggedin',
        'includefile' => '/admin/tool/inactiveusersgc/classes/observer.php',
        'internal'    => false,
        'priority'    => 999,
    ],
];
