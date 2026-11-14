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

namespace block_fredkiteamagents\task;
namespace block_fredkiteamagents\task;

use block_fredkiteamagents\local\responder;

/**
 * Every minute: find recent posts that mention an agent and let the agent answer.
 *
 * The observer reacts when a post is saved. This task is the second way, for posts the observer did not see
 * (e.g. written through the app, by e-mail, or while the agent was being built). An agent never answers a post twice.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class find_mentions extends \core\task\scheduled_task {
    /** @var int Posts of the last minutes are checked. */
    const WINDOW = 30 * MINSECS;

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_mentions', 'block_fredkiteamagents');
    }

    /**
     * Run the task.
     */
    public function execute() {
        mtrace('Team agents: ' . responder::find_mentions(self::WINDOW) . ' answers queued');
    }
}
