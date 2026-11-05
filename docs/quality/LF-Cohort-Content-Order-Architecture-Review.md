# Cohort Content Order Architecture Review

Version: 1.0

Document Status: Draft

Implementation Status: Not Applicable

Last Updated: 2026-11-05

Review Date: 2026-11-05

Document Path: quality/LF-Cohort-Content-Order-Architecture-Review.md

Target: [ADR-0023](../adr/ADR-0023-Cohort-Content-Order.md),
[core_course_cohort_lesson_orders](../database/course/core_course_cohort_lesson_orders.md),
[core_course_cohort_activity_orders](../database/course/core_course_cohort_activity_orders.md).

**Verdict: Pass with changes. Not frozen.** Reviewed by reading documents and code;
no database or test run. The product decisions approved by the Owner were not
reopened.

---

## High

1. **ADR §3 conflicts with occurrence consumption.** An occurrence stays consumed
   by its Origin after the Session moves (`LiveClassSessionOriginService.php:58-63`).
   Activity X has Session S on Wednesday; Y has none and is proposed Friday. After
   dragging to Y, X, S moves to Friday. Wednesday can never be proposed again.
   Needs an Owner decision.
2. **"Position" is undefined for real Sessions.** An Activity can have several
   Sessions; a locked Lesson can sit inside the changed range; dates can be out of
   order after an earlier reschedule; slots can differ in length; each Session
   stores wall-clock time in its own timezone. `recordScheduleChange` sets
   `status = scheduled`, so moving a cancelled Session would revive it.
3. **The overlap check cannot run one Session at a time.** It excludes a single id
   (`CourseCohortOperationController.php:1054`), so any swap fails against the
   other Session's stored time.

## Medium

4. ADR §4 literally locks cancelled Sessions once their time passes. Whether
   `no_show` locks needs an Owner decision. Evidence must be one shared check; it
   is duplicated today.
5. ADR §5 does not state detection. It must read the Version prerequisite table and
   `unlock_after_version_lesson_id`, and apply to every edge whatever
   `prerequisite_match` says. `CourseTemplateLessonUnlockOrder` reads working tables
   and cannot be reused. A `date_based` Lesson has no edge, so a Session can move
   before its unlock date; needs an Owner decision.
6. `core_course_cohorts` has a composite key `(id, customer_id)` for child tables.
   `cohort_id` should use it. Version tables have none, so `version_*_id` stays simple.
7. Locking the Cohort alone is not enough: `updateSession` locks only the Session.
   Lock the Cohort, then the affected Sessions, and evaluate §4 inside the transaction.

## Low

8. Moved Sessions without an Origin do not show `rescheduled`; a Session moved back
   returns to `on_schedule`.
9. The Version-change clause in §8 is dead text: `version_id` is only set from `NULL`.
10. The Cohort gate is not stated: `canMutate` and `assertRunsInClass`.
11. §7 does not cover an ended membership.
12. Database documents do not name constraints and indexes.
13. Documentation drift: `database/course/README.md` wording, Core documents, and
    `LF-SCHEMA-CONTRACT.json` at migration time.
14. ADR-0002 defers Schedule-driven bulk rescheduling. A drag is not Schedule-driven;
    §3 should say so and use a fixed audit `reason`.

Cascade on `cohort_id` needs no change: hard delete exists only for empty draft
Cohorts. Record it as a deliberate exception.

---

## Found In Delivered Work

The same cause affected batch 1 of the Sessions tab: after a Session moved onto a
later occurrence, that occurrence was still proposed and saving it failed the
overlap check. Fixed in `CourseCohortContentTree`: an occurrence overlapping a
Session that is not cancelled or `no_show` is not proposed.

---

## Second Pass: ADR-0023 v1.1

**Verdict: Pass with changes; all changes applied in v1.2.** The re-point mechanism holds:
it fits the existing Session edit rules, Origin has no binding columns, and the date mapping
keeps each date at its position across a drag. Findings 1, 3, 8 and 14 above no longer apply.

1. **High.** Live Activity prerequisites were not checked. Now a drag cannot move a live
   prerequisite behind its live dependent.
2. **High.** Step 4 compared the title with the wrong default, could give an offline Session a
   link, and left `online_provider` stale. Corrected.
3. **Medium.** A form opened before a drag could undo it. Now rejected without a new column.
4. **Medium.** Table documents disagreed with the lock rule in §4. Aligned.
5. **Low.** Column name, private method reference and undefined terms corrected.

Owner decisions on 2026-11-05: any Session past its start locks, whether learners attended, were
absent, or it was cancelled. A `date_based` Lesson may be dragged. First-pass findings 4 and 5 are closed.

Read-only review; nothing was executed. Owner approved v1.2 on 2026-11-05 and the table documents are Frozen.
