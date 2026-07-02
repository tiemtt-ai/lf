# LearnForge Implementation Rules

Version: 1.0

Status: Official Implementation Rule

---

# 1. Purpose

This document is the single source of truth for implementing database-backed
features and CRUD modules in LearnForge.

It applies to Admin and Teacher management modules unless a higher-priority
Governance document or ADR explicitly overrides it.

---

# 2. Reading Order

AI agents should normally read only:

1. `docs/LF-INDEX.md`
2. `docs/prompts/LF-Implementation-Rules.md`
3. `docs/database/<domain>/README.md`
4. `docs/database/<domain>/<table>.md`

For repeated work in the same domain, reuse the most recent completed module as
the implementation pattern.

Do not re-read unnecessary global documents unless a conflict appears or
LF-INDEX routes the task to additional documentation.

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

## One Table At A Time

Implement only the requested table or module. Do not continue to the next table
automatically.

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

- Do not modify old production migrations.
- If the table already exists, do not create a duplicate migration.
- Report an existing table and create only documented additive migrations when
  required.
- Implement documented field types, defaults, indexes, and constraints.

## Delete Rules

- Never cascade delete unless documentation explicitly requires it.
- Check existing child and reference records before deleting.
- Block deletion when references exist.
- Prefer a documented inactive or archived status for reusable master data.

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

- List, search, and filter
- Create and store
- Edit and update
- Delete or status change
- Validation
- Tenant isolation
- Feature tests

## Routes

Use existing route groups, prefixes, names, middleware, and role protection.

## Validation

Validation must follow the documented schema and business rules. Related parent
records must belong to the current tenant.

## Security And Tenant Isolation

Every read and write query must filter by the current `customer_id`. Editing,
updating, deleting, or selecting another tenant's records must be impossible.

## Feature Tests

Cover Admin access, Teacher access when applicable, create, update, validation,
tenant isolation, and delete/reference behavior.

---

# 6. UI Rules

## Reuse Existing UI

Reuse existing layouts, CSS, buttons, cards, tables, form styles, modals, and
navigation. Do not redesign the whole UI.

## Group Related Fields

Group Create/Edit fields by business meaning. Keep dependent fields in the same
visual group and use a clear Vietnamese group title.

## Required Field Indicator

Required fields must show a red asterisk after the label. Optional fields must
not show one. The indicator must follow validation rules.

## Delete Confirmation

Every delete action must ask for confirmation before submitting:

```text
Bạn có chắc chắn muốn xóa dữ liệu này không?
```

Use the existing project modal or `browser confirm()`, whichever is already
consistent with the project UI. Provide Yes/No actions.

## Friendly Messages

Use Vietnamese user-facing messages. Do not expose SQL errors or technical
exceptions. Show friendly reference-blocking messages.

Use user-friendly labels in the UI. Do not expose database table names outside
technical or diagnostic screens.

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

- Put reusable domain routes in `routes/modules/*`.
- Avoid duplicate Admin and Teacher route blocks.
- Let parent groups provide prefixes, name prefixes, and middleware.
- Preserve existing route names, URLs, HTTP methods, and controller actions.

---

# 8. Testing Rules

Run automated Feature Tests first.

Browser QA is optional for small CRUD modules unless explicitly requested.
Run it when UI or layout changes are significant.

For major changes, also run the verification commands required by the
Architecture Guardrails.

---

# 9. Completion Report

Report:

- Files created, modified, and removed
- Migrations, routes, controllers, views, and menus changed
- Tests and results
- Browser QA result if run
- Assumptions
- Documentation conflicts

---

End of Document

