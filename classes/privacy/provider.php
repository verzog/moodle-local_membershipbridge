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
 * Privacy Subsystem implementation for local_membershipbridge.
 *
 * The plugin keeps an audit log of the calls it makes to iMIS (the
 * local_membershipbridge_log table), the last activity grade it queued per learner and
 * grade item (local_membershipbridge_scorepush), and transmits personal data to the
 * external iMIS association management system. All are declared here, and the
 * stored data is exportable and erasable, in line with the Australian Privacy
 * Principles.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the audit log and the external iMIS data flow.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data the plugin stores and transmits.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_membershipbridge_log',
            [
                'userid'      => 'privacy:metadata:local_membershipbridge_log:userid',
                'operation'   => 'privacy:metadata:local_membershipbridge_log:operation',
                'imisid'      => 'privacy:metadata:local_membershipbridge_log:imisid',
                'productcode' => 'privacy:metadata:local_membershipbridge_log:productcode',
                'status'      => 'privacy:metadata:local_membershipbridge_log:status',
                'request'     => 'privacy:metadata:local_membershipbridge_log:request',
                'response'    => 'privacy:metadata:local_membershipbridge_log:response',
                'error'       => 'privacy:metadata:local_membershipbridge_log:error',
                'timecreated' => 'privacy:metadata:local_membershipbridge_log:timecreated',
            ],
            'privacy:metadata:local_membershipbridge_log'
        );

        $collection->add_database_table(
            'local_membershipbridge_scorepush',
            [
                'userid'     => 'privacy:metadata:local_membershipbridge_scorepush:userid',
                'itemid'     => 'privacy:metadata:local_membershipbridge_scorepush:itemid',
                'finalgrade' => 'privacy:metadata:local_membershipbridge_scorepush:finalgrade',
                'timequeued' => 'privacy:metadata:local_membershipbridge_scorepush:timequeued',
            ],
            'privacy:metadata:local_membershipbridge_scorepush'
        );

        $collection->add_external_location_link(
            'imis',
            [
                'userid'         => 'privacy:metadata:imis:userid',
                'courseid'       => 'privacy:metadata:imis:courseid',
                'status'         => 'privacy:metadata:imis:status',
                'score'          => 'privacy:metadata:imis:score',
                'completiondate' => 'privacy:metadata:imis:completiondate',
            ],
            'privacy:metadata:imis'
        );

        return $collection;
    }

    /**
     * Return the system context when the user has audit-log or score-push rows, or a
     * queued task that would send their data.
     *
     * @param int $userid The user to search for.
     * @return contextlist The list of contexts containing the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if (
            $DB->record_exists('local_membershipbridge_log', ['userid' => $userid])
                || $DB->record_exists('local_membershipbridge_scorepush', ['userid' => $userid])
                || self::queued_tasks([$userid])
        ) {
            $contextlist->add_from_sql(
                'SELECT id FROM {context} WHERE contextlevel = :level',
                ['level' => CONTEXT_SYSTEM]
            );
        }

        return $contextlist;
    }

    /**
     * Add the users who have audit-log or score-push rows, or a queued task that would
     * send their data, in the given (system) context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_membershipbridge_log} WHERE userid <> 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_membershipbridge_scorepush}', []);
        $userlist->add_users(array_values(array_unique(self::queued_tasks(null))));
    }

    /**
     * Export the user's audit-log and score-push rows.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }

            self::export_score_pushes($context, (int)$user->id);

            $records = $DB->get_records('local_membershipbridge_log', ['userid' => $user->id], 'timecreated ASC');
            if (!$records) {
                continue;
            }

            $logs = [];
            foreach ($records as $record) {
                $logs[] = [
                    'operation'   => $record->operation,
                    'imisid'      => $record->imisid,
                    'productcode' => $record->productcode,
                    'status'      => $record->status,
                    'request'     => $record->request,
                    'response'    => $record->response,
                    'error'       => $record->error,
                    'timecreated' => transform::datetime($record->timecreated),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('auditlog', 'local_membershipbridge')],
                (object)['logs' => $logs]
            );
        }
    }

    /**
     * Export the user's score-push rows: the last activity grade queued to iMIS per
     * grade item.
     *
     * @param context $context The (system) context to export in.
     * @param int     $userid  The user to export for.
     * @return void
     */
    protected static function export_score_pushes(context $context, int $userid): void {
        global $DB;

        $records = $DB->get_records('local_membershipbridge_scorepush', ['userid' => $userid], 'timequeued ASC');
        if (!$records) {
            return;
        }

        $pushes = [];
        foreach ($records as $record) {
            $pushes[] = [
                'itemid'     => $record->itemid,
                'finalgrade' => $record->finalgrade,
                'timequeued' => transform::datetime($record->timequeued),
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('scorepushes', 'local_membershipbridge')],
            (object)['scorepushes' => $pushes]
        );
    }

    /**
     * Delete all audit-log and score-push rows in a system context.
     *
     * @param context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records('local_membershipbridge_log');
            $DB->delete_records('local_membershipbridge_scorepush');
            self::delete_queued_tasks(null);
        }
    }

    /**
     * Delete one user's audit-log and score-push rows.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $DB->delete_records('local_membershipbridge_log', ['userid' => $userid]);
                $DB->delete_records('local_membershipbridge_scorepush', ['userid' => $userid]);
                self::delete_queued_tasks([(int)$userid]);
            }
        }
    }

    /**
     * Delete audit-log and score-push rows for the listed users in a system context.
     *
     * @param approved_userlist $userlist The approved users to delete for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_membershipbridge_log', "userid {$insql}", $inparams);
        $DB->delete_records_select('local_membershipbridge_scorepush', "userid {$insql}", $inparams);
        self::delete_queued_tasks(array_map('intval', $userids));
    }

    /**
     * Delete queued tasks that would send a learner's data to the membership system,
     * so an erased learner's member ID, completions and scores are never sent.
     *
     * @param int[]|null $userids The learners whose tasks to delete, or null for all.
     * @return void
     */
    protected static function delete_queued_tasks(?array $userids): void {
        global $DB;

        if ($userids === null) {
            [$insql, $params] = self::task_class_sql();
            $DB->delete_records_select('task_adhoc', "classname {$insql}", $params);
            return;
        }

        $ids = array_keys(self::queued_tasks($userids));
        if ($ids) {
            $DB->delete_records_list('task_adhoc', 'id', $ids);
        }
    }

    /**
     * The plugin's queued tasks that carry a learner's data, with the learner they are for.
     *
     * Every task the plugin queues for a learner carries their Moodle user ID. A task
     * queued before version 2.3.0 may have none: it is found only when erasing everyone.
     *
     * @param int[]|null $userids Only tasks for these learners, or null for every learner.
     * @return int[] Task ID => user ID.
     */
    protected static function queued_tasks(?array $userids): array {
        global $DB;

        [$insql, $params] = self::task_class_sql();
        $found = [];
        $tasks = $DB->get_recordset_select('task_adhoc', "classname {$insql}", $params, '', 'id, customdata');
        foreach ($tasks as $task) {
            $data = json_decode((string)$task->customdata);
            $userid = is_object($data) ? (int)($data->userid ?? 0) : 0;
            if ($userid > 0 && ($userids === null || in_array($userid, $userids, true))) {
                $found[(int)$task->id] = $userid;
            }
        }
        $tasks->close();
        return $found;
    }

    /**
     * SQL matching the classnames of the plugin's tasks that carry a learner's data.
     *
     * @return array [$insql, $params] for a "classname $insql" condition.
     */
    protected static function task_class_sql(): array {
        global $DB;

        $classes = [
            \local_membershipbridge\task\push_score_note_task::class,
            \local_membershipbridge\task\push_activity_task::class,
            \local_membershipbridge\task\push_module_note_task::class,
            \local_membershipbridge\task\sync_user_task::class,
        ];
        $classnames = array_map(fn(string $class): string => '\\' . $class, $classes);
        return $DB->get_in_or_equal($classnames, SQL_PARAMS_NAMED, 'taskclass');
    }
}
