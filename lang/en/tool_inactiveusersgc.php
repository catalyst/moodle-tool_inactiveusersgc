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
 * Language strings for the inactive users tool.
 *
 * Defines all translatable strings used by tool_inactiveusersgc,
 * including settings labels, email templates, and task/summary text.
 *
 * @package    tool_inactiveusersgc
 * @category   string
 * @copyright  2025 Waleed ul Hassan
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['cap:manage'] = 'Manage inactive users tool settings';
$string['email:final:body:default'] = 'Hi {$a->firstname},\n\nThis is your final notice. If you don’t sign in by {$a->actiondate}, your account will be {$a->method}.\n\nThanks,\n{$a->supportname}';
$string['email:final:subject:default'] = 'Final notice: Account action on {$a->actiondate}';
$string['email:first:body:default'] = 'Hi {$a->firstname},\n\nIt looks like you haven’t signed in to {$a->sitename} for a while.\nIf you still need your account, please sign in within the next few weeks.\n\nThanks,\n{$a->supportname}';
$string['email:first:subject:default'] = 'We miss you at {$a->sitename} – quick reminder';
$string['email:second:body:default'] = 'Hi {$a->firstname},\n\nThis is a reminder that your {$a->sitename} account has been inactive.\nPlease sign in to keep it active.\n\nThanks,\n{$a->supportname}';
$string['email:second:subject:default'] = 'Important: Your {$a->sitename} account is becoming inactive';
$string['pluginname'] = 'Inactive users manager';
$string['privacy:metadata'] = 'This tool stores per-user notification stage and timestamps to manage inactivity warnings and actions.';
$string['settings:actiondays'] = 'Days until action';
$string['settings:actiondays_desc'] = 'Number of inactivity days after which the configured action is taken.';
$string['settings:finaldays'] = 'Days until last-chance email';
$string['settings:finalmsg'] = 'Last-chance email message (plain text; subject below)';
$string['settings:finalsubject'] = 'Last-chance email subject';
$string['settings:firstdays'] = 'Days until first warning';
$string['settings:firstmsg'] = 'First warning email message (plain text; subject below)';
$string['settings:firstrepeat'] = 'Repeat first warning every (days)';
$string['settings:firstsubject'] = 'First warning email subject';
$string['settings:heading'] = 'Inactive users manager';
$string['settings:method'] = 'Action method';
$string['settings:method:delete'] = 'Delete';
$string['settings:method:suspend'] = 'Suspend';
$string['settings:method_desc'] = 'Choose what action to take when a user exceeds the inactivity action days.';
$string['settings:seconddays'] = 'Days until second warning';
$string['settings:secondmsg'] = 'Second warning email message (plain text; subject below)';
$string['settings:secondrepeat'] = 'Repeat second warning every (days)';
$string['settings:secondsubject'] = 'Second warning email subject';
$string['settings:supportemail'] = 'Support email address';
$string['settings:supportemail_desc'] = 'Address to receive a summary after each task run. Defaults to the site support email if left empty.';
$string['settings:tenantcodes'] = 'Allowed primary membership codes (comma-separated)';
$string['settings:tenantcodes_desc'] =
    'Only process users who belong to a tenant whose CPD Primary Membership Code ' .
    '(configured on the CPD tenant settings page) matches one of these values. ' .
    'Enter a comma-separated list, e.g. "ABC,Afund,Club110". Leave blank to include all tenants.';
$string['summary:body'] = 'Execution time: {$a->when}\nFound: {$a->countfound}\nNotified: {$a->countnotified}\nActioned: {$a->countactioned}\nErrors: {$a->counterrors}';
$string['summary:subject'] = 'Inactive users manager – task summary';
$string['task:process'] = 'Process inactive users (notify and action)';
$string['settings:tenantfilterheading'] = 'Tenant membership filter';
