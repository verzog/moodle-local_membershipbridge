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

/**
 * Security tests for data arriving from iMIS in local_membershipbridge.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\security;

use local_membershipbridge\rest_client;
use local_membershipbridge\tutorial\course_resolver;
use local_membershipbridge\tutorial\group_sync;
use local_membershipbridge\tutorial\membership_row;
use local_membershipbridge\tutorial\reader;

/**
 * iMIS is outside Moodle's trust boundary: anything it returns is treated as hostile.
 *
 * Covers error text shown on admin pages, and group names and member IDs that
 * the tutorial group sync writes into Moodle. iMIS is reached through in-memory
 * fakes, so no connection is made.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rest_client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(group_sync::class)]
final class hostile_imis_data_test extends \advanced_testcase {
    /**
     * Reset the database before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Error bodies an attacker controlling (or impersonating) iMIS could send.
     *
     * @return array
     */
    public static function hostile_error_provider(): array {
        return [
            'json message with image handler' => ['{"Message":"<img src=x onerror=alert(1)>Query failed"}', 'Query failed'],
            // The tags go; the inert text "alert(1)" may remain, which is harmless.
            'json exception with script' => ['{"ExceptionMessage":"<script>alert(1)</script>Bad code"}', null],
            'oauth error with svg' => ['{"error":"x","error_description":"<svg onload=alert(1)>Denied"}', 'Denied'],
            'html page with script' => ['<html><script>alert(1)</script><body>Server error</body></html>', 'Server error'],
            'nested tag trick' => ['<scr<script>ipt>alert(1)</script>Oops', null],
            'javascript link' => ['{"Message":"<a href=\"javascript:alert(1)\">Click</a>"}', 'Click'],
        ];
    }

    /**
     * An iMIS error reason never carries markup onto the admin page.
     *
     * @param string      $body     The raw error body.
     * @param string|null $expected The expected clean text, or null to check only that it is markup-free.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostile_error_provider')]
    public function test_error_reason_strips_markup(string $body, ?string $expected): void {
        $reason = rest_client::error_reason($body);

        $this->assertStringNotContainsString('<', $reason);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $reason);
        $this->assertStringNotContainsStringIgnoringCase('onload', $reason);
        if ($expected !== null) {
            $this->assertSame($expected, $reason);
        }
    }

    /**
     * A huge or malformed error body gives a short reason and never throws.
     *
     * @return void
     */
    public function test_error_reason_survives_huge_and_invalid_bodies(): void {
        $huge = rest_client::error_reason(str_repeat('<b>x</b>', 200000));
        $this->assertLessThanOrEqual(300, \core_text::strlen($huge));

        // Invalid UTF-8 bytes must not break the admin page.
        $invalid = rest_client::error_reason("Bad \xC3\x28 bytes \xFF");
        $this->assertIsString($invalid);
        $this->assertStringNotContainsString('<', $invalid);
    }

    /**
     * Run the tutorial group sync over the given rows for one mapped course.
     *
     * @param array $rows     The membership rows "from iMIS".
     * @param int   $courseid The course the code ISC_26 maps to.
     * @return array The sync report.
     */
    private function run_group_sync(array $rows, int $courseid): array {
        $reader = new class ($rows) implements reader {
            /** @var array The fixed rows. */
            private array $rows;

            /**
             * Store the fixed rows.
             *
             * @param array $rows The fixed rows.
             */
            public function __construct(array $rows) {
                $this->rows = $rows;
            }

            /**
             * Return the fixed rows.
             *
             * @return array The fixed rows.
             */
            public function fetch_rows(): array {
                return $this->rows;
            }
        };
        $resolver = new class ($courseid) implements course_resolver {
            /** @var int The mapped course id. */
            private int $courseid;

            /**
             * Store the mapped course id.
             *
             * @param int $courseid The mapped course id.
             */
            public function __construct(int $courseid) {
                $this->courseid = $courseid;
            }

            /**
             * Map only the code ISC_26.
             *
             * @param string $code The code to resolve.
             * @return int|null The course id, or null.
             */
            public function resolve_courseid(string $code): ?int {
                return ($code === 'ISC_26') ? $this->courseid : null;
            }
        };
        $options = ['trace' => function (): void {
            // Discard progress output in tests.
            return;
        }];
        return (new group_sync($reader, $resolver, $options))->run();
    }

    /**
     * Create and enrol a member whose username is their iMIS ID.
     *
     * @param string $imisid   The iMIS ID.
     * @param int    $courseid The course.
     * @return \stdClass The user.
     */
    private function member(string $imisid, int $courseid): \stdClass {
        $user = $this->getDataGenerator()->create_user(['username' => $imisid]);
        $this->getDataGenerator()->enrol_user($user->id, $courseid, 'student');
        return $user;
    }

    /**
     * A group name with markup is stored as plain text.
     *
     * @return void
     */
    public function test_group_name_markup_is_stripped(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->member('20001', (int)$course->id);

        $hostile = '<script>alert(1)</script><img src=x onerror=alert(2)>Tutorial A';
        $this->run_group_sync([new membership_row('20001', 'ISC_26', $hostile)], (int)$course->id);

        $names = $DB->get_fieldset_select('groups', 'name', 'courseid = ?', [$course->id]);
        $this->assertCount(1, $names);
        $this->assertStringNotContainsString('<', $names[0]);
        $this->assertStringContainsString('Tutorial A', $names[0]);
    }

    /**
     * A group name that is nothing but markup creates no group.
     *
     * @return void
     */
    public function test_markup_only_group_name_creates_nothing(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->member('20001', (int)$course->id);

        $report = $this->run_group_sync([new membership_row('20001', 'ISC_26', '<img src=x onerror=alert(1)>')], (int)$course->id);

        $this->assertSame(0, $DB->count_records('groups', ['courseid' => $course->id]));
        $this->assertSame(1, $report['rowsskipped']);
    }

    /**
     * An over-long group name is trimmed to fit, not allowed to break the sync.
     *
     * @return void
     */
    public function test_overlong_group_name_is_trimmed(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->member('20001', (int)$course->id);

        $this->run_group_sync([new membership_row('20001', 'ISC_26', str_repeat('Long name ', 200))], (int)$course->id);

        $names = $DB->get_fieldset_select('groups', 'name', 'courseid = ?', [$course->id]);
        $this->assertCount(1, $names);
        $this->assertLessThanOrEqual(254, \core_text::strlen($names[0]));
    }

    /**
     * Member IDs crafted to match many accounts.
     *
     * @return array
     */
    public static function hostile_member_id_provider(): array {
        return [
            'sql tautology' => ["20001' OR '1'='1"],
            'sql wildcard' => ['%'],
            'sql underscore wildcard' => ['2000_'],
            'comment' => ['20001--'],
            'padded' => [' 20001 OR 1=1'],
        ];
    }

    /**
     * A crafted member ID matches nobody, so it cannot pull other learners into a group.
     *
     * @param string $imisid The crafted ID.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostile_member_id_provider')]
    public function test_crafted_member_id_matches_nobody(string $imisid): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->member('20001', (int)$course->id);
        $this->member('20002', (int)$course->id);

        $report = $this->run_group_sync([new membership_row($imisid, 'ISC_26', 'Tutorial A')], (int)$course->id);

        $this->assertSame(0, $DB->count_records('groups_members'), 'Nobody may be added by a crafted ID.');
        $this->assertSame(0, $report['membersadded']);
    }

    /**
     * A row for a course code that is not mapped never touches any course.
     *
     * @return void
     */
    public function test_unmapped_code_touches_no_course(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->member('20001', (int)$course->id);

        $report = $this->run_group_sync([new membership_row('20001', '../ISC_26', 'Tutorial A')], (int)$course->id);

        $this->assertSame(0, $DB->count_records('groups'));
        $this->assertSame(1, $report['rowsskipped']);
    }
}
