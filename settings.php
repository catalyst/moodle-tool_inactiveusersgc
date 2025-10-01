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
 * Admin settings for the inactive users tool.
 *
 * Defines the plugin’s admin settings page and controls.
 *
 * @package    tool_inactiveusersgc
 * @category   admin
 * @copyright  2025 Waleed ul Hassan
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'tool_inactiveusersgc',
        get_string('settings:heading', 'tool_inactiveusersgc')
    );

    global $DB;
    $fieldexists = $DB->record_exists('user_info_field', ['shortname' => 'primary_membership_code']);
    if (!$fieldexists) {
        $manageurl = new moodle_url('/user/profile/index.php');
        $warnhtml = html_writer::div(
            get_string('settings:noprofilefield', 'tool_inactiveusersgc', (object)[
                'url' => $manageurl->out(false),
            ]),
            'alert alert-warning'
        );

        $settings->add(new admin_setting_heading(
            'tool_inactiveusersgc_missingfield',
            get_string('settings:tenantfilterheading', 'tool_inactiveusersgc'),
            $warnhtml
        ));
    }

    // Method (suspend/delete).
    $settings->add(new admin_setting_configselect(
        'tool_inactiveusersgc/method',
        get_string('settings:method', 'tool_inactiveusersgc'),
        get_string('settings:method_desc', 'tool_inactiveusersgc'),
        0,
        [
            0 => get_string('settings:method:suspend', 'tool_inactiveusersgc'),
            1 => get_string('settings:method:delete', 'tool_inactiveusersgc'),
        ]
    ));

    // Action days.
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/actiondays',
        get_string('settings:actiondays', 'tool_inactiveusersgc'),
        get_string('settings:actiondays_desc', 'tool_inactiveusersgc'),
        365,
        PARAM_INT
    ));

    // First warning.
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/firstdays',
        get_string('settings:firstdays', 'tool_inactiveusersgc'),
        '',
        60,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/firstrepeat',
        get_string('settings:firstrepeat', 'tool_inactiveusersgc'),
        '',
        30,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/firstsubject',
        get_string('settings:firstsubject', 'tool_inactiveusersgc'),
        '',
        get_string('email:first:subject:default', 'tool_inactiveusersgc'),
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtextarea(
        'tool_inactiveusersgc/firstmsg',
        get_string('settings:firstmsg', 'tool_inactiveusersgc'),
        '',
        get_string('email:first:body:default', 'tool_inactiveusersgc'),
        PARAM_RAW
    ));

    // Second warning.
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/seconddays',
        get_string('settings:seconddays', 'tool_inactiveusersgc'),
        '',
        300,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/secondrepeat',
        get_string('settings:secondrepeat', 'tool_inactiveusersgc'),
        '',
        30,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/secondsubject',
        get_string('settings:secondsubject', 'tool_inactiveusersgc'),
        '',
        get_string('email:second:subject:default', 'tool_inactiveusersgc'),
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtextarea(
        'tool_inactiveusersgc/secondmsg',
        get_string('settings:secondmsg', 'tool_inactiveusersgc'),
        '',
        get_string('email:second:body:default', 'tool_inactiveusersgc'),
        PARAM_RAW
    ));

    // Final warning.
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/finaldays',
        get_string('settings:finaldays', 'tool_inactiveusersgc'),
        '',
        364,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/finalsubject',
        get_string('settings:finalsubject', 'tool_inactiveusersgc'),
        '',
        get_string('email:final:subject:default', 'tool_inactiveusersgc'),
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtextarea(
        'tool_inactiveusersgc/finalmsg',
        get_string('settings:finalmsg', 'tool_inactiveusersgc'),
        '',
        get_string('email:final:body:default', 'tool_inactiveusersgc'),
        PARAM_RAW
    ));

    // Support email & tenant filter codes.
    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/supportemail',
        get_string('settings:supportemail', 'tool_inactiveusersgc'),
        get_string('settings:supportemail_desc', 'tool_inactiveusersgc'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'tool_inactiveusersgc/tenantcodes',
        get_string('settings:tenantcodes', 'tool_inactiveusersgc'),
        get_string('settings:tenantcodes_desc', 'tool_inactiveusersgc'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $ADMIN->add('tools', $settings);
}
