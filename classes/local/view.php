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

namespace block_fredkiteamagents\local;

/**
 * Data for the block: agents of the course and templates still available.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /**
     * Export for the template block_fredkiteamagents/manage.
     *
     * @param int $courseid Course id.
     * @return array
     */
    public static function export(int $courseid): array {
        $action = (new \moodle_url('/blocks/fredkiteamagents/manage.php'))->out(false);
        // Templates are added or removed in FredKI; the licence server tells which ones exist. A scheduled task asks
        // every hour, so the course page never waits for the licence server. Only the very first time is asked here.
        if (!get_config('block_fredkiteamagents', 'templatesat')) {
            templates::refresh();
        }
        $agents = [];
        $used = [];
        // Trainers see an agent leave at once; only site admins follow a removal that still runs in FredKI.
        $admin = has_capability('moodle/site:config', \context_system::instance());
        foreach (agents::for_course($courseid) as $record) {
            if ($record->status === 'removing' && !$admin) {
                continue;
            }
            $agent = agents::export($record);
            $counts = foldersync::counts((int)$record->id);
            $agent['files'] = $counts['synced'] === 1 ? get_string('filecountone', 'block_fredkiteamagents')
                : get_string('filecount', 'block_fredkiteamagents', $counts['synced']);
            $agent['hasfailed'] = $counts['failed'] > 0;
            $agent['failed'] = get_string('filefailed', 'block_fredkiteamagents', $counts['failed']);
            $today = responder::answers_today((int)$record->id);
            $limit = (int)$record->dailylimit;
            $agent['today'] = $limit > 0
                ? get_string('answerstoday', 'block_fredkiteamagents', ['count' => $today, 'limit' => $limit])
                : get_string('answerstodaynolimit', 'block_fredkiteamagents', $today);
            $agent['todayshort'] = get_string(
                $limit > 0 ? 'todayshortlimit' : 'todayshort',
                'block_fredkiteamagents',
                ['count' => $today, 'limit' => $limit]
            );
            $agent['limitreached'] = $limit > 0 && $today >= $limit;
            $values = array_values(array_unique(array_merge([6, 12, 24, 50], $limit > 0 ? [$limit] : [])));
            sort($values);
            $values[] = 0;
            $agent['limits'] = array_map(static fn(int $n) => ['value' => $n, 'selected' => $n === $limit,
                'label' => $n > 0 ? (string)$n : get_string('unlimited', 'block_fredkiteamagents')], $values);
            $agent['iserror'] = $record->status === 'error';
            $agent['isbuilding'] = in_array($record->status, ['new', 'building'], true);
            $agent['isremoving'] = $record->status === 'removing';
            $agent['removeerror'] = $agent['isremoving'] && !empty($record->lasterror) ? s((string)$record->lasterror) : '';
            $agent['error'] = '';
            $agent['errorcode'] = '';
            if ($record->status === 'error') {
                $code = (string)$record->lasterror;
                $agent['errorcode'] = s($code);
                if (str_contains($code, 'FK-TEAM-PLAN')) {
                    $agent['error'] = get_string('error_teamplan', 'block_fredkiteamagents');
                } else if (str_contains($code, 'FK-TEAM-TEMPLATE')) {
                    $agent['error'] = get_string('error_template_missing', 'block_fredkiteamagents');
                } else if (str_contains($code, 'FK-TEAM-ALLOCATION') || str_contains($code, 'FK-TEAM-FREDKI-CONFIG')) {
                    $agent['error'] = get_string('error_server_setup', 'block_fredkiteamagents');
                } else if (str_contains($code, 'FK-TEAM-')) {
                    $agent['error'] = get_string('error_provision_step', 'block_fredkiteamagents');
                } else if (str_contains($code, '-HTTP-404')) {
                    $agent['error'] = get_string('error_routemissing', 'block_fredkiteamagents');
                } else if (
                    str_contains($code, '-HTTP-NETWORK')
                        || (preg_match('/-HTTP-5\d\d$/', $code) && !str_contains($code, ' FK-'))
                ) {
                    // No answer, or an answer without an error code: the server itself is unavailable.
                    $agent['error'] = get_string('error_unreachable', 'block_fredkiteamagents');
                } else if (str_contains($code, '-HTTP-401') || str_contains($code, '-HTTP-403')) {
                    $agent['error'] = get_string('error_denied', 'block_fredkiteamagents');
                } else {
                    $agent['error'] = get_string('error_provision', 'block_fredkiteamagents');
                }
            }
            $agent['folderurl'] = $record->cmid
                ? (new \moodle_url('/mod/folder/view.php', ['id' => $record->cmid]))->out(false) : '';
            $agents[] = $agent;
            $used[$record->template] = true;
        }
        $available = [];
        foreach (templates::addable() as $key => $template) {
            if (empty($used[$key])) {
                unset($template['imagefile']);
                $available[] = $template;
            }
        }
        $teamplan = gateway::team_plan();
        // The learning companion and the expert round appear in the course format FredKI learning space.
        $course = get_course($courseid);
        $needsformat = $course->format !== 'fredki';
        $formaturl = $needsformat && has_capability('moodle/course:update', \context_course::instance($courseid))
            ? (new \moodle_url('/course/edit.php', ['id' => $courseid]))->out(false) : '';
        return [
            'needsformat' => $needsformat,
            'formaturl' => $formaturl,
            'teamplanrefused' => $teamplan['refused'],
            'teamplanurl' => $teamplan['url'],
            'courseid' => $courseid,
            'sesskey' => sesskey(),
            'action' => $action,
            'connected' => gateway::is_ready(),
            'setupurl' => has_capability('moodle/site:config', \context_system::instance())
                ? (new \moodle_url('/blocks/fredkiteamagents/setup.php'))->out(false) : '',
            'agents' => $agents,
            'hasagents' => !empty($agents),
            'templates' => $available,
            'hastemplates' => !empty($available),
            'pending' => agents::pending($courseid),
        ];
    }
}
