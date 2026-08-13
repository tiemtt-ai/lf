# Learning Database Documentation

Version: 1.0

Document Status: Frozen

Implementation Status: Not Implemented

Last Updated: 2026-08-12

Document Path: database/learning/README.md

---

# Purpose

Defines the physical database contract proposed for Learning Foundation v1
under [ADR-0016](../../adr/ADR-0016-Learning-Foundation.md) and
[LF-Core-Learning](../../core/LF-Core-Learning.md).

Learning is the source of truth for Framework semantics, qualified Evidence
and Mastery. Every table is tenant-owned and uses the `core_learning_` prefix.

# Tables

| Table | Responsibility |
| --- | --- |
| [core_learning_frameworks](core_learning_frameworks.md) | Stable identity and tenant authoring defaults for one Learning Framework. |
| [core_learning_framework_versions](core_learning_framework_versions.md) | Immutable published Framework snapshot and frozen mastery scale. |
| [core_learning_node_definitions](core_learning_node_definitions.md) | Stable semantic Node identity within one Framework. |
| [core_learning_nodes](core_learning_nodes.md) | Versioned Node snapshot within one Framework Version. |
| [core_learning_node_relations](core_learning_node_relations.md) | Intra-version semantics and same-Framework version transitions. |
| [core_learning_node_mappings](core_learning_node_mappings.md) | Whitelist-only mapping from immutable source objects to versioned Nodes. |
| [core_learning_evidence](core_learning_evidence.md) | Append-only qualified learning evidence and immutable source lineage. |
| [core_learning_mastery_calculations](core_learning_mastery_calculations.md) | Append-only mastery calculation, override and carry-forward decisions. |
| [core_learning_calculation_evidence](core_learning_calculation_evidence.md) | Exact Evidence set, weight and contribution used by a Calculation. |
| [core_learning_mastery_profiles](core_learning_mastery_profiles.md) | Rebuildable current-state projection per user, stable Node and basis Version. |

# Relationship Map

```text
Framework
├── Framework Versions ──┬── Versioned Nodes ── Node Relations
└── Stable Definitions ──┘           ├── Source Mappings
                                     └── Evidence

Stable Definition + Basis Version
└── Mastery Calculations ── Calculation Evidence ── Evidence
    └── Mastery Profile projection
```

# Foundation Constraints

* Every table has `customer_id NOT NULL`; every parent lookup is tenant-scoped.
* Published Framework Versions and their Nodes/Relations are immutable.
* Evidence and Calculations are append-only. Corrections and overrides create
  new rows linked to the superseded row.
* Profiles are projections, not historical authority, and can be rebuilt.
* Generic source references have no polymorphic hard FK. The Mapping whitelist
  and Evidence whitelist are separate contracts defined below.
* Cross-framework Node relations are forbidden in v1.
* No automatic Evidence expiry or Mastery decay exists. Explicit validity and
  reassessment are allowed only when frozen by an approved qualification rule;
  expiry affects eligibility for a new Calculation and never rewrites history.
* JSON snapshots are immutable audit payloads, not substitutes for keys,
  lifecycle fields or frequently queried canonical state.

# Approved Physical Enforcement Strategy

## Storage Baseline

All ten tables use InnoDB, `utf8mb4`, `utf8mb4_unicode_ci` and `DYNAMIC` row
format. Temporal business/audit columns use `TIMESTAMP(6)`. Composite indexes
are designed below the 3072-byte InnoDB `DYNAMIC` key limit. There is no
`deleted_at` on any Foundation table by design: stable/history tables use
archive, invalidation or append-only correction; projection rebuild is the only
documented physical deletion workflow.

In each Fields table, a type containing `NULL` is nullable; every other field is
`NOT NULL`. IDs are `BIGINT UNSIGNED`; `id` is auto-increment primary key.

Foundation v1 uses one deterministic hierarchy:

1. composite foreign keys enforce tenant, Framework, Version and learner
   identity whenever the required columns can be stored;
2. MySQL/MariaDB `CHECK` constraints enforce row-local vocabulary, interval and
   conditional-null rules;
3. named `BEFORE UPDATE` / `BEFORE DELETE` database triggers enforce
   append-only and post-publish immutability where a foreign key or CHECK cannot.

Application service validation is defense in depth only. It is never
the sole enforcement of a Foundation invariant.

## User Tenant-Key Prerequisite

Learning never references a global user. Before any Learning migration, the
shared `users` contract must provide `UNIQUE (id, customer_id)`. The canonical
ownership migration already makes `users.customer_id` non-null; deployment
preflight must verify both properties before Learning foreign keys are enabled.
Learning uses composite `(user_id, customer_id)` learner keys. Each table doc
enumerates its real actor columns (`created_by`, `approved_by`, `recorded_by`,
and others); `actor_id` is not a physical placeholder column.

The prerequisite is an approved, separate User-domain migration plan, executed
before any Learning table migration:

1. query and fail if any `users.customer_id` is null;
2. create `UNIQUE (id, customer_id)` using a named index;
3. verify the index and tenant FK by schema inspection; and
4. only then create Learning composite user foreign keys.

The down/rollback plan drops only the composite index after all dependent
Learning foreign keys have been removed. This prerequisite is not authorized by
this document and is not a substitute for Foundation Freeze.

## Phase 1 Evidence Source Gate

Two distinct whitelists apply:

| Contract | Open keys at initial activation |
| --- | --- |
| Node Mapping source objects | `course_version_lesson`, `course_version_activity` |
| Evidence source events | `teacher_judgment` only |

Mutable Course projections such as `core_course_activity_progress` may be read
as qualification input but cannot be stored as immutable Evidence lineage.
Teacher Judgment is the only source open at initial Foundation activation.
Course-derived Evidence opens only after an append-only event contract is
implemented and passes Database/Architecture Review. `track_events` is a
candidate contract but is currently `not_implemented`, so no dependency on it
is claimed by these ten tables.

## Trigger Contract

The later migration must create these database triggers exactly; equivalent
application hooks do not satisfy the contract:

| Table | Trigger | Rule |
| --- | --- | --- |
| `core_learning_frameworks` | `trg_lrn_frameworks_bi_scale`, `trg_lrn_frameworks_bu_scale` | Validate ordered mastery-scale structure. |
| `core_learning_framework_versions` | `trg_lrn_fw_versions_bi_validate`, `trg_lrn_fw_versions_bu_immutable`, `trg_lrn_fw_versions_bd_immutable` | Validate lifecycle/scale; freeze snapshot after publish; forbid published delete. |
| `core_learning_nodes` | `trg_lrn_nodes_bu_immutable`, `trg_lrn_nodes_bd_immutable` | Freeze/delete-protect Nodes once the Version is published. |
| `core_learning_node_relations` | `trg_lrn_relations_bi_validate`, `trg_lrn_relations_bu_immutable`, `trg_lrn_relations_bd_immutable` | Reject graph cycles; freeze published semantics; permit one pending-review resolution; forbid delete. |
| `core_learning_node_definitions` | `trg_lrn_definitions_bu_identity` | Reject changes to `customer_id` or `framework_id`. |
| `core_learning_node_mappings` | `trg_lrn_mappings_bu_lifecycle`, `trg_lrn_mappings_bd_immutable` | Permit only one audited invalidation transition; forbid delete. |
| `core_learning_evidence` | `trg_lrn_evidence_bi_validate`, `trg_lrn_evidence_bu_immutable`, `trg_lrn_evidence_bd_immutable` | Validate source/qualification/correction; reject every update/delete. |
| `core_learning_mastery_calculations` | `trg_lrn_calcs_bi_validate`, `trg_lrn_calcs_bu_immutable`, `trg_lrn_calcs_bd_immutable` | Validate source/rule/scale; reject every update/delete. |
| `core_learning_calculation_evidence` | `trg_lrn_calc_evidence_bi_validate`, `trg_lrn_calc_evidence_bu_immutable`, `trg_lrn_calc_evidence_bd_immutable` | Validate direct/transition lineage; reject every update/delete. |
| `core_learning_mastery_profiles` | `trg_lrn_profiles_bi_projection`, `trg_lrn_profiles_bu_projection` | Validate exact Calculation projection identity and values. |

Frameworks and Definitions remain authoring aggregates under their documented
lifecycle. Mastery Profiles are intentionally mutable projections, but every
write is database-validated. Cross-table identity uses composite foreign keys;
triggers cover only invariants that cannot be represented by keys or CHECK.

`LF-SCHEMA-CONTRACT.json` already inventories every required trigger by name,
timing and event. Its `PLANNED CONTRACT` statement text is deliberately not
executable SQL while this Foundation remains `Not Implemented`. Before changing
any Learning table to `implemented`, the approved migration must replace every
placeholder with the normalized, exact trigger body; schema-drift then verifies
both trigger identity and statement. A placeholder is therefore a pre-migration
gate, never evidence that a trigger has been implemented.

Generic Mapping source existence is the documented exception: a database
trigger must not query another Domain's physical tables. The owning Course
adapter validates the registry below and submits a frozen source snapshot;
Learning revalidates the versioned adapter payload transactionally and runs a
reconciliation job for missing sources. This is an intentional Generic
Reference boundary, not a tenant/Framework integrity exception.

## Course Adapter Contract

The trusted internal Course adapter is the only writer of Mapping source
lineage. Before Learning persists a Mapping it must supply a versioned payload:

| Field | Rule |
| --- | --- |
| `adapter_contract_version` | Exact supported contract version; unknown versions fail closed. |
| `customer_id` | Equals Mapping tenant. |
| `source_type`, `source_id`, `source_discriminator` | Must match the Mapping source registry exactly. |
| `source_snapshot` | Immutable source label, source table identity, published Version ID and source creation timestamp. |
| `producer_idempotency_key` | Stable per source object plus discriminator; retries cannot produce a different snapshot. |
| `published_at` | Must be non-null; working Template objects are rejected. |

The adapter executes in the application transaction and is authorized only by
the Course-to-Learning service boundary; browser clients cannot submit this
payload. Course retains the immutable source/event record for as long as a
Mapping or Evidence references it. If reconciliation finds a missing or
mismatched source, Learning blocks new qualification from that Mapping, records
an operational lineage incident and preserves historical lineage unchanged.

# Review And Migration Gate

These documents are Frozen as the approved Phase 3 database contract. Migration
remains blocked until:

1. a separate Phase 4 migration authorization is recorded;
2. the User composite-key prerequisite migration plan is approved and executed;
3. each planned trigger body is replaced by the approved normalized SQL body;
4. implementation-time schema drift and regression gates pass.

No document in this directory authorizes migration by itself.
