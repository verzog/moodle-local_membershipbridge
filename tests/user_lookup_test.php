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
 * Tests for the batched member ID lookup.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge;

/**
 * Unit tests for {@see user_lookup}.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(user_lookup::class)]
final class user_lookup_test extends \advanced_testcase {
    /**
     * Reset the database and match members on ID number.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('imis_id_source', 'idnumber', 'local_membershipbridge');
    }

    /**
     * Preloaded answers match the single-row lookup: one match, none, many, deleted.
     *
     * @return void
     */
    public function test_preload_matches_single_lookup(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $one = $gen->create_user(['idnumber' => '30001']);
        $gen->create_user(['idnumber' => '30002']);
        $gen->create_user(['idnumber' => '30002']);
        $gone = $gen->create_user(['idnumber' => '30003']);
        delete_user($gone);

        $lookup = new user_lookup();
        $lookup->preload(['30001', ' 30002 ', '30003', '30004', '']);

        // IDs with exact matches are answered from the preload, with no further queries.
        $reads = $DB->perf_get_reads();
        $this->assertSame((int)$one->id, (int)$lookup->find('30001')->id);
        $this->assertNull($lookup->find('30002'), 'two accounts share the ID, so it is ambiguous');
        $this->assertNull($lookup->find(''));
        $this->assertSame($reads, $DB->perf_get_reads());

        // IDs with no exact match fall back to the single-row lookup.
        $this->assertNull($lookup->find('30003'), 'a deleted account does not count');
        $this->assertNull($lookup->find('30004'));

        foreach (['30001', '30002', '30003', '30004'] as $imisid) {
            $this->assertEquals(util::find_user_by_imis_id($imisid), $lookup->find($imisid), $imisid);
        }
    }

    /**
     * Values that differ only in case or accents get the same answer as the single-row
     * lookup, whichever way the database's collation compares them: ambiguous where it
     * treats them as equal, distinct where it does not.
     *
     * @return void
     */
    public function test_preload_follows_database_collation(): void {
        $gen = $this->getDataGenerator();
        $gen->create_user(['idnumber' => 'cafe']);
        $gen->create_user(['idnumber' => 'café']);
        $gen->create_user(['idnumber' => 'abc']);
        $gen->create_user(['idnumber' => 'ABC']);
        $gen->create_user(['idnumber' => 'Solo']);

        $ids = ['cafe', 'café', 'abc', 'ABC', 'solo', 'Solo'];
        $lookup = new user_lookup();
        $lookup->preload($ids);

        foreach ($ids as $imisid) {
            $expected = util::find_user_by_imis_id($imisid);
            $actual = $lookup->find($imisid);
            $this->assertSame($expected === null, $actual === null, $imisid);
            if ($expected !== null) {
                $this->assertSame((int)$expected->id, (int)$actual->id, $imisid);
            }
        }
    }

    /**
     * An ID that was not preloaded is looked up singly, and forget() makes a new account visible.
     *
     * @return void
     */
    public function test_fallback_and_forget(): void {
        $lookup = new user_lookup();
        $this->assertNull($lookup->find('30010'));

        $user = $this->getDataGenerator()->create_user(['idnumber' => '30010']);
        $this->assertNull($lookup->find('30010'), 'the earlier answer is remembered');

        $lookup->forget('30010');
        $this->assertSame((int)$user->id, (int)$lookup->find('30010')->id);
    }

    /**
     * A username match is limited to local accounts, as in the single-row lookup.
     *
     * @return void
     */
    public function test_username_preload_ignores_remote_mnet_accounts(): void {
        global $CFG;
        set_config('imis_id_source', 'username', 'local_membershipbridge');
        $this->getDataGenerator()->create_user(['username' => '30020', 'mnethostid' => $CFG->mnet_localhost_id + 1]);
        $local = $this->getDataGenerator()->create_user(['username' => '30021']);

        $lookup = new user_lookup();
        $lookup->preload(['30020', '30021']);

        $this->assertNull($lookup->find('30020'));
        $this->assertSame((int)$local->id, (int)$lookup->find('30021')->id);
    }
}
