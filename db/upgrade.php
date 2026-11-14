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
 * Upgrade steps.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the block.
 *
 * @param int $oldversion Version before the upgrade.
 * @return bool
 */
function xmldb_block_fredkiteamagents_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();
    if ($oldversion < 2026110412) {
        // Agents answer without a daily limit unless the course sets one; 6 was the old default.
        $table = new xmldb_table('block_fredkiteamagents_agent');
        $field = new xmldb_field('dailylimit', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'active');
        $dbman->change_field_default($table, $field);
        $DB->set_field('block_fredkiteamagents_agent', 'dailylimit', 0, ['dailylimit' => 6]);
        upgrade_block_savepoint(true, 2026110412, 'fredkiteamagents');
    }
    if ($oldversion < 2026110415) {
        // Drawn portraits instead of landscapes: give the existing agent accounts their new pictures.
        \block_fredkiteamagents\local\agents::refresh_pictures();
        upgrade_block_savepoint(true, 2026110415, 'fredkiteamagents');
    }
    if ($oldversion < 2026110416) {
        // Portrait photos: the existing agent accounts get them as profile pictures.
        \block_fredkiteamagents\local\agents::refresh_pictures();
        upgrade_block_savepoint(true, 2026110416, 'fredkiteamagents');
    }
    if ($oldversion < 2026110417) {
        // Template "Der Praktiker" is now "Die Praktikerin" with a new portrait; "Die Stimme der Zielgruppe" is no longer offered.
        \block_fredkiteamagents\local\agents::refresh_pictures();
        upgrade_block_savepoint(true, 2026110417, 'fredkiteamagents');
    }
    if ($oldversion < 2026110418) {
        // All agents are women now and carry no article: "Kritikerin", "Supervisorin", … New portraits.
        \block_fredkiteamagents\local\agents::refresh_pictures();
        upgrade_block_savepoint(true, 2026110418, 'fredkiteamagents');
    }
    if ($oldversion < 2026110500) {
        // Templates now come from FredKI via the licence server; ask at the next view of the block.
        unset_config('templatesat', 'block_fredkiteamagents');
        upgrade_block_savepoint(true, 2026110500, 'fredkiteamagents');
    }
    if ($oldversion < 2026110507) {
        // Bilingual descriptions were cut inside their tags; ask the licence server again at the next view.
        unset_config('templatesat', 'block_fredkiteamagents');
        upgrade_block_savepoint(true, 2026110507, 'fredkiteamagents');
    }
    return true;
}
