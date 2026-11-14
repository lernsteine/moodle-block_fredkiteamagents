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
 * Block FredKI Team Agents: add AI agents to a course as participants with their own knowledge folder.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_fredkiteamagents extends block_base {
    /**
     * Init.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_fredkiteamagents');
    }

    /**
     * Where the block may be added.
     *
     * @return array
     */
    public function applicable_formats() {
        return ['course-view' => true, 'site' => false, 'my' => false, 'mod' => false];
    }

    /**
     * One block per course.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Content: only for people who manage agents.
     *
     * @return stdClass
     */
    public function get_content() {
        global $OUTPUT;
        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = (object)['text' => '', 'footer' => ''];
        $course = $this->page->course;
        if (empty($course->id) || $course->id == SITEID) {
            return $this->content;
        }
        $context = context_course::instance($course->id);
        if (!has_capability('block/fredkiteamagents:manage', $context)) {
            return $this->content;
        }
        $data = \block_fredkiteamagents\local\view::export((int)$course->id);
        $this->content->text = $OUTPUT->render_from_template('block_fredkiteamagents/manage', $data);
        // Details behind the info button; while an agent is built or removed, the block asks every few seconds.
        $this->page->requires->js_call_amd('block_fredkiteamagents/manage', 'init', [(int)$course->id, (bool)$data['pending']]);
        return $this->content;
    }

    /**
     * The block has site settings (waiting time before agents answer).
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }
}
