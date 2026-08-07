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
// Both strings are required of every quiz report subplugin, as core builds its
// labels from the report name: quiz_extend_settings_navigation() uses
// get_string($report, ...) for the Results navigation entry, and
// mod/quiz/settings.php uses get_string($report . 'report', ...) for the
// settings page. Without them the report page fails with debugging enabled.
$string['livemonitor'] = 'Live monitor';
$string['livemonitorreport'] = 'Live monitor report';

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
$string['sebnote'] = 'Note: "active" means server-side activity within the last few seconds. The Safe Exam Browser locks the student device but does not report window focus or keystrokes to Moodle.';

// Settings.
$string['setting_refreshinterval'] = 'Auto-refresh interval (seconds)';
$string['setting_refreshinterval_desc'] = 'How often the teacher\'s browser polls for a fresh snapshot. Only the teacher viewing the report polls; students are not affected.';
$string['setting_activewindow'] = 'Active window (seconds)';
$string['setting_activewindow_desc'] = 'An in-progress attempt counts as "active" when its last server-side activity is within this many seconds. Keep this comfortably above the quiz autosave period (Site administration > Plugins > Activity modules > Quiz > Auto-save period), because that is the shortest interval at which the server hears from a student who is typing without changing page. Values that are too low are raised automatically.';
$string['autosavedisabled'] = 'Quiz auto-save is switched off site-wide, so this report cannot see progress or activity until a student submits a page. On a quiz that shows every question on one page that means nothing appears until the attempt is submitted. Set a non-zero auto-save period under Site administration > Plugins > Activity modules > Quiz.';

// Privacy.
$string['privacy:metadata'] = 'The Live monitor plugin does not store any personal data. It only displays data already collected by the Quiz activity.';
