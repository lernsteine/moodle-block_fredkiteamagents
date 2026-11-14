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
 * The learning companion answers Moodle messages (1:1 conversations).
 *
 * A person writes to the companion account in the Moodle messages; the companion answers in the same conversation
 * in the background. The conversation stays in Moodle and can be read again, also in the Moodle app.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class companionmessage {
    /** Earlier messages of the conversation that the companion sees. */
    const HISTORY = 10;

    /** @var bool True while a talk from the learning space is copied into the messages (no answer to those). */
    protected static $mirroring = false;

    /**
     * Keep a talk from the learning space ("Ask FredKI") in the person's Moodle messages with the companion.
     *
     * @param int $courseid Course id.
     * @param int $personid The person who asked.
     * @param string $question Question or the button the person chose.
     * @param string $answer Answer of the companion.
     */
    public static function mirror(int $courseid, int $personid, string $question, string $answer): void {
        global $CFG;
        $question = trim($question);
        $answer = trim($answer);
        $agent = agents::companion($courseid);
        if (!$agent || empty($CFG->messaging) || $question === '' || $answer === '' || agents::is_agent_user($personid)) {
            return;
        }
        $conversationid = \core_message\api::get_conversation_between_users([$personid, (int)$agent->userid]);
        if (!$conversationid) {
            $conversationid = \core_message\api::create_conversation(
                \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
                [$personid, (int)$agent->userid]
            )->id;
        }
        self::$mirroring = true;
        try {
            \core_message\api::send_message_to_conversation($personid, (int)$conversationid, $question, FORMAT_PLAIN);
            $sent = \core_message\api::send_message_to_conversation(
                (int)$agent->userid,
                (int)$conversationid,
                $answer,
                FORMAT_MARKDOWN
            );
            // The person has just read the answer in the learning space: no unread badge for it.
            if (!empty($sent->id)) {
                global $DB;
                $record = $DB->get_record('messages', ['id' => $sent->id]);
                if ($record) {
                    \core_message\api::mark_message_as_read($personid, $record);
                }
            }
        } finally {
            self::$mirroring = false;
        }
    }

    /**
     * A message was sent: queue the answer if it went to a learning companion.
     *
     * @param \core\event\message_sent $event Event.
     */
    public static function message_sent(\core\event\message_sent $event): void {
        global $DB;
        if (self::$mirroring) {
            return;
        }
        $agentuserid = (int)$event->relateduserid;
        $personid = (int)$event->userid;
        if ($agentuserid <= 0 || $personid <= 0 || agents::is_agent_user($personid)) {
            return;
        }
        // Only the learning companion answers messages; the agents of the round speak in the forum.
        $account = agents::export_account($agentuserid);
        if (!$account || empty($account['companion'])) {
            return;
        }
        $agent = agents::companion_for_message($agentuserid, $personid);
        $message = $DB->get_record('messages', ['id' => (int)$event->objectid], 'id, conversationid');
        if (!$message) {
            return;
        }
        $task = new \block_fredkiteamagents\task\companion_reply();
        $task->set_custom_data(['messageid' => (int)$message->id, 'conversationid' => (int)$message->conversationid,
            'personid' => $personid, 'agentuserid' => $agentuserid, 'agentid' => $agent ? (int)$agent->id : 0]);
        $task->set_component('block_fredkiteamagents');
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Write the answer of the companion into the conversation.
     *
     * @param \stdClass $data Task data.
     */
    public static function reply(\stdClass $data): void {
        global $DB;
        $agentuserid = (int)$data->agentuserid;
        $conversationid = (int)$data->conversationid;
        $message = $DB->get_record('messages', ['id' => (int)$data->messageid]);
        if (!$message) {
            return;
        }
        // Several messages in a row: the companion answers once, to the newest one.
        $newer = $DB->record_exists_select(
            'messages',
            'conversationid = ? AND useridfrom = ? AND id > ?',
            [$conversationid, (int)$data->personid, (int)$message->id]
        );
        if ($newer) {
            return;
        }
        $agent = $data->agentid ? $DB->get_record(agents::TABLE, ['id' => (int)$data->agentid]) : null;
        if (!$agent || $agent->status !== 'ready' || !$agent->active) {
            self::send($agentuserid, $conversationid, get_string('companion_notavailable', 'block_fredkiteamagents'));
            return;
        }
        $question = trim(html_to_text(
            format_text((string)$message->fullmessage, (int)$message->fullmessageformat),
            0,
            false
        ));
        if ($question === '') {
            return;
        }
        // Earlier turns of this conversation, oldest first.
        $earlier = $DB->get_records_select(
            'messages',
            'conversationid = ? AND id < ?',
            [$conversationid, (int)$message->id],
            'id DESC',
            'id, useridfrom, fullmessage, fullmessageformat',
            0,
            self::HISTORY
        );
        $history = [];
        foreach (array_reverse($earlier) as $m) {
            $text = trim(html_to_text(format_text((string)$m->fullmessage, (int)$m->fullmessageformat), 0, false));
            if ($text !== '') {
                $history[] = ['role' => (int)$m->useridfrom === $agentuserid ? 'assistant' : 'user',
                    'content' => \core_text::substr($text, 0, 4000)];
            }
        }
        // Without a clear language in the message, the companion answers in the person's Moodle language.
        $person = \core_user::get_user((int)$data->personid);
        $previous = force_current_language($person && !empty($person->lang) ? $person->lang : '');
        try {
            $prompt = self::prompt((int)$agent->courseid, self::with_context($question, $history));
        } finally {
            force_current_language($previous);
        }
        try {
            $answer = gateway::chat($agent, 'companion', $prompt, $history);
        } catch (\Throwable $e) {
            debugging('Learning companion could not answer a message: ' . $e->getMessage(), DEBUG_DEVELOPER);
            self::send($agentuserid, $conversationid, get_string('companion_error', 'block_fredkiteamagents'));
            return;
        }
        self::send($agentuserid, $conversationid, trim($answer));
    }

    /**
     * The new message with the talk so far written into it. FredKI's chat interface does not reliably keep earlier
     * turns, so the companion reads them here: an answer like "green belts" to its own question then makes sense.
     *
     * @param string $question New message of the person.
     * @param array $history Earlier turns, oldest first.
     * @return string
     */
    protected static function with_context(string $question, array $history): string {
        $lines = [];
        $length = 0;
        foreach (array_reverse(array_slice($history, -8)) as $turn) {
            $text = \core_text::substr(trim(preg_replace('/\s+/u', ' ', (string)$turn['content'])), 0, 700);
            $line = ($turn['role'] === 'assistant' ? 'You' : 'Learner') . ': ' . $text;
            $length += \core_text::strlen($line);
            if ($length > 4000) {
                break;
            }
            array_unshift($lines, $line);
        }
        if (!$lines) {
            return $question;
        }
        return str_replace(
            ['{history}', '{message}'],
            [implode("\n", $lines), $question],
            str_replace('\\n', "\n", get_string('companion_context', 'block_fredkiteamagents'))
        );
    }

    /**
     * The request for FredKI: the same as for "Ask FredKI" in the learning space when the course uses it.
     *
     * @param int $courseid Course id.
     * @param string $question Message of the person.
     * @return string
     */
    protected static function prompt(int $courseid, string $question): string {
        $course = get_course($courseid);
        $format = '\\format_fredki\\local\\fredki';
        if ($course->format === 'fredki' && method_exists($format, 'build_message')) {
            return $format::build_message('ask', $course, 0, $question, 0);
        }
        $lang = responder::detect_language($question);
        if ($lang === '') {
            $lang = (string)($course->lang ?: get_config('core', 'lang'));
        }
        $names = get_string_manager()->get_list_of_languages('en');
        $code = explode('_', $lang)[0];
        return get_string('prompt_language', 'block_fredkiteamagents', $names[$code] ?? $code) . "\n" .
            get_string('prompt_style', 'block_fredkiteamagents') . "\n\n" . $question;
    }

    /**
     * Send a message of the companion into a conversation.
     *
     * @param int $agentuserid Companion account.
     * @param int $conversationid Conversation.
     * @param string $text Text (Markdown from FredKI).
     */
    protected static function send(int $agentuserid, int $conversationid, string $text): void {
        if ($text === '') {
            return;
        }
        \core_message\api::send_message_to_conversation($agentuserid, $conversationid, $text, FORMAT_MARKDOWN);
    }
}
