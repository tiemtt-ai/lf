# Table: core_learning_framework_versions

Version: 1.1

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-09-15

Document Path: database/learning/core_learning_framework_versions.md

## Purpose

One authorable snapshot of a Framework. A published row is the immutable basis
for versioned Nodes, Evidence qualification and Mastery calculations.

## Relationships

```text
core_learning_frameworks 1 → N core_learning_framework_versions
core_learning_framework_versions 1 → N core_learning_nodes
users 1 → N core_learning_framework_versions (lifecycle actors)
```

## Business Rules

A Version is authored only under its Framework. Publish freezes the Version,
its Node graph and scale in one transaction; lifecycle transitions are one-way.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `framework_id` | BIGINT UNSIGNED | Parent stable Framework. |
| `version_number` | INT UNSIGNED | Monotonic sequence inside Framework. |
| `version_code` | VARCHAR(100) | Tenant-unique stable code. |
| `title_snapshot` | VARCHAR(255) | Version title frozen at publish. |
| `description_snapshot` | TEXT NULL | Version description frozen at publish. |
| `mastery_scale_key` | VARCHAR(100) | Frozen scale policy key. |
| `mastery_scale_version` | VARCHAR(50) | Frozen scale policy version. |
| `mastery_scale_snapshot` | JSON | Complete ordered scale and thresholds. |
| `status` | VARCHAR(50) | `draft_snapshot`, `published`, `deprecated`, `archived`. |
| `published_at` | TIMESTAMP(6) NULL | Publish time. |
| `deprecated_at` | TIMESTAMP(6) NULL | Deprecation time. |
| `archived_at` | TIMESTAMP(6) NULL | Archive time. |
| `published_by` | BIGINT UNSIGNED NULL | Publish actor. |
| `deprecated_by` | BIGINT UNSIGNED NULL | Deprecation actor. |
| `archived_by` | BIGINT UNSIGNED NULL | Archive actor. |
| `created_by` | BIGINT UNSIGNED | Draft creator. |
| `updated_by` | BIGINT UNSIGNED | Last draft editor. |
| `created_at` | TIMESTAMP(6) NULL | Creation time. |
| `updated_at` | TIMESTAMP(6) NULL | Draft update time; not permission to mutate published payload. |

## Constraints And Indexes

* `UNIQUE (customer_id, framework_id, version_number)`.
* `UNIQUE (customer_id, framework_id, version_code)`; common codes such as
  `v1` may be reused by different Frameworks in one tenant.
* `UNIQUE (id, customer_id, framework_id)` supports composite integrity without
  creating a new business identity.
* `INDEX (customer_id, framework_id, status, published_at)`.
* Framework FK uses `(framework_id, customer_id)`. Each of
  `(published_by, customer_id)`, `(deprecated_by, customer_id)`,
  `(archived_by, customer_id)`, `(created_by, customer_id)` and
  `(updated_by, customer_id)` references `users(id, customer_id)` with
  `RESTRICT`; nullable lifecycle actor columns remain nullable as pairs.
* Status/timestamp/actor consistency is enforced by CHECK where supported and
  by `trg_lrn_fw_versions_bi_validate` /
  `trg_lrn_fw_versions_bu_immutable`. Insert validation includes the complete
  mastery-scale structure; application lifecycle validation is defense in depth.

## Lifecycle And Immutability

Only `draft_snapshot → published → deprecated → archived` is allowed.
Transitions are irreversible. Publish freezes all snapshot fields and the
complete Node/semantic-relation graph in one transaction. Deprecated Versions
remain valid historical bases but are not selected by default for new work.
Archived Versions are read-only and unavailable to new operational writes.

`BEFORE UPDATE` rejects changes to snapshot/business fields after publication;
only the irreversible lifecycle status/timestamp/actor transition is allowed.
`BEFORE DELETE` rejects deletion after publication. These database triggers are
the approved physical immutability mechanism.

Current basis is resolved from Course/Product/Enrollment context or an
approved tenant policy. It is never inferred from the latest timestamp.

## Step 7 inherited-draft extension — Frozen / Not Implemented

### Owner design closure — 2026-09-15

Owner decision: "chốt tài liệu, ko cần reivew quá nhiều". The current Step 7
contract v0.8, six AI table designs and their Course/Learning/ADR design
extensions are approved and Frozen. No additional design-review round is
scheduled by this closure. Earlier Review/pending-Freeze statements below are
historical and superseded for design status only. Implementation remains Not
Implemented. This records Owner approval, NOT reviewer PASS, physical DDL
verification, migration execution or live database/provider authorization.



Owner approved the inherited-draft direction on 2026-09-14, after the Step 7
design review. Existing schema/runtime above remains implemented; this new owner
command is not. See [Step 7 contract](../../platform/LF-AI-Authoring-Proposal-Contract.md).

The command is explicit and admin-only, initiated by an accepted Step 7 proposal.
It copies an exact published Version's active Nodes with stable Definition IDs
into a new draft, including remapped same-Version semantic relations. No old
Node/Version is modified and no transition/carry-forward or Mapping is created.
Retired Nodes and Nodes with inactive Definitions are excluded with incident
relations in a previewed, admin-acknowledged exclusion plan. Missing endpoints
are integrity errors and still block copy. Never reactivate a Definition or
silently lose a dependency; admin accepts exclusions or cancels. No eligible
Node remaining returns proposal_inheritance_empty. Node snapshot content and scale come from the base snapshot, not
mutable current Definition/Framework defaults. Graph and new Version commit in
one transaction with the AI inherit_draft review receipt; UUID replay uses that
receipt and never creates another Version. Empty createDraftVersion is unchanged.

No new physical Version columns are proposed. Source/result identity and command
hash live in the immutable AI decision; Learning alone performs the graph write.
Backend tests must prove duplicate requests, rollback of partial copies, correct
Definition identity and independent old/new Node IDs, with no historical mutation.

## Sample Data

`id=20, customer_id=1, framework_id=10, version_number=1,
version_code=v1, status=published, mastery_scale_key=cefr`
