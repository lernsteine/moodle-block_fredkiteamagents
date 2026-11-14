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
 * Web service functions used by the learning space.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_fredkiteamagents_convene' => [
        'classname' => '\block_fredkiteamagents\external\convene',
        'description' => 'Ask all active agents of the course for their view on a conversation.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/fredkiteamagents:convene',
    ],
    'block_fredkiteamagents_answer_now' => [
        'classname' => '\block_fredkiteamagents\external\answer_now',
        'description' => 'Let an agent answer a post right away.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/fredkiteamagents:convene',
    ],
    'block_fredkiteamagents_schedule' => [
        'classname' => '\\block_fredkiteamagents\\external\\schedule',
        'description' => 'Order answers of agents to a new post, written after a short waiting time.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/fredkiteamagents:convene',
    ],
    'block_fredkiteamagents_status' => [
        'classname' => '\block_fredkiteamagents\external\status',
        'description' => 'Current state of the agents of a course, for the block.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/fredkiteamagents:manage',
    ],
    'block_fredkiteamagents_set_active' => [
        'classname' => '\block_fredkiteamagents\external\set_active',
        'description' => 'Pause an agent or bring it back.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/fredkiteamagents:manage',
    ],
];
