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
 * Backup structure of FredKI Team Agents.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 LERNSTEINE
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Stores the agents of the course: template, whether they take part and their daily limit.
 *
 * The ids of agent and knowledge base in FredKI are not backed up: a restored or duplicated
 * course gets its own agents in FredKI, built from the restored knowledge folders.
 */
class backup_fredkiteamagents_block_structure_step extends backup_block_structure_step {
    /**
     * Define the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $root = new backup_nested_element('fredkiteamagents', ['id'], null);
        $agents = new backup_nested_element('agents');
        $agent = new backup_nested_element('agent', ['id'], ['template', 'active', 'dailylimit']);
        $root->add_child($agents);
        $agents->add_child($agent);

        $root->set_source_array([(object)['id' => $this->task->get_blockid()]]);
        $agent->set_source_sql(
            "SELECT id, template, active, dailylimit
               FROM {block_fredkiteamagents_agent}
              WHERE courseid = ? AND status <> 'removing'
           ORDER BY id",
            [backup::VAR_COURSEID]
        );
        return $this->prepare_block_structure($root);
    }
}
