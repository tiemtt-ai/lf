# Table: core_learning_evidence

Version: 1.0

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-08-22

Document Path: database/learning/core_learning_evidence.md

## Purpose

Append-only qualified evidence that one user demonstrated, encountered or was
judged against one versioned Node. It freezes source lineage and the rule that
qualified the source signal as Learning Evidence.

## Relationships

```text
users 1 → N core_learning_evidence (learner/recorder)
core_learning_nodes 1 → N core_learning_evidence
core_learning_evidence 1 → 0..1 correction successor
```

## Business Rules

Evidence is insert-only qualified lineage. Source type and Evidence type are
separate vocabularies; correction creates a linear successor without rewriting
the original.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `user_id` | BIGINT UNSIGNED | Learner entity; role context does not replace the canonical user FK. |
| `learning_node_id` | BIGINT UNSIGNED | Exact versioned semantic anchor. |
| `evidence_type` | VARCHAR(50) | `exposure`, `completion`, `evaluation`, `expert_judgment`, or approved future `behavioral_signal`. |
| `source_type` | VARCHAR(100) | Versioned whitelist key. |
| `source_id` | BIGINT UNSIGNED | Immutable producer submission/event identity; never an actor ID. |
| `source_discriminator` | VARCHAR(191) | Required immutable event/result discriminator or normalized `-`. |
| `producer_idempotency_key` | VARCHAR(191) | Stable producer key preventing duplicate delivery. |
| `source_occurred_at` | DATETIME(6) | When the source event occurred. |
| `evaluated_at` | DATETIME(6) | When it was qualified as Evidence. |
| `value_numeric` | DECIMAL(18,6) NULL | Optional normalized numeric observation. |
| `value_label` | VARCHAR(100) NULL | Optional categorical observation. |
| `qualification_rule_key` | VARCHAR(100) | Frozen rule key. |
| `qualification_rule_version` | VARCHAR(50) | Frozen rule version. |
| `qualification_rule_snapshot` | JSON | Complete rule and source interpretation. |
| `valid_from` | TIMESTAMP(6) NULL | Explicit rule-derived validity start. |
| `valid_until` | TIMESTAMP(6) NULL | Explicit rule-derived validity end; never inferred from age. |
| `reassessment_due_at` | TIMESTAMP(6) NULL | Advisory reassessment time. |
| `supersedes_evidence_id` | BIGINT UNSIGNED NULL | Prior Evidence corrected by this row. |
| `recorded_by` | BIGINT UNSIGNED NULL | Human actor; null for an authorized system producer. |
| `created_at` | TIMESTAMP(6) NULL | Append time. No `updated_at` business mutation contract. |

## Evidence Validity Decision — E2

Foundation v1 permits explicit validity only when the qualification rule
snapshot declares it deterministically. Null `valid_until` means no declared
expiry, not indefinite proof of current mastery. Time passage alone never
changes an Evidence row or Profile. Expired Evidence remains historical and is
excluded or discounted only by a new Calculation whose frozen rule says so.
`reassessment_due_at` is advisory workflow data and never performs decay.

## Initial Evidence Source Whitelist

```text
teacher_judgment
```

`source_type` identifies the producer contract. `evidence_type` identifies the
meaning of the resulting Evidence; therefore `teacher_judgment` lawfully
produces `expert_judgment` and the two token spaces are intentionally distinct.
Course/Assessment/Track source keys are closed until their append-only event
contracts pass review.

## Constraints And Indexes

* `UNIQUE (customer_id, source_type, producer_idempotency_key,
  learning_node_id, user_id, evidence_type)`; producer keys are stable per
  semantic Evidence output, so one source may lawfully yield exposure and
  completion without collision.
* `UNIQUE (id, customer_id, user_id)` supports Calculation Evidence lineage.
* `UNIQUE (customer_id, supersedes_evidence_id)` keeps correction chains linear;
  null originals may repeat under MySQL/MariaDB unique-null semantics.
* `INDEX (customer_id, user_id, learning_node_id, evaluated_at)`.
* `INDEX (customer_id, valid_until, reassessment_due_at)`.
* FK `(user_id, customer_id)` and nullable actor FK
  `(recorded_by, customer_id)` reference `users(id, customer_id)` with
  `RESTRICT`; a Learning learner/actor must be tenant-bound.
* FK `(learning_node_id, customer_id)` references the Node tenant key;
  supersession uses `(supersedes_evidence_id, customer_id, user_id)` so a
  correction cannot cross tenant or learner.
* At least one of `value_numeric` or `value_label` is required.
* CHECK requires `recorded_by IS NOT NULL` for `expert_judgment` and requires
  `source_type = teacher_judgment` while that is the only open source.
* `valid_from <= valid_until` and `evaluated_at >= source_occurred_at` unless
  the qualification snapshot explicitly records an approved delayed-source
  exception.
* `trg_lrn_evidence_bi_validate` validates the open source whitelist, immutable
  source identity, qualification rule snapshot and correction-chain rules.

## Append-Only And Source Boundary

Correction creates a new row referencing the old row. No cascade deletion is
allowed. `core_course_activity_progress` and other mutable Course projections
may be qualification inputs but are never Evidence source identities. Course-
derived Evidence remains operationally closed until an append-only source event
contract (for example implemented `track_events`) passes its own physical
review. Assessment and Track-derived sources remain closed under the same gate.

For `teacher_judgment`, `source_id` is the immutable numeric submission ID,
`source_discriminator` is its UUID/idempotency discriminator, `recorded_by` is
the authorized human actor, and the complete judgment is frozen in the
qualification snapshot. Archiving a user does not invalidate attribution.

Currently effective Evidence is resolved with `NOT EXISTS` against
`supersedes_evidence_id`; the unique correction key guarantees at most one
direct successor. Historical queries never hide superseded rows.

`BEFORE UPDATE` and `BEFORE DELETE` database triggers reject every mutation of
an Evidence row. Correction is insert-only. This is the approved physical
append-only mechanism for Foundation v1.

## Sample Data

`id=70, customer_id=1, user_id=100, learning_node_id=40,
evidence_type=expert_judgment, source_type=teacher_judgment,
source_id=5001, source_discriminator=judgment-5001,
producer_idempotency_key=teacher-100-5001`
