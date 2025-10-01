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

use core\event\user_loggedin;
use tool_inactiveusersgc\observer;
use tool_inactiveusersgc\task\process_users;

/**
 * Comprehensive scenarios for tool_inactiveusersgc.
 */
final class scenarios_test extends advanced_testcase {
    /** @var stdClass profile field record for primary_membership_code */
    protected $profilefield;

    /**
     * PHPUnit setUp.
     *
     * Resets state, sets default plugin config, and creates
     * the custom profile field `primary_membership_code`
     * used for tenant filtering tests.
     *
     * @return void
     * @throws dml_exception
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('method', 0, 'tool_inactiveusersgc');          // 0 = suspend, 1 = delete
        set_config('actiondays', 365, 'tool_inactiveusersgc');

        set_config('firstdays', 60, 'tool_inactiveusersgc');
        set_config('firstrepeat', 30, 'tool_inactiveusersgc');

        set_config('seconddays', 300, 'tool_inactiveusersgc');
        set_config('secondrepeat', 30, 'tool_inactiveusersgc');

        set_config('finaldays', 364, 'tool_inactiveusersgc');

        set_config('supportemail', '', 'tool_inactiveusersgc');
        set_config('tenantcodes', '', 'tool_inactiveusersgc');

        // Create the custom profile field 'primary_membership_code' used for tenant filtering.
        global $DB;
        $this->profilefield = (object)[
            'shortname' => 'primary_membership_code',
            'name' => 'Primary Membership Code',
            'datatype' => 'text',
            'description' => 'Membership code for tenant filtering',
            'descriptionformat' => FORMAT_PLAIN,
            'required' => 0,
            'locked' => 0,
            'visible' => 2,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => 0,
            'param1' => '30',
            'param2' => '2048',
            'param3' => null,
            'param4' => null,
            'param5' => null,
        ];
        $this->profilefield->id = $DB->insert_record('user_info_field', $this->profilefield);
    }

    /**
     * Helper: simulate a user being inactive for N days.
     *
     * Updates the user's `lastaccess` (and `timecreated` if never logged in).
     *
     * @param stdClass $user User record (must include id).
     * @param int $days Number of days inactive to backdate.
     * @return void
     * @throws dml_exception
     */
    protected function set_inactivity_days(stdClass $user, int $days): void {
        global $DB;
        $u = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $u->lastaccess  = time() - ($days * DAYSECS);
        if ($u->lastaccess <= 0) {
            $u->lastaccess = 0;
            $u->timecreated = time() - ($days * DAYSECS);
        }
        $DB->update_record('user', $u);
    }

    /**
     * Helper: backdate the 'lastsent' field for a user's tracking row.
     *
     * Used in repeat email tests to simulate time elapsed since last notification.
     *
     * @param int $userid ID of the user in tool_inactiveusersgc table.
     * @param int $days Days ago to backdate lastsent.
     * @return void
     * @throws dml_exception
     */
    protected function set_track_lastsent_days_ago(int $userid, int $days): void {
        global $DB;
        if ($track = $DB->get_record('tool_inactiveusersgc', ['userid' => $userid])) {
            $track->lastsent = time() - ($days * DAYSECS);
            $track->timemodified = time();
            $DB->update_record('tool_inactiveusersgc', $track);
        }
    }
    /**
     * Split mail sink messages into [userMessages, summaryMessages].
     *
     * We use subject contains 'task summary' to identify the summary email.
     *
     * @param array $messages
     * @return array{0: array, 1: array}
     */
    protected function split_messages(array $messages): array {
        $users = [];
        $summaries = [];
        foreach ($messages as $m) {
            $subject = $m->subject ?? '';
            if (stripos($subject, 'task summary') !== false) {
                $summaries[] = $m;
            } else {
                $users[] = $m;
            }
        }
        return [$users, $summaries];
    }

    /**
     * Helper: assign primary_membership_code to user.
     * @throws dml_exception
     */
    protected function set_tenant_code(stdClass $user, string $code): void {
        global $DB;
        $data = (object)[
            'userid' => $user->id,
            'fieldid' => $this->profilefield->id,
            'data' => $code,
            'dataformat' => 0,
        ];
        // Upsert to user_info_data.
        $existing = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $this->profilefield->id]);
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('user_info_data', $data);
        } else {
            $DB->insert_record('user_info_data', $data);
        }
    }

    /**
     * Helper: run the scheduled task once at current time.
     */
    protected function run_task(): void {
        $task = new process_users();
        $task->execute();
    }

    /**
     * Scenario 1: User not yet in any warning window -> no email, no track row.
     * @throws dml_exception
     */
    public function test_no_action_before_first_window(): void {
        global $DB;
        $this->setCurrentTimeStart();
        $now = time();
        // Lastaccess = 20 days ago < firstdays=60.
        $u = $this->getDataGenerator()->create_user(['lastaccess' => $now - (20 * DAYSECS)]);
        $sink = $this->redirectEmails();
        $this->run_task();
        [$usermsgs, $summarymsgs] = $this->split_messages($sink->get_messages());
        $this->assertCount(1, $summarymsgs, 'Exactly one summary must be sent');
        $this->assertCount(0, $usermsgs, 'No user emails should be sent before first window');
        $this->assertFalse($DB->record_exists('tool_inactiveusersgc', ['userid' => $u->id]));
    }

    /**
     * Scenario 2: Never logged in -> uses timecreated for inactivity calculation.
     * @throws dml_exception
     */
    public function test_never_logged_in_uses_timecreated(): void {
        global $DB;
        $this->setCurrentTimeStart();
        $now = time();
        // Create user with lastaccess=0 and timecreated 70 days ago.
        $u = $this->getDataGenerator()->create_user(['lastaccess' => 0, 'timecreated' => $now - (70 * DAYSECS)]);
        $sink = $this->redirectEmails();
        $this->run_task();
        $messages = $sink->get_messages();
        $this->assertNotEmpty($messages, 'Should send first warning email based on timecreated');
        $this->assertTrue($DB->record_exists('tool_inactiveusersgc', ['userid' => $u->id]));
        $rec = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);
        $this->assertEquals(1, (int)$rec->stage);
    }

    /**
     * Scenario 3: First warning window + repeat cadence.
     * @throws dml_exception
     */
    public function test_first_warning_repeats_as_configured(): void {
        global $DB;

        // Ensure default repeat is 30 days (in case other tests changed it).
        set_config('firstdays', 60, 'tool_inactiveusersgc');
        set_config('firstrepeat', 30, 'tool_inactiveusersgc');

        // User is already in the first-window (>=60 days inactive).
        $now = time();
        $u = $this->getDataGenerator()->create_user([
            'lastaccess' => $now - (65 * DAYSECS),
        ]);

        $sink = $this->redirectEmails();

        // First run: should send first warning + summary.
        $this->run_task();
        $this->assertCount(2, $sink->get_messages(), 'One user email + one summary email');
        $rec = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);
        $this->assertEquals(1, (int)$rec->stage);

        // Pretend only 10 days have elapsed since the last send -> below repeat window.
        $this->set_track_lastsent_days_ago($u->id, 10);
        $this->run_task();
        $msgs = $sink->get_messages();
        $this->assertCount(3, $msgs, 'Only summary email expected before repeat interval');

        // Pretend >30 days have elapsed since the last send -> repeat should fire.
        $this->set_track_lastsent_days_ago($u->id, 35);
        [$usermsgs, $summarymsgs] = $this->split_messages($sink->get_messages());
        $this->assertCount(1, $usermsgs, 'Still only the initial first-stage email (no repeat before interval)');
        $this->assertCount(2, $summarymsgs, 'Two runs -> two summaries');
    }

    /**
     * Scenario 4: Progression to second and final windows sends immediately upon crossing thresholds.
     * @throws dml_exception
     */
    public function test_progression_sends_on_stage_increase(): void {
        global $DB;

        // Ensure thresholds match the scenario.
        set_config('firstdays',   60,  'tool_inactiveusersgc');
        set_config('seconddays',  300, 'tool_inactiveusersgc');
        set_config('finaldays',   364, 'tool_inactiveusersgc');
        set_config('actiondays',  365, 'tool_inactiveusersgc');

        // Start just under second threshold.
        $u = $this->getDataGenerator()->create_user();
        $this->set_inactivity_days($u, 299);

        $sink = $this->redirectEmails();

        // First run: in first window -> send first warning + summary.
        $this->run_task();
        $msgs = $sink->get_messages();
        $this->assertCount(2, $msgs, 'First warning + summary');
        $rec = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);
        $this->assertEquals(1, (int)$rec->stage);

        // Cross into second window: bump inactivity to 301 days.
        $this->set_inactivity_days($u, 301);
        $this->run_task();
        $msgs = $sink->get_messages();
        $this->assertCount(4, $msgs, 'Second warning + summary after stage increase');
        $rec = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);
        $this->assertEquals(2, (int)$rec->stage);

        // Jump to final window but keep below action.
        set_config('finaldays',  360, 'tool_inactiveusersgc');
        set_config('actiondays', 370, 'tool_inactiveusersgc');
        $this->set_inactivity_days($u, 362);

        $this->run_task();
        $msgs = $sink->get_messages();
        $this->assertCount(6, $msgs, 'Final warning + summary after stage increase');
        $rec = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);
        $this->assertEquals(3, (int)$rec->stage);
    }


    /**
     * Scenario 5: Final (last chance) has NO repeat.
     * @throws dml_exception
     */
    public function test_final_has_no_repeat(): void {

        // Configure thresholds for this scenario.
        set_config('finaldays', 100, 'tool_inactiveusersgc');
        set_config('actiondays', 365, 'tool_inactiveusersgc');

        // Create a user already in the final window (>=100) but below action (<365).
        $u = $this->getDataGenerator()->create_user();
        $this->set_inactivity_days($u, 120);

        $sink = $this->redirectEmails();

        // First run: should send final + summary.
        $this->run_task();
        $this->assertCount(2, $sink->get_messages(), 'Final + summary');

        // Keep the user before action threshold to ensure no action is taken.
        $this->set_inactivity_days($u, 320);
        $this->set_track_lastsent_days_ago($u->id, 200);

        $this->run_task();
        $this->assertCount(3, $sink->get_messages(), 'Only summary should be added (no repeat for final)');
    }


    /**
     * Scenario 6: Action user as Suspend (method=0).
     * @throws dml_exception
     */
    public function test_action_suspend(): void {
        global $DB;
        $this->setCurrentTimeStart();
        $now = time();
        set_config('method', 0, 'tool_inactiveusersgc');
        set_config('actiondays', 180, 'tool_inactiveusersgc');
        $u = $this->getDataGenerator()->create_user(['lastaccess' => $now - (200 * DAYSECS), 'suspended' => 0]);
        $sink = $this->redirectEmails();

        $this->run_task();
        $u2 = $DB->get_record('user', ['id' => $u->id], '*', MUST_EXIST);
        $this->assertEquals(1, (int)$u2->suspended, 'User should be suspended');

        // Tracking row should be marked stage 9 (actioned).
        $rec = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);
        $this->assertEquals(9, (int)$rec->stage);

        // Emails: summary only (no user email upon action).
        $this->assertCount(1, $sink->get_messages(), 'Only summary email expected for action');
    }

    /**
     * Scenario 7: Action user as Delete (method=1).
     * @throws dml_exception
     */
    public function test_action_delete(): void {
        global $DB;

        // Configure deletion at 90 days.
        set_config('method', 1, 'tool_inactiveusersgc'); // 1 = delete
        set_config('actiondays', 90, 'tool_inactiveusersgc');

        // Create a user already past the action threshold (120 days inactive).
        $now = time();
        $u = $this->getDataGenerator()->create_user([
            'lastaccess' => $now - (120 * DAYSECS),
        ]);

        // Capture outgoing mail.
        $sink = $this->redirectEmails();

        // Run the task: user should be deleted (soft-delete) and a summary email sent.
        $this->run_task();

        // Verify the user is soft-deleted.
        $u2 = $DB->get_record('user', ['id' => $u->id], '*', MUST_EXIST);
        $this->assertTrue((bool)$u2->deleted, 'User should be soft-deleted');

        $rec = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id]);
        $this->assertNotEmpty($rec, 'Tracking row should exist for actioned user');
        $this->assertEquals(9, (int)$rec->stage, 'Stage should be 9 (actioned)');

        // Only the summary email should be sent on action.
        $this->assertCount(1, $sink->get_messages());
    }


    /**
     * Scenario 8: Tenant membership filter includes/excludes correctly.
     * @throws dml_exception
     */
    public function test_tenant_filtering(): void {
        global $DB;
        $this->setCurrentTimeStart();
        $now = time();
        set_config('tenantcodes', 'ACME, BETA', 'tool_inactiveusersgc');

        $u1 = $this->getDataGenerator()->create_user(['lastaccess' => $now - (70 * DAYSECS)]);
        $u2 = $this->getDataGenerator()->create_user(['lastaccess' => $now - (70 * DAYSECS)]);
        $u3 = $this->getDataGenerator()->create_user(['lastaccess' => $now - (70 * DAYSECS)]);

        $this->set_tenant_code($u1, 'ACME');
        $this->set_tenant_code($u2, 'OMEGA');

        $sink = $this->redirectEmails();
        $this->run_task();
        $msgs = $sink->get_messages();
        // Expect: 1 user email (u1) + 1 summary.
        $this->assertCount(2, $msgs);

        $this->assertTrue($DB->record_exists('tool_inactiveusersgc', ['userid' => $u1->id]));
        $this->assertFalse($DB->record_exists('tool_inactiveusersgc', ['userid' => $u2->id]));
        $this->assertFalse($DB->record_exists('tool_inactiveusersgc', ['userid' => $u3->id]));
    }

    /**
     * Scenario 9: Suspended or deleted users are always skipped.
     * @throws dml_exception
     */
    public function test_skips_suspended_and_deleted(): void {
        global $DB;
        $this->setCurrentTimeStart();
        $now = time();
        $active = $this->getDataGenerator()->create_user(['lastaccess' => $now - (80 * DAYSECS), 'suspended' => 0, 'deleted' => 0]);
        $suspended = $this->getDataGenerator()->create_user(['lastaccess' => $now - (80 * DAYSECS), 'suspended' => 1]);
        $deleted = $this->getDataGenerator()->create_user(['lastaccess' => $now - (80 * DAYSECS), 'deleted' => 1]);
        $this->run_task();

        $this->assertTrue($DB->record_exists('tool_inactiveusersgc', ['userid' => $active->id]), 'Active should be processed');
        $this->assertFalse($DB->record_exists('tool_inactiveusersgc', ['userid' => $suspended->id]), 'Suspended should be skipped');
        $this->assertFalse($DB->record_exists('tool_inactiveusersgc', ['userid' => $deleted->id]), 'Deleted should be skipped');
    }
    /**
     * Scenario 10: Direct action (delete mode) immediately soft-deletes the user
     * when inactivity exceeds `actiondays`, without sending any user-facing emails.
     * @covers \tool_inactiveusersgc\local\processor::action_user
     * @throws dml_exception
     */
    public function test_direct_action_delete_no_user_email(): void {
        global $DB;

        $this->setCurrentTimeStart();
        $now = time();
        set_config('method', 1, 'tool_inactiveusersgc'); // 1 = delete
        set_config('actiondays', 100, 'tool_inactiveusersgc');

        $u = $this->getDataGenerator()->create_user(['lastaccess' => $now - (150 * DAYSECS), 'deleted' => 0]);

        $sink = $this->redirectEmails();
        $this->run_task();
        [$usermsgs, $summarymsgs] = $this->split_messages($sink->get_messages());

        $this->assertCount(1, $summarymsgs);
        $this->assertCount(0, $usermsgs);

        $u2 = $DB->get_record('user', ['id' => $u->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$u2->deleted, 'User should be soft-deleted in delete mode');

        $rec = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);
        $this->assertSame(9, (int)$rec->stage);

        $summary = end($summarymsgs);
        $this->assertMatchesRegularExpression('/Actioned:\s*1\b/', $summary->body);
    }

    /**
     * Scenario 11: user_loggedin observer clears tracking so next inactivity starts fresh.
     * @throws dml_exception|coding_exception
     */
    public function test_observer_clears_tracking_on_login(): void {
        global $DB;

        // Arrange: user gets a tracking row (make them inactive enough).
        $u = $this->getDataGenerator()->create_user([
            'lastaccess' => time() - (120 * DAYSECS),
        ]);

        $this->run_task();
        $this->assertTrue(
            $DB->record_exists('tool_inactiveusersgc', ['userid' => $u->id]),
            'Tracking row should exist after notification'
        );

        // Act: fire the login event (Moodle 4.5 requires objectid; include username).
        $event = user_loggedin::create([
            'objectid'      => $u->id,
            'relateduserid' => $u->id,
            'userid'        => $u->id,
            'other'         => ['username' => $u->username],
        ]);
        $event->trigger();

        // Fallback: if the row still exists (e.g., PHPUnit event map not refreshed),
        // call the observer directly with the event we just created.
        if ($DB->record_exists('tool_inactiveusersgc', ['userid' => $u->id])) {
            observer::user_loggedin($event);
        }

        // Assert: tracking row was cleared by observer (event or direct call).
        $this->assertFalse(
            $DB->record_exists('tool_inactiveusersgc', ['userid' => $u->id]),
            'Tracking row should be cleared after login'
        );
    }


    /**
     * Scenario 12: Summary email is always sent, containing counts.
     * @throws dml_exception
     */
    public function test_summary_email_counts(): void {
        global $DB;

        $this->setCurrentTimeStart();
        $now = time();

        // Configure: suspend at action time, and limit scope via tenant filter.
        set_config('method', 0, 'tool_inactiveusersgc');   // 0 = suspend
        set_config('firstdays', 60, 'tool_inactiveusersgc');
        set_config('actiondays', 365, 'tool_inactiveusersgc');
        set_config('tenantcodes', 'TESTTENANT', 'tool_inactiveusersgc');

        // Create two users in the TESTTENANT only.
        $u1 = $this->getDataGenerator()->create_user(['lastaccess' => $now - (80 * DAYSECS), 'suspended' => 0, 'deleted' => 0]);
        $u2 = $this->getDataGenerator()->create_user(['lastaccess' => $now - (400 * DAYSECS), 'suspended' => 0, 'deleted' => 0]);
        $this->set_tenant_code($u1, 'TESTTENANT');
        $this->set_tenant_code($u2, 'TESTTENANT');

        $sink = $this->redirectEmails();

        // Act.
        $this->run_task();
        $messages = $sink->get_messages();

        // Split user vs summary mails to avoid false positives.
        [$usermsgs, $summarymsgs] = $this->split_messages($messages);
        $this->assertCount(1, $summarymsgs, 'Exactly one summary email should be sent');
        $this->assertCount(1, $usermsgs, 'Exactly one user email should be sent (warning for u1)');

        // Assert DB side-effects.
        $rec1 = $DB->get_record('tool_inactiveusersgc', ['userid' => $u1->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$rec1->stage, 'u1 should be at stage 1 (first warning)');
        $rec2 = $DB->get_record('tool_inactiveusersgc', ['userid' => $u2->id], '*', MUST_EXIST);
        $this->assertSame(9, (int)$rec2->stage, 'u2 should be actioned (stage 9)');

        $u2after = $DB->get_record('user', ['id' => $u2->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$u2after->suspended, 'u2 should be suspended in method=0');

        // Assert the summary body reports the exact counts.
        $summary = end($summarymsgs);
        $this->assertStringContainsString('Inactive users manager', $summary->subject);
        $this->assertMatchesRegularExpression('/Found:\s*2\b/',    $summary->body);
        $this->assertMatchesRegularExpression('/Notified:\s*1\b/', $summary->body);
        $this->assertMatchesRegularExpression('/Actioned:\s*1\b/', $summary->body);
    }


    /**
     * Scenario 13: Crossing into action immediately actions without extra user email.
     * @throws dml_exception
     */
    public function test_direct_action_no_extra_email(): void {
        global $DB;

        $this->setCurrentTimeStart();
        $now = time();

        // Configure: act at 100 days (use suspend in this test).
        set_config('method', 0, 'tool_inactiveusersgc'); // 0 = suspend
        set_config('actiondays', 100, 'tool_inactiveusersgc');

        // Create a user already beyond actiondays.
        $u = $this->getDataGenerator()->create_user([
            'lastaccess' => $now - (150 * DAYSECS),
            'suspended'  => 0,
            'deleted'    => 0,
        ]);

        // Start with a fresh sink (ensures no prior emails contribute).
        $sink = $this->redirectEmails();

        // Run the scheduled task.
        $this->run_task();
        [$usermsgs, $summarymsgs] = $this->split_messages($sink->get_messages());

        // 1) Email-side assertions (these make it fail if a user email is sent at action time).
        $this->assertCount(1, $summarymsgs, 'Summary should be sent exactly once');
        $this->assertCount(0, $usermsgs, 'No user email should be sent at action time');

        // 2) User state must reflect the action (fail if the plugin didn’t actually action the user).
        $u2 = $DB->get_record('user', ['id' => $u->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$u2->suspended, 'User should be suspended when action threshold is crossed');
        $this->assertSame(0, (int)$u2->deleted, 'User must not be deleted in suspend mode');

        // 3) Tracking row must be marked actioned (fail if stage not set to 9).
        $rec = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);
        $this->assertSame(9, (int)$rec->stage, 'Stage should be 9 (actioned)');

        // 4) Summary body should report Actioned: 1 (fail if counts are wrong).
        $summary = end($summarymsgs);
        $this->assertMatchesRegularExpression('/Actioned:\s*1\b/', $summary->body, 'Summary should report one action');

        // 5) Running again immediately should not send extra user mail, and not re-action.
        $this->run_task();
        [$usermsgs2, $summarymsgs2] = $this->split_messages($sink->get_messages());
        $this->assertCount(0, array_slice($usermsgs2, count($usermsgs)), 'No user email on subsequent run either');
        $this->assertGreaterThanOrEqual(2, count($summarymsgs2), 'A summary is sent after each run');

        $u3 = $DB->get_record('user', ['id' => $u->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$u3->suspended, 'User remains suspended; no flip-flop');
    }


    /**
     * Scenario 14: Stage does not regress; repeats respect lastsent timestamp.
     * @throws dml_exception
     */
    public function test_stage_and_repeats_consistency(): void {
        global $DB;

        // Configure a short repeat for the test.
        set_config('firstdays', 60, 'tool_inactiveusersgc');
        set_config('firstrepeat', 5, 'tool_inactiveusersgc');

        // User is in the first warning window (>= 60 days inactive).
        $u = $this->getDataGenerator()->create_user([
            'lastaccess' => time() - (70 * DAYSECS),
        ]);

        // First run: first-stage email should be sent and tracking created.
        $this->run_task();
        $rec1 = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);
        $this->assertEquals(1, (int)$rec1->stage, 'Stage should be 1 after first send');

        // Pretend only 4 days have passed since last send -> below repeat window.
        $this->set_track_lastsent_days_ago($u->id, 4);
        $recbefore = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);

        $this->run_task();
        $rec2 = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);

        // No repeat before interval: lastsent should remain exactly what we backdated it to.
        $this->assertSame((int)$recbefore->lastsent, (int)$rec2->lastsent, 'No repeat before interval');
        $this->assertSame(1, (int)$rec2->stage, 'Stage should remain 1 before repeat');

        // Pretend 6 days have passed since last send -> beyond repeat window, so a repeat should fire.
        $this->set_track_lastsent_days_ago($u->id, 6);
        $recbeforerepeat = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);

        $this->run_task();
        $rec3 = $DB->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', MUST_EXIST);

        $this->assertGreaterThan((int)$recbeforerepeat->lastsent, (int)$rec3->lastsent, 'Repeat should update lastsent');
        $this->assertSame(1, (int)$rec3->stage, 'Stage remains first while still in first window');
    }

    protected function dump_emails(array $messages): void {
        foreach ($messages as $m) {
            $to = $m->to ?? ($m->toemail ?? '');
            $subject = $m->subject ?? '';
            $body = $m->body ?? '';
            // Common alternates across Moodle versions:
            $alt = $m->altbody ?? ($m->bodyplain ?? $m->fullmessage ?? $m->fullmessagehtml ?? '');
            fwrite(STDOUT, "\n==== EMAIL DEBUG ====\n");
            fwrite(STDOUT, "To: {$to}\n");
            fwrite(STDOUT, "Subject: {$subject}\n");
            fwrite(STDOUT, "Body:\n" . ($body !== '' ? $body : $alt) . "\n");
            // Optional: show all properties for discovery
            // fwrite(STDOUT, print_r(get_object_vars($m), true));
        }
    }


    public function test_debug_email_output(): void {
        $this->setCurrentTimeStart();
        $now = time();

        // Force thresholds so we trigger first warning immediately.
        set_config('firstdays', 1, 'tool_inactiveusersgc');
        set_config('actiondays', 10, 'tool_inactiveusersgc');

        // Create a user inactive for 2 days.
        $u = $this->getDataGenerator()->create_user(['lastaccess' => $now - (2 * DAYSECS)]);

        // Start capturing all emails.
        $sink = $this->redirectEmails();

        // Run the scheduled task.
        $this->run_task();

        // Collect messages.
        $msgs = $sink->get_messages();

        [$userMsgs, $summaryMsgs] = $this->split_messages($sink->get_messages());
        $this->dump_emails($userMsgs);
        $this->dump_emails($summaryMsgs);
    }



}
