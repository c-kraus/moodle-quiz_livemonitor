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
 * Version details for the Live monitor quiz report.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'quiz_livemonitor';
$plugin->version   = 2026080700;
// Moodle 4.2.0. classes/external/get_progress.php extends the core_external\*
// classes, which do not exist before 4.2: on 4.1 the web service class cannot be
// loaded, so the auto-refresh fails at run time. Requiring 4.2 makes an install
// on 4.1 fail immediately instead of during an exam.
$plugin->requires  = 2023042400;
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v0.3.0';
