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

/**
 * Resolves the plugin's settings, including the values they cannot sensibly fall below.
 *
 * Both the page and the polling web service need the same numbers, and the active window in
 * particular cannot be taken at face value: it is bounded from below by how often the server
 * hears from a student at all.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings {
    /** @var int seconds between polls when unset. */
    public const DEFAULT_REFRESH_INTERVAL = 20;

    /** @var int seconds of silence before an attempt reads as idle, when unset. */
    public const DEFAULT_ACTIVE_WINDOW = 150;

    /** @var int headroom added on top of the autosave period and one poll. */
    public const ACTIVE_WINDOW_HEADROOM = 30;

    /**
     * How often the supervising browser asks for a fresh snapshot.
     *
     * @return int seconds.
     */
    public static function refresh_interval(): int {
        return (int) (get_config('quiz_livemonitor', 'refreshinterval') ?: self::DEFAULT_REFRESH_INTERVAL);
    }

    /**
     * The quiz module's autosave period, which is what limits how fresh our data can be.
     *
     * @return int seconds, or 0 when autosave is switched off site-wide.
     */
    public static function autosave_period(): int {
        return (int) get_config('quiz', 'autosaveperiod');
    }

    /**
     * Whether the quiz module writes no autosaves at all.
     *
     * @return bool
     */
    public static function autosave_disabled(): bool {
        return self::autosave_period() === 0;
    }

    /**
     * How recent activity must be for an attempt to count as active.
     *
     * The configured value is raised to a floor, because a window shorter than the interval at
     * which the server hears anything makes the status flicker between active and idle for a
     * student who is working continuously. The browser's autosave is a debounce, not a timer:
     * changes made while it is pending do not restart it, so two writes are one autosave period
     * apart at best. Add a poll interval and some headroom and that is the shortest window that
     * can hold steady.
     *
     * No ceiling is applied. A site with a ten minute autosave period gets a very coarse
     * window, which is unhelpful but honest -- the server knows nothing more precise.
     *
     * @return int seconds.
     */
    public static function active_window(): int {
        $configured = (int) (get_config('quiz_livemonitor', 'activewindow') ?: self::DEFAULT_ACTIVE_WINDOW);

        $autosave = self::autosave_period();
        if ($autosave <= 0) {
            // No autosaves exist, so there is nothing to stay in step with.
            return $configured;
        }

        return max($configured, $autosave + self::refresh_interval() + self::ACTIVE_WINDOW_HEADROOM);
    }
}
