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
 * Add, pause, retry or remove an agent of a course.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use block_fredkiteamagents\local\agents;
use block_fredkiteamagents\local\foldersync;

$courseid = required_param('courseid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$agentid = optional_param('agentid', 0, PARAM_INT);
$template = optional_param('template', '', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
require_login($course);
require_sesskey();
$context = context_course::instance($course->id);
require_capability('block/fredkiteamagents:manage', $context);

$return = new moodle_url('/course/view.php', ['id' => $course->id]);
$agent = null;
if ($agentid) {
    $agent = $DB->get_record(agents::TABLE, ['id' => $agentid, 'courseid' => $course->id], '*', MUST_EXIST);
}

try {
    switch ($action) {
        case 'add':
            if (!\block_fredkiteamagents\local\gateway::is_ready()) {
                throw new moodle_exception('error_notconnected', 'block_fredkiteamagents');
            }
            agents::add((int)$course->id, $template);
            $message = '';
            break;
        case 'pause':
        case 'resume':
            agents::set_active((int)$agent->id, $action === 'resume');
            $message = get_string($action === 'resume' ? 'resumed' : 'paused', 'block_fredkiteamagents');
            break;
        case 'retry':
            agents::queue_provision((int)$agent->id);
            $message = '';
            break;
        case 'sync':
            $DB->set_field('block_fredkiteamagents_file', 'attempts', 0, ['agentid' => $agent->id]);
            $DB->set_field('block_fredkiteamagents_file', 'status', 'pending', ['agentid' => $agent->id, 'status' => 'error']);
            foldersync::queue((int)$agent->id);
            $message = get_string('syncqueued', 'block_fredkiteamagents');
            break;
        case 'limit':
            $limit = required_param('limit', PARAM_INT);
            if ($limit < 0 || $limit > 200) {
                throw new moodle_exception('invalidaction');
            }
            $DB->set_field(agents::TABLE, 'dailylimit', $limit, ['id' => $agent->id]);
            $message = '';
            break;
        case 'templates':
            // Ask the licence server now which templates exist in FredKI.
            \block_fredkiteamagents\local\templates::refresh(true);
            $message = '';
            break;
        case 'remove':
            agents::remove((int)$agent->id);
            $message = '';
            break;
        case 'forget':
            // The removal in FredKI keeps failing: leave FredKI as it is and free the place in this course.
            agents::forget((int)$agent->id);
            $message = get_string('forgotten', 'block_fredkiteamagents');
            break;
        default:
            throw new moodle_exception('invalidaction');
    }
    if ($message === '') {
        // The block shows what happens and updates itself; no notice that would outlive the change.
        redirect($return);
    }
    redirect($return, $message, null, \core\output\notification::NOTIFY_SUCCESS);
} catch (moodle_exception $e) {
    $diag = !empty($e->debuginfo) && str_starts_with((string)$e->debuginfo, 'DIAG-');
    $detail = $e->getMessage() . ($diag ? ' (' . $e->debuginfo . ')' : '');
    redirect(
        $return,
        get_string('error_action', 'block_fredkiteamagents', $detail),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
