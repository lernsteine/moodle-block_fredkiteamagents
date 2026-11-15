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
 * Agents in a course: Moodle account, enrolment, knowledge folder and the agent in FredKI.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agents {
    /** @var string Table of agents. */
    const TABLE = 'block_fredkiteamagents_agent';

    /** @var string Short name of the role for agents. */
    const ROLE = 'fredkiagent';

    /**
     * Add an agent from a template to a course. FredKI builds the agent in the background.
     *
     * @param int $courseid Course id.
     * @param string $templatekey Template key.
     * @return int Agent id.
     */
    public static function add(int $courseid, string $templatekey): int {
        global $DB;
        $template = templates::get($templatekey);
        if (!$template || !isset(templates::addable()[$templatekey])) {
            throw new \moodle_exception('error_template', 'block_fredkiteamagents');
        }
        $existing = $DB->get_record(self::TABLE, ['courseid' => $courseid, 'template' => $templatekey]);
        if ($existing && $existing->status === 'removing') {
            // An older removal still runs in the background: it must not block adding the agent again.
            self::set_aside($existing);
            $existing = null;
        }
        if ($existing) {
            return (int)$existing->id;
        }
        $course = get_course($courseid);
        $now = time();
        $agent = (object)[
            'courseid' => $courseid,
            'template' => $templatekey,
            'userid' => self::ensure_user($template),
            'cmid' => 0,
            'status' => 'new',
            'active' => 1,
            'dailylimit' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $agent->id = $DB->insert_record(self::TABLE, $agent);
        self::enrol($course, (int)$agent->userid);
        $agent->cmid = self::ensure_folder($course, $template);
        $DB->set_field(self::TABLE, 'cmid', $agent->cmid, ['id' => $agent->id]);
        self::queue_provision((int)$agent->id);
        return (int)$agent->id;
    }

    /**
     * Queue building the agent in FredKI.
     *
     * @param int $agentid Agent id.
     */
    public static function queue_provision(int $agentid): void {
        global $DB, $USER;
        $DB->set_field(self::TABLE, 'status', 'building', ['id' => $agentid]);
        $task = new \block_fredkiteamagents\task\provision_agent();
        $task->set_custom_data(['agentid' => $agentid]);
        $task->set_component('block_fredkiteamagents');
        if (!empty($USER->id)) {
            $task->set_userid((int)$USER->id);
        }
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Build the agent in FredKI, then transfer its folder. Runs in the background.
     *
     * @param int $agentid Agent id.
     */
    public static function provision(int $agentid): void {
        global $DB;
        $agent = $DB->get_record(self::TABLE, ['id' => $agentid]);
        if (!$agent) {
            return;
        }
        try {
            $out = gateway::provision($agent);
            $agent->remoteagentid = $out['agent_id'];
            $agent->remoteknowledgeid = $out['knowledge_id'];
            $agent->status = 'ready';
            $agent->lasterror = null;
        } catch (\Throwable $e) {
            $agent->status = 'error';
            $agent->lasterror = $e instanceof \moodle_exception && !empty($e->debuginfo) ? $e->debuginfo : $e->getMessage();
            $agent->timemodified = time();
            $DB->update_record(self::TABLE, $agent);
            throw $e;
        }
        $agent->timemodified = time();
        $DB->update_record(self::TABLE, $agent);
        foldersync::queue($agentid);
    }

    /**
     * Pause an agent or bring it back.
     *
     * @param int $agentid Agent id.
     * @param bool $active Whether the agent takes part.
     */
    public static function set_active(int $agentid, bool $active): void {
        global $DB;
        $DB->update_record(self::TABLE, (object)['id' => $agentid, 'active' => $active ? 1 : 0, 'timemodified' => time()]);
    }

    /**
     * Remove an agent from a course. In Moodle at once (it leaves the course and stops speaking); in FredKI in the
     * background, retried until the licence server confirms. Its posts and its folder stay.
     *
     * @param int $agentid Agent id.
     */
    public static function remove(int $agentid): void {
        global $DB, $USER;
        $agent = $DB->get_record(self::TABLE, ['id' => $agentid], '*', MUST_EXIST);
        self::unenrol(get_course($agent->courseid), (int)$agent->userid);
        $DB->update_record(self::TABLE, (object)['id' => $agentid, 'status' => 'removing', 'active' => 0,
            'lasterror' => null, 'timemodified' => time()]);
        self::set_aside($agent);
        $task = new \block_fredkiteamagents\task\remove_agent();
        $task->set_custom_data(['agentid' => $agentid]);
        $task->set_component('block_fredkiteamagents');
        if (!empty($USER->id)) {
            $task->set_userid((int)$USER->id);
        }
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Free the place of an agent that is being removed, so the same template can be added again at once.
     * The licence server knows the agent by its id, not by the template key.
     *
     * @param \stdClass $agent Agent record.
     */
    protected static function set_aside(\stdClass $agent): void {
        global $DB;
        $suffix = '-' . (int)$agent->id;
        if (str_ends_with((string)$agent->template, $suffix)) {
            return;
        }
        $base = templates::base((string)$agent->template);
        $DB->set_field(
            self::TABLE,
            'template',
            \core_text::substr($base, 0, 40 - strlen($suffix)) . $suffix,
            ['id' => $agent->id]
        );
    }

    /**
     * Remove the agent in FredKI, then forget it. Runs in the background; a failure is retried by Moodle.
     *
     * @param int $agentid Agent id.
     */
    public static function finish_remove(int $agentid): void {
        global $DB;
        $agent = $DB->get_record(self::TABLE, ['id' => $agentid]);
        if (!$agent || $agent->status !== 'removing') {
            return;
        }
        try {
            // Always ask the licence server: it also forgets failed attempts that never reached FredKI.
            gateway::remove($agent);
        } catch (\Throwable $e) {
            $DB->set_field(
                self::TABLE,
                'lasterror',
                $e instanceof \moodle_exception && !empty($e->debuginfo) ? $e->debuginfo : $e->getMessage(),
                ['id' => $agentid]
            );
            throw $e;
        }
        $DB->delete_records('block_fredkiteamagents_file', ['agentid' => $agentid]);
        $DB->delete_records('block_fredkiteamagents_post', ['agentid' => $agentid]);
        $DB->delete_records(self::TABLE, ['id' => $agentid]);
    }

    /**
     * Forget an agent in Moodle whose removal in FredKI keeps failing. Its copy and knowledge base in FredKI stay
     * and must be deleted there by hand.
     *
     * @param int $agentid Agent id.
     */
    public static function forget(int $agentid): void {
        global $DB;
        $agent = $DB->get_record(self::TABLE, ['id' => $agentid]);
        if (!$agent || $agent->status !== 'removing') {
            return;
        }
        $DB->delete_records('block_fredkiteamagents_file', ['agentid' => $agentid]);
        $DB->delete_records('block_fredkiteamagents_post', ['agentid' => $agentid]);
        $DB->delete_records(self::TABLE, ['id' => $agentid]);
    }

    /**
     * Whether something is still happening in the background (built, removed).
     *
     * @param int $courseid Course id.
     * @return bool
     */
    public static function pending(int $courseid): bool {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(['new', 'building', 'removing'], SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        return $DB->record_exists_select(self::TABLE, "courseid = :courseid AND status $insql", $params);
    }

    /**
     * Agents of a course.
     *
     * @param int $courseid Course id.
     * @return \stdClass[]
     */
    public static function for_course(int $courseid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['courseid' => $courseid], 'id');
    }

    /**
     * Agents that may speak now.
     *
     * @param int $courseid Course id.
     * @return \stdClass[]
     */
    public static function speaking(int $courseid): array {
        global $DB;
        // In a FredKI course the teacher chooses either FredKI or the expert round; with FredKI the agents stay silent.
        if (class_exists('\\format_fredki\\local\\council')) {
            $course = get_course($courseid);
            if (
                $course->format === 'fredki'
                    && \format_fredki\local\council::mode($course) !== \format_fredki\local\council::MODE_TEAM
            ) {
                return [];
            }
        }
        // The learning companion talks privately in the learning space and never speaks in the forum.
        return array_filter(
            $DB->get_records(self::TABLE, ['courseid' => $courseid, 'active' => 1, 'status' => 'ready'], 'id'),
            static fn(\stdClass $agent): bool => !templates::is_companion((string)$agent->template)
        );
    }

    /**
     * The learning companion a person reaches when writing a Moodle message to a companion account.
     * The account is the same in every course; the course is the one with this companion where the person was
     * active last.
     *
     * @param int $agentuserid User id of the companion account.
     * @param int $personid User id of the person who writes.
     * @return \stdClass|null Agent record.
     */
    public static function companion_for_message(int $agentuserid, int $personid): ?\stdClass {
        global $DB;
        $best = null;
        $bestaccess = -1;
        foreach ($DB->get_records(self::TABLE, ['userid' => $agentuserid], 'id') as $agent) {
            if (!templates::is_companion((string)$agent->template) || $agent->status !== 'ready' || !$agent->active) {
                continue;
            }
            $context = \context_course::instance((int)$agent->courseid, IGNORE_MISSING);
            if (!$context || !is_enrolled($context, $personid, '', true)) {
                continue;
            }
            $access = (int)$DB->get_field(
                'user_lastaccess',
                'timeaccess',
                ['userid' => $personid, 'courseid' => $agent->courseid]
            );
            if ($access > $bestaccess) {
                $best = $agent;
                $bestaccess = $access;
            }
        }
        return $best;
    }

    /**
     * The learning companion of a course, if it is there and ready.
     *
     * @param int $courseid Course id.
     * @return \stdClass|null Agent record.
     */
    public static function companion(int $courseid): ?\stdClass {
        foreach (self::for_course($courseid) as $agent) {
            if (templates::is_companion((string)$agent->template) && $agent->status === 'ready' && $agent->active) {
                return $agent;
            }
        }
        return null;
    }

    /**
     * Whether a user account belongs to any agent.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function is_agent_user(int $userid): bool {
        global $DB;
        static $cache = [];
        if (!isset($cache[$userid])) {
            // Also accounts of agents that were removed from every course: their posts stay AI posts.
            $cache[$userid] = $userid > 0 && ($DB->record_exists(self::TABLE, ['userid' => $userid])
                || self::export_account($userid) !== null);
        }
        return $cache[$userid];
    }

    /**
     * An agent account that is no longer part of a course (removed), so its old posts stay marked as AI.
     *
     * @param int $userid User id.
     * @return array|null Display data like export(), null if the account is not an agent.
     */
    public static function export_account(int $userid): ?array {
        global $CFG, $DB;
        static $cache = [];
        if (!array_key_exists($userid, $cache)) {
            $cache[$userid] = null;
            $username = (string)$DB->get_field('user', 'username', ['id' => $userid, 'mnethostid' => $CFG->mnet_localhost_id]);
            if (strpos($username, 'fredki_agent_') === 0) {
                $key = substr($username, strlen('fredki_agent_'));
                $cache[$userid] = self::export((object)['id' => 0, 'userid' => $userid, 'template' => $key, 'status' => 'removing',
                    'active' => 0, 'cmid' => 0]);
                $cache[$userid]['ready'] = false;
            }
        }
        return $cache[$userid];
    }

    /**
     * Agents of a course for display, keyed by user id.
     *
     * @param int $courseid Course id.
     * @return array
     */
    public static function export_for_course(int $courseid): array {
        $result = [];
        foreach (self::for_course($courseid) as $agent) {
            $result[(int)$agent->userid] = self::export($agent);
        }
        return $result;
    }

    /**
     * One agent for display.
     *
     * @param \stdClass $agent Agent record.
     * @return array
     */
    public static function export(\stdClass $agent): array {
        $template = templates::get($agent->template) ?? ['name' => $agent->template, 'role' => '', 'colour' => '#3d4552',
            'image' => '', 'mention' => $agent->template];
        $status = in_array($agent->status, ['new', 'building', 'ready', 'error', 'removing'], true) ? $agent->status : 'new';
        return [
            'id' => (int)$agent->id,
            'userid' => (int)$agent->userid,
            'name' => $template['name'],
            'role' => $template['role'],
            'colour' => $template['colour'],
            'image' => $template['image'],
            'mention' => '@' . $template['mention'],
            'aliases' => $template['aliases'] ?? [],
            'active' => (bool)$agent->active,
            'ready' => $status === 'ready',
            'status' => $status,
            'statuslabel' => get_string('status_' . $status, 'block_fredkiteamagents'),
            'cmid' => (int)$agent->cmid,
            'companion' => templates::is_companion((string)$agent->template),
        ];
    }

    /**
     * Moodle account of a template agent, the same in every course of the site.
     *
     * @param array $template Template.
     * @return int User id.
     */
    protected static function ensure_user(array $template): int {
        global $CFG, $DB;
        $username = 'fredki_agent_' . $template['key'];
        $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
        if ($user && empty($user->deleted)) {
            if ($user->suspended) {
                $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
            }
            return (int)$user->id;
        }
        require_once($CFG->dirroot . '/user/lib.php');
        // Moodle 5.3 replaces user_create_user() by \core\user::create_user().
        $create = method_exists(\core\user::class, 'create_user')
            ? [\core\user::class, 'create_user'] : 'user_create_user';
        $id = $create((object)[
            'username' => $username,
            'auth' => 'nologin',
            'firstname' => $template['name'],
            'lastname' => get_string('ailabel', 'block_fredkiteamagents'),
            'email' => 'noreply-' . $username . '@' . (parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'localhost'),
            'emailstop' => 1,
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'lang' => $CFG->lang,
            'description' => $template['role'],
        ], false, false);
        set_user_preference('message_provider_mod_forum_posts_enabled', 'none', $id);
        self::set_picture((int)$id, $template['imagefile']);
        return (int)$id;
    }

    /**
     * Give all agent accounts the current pictures and names of their templates (after an update).
     */
    public static function refresh_pictures(): void {
        global $CFG, $DB;
        foreach (templates::all() as $key => $template) {
            $user = $DB->get_record('user', ['username' => 'fredki_agent_' . $key, 'mnethostid' => $CFG->mnet_localhost_id,
                'deleted' => 0], 'id, firstname, description');
            if ($user) {
                self::set_picture((int)$user->id, $template['imagefile']);
                if ($user->firstname !== $template['name'] || $user->description !== $template['role']) {
                    // A renamed template (e.g. "Der Praktiker" became "Die Praktikerin").
                    $DB->update_record('user', (object)['id' => $user->id, 'firstname' => $template['name'],
                        'description' => $template['role'], 'timemodified' => time()]);
                }
            }
            // The knowledge folders in the courses carry the name too.
            foreach ($DB->get_records(self::TABLE, ['template' => $key], '', 'id, courseid, cmid') as $agent) {
                $cm = $agent->cmid ? $DB->get_record('course_modules', ['id' => $agent->cmid], 'id, instance') : null;
                if ($cm) {
                    $DB->set_field(
                        'folder',
                        'name',
                        get_string('foldername', 'block_fredkiteamagents', $template['name']),
                        ['id' => $cm->instance]
                    );
                    rebuild_course_cache((int)$agent->courseid, true);
                }
            }
        }
    }

    /**
     * Give the agent account its picture.
     *
     * @param int $userid User id.
     * @param string $source Image file.
     */
    public static function set_picture(int $userid, string $source): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gdlib.php');
        if (!is_readable($source)) {
            return;
        }
        $temp = make_request_directory() . '/agent.jpg';
        copy($source, $temp);
        $fileid = process_new_icon(\context_user::instance($userid), 'user', 'icon', 0, $temp);
        if ($fileid) {
            $DB->set_field('user', 'picture', $fileid, ['id' => $userid]);
        }
    }

    /**
     * Role for agents: may read and write in forums, nothing else.
     *
     * @return int Role id.
     */
    public static function ensure_role(): int {
        global $DB;
        $role = $DB->get_record('role', ['shortname' => self::ROLE]);
        if ($role) {
            return (int)$role->id;
        }
        $roleid = create_role(
            get_string('rolename', 'block_fredkiteamagents'),
            self::ROLE,
            get_string('roledescription', 'block_fredkiteamagents'),
            ''
        );
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        $system = \context_system::instance();
        foreach (['mod/forum:viewdiscussion', 'mod/forum:startdiscussion', 'mod/forum:replypost'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $system->id, true);
        }
        return (int)$roleid;
    }

    /**
     * Enrol the agent with the agent role.
     *
     * @param \stdClass $course Course.
     * @param int $userid User id.
     */
    protected static function enrol(\stdClass $course, int $userid): void {
        $plugin = enrol_get_plugin('manual');
        $instance = self::manual_instance($course, true);
        $plugin->enrol_user($instance, $userid, self::ensure_role());
    }

    /**
     * Take the agent out of the course.
     *
     * @param \stdClass $course Course.
     * @param int $userid User id.
     */
    protected static function unenrol(\stdClass $course, int $userid): void {
        $instance = self::manual_instance($course, false);
        if ($instance) {
            enrol_get_plugin('manual')->unenrol_user($instance, $userid);
        }
    }

    /**
     * Manual enrolment instance of a course.
     *
     * @param \stdClass $course Course.
     * @param bool $create Create it when missing.
     * @return \stdClass|null
     */
    protected static function manual_instance(\stdClass $course, bool $create): ?\stdClass {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance && $create) {
            $id = enrol_get_plugin('manual')->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['id' => $id]);
        }
        return $instance ?: null;
    }

    /**
     * Knowledge folder of the agent: an ordinary Moodle folder, hidden from learners.
     *
     * @param \stdClass $course Course.
     * @param array $template Template.
     * @return int Course module id.
     */
    protected static function ensure_folder(\stdClass $course, array $template): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        // An agent added again finds its old folder and its files.
        $name = get_string('foldername', 'block_fredkiteamagents', $template['name']);
        foreach (get_fast_modinfo($course)->get_instances_of('folder') as $cm) {
            if (
                $cm->name === $name && !$cm->deletioninprogress
                    && !$DB->record_exists(self::TABLE, ['courseid' => $course->id, 'cmid' => $cm->id])
            ) {
                return (int)$cm->id;
            }
        }
        $moduleinfo = (object)[
            'modulename' => 'folder',
            'course' => $course->id,
            'section' => 0,
            'visible' => 0,
            'visibleoncoursepage' => 0,
            'name' => $name,
            'introeditor' => ['text' => get_string('folderintro', 'block_fredkiteamagents', $template['name']),
                'format' => FORMAT_HTML, 'itemid' => 0],
            'display' => 0,
            'showexpanded' => 1,
            'showdownloadfolder' => 0,
            'forcedownload' => 0,
            'files' => 0,
        ];
        $moduleinfo = create_module($moduleinfo);
        return (int)$moduleinfo->coursemodule;
    }
}
