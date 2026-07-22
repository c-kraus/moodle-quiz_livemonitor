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
 * Capability definitions for the Live monitor quiz report.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Whether a user may view the live monitor for a quiz.
    // The name follows the pattern quiz/<report>:view that mod_quiz uses to decide
    // which reports appear in the "Results" navigation.
    'quiz/livemonitor:view' => [
        'captype'              => 'read',
        'contextlevel'         => CONTEXT_MODULE,
        'archetypes'           => [
            'teacher'          => CAP_ALLOW,
            'editingteacher'   => CAP_ALLOW,
            'manager'          => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quiz:viewreports',
    ],
];
