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
        global $PAGE;

        $context = context_module::instance($cm->id);
        require_capability('quiz/livemonitor:view', $context);

        $PAGE->set_url(new moodle_url(
            '/mod/quiz/report.php',
            ['id' => $cm->id, 'mode' => 'livemonitor']
        ));

        // Standard quiz report header and navigation tabs.
        $this->print_header_and_tabs($cm, $course, $quiz, 'livemonitor');

        $activewindow = (int) (get_config('quiz_livemonitor', 'activewindow') ?: 60);

        $renderable = new \quiz_livemonitor\output\monitor_table($quiz, $cm, $context, $activewindow);
        /** @var \quiz_livemonitor\output\renderer $renderer */
        $renderer = $PAGE->get_renderer('quiz_livemonitor');
        echo $renderer->render($renderable);

        $refresh = (int) (get_config('quiz_livemonitor', 'refreshinterval') ?: 20);
        $PAGE->requires->js_call_amd('quiz_livemonitor/monitor', 'init', [(int) $cm->id, $refresh]);

        // No footer here: mod/quiz/report.php prints it after display() returns.
        // Emitting it twice unbalances the container stack.
        return true;
    }
}
