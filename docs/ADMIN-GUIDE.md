# Membership Bridge: admin setup and use

For Moodle site administrators. It covers setting the plugin up, checking that it works,
and looking into problems. Day-to-day staff tasks are in `STAFF-GUIDE.md`.

Membership Bridge connects Moodle to your iMIS membership system. It does three jobs:

- **Enrols members** in Moodle courses from their iMIS event registrations, and suspends
  them when a registration is cancelled.
- **Sends results to iMIS**: course completions, quiz scores, and optionally activity
  completions and activity scores.
- **Builds tutorial groups** in Moodle courses from the groups set up in iMIS (optional).

You need the *Site administrator* role for everything in this guide.

## Where things are

Everything is under **Site administration › Plugins › Local plugins**:

| Page | What it is for |
|---|---|
| **Membership Bridge** | All the settings. |
| **Manual sync controls** | Sync one member now, test the connection, send a test result. |
| **Open integration audit log** | Every call made to iMIS, and whether it worked. |

## Before you start

- **Some features start as soon as the connection details are saved.** These are on by
  default: *Push course completions*, *Push quiz scores*, the three *Sync … on login*
  settings and the three nightly sync tasks. Untick any you are not ready for **before**
  you enter the connection details, then turn each one on as you set it up.
- The registration sync and the tutorial group sync start in **dry run** mode: they report
  what they would do and change nothing until you turn dry run off.
- Set it up on a staging copy of Moodle first if you can.

## Part 1: Setup

### Step 1. Install

1. Download `local_membershipbridge-vX.Y.Z.zip` from the plugin's GitHub releases page.
2. Go to **Site administration › Plugins › Install plugins**, upload the zip and follow the
   prompts to complete the upgrade.
3. Moodle 5.1 to 5.3 is supported.

To update later, install the new zip the same way, then complete the upgrade at
**Site administration › Notifications**.

### Step 2. Connect to iMIS

Open **Site administration › Plugins › Local plugins › Membership Bridge**.

Choose a **Connection provider**. This is how results are sent to iMIS:

- **ATS SOAP bridge**: if your iMIS is linked to Moodle through ATS. Fill in
  *ATS SOAP bridge WSDL URL* and *ATS API AuthToken*. ATS supplies both.
- **Direct REST API**: talk to iMIS directly. Fill in *REST base URL* (your iMIS address,
  for example `https://yourorg.imiscloud.com`), *Service-account username* and
  *Service-account password*. Use an iMIS account made for this, not a person's login.

Then choose a **Sync provider**. This is where enrolments come from. It can differ from the
connection provider.

The tutorial group sync and the REST registration sync always use the REST settings, so fill
those in if you use either feature.

### Step 3. Match Moodle users to iMIS members

- **Member ID source**: the Moodle user field that holds each person's iMIS member ID.
  Choose *Username* (the default), *ID number* or *Email*.
- **Member ID format**: choose *Digits only* if your member IDs are always numbers. Accounts
  whose ID is not in that format (for example `admin`) are then never sent to iMIS.

People without a member ID in that field are skipped by every feature.

### Step 4. Choose what is sent to iMIS

Under **Automatic sync controls**, tick what you want sent:

| Setting | Sends | Default |
|---|---|---|
| Push course completions | A completion record when a learner completes a course | On |
| Push quiz scores | A score note each time a quiz attempt is submitted | On |
| Push module completions | A note when a graded activity is completed | Off |
| Push activity scores | A score note when an activity of a ticked type is marked | None ticked |

Results are queued and sent by Moodle's cron within a few minutes. A failed send is retried
automatically.

### Step 5. Map iMIS events to Moodle courses

The registration sync and the tutorial group sync need to know which Moodle course each iMIS
event belongs to. Choose one under **Course mapping**:

- **Moodle course ID number**: nothing to build in iMIS. In each course, set
  *Course settings › General › Course ID number* to the iMIS event code, for example
  `ACCD_26`.
- **Lookup query**: an iMIS IQA that returns the Moodle course id for an event code. Set
  *Course lookup IQA* to its path. If your lookup uses a product code such as `ACCD_26/REG`,
  set *Registration product suffix* to `/REG`.

**Course mapping overrides** (one `CODE=courseid` per line) always win. They are useful for
pointing an event at a test course.

### Step 6. Set up enrolments

How enrolment works depends on the **Sync provider**.

**ATS SOAP bridge.** ATS performs the enrolments. Moodle asks ATS each night (and when a
member logs in) to send new and cancelled orders. Make sure ATS's web service user has the
**Cancel enrolment** capability (`local/membershipbridge:cancelenrolment`) at system level.
That service suspends cancelled learners instead of unenrolling them, so their grades and
completion are kept.

**Direct REST API** (the registration sync). Moodle reads registrations from iMIS itself:

1. In iMIS, build an IQA listing current registrations with the columns `ID`, `EventCode`
   and `Status`. Add `FirstName`, `LastName` and `Email` if Moodle should create accounts
   for new members. Tick *Available via REST API*.
2. Set **Registrations IQA** to its path and **Sync provider** to *Direct REST API*.
3. Check the registration settings:

| Setting | What it does | Default |
|---|---|---|
| Registration sync: dry run | Report only; change nothing | On |
| Registration sync: create accounts | Create a Moodle account for a new member | On |
| Registration sync: cancelled status codes | Status values that mean cancelled | `C` |
| Registration sync: look-back (days) | Ignore events that ended longer ago than this | 7 |
| Registration sync: suspension guard (%) | Skip a course's suspensions if a run would suspend more than this share of it | 50 |

4. Run a dry run (see *Running a task now* below) and read the task log.
5. When the log shows what you expect, untick **Registration sync: dry run**.

What the registration sync does:

- **Active registration**: enrols the member through the course's *Manual enrolments*
  method with its default role (normally Student). A suspended enrolment is reactivated.
- **No Moodle account**: creates one, if *create accounts* is on.
- **All registrations for a course cancelled**: suspends the enrolment and removes the
  role. Nothing is unenrolled or deleted.

Each course must have an enabled **Manual enrolments** method.

### Step 7. Tutorial groups (optional)

This mirrors tutorial groups built in iMIS into Moodle course groups each night.

1. In iMIS, set up the tutorial panel on the contact record, with the member ID, event
   code, group name and (optionally) a *Tutor* tick box. `docs/SETUP.md` §6 lists the
   fields. `imis/tutorial-groups.html` is a ready-made iMIS page for building groups.
2. In Moodle, set the course mapping (Step 5). If you use tutors, set **Tutor field** and
   **Tutor role**.
3. Tick **Enable tutorial group sync** and leave **Tutorial group sync: dry run** on.
4. Run it once and read the task log.
5. When it shows what you expect, untick dry run.

The sync only touches its own groups (their ID numbers start with `imis:`), inside a
grouping called *Tutorial groups (synced)*. Groups teachers make themselves are never
changed. A run that reads nothing from iMIS changes nothing, and large removals are held
back by the **removal guard**.

### Step 8. Turn on the nightly tasks

Under **Scheduled tasks**, tick the nightly tasks you use. They run at:

| Time | Task |
|---|---|
| 02:00 | Enrolment sync (all members) |
| 02:15 | Cancellation sync (all members) |
| 02:30 | Group sync (ATS) |
| 02:45 | Tutorial group sync (when enabled) |
| 03:45 | Audit-log clean-up |

The **Sync … on login** settings also sync each member's own enrolments a few minutes after
they log in.

## Part 2: Day-to-day use

### Sync one member now

Use this when a member has registered and cannot wait for the overnight sync.

1. Go to **Site administration › Plugins › Local plugins › Manual sync controls**.
2. Enter the **member ID**.
3. Click **Sync enrolments**, **Sync cancellations**, **Sync groups** or **Run full sync**.
4. Confirm on the next page.
5. Read the result shown on the page.

For the Direct REST API only: tick **Include events outside the look-back window** to apply
an older registration for this one member, for example a cancellation processed after the
course ended.

### Test the connection

On **Manual sync controls**, click **Test connection**. It only reads from iMIS and changes
nothing. Enter a member ID first to also test a profile lookup.

### Send a test result

On **Manual sync controls**:

- **Push completion (test)**: enter a member ID and the course's numeric id (from the course
  address, `?id=N`), then click **Push completion now**. Needs *Push course completions* on.
- **Push activity note (test)**: enter a member ID and course id, click to list the graded
  activities, choose one, then push. Needs *Push module completions* on.

**These write real records to iMIS.** Use a test member where you can. The page shows how the
score was worked out. A warning means the member ID did not match exactly one Moodle user,
so a placeholder score of 0 was sent.

### Send a course's existing results (back-fill)

To send results that already existed before the plugin was set up, ask someone with server
access to run:

```bash
php local/membershipbridge/cli/backfill.php --course=ID --type=all
php local/membershipbridge/cli/backfill.php --course=ID --type=all --execute
```

The first command is a dry run that lists what would be sent. The second queues the sends,
and cron sends them. A score already sent and unchanged is not sent again.

### Running a task now

Ask someone with server access to run the task, for example:

```bash
php admin/cli/scheduled_task.php --execute='\local_membershipbridge\task\sync_enrollments_task'
php admin/cli/scheduled_task.php --execute='\local_membershipbridge\task\sync_tutorial_groups'
```

Or click **Run now** next to the task at **Site administration › Server › Tasks ›
Scheduled tasks**.

## Part 3: Checking and troubleshooting

### Where to look

- **Each call made to iMIS, and whether it worked**: **Open integration audit log**. Filter
  by operation and status.
- **What a nightly sync decided for each member**: **Site administration › Server › Tasks ›
  Task logs**, then the Membership Bridge task.
- **Results sent, manual syncs and back-fills**: **Site administration › Reports › Logs**.
  Look for *Result sent to the membership system*, *Manual membership sync run* and
  *Results back-filled to the membership system*.
- **Enrolments, suspensions and new accounts**: **Site administration › Reports › Logs**, or
  the course's **Participants** page.

The audit log keeps entries for 90 days by default (**Log retention (days)**). Full request
and response bodies are kept only if **Capture full request/response** is on. Credentials are
always removed.

### Common problems

**A member was not enrolled.** Check that:

1. their member ID is in the Moodle field set in **Member ID source**, and exactly one Moodle
   user has it;
2. the event is mapped to the course (Step 5);
3. the course has an enabled *Manual enrolments* method;
4. **Registration sync: dry run** is off (Direct REST API only).

Then sync the member manually and read the result.

**"No unique Moodle account" in the task log.** Two Moodle users share the member ID, or none
has it. Fix the user records.

**A course's suspensions were skipped ("suspension guard tripped").** The run would have
suspended too many people in that course at once. Check the registrations in iMIS. If the
change is genuine, raise the guard for one run, or sync members individually.

**Results are not reaching iMIS.** Check that the *Push …* setting is on, the connection test
passes, and cron is running. Errors appear in the audit log with the reply from iMIS.

**A score was sent as 0.** The learner had no grade in the gradebook yet. A mark only counts
once it reaches the gradebook.

**A tutorial group is missing.** Check that the event is mapped to the course, the member is
enrolled, and the group name is filled in iMIS. Then read the tutorial sync task log.

**Nothing happens at all.** Check that the connection settings are filled in, the nightly tasks
are ticked, and cron is running (**Site administration › Server › Tasks › Scheduled tasks**).

### Permissions

The plugin adds two capabilities. Neither is given to any role by default, and both carry
risk warnings, because they expose learners' results or remove course access.

- `local/membershipbridge:readcompletions` lets a web service account read completions and
  grades through the export web service. Assign it at system level, only to the account
  that needs it.
- `local/membershipbridge:cancelenrolment` lets a web service account suspend enrolments
  through the inbound web service. Assign it at system level to ATS's web service account.

### Privacy

Data requests and deletions through Moodle's privacy tools (**Site administration › Users ›
Privacy and policies › Data requests**) include the plugin's audit log, its score-tracking
records and any results still waiting to be sent.
