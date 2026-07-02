# LearnForge Implementation Rules

Version: 1.1

Status: Official Implementation Rule

---

# 1. Purpose

This document is the single source of truth for implementing database-backed
features and CRUD modules in LearnForge.

It applies to Admin and Teacher management modules unless a higher-priority
Governance document or ADR explicitly overrides it.

---

# 2. Reading Strategy

AI agents should read only the documents required for the current task.

Normal reading order:

1. `docs/LF-INDEX.md`
2. `docs/prompts/LF-Implementation-Rules.md`
3. `docs/database/<domain>/README.md`
4. `docs/database/<domain>/<table>.md`

For repeated work in the same domain, reuse the most recent completed module as
the implementation pattern.

Do not re-read unnecessary global documents unless a conflict appears or
`docs/LF-INDEX.md` routes the task to additional documentation.

For large CRUD work, do not reload the whole project for every table.

---

# 3. Development Principles

## Documentation First

Read the current repository files. Never rely on memory from previous tasks.

## Database First

Database and domain documentation define fields, relationships, indexes,
statuses, and business rules.

## Keep It Simple

Reuse the existing Laravel, Blade, CSS, route, middleware, and test patterns.
Do not create unnecessary architecture.

## Reuse Before Creating

Before creating new code, search for the closest existing implementation.

Prefer reusing:

* Controller structure
* Route structure
* Blade layout
* Form components
* Table components
* Validation pattern
* Tenant isolation pattern
* Feature test pattern

Do not duplicate code that already exists.

## Batch CRUD Implementation

When multiple tables belong to the same business module, do not implement one
table at a time unless explicitly requested.

Preferred workflow:

1. Build one Golden CRUD.
2. Validate the Golden CRUD.
3. Reuse it for remaining tables.
4. Create or update a reusable CRUD Generator.
5. Process related tables in batches of 5–10.
6. Stop after completing the requested batch.

Never continue automatically to unrelated tables.

## No Eloquent Models

Do not create or use Eloquent Models for CRUD modules. Use `DB::table()`.

## Tenant First

Business data and every related query must be scoped to the current
`customer_id` from `TenantContext::customerId()`.

## Conflict Rule

If documentation, Guardrails, an ADR, or current code conflict, stop and report
the conflict. Do not silently choose one.

---

# 4. Database Rules

## Documentation Is The Source Of Truth

Implement each table from its exact table documentation.

## No Undocumented Fields

Do not add, rename, or remove fields, relationships, indexes, statuses, or
business rules unless the documentation requires it.

## Relationships

Respect documented relationships. Validate that parent and child records belong
to the same tenant.

## Migration Rules

* Do not modify old production migrations.
* If the table already exists, do not create a duplicate migration.
* Report an existing table and create only documented additive migrations when
  required.
* Implement documented field types, defaults, indexes, and constraints.

## Delete Rules

* Never cascade delete unless documentation explicitly requires it.
* Check existing child and reference records before deleting.
* Block deletion when references exist.
* Prefer a documented inactive or archived status for reusable master data.

## Direct Database Access

Use:

```php
DB::table('table_name')
```

Do not use `Model::query()`, `Model::create()`, or `Model::find()`.

---

# 5. CRUD Rules

## Standard CRUD Scope

A normal CRUD module includes:

* List, search, and filter
* Create and store
* Edit and update
* Delete or status change
* Validation
* Tenant isolation
* Feature tests

## Golden CRUD

For repeated CRUD work, first create one Golden CRUD module.

The Golden CRUD must be simple, complete, and consistent with the existing
project architecture.

It should become the reference implementation for the remaining CRUD modules in
the same business domain.

The Golden CRUD should include:

* Controller
* Routes
* Blade views
* Validation
* Search
* Pagination
* Delete or status change
* Tenant isolation
* Vietnamese UI messages
* Feature tests

## CRUD Generator

For projects containing many CRUD modules, AI agents should first determine
whether a CRUD Generator already exists.

If one exists, reuse it.

If none exists, create one after the first Golden CRUD.

The generator should produce:

* Controller
* Routes
* Blade views
* Validation
* Search
* Pagination
* Status change or delete
* Tenant isolation
* Vietnamese labels and messages
* Basic feature tests
* Language keys if the project uses i18n

CRUD configuration should define:

* Table name
* Module name
* Route name
* Page title
* Searchable fields
* List fields
* Form fields
* Required fields
* Status field if available
* Tenant field if available
* Parent relationship fields if needed

Complex business logic should remain manual.

If a table has custom workflow, nested relationship, special permission,
non-standard UI, or complex domain behavior, do not force the generator.

Mark it as:

```text
Manual Review Required
```

## Batch Generation

After the Golden CRUD and generator are ready, generate CRUD modules in batches.

Recommended batch size:

```text
5–10 related tables
```

Do not generate unrelated tables in the same batch.

Do not continue to the next batch unless explicitly requested.

## Routes

Use existing route groups, prefixes, names, middleware, and role protection.

## Validation

Validation must follow the documented schema and business rules.

Related parent records must belong to the current tenant.

## Security And Tenant Isolation

Every read and write query must filter by the current `customer_id`.

Editing, updating, deleting, or selecting another tenant's records must be
impossible.

## Feature Tests

Cover Admin access, Teacher access when applicable, create, update, validation,
tenant isolation, and delete/reference behavior.

---

# 6. UI Rules

## Reuse Existing UI

Reuse existing layouts, CSS, buttons, cards, tables, form styles, modals, and
navigation.

Do not redesign the whole UI.

## Group Related Fields

Group Create/Edit fields by business meaning.

Keep dependent fields in the same visual group and use a clear Vietnamese group
title.

## Required Field Indicator

Required fields must show a red asterisk after the label.

Optional fields must not show one.

The indicator must follow validation rules.

## Delete Confirmation

Every delete action must ask for confirmation before submitting:

```text
Bạn có chắc chắn muốn xóa dữ liệu này không?
```

Use the existing project modal or `browser confirm()`, whichever is already
consistent with the project UI.

Provide Yes/No actions.

## Friendly Messages

Use Vietnamese user-facing messages.

Do not expose SQL errors or technical exceptions.

Show friendly reference-blocking messages.

Use user-friendly labels in the UI.

Do not expose database table names outside technical or diagnostic screens.

## Responsive Design

Forms, tables, modals, and menus must be usable at:

```text
375px
768px
1366px
1440px
```

Avoid horizontal page overflow.

---

# 7. Routing Rules

* Put reusable domain routes in `routes/modules/*`.
* Avoid duplicate Admin and Teacher route blocks.
* Let parent groups provide prefixes, name prefixes, and middleware.
* Preserve existing route names, URLs, HTTP methods, and controller actions.

---

# 8. Testing Rules

Run automated Feature Tests first.

Browser QA is optional for small CRUD modules unless explicitly requested.

Run Browser QA when UI or layout changes are significant.

For major changes, also run the verification commands required by the
Architecture Guardrails.

---

# 9. Incremental Context Rules

Do not reload the entire project for every task.

Reuse the context from the current implementation whenever possible.

Only re-read documents when:

* entering a new business domain
* implementation rules changed
* documentation conflict exists
* current code does not match documentation
* the task requires a different architecture pattern

For repeated CRUD work in the same domain, reuse:

* the latest completed CRUD module
* the latest route pattern
* the latest view pattern
* the latest test pattern
* the latest generator configuration

---

# 10. Large Scale CRUD Strategy

For projects with dozens of tables, use this order:

```text
Golden CRUD
↓
CRUD Generator
↓
Batch Generation
↓
Manual Review
```

Expected batch size:

```text
5–10 tables
```

Tables requiring custom workflows must be marked as:

```text
Manual Review Required
```

Do not force generic CRUD on tables that need business-specific behavior.

---

# 11. Completion Report

Always report:

* Completed tables
* Skipped tables
* Manual review tables
* Files created
* Files modified
* Files removed
* Migrations changed
* Routes changed
* Controllers changed
* Views changed
* Menus changed
* Generator updated: Yes/No
* Tests and results
* Browser QA result if run
* Assumptions
* Documentation conflicts

---

End of Document
