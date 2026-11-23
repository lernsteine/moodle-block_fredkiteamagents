// This file is part of Moodle - https://moodle.org/
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
 * While an agent is built or removed, ask every few seconds and show the new state in the block.
 *
 * @module     block_fredkiteamagents/manage
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('block_fredkiteamagents/manage', ['core/ajax', 'core/templates'], function(Ajax, Templates) {
    'use strict';

    /** @var {Number} Seconds between two checks. */
    var INTERVAL = 4000;

    /** @var {Number} Checks before giving up (about 15 minutes). */
    var MAXTRIES = 225;

    /** @var {Object} Open details by agent id, kept when the block is drawn again. */
    var open = {};

    /**
     * Show or hide the details of an agent.
     *
     * @param {HTMLElement} button
     * @param {Boolean} show
     */
    var setOpen = function(button, show) {
        var panel = document.getElementById(button.getAttribute('aria-controls'));
        if (!panel) {
            return;
        }
        panel.hidden = !show;
        button.setAttribute('aria-expanded', show ? 'true' : 'false');
        open[button.closest('[data-agentid]').dataset.agentid] = show;
    };

    /**
     * Open again what was open before the block was drawn again.
     *
     * @param {HTMLElement} root
     */
    var restore = function(root) {
        root.querySelectorAll('[data-action="fta-info"]').forEach(function(button) {
            if (open[button.closest('[data-agentid]').dataset.agentid]) {
                setOpen(button, true);
            }
        });
    };

    var bound = false;

    return {
        /**
         * Start the block: details behind the info button; while an agent is built or removed, check every few seconds.
         *
         * @param {Number} courseid Course id.
         * @param {Boolean} pending Whether something is in progress.
         */
        init: function(courseid, pending) {
            var selector = '[data-region="fredkiteamagents"][data-courseid="' + courseid + '"]';
            if (!bound) {
                bound = true;
                document.addEventListener('click', function(e) {
                    var button = e.target.closest('[data-action="fta-info"]');
                    if (button && button.closest('[data-region="fredkiteamagents"]')) {
                        setOpen(button, button.getAttribute('aria-expanded') !== 'true');
                    }
                });
            }
            if (!pending) {
                return;
            }
            var tries = 0;
            var check = function() {
                tries++;
                var latest = null;
                Ajax.call([{methodname: 'block_fredkiteamagents_status', args: {courseid: courseid}}])[0]
                    .then(function(result) {
                        latest = result;
                        if (!document.querySelector(selector)) {
                            return null;
                        }
                        return Templates.renderForPromise('block_fredkiteamagents/manage', JSON.parse(result.data));
                    })
                    .then(function(rendered) {
                        var root = document.querySelector(selector);
                        if (!rendered || !root) {
                            return null;
                        }
                        var nodes = Templates.replaceNode(root, rendered.html, rendered.js);
                        var fresh = nodes && nodes[0] ? nodes[0] : document.querySelector(selector);
                        if (fresh && fresh.querySelectorAll) {
                            restore(fresh);
                        }
                        if (latest.pending && tries < MAXTRIES) {
                            window.setTimeout(check, INTERVAL);
                        }
                        return null;
                    })
                    .catch(function() {
                        if (tries < MAXTRIES) {
                            window.setTimeout(check, INTERVAL * 2);
                        }
                    });
            };
            window.setTimeout(check, INTERVAL);
        }
    };
});
