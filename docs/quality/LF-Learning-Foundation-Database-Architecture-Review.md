# Learning Foundation Database Architecture Review

Version: 1.6

Document Status: Approved

Implementation Status: Not Applicable

Review Status: Pass

Last Updated: 2026-08-13

Document Path: quality/LF-Learning-Foundation-Database-Architecture-Review.md

## Scope

Architecture and physical-contract review of the ten Learning Foundation table
documents governed by ADR-0016 and LF-Core-Learning Version 1.1. This review is
the Database/Architecture Review gate for Phase 3; it does not authorize a
migration.

## Verdict

```text
Round 1 invariant result: 11 PASS / 6 PARTIAL / 3 FAIL
Round 1 severity result:  5 BLOCKER / 20 MAJOR / 10 MINOR

Round 2 severity result:  0 BLOCKER / 17 MAJOR / 10 MINOR / 7 NEW

Final verdict: BLOCKED
```

The policy layer is coherent: ownership, D4/D6/D11, E2/E3 and the Phase 2B
governance routing are aligned. The blocking defects are in the proposed
physical contracts, where prose assertions do not yet have enforceable columns,
keys or a single approved database mechanism.

## Blockers

| ID | Finding | Required remediation |
| --- | --- | --- |
| B1 | `core_learning_nodes` lacks `framework_id`; Definition and Version membership in the same Framework is only application validation. | Add the denormalized Framework key and composite foreign keys that prove both parent paths. |
| B2 | Documents leave enforcement as “trigger or approved persistence guard”. | Select one physical mechanism for every cross-row invariant and name the exact constraints/triggers. |
| B3 | Calculation Evidence requires a shared learner but has no `user_id`. | Add `user_id` and tenant/learner composite foreign keys to Calculation and Evidence. |
| B4 | `users.customer_id` is nullable and `users` lacks `UNIQUE (id, customer_id)`, so tenant-safe learner/actor FKs cannot be created. | Make the composite user key a Phase 3 physical prerequisite; Learning rejects global users and references `(user_id, customer_id)`. Backfill/preflight is mandatory before migration. |
| B5 | `core_course_activity_progress` is mutable state and cannot be immutable Evidence lineage. | Do not reference it as the Evidence source. Keep Course-derived Evidence closed until an append-only source event contract is implemented; allow teacher judgment through an immutable submission identity. |

## Named Major Findings

| ID | Finding | Required remediation |
| --- | --- | --- |
| M3 | ADR uses `calculation_source`, table doc uses `calculation_type`. | Standardize on `calculation_source`. |
| M4 | ADR status text says table docs do not exist while its result says they were created. | State consistently that they exist in Review and migration is blocked. |
| M8 | A wrong mapping has no approved invalidation path. | Add an audited invalidation lifecycle without allowing semantic-field mutation. |
| M14 | `usage` risks a MySQL reserved-word collision. | Rename it to `evidence_role`. |

## Remediation Tracking

This file records the reviewer-provided summary and named findings. When this
official record was first created, the source review artifact was not present
in the workspace and the remaining 16 MAJOR and 10 MINOR findings could not be
reconstructed. Round 2 re-review has now supplied them; they are recorded in
full in the sections "Open MAJOR Findings" and "Open MINOR Findings" under
Round 2 Independent Re-review below. Those headings contain an em dash, so
they are referenced by name rather than by anchor.

### Remediation applied, pending independent re-review

* B1: `framework_id` and two same-tenant/same-Framework composite parent FKs
  added to the Node contract.
* B2: composite FK → CHECK → named database-trigger hierarchy fixed in
  `database/learning/README.md`; application guards are defense in depth only.
* B3: junction `user_id` plus composite Calculation/Evidence FKs added.
* B4: repository migration inspection found
  `2026_06_10_000000_enforce_user_customer_ownership.php` already makes
  `users.customer_id` non-null. The missing `UNIQUE (id, customer_id)` is now an
  explicit User/Learning physical prerequisite; no migration has been created.
* B5: mutable Course progress removed as source identity; Course-derived
  Evidence remains closed until an append-only event contract is implemented.
* M3/M14: standardized `calculation_source`; renamed `usage` to
  `evidence_role`.
* M4: status language was intended to say consistently that the ten docs exist
  in Review and do not authorize migration. Round 2 verification found this
  remediation incomplete — see the M4 evidence note below. M4 remains open.
* M8: audited one-way mapping invalidation contract added.

After remediation, reviewers must rerun the full invariant checklist and update
this document with evidence. Until the verdict becomes PASS and Foundation
Freeze is recorded:

* table documents remain `Review`;
* schema entries remain `not_implemented`;
* no Learning migration or runtime implementation is permitted.

---

# Round 2 Independent Re-review

Date: 2026-08-12. Scope: the full working tree after remediation round 1,
including all eleven files under `docs/database/learning/`, the ADR and domain
policy diffs, `LF-SCHEMA-CONTRACT.json`, and the `users` migration history.

```text
Round 1:  5 BLOCKER · 20 MAJOR · 10 MINOR
Round 2:  0 BLOCKER · 17 MAJOR · 10 MINOR · 7 NEW (N1–N7)

Final verdict: BLOCKED — Foundation Freeze and migration remain forbidden.
```

The round 1 Critical Failure (cross-tenant exposure) is resolved. The remaining
Critical Failure is procedural only: Foundation Freeze is not owner-confirmed.

## Reviewer Correction — B4

Round 1 stated that `users.customer_id` is nullable. That half was wrong.
`2026_06_10_000000_enforce_user_customer_ownership.php` already sets the column
to `nullable(false)`, deletes tenant-less users and recreates the foreign key
with `restrictOnDelete()`. The round 1 grep pattern used `users` and missed the
file, whose name uses `user_customer_ownership`. The remaining and still valid
half of B4 is the absent `UNIQUE (id, customer_id)`. The remediation correction
is accepted.

## Verification of Named Findings

| ID | Round 2 verdict | Verification basis |
| --- | --- | --- |
| B1 | Closed | Every composite FK traced to a declared parent key; see below |
| B2 | Closed with residue | Strategy fixed; residue tracked as N3, N4, N7 |
| B3 | Closed | `user_id` plus two composite FKs; parent keys match |
| B4 | Closed | Explicit physical prerequisite; `LF-Core-User` raised to 1.1 |
| B5 | Closed | Propagated to ADR and domain policy; residue tracked as N5 |
| M3 | Closed | `calculation_source` in the table contract |
| M4 | **Not closed** | Contradicting text still present; see N-series preamble |
| M8 | Closed | Audited one-way invalidation lifecycle plus trigger |
| M14 | Closed | Renamed to `evidence_role` |

Composite foreign key verification. Each child FK was traced to its declared
parent unique key; all ten pairs resolve:

| Child foreign key | Required parent key | Declared in |
| --- | --- | --- |
| `nodes(node_definition_id, customer_id, framework_id)` | `UNIQUE (id, customer_id, framework_id)` | `core_learning_node_definitions` |
| `nodes(framework_version_id, customer_id, framework_id)` | `UNIQUE (id, customer_id, framework_id)` | `core_learning_framework_versions` |
| `relations(source_learning_node_id, customer_id, framework_id, source_framework_version_id)` | `UNIQUE (id, customer_id, framework_id, framework_version_id)` | `core_learning_nodes` |
| `relations(target_learning_node_id, customer_id, framework_id, target_framework_version_id)` | same key | `core_learning_nodes` |
| `calculation_evidence(mastery_calculation_id, customer_id, user_id)` | `UNIQUE (id, customer_id, user_id)` | `core_learning_mastery_calculations` |
| `calculation_evidence(evidence_id, customer_id, user_id)` | `UNIQUE (id, customer_id, user_id)` | `core_learning_evidence` |
| `evidence(supersedes_evidence_id, customer_id, user_id)` | `UNIQUE (id, customer_id, user_id)` | `core_learning_evidence` |
| `profiles(current_calculation_id, customer_id, user_id, node_definition_id, basis_framework_version_id)` | `UNIQUE (id, customer_id, user_id, node_definition_id, basis_framework_version_id)` | `core_learning_mastery_calculations` |
| `versions/definitions(framework_id, customer_id)` | `UNIQUE (id, customer_id)` | `core_learning_frameworks` |
| `mappings/evidence(learning_node_id, customer_id)` | `UNIQUE (id, customer_id)` | `core_learning_nodes` |

Both relation endpoint FKs share one `framework_id` and one `customer_id`
column on the relation row, so a cross-tenant or cross-Framework relation is
not representable in the database. D11 moves from "must be verified" to
"cannot be expressed". Largest composite unique keys (`evidence` and
`mappings`, about 1.4 KB under utf8mb4) stay below the 3072-byte InnoDB
`DYNAMIC` limit.

M4 evidence. `adr/ADR-0016-Learning-Foundation.md` lines 663–665 still read
"Canonical table documentation sẽ đặt tại `docs/database/learning/` và **chưa
tồn tại** tại thời điểm ADR này ở trạng thái Review", while the same file
declares `Status: Approved` and its Result block declares "10 table docs đã
tạo". The clause is now false on two counts, so M4 is worse than in round 1.
`docs:lint` passing indicates its ADR self-contradiction check compares
metadata fields rather than body text.

## Findings Introduced Or Exposed By Remediation

| ID | Severity | Class | Finding |
| --- | --- | --- | --- |
| N1 | MAJOR | `CONFLICT` | Continuity approval deadlock on `core_learning_node_relations` |
| N2 | MAJOR | `CONFLICT` | Approved ADR edited in place without amendment record or version bump |
| N3 | MAJOR | `GAP` | Columns declared immutable with no enforcement under the new strategy |
| N4 | MAJOR | `GAP` | Trigger-based source validation hard-couples Learning to Course physical tables |
| N5 | MAJOR | `AMBIGUITY` | `database/learning/README.md` states two conflicting Phase 1 whitelists |
| N6 | MINOR | `AMBIGUITY` | Placeholder column name `actor_id` used across five contracts |
| N7 | MAJOR | `GAP` | Enforcement strategy has an unstated database version floor |

N1 — continuity approval deadlock. Three statements cannot all hold. The
relation contract requires `approved_by`/`approved_at` when policy permits
carry-forward. ADR-0016 makes `equivalent_to` default to `requires_review`,
so approval happens after the relation exists. The new lifecycle text has
`BEFORE UPDATE` and `BEFORE DELETE` triggers reject changes "after either
endpoint Version is published". A `version_transition` relation is only
meaningful once the target Version is published, and at that moment the row
freezes, so the approval columns can never be filled. `requires_review`
becomes a terminal state and lawful carry-forward is unreachable. Options for
the owner: allow exactly one `NULL → NOT NULL` transition on the approval pair,
mirroring the accepted `invalidated_at` pattern; move continuity approval to a
separate table with its own lifecycle; or require approval at INSERT and state
that `requires_review` means the relation is not yet created. N1, M6, m8 and
m9 should be resolved by one decision.

N2 — change control. `adr/README.md` § Change Policy requires an amendment
linked to the original ADR and forbids rewriting approved decisions in a way
that loses prior context. Remediation edited D8, § Enforcement Requirements,
§ Relationship With Course, Invariants 15 and 20, § Implementation Scope
Phase 1 and § Deferred Decisions E2 directly on an ADR carrying
`Status: Approved` and an Owner Approval block. Invariant 20 and the Phase 1
scope change the Evidence strategy, which ADR-0016 § Foundation Change Control
itself lists as requiring an ADR Amendment. ADR-0016 and LF-Core-Learning both
remain `Version: 1.0`, while `LF-Core-User` and `LF-Naming-Convention` were
correctly raised to 1.1 in the same round. Mitigating context: approval and
edit occurred on the same date, 2026-08-12. The owner must either record an
amendment and raise both documents to 1.1, or restate that the Owner Approval
block covers the post-remediation text.

N3 — unenforced immutability. The approved strategy states application
validation is never the sole enforcement of a Foundation invariant, yet three
columns declared immutable sit outside all three enforcement layers:
`core_learning_node_definitions.framework_id` (no trigger at all — this is the
only one of the ten tables absent from the Trigger Contract),
`core_learning_nodes.framework_id` and
`core_learning_node_relations.framework_id` (their triggers freeze only after
publication, so a draft row can still move Framework if the whole FK tuple is
updated together). `core_learning_node_definitions` § Business Rules states "A
Definition cannot move between Frameworks" with nothing enforcing it.

N4 — trigger coupling. `trg_lrn_mappings_bi_source` must `SELECT` into
`core_course_template_version_lessons` and
`core_course_template_version_activities` and branch on `source_type`. Four
undocumented consequences: Learning triggers hard-code Course physical table
names, inverting the coupling the Generic Reference Principle avoids; opening a
Phase 2 source becomes a `DROP`/`CREATE TRIGGER` migration rather than
configuration; the README fixes trigger *names* but not trigger *bodies*, so
`schema:drift` can only confirm existence, not correctness; and
`trg_lrn_calc_evidence_bi_validate` must parse `continuity_policy_snapshot`
JSON to verify transition lineage, which is a direct consequence of M11 being
open. M1 is a prerequisite for writing this trigger at all.

N5 — conflicting whitelists. § Foundation Constraints states the Phase 1
whitelist covers "verified immutable Course Version Lesson/Activity sources and
approved Teacher Judgment events", while § Phase 1 Evidence Source Gate states
"Teacher Judgment is the only source open at initial Foundation activation".
The first sentence describes the Mapping whitelist and the second the Evidence
whitelist, but neither says so. The two whitelists must be named distinctly.

N6 — placeholder column names. Five contracts use "actor FKs use
`(actor_id, customer_id) → users(id, customer_id)`", but no table has a column
named `actor_id`. The real columns are `created_by`, `updated_by`,
`published_by`, `deprecated_by`, `archived_by`, `approved_by`, `recorded_by`,
`calculated_by` and `invalidated_by`. Either enumerate each actor FK or define
`actor_id` as a stated convention in the README.

N7 — database version floor. Layer 2 of the strategy depends on `CHECK` and
layer 3 on triggers. `tech/LF-Tech-Stack.md` § Database Layer names "MySQL"
without a minimum version; `config/database.php` declares both `mysql` and
`mariadb` drivers; CI runs `mariadb:11.4.3`, which supports everything
required. The risk is that MySQL below 8.0.16 parses `CHECK` and silently
ignores it, with no error, removing the entire CHECK layer while the
documentation still claims database enforcement. The contract must pin a floor
per driver and add a version assertion to the same deployment preflight that
verifies the `users` composite key.

## Open MAJOR Findings — 16 open

Supplied by round 2 re-review and re-verified against the current document
state, not copied from round 1.

| ID | Table / document | Class | Finding | Required remediation |
| --- | --- | --- | --- | --- |
| M1 | `core_learning_node_mappings` | `GAP` | Whitelist keys `course_version_lesson` / `course_version_activity` are not bound to physical table names. The real tables are `core_course_template_version_lessons` and `core_course_template_version_activities`. Precedent: `media_file_usages` enumerates all twelve `owner_type` values. | Publish a three-column table: `source_type` → physical table → the column used as `source_discriminator`. Prerequisite for N4. |
| M2 | `core_learning_evidence` | `GAP` | No enumerated `source_type` whitelist exists anywhere, although `trg_lrn_evidence_bi_validate` is said to validate "the open source whitelist". Evidence is now the only open entry point. Related hazard: `evidence_type` uses `expert_judgment` while the source key is written `teacher_judgment`. | Enumerate the permitted `source_type` values as a code block, as the Mapping contract does, and state whether the two token spaces are distinct. |
| M5 | `core_learning_nodes` | `AMBIGUITY` | `status` allows `retired` while published Versions are immutable (ADR Invariant 3). `trg_lrn_nodes_bu_immutable` now answers this technically — retirement is only reachable in draft — but no document says so. | State that `retired` is an authoring state settable only inside a draft Version, and is not a mechanism for removing a Node from a published Version. |
| M6 | `core_learning_node_relations` | `GAP` | § Lifecycle refers to an "owning Version graph" but no `owning_framework_version_id` exists, so a transition relation has no owning Version. Combined with the new freeze trigger, the authoring window for transition relations nearly closes: V1 is normally already published, so the relation must be created before V2 publishes. | Resolve together with N1 as one decision on the transition-relation lifecycle. |
| M7 | `core_learning_node_relations` | `GAP` | `relation_type` sits inside the business UNIQUE, so the same pair (A→B) may carry both `equivalent_to` and `supersedes`, whose default continuity policies contradict each other, with no precedence rule. No constraint prevents `prerequisite` or `part_of` cycles. | Decide precedence or forbid multiple types per ordered pair, and decide how the DAG invariant is enforced. If by trigger, add it to the Trigger Contract. |
| M9 | `core_learning_mastery_calculations` | `GAP` | No idempotency key; the two new composite uniques are FK-support keys and do not prevent a duplicated job creating near-identical Calculations. `core_learning_mastery_profiles` refers to "deterministic ordering" that is defined nowhere. No document declares `TIMESTAMP` precision, so same-second Calculations tie with no breaker. Compare `producer_idempotency_key` on Evidence. | Define canonical ordering (for example `(calculated_at, id)`), declare timestamp precision, and add a calculation idempotency key for `calculation_source = 'system'` or justify its absence. |
| M10 | `core_learning_evidence` | `GAP` | Partially improved: the new `(supersedes_evidence_id, customer_id, user_id)` composite FK blocks cross-tenant and cross-learner corrections. Still missing `UNIQUE (customer_id, supersedes_evidence_id)`, so two rows may correct the same original and the chain forks. No column or index marks a row as superseded, so "currently valid evidence" requires an anti-join, including inside `trg_lrn_calc_evidence_bi_validate`. | Add the uniqueness that keeps the correction chain linear and decide how supersession is queried. |
| M11 | `core_learning_mastery_calculations` | `GAP` | Carry-forward lineage exists only as `continuity_policy_snapshot` JSON; there is no `source_node_relation_id`. This contradicts the principle asserted by `core_learning_calculation_evidence`: "The Evidence set is not hidden in JSON because it is canonical audit lineage." It is also the root cause of the JSON parsing burden in N4. | Add a composite FK to the authorising relation, or record why continuity lineage is exempt from the rule applied to Evidence lineage. |
| M12 | `core_learning_framework_versions` | `AMBIGUITY` | `UNIQUE (customer_id, version_code)` is tenant-scoped while `core_learning_node_definitions` scopes its equivalent code by `(customer_id, framework_id, code)`. Two Frameworks in one tenant therefore cannot both use `version_code = "v1"`. | Confirm whether tenant-wide scoping is intentional; if not, scope by `framework_id`. |
| M13 | `core_learning_mastery_profiles` | `GAP` | `mastery_status` has values `established`, `needs_review`, `reassessment_due`, but no field in `core_learning_mastery_calculations` derives them, while `current_calculation_id` is documented as the source of every projection value. `trg_lrn_profiles_bi_projection` cannot validate this column because there is nothing to compare it to, so one read-model column sits outside the newly approved enforcement. | Define the derivation rule and state how the projection trigger validates it. |
| M15 | `database/LF-SCHEMA-CONTRACT.json` | `GAP` | All ten `core_learning_*` entries have `columns`, `indexes`, `foreign_keys`, `checks` and `triggers` empty, with `implementation_status: not_implemented`. Gate item 3 is therefore unmet. `schema:drift --docs-only` passing with an empty contract is correct tool behaviour and is not evidence for that gate. The 24 named triggers are absent from the contract, so drift can never detect a missing or altered trigger. | Populate all ten entries including the trigger list, and consider the `planned` vocabulary once table docs are Approved. |
| M16 | all ten table docs | `GAP` | `LF-Data-Modeling.md` § LearnForge Design Flow mandates `Domain → Table → Relationship → Business Rules → Fields → Indexes → Sample Data` and states relationships must precede fields. The ten docs go from `## Purpose` straight to `## Fields`; none has `## Relationships` or sample data. Precedent: `core_course_enrollments.md` documents each parent as a 1→N diagram. Noted fairly: the README carries an overall relationship map and the new docs are far more concise. | Either add the missing sections or amend `LF-Data-Modeling.md` to recognise the new format. The two documents must stop contradicting each other. |
| M17 | `core_learning_frameworks` | `GAP` | § Lifecycle requires an "approved lifecycle action and audit actor" for restoring an archived Framework, but the table has only `created_by`/`updated_by` — no `archived_at`/`archived_by`. `core_learning_framework_versions` carries the full `published_*`/`deprecated_*`/`archived_*` set. | Add the archive audit fields or move the lifecycle statement to where it can be recorded. |
| M18 | `governance/LF-Glossary.md` | `GAP` | Naming Convention requires names to follow the Glossary. § Learning Terms still holds eight terms covering six of ten tables. Missing table terms: Node Relation, Node Mapping, Calculation Evidence, and Mastery Calculation as an entity distinct from Mastery. Missing policy terms: Continuity Policy, Qualification Rule, Basis Framework Version, Source Discriminator, Mastery Scale Snapshot. Missing terms created by remediation: Evidence Role, Calculation Source, Mapping Invalidation, Approved Physical Enforcement Strategy. | Extend § Learning Terms so every table and every governing policy concept has a canonical entry. |
| M19 | `core_learning_evidence` | `AMBIGUITY` | The business UNIQUE excludes `evidence_type`, so one source event that lawfully yields both `exposure` and `completion` for the same Node and learner collides unless the producer varies `producer_idempotency_key` — a requirement stated nowhere. The second row would be silently rejected. | Add `evidence_type` to the key or publish the producer key-construction contract. |
| M20 | `core_learning_evidence` | `AMBIGUITY` | `source_id` is documented as "Source event/result/snapshot identity" but redefined for `teacher_judgment` as the `recorded_by` user ID. Every lineage query must branch on `evidence_type`. After B5, Teacher Judgment is the only open source, so the exceptional branch is now the default one. `recorded_by` is nullable while `expert_judgment` requires it, with no CHECK (see m6). | Separate the two meanings, or state the overload explicitly as a contract with the required conditional constraints. |

## Open MINOR Findings — 10 open

| ID | Table / document | Finding | Change after remediation |
| --- | --- | --- | --- |
| m1 | `core_learning_frameworks` | § Purpose claims the table owns "version sequence allocation" but no column or mechanism exists; the concurrency-safe strategy for allocating `version_number` is unspecified. | none |
| m2 | `core_learning_frameworks` | `default_mastery_scale` is not marked NULL, so a Framework cannot be created without a complete two-level scale; state whether this is intended. | CHECK for valid non-empty JSON added; the NOT NULL implication is still unstated |
| m3 | `core_learning_nodes` | Has `created_by` and `updated_at` but no `updated_by`, unlike sibling tables. | none |
| m4 | `core_learning_node_mappings` | The `weight` range `0..1` appears only in the field description, with no CHECK in § Constraints And Indexes. | none |
| m5 | all ten table docs | No charset, collation, engine or row format is declared. The largest composite uniques (about 1.4 KB under utf8mb4) stay below the 3072-byte InnoDB `DYNAMIC` limit, but the assumption should be written down. | none |
| m6 | `core_learning_evidence` | No CHECK requiring `recorded_by IS NOT NULL` when `evidence_type = 'expert_judgment'`. | `trg_lrn_evidence_bi_validate` may cover it, but the contract does not say so |
| m7 | all ten table docs | No table has `deleted_at`. This is consistent with append-only, but should be stated as "no soft delete by design" so tooling and agents do not read it as an omission. | none |
| m8 | `core_learning_node_relations` | `approved_by`/`approved_at` are conditionally required with no corresponding CHECK. | none; now linked to N1 |
| m9 | `core_learning_node_relations` | `requires_review` has no place on the relation to record the review outcome. | none; now the direct cause of N1 |
| m10 | all ten table docs | `TIMESTAMP` precision is undeclared, which directly affects M9's ordering determinism. | none |

## Round 2 Gate Status

| Gate | Status |
| --- | --- |
| 1. Ten table contracts pass Database/Architecture Review | Not met — 17 MAJOR and 7 new findings open |
| 2. Composite-FK/CHECK/database-trigger strategy passes re-review | Largely met — key architecture verified; N3, N4, N7 outstanding |
| 3. `LF-SCHEMA-CONTRACT.json` matches the approved docs | Not met — M15 |
| 4. Foundation Freeze recorded | Not met |

Architecture Review Checklist movement since round 1: Section B — Data
Ownership moves from fail to pass; Sections C, D, E, F and G remain "needs
clarification"; Section H remains fail because Freeze is not owner-confirmed.

`docs:lint` and `schema:drift --docs-only` passing is accurate and useful, but
their scope is metadata and contract consistency. Neither detects semantic
contradictions such as M4, N1 or N5, so those passes are not evidence for
Freeze.

Suggested sequence: (1) governance only — N2, M4, N5; (2) one decision covering
N1, M6, m8, m9; (3) close the gaps in the new strategy — N3, N4 with M1 as
prerequisite, N7, M7; (4) complete the data contract — M2, M9, M10, M11, M12,
M13, M17, M19, M20 and all MINOR items; (5) contract and gate — M15, M16, M18,
then full checklist, Owner Approval and Freeze.

---

# Round 3 Remediation Submission

Date: 2026-08-12. Status: ready for independent re-review; not self-approved.

The Round 2 backlog has been implemented in documentation as follows:

| Findings | Remediation evidence |
| --- | --- |
| N2, M4 | ADR-0016 and LF-Core-Learning raised to 1.1; obsolete “docs do not exist” text removed. Amendment Owner approval is explicitly pending. |
| N5, M1, M2 | Mapping and Evidence whitelists named separately; source registry and initial Evidence keys enumerated. |
| N1, M6, m8, m9 | Relation owns the target Version, records pending/approved/rejected review and permits exactly one post-publish resolution. |
| N3 | Definition, Node and Relation identity columns are covered by named update triggers in draft and published states. |
| N4 | Cross-Domain lookup trigger removed; versioned Course adapter validation plus reconciliation is the explicit Generic Reference exception. |
| N7 | Tech Stack pins MySQL 8.0.16+ and MariaDB 10.5+ with deployment preflight. |
| M5, M7 | `retired` limited to draft; contradictory pair types forbidden; prerequisite/part-of cycle trigger specified. |
| M9–M13 | Calculation idempotency/order, linear Evidence correction, relation FK lineage, Framework-scoped version code and Profile status derivation specified. |
| M15 | All ten schema-contract entries now contain columns, keys, FKs, checks and all 24 planned triggers. |
| M16–M18 | All table docs now follow Relationships → Business Rules → Fields and include Sample Data; archive audit and Glossary terms added. |
| M19, M20 | Evidence unique includes `evidence_type`; Teacher Judgment source ID is a submission identity distinct from actor. |
| m1–m7, m10 | Version allocation, scale nullability, update actor, weight CHECK, storage baseline, judgment CHECK, no-soft-delete and microsecond precision documented. |

Round 3 follow-up strengthened schema drift: trigger identity is now captured
from MySQL metadata and Learning contract entries require `name`, `timing`,
`event` and `statement`. Legacy contracts without identity remain compatible.
The 24 Learning entries intentionally retain `PLANNED CONTRACT` bodies while
the tables are not implemented; replacing each with the normalized executable
body is a mandatory pre-implementation gate, not deferred design work.

The review verdict remained `BLOCKED` until the Round 4 independent re-review
and Architecture Owner decisions recorded below. No migration or runtime
implementation is authorized by Round 3 alone.

---

# Round 4 Independent Re-review And Freeze

Date: 2026-08-12

```text
Independent re-review verdict: PASS
Architecture Owner Amendment 1.1: APPROVED
Foundation Freeze: RECORDED
Phase 3: COMPLETE
```

Verification evidence:

* `docs:lint` passed;
* `schema:drift --docs-only` passed;
* Learning schema contract has no empty columns/indexes/FKs/checks/triggers;
* documentation/schema test set passed 40 tests and 46 assertions;
* `git diff --check` passed.

Round 1–3 blocked findings remain historical audit context. Their remediation
has passed the Round 4 independent re-review. The Foundation Freeze covers only
the database design contract; implementation remains `Not Implemented` and
requires a separate Phase 4 migration authorization.

---

# Freeze Confirmation And Phase 4 Handoff

Date: 2026-08-12

An additional independent Freeze check revalidated all 17 MAJOR, 10 MINOR and
7 N-findings against the frozen repository state. Result: no finding remains
open. It specifically confirmed the unified relation lifecycle, the conflict-
free relation unique key, 174 documented columns, 51 foreign keys, 30 CHECK
constraints, 24 trigger contracts and 21 Learning Glossary terms.

The former non-blocking ADR grammar defect and its stale Round 3 reference were
corrected on 2026-08-13 through the explicit Editorial Correction Record in
ADR-0016. The correction does not alter the Version 1.1 decision, Freeze scope
or separate Phase 4 authorization.

## Phase 4 Plan — Non-authorizing

Phase 4 is deliberately divided into separately authorized gates. Completing a
stage does not authorize its successor.

| Stage | Scope | Entry gate | Exit evidence |
| --- | --- | --- | --- |
| 4A | User prerequisite: composite `UNIQUE (id, customer_id)`. | Existing-Feature Regression Audit and explicit auth-boundary approval. | MariaDB migration rehearsal, index preflight, rollback rehearsal and tenant-auth regression PASS. |
| 4B | Structural design scope: ten `core_learning_*` tables, keys and CHECK constraints. No standalone implementation gate exists. | 4A PASS; continue only through the combined 4B/4C gate. | Evidence is accepted only with 4C in the combined gate. |
| 4C | Physical-enforcement design scope: the 24 executable trigger bodies. Implemented only with 4B. | Approved Trigger Specification and combined 4B/4C Owner authorization. | Fresh MariaDB schema; FK/CHECK/trigger negative tests; trigger identity/body and full schema drift PASS. |
| 4D | Learning runtime, authorization, projector and schema-drift contract. | 4B and 4C PASS; implementation authorization. | Tenant, authorization, idempotency, projection, drift and append-only regression audit PASS. |
| 4E | Teacher Judgment end-to-end flow. | 4D PASS; product/teacher workflow authorization. | Human judgment submission, Evidence, Calculation/Profile projection and audit lineage acceptance PASS. |

The 4C Trigger Specification is a new review artifact, outside the frozen table
docs. It must define each trigger’s precise reject conditions, `SIGNAL` SQLSTATE
and message, JSON-path handling for `continuity_policy_snapshot`, transaction
assumptions, and expected test cases before a trigger migration is written.

The MariaDB integration suite must contain negative tests for every enforced
invariant (estimated at about 105 assertions) and an explicit test that CHECK
constraints are enforced by the selected engine. The current CI baseline is
MariaDB 11.4; deployment preflight must still enforce the documented MySQL /
MariaDB version floor.

The former staging question is resolved: `schema:drift --fresh` does not provide
an accepted intermediate state for implemented Learning tables without their
required triggers. Consequently 4B and 4C are one combined implementation and
acceptance gate. They remain separate design scopes only. The gate must not be
weakened and the analyzer must not be modified merely to split this work.

## Phase 4 Mandatory Implementation Boundary

This section constrains every Phase 4 authorization. It does not authorize a
stage, expand ADR-0016 or activate a future integration. Phase 3 documentation
is complete and Learning Foundation Version 1.1 remains Frozen. Each Phase 4
stage still requires its own authorization and entry gate.

The complete Phase 4 scope is limited to:

1. **4A — User prerequisite:** the composite user tenant key required by
   Learning foreign keys.
2. **4B — Learning schema:** the ten documented `core_learning_*` tables,
   their keys and CHECK constraints.
3. **4C — Learning physical enforcement:** the approved Learning triggers and
   their executable negative-constraint verification.
4. **4D — Learning runtime:** Learning authorization, application services,
   projector and schema-drift contract.
5. **4E — Teacher Judgment:** the end-to-end human judgment flow through
   Evidence, Calculation, Profile projection and audit lineage.

### Phase 4 Non-goals And Guardrails

The following work is forbidden throughout Phase 4 unless a later, separately
approved architecture change explicitly opens it:

* AI must not read `core_learning_mastery_profiles`.
* No `ai_*` migration, model, service, RAG pipeline, knowledge chunk or
  embedding may be created or changed for Learning integration.
* Mastery Profile must not be registered in `ai_knowledge_sources`.
* Track-derived Evidence remains closed. Neither `track_events` nor
  `behavioral_signal` is an accepted Learning Evidence source.
* No `track_*` migration, model, event ingestion or projection may be created,
  changed or required by Learning Phase 4.
* AI has no write path to Learning Evidence, Calculation, Profile, Framework,
  Node or any other `core_learning_*` state.

These are stop conditions, not optional sequencing advice. A Phase 4 change
that needs AI or Track implementation exceeds its authorization and must stop
for a separate Architecture Review.

### Acceptance And Negative Verification

Phase 4C/4D verification must prove all of the following:

* `teacher_judgment` is the only open `source_type` at initial Phase 1
  activation.
* Attempts to create Learning Evidence with `source_type = 'track_events'` or
  `source_type = 'behavioral_signal'` are rejected at the physical and runtime
  boundaries. The token `track_events` is tested explicitly even though it is
  not a canonical Evidence source token, so an implementation cannot mistake a
  Track table name for an allowed discriminator.
* No AI principal, service or route has write authorization to any
  `core_learning_*` resource.
* No AI principal, service or route has read authorization to Mastery Profile.
  That read boundary remains closed until the proposed ADR-0006 Amendment is
  approved by the Architecture Owner and its separate implementation,
  authorization, privacy, tenant and regression gates all pass.

Phase 4E acceptance exercises Teacher Judgment only. Course-derived Evidence
remains subject to the immutable source-event review in ADR-0016, while
Assessment and Track sources remain closed at initial activation.

### Deferred Workstreams Outside Phase 4

**Track to Learning Evidence** may open only after Track is implemented and an
append-only event contract, Evidence qualification policy, database design and
independent Architecture Review are approved. It is not a dependency or
deliverable of Learning Phase 4.

**Learning Mastery Profile to AI** may open only after the ADR-0006 Amendment
is approved by the Architecture Owner, Learning is implemented, and a separate
AI authorization and implementation review passes. If opened, Mastery Profile
is a structured read-model input for Recommendation/Insight only. It is not a
Knowledge/RAG source and must not be registered, chunked or embedded.

The ADR-0006 Version 1.0 Foundation remains Frozen, but its Learning Integration
Amendment is Proposed and not effective. Track-derived Evidence remains a
future capability and closed at Learning initial activation.

---

# Phase 4 Authorization Record

This record persists the Architecture Owner authorization given for Phase 4A.
It is separate from the ADR-0016 Foundation Freeze and does not amend the
Frozen Learning database contract.

```text
Role: LearnForge Architecture Owner
Date: 2026-08-13
Decision: Approved Phase 4A — User composite-key prerequisite
Authorized scope: read-only preflight, HIGH regression/architecture audit,
                  forward migration and tests for
                  users UNIQUE (id, customer_id)
Excluded scope: Phase 4B, 4C, 4D, 4E; core_learning_* tables and triggers;
                Learning runtime; AI; Track; production deployment
```

Authorization is stage-scoped. It permits preparation and test-database
rehearsal of Phase 4A only. It does not permit executing the migration on the
real LearnForge database, and it does not authorize any successor stage.

The authorization was recorded after the Foundation Freeze and before Phase 4A
was accepted as complete. The technical artifact may exist in the working tree,
but it is not production deployment evidence.

---

# Phase 4B Readiness Review

Date: 2026-08-13

Mode: documentation and source inspection only. No migration, schema, database
or runtime change was authorized or performed by this review.

## Readiness Scope

The review checked the Frozen ten-table Learning contract, the schema contract,
foreign-key creation order, CHECK coverage, Phase 4A prerequisite, fresh-schema
drift behavior, negative-test requirements and test-database isolation.

## Confirmed Prerequisites

| Prerequisite | Evidence | Result |
| --- | --- | --- |
| Phase 3 contract is Frozen | ADR-0016 Version 1.1 and Round 4 Freeze | PASS |
| Ten canonical table documents exist | `docs/database/learning/` | PASS |
| Phase 4A user composite key exists in source and passed audit | `uk_users_id_customer` record and targeted tests | PASS |
| Contract contains all Learning structures | 174 columns, 51 FKs, 30 CHECKs and 24 triggers | PASS |
| AI and Track remain excluded | Phase 4 mandatory implementation boundary | PASS |
| Safe test-database lifecycle exists | `schema:drift --fresh` creates and drops a random `lf_schema_drift_*` database in `finally` | PASS |

The safe forward dependency order is:

1. `core_learning_frameworks`;
2. `core_learning_framework_versions` and
   `core_learning_node_definitions`;
3. `core_learning_nodes`;
4. `core_learning_node_relations`, `core_learning_node_mappings` and
   `core_learning_evidence`;
5. `core_learning_mastery_calculations`;
6. `core_learning_calculation_evidence`; and
7. `core_learning_mastery_profiles`.

Rollback must use the exact reverse order. It must not remove the Phase 4A User
composite key while a Learning foreign key still depends on it.

## Blocking Gate Decision — 4B And 4C

`schema:drift --fresh` has no phase-aware exception for intentionally absent
Learning triggers:

* if a Learning table remains `not_implemented` after its migration exists,
  schema drift reports a HIGH deferred-table conflict;
* if the table is changed to `implemented`, comparison includes its trigger
  contract and reports every absent trigger as HIGH `triggers.missing`; and
* all ten Learning contracts set `trigger_identity_required: true` and already
  declare the 24 trigger identities.

Therefore a standalone 4B migration cannot satisfy its documented fresh-schema
exit gate under the current contract. The gate must not be weakened and the
schema-drift analyzer must not be changed merely to conceal an intermediate
failure.

Decision: **4B and 4C must be implemented as one combined migration gate.**
This combines execution and acceptance only; it does not merge their design
responsibilities. The Trigger Specification review remains mandatory before
any combined migration is written.

Standalone 4B is not a pending option and cannot later become ready under the
current Frozen Migration Gate. Any future proposal to restore a standalone 4B
would require an explicit Foundation amendment and schema-drift design review;
changing the analyzer or lowering finding severity is not an acceptable bypass.

## Required Negative Verification

The combined gate must prepare MariaDB tests that prove:

* all tenant and parent composite FKs reject mismatched identities;
* every CHECK rejects invalid vocabulary, lifecycle and conditional-null rows;
* the selected MariaDB engine actually enforces CHECK constraints;
* all 24 trigger identities and normalized bodies match the contract;
* the trigger-specific negative matrix passes;
* `teacher_judgment` is the only initially open Evidence source and both
  `track_events` and `behavioral_signal` are rejected; and
* the fresh test database is uniquely named and removed in all success/failure
  paths.

## Authorization Still Required

The existing Owner decision authorizes Phase 4A only. Before implementation,
the Architecture Owner must approve both artifacts below:

1. the standalone Phase 4C Trigger Specification review; and
2. a combined **Phase 4B/4C implementation authorization** limited to the ten
   Learning tables, their keys/CHECKs and the 24 approved trigger bodies.

That authorization must continue to exclude the real LearnForge database,
production deployment, 4D runtime, 4E Teacher Judgment workflow, AI and Track.

## Readiness Verdict

`BLOCKED FOR STANDALONE 4B` — final under the current Frozen Migration Gate.
Trigger-free 4B cannot satisfy schema drift and is not an implementation path.

`PENDING FOR COMBINED 4B/4C` — proceed only after the Trigger Specification is
approved and the Architecture Owner records a combined implementation
authorization. No migration code is authorized by this readiness review.

## Combined Phase 4B/4C Implementation Authorization

```text
Role: LearnForge Architecture Owner
Date: 2026-08-13
Decision: Approved combined Phase 4B/4C implementation
Authorized scope: one combined implementation gate for the ten
                  core_learning_* tables; their columns, primary keys,
                  36 indexes, 51 foreign keys, 30 CHECK constraints and
                  24 independently accepted trigger bodies; disposable
                  MariaDB migration rehearsal, schema drift and regression tests
Excluded scope: real LearnForge database; production deployment; Phase 4D;
                Phase 4E; AI; Track; application runtime, API and UI
```

This authorization follows Phase 4C independent technical acceptance with
`0 BLOCKER · 0 MAJOR`. It does not authorize standalone 4B, execution against
the real LearnForge database, deployment or any successor phase.

---

# Phase 4A User Prerequisite Regression Audit

Date: 2026-08-13

## Classification

`Existing-Feature Change` — additive shared-schema prerequisite.

## Audit Level

Initial Audit Level: `HIGH`.

Final Audit Level: `HIGH`.

Audit Level Escalation: None.

Rationale: the change adds a database constraint/index to shared `users`,
touches the tenant ownership contract and enables future composite foreign keys
from Learning. It does not change User identity, the `users.id` primary key,
Auth behavior, tenant resolution, roles, routes, API or UI.

## Current And Requested Behavior

Before Phase 4A, `users.customer_id` was already `NOT NULL` and referenced
`saas_customers.id`, but no unique index represented `(id, customer_id)` as a
foreign-key target. Phase 4A adds exactly one named composite unique index:

```text
uk_users_id_customer UNIQUE (id, customer_id)
```

## Documents Reviewed

* `docs/LF-INDEX.md`
* `docs/README.md`
* `docs/governance/LF-Architecture-Guardrails.md`
* `docs/adr/ADR-0016-Learning-Foundation.md`
* `docs/core/LF-Core-Learning.md`
* `docs/core/LF-Core-User.md`
* `docs/core/LF-Core-Auth.md`
* `docs/saas/LF-SaaS-Tenant.md`
* `docs/database/learning/README.md`
* `docs/database/LF-SCHEMA-CONTRACT.json`
* `docs/database/LF-Schema-Drift.md`
* `docs/LF-Data-Modeling.md`
* `docs/LF-Development-Standards.md`
* `docs/prompts/LF-Implementation-Rules.md`
* `docs/quality/LF-Regression-Audit.md`
* `docs/governance/LF-Architecture-Review-Checklist.md`

## Source Of Truth

User/Auth owns user identity and tenant compatibility fields. SaaS Tenant owns
tenant identity. ADR-0016 and the frozen Learning database contract require
`users(id, customer_id)` as the composite parent key for future Learning
learner/actor foreign keys.

## Invariants

* `users.id` remains the sole primary key.
* `users.customer_id` remains `BIGINT UNSIGNED NOT NULL` and tenant-valid.
* No user data is deleted, rewritten or backfilled.
* No duplicate equivalent unique index is created.
* Down migration removes only the owned named index.
* No `core_learning_*` table, trigger or runtime component is created.
* Phase 4B remains separately authorized.

## Impact Analysis And Consumers

Direct consumers are the `users` schema and future Learning composite foreign
keys. Indirect consumers are User/Auth/Tenant flows and fresh-schema build/test
jobs. The additive key duplicates the already-unique `id` prefix only to make
tenant ownership physically referenceable; it does not introduce a second
business identity.

## Architecture Review Checklist Section B

Section B — Data Ownership and Tenant Isolation — was applied explicitly
because Phase 4A changes the shared User tenant-reference contract.

| Section B control | Evidence | Result |
| --- | --- | --- |
| Source of truth remains with User/Tenant | User retains identity; SaaS Tenant retains tenant identity | PASS |
| Every user remains tenant-owned | `users.customer_id` is `NOT NULL` and tenant-valid | PASS |
| Cross-tenant references fail closed | Future consumers must use `(user_id, customer_id)` composite FK | PASS |
| No global-user fallback is introduced | `users.id` remains the sole PK; tenant key is additive | PASS |
| Existing tenant integrity is preserved | Null and orphan preflight both returned zero | PASS |
| No duplicate equivalent key is created | Existing-index inspection and idempotent migration test | PASS |
| Rollback is ownership-scoped | Down removes only `uk_users_id_customer` after exact verification | PASS |
| Real database write boundary is preserved | Real schema inspection was read-only; rehearsal used disposable test DB | PASS |

Section B verdict: `PASS`. No tenant/auth ownership finding remains open for
Phase 4A. This result does not review or authorize Learning tables in Phase 4B.

## Preflight Evidence

Read-only inspection of configured local MySQL database `learnforge_db`:

| Check | Result |
| --- | --- |
| `users` and `customer_id` exist | PASS |
| `id` and `customer_id` types | `BIGINT UNSIGNED NOT NULL` |
| `users.customer_id IS NULL` | `0` |
| orphan `users.customer_id` | `0` |
| FK to `saas_customers(id)` | Present |
| equivalent `(id, customer_id)` unique | Absent before migration |

The real database was inspected read-only and was not migrated.

## Files Changed

* `database/migrations/2026_08_13_000000_add_user_tenant_composite_unique.php`
* `tests/Feature/UserTenantCompositeKeyMigrationTest.php`
* `docs/core/LF-Core-User.md`
* this review record
* `docs/LF-INDEX.md`

New Files: the migration and targeted test above.

## Implementation Summary

The forward migration performs fail-closed schema/data/tenant preflight,
returns when an equivalent unique already exists, rejects a conflicting named
index, then creates `uk_users_id_customer`. MySQL/MariaDB uses
`ALGORITHM=INPLACE, LOCK=NONE`. Down verifies exact name, columns and uniqueness
before dropping only that index.

## Tests Added Or Updated

`UserTenantCompositeKeyMigrationTest` verifies fresh reconstruction, exact
index identity, cross-tenant composite-FK rejection, idempotent `up()`, scoped
`down()`, nullable ownership rejection and orphan tenant rejection.

## Commands And Results

```text
Read-only real-database preflight                         PASS
Baseline User/Auth/Tenant: 23 tests / 299 assertions    PASS
SQLite Phase 4A: 5 tests / 12 assertions                PASS
MariaDB fresh migration                                  PASS
MariaDB rollback and re-migration                        PASS
MariaDB Phase 4A + tenant auth: 13 tests / 54 assertions PASS
Full PHPUnit: 702 passed / 1 skipped / 8108 assertions  PASS
docs:lint                                                PASS
schema:drift --docs-only                                 PASS
Scoped Pint                                              PASS
Repository-wide Pint                                     FAIL — pre-existing debt
git diff --check                                         PASS
```

The MariaDB rehearsal used only
`lf_schema_drift_phase4a_20260813`. Post-run inspection confirmed no database
matching `lf_schema_drift_%` remained.

Frontend build and browser QA are not applicable because Phase 4A changes no
frontend asset, route, request, response or visible behavior.

## Requirement-To-Test Traceability

| Requirement | Evidence |
| --- | --- |
| Exact composite unique exists | fresh-schema and MariaDB index inspection tests |
| Cross-tenant user reference cannot be represented | composite FK negative test |
| No duplicate index | idempotent `up()` test and preflight inspection |
| Safe rollback | SQLite and MariaDB rollback rehearsal |
| Null/orphan data blocks migration | negative preflight tests |
| Auth and tenant isolation unchanged | baseline and MariaDB tenant-auth tests |
| Real database remains untouched | separate read-only inspection and test DB cleanup evidence |

## Unverified Items

None within Phase 4A. Repository-wide formatting remains an external quality
debt classified below.

## Remaining Risks

Production execution can still experience engine-specific DDL scheduling even
with `LOCK=NONE`; deployment must monitor the online DDL and retain the
fail-closed preflight. Repository-wide Pint still reports seven pre-existing
violations in unrelated Course migrations/controllers, a shared video service
and its unit test. The changed Phase 4A migration and test pass scoped Pint;
the unrelated files were not modified because that would exceed Phase 4A.
Phase 4B cannot begin without separate authorization.

## Findings By Severity

```text
BLOCKER 0
HIGH    0
MEDIUM  0
LOW     1 — pre-existing repository formatter debt outside Phase 4A
```

## Final Verdict

`PASS WITH DOCUMENTED RISKS` — Phase 4A implementation and its schema, tenant,
rollback and migration gates pass. The only recorded risk is unrelated existing
formatter debt. This verdict authorizes no Phase 4B migration, Learning table,
trigger or runtime implementation.
