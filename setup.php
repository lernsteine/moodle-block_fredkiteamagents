<?php
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
 * Setup checklist of FredKI Team Agents for site administrators.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_fredkiteamagents\local\gateway;

admin_externalpage_setup('block_fredkiteamagents_setup');

$status = gateway::site_status();
$activation = new moodle_url('/blocks/fredkitheagent/activation.php');
$step1 = $status['reachable'] && $status['active'];
$step2 = $step1 && $status['team'] !== false;

$steps = [
    [
        'number' => 1,
        'title' => get_string('setup_step1', 'block_fredkiteamagents'),
        'done' => $step1,
        'text' => get_string($step1 ? 'setup_step1_done' : 'setup_step1_todo', 'block_fredkiteamagents'),
        'url' => $step1 ? '' : $activation->out(false),
        'label' => get_string('setup_step1_button', 'block_fredkiteamagents'),
    ],
    [
        'number' => 2,
        'title' => get_string('setup_step2', 'block_fredkiteamagents'),
        'done' => $step2,
        'text' => get_string(
            !$step1 ? 'setup_step2_wait' : ($status['team'] === false ? 'setup_step2_todo' : 'setup_step2_done'),
            'block_fredkiteamagents'
        ),
        'url' => $step1 && $status['team'] === false ? $activation->out(false) : '',
        'label' => get_string('teamplan_upgrade', 'block_fredkiteamagents'),
        'external' => false,
    ],
    [
        'number' => 3,
        'title' => get_string('setup_step3', 'block_fredkiteamagents'),
        'done' => false,
        'next' => true,
        'text' => get_string('setup_step3_text', 'block_fredkiteamagents'),
        'url' => '',
        'label' => '',
    ],
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_fredkiteamagents/setup', [
    'intro' => get_string('setup_intro', 'block_fredkiteamagents'),
    'steps' => $steps,
    'unreachable' => gateway::is_ready() && !$status['reachable'],
]);
echo $OUTPUT->footer();
