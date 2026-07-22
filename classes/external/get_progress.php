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

namespace quiz_livemonitor\external;

// NOTE: These namespaced external base classes exist in Moodle 4.2+. On Moodle 4.1
// swap them for the legacy global classes (external_api, external_function_parameters, ...).
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use quiz_livemonitor\local\progress_provider;

/**
 * Web service: return a live progress snapshot for one quiz.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_progress extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz.'),
        ]);
    }

    /**
     * Return the snapshot.
     *
     * @param int $cmid course module id.
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('quiz/livemonitor:view', $context);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $activewindow = (int) (get_config('quiz_livemonitor', 'activewindow') ?: 60);

        return progress_provider::get_data($quiz, $cm, $context, $activewindow);
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'           => new external_value(PARAM_INT, 'Course module id.'),
            'generatedat'    => new external_value(PARAM_INT, 'Unix time the snapshot was taken.'),
            'generatedatstr' => new external_value(PARAM_TEXT, 'Formatted snapshot time.'),
            'hasrows'        => new external_value(PARAM_BOOL, 'Whether there are any participants.'),
            'summary'        => new external_single_structure([
                'total'      => new external_value(PARAM_INT, 'Eligible participants.'),
                'active'     => new external_value(PARAM_INT, 'Attempts active right now.'),
                'inprogress' => new external_value(PARAM_INT, 'Attempts in progress (incl. idle/overdue).'),
                'finished'   => new external_value(PARAM_INT, 'Submitted attempts.'),
                'overrun'    => new external_value(PARAM_INT, 'Attempts past their time limit.'),
                'notstarted' => new external_value(PARAM_INT, 'Participants with no attempt yet.'),
            ]),
            'rows'           => new external_multiple_structure(new external_single_structure([
                'userid'              => new external_value(PARAM_INT, 'User id.'),
                'fullname'            => new external_value(PARAM_TEXT, 'Participant full name.'),
                'statuskey'           => new external_value(PARAM_ALPHA, 'Status key.'),
                'statuslabel'         => new external_value(PARAM_TEXT, 'Localized status label.'),
                'statusbadgeclass'    => new external_value(PARAM_TEXT, 'Badge CSS classes.'),
                'answered'            => new external_value(PARAM_INT, 'Answered question count.'),
                'total'               => new external_value(PARAM_INT, 'Total question count.'),
                'progresspercent'     => new external_value(PARAM_INT, 'Progress percentage.'),
                'progresslabel'       => new external_value(PARAM_TEXT, 'Progress label "X / N".'),
                'lastactivitylabel'   => new external_value(PARAM_TEXT, 'Last activity label.'),
                'lastactivityseconds' => new external_value(PARAM_INT, 'Seconds since last activity (-1 if none).'),
                'elapsedlabel'        => new external_value(PARAM_TEXT, 'Elapsed working-time label.'),
                'hastimelimit'        => new external_value(PARAM_BOOL, 'Whether a time limit applies.'),
                'timeleftlabel'       => new external_value(PARAM_TEXT, 'Time-remaining label.'),
                'timeleftseconds'     => new external_value(PARAM_INT, 'Seconds remaining (negative if overrun).'),
                'isoverrun'           => new external_value(PARAM_BOOL, 'Whether the time limit is exceeded.'),
                'isactive'            => new external_value(PARAM_BOOL, 'Whether the attempt is active now.'),
            ])),
        ]);
    }
}
