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
 * Decides when an agent speaks and writes its forum post. Moodle steers, FredKI only answers.
 *
 * Rules: only on posts written by people, at most one answer of an agent per post, a daily limit per agent,
 * paused agents stay silent.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class responder {
    /** @var string Table of agent posts. */
    const TABLE = 'block_fredkiteamagents_post';

    /** @var int Posts of the conversation sent along as context. */
    const CONTEXT_POSTS = 12;

    /** @var int Other conversations of the forum an overview agent sees. */
    const OVERVIEW_DISCUSSIONS = 12;

    /** @var int Posts per other conversation an overview agent sees. */
    const OVERVIEW_POSTS = 8;

    /** @var int Bytes of the whole conversation sent to FredKI (the server accepts 20000). */
    const MAX_BYTES = 18000;

    /**
     * Agents of a course mentioned in a text, e.g. "@Expertin".
     *
     * @param int $courseid Course id.
     * @param string $html Post message.
     * @return \stdClass[]
     */
    public static function mentioned(int $courseid, string $html): array {
        $text = html_to_text($html, 0, false);
        $result = [];
        foreach (agents::speaking($courseid) as $agent) {
            $template = templates::get($agent->template);
            if (!$template) {
                continue;
            }
            $names = array_map(
                static fn(string $n): string => preg_quote($n, '/'),
                array_merge([$template['mention'], $template['name']], $template['aliases'] ?? [])
            );
            // Mentions like "@Expertin", also "@ Expertin" (a space, or the protected space some editors insert).
            if (preg_match('/@[\s\x{00A0}]?(' . implode('|', $names) . ')(?![\p{L}\p{N}])/iu', $text)) {
                $result[$agent->id] = $agent;
            }
        }
        return $result;
    }

    /** @var string Why the last claim or answer did not happen: paused, notperson, already, limit or busy. */
    public static string $refusal = '';

    /**
     * Whether a post was written by a person (not an agent, not FredKI).
     *
     * @param \stdClass $post Forum post.
     * @return bool
     */
    public static function by_person(\stdClass $post): bool {
        $fredki = (int)get_config('format_fredki', 'botuserid');
        return !agents::is_agent_user((int)$post->userid) && ($fredki === 0 || (int)$post->userid !== $fredki);
    }

    /**
     * Claim the answer of an agent to a post, if the rules allow it.
     *
     * @param \stdClass $agent Agent record.
     * @param int $postid Post to answer.
     * @param string $reason mention or convene.
     * @return int Claim id, 0 if the agent must not answer.
     */
    public static function claim(\stdClass $agent, int $postid, string $reason): int {
        global $DB;
        if (!$agent->active || $agent->status !== 'ready' || templates::is_companion((string)$agent->template)) {
            self::$refusal = 'paused';
            return 0;
        }
        $post = $DB->get_record('forum_posts', ['id' => $postid, 'deleted' => 0]);
        if (!$post || !self::by_person($post)) {
            self::$refusal = 'notperson';
            return 0;
        }
        if ($DB->record_exists(self::TABLE, ['agentid' => $agent->id, 'replyto' => $postid])) {
            self::$refusal = 'already';
            return 0;
        }
        // 0 = no daily limit.
        if ((int)$agent->dailylimit > 0 && self::answers_today((int)$agent->id) >= (int)$agent->dailylimit) {
            self::$refusal = 'limit';
            return 0;
        }
        try {
            // Claim the answer first: a second trigger for the same post then finds the record and stops.
            return (int)$DB->insert_record(self::TABLE, (object)['agentid' => $agent->id, 'postid' => 0,
                'replyto' => $postid, 'reason' => $reason, 'timecreated' => time()]);
        } catch (\dml_write_exception $e) {
            return 0;
        }
    }

    /**
     * Queue an answer of an agent to a post, if the rules allow it. Written in the background by cron.
     *
     * @param \stdClass $agent Agent record.
     * @param int $postid Post to answer.
     * @param string $reason mention or convene.
     * @param int $runat Answer not before this time, 0 for as soon as possible.
     * @return bool Whether an answer was queued.
     */
    public static function queue(\stdClass $agent, int $postid, string $reason, int $runat = 0): bool {
        $claimid = self::claim($agent, $postid, $reason);
        if (!$claimid) {
            return false;
        }
        $task = new \block_fredkiteamagents\task\agent_reply();
        $task->set_custom_data(['claimid' => $claimid]);
        $task->set_component('block_fredkiteamagents');
        if ($runat > time()) {
            // The waiting time: the person can still correct the post. The agent reads it only when it answers.
            $task->set_next_run_time($runat);
        }
        \core\task\manager::queue_adhoc_task($task);
        return true;
    }

    /**
     * Let an agent answer a post now, while the person waits (learning space). Uses the claim of an answer that
     * is already queued, so the background task finds it done and stops.
     *
     * @param \stdClass $agent Agent record.
     * @param int $postid Post to answer.
     * @param string $reason mention or convene.
     * @return int Post id of the answer, 0 if the agent must not answer.
     */
    public static function answer_now(\stdClass $agent, int $postid, string $reason): int {
        global $DB;
        self::$refusal = '';
        $claim = $DB->get_record(self::TABLE, ['agentid' => $agent->id, 'replyto' => $postid]);
        $claimid = $claim ? (int)$claim->id : self::claim($agent, $postid, $reason);
        if (!$claimid) {
            return 0;
        }
        self::respond($claimid);
        $answer = (int)$DB->get_field(self::TABLE, 'postid', ['id' => $claimid]);
        if (!$answer && $DB->record_exists(self::TABLE, ['id' => $claimid])) {
            // Cron writes this answer at the same moment; it appears as soon as it is done.
            self::$refusal = 'busy';
        }
        return $answer;
    }

    /**
     * Answers of an agent since midnight (including answers still being written).
     *
     * @param int $agentid Agent id.
     * @return int
     */
    public static function answers_today(int $agentid): int {
        global $DB;
        return $DB->count_records_select(
            self::TABLE,
            'agentid = :agentid AND timecreated >= :since',
            ['agentid' => $agentid, 'since' => usergetmidnight(time())]
        );
    }

    /**
     * Find recent posts by people that mention an agent and queue the answers. Safe to repeat: an agent answers a
     * post only once.
     *
     * @param int $window Seconds back from now.
     * @return int Number of answers queued.
     */
    public static function find_mentions(int $window): int {
        global $DB;
        $courses = $DB->get_fieldset_select(
            agents::TABLE,
            'DISTINCT courseid',
            'active = 1 AND status = :ready',
            ['ready' => 'ready']
        );
        if (!$courses) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $courses), SQL_PARAMS_NAMED);
        $params['since'] = time() - $window;
        $params['at'] = '%@%';
        $posts = $DB->get_records_sql("SELECT p.id, p.userid, p.message, d.course
                                         FROM {forum_posts} p
                                         JOIN {forum_discussions} d ON d.id = p.discussion
                                        WHERE d.course $insql AND p.created > :since AND p.deleted = 0
                                              AND " . $DB->sql_like('p.message', ':at') . "
                                     ORDER BY p.created ASC", $params, 0, 500);
        $queued = 0;
        foreach ($posts as $post) {
            if (!self::by_person($post)) {
                continue;
            }
            foreach (self::mentioned((int)$post->course, (string)$post->message) as $agent) {
                $queued += self::queue($agent, (int)$post->id, 'mention') ? 1 : 0;
            }
        }
        return $queued;
    }

    /**
     * Ask all active agents for their view on a conversation: they answer the latest post of a person.
     *
     * @param int $discussionid Discussion id.
     * @return int Number of agents asked.
     */
    public static function convene(int $discussionid): int {
        global $DB;
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
        $posts = $DB->get_records('forum_posts', ['discussion' => $discussionid, 'deleted' => 0], 'created DESC, id DESC');
        $target = null;
        foreach ($posts as $post) {
            if (self::by_person($post)) {
                $target = $post;
                break;
            }
        }
        if (!$target) {
            return 0;
        }
        $count = 0;
        foreach (agents::speaking((int)$discussion->course) as $agent) {
            $count += self::queue($agent, (int)$target->id, 'convene') ? 1 : 0;
        }
        return $count;
    }

    /**
     * Write the answer of a queued claim. Runs in the background.
     *
     * @param int $claimid Claim record id.
     */
    public static function respond(int $claimid): void {
        // One writer per answer: the person waiting in the browser and cron may try at the same moment.
        $lock = \core\lock\lock_config::get_lock_factory('block_fredkiteamagents')->get_lock('claim' . $claimid, 120);
        if (!$lock) {
            return;
        }
        try {
            self::respond_locked($claimid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Write the answer of a claim; the caller holds the lock.
     *
     * @param int $claimid Claim record id.
     */
    protected static function respond_locked(int $claimid): void {
        global $DB;
        $claim = $DB->get_record(self::TABLE, ['id' => $claimid]);
        if (!$claim || $claim->postid) {
            return;
        }
        $agent = $DB->get_record(agents::TABLE, ['id' => $claim->agentid]);
        $post = $DB->get_record('forum_posts', ['id' => $claim->replyto, 'deleted' => 0]);
        if (!$agent || !$agent->active || $agent->status !== 'ready' || !$post) {
            $DB->delete_records(self::TABLE, ['id' => $claimid]);
            return;
        }
        try {
            $labels = [];
            $answer = gateway::chat($agent, $claim->reason, self::conversation($post, $agent, $labels));
            // FredKI only saw "Person A", "Person B": put the first names back before the post is published.
            $answer = self::personalise($answer, $labels);
        } catch (\Throwable $e) {
            // Free the claim so that a later mention or "convene" can try again.
            $DB->delete_records(self::TABLE, ['id' => $claimid]);
            throw $e;
        }
        $claim->postid = self::write($agent, $post, $answer);
        $DB->update_record(self::TABLE, $claim);
    }

    /**
     * The conversation as plain text for the agent. People appear as "Person A", "Person B".
     *
     * @param \stdClass $post Post to answer.
     * @param \stdClass $agent Agent record.
     * @param array $labels Neutral names ("Person A") per user id; filled in while the conversation is built.
     * @return string
     */
    protected static function conversation(\stdClass $post, \stdClass $agent, array &$labels = []): string {
        global $DB;
        $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
        $posts = $DB->get_records('forum_posts', ['discussion' => $post->discussion, 'deleted' => 0], 'created ASC, id ASC');
        $posts = array_slice($posts, -self::CONTEXT_POSTS, null, true);
        $names = [];
        $label = function (\stdClass $p) use (&$names, &$labels, $agent): string {
            if ((int)$p->userid === (int)$agent->userid) {
                return get_string('you', 'block_fredkiteamagents');
            }
            foreach (agents::for_course((int)$agent->courseid) as $a) {
                if ((int)$a->userid === (int)$p->userid) {
                    $t = templates::get($a->template);
                    return $t ? $t['name'] : 'Agent';
                }
            }
            if ((int)$p->userid === (int)get_config('format_fredki', 'botuserid')) {
                return 'FredKI';
            }
            if (!isset($names[$p->userid])) {
                $names[$p->userid] = get_string('person', 'block_fredkiteamagents', chr(65 + count($names) % 26));
                $labels[$names[$p->userid]] = (int)$p->userid;
            }
            return $names[$p->userid];
        };
        // Agents that keep the overview (Supervisor, Moderatorin) also see the other conversations of the forum:
        // the ones active in the last 24 hours, or else the most recent ones.
        $others = [];
        $template = templates::get($agent->template) ?? [];
        if (!empty($template['overview'])) {
            $others = self::other_conversations($discussion);
        }
        // The person the agent answers is always "Person A"; then everyone else in order of appearance.
        $label($post);
        foreach ($posts as $p) {
            $label($p);
        }
        foreach ($others as $other) {
            foreach ($other['posts'] as $p) {
                $label($p);
            }
        }
        // Names must never reach FredKI, also not from earlier posts that already address someone by name.
        $first = [];
        if ($labels) {
            foreach ($DB->get_records_list('user', 'id', array_values($labels), '', 'id, firstname, lastname') as $u) {
                foreach ([trim((string)$u->firstname), trim((string)$u->lastname)] as $n) {
                    if (\core_text::strlen($n) >= 2 && !isset($first[$n])) {
                        $first[$n] = array_search((int)$u->id, $labels, true);
                    }
                }
            }
            uksort($first, static fn($a, $b) => \core_text::strlen($b) <=> \core_text::strlen($a));
        }
        $clean = function (string $html) use ($first): string {
            $text = html_entity_decode(
                strip_tags(preg_replace('~<(?:br|/p|/li|/div|/h[1-6])\b[^>]*>~i', ' ', $html)),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            foreach ($first as $name => $lab) {
                $text = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(s?)(?![\p{L}\p{N}])/u', $lab . '$1', $text);
            }
            return trim(preg_replace('/\s+/u', ' ', $text));
        };
        $lines = [get_string('prompt_language', 'block_fredkiteamagents', self::answer_language($post, (int)$agent->courseid)),
            get_string('prompt_style', 'block_fredkiteamagents'),
            get_string('prompt_topic', 'block_fredkiteamagents', $clean($discussion->name))];
        foreach ($posts as $p) {
            if ((int)$p->id === (int)$post->id) {
                continue;
            }
            $lines[] = $label($p) . ': ' . shorten_text($clean($p->message), 600);
        }
        $lines[] = '';
        $lines[] = get_string('prompt_answerto', 'block_fredkiteamagents', $label($post));
        $lines[] = shorten_text($clean($post->message), 2000);
        $text = implode("\n", $lines);
        if ($others) {
            $extra = ['', get_string('prompt_overview', 'block_fredkiteamagents')];
            foreach ($others as $other) {
                $block = ['', get_string('prompt_topic', 'block_fredkiteamagents', $clean($other['name']))];
                foreach ($other['posts'] as $p) {
                    $block[] = $label($p) . ': ' . shorten_text($clean($p->message), 300);
                }
                $block = implode("\n", $block);
                if (strlen($text) + strlen(implode("\n", $extra)) + strlen($block) + 2 > self::MAX_BYTES) {
                    break;
                }
                $extra[] = $block;
            }
            if (count($extra) > 2) {
                $text .= "\n" . implode("\n", $extra);
            }
        }
        return $text;
    }

    /**
     * Seconds the agents wait before they answer a new post, so the person can still correct it (site setting).
     *
     * @return int
     */
    public static function answer_delay(): int {
        $delay = get_config('block_fredkiteamagents', 'answerdelay');
        return $delay === false || $delay === '' ? 120 : max(0, (int)$delay);
    }

    /** Frequent short words of some languages, to see which language a post is written in. */
    const LANGUAGE_WORDS = [
        'en' => ['the', 'and', 'is', 'are', 'what', 'why', 'how', 'there', 'any', 'you', 'we', 'do', 'does', 'of',
            'to', 'in', 'it', 'this', 'that', 'not', 'can', 'with', 'for', 'more', 'have', 'would', 'should', 'which',
            'me', 'my', 'please', 'give', 'a', 'an', 'about', 'or', 'who', 'where'],
        'de' => ['der', 'die', 'das', 'und', 'ist', 'sind', 'was', 'warum', 'wie', 'es', 'gibt', 'nicht', 'ich', 'wir',
            'du', 'ein', 'eine', 'mit', 'für', 'auf', 'zu', 'den', 'dem', 'noch', 'mehr', 'auch', 'kann', 'welche',
            'mir', 'mich', 'dir', 'dich', 'bitte', 'gib', 'kannst', 'wer', 'wo', 'mein', 'meine', 'einen', 'über', 'oder'],
        'fr' => ['le', 'la', 'les', 'et', 'est', 'sont', 'pourquoi', 'comment', 'que', 'qui', 'nous', 'vous', 'une',
            'des', 'pas', 'pour', 'avec', 'dans', 'il', 'y', 'a', 'plus'],
        'es' => ['el', 'la', 'los', 'las', 'y', 'es', 'son', 'por', 'qué', 'cómo', 'que', 'hay', 'una', 'para', 'con',
            'no', 'más', 'nosotros', 'de', 'en'],
        'it' => ['il', 'lo', 'la', 'gli', 'le', 'e', 'è', 'sono', 'perché', 'come', 'che', 'non', 'una', 'per', 'con',
            'più', 'ci', 'del', 'della'],
        'nl' => ['de', 'het', 'een', 'en', 'is', 'zijn', 'waarom', 'hoe', 'wat', 'er', 'niet', 'wij', 'we', 'met',
            'voor', 'van', 'geen', 'meer'],
    ];

    /**
     * Language the answer should be in. In this order: the language forced for the course; the language the post is
     * written in; the language of the person who asks right now (web request); the language of the person who wrote
     * the post; the language of the site. Named in English ("English", "German"), so the agent understands it
     * whatever language its prompt is in.
     *
     * @param \stdClass $post Post that is answered.
     * @param int $courseid Course id.
     * @return string
     */
    protected static function answer_language(\stdClass $post, int $courseid): string {
        global $CFG, $DB;
        $sm = get_string_manager();
        $lang = (string)$DB->get_field('course', 'lang', ['id' => $courseid]);
        if ($lang === '') {
            $lang = self::detect_language(strip_tags((string)$post->subject . ' ' . (string)$post->message));
        }
        if ($lang === '' && !CLI_SCRIPT && !(defined('CRON_SCRIPT') && CRON_SCRIPT)) {
            $lang = current_language();
        }
        if ($lang === '') {
            $lang = (string)$DB->get_field('user', 'lang', ['id' => $post->userid]);
        }
        if ($lang === '' || (strlen($lang) > 2 && !$sm->translation_exists($lang))) {
            $lang = $lang !== '' ? explode('_', $lang)[0] : (string)$CFG->lang;
        }
        $code = explode('_', $lang)[0];
        $names = $sm->get_list_of_languages('en');
        return $names[$code] ?? $code;
    }

    /**
     * Which language a short text is written in, by its frequent words. Empty if not clear.
     *
     * @param string $text Text.
     * @return string Language code or empty string.
     */
    public static function detect_language(string $text): string {
        $text = \core_text::strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Mentions like @Expertin say nothing about the language.
        $text = preg_replace('/@[\p{L}\p{N}_-]+/u', ' ', $text);
        $words = preg_split('/[^\p{L}\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $scores = [];
        foreach (self::LANGUAGE_WORDS as $code => $list) {
            $scores[$code] = count(array_intersect($words, $list));
        }
        arsort($scores);
        $best = (int)reset($scores);
        $code = (string)key($scores);
        $second = (int)next($scores);
        // At least two hits and clearly more than any other language.
        if ($best >= 2 && $best >= $second * 2) {
            return $code;
        }
        return '';
    }

    /**
     * Other conversations of the same forum, for agents that keep the overview.
     *
     * @param \stdClass $discussion The conversation being answered.
     * @return array List of ['name' => subject, 'posts' => posts oldest first].
     */
    protected static function other_conversations(\stdClass $discussion): array {
        global $DB;
        $params = ['forum' => $discussion->forum, 'id' => $discussion->id];
        $sql = 'forum = :forum AND id <> :id';
        $recent = $DB->get_records_select(
            'forum_discussions',
            $sql . ' AND timemodified >= :since',
            $params + ['since' => time() - DAYSECS],
            'timemodified DESC',
            'id, name',
            0,
            self::OVERVIEW_DISCUSSIONS
        );
        if (!$recent) {
            $recent = $DB->get_records_select(
                'forum_discussions',
                $sql,
                $params,
                'timemodified DESC',
                'id, name',
                0,
                self::OVERVIEW_DISCUSSIONS
            );
        }
        $result = [];
        foreach ($recent as $d) {
            $posts = $DB->get_records(
                'forum_posts',
                ['discussion' => $d->id, 'deleted' => 0],
                'created DESC, id DESC',
                'id, userid, message, created',
                0,
                self::OVERVIEW_POSTS
            );
            if ($posts) {
                $result[] = ['name' => (string)$d->name, 'posts' => array_reverse($posts)];
            }
        }
        return $result;
    }

    /**
     * Replace "Person A" etc. in an answer with the first names of the people. Moodle keeps the names; FredKI never
     * receives them.
     *
     * @param string $answer Answer of the agent.
     * @param array $labels Label => user id.
     * @return string
     */
    public static function personalise(string $answer, array $labels): string {
        global $DB;
        if (!$labels) {
            return $answer;
        }
        $names = $DB->get_records_list('user', 'id', array_values($labels), '', 'id, firstname');
        // Longest labels first, so "Person A" never cuts into a longer label.
        uksort($labels, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($labels as $label => $userid) {
            $first = trim((string)($names[$userid]->firstname ?? ''));
            if ($first === '') {
                continue;
            }
            // Placeholders: "Person A" becomes "Ralf", the genitive "Person As" becomes "Ralfs".
            $answer = preg_replace(
                '/(?<![\p{L}\p{N}])' . preg_quote($label, '/') . '(s?)(?![\p{L}\p{N}])/u',
                str_replace(['\\', '$'], ['\\\\', '\\$'], $first) . '$1',
                $answer
            );
        }
        return $answer;
    }

    /**
     * Write the answer as a forum post of the agent account.
     *
     * @param \stdClass $agent Agent record.
     * @param \stdClass $parent Post that is answered.
     * @param string $answer Answer text (Markdown).
     * @return int New post id.
     */
    protected static function write(\stdClass $agent, \stdClass $parent, string $answer): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $discussion = $DB->get_record('forum_discussions', ['id' => $parent->discussion], '*', MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course, false, MUST_EXIST);
        $now = time();
        $html = clean_text(markdown_to_html(trim($answer)), FORMAT_HTML);
        $post = (object)[
            'discussion' => $discussion->id,
            'parent' => $parent->id,
            'userid' => $agent->userid,
            'created' => $now,
            'modified' => $now,
            'mailed' => FORUM_MAILED_PENDING,
            'subject' => shorten_text(get_string('re', 'forum') . ' ' . $discussion->name, 250),
            'message' => $html,
            'messageformat' => FORMAT_HTML,
            'messagetrust' => 0,
            'attachment' => '',
            'totalscore' => 0,
            'mailnow' => 0,
            'privatereplyto' => 0,
            'deleted' => 0,
        ];
        \mod_forum\local\entities\post::add_message_counts($post);
        $post->id = $DB->insert_record('forum_posts', $post);
        $DB->set_field('forum_discussions', 'timemodified', $now, ['id' => $discussion->id]);
        $DB->set_field('forum_discussions', 'usermodified', $agent->userid, ['id' => $discussion->id]);
        \mod_forum\event\post_created::create([
            'context' => \context_module::instance($cm->id),
            'objectid' => $post->id,
            'relateduserid' => $agent->userid,
            'other' => ['discussionid' => $discussion->id, 'forumid' => $forum->id, 'forumtype' => $forum->type],
        ])->trigger();
        return (int)$post->id;
    }
}
