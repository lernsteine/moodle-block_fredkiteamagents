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

namespace block_fredkiteamagents\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider. The plugin stores no personal data of people; it sends conversation text without names
 * to the FredKI licence server.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the data.
     *
     * @param collection $collection Collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('licenceserver', [
            'subject' => 'privacy:metadata:licenceserver:subject',
            'message' => 'privacy:metadata:licenceserver:message',
            'files' => 'privacy:metadata:licenceserver:files',
        ], 'privacy:metadata:licenceserver');
        return $collection;
    }

    /**
     * No stored personal data.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * No stored personal data.
     *
     * @param userlist $userlist User list.
     */
    public static function get_users_in_context(userlist $userlist) {
    }

    /**
     * No stored personal data.
     *
     * @param approved_contextlist $contextlist Contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
    }

    /**
     * No stored personal data.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
    }

    /**
     * No stored personal data.
     *
     * @param approved_contextlist $contextlist Contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
    }

    /**
     * No stored personal data.
     *
     * @param approved_userlist $userlist Users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
    }
}
