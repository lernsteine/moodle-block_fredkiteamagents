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

namespace block_fredkiteamagents\external;

use block_fredkiteamagents\local\responder;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Ask all active agents of the course for their view on a conversation.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class convene extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'discussionid' => new external_value(PARAM_INT, 'Discussion id'),
        ]);
    }

    /**
     * Queue the answers.
     *
     * @param int $discussionid Discussion id.
     * @return array
     */
    public static function execute(int $discussionid): array {
        global $DB;
        ['discussionid' => $discussionid] = self::validate_parameters(
            self::execute_parameters(),
            ['discussionid' => $discussionid]
        );
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $discussion->forum, $discussion->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('block/fredkiteamagents:convene', \context_course::instance($discussion->course));
        require_capability('mod/forum:viewdiscussion', $context);
        return ['count' => responder::convene($discussionid)];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Number of agents that will answer'),
        ]);
    }
}
