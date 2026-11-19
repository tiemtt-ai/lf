# ADR-0002

LiveClass Foundation

---

## Status

Approved

---

## Date

2026-06-27

Document Path: adr/ADR-0002-LiveClass-Foundation.md

## LiveClass Whiteboard Amendment

Approved: 2026-11-07 (Owner decision, from
[LF-LiveClass-Whiteboard-Proposal](../quality/LF-LiveClass-Whiteboard-Proposal.md)).
Architecture Review:
[LF-LiveClass-Whiteboard-Architecture-Review](../quality/LF-LiveClass-Whiteboard-Architecture-Review.md)
(Pass with changes; changes applied in this text). Frozen 2026-11-07.
Implemented 2026-11-07: phase 1 (teacher board, administrator entry) and phase 2
(learner viewer, private channel, active-presenter lock, cached state, board end
and learner revoke).

This amendment moves **Whiteboard** (Vietnamese UI name: "Bảng điện tử") out of
Future Considerations. It adds no table, column or migration and changes no
ownership boundary below. Live Quiz and the AI board stay in Future
Considerations and need their own ADR.

### What the Whiteboard is

A full-screen teaching surface for one live Session. A teacher shows the
Session's lesson material on a classroom touch screen and writes over it.
Learners may open the same board on their own device and follow the teacher in
real time. The business behaviour follows the AllVia e-board (read-only
reference project). The function table in the proposal is the acceptance
baseline. The QR invitation and guest accounts are excluded.

### Ownership

* The Whiteboard is a LiveClass operational feature bound to exactly one
  same-tenant Session. It has no identity, table or lifecycle of its own.
* It owns no learning content and produces no Attendance, Replay, Progress or
  Completion evidence. Opening, viewing or drawing on a board is not evidence.
* Board strokes, notes, page and zoom are stored only in the browser of the
  person who drew them, keyed by tenant, user, Session and material, as in
  AllVia. They are not Media, Recording or Chat. They are cleared from the
  browser on logout, because a classroom touch screen is a shared device.
  Storing boards on the server, exporting them or recording them needs a later
  amendment.
* The shared live state is a cache read model keyed by `customer_id` and
  Session. It is never a source of truth. Its TTL is the Session's planned end
  plus a buffer, refreshed on every write. Losing it loses only what late
  joiners see; the active presenter's browser re-publishes its state on
  reconnect.

### Material

* Curriculum Session: the material list is the Media of the published Version
  Activities in the Session's Version Lesson that have
  `available_during_session = 1` and a ready Media File, read-only, resolved
  through the Cohort's locked Version and the Cohort content order
  (`CourseCohortContentOrder`). Course keeps ownership. Activities without
  Media (`quiz`, `live_class`) are not listed in this amendment.
* Operational Session: no material list.
* Showing material during the live Session is the teacher's presentation, so
  learner unlock rules (`VersionActivityAccessService`) do not apply to it; the
  Activity flag `available_during_session` is the gate (Owner confirmed
  2026-11-07). This display is not Activity access and produces no Progress.
* The presenter may open a PDF from their own device. That file stays in the
  browser. It is never uploaded, stored or sent to learners, not even as page
  images: learners following a local PDF see only the strokes, with a notice
  that the material is on the teacher's screen.
* PDF is rendered inside the board with pdf.js, and uploaded or trusted
  embedded video and uploaded audio play inside the board, under the Media
  Whiteboard Rendering Exception in [LF-Media](../platform/LF-Media.md). Every
  other document type keeps opening in a new tab. (Audio: Whiteboard Media
  Amendment below.)
* Signed URLs for board material are issued by a Media method that receives a
  LiveClass-authorized context. LiveClass never reads storage directly. The
  current `generateVersionActivitySignedUrl(s)` stay administrator-only.

### Who may open it

All conditions are checked on the server for every page load, channel
subscription, signed URL request and published event:

* the Session and the actor share `customer_id` from `TenantContext`;
* the Cohort is `active` and the Session is `live`;
* **presenter**: an active teacher User with a row in
  `core_liveclass_session_teachers` for that Session (any role) whose
  `assigned_from`/`assigned_to` window, when set, covers now; or a
  `customer_admin` of the tenant;
* **viewer**: a learner with an `active` `core_course_cohort_students` row with
  `left_at` empty for the Session's Cohort, whose Enrollment is `active` and
  passes `CourseEnrollmentLifecycleService::allowsLearningAccessAt(now)`.

A `completed`, `cancelled` or `no_show` Session never opens a board.

### One active presenter

Several people may be presenters, but only one **active presenter** publishes
at a time. The active presenter is held as a per-Session lock in the cache.
The first presenter to open the board takes it. Another presenter sees the
board as a viewer and may take over explicitly; the previous holder is
notified and becomes a viewer. Only the lock holder may publish or re-publish
state. The server rejects events from anyone else.

### Starting and ending a Session

An assigned teacher who passes the presenter check may start their own Session
(`scheduled → live`) and complete it (`live → completed`) through the existing
`LiveClassSessionLifecycleService`, under the existing transition table. The
server rejects a start outside the Session's planned local date in its
recorded timezone, as AllVia allows it only for today's class. Cancel and
no-show stay administrator actions.

Every transition out of `live` (complete, cancel, no-show) and a Cohort leaving
`active` must, from one shared point in the lifecycle service, clear the cache
state and presenter lock and broadcast a board-ended event. Clients close the
board on that event. A learner removed from the Cohort is disconnected the
same way.

### Real-time sync

* One private broadcast channel per Session, authorized in
  `routes/channels.php` with the conditions above. It is not shared with any
  video or meeting provider.
* **Only the active presenter publishes.** The presenter sends board events to
  an authenticated server endpoint. The server authorizes, validates the event
  type and size, assigns an atomic per-Session sequence number, updates the
  cache read model and broadcasts immediately (`ShouldBroadcastNow`, or a
  single ordered queue).
* **Client events are switched off.** Reverb defaults accept `client-*`
  events from channel members and any origin. The published
  `config/reverb.php` must set `accept_client_events_from = none`, restrict
  `allowed_origins` to tenant domains and enable rate limiting.
* Learners only receive. A learner's own strokes and notes stay on their device.
* A late joiner subscribes first, buffers incoming events, loads the cached
  state, then applies buffered events after the state's sequence. A gap in
  sequence numbers triggers a full resync.
* Event types follow AllVia: stroke added or removed, page cleared, undo/redo,
  note added, changed or removed, page changed, material changed, video
  play/pause/seek, pointer position, scroll and zoom. One event is at most
  8 KB, under Reverb's 10,000-byte limit; long strokes are split. Publish rate
  is limited. Pointer and scroll events are not cached.

### Navigation and middleware

Each role has its own route group with that role's existing middleware set,
following the Navigation and Middleware Guardrails; there is no mixed-role
group:

| Role | Entry | Board routes |
| --- | --- | --- |
| Teacher | Teacher Session list | under `/teacher/...` |
| Administrator | Session detail in the Cohort Sessions tab | under `/admin/...` |
| Learner | Learner course page | under `/my-courses/...`, `role:student` |

The learner course page does not exist yet (`/my-courses` is a placeholder);
the board's learner entry is built with it. The board opens in its own window.

### Interface

Layout and functions follow AllVia; colours, icons and tooltips follow the LF
UI Kit. Every icon-only control has an i18n tooltip and `aria-label`
(`data-tooltip`); on touch screens a long press shows it. Every board text
(notices, connection status, the resume prompt, errors) uses
`LF_liveclass_whiteboard_*` keys in `vi` and `en`. Tools are keyboard
operable and animations respect `prefers-reduced-motion`.

### Hybrid delivery

The board carries no audio or video of the teacher. In a `hybrid` or `online`
Session, remote learners still hear the teacher through the Session's
`meeting_url_snapshot`. `delivery_mode` is unchanged. In-product audio/video
(WebRTC) is not authorized by this amendment.

### Not authorized here

QR invitation and guest accounts; attendance at board start; learner
submissions to the teacher; Live Quiz and AI board features; quiz Activities
on the board; server-side board persistence, export and recording.
Office-to-PDF conversion was listed here until the Whiteboard Office Preview
Amendment below authorized it on 2026-11-08.

### Whiteboard Media Amendment — 2026-11-08

Approved: 2026-11-08 (Owner decision, phase 1 of
[LF-LiveClass-Whiteboard-Media-Evaluation](../quality/LF-LiveClass-Whiteboard-Media-Evaluation.md)).
No table, column or migration; ownership unchanged.

* Uploaded audio plays inside the board, synchronised like video
  (play, pause, seek, close). One Media item is open at a time.
* The server stamps its own time on every media event and on the media part of
  the snapshot, and returns its time with the cached state, so viewers and late
  joiners compute the presenter's position. The presenter sends a non-cached
  position heartbeat while playing; a viewer corrects drift above one second.
* A presenter may preview a media item privately; nothing is published until
  they show it to the class.
* Trusted YouTube and Vimeo embeds are synchronised the same way (phase 2),
  controlled through `postMessage` with the provider's iframe. No provider
  script is loaded into the tenant page.
* A viewer chooses a listening mode: in the room (muted) or remote (with
  sound). The default follows the Session's `delivery_mode`; a `hybrid` Session
  asks on joining and remembers the answer in the browser for that Session.
  Sound is turned on only inside a user gesture; when autoplay with sound is
  blocked the media plays muted and offers "turn sound on". Opening the board
  stays optional for every learner and is still not attendance.
* This does not carry the teacher's own voice or camera: remote learners still
  hear the teacher through `meeting_url_snapshot`, and in-product WebRTC stays
  unauthorized (§ Hybrid delivery).

### Whiteboard Office Preview Amendment — 2026-11-08

Approved: 2026-11-08 (Owner decision 4 and phase 3 of
[LF-LiveClass-Whiteboard-Media-Evaluation](../quality/LF-LiveClass-Whiteboard-Media-Evaluation.md)).
No table, column or migration; ownership unchanged.

* Media, not LiveClass, converts a Word, PowerPoint or Excel file attached to a
  course Activity into a PDF once, with LibreOffice on the Media worker, and keeps
  it as a `preview` variant. The rules are in
  [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md)
  § Document board preview.
* Conversion runs when the file is attached to the Activity, on its own queue,
  never when the board opens it. Nobody waits for it during a class.
* When the preview is ready, the board shows it like an uploaded PDF: drawing,
  page sync and private viewer notes work the same way. Until then, or when
  conversion failed, the board opens the original file in a new tab, as before.
  The material list says which applies.
* The PDF is a picture of the file: PowerPoint animations, transitions, embedded
  media and macros are lost. It is not a new Media File and not a new Activity.
* The administrator sees the conversion status on the Activity and can ask for a
  conversion again; the teacher sees it in the board's material drawer.

## Attendance Check-In Amendment — 2026-11-09

Approved: 2026-11-09 (Owner decision). Implemented 2026-11-09 behind
`liveclass.attendance_enabled`.

Source: the Owner's business specification "Đặc tả nghiệp vụ: Buổi học của
Lớp học" v1.0 (finalized 2026-11-09), § 6 Điểm danh. That specification
replaces every earlier rule it contradicts. This amendment covers its first
phase only: QR check-in for learners in the room. No table, column or
migration.

* **Scope.** A Session whose `delivery_mode` is `offline` or `hybrid` can show
  an attendance QR code on its Whiteboard. Online check-in through "Vào phòng
  họp", automatic absence, the edit history of attendance, a per-tenant late
  threshold, the teacher attendance screen and Activity completion from
  attendance are later phases and are not authorized here.
* **Not the board's QR invitation.** The QR invitation and guest accounts listed
  under § Not authorized here stay excluded. The attendance code admits nobody to
  the board: a learner scans it with their own phone, signed in with their own
  account. Opening, watching or drawing on the board is still not attendance.
* **The code.** Only the board of someone who may present (the assigned teacher or
  an administrator) fetches it, together with how many learners have checked in.
  It changes every 30 seconds and is accepted for at most 60 seconds after it was
  issued, plus a 5-second network margin, so a forwarded photo stops working. It
  is an HMAC over tenant, Session and time window, signed with the application
  key. Nothing is stored. The QR code holds a short link (`/qr/{session}/{code}`,
  about 50 characters), so its cells stay big enough to scan from the back of the
  room; the board can also show it over the whole screen.
* **Who and when.** The learner must pass the same checks as a board viewer: an
  active membership in the Session's Cohort, an active learner account, and an
  active Enrollment inside its access window. The Session must be `live`, and the
  scan must arrive between 15 minutes before the planned start and the planned
  end.
* **What is written.** One Attendance row per Enrollment and Session, as before:
  - `status` is `present`, or `late` when the scan arrives more than 10 minutes
    after the later of the planned start and the actual start. A scan before the
    start counts as on time.
  - `joined_at` is the server time when the code was received;
  - `attendance_mode` is `offline`;
  - `attendance_source` is `system`;
  - `version_activity_id` comes from the Session.

  The 10 minutes are `liveclass.late_after_minutes` until the per-tenant setting
  is approved.
* **First scan only.** A row that already has `joined_at` is not changed. A row
  that a teacher or administrator has already set by hand is not overwritten
  either.
* **Manual attendance** keeps working and wins. Saving the Cohort attendance tab
  now records only active Enrollments (closes DOC-CONFLICT-0011). It leaves
  unchanged rows as they are, including their `joined_at`, mode and source. Rows
  it changes become `manual`.
* **Sign-in on the phone.** A learner who is not signed in on the phone is asked to
  sign in, then returns to the check-in page. The time recorded is when the code
  was received, before sign-in. The pending check-in expires after 10 minutes.
* **Switch.** Everything above, the board button included, stays behind
  `liveclass.attendance_enabled`.

## Attendance Policy And Recording Amendment — 2026-11-17

Approved: 2026-11-17 (Owner decision on the business proposal, questions 1–10).
The Owner approved the data design (the Database documents named below) on the
same day and gave the go-ahead to start coding. Parts A and B and the review
additions are implemented (2026-11-17), all behind
`liveclass.attendance_enabled`, which defaults to on since 2026-11-17 (Owner
decision 11). The Architecture Review so far is a self-check against the
Guardrails, not an independent review.

Implementation notes (2026-11-17):

* The Cohort's foreign key is the pair `(attendance_policy_id, customer_id)`, so
  the schema itself keeps a Cohort on a policy of its own tenant.
* Automatic absence looks back 7 days from now (so a scheduler that was down
  catches up) and never before `liveclass.auto_absent_from`
  (`LIVECLASS_AUTO_ABSENT_FROM`, UTC, default `2026-11-18 00:00:00`). It runs
  every minute as `liveclass:mark-absent`. A `hybrid` Session is skipped only when
  the policy leaves both in-room and online recording to the teacher.
* The late-start evaluation (review addition 1) runs when the Session goes live
  and touches only online entries recorded by the system.
* Entries are written by one service, `LiveClassAttendanceRecorder`; the thresholds
  are computed by `LiveClassAttendanceTiming`.

Source: the Owner's business document "Nghiệp vụ luồng học tập lớp MK" (2026-11-16),
applied to LearnForge with three Owner decisions of 2026-11-17: content stays in
the Template and its immutable published Version, a Cohort only reads it; there is
no Klass delivery; the "one device, one learner" rule is postponed. Apart from
these, the Owner wants the attendance rules to follow the source document closely. This amendment
covers the attendance policy and the remaining attendance phases listed as "later
phases" in § Attendance Check-In Amendment. Make-up attendance, the joined-date
rules and "counts toward attendance" on an Activity are later amendments.

### A. Attendance policy

* **What it is.** A named set of attendance thresholds owned by one tenant:
  `core_liveclass_attendance_policies`. A Cohort points to one policy through
  `core_course_cohorts.attendance_policy_id`; `NULL` means the tenant's default
  policy. Each tenant has exactly one default policy.
* **Why a table.** `saas_customer_settings` stores single keyed values. It cannot
  hold several named sets or be assigned to a Cohort.
* **Settings and defaults.** Opens before the planned start: 15 minutes. Late
  after: 10 minutes. Absent after: 30 minutes, or no limit. Automatic absence: 30
  minutes after the planned end. Count from the actual start: on. How the entry
  time is recorded, separately for in-room and online: by the system, or by the
  teacher by hand. Make-up: on, no limit. Teacher edit window: 7 days after the
  Session.
* **Who manages it.** `customer_admin` creates, edits and deletes policies and
  assigns them to Cohorts. A teacher only reads the policy of their Cohort. The
  default policy and a policy assigned to a Cohort cannot be deleted.
* **Effect of a change.** Editing a policy or assigning another one affects only
  results written afterwards. A stored Attendance result is never recalculated.
* **QR check-in.** The opening time and the late threshold come from the Cohort's
  policy and replace `liveclass.late_after_minutes` and the fixed 15 minutes. A
  scan after the absent threshold is refused and the board stops showing the
  code; the teacher records that learner by hand. With in-room recording set to
  "by hand", the board does not offer the code. The code itself is unchanged: it
  still changes every 30 seconds (Owner kept this on 2026-11-17).
* **Not adopted from the source document.** Klass and its exceptions; the
  per-device rule (postponed).
* **Time.** Thresholds are compared in the Session's time zone against server
  time, as today.

### B. Online entry, manual attendance, edit history, automatic absence

* **"Vào phòng họp".** On the learner's class page the meeting link is replaced
  by a button that records the entry on the server and then opens the meeting.
  It applies to an `online` Session and to the remote learners of a `hybrid`
  Session. It is accepted from the policy's opening time until the planned end,
  for a Session that is not cancelled, and does not require the teacher to have
  started the Session. The learner must pass the same checks as for the QR code.
  The row gets `attendance_mode = online`, `attendance_source = system`,
  `joined_at` = server time, and `status` `present`, `late` or `absent` by the
  policy's thresholds. After the absent threshold the learner still enters, the
  result is `absent`, the entry time is kept, and the page warns them first.
  Only the first entry counts; a result set by hand is not overwritten. With
  online recording set to "by hand" the button opens the meeting and records
  nothing. The system knows only that the button was pressed, not that the
  learner stayed in the meeting.
* **Opening the Whiteboard as a learner** (Owner decision 2026-11-17). Unlike the
  source system, a LearnForge learner watches the board in their own account,
  without the meeting link. The server cannot tell where a learner is — a learner
  in the room may watch the board on a laptop — so it decides by the Session's
  `delivery_mode`, which the centre sets:
  - `online`: nobody is in the room, so opening the board records the entry
    exactly like "Vào phòng họp". Whichever comes first counts; the other changes
    nothing.
  - `offline` and `hybrid`: opening the board is never attendance, for anyone.
    § Attendance Check-In Amendment stays as written for these modes. In a
    `hybrid` Session a remote learner enters through "Vào phòng họp"; a learner
    in the room scans the QR code or presses the "Điểm danh" button.

  In a `hybrid` Session a learner recorded as `online` (through "Vào phòng họp")
  who later checks in inside the room keeps the earliest `joined_at` and the
  `status` already written; only `attendance_mode` changes to `offline`.

  The board opens only while the Session is `live`, so an early learner enters
  through "Vào phòng họp". With online recording set to "by hand", opening the
  board records nothing. This replaces "opening, watching or drawing on the board
  is still not attendance" for `online` Sessions only.
* **"Điểm danh" button** (Owner decision 2026-11-17, replacing answer 3 of the
  proposal). An in-room Session uses one attendance tool, stored in
  `core_liveclass_sessions.attendance_tool`: `board` (the QR code on the
  Whiteboard, the default and the meaning of `NULL`) or `button`. With `button`
  the learner presses "Điểm danh" on their class page and the teacher opens
  nothing; the board does not offer the code. The button follows the rules of
  "Vào phòng họp" — window, learner checks, first press only, no need for the
  Session to be started, `absent` with the entry time kept after the absent
  threshold, a warning first — except that it writes `attendance_mode = offline`
  and always counts from the planned start. In a `hybrid` Session the tool
  applies to learners in the room; remote learners use "Vào phòng họp". The
  system cannot tell that the learner is in the room; the per-device rule that
  limits this is postponed.
* **Manual attendance.** `customer_admin` on every Cohort, as today, and every
  teacher with an active assignment to the Cohort, on a new screen in the
  teacher area. Values: `present`, `late`, `absent`, `excused`, with a note.
  `excused` is set only by hand. A teacher may record and correct from the
  Session's start until the policy's edit window ends; `customer_admin` has no
  limit. A result set by hand is final: a scan, an online entry and automatic
  absence never replace it.
* **Edit history.** `core_liveclass_attendance_changes` receives one append-only
  row whenever an existing result changes, and for every manual write: who, when,
  from which status and mode to which, and the note. The first write made by the
  system is not a history row. `customer_admin` and the Cohort's teachers read
  it on the attendance screen.
* **Automatic absence.** A scheduled command writes `absent`, source `system`,
  for each active Cohort member with an active Enrollment that has no result
  (no row, or `registered`) once the planned end plus the policy's delay has
  passed. It skips a cancelled Session, any learner who already has a result,
  and a Session whose delivery mode is set to manual recording. Until then the
  learner sees "Chờ ghi nhận điểm danh" and the teacher sees "Chưa điểm danh".
  A teacher or administrator can still correct the result afterwards.
* **Switch.** Everything stays behind `liveclass.attendance_enabled`. Once this
  amendment is implemented and tried, the switch defaults to on (Owner decision
  9). Done 2026-11-17 (Owner decision 11): the default is on; a deployment can
  still turn it off with `LIVECLASS_ATTENDANCE_ENABLED=false`.

### Review additions — confirmed by the Owner on 2026-11-17

The rules below were added when the proposal was reviewed for ambiguity. The Owner
confirmed all seven on 2026-11-17. Rule 2 replaces "opening, watching or drawing
on the board is still not attendance" of § Attendance Check-In Amendment for
`online` Sessions.

1. **Late start after an early entry.** When the teacher starts the Session later
   than planned and the policy counts from the actual start, results that the
   system wrote before that moment are evaluated once more against the new
   start. Manual results are not touched. Each changed result adds a `system` row
   to the edit history.
2. **Opening the Whiteboard as a learner**, as written in part B: an entry in an
   `online` Session only. The Owner first confirmed it for `hybrid` too and
   narrowed it the same day, because a learner in the room may watch the board on
   a laptop. In a `hybrid` Session an in-room action (QR scan or "Điểm danh"
   button) after an `online` entry changes only `attendance_mode` to `offline`.
3. **Manual values.** Manual attendance offers `present`, `late`, `absent`,
   `excused`. A result cannot be set back to `registered`; the Cohort attendance
   tab stops offering it.
4. **Teacher edit window.** `teacher_edit_days` × 24 hours, counted from the
   planned end of the Session.
5. **Early end.** When the teacher completes the Session before the planned end,
   every way of recording an entry closes at that moment.
6. **Edit history scope.** The first write by the system is not a history row; the
   Cohort's teachers may read the history.
7. **Automatic absence guards.** The command skips a Session that ended before the
   deployment value `liveclass.auto_absent_from`, and a member whose Cohort
   `joined_at` is later than the Session's planned end.

Learner-facing page: the Session card appears from the policy's opening time, not
only while the Session is `live`, so that "Vào phòng họp" and the "Điểm danh"
button can be pressed before the teacher starts (Owner decision 7 of the
proposal).

### Database documents (approved 2026-11-17)

* `database/liveclass/core_liveclass_attendance_policies.md` (new table);
* `database/liveclass/core_liveclass_attendance_changes.md` (new table);
* `database/course/core_course_cohorts.md` § Attendance Policy Amendment (new
  column);
* `database/liveclass/core_liveclass_attendances.md` § Policy And Online Entry
  Amendment (no column);
* `database/liveclass/core_liveclass_sessions.md` § Attendance Tool Amendment (new
  column).

Implementation order (Owner decision 10): the documents together, then code for
part A, then code for part B.

---

## Teacher Session Operations Amendment — 2026-11-17

Approved: 2026-11-17 (Owner decision on the proposal "Gắn buổi học trực tiếp trong
LF", questions 3–5). Implemented 2026-11-17. No table or migration of its own; the
attendance tool column comes with the attendance migration. A stored meeting link
that is not an http(s) URL is never echoed back into the edit field.

Until now only `customer_admin` could change a Session of a Cohort, and nobody could
change anything once the planned start had passed. In the source system a teacher
configures their own sessions. In LearnForge the content belongs to the Template,
so what a teacher may change is the operational part of the Session only.

* **Who.** A teacher or assistant assigned to that Session
  (`core_liveclass_session_teachers`), within their own tenant. Not every teacher
  of the Cohort. `customer_admin` keeps full authority over every Session.
* **What a teacher may change.** The meeting link, the room name, the address, and
  the attendance tool (`core_liveclass_sessions.attendance_tool`).
* **What stays with `customer_admin`.** The delivery mode, the date and time, the
  teaching team, cancelling and no-show. Rescheduling must check the other
  teachers' availability and there is no learner notification yet.
* **When.** The existing edit rule: the Session is `scheduled`, has not been
  started, has no Attendance, Recording or Replay, and its planned start is in the
  future.
* **Meeting link exception.** The meeting link alone may be changed until the
  planned end, also while the Session is `live`, by `customer_admin` and by the
  Session's teachers. A broken link during class could not be replaced before.
* **Where.** The teacher area, page "Buổi học trực tiếp": an edit action on the
  Session row, with a form that holds only the fields above. The server checks the
  role, the tenant and the Session assignment; it does not trust the request.
* **Audit.** Every such change, by a teacher or by `customer_admin`, writes a row
  to `saas_audit_logs` with the values before and after. The schedule change
  history is unchanged.
* **Attendance tool.** It follows the same edit rule; this replaces "while the
  Session has no attendance result" in § Attendance Policy And Recording
  Amendment.

---

## Schedule Deletion Amendment — 2026-11-19

Trạng thái: Approved 2026-11-19 (Owner chọn phương án B và duyệt chi tiết dưới đây);
đã triển khai 2026-11-19. Không thêm cột, không thêm bảng, không migration.

Amendment này thay các câu nói xóa Schedule "deferred", "not available" hoặc
"remain unapproved" trong Automatic Session Generation Amendment, Schedule-To-Session
Origin Amendment và Cohort Schedule Foundation Amendment. Các phần khác không đổi.

**Lý do.** Lưu Schedule là tự tạo Session ngay. Một Schedule lưu nhầm vì vậy luôn
kéo theo Session, Origin và phân công giáo viên. Nếu không có cách xóa, quản trị
phải hủy từng buổi, và các buổi đã hủy vẫn nằm lại trong lớp.

**Khi nào được xóa.** Quản trị lớp được xóa một Schedule khi Cohort đang `draft`
hoặc `active` và mọi Session có Origin trỏ về Schedule đó đều thỏa tất cả các điều
kiện sau:

* `status = scheduled` và chưa có `actual_start_at`;
* giờ bắt đầu dự kiến vẫn còn ở phía trước;
* không có Attendance, Attendance change, Recording hoặc Replay;
* chưa từng dời giờ, tức không có row trong `core_liveclass_session_schedule_changes`;
* không thay thế Session khác và không bị Session khác thay thế
  (`superseded_by_session_id`).

Chỉ cần một Session không thỏa là việc xóa bị từ chối và không đổi gì. Thông báo nêu
lý do và hướng dẫn: sửa ngày kết thúc của Schedule, rồi hủy từng buổi thừa ở tab
Buổi học. Session đã hủy hoặc vắng mặt (`cancelled`, `no_show`) cũng chặn việc xóa,
vì loại Session này không bao giờ bị hard delete. Schedule chưa có Session nào luôn
xóa được.

**Xóa gì.** Mọi thứ nằm trong một transaction, sau khi lock Cohort, Schedule và các
Session liên quan:

1. phân công giáo viên của các Session đó (`core_liveclass_session_teachers`, chỉ là
   dữ liệu chuẩn bị);
2. Origin của các Session đó;
3. các Session đó;
4. Exclusion, Slot và chính Schedule.

Đây là ngoại lệ duy nhất của quy tắc "Origin chỉ thêm, không sửa, không xóa": một
Origin chỉ bị xóa cùng Session của nó, khi Schedule của nó bị xóa. Origin vẫn không
bao giờ bị sửa. Việc xóa ghi một dòng audit `liveclass_schedule.deleted`, gồm tên,
khoảng ngày, timezone, các Slot và danh sách Session đã xóa (id, số buổi, giờ bắt
đầu, giờ kết thúc).

**Hệ quả.**

* Hoạt động `live_class` của các Session đã xóa trở lại trạng thái chưa có buổi.
  Lần lưu Schedule sau sẽ ghép ngày cho chúng như thường lệ.
* Học viên và giáo viên không còn thấy các buổi đó.
* Session khác giữ nguyên số buổi. Buổi mới tiếp tục từ số lớn nhất hiện có cộng
  một, nên số buổi có thể bị hở.
* Cohort `completed` hoặc `archived` vẫn chỉ đọc: không có nút xóa, endpoint từ chối.

**Giao diện.** Có nút "Xóa lịch" ở chi tiết Schedule, chỉ hiện với Cohort `draft`
hoặc `active`. Bấm vào thì hiện popup xác nhận: nếu xóa được, popup nêu số buổi sẽ
bị xóa theo; nếu không, popup nêu lý do và không có nút xóa. Endpoint
`DELETE /course-cohorts/{cohort}/schedules/{schedule}` kiểm lại mọi điều kiện ở
server.

**Triển khai.** `LiveClassScheduleService::deletion()` (điều kiện, dùng cho cả popup)
và `delete()` (transaction, audit); `LiveClassScheduleController::destroy`, route
`course-cohorts.schedules.destroy`; popup trong
`course-cohort-schedules/partials/detail`. Test: `CourseCohortManagementTest`
(`test_a_schedule_whose_sessions_are_unused_is_deleted_with_them`,
`test_a_schedule_with_a_used_session_stays_with_everything_it_made`,
`test_completed_schedule_is_readonly_and_cannot_be_deleted`).

## Automatic Session Generation Amendment

Approved: 2026-11-05 (Owner decision)

This amendment supersedes the clauses below that keep automatic generation
deferred, that require an admin to confirm each occurrence, and that say saving
a Schedule must not create a Session.

Saving a Schedule (create or update) now creates Sessions in the same
transaction:

* Free future occurrences of the Cohort are paired, in the Cohort's programme
  order, with `live_class` Activities that have no delivering Session. This is
  the pairing the Sessions tab already showed.
* Each Session starts `scheduled`, with the Lesson's title, online, the Activity's
  link and the Cohort's teachers active on that date. Each gets an immutable
  Origin; occurrence uniqueness still applies.
* Learners see them under the same rules as any Session. Changes are made on the
  Sessions tab, Session by Session.

Unchanged: an existing Session or Origin is never updated, moved or cancelled by a
Schedule edit, so Schedule-driven synchronization stays deferred, as do Schedule
deletion and replacement. A Slot used by an Origin still cannot be removed.
Occurrences beyond the number of Activities stay unused. Saving is refused, and
nothing is stored, when the Cohort's free dates do not reach every `live_class`
Activity without a delivering Session (Owner decision 2026-11-05): the form says
how many are missing while editing, and the backend decides.

A class teacher added later joins upcoming Sessions that have no team, within
their period, because such Sessions block Cohort activation.

The admin batch endpoint and form are removed, and the manual create button is
removed from the Sessions tab. A
date can still appear without a Session, for example after a cancellation; it
keeps the read-only presentation and is saved from its row.

## Session Status Vocabulary And Replacement Scope Clarification

Approved: 2026-08-10

This clarification resolves two registered documentation items and changes no
ownership boundary, no source of truth and no historical data.

### Session status vocabulary

The canonical Session status set is:

```text
scheduled
live
completed
cancelled
no_show
```

`ended` is retired in favour of `completed`, which is the single terminal value
for a Session that has taken place. `draft` is retired at Session level: no
approved policy ever defined its business meaning, entry condition or exit
transition, and a Session prepared inside a `draft` Cohort is created as
`scheduled`. Setup state belongs to the Cohort lifecycle.

This resolves `DOC-CONFLICT-0001`. The canonical contract, including allowed
transitions, is held by
[core_liveclass_sessions](../database/liveclass/core_liveclass_sessions.md)
§ Session Status And Time Convention Amendment — 2026-08-10 and is reflected in
[LF-Core-LiveClass](../core/LF-Core-LiveClass.md) v2.6.

Retiring these two values does not authorize a Session lifecycle
implementation by itself. Transition actions, their authorization and their
effect on runtime eligibility remain governed by the existing Session
amendments in this ADR.

### Replacement Session scope

The Schedule-To-Session Origin Amendment below states that "Replacement and
superseded occurrence reuse are not authorized". That sentence is scoped to
**occurrence identity**: no workflow may consume an occurrence identity that a
cancelled or no-show Session already holds.

It does not silently re-authorize the replacement workflow described in the
Cohort-Centered Amendment for Sessions without an Origin. Replacement remains
**deferred for every Session**, with or without lineage, and
`core_liveclass_sessions.superseded_by_session_id` remains a reserved column
that current implementation must not write.

A cancelled Session is handled by one of two approved paths only:

```text
reschedule the same Session   → Origin retained, schedule-change audit appended
create a new manual Session   → no Origin, off_schedule, new time range
```

Reopening replacement requires a separate owner-approved amendment and an
Architecture Review, consistent with the Deferred Boundary in
[LF-LiveClass-Cohort-Schedule-Architecture-Review](../quality/LF-LiveClass-Cohort-Schedule-Architecture-Review.md).
The column is not dropped: dropping it would be a destructive schema change
with no operational benefit.

This resolves `DOC-CONFLICT-0005`.

## Schedule-To-Session Origin Amendment

Approved: 2026-08-05

This amendment authorizes explicit, immutable provenance when an authorized
actor creates one or more concrete Sessions from selected projected Schedule
occurrences. It supersedes only the clauses that deferred Schedule-to-Session
provenance, confirmed generation and idempotency. Automatic generation (now
authorized by the Automatic Session Generation Amendment above),
Schedule-driven synchronization, replacement Sessions and Schedule deletion
remain unapproved.

Canonical ownership is:

```text
Schedule + Slot + local date
→ projected occurrence
→ confirmed Session
→ immutable Session Schedule Origin
```

Lineage is stored only in
`core_liveclass_session_schedule_origins`. No lineage column is added to
`core_liveclass_sessions`. Every Origin belongs to exactly one same-tenant
Session, Schedule and Schedule Slot. A Session has zero or one Origin. Absence
of an Origin means either an explicitly manual Session or a legacy Session;
the application distinguishes those cases by the immutable feature rollout
cutover: no-Origin Sessions created before the cutover are legacy; no-Origin
Sessions created through the manual workflow at or after the cutover are
manual. The cutover instant is deployment configuration, not client input, and
must not move after rollout. The application must not infer or backfill
historical lineage from coincident timestamps.

Canonical occurrence identity is:

```text
customer_id + schedule_id + schedule_slot_id + source_local_date
```

This tuple is unique for all history. A cancelled or no-show Session retains
its Origin and continues to consume the occurrence identity. Replacement and
superseded occurrence reuse are not authorized. A make-up Session must use the
existing reschedule workflow or be created manually outside the Schedule.

Origin snapshots are immutable and include the source local date, local start
and end times, IANA timezone and absolute start/end instants. The local tuple
is interpreted in `source_timezone`. `source_start_at` and `source_end_at` are
the resulting UTC instants stored as timezone-free UTC `DATETIME` values. A
Session created from an occurrence receives the Schedule timezone and the
corresponding planned interval under the existing Session time convention.
Presentation and comparison must parse the Session interval in its recorded
timezone and compare normalized UTC instants; browser or server timezone is
never an authority.

Origin foreign keys to Session, Schedule and Schedule Slot use `RESTRICT`.
Schedule deletion remains unavailable. A Slot referenced by an Origin cannot
be hard-deleted; Schedule mutation must preserve stable Slot identity and may
not implement child replacement by deleting referenced Slots. Editing a
Schedule or Slot never updates an existing Origin snapshot or Session.

Creating Sessions from occurrences is an explicit preview, selection and
confirmation workflow. Preview creates no row. Confirmation runs in one
atomic transaction, locks the relevant same-tenant Cohort, Schedule, Slots and
occurrence identities, recalculates every occurrence with the canonical
Schedule preview rules and trusts no client-provided occurrence timestamp,
timezone or metadata. If any selected row is invalid, excluded, outside the
Schedule/Cohort window, already consumed or fails Session binding validation,
the whole batch fails and creates no Session or Origin. Database uniqueness is
the final double-submit guard.

Session curriculum/operational binding, teacher eligibility, delivery,
lifecycle and authorization remain unchanged. Confirmation creates only
Session, Session Teacher assignments when explicitly selected, and Origin. It
must not create Attendance, Recording, Replay, Progress or Completion.

Canonical relationship labels are:

* Origin exists and the normalized current Session interval equals its source
  snapshot: `on_schedule` / “Theo lịch”.
* Origin exists and the normalized interval differs: `rescheduled` / “Đã điều chỉnh”.
* A newly created manual Session without Origin: `off_schedule` / “Ngoài lịch”.
* A legacy Session without Origin: `source_unknown` / “Không có dữ liệu nguồn”.
* A projected occurrence without Session/Origin: `planned_occurrence` / “Ngày dự kiến”.

Rescheduling retains the Origin, updates only the Session planned interval and
appends `core_liveclass_session_schedule_changes`. It never mutates Schedule,
Slot or Origin.

## Cohort Schedule Foundation Amendment

Approved: 2026-08-05

This amendment separates recurring Cohort planning from concrete LiveClass
Sessions. LiveClass owns both concepts, but they have different sources of
truth and lifecycles:

```text
Cohort 1 → N LiveClass Schedules
Schedule 1 → N Schedule Slots
Schedule 1 → N Schedule Exclusions

Cohort 1 → N LiveClass Sessions
```

A Schedule belongs directly to one same-tenant Cohort. The Cohort supplies
tenant, Product, locked Template Version and lifecycle context. A Schedule is
LiveClass setup/planning data; it is not Course content, is not published into
an immutable Template Version and does not own Progress, Completion,
Attendance, Recording or Replay.

The Cohort owns its inclusive operating period through the existing canonical
`core_course_cohorts.start_date` and `core_course_cohorts.end_date` fields. Each
Schedule owns only its own inclusive application range. A Cohort may have many
Schedules, and every Schedule must satisfy:

```text
cohort.start_date <= schedule.starts_on
schedule.starts_on <= schedule.ends_on
schedule.ends_on <= cohort.end_date
```

The Cohort operating period is never inferred from a Schedule, and Schedule
create/update never expands or changes it. Updating the Cohort operating period
must fail when the proposed range no longer contains every existing Schedule;
the application must not truncate, update or delete those Schedules. Both
operating dates remain nullable at database level only for legacy compatibility.
New Cohorts must provide both dates, while a legacy Cohort without a complete
operating period remains readable but cannot create or preview a Schedule until
the period is completed. Schedule remains the timezone authority for preview;
this amendment does not move timezone ownership to Cohort.

Canonical Schedule persistence is normalized across:

```text
core_liveclass_schedules
core_liveclass_schedule_slots
core_liveclass_schedule_exclusions
```

Schedule data must not be stored in `core_liveclass_sessions`, Cohort metadata,
Course Template/Version, Room or a JSON recurrence payload. A Schedule has one
inclusive application range and IANA timezone. Its Slots store individual ISO
weekdays and same-day time intervals; a Schedule must retain at least one Slot,
exact duplicates and overlaps on the same weekday are rejected, and
`end_time > start_time`. Exclusions store unique dates within the Schedule
range and remove those dates from preview only.

The canonical preview is a backend-calculated read model:

```text
Schedule + Slots + Exclusions + timezone
→ projected occurrence timestamps
```

Preview includes both range endpoints, excludes configured dates, stores no
occurrence rows, creates no Session and displays that concrete Sessions have
not yet been created. Client-side calculation may assist presentation but is
never authoritative.

Schedule lifecycle follows its Cohort: `draft` and `active` Cohorts permit
authorized create/update; `completed` and `archived` Cohorts are read-only.
Schedule status is derived from the current date in its timezone and is not a
persisted column. Schedule mutation never activates a Cohort and never creates
or mutates runtime or learning evidence.

The canonical Cohort detail navigation is now:

```text
Overview | Students | Teachers | Schedules | Sessions | Attendance |
Recordings / Replay
```

Schedules and Sessions are independent tabs. The Sessions tab retains the
current Session Foundation and behavior without redesign in this amendment.
Cohort Attendance and Recordings/Replay remain aggregate views over Sessions
that belong to the Cohort.

Schedule deletion, automatic generation, future-Session synchronization,
shared holiday calendars and Schedule-driven bulk rescheduling remain
deferred. Explicit preview/selection/confirmation and immutable provenance are
authorized only by the Schedule-To-Session Origin Amendment above. Creating or
updating a Schedule still must not update, cancel or delete any Session; creating
Sessions on save is authorized by the Automatic Session Generation Amendment.

## Curriculum And Operational Session Amendment

Approved: 2026-08-03

This amendment supersedes the requirement in the 2026-07-25 amendment that
every Session must reference a Version Lesson. Every Session still belongs to
one same-tenant Cohort and freezes the Cohort's immutable
`template_version_id`, but it now declares exactly one canonical
`session_type`:

```text
curriculum
operational
```

A `curriculum` Session realizes one published Live Class Activity and requires
the complete authority chain:

```text
Cohort
→ locked Template Version
→ Version Lesson
→ Version Activity (activity_type = live_class)
→ Session
```

Its `version_lesson_id` and `version_activity_id` are both required. The Lesson
must belong to the Cohort Version. The Activity must belong to that Lesson and
Version, must share `customer_id`, and must have `activity_type = live_class`.
One Version Lesson may contain zero, one or many Live Class Activities, and one
Live Class Activity may be realized by zero, one or many Sessions. Non-live
Activities never create or require Sessions automatically.

An `operational` Session represents orientation, class administration,
supplementary support, workshops, non-replacement make-up meetings, closing
events or another event outside published learning content. Both
`version_lesson_id` and `version_activity_id` must be `NULL`. It may retain the
Cohort's locked `template_version_id` as immutable context, but it cannot
produce Activity Progress, Lesson Completion or Course Completion evidence.
Attendance and other operational history may still be recorded when the
applicable Cohort and Session lifecycle permits it.

Session type and curriculum binding are canonical columns, not inferred from
title, nullable metadata or Working Template identifiers. Switching to
`operational` must clear both Version IDs on the client and be canonicalized or
rejected by the server. Create and edit must share the same validation policy.

Session title remains editable. For a curriculum Session, the UI may suggest a
title from the selected Version Lesson and Live Class Activity only while the
user has not manually edited the title. Operational Sessions require a
user-provided title.

Cancelled and no-show Sessions are retained. A Session with Attendance,
Recording, Replay, Progress evidence or other operational history must not be
hard deleted. Binding fields become immutable once evidence exists; scheduling
changes follow the append-only schedule-change policy.

This amendment does not change the Cohort Version binding, published Version
content, Enrollment, Product policy or Course ownership of Progress and
Completion.

## Cohort-Centered Session Amendment

Approved: 2026-07-25

This amendment supersedes every conflicting Room-owned Session clause below.

The operational root for a scheduled class meeting is now:

```text
Course Product
└── Cohort
    └── LiveClass Session
        ├── Published Version Lesson
        ├── Published Live Class Activity (conditional)
        ├── Session Teachers
        ├── Optional Delivery Resource
        ├── Attendance
        └── Recording / Replay
```

Every Session requires same-tenant `cohort_id`, `template_version_id` and
`version_lesson_id`. The Version must equal the immutable Version locked by the
Cohort, and the Lesson must belong to that Version.

`version_activity_id` is required when the Session operationalizes a published
Live Class Activity and may produce Course Activity evidence. It may be `NULL`
for operational events outside Course Activity completion. When present, it
must belong to the Session Lesson and Version, have `activity_type =
live_class`, and share `customer_id`.

Session belongs directly to Cohort. `room_id` is nullable. Room is a reusable
delivery resource only and is no longer the Session owner or learning-context
authority. Online, offline and hybrid Sessions snapshot the delivery details
needed for historical reproducibility.

Session teachers use an assignment table and support `primary_teacher`,
`teacher`, `assistant` and `substitute`. A nullable `primary_teacher_id` on
Session is a convenience value; assignments are the complete authority.

Rescheduling updates the planned times while appending a schedule-change audit
record. A replacement Session uses `superseded_by_session_id`; the old Session
is cancelled and historical evidence remains attached to the old Session.

Attendance and Replay may provide Course Activity Completion Evidence only
when `version_activity_id` is present. LiveClass never updates Course Progress
directly.

---

## Context

LearnForge cần một Domain độc lập để quản lý hoạt động học đồng bộ và hybrid:

* Live Room
* Recurring Cohort Schedule
* Session
* Attendance
* Replay
* Recording
* Chat

Course Domain vẫn phải là nơi định nghĩa Learning Structure, published
immutable content, Enrollment learning cycle và Learning Progress.

Nếu LiveClass kế thừa Course hoặc tự sở hữu completion, hệ thống sẽ tạo hai
nguồn sự thật cho cùng một learning activity. Nếu LiveClass sở hữu binary
recording, ranh giới với Media Domain cũng bị phá vỡ. Foundation vì vậy cần
quyết định rõ ownership và integration boundaries trước implementation.

---

## Decision

LiveClass được thiết kế là:

```text
Operational Domain
```

LiveClass không phải Learning Domain và không phải Course Domain.

LiveClass quản lý dữ liệu vận hành của hoạt động live. Course Domain quản lý
learning structure và tự quyết định progress/completion từ evidence phù hợp.

Mọi LiveClass business record phải tenant-scoped bằng `customer_id`.

---

## Final Architecture

```text
Course Template

↓

Template Activity

↓ publish

Template Version / Version Activity

↓ locked context

Cohort

├── LiveClass Schedule → read-only occurrence preview
└── LiveClass Session → optional Room / delivery resource

↓

Attendance

↓

Replay

↓ evidence

Course Activity Progress
```

Version Lesson và conditional Version Activity là published immutable learning
context. Session is the Cohort-owned operational instance; Room is optional.

---

## Operational Data Decision

LiveClass chỉ sở hữu:

* Rooms
* Cohort Schedules, Slots and Exclusions
* Sessions
* Attendance
* Replay
* Recording references
* Chat

LiveClass không sở hữu:

* Progress
* Completion
* Certificate

Attendance percentage, replay percentage, watch position và operational status
chỉ là evidence/read models. Chúng không phải source of truth của learning
completion.

---

## Media Integration

```text
Recording

↓

Media File
```

Media Domain sở hữu:

* Binary
* Storage
* Delivery
* Subtitle
* Transcript

LiveClass chỉ lưu Media references và provider/source metadata cần thiết cho
vận hành. AI summary hoặc transcript processing không được ghi trực tiếp vào
LiveClass Recording.

---

## Course Integration

```text
Version Activity

↓

LiveClass

↓

Attendance Evidence

↓

Replay Evidence

↓

Course Activity Progress
```

`version_activity_id` là liên kết chính với Course Domain.

Attendance và Replay của người học phải tham chiếu `enrollment_id` để giữ đúng
learning cycle. Course Domain đọc evidence, áp dụng frozen completion rule và
tự cập nhật/recalculate Course Progress.

LiveClass không được cập nhật trực tiếp Course Progress, Completion hoặc
Certificate eligibility.

---

## Future Integration

```text
Track Domain

↓

Raw Events

↓

Attendance Summary

Replay Summary
```

Track Domain có thể ghi raw join/leave và playback events. LiveClass giữ
operational summaries; Course Domain quyết định learning state.

```text
AI Domain

↓

Transcript

↓

Summary

↓

Recommendations
```

AI output là insight/recommendation, không ghi đè operational source data và
không tự chuyển Course business state.

---

## Foundation Decisions

* LiveClass là Operational Domain.
* Schedule là LiveClass-owned recurring planning data thuộc trực tiếp Cohort.
* Schedule và Session có source of truth riêng; preview không tạo Session.
* Room không kế thừa Course và không sở hữu Session.
* Không tạo Runtime Course tables cho LiveClass.
* Working Template Activity chỉ định nghĩa `activity_type = live_class`.
* Published Version Activity đóng băng learning context và completion rule.
* `cohort_id`, `template_version_id` và `version_lesson_id` là Session
  invariants.
* `version_activity_id` là conditional Course Activity evidence link.
* Room và downstream records luôn tenant-scoped bằng `customer_id`.
* Room visibility không thay thế authorization.
* Session thuộc Cohort, có Room/Delivery Resource tùy chọn và tách thời gian
  scheduled khỏi actual.
* Session status không quyết định learning completion.
* Attendance tham chiếu Enrollment learning cycle.
* Attendance source và attendance method là hai khái niệm riêng.
* Attendance percentage là read-model, không quyết định completion.
* Replay tham chiếu Enrollment và Recording.
* Replay percentage và last position phục vụ vận hành/resume UX, không quyết
  định completion.
* Attendance và Replay chỉ là evidence cho Course Progress recalculation.
* `core_course_activity_progress` là source of truth cho Activity completion.
* Course progress, completion và certificate eligibility thuộc Course Domain.
* Recording binary, storage và delivery thuộc Media Domain.
* Transcript và subtitle files thuộc Media Domain; LiveClass chỉ lưu
  references.
* Chat là Operational Data và không tính completion trực tiếp.
* Chat soft-hide không đồng nghĩa hard-delete; retention/legal hold vẫn được
  tôn trọng.
* Track Domain sở hữu raw behavior events khi cần.
* AI Domain sở hữu summaries, insights và recommendations.
* Snapshot learning content nằm ở Course Template Version/Version Activity,
  không nằm trong LiveClass.

---

## Future Considerations

Các hạng mục sau là hướng mở rộng, không phải lỗi của Foundation:

* Provider abstraction và provider sync contracts
* Breakout Rooms
* Webinar Mode
* Live Poll
* Whiteboard — authorized by the LiveClass Whiteboard Amendment (2026-11-07)
* Live Quiz
* AI Live Assistant
* Calendar synchronization
* Advanced recording consent and retention policies
* Multi-product Room sharing policy

Mọi mở rộng tương lai phải giữ Domain Responsibility Principle và các ownership
boundaries trong ADR này.

---

## Consequences

### Positive

* Course, LiveClass, Media, Track và AI có source of truth rõ ràng.
* Không tạo completion source song song.
* Published learning context vẫn immutable và Enrollment-cycle safe.
* Provider và Media implementation có thể phát triển mà không tạo Runtime
  Course.

### Trade-offs

* Cần integration/service boundary để chuyển Evidence, Event hoặc Request giữa
  các Domain.
* Course Progress cần cơ chế recalculation idempotent.
* Provider sync, threshold, retention, privacy và Room sharing policy vẫn cần
  được chốt trước implementation.

---

## Applied Principles

See:

[LF-Architecture-Principles.md](../governance/LF-Architecture-Principles.md)

* Domain Responsibility Principle
* Source Of Truth Principle
* Immutable Principle
* Snapshot Principle
* Versioning Principle
* Evidence Principle
* Operational Data Principle
* Tenant Isolation Principle
* Read Model Principle
* Append Only Principle
* AI Consumer Principle
* Backward Compatibility Principle
* ADR Principle

---

## Result

```text
Foundation Ready

YES
```
