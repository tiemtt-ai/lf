# LearnForge Implementation Rules

Version: 1.3

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: prompts/LF-Implementation-Rules.md

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

## Existing-Feature Change Trigger

Before modifying existing behavior, automatically activate the
[Existing-Feature Change Safety Protocol](../LF-Development-Standards.md#existing-feature-change-safety-protocol)
and complete the risk-proportionate
[LF Regression Audit](../quality/LF-Regression-Audit.md). Declare `LOW`,
`MEDIUM` or `HIGH` before implementation, reassess after the final diff, and
use the highest applicable level. This does not depend on the user explicitly
asking for review, audit or regression testing.

---

# 3. Development Principles

## Documentation First

Read the current repository files.

Never rely on memory from previous tasks.

Documentation is the source of truth.

---

## Database First

Database documentation defines:

- fields
- relationships
- indexes
- statuses
- business rules

Implement the database exactly as documented.

---

## Keep It Simple

Reuse the existing Laravel, Blade, CSS, routing, middleware, and testing
patterns.

Avoid unnecessary abstraction.

---

## Reuse Safely

Reuse existing CRUD implementations whenever possible.

Always verify:

- table documentation
- validation
- tenant isolation
- parent relationships
- delete rules

Never assume two tables share identical business rules.

---

## Business Module First

Understand the business workflow before implementing CRUD.

Database tables exist to support business processes.

Do not implement related tables as isolated CRUD screens when they belong to a
single business workflow.

---

## Module First

When implementing a business module:

1. Analyze all requested tables.
2. Classify each table:
  - Simple CRUD
  - Standard CRUD
  - Manual Review Required
3. Implement only the requested batch.
4. Stop after completing the batch.

---

## No Eloquent Models

Do not create or use Eloquent Models.

Use:

```php
DB::table()
```

---

## Tenant First

Every business query must be scoped by:

```php
TenantContext::customerId()
```

No cross-tenant access is allowed.

---

## Conflict Rule

If documentation, Guardrails, ADRs, or current implementation conflict:

Stop.

Report the conflict.

Do not silently choose one.

---

# 4. Architecture Change Workflow

This workflow applies only to architecture-level changes.

Routine CRUD implementation that follows approved documentation does **not**
require a new Architecture Review or Architecture Freeze.

Workflow:

Business Requirement

↓

Architecture Proposal

↓

Documentation Update

↓

Architecture Review

↓

Architecture Freeze

↓

Impact Analysis

↓

Implementation

↓

Testing

↓

Done

Rules:

- Documentation is the single source of truth.
- Update documentation before updating code.
- No implementation may begin before Architecture Freeze.
- After Architecture Freeze, every implementation must follow the approved architecture.
- If implementation conflicts with approved documentation, stop and report.

---

# 5. Database Rules

## Documentation Is The Source Of Truth

Implement every table exactly as documented.

---

## No Undocumented Fields

Do not:

- add
- rename
- remove

fields, indexes, relationships, statuses, or business rules unless documentation
explicitly requires it.

---

## Relationships

Respect documented relationships.

Validate that related records belong to the same tenant.

Optional documented relationships (nullable foreign keys) must remain optional.

Do not force optional relationships to become mandatory.

---

## Migration Rules

- Never modify historical production migrations.
- Do not create duplicate migrations.
- Create only documented additive migrations when required.
- Match documented field types, defaults, indexes, and constraints.

---

## Database Naming Rules

MySQL limits identifier names to 64 characters.

Never rely on Laravel auto-generated names for long table names.

Always explicitly define short names for:

- indexes
- unique constraints
- foreign keys

Recommended prefixes:

- idx_
- uk_
- fk_

---

## Delete Rules

- Never cascade delete unless documentation explicitly requires it.
- Check child/reference records before deleting.
- Block deletion when references exist.
- Prefer inactive/archive status for reusable master data.

---

## Direct Database Access

Use:

```php
DB::table('table_name')
```

Never use:

- Model::query()
- Model::create()
- Model::find()

---

# 6. CRUD Rules

## Standard CRUD Scope

A normal CRUD module includes:

- List
- Search / Filter
- Create
- Update
- Delete or Status Change
- Validation
- Tenant Isolation
- Feature Tests

---

## Golden CRUD

For repeated CRUD work:

1. Build one Golden CRUD.
2. Review it.
3. Reuse it.

Golden CRUD defines coding style only.

Business rules always come from the current table documentation.

Do not reuse Golden CRUD for Manual Review tables.

---

## Routes

Reuse existing:

- route groups
- prefixes
- middleware
- naming
- role protection

Avoid duplicate routes.

---

## Validation

Validation must follow documented business rules.

Parent records must belong to the current tenant.

---

## Security

Every read/write query must filter by:

```php
customer_id
```

Editing another tenant's data must be impossible.

---

## Feature Tests

Include at minimum:

- Admin access
- Teacher access (if applicable)
- Create
- Update
- Validation
- Tenant isolation
- Delete/reference rules

---

# 7. UI Rules

## Reuse Existing UI

Reuse existing:

- layouts
- CSS
- buttons
- tables
- cards
- forms
- modals

Do not redesign the application.

---

## Group Related Fields

Group fields by business meaning.

Dependent fields should appear together.

Use clear Vietnamese group titles.

Child tables should be managed inside the parent business screen whenever practical.

Avoid standalone menus for child tables unless they have independent business value.

---

## Required Field Indicator

Required fields must display a red asterisk.

Optional fields must not.

---

## Delete Confirmation

Every delete action must ask for confirmation.

Example:

```text
Bạn có chắc chắn muốn xóa dữ liệu này không?
```

Use the project's existing confirmation style.

---

## Friendly Messages

Show Vietnamese business messages.

Never expose SQL or technical exceptions.

Use business labels instead of database names.

---

## Responsive Design

Follow the current LearnForge responsive design standard.

Do not introduce layout regressions.

---

# 8. Routing Rules

- Put reusable routes in `routes/modules/*`
- Avoid duplicated Admin/Teacher route blocks
- Let parent groups provide prefixes, middleware, and route names
- Preserve existing URLs and route names

---

# 9. Testing Rules

Run Feature Tests first.

Browser QA is optional unless:

- significant UI changes
- explicitly requested

For major architectural changes, also execute verification required by the
Architecture Guardrails.

---

# 10. Stop Rule

Stop implementation immediately if:

- documentation conflicts exist
- business rules are unclear
- tenant ownership cannot be verified
- implementation differs significantly from Golden CRUD
- approved documentation and current implementation diverge significantly

Report impacted files before proposing changes.

Do not guess.

---

# 11. Completion Report

Always report:

- Files created
- Files modified
- Files removed
- Migrations
- Routes
- Controllers
- Views
- Tests
- Browser QA (if run)
- Assumptions
- Documentation conflicts

Include architecture assumptions.

If none exist, explicitly report:

```text
No architecture assumptions.
```

---

# Guiding Principle

When multiple implementation approaches are technically correct,
prefer the one that is:

- Simpler
- Easier to maintain
- Easier for future AI agents to understand
- Consistent with existing LearnForge implementation

Avoid unnecessary abstraction.

---

End of Document
