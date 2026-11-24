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
 * Backup task of FredKI Team Agents.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 LERNSTEINE
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/fredkiteamagents/backup/moodle2/backup_fredkiteamagents_stepslib.php');

/**
 * Backs up which Team Agents take part in the course (template, active, daily limit).
 */
class backup_fredkiteamagents_block_task extends backup_block_task {
    /**
     * No settings.
     */
    protected function define_my_settings() {
    }

    /**
     * One structure step with the agents of the course.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_fredkiteamagents_block_structure_step('fredkiteamagents_structure', 'fredkiteamagents.xml'));
    }

    /**
     * No file areas of the block itself (the knowledge folders are ordinary course activities).
     *
     * @return array
     */
    public function get_fileareas() {
        return [];
    }

    /**
     * No encoded configuration attributes.
     *
     * @return array
     */
    public function get_configdata_encoded_attributes() {
        return [];
    }

    /**
     * No links to encode.
     *
     * @param string $content Content.
     * @return string
     */
    public static function encode_content_links($content) {
        return $content;
    }
}
