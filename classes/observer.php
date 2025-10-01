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
 * Event observer class for the inactive users tool.
 *
 * Provides handlers for Moodle events to keep the inactivity tracking table
 * in sync. For example, when a user logs in, their inactivity record is
 * cleared so that future warnings and actions are recalculated from the
 * new login date.
 *
 * @package    tool_inactiveusersgc
 * @category   event
 * @copyright  2025 Waleed ul Hassan <waleed.hassan@catalyst-eu.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_inactiveusersgc;

use core\event\base;
use core\event\user_loggedin;
use dml_exception;

/**
 * Event observers for the inactive users tool.
 *
 * Handles Moodle events such as user login to reset inactivity tracking.
 *
 * @package    tool_inactiveusersgc
 * @category   event
 */
class observer {
    /**
     * Clear tracking when a user logs in.
     *
     * Accept base so it works both from Moodle's dispatcher and in direct calls from tests.
     *
     * @param base $event
     * @return void
     * @throws dml_exception
     */
    public static function user_loggedin(base $event): void {
        global $DB;

        // Only handle the specific event.
        if (!($event instanceof user_loggedin)) {
            return;
        }

        // Resolve userid robustly: relateduserid → userid → objectid.
        $userid = $event->relateduserid ?: ($event->userid ?: $event->objectid);

        if (!empty($userid)) {
            $DB->delete_records('tool_inactiveusersgc', ['userid' => $userid]);
        }
    }
}
