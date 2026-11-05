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
 * Event: a learner's result was sent to the membership system.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\event;

/**
 * Triggered after a completion, score or module note is sent to the membership system,
 * by the push tasks or by the manual push test on the sync page.
 *
 * The event holds no payload: only what kind of record was sent, the course and
 * activity it was for, and the member ID it was sent under. The full request and
 * response stay in the plugin's own audit log.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - string operation: completion, score, modulenote or scorenote.
 *      - int courseid: the Moodle course ID, or 0 when not known.
 *      - int cmid: the course module ID, or 0 for a course-level record.
 *      - string member: the member ID the record was sent under.
 * }
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_pushed extends \core\event\base {
    /** @var string[] The kinds of record the plugin sends. */
    public const OPERATIONS = ['completion', 'score', 'modulenote', 'scorenote'];

    /**
     * Build and trigger the event for one sent record.
     *
     * Takes the queued push data, which carries userid, courseid and (for activity
     * records) cmid. The course context is used when the course still exists,
     * otherwise the system context.
     *
     * @param string    $operation One of {@see self::OPERATIONS}.
     * @param \stdClass $data      The push data that was sent.
     * @return void
     */
    public static function trigger_for(string $operation, \stdClass $data): void {
        $courseid = (int)($data->courseid ?? 0);
        $userid   = (int)($data->userid ?? 0);
        $context  = ($courseid > 0) ? \context_course::instance($courseid, IGNORE_MISSING) : false;

        $params = [
            'context' => $context ?: \context_system::instance(),
            'other'   => [
                'operation' => $operation,
                'courseid'  => $courseid,
                'cmid'      => (int)($data->cmid ?? 0),
                'member'    => (string)($data->imisid ?? ''),
            ],
        ];
        if ($userid > 0) {
            $params['relateduserid'] = $userid;
        }
        self::create($params)->trigger();
    }

    /**
     * Set the basic event properties.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud']     = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventrecordpushed', 'local_membershipbridge');
    }

    /**
     * A plain-English description for the logs.
     *
     * @return string
     */
    public function get_description(): string {
        $who = !empty($this->relateduserid) ? "the user with id '{$this->relateduserid}'" : 'a member with no single Moodle user';
        return "The user with id '{$this->userid}' sent a {$this->other['operation']} record for {$who} "
            . "(member '{$this->other['member']}', course id '{$this->other['courseid']}') to the membership system.";
    }

    /**
     * Check the event data.
     *
     * @return void
     * @throws \coding_exception When a required value is missing.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['operation']) || !in_array($this->other['operation'], self::OPERATIONS, true)) {
            throw new \coding_exception('The \'operation\' value must be set in other.');
        }
        if (!isset($this->other['courseid'])) {
            throw new \coding_exception('The \'courseid\' value must be set in other.');
        }
    }

    /**
     * Mapping for the 'other' values when the event is restored with a course backup.
     *
     * @return array
     */
    public static function get_other_mapping(): array {
        return [
            'courseid' => ['db' => 'course', 'restore' => 'course'],
            'cmid'     => ['db' => 'course_modules', 'restore' => 'course_module'],
        ];
    }
}
