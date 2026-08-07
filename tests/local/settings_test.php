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

namespace quiz_livemonitor\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the settings resolver, in particular the active-window floor.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_livemonitor\local\settings::class)]
final class settings_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The window may never be shorter than the interval at which the server hears anything.
     *
     * This is what repairs sites that were installed before the default was raised: they hold
     * 60 in the database, and changing the default in settings.php would never reach them.
     */
    public function test_the_active_window_is_raised_above_the_autosave_period(): void {
        set_config('autosaveperiod', 60, 'quiz');
        set_config('refreshinterval', 20, 'quiz_livemonitor');
        set_config('activewindow', 60, 'quiz_livemonitor');

        // 60 + 20 + 30 headroom.
        $this->assertSame(110, settings::active_window());
    }

    public function test_a_generous_configured_window_is_left_alone(): void {
        set_config('autosaveperiod', 60, 'quiz');
        set_config('refreshinterval', 20, 'quiz_livemonitor');
        set_config('activewindow', 300, 'quiz_livemonitor');

        $this->assertSame(300, settings::active_window());
    }

    public function test_the_floor_follows_a_longer_autosave_period(): void {
        set_config('autosaveperiod', 600, 'quiz');
        set_config('refreshinterval', 20, 'quiz_livemonitor');
        set_config('activewindow', 150, 'quiz_livemonitor');

        // No ceiling: a coarse window is unhelpful but honest.
        $this->assertSame(650, settings::active_window());
    }

    public function test_without_autosave_the_configured_window_stands(): void {
        set_config('autosaveperiod', 0, 'quiz');
        set_config('activewindow', 60, 'quiz_livemonitor');

        $this->assertTrue(settings::autosave_disabled());
        $this->assertSame(60, settings::active_window(), 'no autosaves means nothing to stay in step with');
    }

    public function test_unset_values_fall_back_to_the_documented_defaults(): void {
        unset_config('activewindow', 'quiz_livemonitor');
        unset_config('refreshinterval', 'quiz_livemonitor');
        set_config('autosaveperiod', 0, 'quiz');

        $this->assertSame(settings::DEFAULT_ACTIVE_WINDOW, settings::active_window());
        $this->assertSame(settings::DEFAULT_REFRESH_INTERVAL, settings::refresh_interval());
    }
}
