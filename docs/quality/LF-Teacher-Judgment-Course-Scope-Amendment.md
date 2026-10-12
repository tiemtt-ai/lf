# Teacher Judgment Course Scope Amendment

Version: 1.2

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-10-12

Document Path: quality/LF-Teacher-Judgment-Course-Scope-Amendment.md

---

## Decision

```text
Role: LearnForge Owner
Date: 2026-10-11
Decision: Approved in the workspace conversation ("chọn A, làm tiếp").
Scope:    Teacher Judgment may only judge a competency of the Framework Version
          that the cohort's published Course Version mapped at publication.
          Competencies mapped to a Lesson or Activity of that Course Version are
          listed first (option A: prioritise, not restrict).
Paused:   Automatic, course-derived mastery calculation. No calculation rule is
          designed or authorised by this amendment.
```

This amends the basis and Node eligibility rule of
[Phase 4E Teacher Judgment Design](LF-Learning-Foundation-Phase-4E-Teacher-Judgment-Design.md).
The External Surface for Teacher Judgment was already authorised on 2026-08-17
(same document, § External Surface Authorization); this amendment does not
widen it. No schema, migration, trigger or historical record changes.

## Confirmed gap

`TeacherJudgmentService::lockAndAuthorize()` accepts any active Node of any
published Framework Version in the tenant. A teacher could judge a competency
the cohort's course never selected, so the Course Template "Đầu ra & năng lực"
mappings had no effect on judgment.

## Rule

For a submission with cohort `C` (Course Version `C.version_id`), basis Version
`B` and Node `N`:

1. Course competency basis — the set of Framework Versions referenced by the
   non-invalidated canonical mappings of `C.version_id`:

   ```text
   core_learning_node_mappings
     WHERE customer_id = tenant
       AND source_type IN ('course_version_lesson', 'course_version_activity')
       AND source_discriminator = C.version_id
       AND invalidated_at IS NULL
     → learning_node_id → core_learning_nodes.framework_version_id
   ```

   `source_discriminator` is written by `LearningMappingPromotionService` at
   publication and equals the Course Version id, so Learning answers this
   without reading Course tables.
2. `B` must belong to that set, in addition to every existing Phase 4E check
   (published basis, active Node and Definition, Node in `B`, valid level under
   `B`'s frozen scale). Otherwise the service raises
   `LF_TEACHER_JUDGMENT_BASIS_NOT_IN_COURSE`.
3. `N` may be any active Node of `B` (option A). Mapped Nodes are a presentation
   priority, not an authorization rule.
4. A Course Version published without any mapping has an empty set: nothing can
   be judged for its cohorts. The UI explains that the course needs linked
   competencies and a new publication.
5. G2-N2 holds: the basis is the explicit Version recorded by the Course
   Version's mappings, never `latest`, publish time or version number. A Course
   Version mapped to several Versions offers each one, labelled.
6. A correction (`supersedes_judgment_id`) keeps the predecessor's basis and
   Node, as today; the course-scope check applies to it too.

## Teacher surface

Teachers currently have no cohort pages. The surface adds, for an active
teacher assignment only:

* "Lớp của tôi" list of the teacher's active cohort assignments;
* a cohort judgment page: students with an active membership, the course
  competencies (mapped first, with the Lesson/Activity labels from the mapping
  snapshot) and the latest judged level per student and competency;
* a judgment form: level from the frozen scale, optional score, reason,
  occurred-at; submission through `TeacherJudgmentService` only.

The UI layout is reviewed as a mockup before implementation.

## Verification obligations

* MariaDB: a judgment on a Node of a Framework Version the Course Version did
  not map is refused with `LF_TEACHER_JUDGMENT_BASIS_NOT_IN_COURSE`, and writes
  nothing (no judgment, evidence, calculation or profile row).
* MariaDB: an unmapped Node of a mapped Version is accepted (option A).
* MariaDB: an invalidated mapping no longer qualifies its Version.
* MariaDB: a correction keeps passing when the basis is still in scope.
* Existing Phase 4E negative matrix stays green unchanged.
* HTTP: a teacher sees only own active cohorts; another teacher, admin, student
  and another tenant are denied.

## Implementation — 2026-10-11

Owner approved the mockup on 2026-10-11 ("duyệt, nhập điểm theo %"), with help
tooltips on every non-obvious field.

* `TeacherJudgmentService` refuses a basis outside the course scope with
  `LF_TEACHER_JUDGMENT_BASIS_NOT_IN_COURSE`, checked after the existing basis
  lifecycle rule so earlier codes are unchanged.
* Read path for the teacher surface, approved with this amendment as the
  separately approved runtime read named by G2-N1:
  `LearningCourseCompetencyReadService` (Learning-owned; reads only
  `core_learning_*`, keyed by the Course Version id in `source_discriminator`)
  and `TeacherCohortReadService` (cohorts, students and latest judgments of the
  teacher's own active assignments; a cohort without one is a 404).
* Routes under `role:teacher`: `teacher.cohorts.index`, `teacher.cohorts.show`,
  `teacher.cohorts.judgments.store`. The form sends only membership, basis,
  competency, level, score in percent, reason and a local observation time; the
  controller derives tenant, teacher, assignment, enrollment and student, turns
  the percent into 0–1 and adds the application timezone offset.
* "Chấm lại" records a new judgment; corrections (`supersedes_judgment_id`) are
  not offered by this surface.
* Shared `<x-info-tip>` help bubble (hover, keyboard focus or tap).

## Form revision — 2026-10-12

Owner request on 2026-10-12: once a score is typed, levels that cannot match it
are locked.

* The service accepts a score only with the level its thresholds give (the
  highest level whose threshold the score reaches); any other level is refused
  with `LF_TEACHER_JUDGMENT_RESULT_INVALID`. With a score there is therefore
  exactly one valid level.
* The judgment form selects that level as the score is typed and disables the
  other level options (shown dimmed). Clearing the score unlocks every level, so
  a level without a score stays possible.
* Presentation only: the service rule is unchanged and still refuses a
  mismatched level sent by any other means.
* Each level option shows its minimum percentage on a second line under the
  level name, so the options line up.

Verification: checked in the browser (10 → Chưa bắt đầu, 50 → Đang phát triển,
78 → Thành thạo, 95 → Làm chủ, others locked; empty score → all open). The
MariaDB teacher judgment tests and the full SQLite suite stay green
(1435 passed, 23 skipped).

Verification: `TeacherJudgmentRuntimeMariaDbTest` (course-scope refusal writes
nothing, unmapped Node of a mapped Version accepted, invalidated mapping out of
scope), `TeacherCohortJudgmentHttpMariaDbTest` (own cohorts only, mapped first,
percent to fraction with offset, readable refusals, other teacher/admin/student
denied, foreign membership refused) and the updated
`LearningFrameworkAuthoringMariaDbTest` end-to-end case.
