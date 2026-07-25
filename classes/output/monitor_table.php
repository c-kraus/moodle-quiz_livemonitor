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

namespace quiz_livemonitor\output;

use quiz_livemonitor\local\progress_provider;
use renderer_base;
use renderable;
use templatable;

/**
 * Renderable for the live monitor overview.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class monitor_table implements renderable, templatable {
    /** @var \stdClass the quiz record. */
    protected $quiz;

    /** @var \stdClass|\cm_info the course module. */
    protected $cm;

    /** @var \context the module context. */
    protected $context;

    /** @var int seconds within which server activity counts as "active". */
    protected $activewindow;

    /**
     * Constructor.
     *
     * @param \stdClass $quiz the quiz record.
     * @param \stdClass|\cm_info $cm the course module.
     * @param \context $context the module context.
     * @param int $activewindow "active" threshold in seconds.
     */
    public function __construct($quiz, $cm, \context $context, int $activewindow) {
        $this->quiz = $quiz;
        $this->cm = $cm;
        $this->context = $context;
        $this->activewindow = $activewindow;
    }

    /**
     * Export the snapshot for the Mustache templates.
     *
     * @param renderer_base $output the renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return progress_provider::get_data($this->quiz, $this->cm, $this->context, $this->activewindow);
    }
}
