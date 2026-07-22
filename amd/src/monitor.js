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
 * Live monitor: polls a progress snapshot and re-renders the body region.
 *
 * @module     quiz_livemonitor/monitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    region: '[data-region="livemonitor-body"]',
    toggle: '[data-action="livemonitor-toggle"]',
    refresh: '[data-action="livemonitor-refresh"]',
};

let cmid = null;
let intervalMs = 20000;
let timer = null;
let paused = false;
let inflight = false;

/**
 * Fetch a fresh snapshot and re-render the body region.
 *
 * @returns {Promise<void>}
 */
const refresh = async() => {
    if (inflight) {
        return;
    }
    const region = document.querySelector(SELECTORS.region);
    if (!region) {
        return;
    }
    inflight = true;
    try {
        const response = await Ajax.call([{
            methodname: 'quiz_livemonitor_get_progress',
            args: {cmid: cmid},
        }])[0];
        const {html, js} = await Templates.renderForPromise('quiz_livemonitor/monitor_body', response);
        Templates.replaceNodeContents(region, html, js);
    } catch (error) {
        Notification.exception(error);
    } finally {
        inflight = false;
    }
};

/**
 * Toggle auto-refresh on/off and update the button label.
 *
 * @param {HTMLElement} button the toggle button.
 * @returns {Promise<void>}
 */
const togglePause = async(button) => {
    paused = !paused;
    const key = paused ? 'resume' : 'pause';
    button.textContent = await getString(key, 'quiz_livemonitor');
    if (!paused) {
        refresh();
    }
};

/**
 * Initialise the live monitor.
 *
 * @param {number} cmidParam course module id.
 * @param {number} intervalSeconds poll interval in seconds.
 */
export const init = (cmidParam, intervalSeconds) => {
    cmid = parseInt(cmidParam, 10);
    intervalMs = Math.max(5, parseInt(intervalSeconds, 10) || 20) * 1000;

    document.addEventListener('click', (e) => {
        const toggle = e.target.closest(SELECTORS.toggle);
        if (toggle) {
            e.preventDefault();
            togglePause(toggle);
            return;
        }
        const refreshBtn = e.target.closest(SELECTORS.refresh);
        if (refreshBtn) {
            e.preventDefault();
            refresh();
        }
    });

    timer = setInterval(() => {
        if (!paused && !document.hidden) {
            refresh();
        }
    }, intervalMs);
};
