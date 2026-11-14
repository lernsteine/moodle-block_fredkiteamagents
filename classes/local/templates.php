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
 * Agent templates. The prompt of each template lives in FredKI; Moodle only knows how the agent looks.
 *
 * Step 1 offers one template. More follow in step 2.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class templates {
    /** @var array Templates by key: colour, picture in pix/, name used for @mentions, overview = sees the whole forum. */
    const TEMPLATES = [
        'expertin' => ['colour' => '#3149d6', 'image' => 'agent_expertin', 'mention' => 'Expertin'],
        'kritiker' => ['colour' => '#b4432c', 'image' => 'agent_kritiker', 'mention' => 'Kritikerin',
            'aliases' => ['Kritiker']],
        'optimistin' => ['colour' => '#c98a12', 'image' => 'agent_optimistin', 'mention' => 'Optimistin'],
        'praktiker' => ['colour' => '#2f7d4f', 'image' => 'agent_praktiker', 'mention' => 'Praktikerin',
            'aliases' => ['Praktiker']],
        // No longer offered; agents already in a course keep working.
        'zielgruppe' => ['colour' => '#8a3fa6', 'image' => 'agent_zielgruppe', 'mention' => 'Zielgruppe', 'retired' => true],
        'moderatorin' => ['colour' => '#256f8f', 'image' => 'agent_moderatorin', 'mention' => 'Moderatorin', 'overview' => true],
        'supervisor' => ['colour' => '#3d4552', 'image' => 'agent_supervisor', 'mention' => 'Supervisorin', 'overview' => true,
            'aliases' => ['Supervisor']],
        // The learning companion talks with each person privately in the learning space; it never posts in the forum.
        'lernbegleiter' => ['colour' => '#4f5ff0', 'image' => 'agent_lernbegleiter', 'mention' => 'Lernbegleiter',
            'companion' => true],
    ];

    /** @var string[] Calm colours for templates without their own. */
    const PALETTE = ['#3149d6', '#b4432c', '#c98a12', '#2f7d4f', '#256f8f', '#3d4552', '#8a3fa6', '#a2475e'];

    /** @var int Seconds before the list of templates is asked from the licence server again. */
    const TTL = HOURSECS;

    /**
     * The templates found in FredKI, as last told by the licence server. Null if never known.
     *
     * @return array|null
     */
    public static function remote(): ?array {
        $raw = get_config('block_fredkiteamagents', 'templates');
        if ($raw === false || $raw === '') {
            return null;
        }
        $list = json_decode((string)$raw, true);
        return is_array($list) ? $list : null;
    }

    /**
     * Ask the licence server for the templates again when the list is older than an hour (or when forced).
     * New names also go to the agent accounts and their folders.
     *
     * @param bool $force Ask now.
     */
    public static function refresh(bool $force = false): void {
        if (!$force && (int)get_config('block_fredkiteamagents', 'templatesat') > time() - self::TTL) {
            return;
        }
        set_config('templatesat', time(), 'block_fredkiteamagents');
        if (!gateway::is_ready()) {
            return;
        }
        try {
            $list = gateway::templates($force);
        } catch (\Throwable $e) {
            return;
        }
        $old = self::remote();
        set_config('templates', json_encode($list), 'block_fredkiteamagents');
        if ($old !== $list) {
            agents::refresh_pictures();
        }
    }

    /**
     * All templates known: those in FredKI and those of agents already in courses.
     *
     * @return array
     */
    public static function all(): array {
        global $DB;
        $keys = array_keys(self::TEMPLATES);
        $remote = self::remote();
        if ($remote !== null) {
            $used = $DB->get_fieldset_sql('SELECT DISTINCT template FROM {' . agents::TABLE . '}');
            $keys = array_merge(array_keys($remote), $used);
        }
        $result = [];
        foreach (array_unique($keys) as $key) {
            $result[$key] = self::get((string)$key);
        }
        return $result;
    }

    /**
     * Templates that can be added to a course: the ones in FredKI (before the first contact: the built-in ones).
     *
     * @return array
     */
    public static function addable(): array {
        $remote = self::remote();
        $result = [];
        if ($remote !== null) {
            foreach (array_keys($remote) as $key) {
                $result[$key] = self::get((string)$key);
            }
            return $result;
        }
        foreach (self::TEMPLATES as $key => $t) {
            if (empty($t['retired'])) {
                $result[$key] = self::get($key);
            }
        }
        return $result;
    }

    /**
     * Colour, picture and mention of this plugin for a template: by key, or by name for a template created anew
     * in FredKI (e.g. "Vorlage – Kritikerin" gets the picture of the critic).
     *
     * @param string $key Template key.
     * @param string $name Name of the template.
     * @return array
     */
    protected static function decoration(string $key, string $name): array {
        if (isset(self::TEMPLATES[$key])) {
            return self::TEMPLATES[$key] + ['_key' => $key];
        }
        $word = \core_text::strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $name));
        foreach (self::TEMPLATES as $known => $t) {
            $names = array_map(
                static fn(string $n): string => \core_text::strtolower($n),
                array_merge([$known, $t['mention']], $t['aliases'] ?? [])
            );
            if (in_array($word, $names, true) || in_array($key, $names, true)) {
                unset($t['aliases']);
                return $t + ['_key' => $known];
            }
        }
        return [];
    }

    /**
     * Whether a text from FredKI carries several languages (multi-language tags).
     *
     * @param string $text Text.
     * @return bool
     */
    public static function has_languages(string $text): bool {
        return self::language_parts($text) !== [];
    }

    /**
     * Language parts of a text with multi-language tags, keyed by language code.
     *
     * Tolerant on purpose: FredKI or a browser may encode the tags as entities, swap the attributes or use
     * single quotes. Supported: <span lang="de" class="multilang">…</span> and {mlang de}…{mlang}.
     *
     * @param string $text Text from FredKI.
     * @return array
     */
    protected static function language_parts(string $text): array {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['„', '“', '”', '″'], '"', $text);
        $parts = [];
        if (preg_match_all('~<span\b([^>]*)>(.*?)</span>~siu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                if (
                    stripos($match[1], 'multilang') !== false
                        && preg_match('~\blang\s*=\s*["\']?([a-zA-Z_-]+)~', $match[1], $lang)
                ) {
                    $parts[strtolower(str_replace('-', '_', $lang[1]))] = trim($match[2]);
                }
            }
        }
        if (!$parts && preg_match_all('~\{mlang\s+([a-zA-Z_-]+)\s*\}(.*?)\{mlang\}~su', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $parts[strtolower(str_replace('-', '_', $match[1]))] = trim($match[2]);
            }
        }
        return $parts;
    }

    /**
     * The part of a text in the current language. FredKI names and descriptions may hold several languages, as
     * Moodle's multi-language tags write them: <span lang="de" class="multilang">…</span><span lang="en" …>…</span>
     * or {mlang de}…{mlang}{mlang en}…{mlang}. Without such tags the text stays as it is.
     *
     * @param string $text Text from FredKI.
     * @return string
     */
    public static function in_language(string $text): string {
        $parts = self::language_parts($text);
        if (!$parts) {
            return trim(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        $lang = current_language();
        $short = explode('_', $lang)[0];
        $pick = $parts[$lang] ?? $parts[$short] ?? $parts['en'] ?? reset($parts);
        return trim(strip_tags($pick));
    }

    /**
     * Template key without the suffix of an agent that is being removed ("expertin-42" → "expertin").
     *
     * @param string $key Stored template key.
     * @return string
     */
    public static function base(string $key): string {
        return preg_replace('/-\d+$/D', '', $key);
    }

    /**
     * One template for display. Name and role come from FredKI; colour and picture from this plugin, or calm defaults
     * for a template that is new in FredKI.
     *
     * @param string $key Template key.
     * @return array|null
     */
    public static function get(string $key): ?array {
        $key = self::base($key);
        if (!preg_match('/^[a-z0-9]{1,40}$/D', $key)) {
            return null;
        }
        $remote = self::remote()[$key] ?? null;
        $sm = get_string_manager();
        $name = $remote['name'] ?? ($sm->string_exists('template_' . $key, 'block_fredkiteamagents')
            ? get_string('template_' . $key, 'block_fredkiteamagents') : $key);
        $t = self::decoration($key, $name);
        $role = ($remote['role'] ?? '') !== '' ? $remote['role']
            : ($sm->string_exists('template_' . $key . '_role', 'block_fredkiteamagents')
                ? get_string('template_' . $key . '_role', 'block_fredkiteamagents') : '');
        // FredKI names and roles are German. In another language, the known roles take this plugin's translation
        // ("Expert", "Critic", …); any template may also carry several languages in FredKI, see in_language().
        $original = $name;
        // A text from FredKI with its own languages always wins; only plain German texts are translated here.
        $foreign = isset($t['_key']) && strpos(current_language(), 'de') !== 0;
        $name = $foreign && !self::has_languages($name) ? get_string('template_' . $t['_key'], 'block_fredkiteamagents')
            : self::in_language($name);
        $role = $foreign && !self::has_languages($role)
            ? get_string('template_' . $t['_key'] . '_role', 'block_fredkiteamagents') : self::in_language($role);
        $name = \core_text::substr($name, 0, 60);
        $role = \core_text::substr($role, 0, 160);
        $image = $t['image'] ?? 'agent_default';
        $file = __DIR__ . '/../../pix/' . $image . '.jpg';
        // A mention is the name in one word: "Kritikerin", "KritischeFreundin". The German and the shown name both work.
        $mention = $t['mention'] ?? preg_replace('/[^\p{L}\p{N}]+/u', '', self::in_language($original));
        $aliases = $t['aliases'] ?? [];
        foreach ([$name, self::in_language($original)] as $spoken) {
            $word = preg_replace('/[^\p{L}\p{N}]+/u', '', $spoken);
            if ($word !== '' && \core_text::strtolower($word) !== \core_text::strtolower($mention)) {
                $aliases[] = $word;
            }
        }
        return [
            'key' => $key,
            'name' => $name,
            'role' => $role,
            'colour' => $t['colour'] ?? self::PALETTE[crc32($key) % count(self::PALETTE)],
            // The file date in the address makes browsers load a new picture after an update.
            'image' => (new \moodle_url('/blocks/fredkiteamagents/pix/' . $image . '.jpg', ['v' => (int)@filemtime($file)]))
                ->out(false),
            'imagefile' => $file,
            'mention' => $mention,
            'aliases' => array_values(array_unique($aliases)),
            'overview' => !empty($t['overview']),
            'companion' => !empty($t['companion']),
        ];
    }

    /**
     * Whether a template is the learning companion (private talks, no forum posts).
     *
     * @param string $key Template key.
     * @return bool
     */
    public static function is_companion(string $key): bool {
        $key = self::base($key);
        if (!empty(self::TEMPLATES[$key]['companion'])) {
            return true;
        }
        // A companion template created anew in FredKI under another key, e.g. "Vorlage – Lernbegleiterin".
        $t = self::get($key);
        return $t !== null && !empty($t['companion']);
    }
}
