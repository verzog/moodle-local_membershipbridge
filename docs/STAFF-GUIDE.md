# Membership Bridge: guide for staff

For teachers, tutors and the education and membership team. It explains what Membership
Bridge does to your courses, and what to do when something looks wrong. You don't need to
change any settings. Site administrators set the plugin up (see `ADMIN-GUIDE.md`).

## What Membership Bridge does

Membership Bridge links Moodle to iMIS, our membership system:

- **It enrols people automatically.** When someone registers for an event in iMIS, they are
  enrolled in the matching Moodle course. If needed, a Moodle account is created for them.
- **It removes access when a registration is cancelled.** Their enrolment is *suspended*,
  not deleted, so their grades and progress are kept.
- **It sends results back to iMIS.** Course completions and quiz scores (and other activity
  results, if your site sends them) go to the member's record in iMIS.
- **It can build tutorial groups.** Groups set up in iMIS appear as groups in the course.

**iMIS is the source of truth.** Make registration and group changes in iMIS, and Moodle
follows.

## When changes appear in Moodle

| Change in iMIS | Appears in Moodle |
|---|---|
| New registration | Overnight, or a few minutes after the member next logs in to Moodle |
| Cancelled registration | Overnight, or a few minutes after the member next logs in |
| Tutorial group change | Overnight |

If someone needs access sooner, ask a site administrator to sync that member manually.

| Event in Moodle | Reaches iMIS |
|---|---|
| A learner completes a course | Usually within a few minutes |
| A learner submits a quiz | Usually within a few minutes |
| A tutor marks an activity (if your site sends that type) | Usually within a few minutes |

## Checking a learner's enrolment

1. Open the course and go to **Participants**.
2. Find the learner. Check the **Status** column:
   - **Active**: they have access.
   - **Suspended**: their registration was cancelled, or they were removed. Their grades
     and progress are still there.
   - **Not listed**: they are not enrolled. See *A learner can't get into the course* below.
3. Enrolments made by Membership Bridge show the method **Manual enrolments**.

## Tutorial groups

- Synced groups sit in a grouping called **Tutorial groups (synced)**. On sites set up
  before version 2.2.1 the grouping may be called *Tutorials (iMIS)*.
- Tutors marked in iMIS are given the tutor role in the course, and enrolled if they
  weren't already.
- **Change groups in iMIS, not in Moodle.** If you remove someone from a synced group in
  Moodle, the next overnight sync puts them back.
- **Your own groups are safe.** Groups you create yourself in Moodle are never changed, and
  people you add by hand to a synced group are left there.

## Please don't

- **Don't unenrol learners by hand.** Unenrolling can remove their grades and completion.
  Cancel the registration in iMIS instead; Moodle suspends the enrolment and keeps the
  record. If you must remove access in a hurry, *suspend* the enrolment: on
  **Participants**, click the edit (cog) icon next to the learner's enrolment and set
  **Status** to **Suspended**.
- **Don't change a learner's username or ID number.** That field links them to iMIS. If it
  is wrong, ask a site administrator.
- **Don't rename or delete synced tutorial groups** in Moodle.

## Common situations

### A learner can't get into the course

1. Check the registration in iMIS: it should be for the right event and not cancelled.
2. Ask the learner to log out of Moodle and log back in. This checks their registrations
   within a few minutes.
3. Check **Participants** again.
4. If they are still missing, send a site administrator the details listed under *Asking for
   help* below.

### A learner has re-registered after cancelling

Nothing to do. When the new registration is active, their suspended enrolment is
reactivated overnight (or at their next login), with their previous grades and progress.

### A learner's completion or score isn't showing in iMIS

1. Check the learner has actually completed the course, or that the grade appears in the
   course **Grades** page. A mark on an attempt only counts once it reaches the gradebook.
2. Allow a few minutes, then check iMIS again.
3. If it is still missing after an hour, ask a site administrator. They can see every
   result sent to iMIS and whether iMIS accepted it.

### A learner appears twice, or the wrong person was enrolled

This usually means two Moodle accounts share a member ID. Don't fix it yourself: ask a site
administrator, giving both account names.

## Asking for help

Contact a site administrator, and include:

- the learner's **name** and **member ID**;
- the **course** name (or the course link);
- the **event code** from iMIS, if you have it;
- what you expected to happen, what happened instead, and roughly when.
