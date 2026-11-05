<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Batched member ID to Moodle user lookup for the sync loops.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge;

/**
 * Resolves member IDs to Moodle users for one sync run, with the same rules as
 * {@see util::find_user_by_imis_id()}: the configured source field, active (not
 * deleted) users only, local accounts only for usernames, and exactly one match or
 * none.
 *
 * {@see preload()} looks up a whole feed's member IDs in a few queries rather than
 * one per row, and {@see find()} answers from those results, so a member who
 * appears on many rows costs nothing extra. An ID that was not preloaded falls back
 * to a single lookup, which is then remembered too.
 *
 * Use one instance per run, as answers are kept for its lifetime. Call
 * {@see forget()} after creating an account for a member ID, so the next lookup
 * finds it.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_lookup {
    /** @var int Member IDs per query, to keep the IN list a sensible size. */
    private const CHUNK = 1000;

    /** @var array Trimmed member ID => the single matching user, or null for none or many. */
    private array $users = [];

    /**
     * Look up many member IDs at once, ahead of the calls to {@see find()}.
     *
     * Values are matched in PHP exactly as given. On a database whose comparison
     * ignores case or accents, the query can also return users whose value only
     * folds to one of the IDs. Then exact matching in PHP could disagree with what
     * the per-row query would find, so that chunk is not remembered and each of its
     * IDs is looked up singly instead, keeping the behaviour identical.
     *
     * @param string[] $imisids The member IDs.
     * @return void
     */
    public function preload(array $imisids): void {
        global $CFG, $DB;

        $wanted = [];
        foreach ($imisids as $imisid) {
            $imisid = trim((string)$imisid);
            if ($imisid !== '' && !array_key_exists($imisid, $this->users)) {
                $wanted[$imisid] = true;
            }
        }
        if (!$wanted) {
            return;
        }

        // The source field comes from a fixed allow-list, so it is safe in SQL.
        $field = util::imis_id_source();
        foreach (array_chunk(array_keys($wanted), self::CHUNK) as $chunk) {
            $chunk = array_map('strval', $chunk);
            // Two IDs that differ only in case could match each other's users on a
            // case-insensitive database: leave such a chunk to the single lookups.
            $folded = array_map(fn(string $id): string => \core_text::strtolower($id), $chunk);
            if (count(array_unique($folded)) !== count($chunk)) {
                continue;
            }

            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'mid');
            $select = "{$field} {$insql} AND deleted = 0";
            if ($field === 'username') {
                $select .= ' AND mnethostid = :mnethostid';
                $params['mnethostid'] = $CFG->mnet_localhost_id;
            }

            $inchunk = array_flip($chunk);
            $byvalue = [];
            $exact = true;
            $records = $DB->get_recordset_select('user', $select, $params);
            foreach ($records as $user) {
                $value = (string)$user->{$field};
                if (!isset($inchunk[$value])) {
                    // Matched by case or accent folding only: exact matching would differ.
                    $exact = false;
                    break;
                }
                $byvalue[$value][] = $user;
            }
            $records->close();
            if (!$exact) {
                continue;
            }

            foreach ($chunk as $imisid) {
                $matches = $byvalue[$imisid] ?? [];
                $this->users[$imisid] = (count($matches) === 1) ? reset($matches) : null;
            }
        }
    }

    /**
     * The single active user for a member ID, or null for none or many.
     *
     * @param string $imisid The member ID.
     * @return \stdClass|null
     */
    public function find(string $imisid): ?\stdClass {
        $imisid = trim($imisid);
        if ($imisid === '') {
            return null;
        }
        if (!array_key_exists($imisid, $this->users)) {
            $this->users[$imisid] = util::find_user_by_imis_id($imisid);
        }
        return $this->users[$imisid];
    }

    /**
     * Drop the remembered answer for a member ID, after an account was created for it.
     *
     * @param string $imisid The member ID.
     * @return void
     */
    public function forget(string $imisid): void {
        unset($this->users[trim($imisid)]);
    }
}
