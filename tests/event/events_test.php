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
 * Tests for the plugin's events.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\event;

/**
 * Unit tests for the events that record exports to the membership system.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(record_pushed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(manual_sync_run::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(backfill_queued::class)]
final class events_test extends \advanced_testcase {
    /**
     * A sent record is logged in its course, against the learner, with no payload.
     *
     * @return void
     */
    public function test_record_pushed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        $sink = $this->redirectEvents();
        record_pushed::trigger_for('completion', (object)[
            'imisid'   => '20018',
            'userid'   => (int)$user->id,
            'courseid' => (int)$course->id,
            'score'    => 87.5,
        ]);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(record_pushed::class, $event);
        $this->assertEquals(\context_course::instance($course->id), $event->get_context());
        $this->assertEquals($user->id, $event->relateduserid);
        $this->assertSame('completion', $event->other['operation']);
        $this->assertSame('20018', $event->other['member']);
        $this->assertArrayNotHasKey('score', $event->other);
        $this->assertNotEmpty($event->get_description());
    }

    /**
     * With no single user behind the member and no course, the event is still
     * recorded, in the system context.
     *
     * @return void
     */
    public function test_record_pushed_without_user_or_course(): void {
        $this->resetAfterTest();

        $sink = $this->redirectEvents();
        record_pushed::trigger_for('modulenote', (object)['imisid' => '20018', 'userid' => 0, 'courseid' => 0]);
        $event = $sink->get_events()[0];
        $sink->close();

        $this->assertEquals(\context_system::instance(), $event->get_context());
        $this->assertNull($event->relateduserid);
    }

    /**
     * An unknown operation is refused.
     *
     * @return void
     */
    public function test_record_pushed_rejects_unknown_operation(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        record_pushed::trigger_for('delete', (object)['imisid' => '20018']);
    }

    /**
     * A manual sync is logged with its action and member.
     *
     * @return void
     */
    public function test_manual_sync_run(): void {
        $this->resetAfterTest();

        $sink = $this->redirectEvents();
        manual_sync_run::create([
            'context' => \context_system::instance(),
            'other'   => ['action' => 'enrollments', 'member' => '20018'],
        ])->trigger();
        $event = $sink->get_events()[0];
        $sink->close();

        $this->assertInstanceOf(manual_sync_run::class, $event);
        $this->assertSame('enrollments', $event->other['action']);
        $this->assertNotEmpty($event->get_description());
    }

    /**
     * A back-fill run logs one event per type in the course; a dry run logs none.
     *
     * @return void
     */
    public function test_backfill_queued(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_membershipbridge');
        set_config('imis_id_source', 'idnumber', 'local_membershipbridge');
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user(['idnumber' => '20016']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $DB->insert_record('course_completions', (object)[
            'userid'        => $user->id,
            'course'        => $course->id,
            'timeenrolled'  => time() - WEEKSECS,
            'timestarted'   => 0,
            'timecompleted' => time(),
            'reaggregate'   => 0,
        ]);

        $sink = $this->redirectEvents();
        (new \local_membershipbridge\backfill((int)$course->id, false))->completions();
        $this->assertCount(0, $this->backfill_events($sink->get_events()), 'a dry run records nothing');

        (new \local_membershipbridge\backfill((int)$course->id, true))->completions();
        $events = $this->backfill_events($sink->get_events());
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(\context_course::instance($course->id), $event->get_context());
        $this->assertSame('completions', $event->other['type']);
        $this->assertSame(1, $event->other['queued']);
    }

    /**
     * The back-fill events among a list of events.
     *
     * @param \core\event\base[] $events The events.
     * @return backfill_queued[]
     */
    private function backfill_events(array $events): array {
        return array_values(array_filter($events, fn($event): bool => $event instanceof backfill_queued));
    }
}
