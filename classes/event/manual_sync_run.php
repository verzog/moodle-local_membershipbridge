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
 * Event: an administrator ran a manual sync from the sync page.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\event;

/**
 * Triggered after a manual enrolment, cancellation or group sync for one member
 * finishes on the sync page.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - string action: enrollments, cancellations, groups or all.
 *      - string member: the member ID that was synced.
 * }
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manual_sync_run extends \core\event\base {
    /**
     * Set the basic event properties.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud']     = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventmanualsyncrun', 'local_membershipbridge');
    }

    /**
     * A plain-English description for the logs.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' ran a manual '{$this->other['action']}' sync "
            . "for member '{$this->other['member']}'.";
    }

    /**
     * The sync page.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/membershipbridge/admin/sync.php');
    }

    /**
     * Check the event data.
     *
     * @return void
     * @throws \coding_exception When a required value is missing.
     */
    protected function validate_data(): void {
        parent::validate_data();
        foreach (['action', 'member'] as $key) {
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
