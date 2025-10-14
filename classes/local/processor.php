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

namespace tool_inactiveusersgc\local;
use coding_exception;
use dml_exception;
use stdClass;

/**
 * Core processor for handling inactive users.
 *
 * This class encapsulates the main logic of the inactive users tool,
 * including fetching candidate users, applying inactivity thresholds,
 * sending notifications, and performing configured actions (suspend or delete).
 *
 * It maintains references to the Moodle database, plugin configuration,
 * and the site support user for sending notifications.
 *
 * @package    tool_inactiveusersgc
 * @copyright  2025 Waleed ul Hassan <waleed.hassan@catalyst-eu.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class processor {
    /** @var \moodle_database */
    protected $db;
    /** @var stdClass site support user */
    protected $supportuser;
    /** @var array config */
    protected $cfg;
    /**
     * Constructor.
     *
     * Initialises database connection, loads plugin configuration,
     * and fetches the support user record.
     */
    public function __construct() {
        global $DB, $CFG;
        $this->db = $DB;
        $this->cfg = get_config('tool_inactiveusersgc');
        $this->supportuser = \core_user::get_support_user();
    }

    /**
     * Entry point.
     */
    public function run() {
        $when = \userdate(time());
        $counts = (object)['found' => 0, 'notified' => 0, 'actioned' => 0, 'errors' => 0];

        $candidates = $this->find_candidates();
        $counts->found = count($candidates);

        foreach ($candidates as $u) {
            try {
                // Skip special accounts.
                if (\isguestuser($u) || \is_siteadmin($u->id)) {
                    continue;
                }
                $this->process_user($u, $counts);
            } catch (\Throwable $e) {
                $counts->errors++;
                \debugging('tool_inactiveusersgc error for user ' . $u->id.': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $this->send_summary($when, $counts);
    }

    /**
     * Find users matching filters and inactive thresholds (we evaluate per-user in process_user).
     * Filters: deleted=0, suspended=0, optional tenant codes.
     * @return array of user records (id, firstname, lastname, email, lastaccess, timecreated)
     * @throws dml_exception
     */
    protected function find_candidates(): array {
        $params = [];
        $tenantfilter = '';
        $tenantfilterwhere = '';
        $codescsv = trim((string)($this->cfg->tenantcodes ?? ''));
        if ($codescsv !== '') {
            // Filter by profile field 'primary_membership_code' in a list of values.
            $codes = array_map('trim', explode(',', $codescsv));
            list($insql, $inparams) = $this->db->get_in_or_equal($codes, SQL_PARAMS_NAMED);
            $params += $inparams;
            $tenantfilter = "
                JOIN {user_info_data} uid ON uid.userid = u.id
                JOIN {user_info_field} uif ON uif.id = uid.fieldid AND uif.shortname = :pfshort
            ";
            $params['pfshort'] = 'primary_membership_code';
            $tenantfilterwhere = " AND uid.data $insql ";
        }

        $sql = "
            SELECT u.id, u.firstname, u.lastname, u.email, u.lastaccess,
                   u.timecreated, u.suspended, u.deleted
            FROM {user} u $tenantfilter
            WHERE u.deleted = 0 AND u.suspended = 0 $tenantfilterwhere
        ";
        return $this->db->get_records_sql($sql, $params);
    }

    /**
     * Process one user: decide stage, send emails based on inactivity, take action if over threshold.
     */
    protected function process_user(stdClass $u, stdClass $counts) {
        $u = \core_user::get_user($u->id, '*', MUST_EXIST);
        $now = time();
        $lastactivity = (int)$u->lastaccess ?: (int)$u->timecreated; // Caveat: never logged in -> use creation time.
        $daysinactive = floor(($now - $lastactivity) / DAYSECS);

        $config = $this->read_config();

        // Fetch or create tracking row.
        $track = $this->db->get_record('tool_inactiveusersgc', ['userid' => $u->id], '*', IGNORE_MISSING);
        if (!$track) {
            $track = (object)['userid' => $u->id, 'stage' => 0, 'lastsent' => null, 'timemodified' => $now];
        }

        // Determine next stage based on inactivity days & config windows.
        $nextstage = $this->determine_stage($daysinactive, $config);

        if ($nextstage === 0) {
            // Nothing to do (not yet in first window).
            return;
        }

        if ($nextstage === 9) {
            // Action user if past action_days.
            if ($this->action_user($u, $config)) {
                $counts->actioned++;
                $this->save_track($track, 9, $now);
            }
            return;
        }

        // For stages 1,2,3 determine if we should send (consider repeats and lastsent).
        $shouldsend = $this->should_send($track, $nextstage, $config, $now);
        if ($shouldsend) {
            if ($this->send_stage_email($u, $nextstage, $config, $daysinactive)) {
                $counts->notified++;
                $this->save_track($track, $nextstage, $now);
            }
        }
    }

    /**
     * Reads and normalises plugin configuration values.
     *
     * Converts raw plugin settings into a typed configuration object
     * with sensible defaults for missing values. This includes
     * inactivity thresholds, repeat intervals, email subjects/bodies,
     * and support email address.
     *
     * @return stdClass Object containing normalised configuration:
     *  - method (int) suspend=0, delete=1
     *  - actiondays (int) number of days before action
     *  - firstdays (int) days before first warning
     *  - firstrepeat (int) repeat interval for first warning
     *  - seconddays (int) days before second warning
     *  - secondrepeat (int) repeat interval for second warning
     *  - finaldays (int) days before final warning
     *  - firstsubject (string) subject for first warning email
     *  - firstmsg (string) body for first warning email
     *  - secondsubject (string) subject for second warning email
     *  - secondmsg (string) body for second warning email
     *  - finalsubject (string) subject for final warning email
     *  - finalmsg (string) body for final warning email
     *  - supportemail (string) optional support contact address
     * @throws coding_exception
     */
    protected function read_config(): stdClass {
        $cfg = (object)[
            'method' => (int)($this->cfg->method ?? 0),
            'actiondays' => (int)($this->cfg->actiondays ?? 365),
            'firstdays' => (int)($this->cfg->firstdays ?? 60),
            'firstrepeat' => (int)($this->cfg->firstrepeat ?? 30),
            'seconddays' => (int)($this->cfg->seconddays ?? 300),
            'secondrepeat' => (int)($this->cfg->secondrepeat ?? 30),
            'finaldays' => (int)($this->cfg->finaldays ?? 364),
            'firstsubject' => (string)(
                $this->cfg->firstsubject ?? get_string('email:first:subject:default',
                'tool_inactiveusersgc', (object)['sitename' => format_string($globals['SITE']->fullname)])
            ),
            'firstmsg' => (string)(
                $this->cfg->firstmsg ?? get_string('email:first:body:default',
                'tool_inactiveusersgc')
            ),
            'secondsubject' => (string)(
                $this->cfg->secondsubject ?? get_string('email:second:subject:default',
                'tool_inactiveusersgc', (object)['sitename' => format_string($globals['SITE']->fullname)])
            ),
            'secondmsg' => (string)(
                $this->cfg->secondmsg ?? get_string('email:second:body:default', 'tool_inactiveusersgc')
            ),
            'finalsubject' => (string)(
                $this->cfg->finalsubject ?? get_string('email:final:subject:default',
                'tool_inactiveusersgc', (object)['sitename' => format_string($globals['SITE']->fullname)])),
            'finalmsg' => (string)($this->cfg->finalmsg ?? get_string('email:final:body:default', 'tool_inactiveusersgc')),
            'supportemail' => (string)($this->cfg->supportemail ?? ''
            ),
        ];
        return $cfg;
    }

    /**
     * Map inactivity days to stage:
     *  0 none, 1 first window, 2 second window, 3 final window, 9 action.
     */
    protected function determine_stage(int $daysinactive, stdClass $cfg): int {
        return match (true) {
            $daysinactive >= $cfg->actiondays => 9,
            $daysinactive >= $cfg->finaldays  => 3,
            $daysinactive >= $cfg->seconddays => 2,
            $daysinactive >= $cfg->firstdays  => 1,
            default                           => 0,
        };
    }
    /**
     * Determine whether a user should receive an inactivity notification email.
     *
     * Decision rules:
     *  - If no notification has ever been sent (`lastsent` empty or stage=0), send immediately.
     *  - If the current stage is greater than the previously recorded stage, send immediately.
     *  - If the stage is unchanged:
     *      - Stage 1 → respect the configured `firstrepeat` interval.
     *      - Stage 2 → respect the configured `secondrepeat` interval.
     *      - Stage 3 (final) → never repeat.
     *
     * @param stdClass $track Current tracking row for the user (must include `stage` and `lastsent`).
     * @param int $stage Newly determined stage (1 = first, 2 = second, 3 = final).
     * @param stdClass $cfg Normalised plugin configuration (from {@see read_config()}).
     * @param int $now Current timestamp (usually `time()` or a frozen test time).
     * @return bool True if a notification should be sent, false otherwise.
     */
    protected function should_send(stdClass $track, int $stage, stdClass $cfg, int $now): bool {
        // If we've never sent, send.
        if (empty($track->lastsent) || (int)$track->stage === 0) {
            return true;
        }
        // If the stage increased, send immediately.
        if ((int)$track->stage < $stage) {
            return true;
        }
        // Same stage -> check repeat interval (final has no repeat).
        $repeat = 0;
        if ($stage === 1) {
            $repeat = $cfg->firstrepeat;
        } else if ($stage === 2) {
            $repeat = $cfg->secondrepeat;
        } else if ($stage === 3) {
            $repeat = 0;
        }

        if ($repeat <= 0) {
            return false;
        }
        return ($now - (int)$track->lastsent) >= ($repeat * DAYSECS);
    }
    /**
     * Persist or update a tracking row for a user's inactivity notification.
     *
     * Updates the given tracking record with the new stage and timestamp,
     * then inserts it if new, or updates the existing record otherwise.
     *
     * Fields updated:
     *  - stage → current inactivity stage (1=first, 2=second, 3=final, 9=actioned).
     *  - lastsent → when the last notification was sent (timestamp).
     *  - timemodified → when this row was last updated (timestamp).
     *
     * @param stdClass $track Tracking record (may or may not include `id`).
     * @param int $stage Stage number to set on the tracking record.
     * @param int $now Current timestamp (usually from `time()` or frozen test time).
     * @return void
     * @throws dml_exception If the insert or update fails.
     */
    protected function save_track(stdClass $track, int $stage, int $now): void {
        $track->stage = $stage;
        $track->lastsent = $now;
        $track->timemodified = $now;
        if (empty($track->id)) {
            $this->db->insert_record('tool_inactiveusersgc', $track);
        } else {
            $this->db->update_record('tool_inactiveusersgc', $track);
        }
    }
    /**
     * Send an inactivity notification email to a user for the given stage.
     *
     * Composes and delivers an email based on the inactivity stage (first, second, or final).
     * Subject and body templates are taken from plugin configuration, and placeholders are
     * replaced with context values (e.g. site name, action date, support contact).
     *
     * Placeholders supported in messages:
     *  - {$a->firstname}
     *  - {$a->sitename}
     *  - {$a->actiondate}
     *  - {$a->method} ("suspend" or "delete")
     *  - {$a->supportname}
     *
     * @param stdClass $u User record to notify.
     * @param int $stage Inactivity stage (1=first, 2=second, 3=final).
     * @param stdClass $cfg Plugin configuration (subjects, bodies, actiondays, etc).
     * @param int $daysinactive Number of days the user has been inactive.
     * @return bool True if the email was sent successfully, false otherwise.
     * @throws coding_exception If invalid stage is passed.
     */
    protected function send_stage_email(\stdClass $u, int $stage, \stdClass $cfg, int $daysinactive): bool {
        global $SITE;

        $a = (object)[
            'firstname'   => fullname($u),
            'sitename'    => format_string($SITE->fullname),
            'method'      => $cfg->method
                ? get_string('settings:method:delete', 'tool_inactiveusersgc')
                : get_string('settings:method:suspend', 'tool_inactiveusersgc'),
            'actiondate'  => userdate(time() + (($cfg->actiondays - $daysinactive) * DAYSECS)),
            'supportname' => fullname($this->supportuser),
        ];

        // Pick templates.
        switch ($stage) {
            case 1:
                $subjecttpl = (string)$cfg->firstsubject;
                $bodytpl    = (string)$cfg->firstmsg;
                break;
            case 2:
                $subjecttpl = (string)$cfg->secondsubject;
                $bodytpl    = (string)$cfg->secondmsg;
                break;
            case 3:
                $subjecttpl = (string)$cfg->finalsubject;
                $bodytpl    = (string)$cfg->finalmsg;
                break;
            default:
                return false;
        }

        // Replace placeholders in subject and body.
        $replacements = [
            '{$a->firstname}'   => $a->firstname,
            '{$a->sitename}'    => $a->sitename,
            '{$a->actiondate}'  => $a->actiondate,
            '{$a->method}'      => $a->method,
            '{$a->supportname}' => $a->supportname,
        ];
        $subject = strtr($subjecttpl, $replacements);

        // Start from plain text body (don’t run filters here — keep deterministic).
        $bodytext = strtr($bodytpl, $replacements);
        $bodytext = trim(format_text($bodytext, FORMAT_PLAIN, ['filter' => false, 'noclean' => true]));

        // Build HTML counterpart so $msg->body is populated in tests/clients.
        // text_to_html() converts newlines to <br> and escapes safely.
        $bodyhtml = text_to_html($bodytext, false, false, true);

        // From support user; Moodle will handle mailformat.
        $from = $this->supportuser;

        // Provide both plain and HTML bodies.
        return email_to_user($u, $from, $subject, $bodytext, $bodyhtml);
    }
    /**
     * Take the final configured action on an inactive user (suspend or delete).
     *
     * Behaviour depends on $cfg->method:
     *  - 0 → suspend the user (set suspended flag).
     *  - 1 → delete the user (soft-delete via delete_user()).
     *
     * This method ensures users are not redundantly suspended and uses Moodle core APIs.
     *
     * @param stdClass $u User record (must include id).
     * @param stdClass $cfg Plugin configuration (must contain 'method').
     * @return bool Always true once action is attempted.
     * @throws dml_exception|coding_exception If DB operations fail.
     */
    protected function action_user(stdClass $u, stdClass $cfg): bool {
        global $DB;
        if ($cfg->method) {
            // Delete.
            delete_user($u);
            return true;
        } else {
            // Suspend.
            if (!$u->suspended) {
                $u2 = $DB->get_record('user', ['id' => $u->id], '*', MUST_EXIST);
                $u2->suspended = 1;
                $DB->update_record('user', $u2);
            }
            return true;
        }
    }
    /**
     * Send a summary email to site support (or configured address) after task run.
     *
     * The summary contains counts of users found, notified, actioned, and any errors.
     * Email is sent to the real support user unless a custom support email is configured.
     *
     * Example message body:
     *   Execution time: <date>
     *   Found: X
     *   Notified: Y
     *   Actioned: Z
     *   Errors: N
     *
     * @param string $when Human-readable timestamp (execution time).
     * @param stdClass $counts Object with ->found, ->notified, ->actioned, ->errors integers.
     * @return void
     * @throws coding_exception If strings are missing.
     */
    protected function send_summary(string $when, stdClass $counts): void {
        // Use the real support user as the "to" user, so email_to_user() treats it as valid.
        $to = \core_user::get_support_user();

        // If a custom address is configured, keep the real user object but override the email.
        $configemail = isset($this->cfg->supportemail) ? trim((string)$this->cfg->supportemail) : '';
        if ($configemail !== '') {
            $to->email = $configemail;
        }

        // From noreply (real user object).
        $from = \core_user::get_noreply_user();

        $a = (object)[
            'when' => $when,
            'countfound' => $counts->found,
            'countnotified' => $counts->notified,
            'countactioned' => $counts->actioned,
            'counterrors' => $counts->errors,
        ];

        $subject = get_string('summary:subject', 'tool_inactiveusersgc');
        $body = get_string('summary:body', 'tool_inactiveusersgc', $a);

        \email_to_user($to, $from, $subject, $body);
    }
}
