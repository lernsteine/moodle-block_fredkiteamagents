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
 * Site settings of FredKI Team Agents.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Checklist for the setup: activation, package, first course.
$ADMIN->add('blocksettings', new admin_externalpage(
    'block_fredkiteamagents_setup',
    get_string('setup_title', 'block_fredkiteamagents'),
    new moodle_url('/blocks/fredkiteamagents/setup.php'),
    'moodle/site:config'
));

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'block_fredkiteamagents/setupheading',
        get_string('setup_title', 'block_fredkiteamagents'),
        get_string('setup_settingslink', 'block_fredkiteamagents', (new moodle_url('/blocks/fredkiteamagents/setup.php'))->out())
    ));
    $choices = [];
    foreach ([0, 30, 60, 120, 180, 300] as $seconds) {
        $choices[$seconds] = $seconds === 0 ? get_string('answerdelay_none', 'block_fredkiteamagents')
            : format_time($seconds);
    }
    $settings->add(new admin_setting_configselect(
        'block_fredkiteamagents/answerdelay',
        get_string('answerdelay', 'block_fredkiteamagents'),
        get_string('answerdelay_desc', 'block_fredkiteamagents'),
        120,
        $choices
    ));
}
