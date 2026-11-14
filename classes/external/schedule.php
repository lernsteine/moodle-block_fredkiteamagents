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

use block_fredkiteamagents\local\agents;
use block_fredkiteamagents\local\responder;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Order answers of agents to a new post. They are written after the waiting time, so the person can still correct
 * the post. The learning space usually lets them answer itself when the time is up; this order is the safety net
 * when the page is closed before.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'postid' => new external_value(PARAM_INT, 'Post to answer'),
            'agentids' => new external_multiple_structure(new external_value(PARAM_INT, 'Agent id')),
        ]);
    }

    /**
     * Queue the answers.
     *
     * @param int $postid Post id.
     * @param array $agentids Agent ids.
     * @return array
     */
    public static function execute(int $postid, array $agentids): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['postid' => $postid, 'agentids' => $agentids]);
        $post = $DB->get_record('forum_posts', ['id' => $params['postid'], 'deleted' => 0], '*', MUST_EXIST);
        $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $discussion->forum, $discussion->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/forum:viewdiscussion', $context);
        require_capability('block/fredkiteamagents:convene', \context_course::instance($discussion->course));
        // Only the author orders answers to a post at the moment of writing it.
        if ((int)$post->userid !== (int)$USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'schedule');
        }
        $delay = responder::answer_delay();
        // A minute more than in the browser: if the page is still open, it lets the agents answer one after another.
        $runat = (int)$post->created + $delay + 60;
        $count = 0;
        foreach (array_unique($params['agentids']) as $agentid) {
            $agent = $DB->get_record(agents::TABLE, ['id' => $agentid, 'courseid' => $discussion->course]);
            if ($agent && responder::queue($agent, (int)$post->id, 'mention', $runat)) {
                $count++;
            }
        }
        return ['count' => $count, 'delay' => $delay];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Answers ordered'),
            'delay' => new external_value(PARAM_INT, 'Seconds before the agents answer'),
        ]);
    }
}
