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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Pause an agent or bring it back.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_active extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'agentid' => new external_value(PARAM_INT, 'Agent id'),
            'active' => new external_value(PARAM_BOOL, 'Whether the agent takes part'),
        ]);
    }

    /**
     * Change the state.
     *
     * @param int $agentid Agent id.
     * @param bool $active Active.
     * @return array
     */
    public static function execute(int $agentid, bool $active): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['agentid' => $agentid, 'active' => $active]);
        $agent = $DB->get_record(agents::TABLE, ['id' => $params['agentid']], '*', MUST_EXIST);
        $context = \context_course::instance($agent->courseid);
        self::validate_context($context);
        require_capability('block/fredkiteamagents:manage', $context);
        agents::set_active((int)$agent->id, $params['active']);
        return ['active' => $params['active']];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'active' => new external_value(PARAM_BOOL, 'New state'),
        ]);
    }
}
