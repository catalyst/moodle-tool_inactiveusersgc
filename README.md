# tool_inactiveusersgc

Admin tool for Moodle/Totara (tested baseline: Moodle 3.11 / Totara 19) to send staged inactivity warnings and action users.

## Installation
Place this folder at `admin/tool/inactiveusersgc` and visit Site administration to complete installation.

## Configuration
Go to *Site administration → Server → Inactive users manager (GC)* and set:
- Days until first/second/final warnings
- Repeat intervals for first/second warnings
- Action days and method (suspend/delete)
- Optional support email for run summaries
- Optional list of allowed Primary Membership Codes (comma-separated) via user profile field `primary_membership_code`.

## How it works
- A daily scheduled task scans users who are not deleted/suspended and (optionally) match the tenant codes.
- For each user, inactivity is calculated from `lastaccess` or `timecreated` if never logged in.
- It sends first/second/final emails according to thresholds and repeats, and actions accounts past `actiondays`.
- On user login, any notification records are cleared so future inactivity starts from stage 1.

## Table
- `tool_inactiveusersgc` tracks per-user stage and last time an email was sent.

## Notes
- Email bodies are `plain text`. Use placeholders like `{$a->sitename}`, `{$a->firstname}`, `{$a->actiondate}`, `{$a->method}`.
- Summary email is sent after each run to the configured Support Email (or site support email).

## Tests
- Includes PHPUnit tests.
