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

use block_fredkitheagent\local\managed_gateway;

/**
 * Connection to the FredKI licence server for team agents.
 *
 * Uses the installation registered by FredKI The Agent: the same address and the same secret. Moodle never holds
 * FredKI credentials; the licence server creates agents and knowledge bases and answers on their behalf.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway {
    /** @var string Component that holds the installation credentials. */
    const AGENTCOMPONENT = 'block_fredkitheagent';

    /** @var string Default address, the same as in FredKI The Agent. */
    const DEFAULT_API_URL = 'https://fredki-theagent.lernsteine.de/api/v1/index.php';

    /**
     * Whether the site is connected to FredKI.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return managed_gateway::is_registered();
    }

    /**
     * Create the agent and its knowledge base in FredKI, or return the existing ones.
     *
     * @param \stdClass $agent Agent record.
     * @return array ['agent_id' => string, 'knowledge_id' => string]
     */
    public static function provision(\stdClass $agent): array {
        $course = get_course($agent->courseid);
        // The course must be known to the licence server (counts against the package like The Agent).
        managed_gateway::ensure_course_registered($course);
        $template = templates::get($agent->template);
        $out = self::request('teamagents/provision', [
            'installation_id' => managed_gateway::get_installation_id(),
            'course_id' => (int)$agent->courseid,
            // Since 0.2.19: the licence server files agents and knowledge bases in a folder per course.
            'course_shortname' => format_string($course->shortname, true, ['escape' => false]),
            'agent_key' => (int)$agent->id,
            'template' => $agent->template,
            'name' => $template ? $template['name'] : $agent->template,
            'language' => current_language(),
        ], 120);
        foreach (['agent_id', 'knowledge_id'] as $key) {
            if (empty($out[$key]) || !is_string($out[$key]) || !preg_match('/^[A-Za-z0-9_-]{4,100}$/D', $out[$key])) {
                throw new \moodle_exception('error_provision', 'block_fredkiteamagents');
            }
        }
        return $out;
    }

    /**
     * Let the agent answer.
     *
     * @param \stdClass $agent Agent record.
     * @param string $reason Why the agent speaks: mention or convene.
     * @param string $message The post and its conversation, prepared for the agent.
     * @param array $history Earlier turns of a private talk: [['role' => 'user'|'assistant', 'content' => …]].
     * @return string Answer text.
     */
    public static function chat(\stdClass $agent, string $reason, string $message, array $history = []): string {
        $payload = [
            'installation_id' => managed_gateway::get_installation_id(),
            'course_id' => (int)$agent->courseid,
            'agent_key' => (int)$agent->id,
            'reason' => $reason,
            'message' => $message,
        ];
        if ($history) {
            // Private talks of the learning companion keep their last turns.
            $payload['history'] = array_slice(array_values($history), -10);
        }
        $out = self::chat_job($payload);
        if (!isset($out['answer']) || !is_string($out['answer']) || trim($out['answer']) === '') {
            throw new \moodle_exception('error_chat', 'block_fredkiteamagents');
        }
        // The server chooses the agent from the binding; if it names one, it must be this agent.
        if (!empty($out['agent_id']) && !hash_equals((string)$agent->remoteagentid, (string)$out['agent_id'])) {
            throw new \moodle_exception('error_chat', 'block_fredkiteamagents');
        }
        return $out['answer'];
    }

    /**
     * Remove the agent and its knowledge base in FredKI.
     *
     * @param \stdClass $agent Agent record.
     */
    public static function remove(\stdClass $agent): void {
        self::request('teamagents/remove', [
            'installation_id' => managed_gateway::get_installation_id(),
            'course_id' => (int)$agent->courseid,
            'agent_key' => (int)$agent->id,
        ], 60);
    }

    /**
     * Send one file to the knowledge base of the agent.
     *
     * @param \stdClass $agent Agent record.
     * @param \stored_file $file File of the knowledge folder.
     * @return string Document id in FredKI.
     */
    public static function upload(\stdClass $agent, \stored_file $file): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $tmp = make_request_directory() . '/' . clean_filename($file->get_filename());
        $file->copy_content_to($tmp);
        $curl = new \curl();
        $curl->setHeader(['Accept: application/json', 'Authorization: Bearer ' . self::secret()]);
        $raw = $curl->post(self::document_url('sync'), [
            'installation_id' => managed_gateway::get_installation_id(),
            'course_id' => (string)$agent->courseid,
            'target' => 'agent:' . (int)$agent->id,
            'file_key' => $file->get_pathnamehash(),
            'content_hash' => $file->get_contenthash(),
            'filename' => $file->get_filename(),
            'source_url' => '',
            'file' => new \CURLFile($tmp, 'application/octet-stream', $file->get_filename()),
        ], ['CURLOPT_TIMEOUT' => 240, 'CURLOPT_CONNECTTIMEOUT' => 15]);
        @unlink($tmp);
        $http = (int)($curl->get_info()['http_code'] ?? 0);
        $parsed = json_decode(trim((string)$raw), true);
        if (
            $curl->get_errno() || $http < 200 || $http >= 300 || !is_array($parsed)
                || !in_array($parsed['state'] ?? '', ['synced', 'unchanged'], true)
                || empty($parsed['document_id']) || !preg_match('/^[A-Za-z0-9_-]{4,100}$/D', (string)$parsed['document_id'])
        ) {
            throw new transient_exception('DIAG-AGENT-DOCUMENT-HTTP-' . ($http ?: 'NETWORK'), $http === 0 || $http >= 500
                || in_array($http, [408, 425, 429], true));
        }
        return (string)$parsed['document_id'];
    }

    /**
     * Delete a document from the knowledge base of the agent.
     *
     * @param \stdClass $agent Agent record.
     * @param string $filekey Path name hash of the file.
     */
    public static function delete_document(\stdClass $agent, string $filekey): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json',
            'Authorization: Bearer ' . self::secret()]);
        $raw = $curl->post(self::document_url('delete'), json_encode([
            'installation_id' => managed_gateway::get_installation_id(),
            'course_id' => (int)$agent->courseid,
            'target' => 'agent:' . (int)$agent->id,
            'file_key' => $filekey,
        ]), ['CURLOPT_TIMEOUT' => 90]);
        $http = (int)($curl->get_info()['http_code'] ?? 0);
        if (!$curl->get_errno() && ($http === 404 || ($http >= 200 && $http < 300))) {
            return;
        }
        throw new transient_exception('DIAG-AGENT-DELETE-HTTP-' . ($http ?: 'NETWORK'), $http === 0 || $http >= 500);
    }

    /**
     * Installation secret, stored by FredKI The Agent.
     *
     * @return string
     */
    /**
     * The templates found in FredKI ("Vorlage – …"), from the licence server.
     *
     * @param bool $now Ask the licence server to look into FredKI now.
     * @return array List of ['key', 'name', 'role'].
     */
    public static function templates(bool $now = false): array {
        $out = self::request('teamagents/templates', [
            'installation_id' => managed_gateway::get_installation_id(),
            'refresh' => $now,
        ], 30);
        $result = [];
        foreach ((array)($out['templates'] ?? []) as $t) {
            $key = (string)($t['key'] ?? '');
            $name = trim((string)($t['name'] ?? ''));
            if (!preg_match('/^[a-z0-9]{1,40}$/D', $key) || $name === '') {
                continue;
            }
            // Names and roles may hold several languages (multilang tags). They are shortened only after the
            // language is chosen (templates::get()), so a cut never breaks a tag.
            $result[$key] = ['key' => $key, 'name' => \core_text::substr(clean_param($name, PARAM_TEXT), 0, 400),
                'role' => \core_text::substr(clean_param((string)($t['role'] ?? ''), PARAM_TEXT), 0, 1000)];
        }
        return $result;
    }

    /**
     * Shared secret of the installation.
     *
     * @return string
     */
    protected static function secret(): string {
        $secret = (string)get_config(self::AGENTCOMPONENT, 'managed_installation_secret');
        if (!preg_match('/^[0-9a-f]{64}$/D', $secret)) {
            throw new \moodle_exception('error_notconnected', 'block_fredkiteamagents');
        }
        return $secret;
    }

    /**
     * Licence server address, following the rules of FredKI The Agent.
     *
     * @return string
     */
    protected static function api_address(): string {
        global $CFG;
        $lock = get_config(self::AGENTCOMPONENT, 'managed_api_url_lock');
        $locked = $lock === false ? empty(get_config(self::AGENTCOMPONENT, 'managed_api_url_unlock')) : !empty($lock);
        $custom = $locked ? '' : trim((string)get_config(self::AGENTCOMPONENT, 'managed_api_url'));
        $address = $custom !== '' ? $custom : self::DEFAULT_API_URL;
        $parts = parse_url($address);
        if (
            !is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['query'])
                || !str_ends_with((string)($parts['path'] ?? ''), '/api/v1/index.php')
        ) {
            throw new \moodle_exception('error_notconnected', 'block_fredkiteamagents');
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            // Plain HTTP only when Moodle and the licence server both run on this machine (development).
            $local = ['localhost', '127.0.0.1'];
            if (
                ($parts['scheme'] ?? '') !== 'http' || !in_array(strtolower($parts['host']), $local, true)
                    || !in_array(strtolower((string)parse_url($CFG->wwwroot, PHP_URL_HOST)), $local, true)
            ) {
                throw new \moodle_exception('error_notconnected', 'block_fredkiteamagents');
            }
        }
        return $address;
    }

    /**
     * Address of the document endpoint.
     *
     * @param string $action sync or delete.
     * @return string
     */
    protected static function document_url(string $action): string {
        return substr(self::api_address(), 0, -strlen('index.php')) . 'documents.php?action=' . rawurlencode($action);
    }

    /** Seconds Moodle waits at most for an answer of FredKI. */
    const CHAT_WAIT = 300;

    /** @var callable|null Called while Moodle waits for FredKI, e.g. to keep the browser's connection alive. */
    public static $heartbeat = null;

    /** @var int Time of the last heartbeat. */
    protected static $lastbeat = 0;

    /**
     * Call the heartbeat, at most every few seconds.
     */
    protected static function beat(): void {
        if (self::$heartbeat !== null && time() - self::$lastbeat >= 5) {
            self::$lastbeat = time();
            (self::$heartbeat)();
        }
    }

    /**
     * Ask an agent through a job of the licence server: the server answers at once and asks FredKI afterwards; Moodle
     * collects the answer. Slow models no longer run into the time limit of the licence server's web host (HTTP 504).
     * Licence servers before 13.28 do not know jobs; then the question is asked in one request as before.
     *
     * @param array $payload Chat request.
     * @return array Answer of the server: ['answer' => …, 'agent_id' => …].
     */
    protected static function chat_job(array $payload): array {
        try {
            // 13.29: the licence server answers in this request and keeps the connection alive while FredKI works.
            $start = self::request('teamagents/chat_start', $payload, self::CHAT_WAIT);
        } catch (transient_exception $e) {
            if (strpos((string)$e->debuginfo, '-HTTP-404') !== false) {
                // Older licence server (no jobs) or unknown agent: the classic request tells which.
                return self::request('teamagents/chat', $payload, 120);
            }
            throw $e;
        }
        $startstatus = (string)($start['status'] ?? '');
        if ($startstatus === 'done') {
            return $start;
        }
        if ($startstatus === 'error') {
            $code = preg_match('/^FK-[A-Z0-9-]{2,40}$/D', (string)($start['error_code'] ?? '')) ? ' ' . $start['error_code'] : '';
            throw new transient_exception('DIAG-TEAMAGENTS-teamagents/chat' . $code, true);
        }
        // 13.28 answered with a job to collect.
        $job = (string)($start['job_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $job)) {
            throw new transient_exception('DIAG-TEAMAGENTS-teamagents/chat_start-NOJOB', true);
        }
        $deadline = time() + self::CHAT_WAIT;
        $wait = 1;
        while (time() < $deadline) {
            self::beat();
            sleep($wait);
            $wait = min(3, $wait + 1);
            $out = self::request('teamagents/chat_result', [
                'installation_id' => $payload['installation_id'],
                'course_id' => $payload['course_id'],
                'job_id' => $job,
            ], 20);
            $status = (string)($out['status'] ?? '');
            if ($status === 'done') {
                return $out;
            }
            if ($status === 'error') {
                $code = preg_match('/^FK-[A-Z0-9-]{2,40}$/D', (string)($out['error_code'] ?? '')) ? ' ' . $out['error_code'] : '';
                throw new transient_exception('DIAG-TEAMAGENTS-teamagents/chat-JOB' . $code, true);
            }
        }
        throw new transient_exception('DIAG-TEAMAGENTS-teamagents/chat-TIMEOUT', true);
    }

    /**
     * Remember that the licence server refused Team Agents because the booked package does not include them
     * (null: Team Agents work again). The link leads to the booking or the upgrade on the licence server.
     *
     * @param string|null $url Link to book or upgrade, or null to forget the refusal.
     */
    protected static function remember_team_plan(?string $url): void {
        if ($url === null) {
            if (get_config('block_fredkiteamagents', 'teamplanrefused')) {
                unset_config('teamplanrefused', 'block_fredkiteamagents');
                unset_config('teamplanurl', 'block_fredkiteamagents');
            }
            return;
        }
        set_config('teamplanrefused', time(), 'block_fredkiteamagents');
        set_config('teamplanurl', preg_match('~^https://[^\s"<>]+$~', $url) ? $url : '', 'block_fredkiteamagents');
    }

    /**
     * State of this site at the licence server for the setup page: connected, active, Team Agents included.
     * One request; nothing is stored.
     *
     * @return array reachable, active, team (true, false or null when the server does not say), teamurl
     */
    public static function site_status(): array {
        $out = ['reachable' => false, 'active' => false, 'team' => null, 'teamurl' => ''];
        if (!self::is_ready()) {
            return $out;
        }
        try {
            $data = self::request('installations/status', ['installation_id' => managed_gateway::get_installation_id()], 10);
        } catch (\Throwable $e) {
            return $out;
        }
        $out['reachable'] = true;
        $out['active'] = in_array((string)($data['state'] ?? ''), ['trial_active', 'licensed', 'free'], true)
            || !empty($data['active']);
        if (array_key_exists('team_allowed', $data)) {
            $out['team'] = $data['team_allowed'] === true;
        }
        $url = (string)($data['team_url'] ?? '');
        $host = (string)parse_url(self::api_address(), PHP_URL_HOST);
        if (preg_match('~^https://[^\s"<>]+$~', $url) && strcasecmp((string)parse_url($url, PHP_URL_HOST), $host) === 0) {
            $out['teamurl'] = $url;
        }
        return $out;
    }

    /**
     * Whether the licence server last refused Team Agents for the booked package, with the link to upgrade.
     *
     * @return array ['refused' => bool, 'url' => string]
     */
    public static function team_plan(): array {
        return [
            'refused' => (bool)get_config('block_fredkiteamagents', 'teamplanrefused'),
            'url' => (string)get_config('block_fredkiteamagents', 'teamplanurl'),
        ];
    }

    /**
     * JSON request to the licence server.
     *
     * @param string $route Route.
     * @param array $body Body.
     * @param int $timeout Timeout in seconds.
     * @return array
     */
    protected static function request(string $route, array $body, int $timeout): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json',
            'Authorization: Bearer ' . self::secret()]);
        $options = [
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_FOLLOWLOCATION' => false,
        ];
        if (self::$heartbeat !== null) {
            // While the licence server works, the browser waiting for Moodle hears a heartbeat now and then.
            $options['CURLOPT_NOPROGRESS'] = false;
            $options['CURLOPT_PROGRESSFUNCTION'] = static function (): int {
                self::beat();
                return 0;
            };
        }
        $raw = $curl->post(self::api_address() . '?route=' . rawurlencode($route), json_encode($body), $options);
        $http = (int)($curl->get_info()['http_code'] ?? 0);
        $parsed = json_decode(trim((string)$raw), true);
        if ($http === 402 && is_array($parsed) && ($parsed['error_code'] ?? '') === 'FK-TEAM-PLAN') {
            // The booked package of this site is FredKI The Agent only: remember it for the notice in the block.
            self::remember_team_plan((string)($parsed['team_url'] ?? ''));
        } else if (
            in_array($http, [200, 201, 202], true) && str_starts_with($route, 'teamagents/') && $route !== 'teamagents/templates'
        ) {
            self::remember_team_plan(null);
        }
        if ($curl->get_errno() || !in_array($http, [200, 201, 202], true) || !is_array($parsed)) {
            // Never pass on response bodies: they may contain internal details of the server.
            $code = is_array($parsed) && preg_match('/^FK-[A-Z0-9-]{2,40}$/D', (string)($parsed['error_code'] ?? ''))
                ? ' ' . $parsed['error_code'] : '';
            throw new transient_exception(
                'DIAG-TEAMAGENTS-' . $route . '-HTTP-' . ($http ?: 'NETWORK') . $code,
                $http === 0 || $http >= 500 || in_array($http, [408, 409, 425, 429], true)
            );
        }
        return $parsed;
    }
}
