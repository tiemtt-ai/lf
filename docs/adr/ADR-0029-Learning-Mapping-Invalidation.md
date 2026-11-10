# ADR-0029 — Marking A Published Learning Mapping As Wrong

Version: 1.2

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-10

Proposal Date: 2026-11-10

Approval Date: 2026-11-10

Approved By: LearnForge Business/Architecture Owner

Document Path: adr/ADR-0029-Learning-Mapping-Invalidation.md

Related:

* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md)
* [ADR-0013 — Course Template Version Duplicate to Draft](ADR-0013-Course-Template-Version-Duplicate-to-Draft.md) — duplicate skips invalidated Mappings
* `docs/database/learning/core_learning_node_mappings.md` § Immutability And Deletion

---

# Context

Publishing a Course Template Version promotes its Mapping Intents into
`core_learning_node_mappings` (Lesson/Activity of the published Version → Learning
Node, with role and weight). The Mapping is then immutable.

Review 2 of the course flow (2026-11-10) found that a wrong link has no correction
path. The table already supports one: `invalidated_at`, `invalidated_by` and
`invalidation_reason` are set together, once, by the only update the
`BEFORE UPDATE` trigger allows. Teacher judgment (`TeacherJudgmentService`), the
cohort competency list (`LearningCourseCompetencyReadService`) and duplicate to
draft already ignore invalidated rows. Nothing in the application writes them.

Today the only fix is a new Template Version. Cohorts already on the old Version
keep judging against the wrong link until they end.

Owner decision 3b (2026-11-10): add "mark as wrong", with a required reason and
nothing deleted. Because it changes what a published Version means to teacher
judgment, it is decided here before any code (Guardrails Rule 7).

# Decision

## 1. Who and where

* Only `customer_admin`. The capability is Learning curriculum authority (Owner
  decision N1, the same capability that publishes a Framework Version).
* Template workspace → published Version detail → competency links of that
  Version. Each active link has "Đánh dấu sai". Invalidated links stay listed,
  with the reason, the person and the time.

## 2. What it does

* One transaction sets `invalidated_at = now`, `invalidated_by = actor` and
  `invalidation_reason` (required, trimmed, 1–1000 characters) on one Mapping of
  the actor's tenant whose source is a published Course Version Lesson or
  Activity. The Mapping must not already be invalidated.
* Nothing is deleted. No other Mapping field, no Course Version row, no Evidence,
  no Mastery calculation and no AI provenance changes.
* There is no undo. A wrong invalidation is corrected the way the table already
  defines: a new Template Version whose publish creates a new Mapping.

## 3. Effects

Teacher judgment follows Option A (LF-Teacher-Judgment-Course-Scope-Amendment,
Owner 2026-10-11): a cohort may judge every active Node of a Framework Version
its Course Version mapped; mapped Nodes are listed first with their Lesson or
Activity labels. Invalidating one link therefore:

* removes that link's label and its "mapped" ordering from the judgment list;
* takes the whole Framework Version out of the cohort's judgment scope only when
  no other valid link of the Course Version maps it;
* leaves the Node judgeable as an unmapped competency while another valid link
  keeps its Framework Version in scope.

Evidence recorded before keeps its frozen source interpretation and stays as it
is. Duplicating that Version to the draft does not recreate the link (ADR-0013,
already implemented).

Amendment 2026-11-10 (during implementation): version 1.0 also proposed refusing
a judgment unless a valid link names the exact Node. That would end Option A for
every Node never linked, not only for wrong links, so it is withdrawn and not
implemented. Owner decision 2026-11-10: keep Option A; no Node-level check.

## 4. Rule 7

The published Course Version content (snapshots, Lessons, Activities) is not
touched. A Mapping is a Learning record about that Version, and invalidation is
the single correction its own table contract allows, so this ADR adds no new
exception to Rule 7. It only opens the application path to the existing
lifecycle.

# Alternatives rejected

* **Only a new Version.** It leaves running cohorts judging on a known wrong link.
* **Delete the Mapping.** Breaks Evidence lineage; the trigger forbids it.
* **Edit role or weight in place.** Semantic edit of an immutable row; a corrected
  link is a new row through a new Version.
* **Let assigned teachers mark links wrong.** Teachers judge; changing the measure
  is curriculum authority (N1).

# Compatibility and migration

* No migration. Columns, CHECK and trigger exist.
* Code: a Learning-owned command (Course passes tenant, actor, Mapping ID and
  reason; Learning validates and writes), an admin route, the list on the Version
  detail, lang keys vi/en.
* Tests on MariaDB 11.4: success path, empty reason refused, second invalidation
  refused, other-tenant Mapping 404, teacher 403, the trigger still refuses any
  other update, the judgment list drops the link (and the Framework Version when
  it was the last link), existing Evidence untouched.

# Architecture Review (2026-11-10) — Pass

* Tenant: every read and the write filter on the actor's `customer_id`; a Mapping of another tenant is 404.
* Rule 7: no Course Version row changes; the write is the one transition the Mapping trigger allows.
* Learning ownership: Learning validates and writes; Course only passes the command.
* Database: no schema change.

# Approval

Approved by the Business/Architecture Owner on 2026-11-10. The Node-level save
check of version 1.0 is withdrawn (§3 amendment); the Owner kept Option A on
2026-11-10, so no Node-level check is planned.

# Implementation record

* Version page lists every link of the Course Version (`LearningFrameworkReadService::courseVersionMappingReview`);
  admin marks one wrong with a reason (`LearningFrameworkAuthoringService::invalidateCourseMapping`,
  route `admin.course-templates.versions.links.invalidate`).
* Tested on MariaDB 11.4 (2026-11-10): teacher 403, other tenant 404, blank reason refused, once only,
  no other field changes, nothing deleted, teacher judgment list drops the label, trigger still refuses other updates.
