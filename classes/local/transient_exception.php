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
 * A failure talking to the licence server, with a safe diagnostic code and whether a retry may help.
 *
 * @package    block_fredkiteamagents
 * @copyright  2026 lernsteine.com
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transient_exception extends \moodle_exception {
    /** @var bool Whether trying again later may help. */
    public bool $transient;

    /**
     * Constructor.
     *
     * @param string $diagnostic Safe diagnostic code.
     * @param bool $transient Whether a retry may help.
     */
    public function __construct(string $diagnostic, bool $transient) {
        $this->transient = $transient;
        parent::__construct('error_server', 'block_fredkiteamagents', '', $diagnostic, $diagnostic);
    }
}
