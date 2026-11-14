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
namespace block_fredkiteamagents\external;

use block_fredkiteamagents\local\agents;
use block_fredkiteamagents\local\responder;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Let an agent answer a post right away, while the person waits in the learning space.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_now extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'postid' => new external_value(PARAM_INT, 'Post to answer'),
            'agentid' => new external_value(PARAM_INT, 'Agent id'),
        ]);
    }

    /**
     * Write the answer now.
     *
     * @param int $postid Post id.
     * @param int $agentid Agent id.
     * @return array
     */
    public static function execute(int $postid, int $agentid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['postid' => $postid, 'agentid' => $agentid]);
        $post = $DB->get_record('forum_posts', ['id' => $params['postid'], 'deleted' => 0], '*', MUST_EXIST);
        $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $discussion->forum, $discussion->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/forum:viewdiscussion', $context);
        require_capability('block/fredkiteamagents:convene', \context_course::instance($discussion->course));
        $agent = $DB->get_record(agents::TABLE, ['id' => $params['agentid'], 'courseid' => $discussion->course], '*', MUST_EXIST);
        \core_php_time_limit::raise(360);
        // Free the session while the agent thinks: otherwise every other page of this person waits for the answer.
        \core\session\manager::write_close();
        $diagnostic = '';
        try {
            $answer = responder::answer_now($agent, (int)$post->id, 'mention');
            $reason = $answer ? '' : (responder::$refusal ?: 'busy');
        } catch (\Throwable $e) {
            // FredKI or the licence server did not answer. Teachers get the safe diagnostic code.
            $answer = 0;
            $reason = 'error';
            if (
                $e instanceof \moodle_exception && preg_match('/^DIAG-[A-Za-z0-9\/_ -]{2,120}$/', (string)$e->debuginfo)
                    && has_capability('moodle/course:update', \context_course::instance($discussion->course))
            ) {
                $diagnostic = (string)$e->debuginfo;
            }
        }
        return ['answerid' => $answer, 'discussionid' => (int)$discussion->id, 'reason' => $reason,
            'diagnostic' => $diagnostic];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'answerid' => new external_value(PARAM_INT, 'Post id of the answer, 0 if the agent did not answer'),
            'discussionid' => new external_value(PARAM_INT, 'Discussion id'),
            'reason' => new external_value(
                PARAM_ALPHA,
                'Why there is no answer: paused, notperson, already, limit, busy, error',
                VALUE_DEFAULT,
                ''
            ),
            'diagnostic' => new external_value(PARAM_TEXT, 'Diagnostic code for teachers', VALUE_DEFAULT, ''),
        ]);
    }
}
