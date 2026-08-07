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
 * Admin settings for the Live monitor quiz report.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // How often (seconds) the teacher's browser polls for a fresh snapshot.
    $settings->add(new admin_setting_configtext(
        'quiz_livemonitor/refreshinterval',
        get_string('setting_refreshinterval', 'quiz_livemonitor'),
        get_string('setting_refreshinterval_desc', 'quiz_livemonitor'),
        20,
        PARAM_INT
    ));

    // How recent (seconds) server-side activity must be to count an attempt as "active".
    // Must stay comfortably above the quiz module's autosave period, or the status flickers;
    // quiz_livemonitor\local\settings::active_window() raises values that are too low.
    $settings->add(new admin_setting_configtext(
        'quiz_livemonitor/activewindow',
        get_string('setting_activewindow', 'quiz_livemonitor'),
        get_string('setting_activewindow_desc', 'quiz_livemonitor'),
        \quiz_livemonitor\local\settings::DEFAULT_ACTIVE_WINDOW,
        PARAM_INT
    ));
}
