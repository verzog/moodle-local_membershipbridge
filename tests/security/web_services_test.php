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
 * Security tests for the local_membershipbridge web services.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\security;

use local_membershipbridge\external\cancel_enrolment;
use local_membershipbridge\external\get_completions_since;

/**
 * Who may call the web services, in which context, and with what input.
 *
 * These lock in the access rules so a later change cannot quietly widen them:
 * no role holds either capability by default, a course-level grant only works in
 * that course, the completions export needs a system-level grant, and IDs that
 * are not plain integers are rejected before any work is done.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cancel_enrolment::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_completions_since::class)]
final class web_services_test extends \advanced_testcase {
    /** @var string Capability for the cancel-enrolment web service. */
    private const CAP_CANCEL = 'local/membershipbridge:cancelenrolment';

    /** @var string Capability for the completions export web service. */
    private const CAP_READ = 'local/membershipbridge:readcompletions';

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
     * Enrol a learner as a student and return them.
     *
     * @param int $courseid The course to enrol them in.
     * @return \stdClass The learner.
     */
    private function enrolled_learner(int $courseid): \stdClass {
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $courseid, 'student');
        return $learner;
    }

    /**
     * Whether the learner still has active access to the course.
     *
     * @param int $userid   The learner.
     * @param int $courseid The course.
     * @return bool
     */
    private function is_active(int $userid, int $courseid): bool {
        return is_enrolled(\context_course::instance($courseid), $userid, '', true);
    }

    /**
     * Create a role that holds one capability, allowed only in the given context.
     *
     * @param string    $capability The capability to allow.
     * @param \context  $context    Where the capability is allowed.
     * @return int The new role id.
     */
    private function role_with(string $capability, \context $context): int {
        $roleid = create_role('Bridge test role', 'bridgetest' . random_string(6), '');
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id);
        return $roleid;
    }

    /**
     * Neither capability is granted to any role out of the box, and each carries the
     * risk flag that warns admins when they grant it.
     *
     * @return void
     */
    public function test_capabilities_have_no_default_roles(): void {
        $system = \context_system::instance();

        $this->assertEmpty(get_roles_with_capability(self::CAP_CANCEL, CAP_ALLOW, $system));
        $this->assertEmpty(get_roles_with_capability(self::CAP_READ, CAP_ALLOW, $system));

        $this->assertNotEmpty(get_capability_info(self::CAP_CANCEL)->riskbitmask & RISK_DATALOSS);
        $this->assertNotEmpty(get_capability_info(self::CAP_READ)->riskbitmask & RISK_PERSONAL);
    }

    /**
     * Both functions name their capability, are not callable from the browser (AJAX),
     * and live in a service restricted to named users with no file transfer.
     *
     * @return void
     */
    public function test_service_definition_is_locked_down(): void {
        global $CFG;

        $functions = [];
        $services = [];
        require($CFG->dirroot . '/local/membershipbridge/db/services.php');

        $expected = [
            'local_membershipbridge_get_completions_since' => self::CAP_READ,
            'local_membershipbridge_cancel_enrolment' => self::CAP_CANCEL,
        ];
        foreach ($expected as $name => $capability) {
            $this->assertArrayHasKey($name, $functions);
            $this->assertSame($capability, $functions[$name]['capabilities'], $name);
            $this->assertFalse($functions[$name]['ajax'] ?? false, $name . ' must not be callable via AJAX.');
        }

        $service = $services['Membership bridge'];
        $this->assertSame(1, $service['restrictedusers']);
        $this->assertSame(0, $service['downloadfiles']);
        $this->assertSame(0, $service['uploadfiles']);
    }

    /**
     * A caller without the capability is refused and the learner keeps access.
     *
     * @return void
     */
    public function test_cancel_requires_capability(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner((int)$course->id);

        // A teacher in the course: plenty of rights, but not this capability.
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        try {
            cancel_enrolment::execute([['userid' => $learner->id, 'courseid' => $course->id]]);
            $this->fail('Expected required_capability_exception.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $this->assertTrue($this->is_active((int)$learner->id, (int)$course->id));
    }

    /**
     * A grant in one course does not reach another course.
     *
     * @return void
     */
    public function test_cancel_course_grant_does_not_reach_other_courses(): void {
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $learnera = $this->enrolled_learner((int)$coursea->id);
        $learnerb = $this->enrolled_learner((int)$courseb->id);

        // The caller is in course A only, with the capability granted there.
        $contexta = \context_course::instance($coursea->id);
        $caller = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($caller->id, $coursea->id, 'student');
        role_assign($this->role_with(self::CAP_CANCEL, $contexta), $caller->id, $contexta->id);
        $this->setUser($caller);

        // Course B is refused (not enrolled there, and no grant there).
        try {
            cancel_enrolment::execute([['userid' => $learnerb->id, 'courseid' => $courseb->id]]);
            $this->fail('Course B must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertTrue(
                $e instanceof \required_capability_exception || $e instanceof \require_login_exception,
                'Unexpected exception: ' . get_class($e)
            );
        }
        $this->assertTrue($this->is_active((int)$learnerb->id, (int)$courseb->id));

        // Course A works, which proves the refusal above is about scope, not setup.
        $result = cancel_enrolment::execute([['userid' => $learnera->id, 'courseid' => $coursea->id]]);
        $this->assertSame('cancelled', $result['results'][0]['status']);
        $this->assertFalse($this->is_active((int)$learnera->id, (int)$coursea->id));
    }

    /**
     * IDs that are not plain integers.
     *
     * @return array
     */
    public static function non_integer_id_provider(): array {
        return [
            'word' => ['abc'],
            'sql injection' => ['1 OR 1=1'],
            'stacked query' => ['1; DROP TABLE mdl_user'],
            'decimal' => ['1.5'],
            'array' => [[1, 2]],
        ];
    }

    /**
     * A user or course ID that is not a plain integer is rejected before any lookup.
     *
     * @param mixed $badid The bad ID.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('non_integer_id_provider')]
    public function test_cancel_rejects_non_integer_ids(mixed $badid): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner((int)$course->id);

        foreach ([['userid' => $badid, 'courseid' => $course->id], ['userid' => $learner->id, 'courseid' => $badid]] as $entry) {
            try {
                cancel_enrolment::execute([$entry]);
                $this->fail('Expected invalid_parameter_exception.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }

        $this->assertTrue($this->is_active((int)$learner->id, (int)$course->id));
    }

    /**
     * The completions export checks in the system context, so a role granted only in
     * a course is not enough - matching the warning in db/access.php.
     *
     * @return void
     */
    public function test_completions_course_level_grant_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $caller = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($caller->id, $course->id, 'student');
        role_assign($this->role_with(self::CAP_READ, $context), $caller->id, $context->id);
        $this->setUser($caller);

        $this->expectException(\required_capability_exception::class);
        get_completions_since::execute(0);
    }

    /**
     * A system-level grant works, which proves the refusal above is about context.
     *
     * @return void
     */
    public function test_completions_system_grant_is_accepted(): void {
        $system = \context_system::instance();
        $caller = $this->getDataGenerator()->create_user();
        role_assign($this->role_with(self::CAP_READ, $system), $caller->id, $system->id);
        $this->setUser($caller);

        $result = get_completions_since::execute(0);
        $this->assertArrayHasKey('records', $result);
    }

    /**
     * Guests and logged-out visitors cannot export completions.
     *
     * @return void
     */
    public function test_completions_refuses_guest_and_logged_out(): void {
        foreach (['guest', 'none'] as $who) {
            if ($who === 'guest') {
                $this->setGuestUser();
            } else {
                $this->setUser(null);
            }
            try {
                get_completions_since::execute(0);
                $this->fail('Expected refusal for ' . $who . '.');
            } catch (\moodle_exception $e) {
                $this->assertTrue(
                    $e instanceof \required_capability_exception || $e instanceof \require_login_exception,
                    $who . ': unexpected exception ' . get_class($e)
                );
            }
        }
    }
}
