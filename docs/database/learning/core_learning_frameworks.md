# Table: core_learning_frameworks

Version: 1.0

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-08-22

Document Path: database/learning/core_learning_frameworks.md

## Purpose

Stable tenant-owned identity of one competency or learning-semantics framework.
It owns stable Node Definitions and version sequence allocation. The tenant
default mastery scale is authoring input only; each published Version freezes
its own snapshot.

## Relationships

```text
saas_customers 1 → N core_learning_frameworks
core_learning_frameworks 1 → N core_learning_framework_versions
core_learning_frameworks 1 → N core_learning_node_definitions
users 1 → N core_learning_frameworks (author/archive actors)
```

## Business Rules

Framework is the stable tenant-owned aggregate. It supplies authoring defaults,
allocates Version numbers transactionally and never replaces published history.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `code` | VARCHAR(100) | Stable tenant-unique business code. |
| `name` | VARCHAR(255) | Display name. |
| `description` | TEXT NULL | Authoring description. |
| `default_mastery_scale_key` | VARCHAR(100) | Versioned policy key used to prefill a draft Version. |
| `default_mastery_scale_version` | VARCHAR(50) | Policy version paired with the key. |
| `default_mastery_scale` | JSON | Validated ordered levels and thresholds; authoring-only after Version creation. |
| `status` | VARCHAR(50) | `active` or `archived`. |
| `archived_at` | TIMESTAMP(6) NULL | Archive audit time. |
| `archived_by` | BIGINT UNSIGNED NULL | Tenant actor performing archive. |
| `created_by` | BIGINT UNSIGNED | Tenant creator. |
| `updated_by` | BIGINT UNSIGNED | Last tenant editor. |
| `created_at` | TIMESTAMP(6) NULL | Creation time. |
| `updated_at` | TIMESTAMP(6) NULL | Update time. |

## Constraints And Indexes

* `UNIQUE (customer_id, code)`.
* `UNIQUE (id, customer_id)` supports tenant-safe Version and Definition FKs.
* `INDEX (customer_id, status, name)`.
* FK `customer_id → saas_customers.id`; `(created_by, customer_id)`,
  `(updated_by, customer_id)` and nullable `(archived_by, customer_id)` each
  reference `users(id, customer_id)`, all with `RESTRICT`.
* The scale JSON must have at least two uniquely keyed, strictly ordered levels
  and deterministic numeric thresholds. CHECK enforces valid/non-empty JSON;
  `trg_lrn_frameworks_bi_scale` and `trg_lrn_frameworks_bu_scale` validate the
  ordered-level contract before storage.
* `default_mastery_scale` is intentionally NOT NULL: a Framework cannot enter
  authoring without a valid scale. `status = archived` requires both archive
  audit fields; active requires both null.

## Lifecycle And Delete Rules

Archiving blocks new draft Versions but preserves all published history.
Frameworks referenced by any Version, Definition, Evidence basis or Profile
cannot be hard-deleted. Restoring an archived Framework requires an approved
lifecycle action and audit actor; it never restores an archived Version.

Version-number allocation locks the parent Framework row with `SELECT ... FOR
UPDATE`, reads the maximum committed number and inserts the next number in the
same transaction. The Version unique key is the final concurrency guard.

## Sample Data

`id=10, customer_id=1, code=english-b2, name=English B2, status=active,
default_mastery_scale_key=cefr, default_mastery_scale_version=1`
