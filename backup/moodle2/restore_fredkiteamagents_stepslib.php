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
 * Restore structure of FredKI Team Agents.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 LERNSTEINE
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_fredkiteamagents\local\agents;

/**
 * Adds the agents of the backup to the restored course.
 *
 * Each agent is added as if a teacher had added it: it is enrolled, finds its restored
 * knowledge folder and is built in FredKI in the background (own agent and knowledge base).
 */
class restore_fredkiteamagents_block_structure_step extends restore_structure_step {
    /** @var array Agents of the backup (template, active, dailylimit). */
    protected $agents = [];

    /**
     * Backups made before version 1.2.2 have no agents file: then there is nothing to restore.
     *
     * @return bool
     */
    protected function execute_condition() {
        return file_exists(rtrim($this->task->get_taskbasepath(), '/') . '/' . $this->filename);
    }

    /**
     * Define the structure.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        return [new restore_path_element('agent', '/block/fredkiteamagents/agents/agent')];
    }

    /**
     * Remember one agent; it is added after the whole course has been restored.
     *
     * @param array $data Agent data.
     */
    public function process_agent($data) {
        $data = (object)$data;
        $template = clean_param((string)($data->template ?? ''), PARAM_ALPHANUMEXT);
        if ($template !== '') {
            $this->agents[] = (object)[
                'template' => $template,
                'active' => empty($data->active) ? 0 : 1,
                'dailylimit' => max(0, (int)($data->dailylimit ?? 0)),
            ];
        }
    }

    /**
     * Add the agents once the knowledge folders (ordinary course activities) exist again.
     */
    protected function after_restore() {
        global $DB;
        $courseid = (int)$this->get_courseid();
        if (!$this->agents || !$courseid) {
            return;
        }
        rebuild_course_cache($courseid, true);
        foreach ($this->agents as $data) {
            try {
                $agentid = agents::add($courseid, $data->template);
                $DB->set_field('block_fredkiteamagents_agent', 'dailylimit', $data->dailylimit, ['id' => $agentid]);
                if (!$data->active) {
                    agents::set_active($agentid, false);
                }
            } catch (\Throwable $e) {
                // A template that is no longer offered, or FredKI not reachable: the teacher adds it again.
                $message = 'FredKI Team Agents: agent "' . $data->template . '" not restored (' . get_class($e) . ')';
                $this->log($message, backup::LOG_WARNING);
            }
        }
    }
}
