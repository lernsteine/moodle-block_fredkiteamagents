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
 * Keeps the knowledge base of an agent in step with its Moodle folder.
 *
 * New and changed files are uploaded, removed files are deleted in FredKI. Network failures are retried after
 * 1, 2, 4, 8 and 16 minutes.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class foldersync {
    /** @var string Table of synchronised files. */
    const TABLE = 'block_fredkiteamagents_file';

    /** @var int Attempts before a file is marked as failed. */
    const MAX_ATTEMPTS = 5;

    /**
     * Queue a sync of the folder of an agent.
     *
     * @param int $agentid Agent id.
     * @param int $delay Delay in seconds.
     */
    public static function queue(int $agentid, int $delay = 0): void {
        $task = new \block_fredkiteamagents\task\sync_folder();
        $task->set_custom_data(['agentid' => $agentid]);
        $task->set_component('block_fredkiteamagents');
        if ($delay > 0) {
            $task->set_next_run_time(time() + $delay);
        }
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Synchronise the folder.
     *
     * @param int $agentid Agent id.
     * @return array Counts: synced, deleted, failed, retry.
     */
    public static function run(int $agentid): array {
        global $DB;
        $result = ['synced' => 0, 'deleted' => 0, 'failed' => 0, 'retry' => 0];
        $agent = $DB->get_record(agents::TABLE, ['id' => $agentid]);
        if (!$agent || $agent->status !== 'ready' || empty($agent->cmid)) {
            return $result;
        }
        $files = [];
        $cm = get_coursemodule_from_id('folder', $agent->cmid, $agent->courseid, false, IGNORE_MISSING);
        if ($cm) {
            $context = \context_module::instance($cm->id);
            $areafiles = get_file_storage()->get_area_files($context->id, 'mod_folder', 'content', 0, 'filepath, filename', false);
            foreach ($areafiles as $file) {
                $files[$file->get_pathnamehash()] = $file;
            }
        }
        $known = $DB->get_records(self::TABLE, ['agentid' => $agentid], '', '*');
        $byhash = [];
        foreach ($known as $record) {
            $byhash[$record->pathnamehash] = $record;
        }
        $nextattempt = 0;

        foreach ($files as $hash => $file) {
            $record = $byhash[$hash] ?? null;
            if ($record && $record->status === 'synced' && $record->contenthash === $file->get_contenthash()) {
                continue;
            }
            if ($record && $record->status === 'error' && $record->contenthash === $file->get_contenthash()) {
                continue;
            }
            if (!$record) {
                $record = (object)['agentid' => $agentid, 'pathnamehash' => $hash, 'attempts' => 0, 'status' => 'pending'];
            } else if ($record->contenthash !== $file->get_contenthash()) {
                $record->attempts = 0;
            }
            $record->contenthash = $file->get_contenthash();
            $record->filename = $file->get_filename();
            $record->timemodified = time();
            try {
                $record->documentid = gateway::upload($agent, $file);
                $record->status = 'synced';
                $record->attempts = 0;
                $result['synced']++;
            } catch (transient_exception $e) {
                $record->attempts++;
                $retry = $e->transient && $record->attempts < self::MAX_ATTEMPTS;
                $record->status = $retry ? 'pending' : 'error';
                $result[$retry ? 'retry' : 'failed']++;
                if ($retry) {
                    $wait = MINSECS * (2 ** ($record->attempts - 1));
                    $nextattempt = $nextattempt ? min($nextattempt, $wait) : $wait;
                }
            }
            if (empty($record->id)) {
                $DB->insert_record(self::TABLE, $record);
            } else {
                $DB->update_record(self::TABLE, $record);
            }
        }

        foreach ($byhash as $hash => $record) {
            if (isset($files[$hash])) {
                continue;
            }
            try {
                if (!empty($record->documentid)) {
                    gateway::delete_document($agent, $hash);
                }
                $DB->delete_records(self::TABLE, ['id' => $record->id]);
                $result['deleted']++;
            } catch (transient_exception $e) {
                $result['retry']++;
                $nextattempt = $nextattempt ? min($nextattempt, MINSECS) : MINSECS;
            }
        }

        if ($nextattempt) {
            self::queue($agentid, $nextattempt);
        }
        return $result;
    }

    /**
     * Number of files in FredKI and of files with problems, for the status display.
     *
     * @param int $agentid Agent id.
     * @return array ['synced' => int, 'pending' => int, 'failed' => int]
     */
    public static function counts(int $agentid): array {
        global $DB;
        $counts = ['synced' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($DB->get_records(self::TABLE, ['agentid' => $agentid], '', 'id, status') as $record) {
            $key = $record->status === 'synced' ? 'synced' : ($record->status === 'error' ? 'failed' : 'pending');
            $counts[$key]++;
        }
        return $counts;
    }
}
