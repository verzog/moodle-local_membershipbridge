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
 * Audit-log report page for local_membershipbridge.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Registered admin page: handles login, the moodle/site:config capability, the
// page context/title/heading, and makes the page bookmarkable from the admin.
admin_externalpage_setup('local_membershipbridge_log');

$operation = optional_param('operation', '', PARAM_ALPHANUMEXT);
$status    = optional_param('status', '', PARAM_ALPHA);
$page      = optional_param('page', 0, PARAM_INT);

$perpage = 50;

$baseurl = new moodle_url('/local/membershipbridge/admin/log.php');
$pageurl = new moodle_url($baseurl, ['operation' => $operation, 'status' => $status]);

$PAGE->set_url($baseurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('auditlog', 'local_membershipbridge'));
$PAGE->set_heading(get_string('auditlog', 'local_membershipbridge'));

// Build the filter conditions.
$where  = [];
$params = [];
if ($operation !== '') {
    $where[] = 'operation = :operation';
    $params['operation'] = $operation;
}
if ($status === \local_membershipbridge\logger::STATUS_SUCCESS || $status === \local_membershipbridge\logger::STATUS_ERROR) {
    $where[] = 'status = :status';
    $params['status'] = $status;
}
$select = implode(' AND ', $where);

$total   = $DB->count_records_select('local_membershipbridge_log', $select, $params);
$records = $DB->get_records_select(
    'local_membershipbridge_log',
    $select,
    $params,
    'timecreated DESC, id DESC',
    '*',
    $page * $perpage,
    $perpage
);

// Preload the users shown on this page for name display.
$userids = array_filter(array_column($records, 'userid'));
$users   = $userids ? $DB->get_records_list('user', 'id', $userids, '', '*') : [];

echo $OUTPUT->header();

// Filter options.
$operations = $DB->get_fieldset_sql('SELECT DISTINCT operation FROM {local_membershipbridge_log} ORDER BY operation');
$opmenu     = ['' => get_string('log_filter_all', 'local_membershipbridge')];
foreach ($operations as $op) {
    $opmenu[$op] = $op;
}
$statusmenu = [
    ''                      => get_string('log_filter_all', 'local_membershipbridge'),
    \local_membershipbridge\logger::STATUS_SUCCESS  => get_string('status_success', 'local_membershipbridge'),
    \local_membershipbridge\logger::STATUS_ERROR    => get_string('status_error', 'local_membershipbridge'),
];
$options = static function (array $menu, string $current): array {
    $out = [];
    foreach ($menu as $value => $label) {
        $out[] = ['value' => (string)$value, 'label' => $label, 'selected' => ((string)$value === $current)];
    }
    return $out;
};

// One row per entry. Every value is escaped by the template.
$rows = [];
foreach ($records as $row) {
    $hasuser = !empty($row->userid) && isset($users[$row->userid]);
    $error   = (string)($row->error ?? '');
    $request = (string)($row->request ?? '');
    $reply   = (string)($row->response ?? '');
    $rows[] = [
        'time'       => userdate($row->timecreated),
        'hasuser'    => $hasuser,
        'userurl'    => $hasuser ? (new moodle_url('/user/view.php', ['id' => $row->userid]))->out(false) : '',
        'username'   => $hasuser ? fullname($users[$row->userid]) : '',
        'operation'  => $row->operation,
        'member'     => $row->imisid ?? '-',
        'product'    => $row->productcode ?? '-',
        'success'    => ($row->status === \local_membershipbridge\logger::STATUS_SUCCESS),
        'status'     => $row->status,
        'error'      => $error,
        'request'    => $request,
        'response'   => $reply,
        'haspayload' => ($request !== '' || $reply !== ''),
    ];
}

echo $OUTPUT->render_from_template('local_membershipbridge/audit_log', [
    'actionurl'  => $baseurl->out_omit_querystring(),
    'operations' => $options($opmenu, $operation),
    'statuses'   => $options($statusmenu, $status),
    'hasrows'    => !empty($rows),
    'count'      => get_string('log_count', 'local_membershipbridge', $total),
    'rows'       => $rows,
]);

if (!$rows) {
    echo $OUTPUT->notification(get_string('log_empty', 'local_membershipbridge'), 'notifymessage');
} else {
    echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
}
echo $OUTPUT->footer();
