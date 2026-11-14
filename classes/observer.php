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

namespace block_fredkiteamagents;

use block_fredkiteamagents\local\agents;
use block_fredkiteamagents\local\foldersync;
use block_fredkiteamagents\local\responder;

/**
 * Event observers: transfer knowledge folders, let agents answer when someone writes @Name.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Agent whose knowledge folder is the course module of an event.
     *
     * @param \core\event\base $event Event.
     * @return \stdClass|null
     */
    protected static function agent_of_module(\core\event\base $event): ?\stdClass {
        global $DB;
        $cmid = $event->contextlevel == CONTEXT_MODULE ? (int)$event->contextinstanceid : 0;
        if ($event instanceof \core\event\course_module_updated || $event instanceof \core\event\course_module_deleted) {
            $cmid = (int)$event->objectid;
        }
        if ($cmid <= 0 || empty($event->courseid)) {
            return null;
        }
        return $DB->get_record(agents::TABLE, ['courseid' => $event->courseid, 'cmid' => $cmid]) ?: null;
    }

    /**
     * A knowledge folder changed: transfer it shortly after, once for many changes.
     *
     * @param \core\event\base $event Event.
     */
    public static function folder_changed(\core\event\base $event): void {
        $agent = self::agent_of_module($event);
        if ($agent && $agent->status === 'ready') {
            foldersync::queue((int)$agent->id);
        }
    }

    /**
     * A knowledge folder was deleted: the agent keeps working with what FredKI already has.
     *
     * @param \core\event\base $event Event.
     */
    public static function module_deleted(\core\event\base $event): void {
        global $DB;
        $agent = self::agent_of_module($event);
        if ($agent) {
            $DB->set_field(agents::TABLE, 'cmid', 0, ['id' => $agent->id]);
        }
    }

    /**
     * A person mentions an agent: that agent answers in the conversation.
     *
     * @param \core\event\base $event Event.
     */
    public static function post_created(\core\event\base $event): void {
        global $DB;
        if (empty($event->courseid) || !agents::speaking((int)$event->courseid)) {
            return;
        }
        $postid = $event instanceof \mod_forum\event\discussion_created
            ? (int)$DB->get_field('forum_discussions', 'firstpost', ['id' => $event->objectid])
            : (int)$event->objectid;
        $post = $postid ? $DB->get_record('forum_posts', ['id' => $postid]) : null;
        if (!$post || !responder::by_person($post)) {
            return;
        }
        foreach (responder::mentioned((int)$event->courseid, (string)$post->message) as $agent) {
            responder::queue($agent, $postid, 'mention', (int)$post->created + responder::answer_delay());
        }
    }

    /**
     * A course was deleted: forget its agents. Their FredKI agents are removed by the licence server with the course.
     *
     * @param \core\event\base $event Event.
     */
    public static function course_deleted(\core\event\base $event): void {
        global $DB;
        $ids = $DB->get_fieldset_select(agents::TABLE, 'id', 'courseid = ?', [$event->objectid]);
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('block_fredkiteamagents_file', "agentid $insql", $params);
            $DB->delete_records_select('block_fredkiteamagents_post', "agentid $insql", $params);
            $DB->delete_records(agents::TABLE, ['courseid' => $event->objectid]);
        }
    }
}
