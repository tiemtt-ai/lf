# LearnForge AI Implementation Prompts

This directory contains the official implementation guidance for AI agents working on LearnForge.

## Main File

* `LF-Implementation-Rules.md`

This is the single implementation rule document for Developers and AI agents.

## Goal

Implement LearnForge features with:

* Minimum token usage
* Minimum repeated work
* Maximum code consistency
* Maximum reuse of existing modules

AI agents should avoid re-analyzing the whole project whenever possible.

## Reading Order

Normally AI agents should read only:

1. `docs/LF-INDEX.md`
2. `docs/prompts/LF-Implementation-Rules.md`
3. `docs/database/<domain>/README.md`
4. `docs/database/<domain>/<table>.md`

Read additional documentation only when:

* `docs/LF-INDEX.md` explicitly requires it
* a documentation conflict appears
* the task enters a new business domain
* the requested feature is not covered by the normal reading order

## Large CRUD Projects

For projects containing many CRUD modules, do not implement every table manually.

Preferred workflow:

1. Build one Golden CRUD.
2. Reuse that CRUD as the implementation template.
3. Build or update a reusable CRUD Generator.
4. Generate CRUD in batches of 5–10 related tables.
5. Manually implement only exceptional or complex tables.

## AI Optimization Rules

Before writing code, AI agents should search for:

* Existing CRUD module
* Existing Controller
* Existing Blade views
* Existing Route pattern
* Existing Validation pattern
* Existing Feature Test
* Existing UI component or layout

Reuse them whenever possible.

Do not duplicate existing implementation.

## Principle

Reuse > Generate > Rewrite

Always prefer improving existing code over creating unnecessary new code.

Keep documentation small, simple, and authoritative.

Do not create additional prompt files unless the content is clearly reusable and cannot fit into `LF-Implementation-Rules.md`.
