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
 * Live monitor quiz report class.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The Live monitor report: a compact, self-refreshing overview of quiz attempts in progress.
 *
 * The class name is dictated by mod/quiz/report.php, which instantiates
 * 'quiz_' . $mode . '_report', so it stays in the global namespace.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_livemonitor_report extends \mod_quiz\local\reports\report_base {
    /**
     * Display the report.
     *
     * @param stdClass $quiz the quiz record.
     * @param stdClass $cm the course module record.
     * @param stdClass $course the course record.
     * @return bool
     */
    public function display($quiz, $cm, $course): bool {
        global $PAGE, $OUTPUT;

        $context = context_module::instance($cm->id);
        require_capability('quiz/livemonitor:view', $context);

        $PAGE->set_url(new moodle_url(
            '/mod/quiz/report.php',
            ['id' => $cm->id, 'mode' => 'livemonitor']
        ));

        // Standard quiz report header and navigation tabs.
        $this->print_header_and_tabs($cm, $course, $quiz, 'livemonitor');

        // Without autosaves the server hears nothing between page submits, so on a quiz that
        // shows every question on one page there is simply no data to report. Say so rather
        // than showing a table that silently stays empty. Printed outside the polled region
        // because it is static.
        if (\quiz_livemonitor\local\settings::autosave_disabled()) {
            echo $OUTPUT->notification(
                get_string('autosavedisabled', 'quiz_livemonitor'),
                \core\output\notification::NOTIFY_WARNING
            );
        }

        $activewindow = \quiz_livemonitor\local\settings::active_window();

        $renderable = new \quiz_livemonitor\output\monitor_table($quiz, $cm, $context, $activewindow);
        /** @var \quiz_livemonitor\output\renderer $renderer */
        $renderer = $PAGE->get_renderer('quiz_livemonitor');
        echo $renderer->render($renderable);

        $refresh = \quiz_livemonitor\local\settings::refresh_interval();
        $PAGE->requires->js_call_amd('quiz_livemonitor/monitor', 'init', [(int) $cm->id, $refresh]);

        // No footer here: mod/quiz/report.php prints it after display() returns.
        // Emitting it twice unbalances the container stack.
        return true;
    }
}
