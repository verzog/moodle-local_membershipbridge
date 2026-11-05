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
 * Event: a back-fill queued a course's existing results for sending.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\event;

/**
 * Triggered when a back-fill run with --execute finishes queuing one type of result
 * (completions or scores) for a course. A dry run triggers nothing.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - string type: completions or scores.
 *      - int queued: how many pushes were queued.
 * }
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_queued extends \core\event\base {
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
        return get_string('eventbackfillqueued', 'local_membershipbridge');
    }

    /**
     * A plain-English description for the logs.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' back-filled {$this->other['queued']} {$this->other['type']} "
            . "in the course with id '{$this->courseid}' to the membership system.";
    }

    /**
     * Check the event data.
     *
     * @return void
     * @throws \coding_exception When a required value is missing or the context is not a course.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if ($this->contextlevel != CONTEXT_COURSE) {
            throw new \coding_exception('Context level must be CONTEXT_COURSE.');
        }
        foreach (['type', 'queued'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * No 'other' values need mapping on restore.
     *
     * @return bool
     */
    public static function get_other_mapping(): bool {
        return false;
    }
}
