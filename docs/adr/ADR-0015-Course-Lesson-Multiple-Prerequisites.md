# ADR-0015 — Course Lesson Multiple Prerequisites

Version: 1.0

Status: Approved

Proposal Date: 2026-07-26

Decision Date: 2026-07-26

Extends:

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)
* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md)
* [ADR-0013 — Course Template Version Duplicate to Draft](ADR-0013-Course-Template-Version-Duplicate-to-Draft.md)

---

# Context

Course Lesson authoring currently supports either no restriction, one manually
selected prerequisite Lesson, or a date-based unlock. A single
`unlock_after_lesson_id` cannot represent these authoring requirements:

* complete every preceding Lesson;
* complete a selected set of preceding Lessons;
* require all or any Lesson in that selected set.

The published learner experience must remain immutable and tenant-safe. It
must not recalculate prerequisites from a mutable Template after publication.

# Decision

## Canonical Unlock Rules

Working and Version Lessons support:

```text
none
all_previous_lessons_completed
selected_lessons_completed
date_based
```

`selected_lessons_completed` additionally stores `prerequisite_match`:

```text
all
any
```

`all` requires every selected prerequisite. `any` requires at least one.
`prerequisite_match` is not meaningful for the other rules.

The legacy `previous_lesson_completed` rule is migrated to
`selected_lessons_completed` with `prerequisite_match = all` and one explicit
prerequisite.

## Previous Lesson Ordering

“Previous” is determined from the canonical authoring content lane:

* a direct Lesson considers only earlier direct Lessons;
* a Sectioned Lesson considers earlier Sectioned Lessons in depth-first
  Section-tree order, then Lesson `sort_order`, then Lesson `id`;
* direct and Sectioned Lessons do not form prerequisite relationships across
  lanes.

Selected prerequisites must precede the dependent Lesson in the same lane.
This restriction keeps the learning path understandable, makes cycles
impossible in valid authoring state and prevents a later Lesson from blocking
an earlier Lesson.

The first Lesson in a lane cannot use
`all_previous_lessons_completed`, because the rule would have no effective
prerequisite. The authoring UI must disable or reject that choice.

## Normalized Prerequisite Graph

Multiple prerequisites are stored as normalized tenant-owned relationship
rows:

```text
core_course_template_lesson_prerequisites
core_course_template_version_lesson_prerequisites
```

The Lesson row owns only the unlock rule and selected-set match policy. The
relationship tables own the prerequisite edges. Metadata or JSON is not used
for canonical relationships.

Every relationship is scoped to the same `customer_id`, Template or Version,
and content lane as its dependent Lesson.

## Publish Snapshot

Publish resolves the effective prerequisite set while holding the working
Template lock:

* `selected_lessons_completed` copies the explicitly selected working edges;
* `all_previous_lessons_completed` expands the canonical preceding Lessons
  into explicit Version prerequisite edges;
* `none` and `date_based` create no Version prerequisite edges.

The Version Lesson retains the authored rule and match policy snapshot, while
the Version prerequisite rows freeze the exact effective set. Runtime never
derives “previous” from mutable working rows or from a later display order.

Publishing fails closed when an edge is cross-tenant, cross-Template,
cross-Version, cross-lane, self-referencing, non-preceding, duplicated, empty
for a prerequisite rule, or otherwise inconsistent.

## Runtime Access

Runtime evaluates only immutable Version Lesson data in the exact same:

```text
customer
student
Enrollment
Product
Template Version
```

For `prerequisite_match = all`, every prerequisite Version Lesson must have
completed Lesson Progress. For `any`, at least one must have completed Lesson
Progress. A missing or inconsistent relationship set fails closed.

`date_based` continues to compare the frozen UTC timestamp. Unknown rules fail
closed.

## Duplicate Version To Draft

Duplicate-to-draft recreates new working Lesson identities first and then
reconstructs prerequisite edges:

* a Version Lesson authored as `selected_lessons_completed` restores the
  selected set and match policy;
* a Version Lesson authored as `all_previous_lessons_completed` restores that
  rule; its working effective set is derived again from the restored draft
  order;
* no immutable Version row is changed.

## Compatibility Rollout

The first migration is additive:

1. add working and Version prerequisite relationship tables;
2. add working `prerequisite_match` and Version
   `prerequisite_match_snapshot`;
3. backfill every valid legacy single prerequisite as one normalized edge;
4. convert its rule to `selected_lessons_completed` with match `all`;
5. keep legacy single-prerequisite columns temporarily for rollback and
   verification, but stop writing them from the new application flow.

Rollback is lossless-or-refused:

* `selected_lessons_completed` with match `all` and exactly one normalized
  edge is restored to legacy `previous_lesson_completed`;
* the normalized edge is copied back to the legacy prerequisite column before
  the relationship tables or match columns are removed;
* `any`, multiple selected prerequisites, `all_previous_lessons_completed`,
  missing edges and incompatible orphan edges cannot be represented by the
  legacy schema, so rollback must fail before changing data or schema;
* operators must migrate affected authoring data forward or use a separately
  reviewed data-conversion procedure before retrying rollback.

Removal of legacy columns requires a separate reviewed cleanup migration after
production verification. New runtime behavior reads the normalized graph.

# Authorization And Tenant Isolation

Existing Lesson authoring and publish authorization remains unchanged. Every
query and mutation resolves the tenant through
`TenantContext::customerId()`. Request-provided tenant IDs are never trusted.

# Consequences

## Positive

* Supports simple sequential, selected-AND and selected-OR learning paths.
* Preserves immutable published behavior after draft reorder or editing.
* Makes prerequisite relationships queryable and auditable.
* Keeps learner access evaluation inside Course and Progress boundaries.

## Cost

* Adds two relationship tables and one match-policy field per Lesson layer.
* Publish and duplicate require graph mapping.
* Authoring reorder can invalidate selected prerequisites and must be blocked
  or corrected before publish.
* Legacy data requires an additive backfill and a later cleanup gate.

# Approval

The Architecture Owner approved this ADR and confirmed Foundation Freeze on
2026-07-26. Migration and implementation are authorized under the documented
additive compatibility plan.

---

End of Document
