<?php
// This file is part of the quiz_livemonitor plugin for Moodle.
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
 * English strings for quiz_livemonitor.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Live monitor';
$string['livemonitor:view'] = 'View the live monitor for a quiz';

// Column headers.
$string['col_name'] = 'Participant';
$string['col_status'] = 'Status';
$string['col_progress'] = 'Progress';
$string['col_lastactivity'] = 'Last activity';
$string['col_elapsed'] = 'Elapsed';
$string['col_timeleft'] = 'Time left';

// Status labels.
$string['status_active'] = 'Active';
$string['status_idle'] = 'Idle';
$string['status_finished'] = 'Submitted';
$string['status_overdue'] = 'Time overrun';
$string['status_abandoned'] = 'Abandoned';
$string['status_notstarted'] = 'Not started';

// Summary tiles.
$string['summary_total'] = 'Participants';
$string['summary_active'] = 'Active now';
$string['summary_inprogress'] = 'In progress';
$string['summary_finished'] = 'Submitted';
$string['summary_overrun'] = 'Time overrun';
$string['summary_notstarted'] = 'Not started';

// Misc UI.
$string['active'] = 'Active now';
$string['ago'] = '{$a} ago';
$string['overrunby'] = 'over by {$a}';
$string['lastupdated'] = 'Last updated:';
$string['noparticipants'] = 'No participants are eligible to attempt this quiz yet.';
$string['pause'] = 'Pause auto-refresh';
$string['resume'] = 'Resume auto-refresh';
$string['refreshnow'] = 'Refresh now';
$string['legend'] = 'Legend';
$string['sebnote'] = 'Note: "active" means server-side activity within the last few seconds. The Safe Exam Browser locks the student device but does not report window focus or keystrokes to Moodle.';

// Settings.
$string['setting_refreshinterval'] = 'Auto-refresh interval (seconds)';
$string['setting_refreshinterval_desc'] = 'How often the teacher\'s browser polls for a fresh snapshot. Only the teacher viewing the report polls; students are not affected.';
$string['setting_activewindow'] = 'Active window (seconds)';
$string['setting_activewindow_desc'] = 'An in-progress attempt counts as "active" when its last server-side activity is within this many seconds.';

// Privacy.
$string['privacy:metadata'] = 'The Live monitor plugin does not store any personal data. It only displays data already collected by the Quiz activity.';
