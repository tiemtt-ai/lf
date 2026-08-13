# LF Schema Drift Trigger Identity Regression Audit

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-13

Document Path: quality/LF-Schema-Drift-Trigger-Identity-Regression-Audit.md

---

# Classification

Existing-Feature Change.

# Audit Level

HIGH.

# Audit Level Rationale

The schema-drift quality gate is shared database infrastructure. Its output is
used to decide whether a future migration satisfies an approved Foundation
contract. The change adds an opt-in trigger-name invariant to the machine-read
contract and therefore changes a compatibility-sensitive public quality
contract across database consumers.

Initial Audit Level: HIGH.

Final Audit Level: HIGH.

Audit Level Escalation: None.

# Current And Requested Behavior

Previously, schema drift compared trigger timing, event and normalized statement
while intentionally ignoring trigger names. The requested Learning Foundation
contract names 24 physical triggers, so a renamed trigger could pass even though
the approved identity was lost.

The final behavior preserves legacy compatibility by ignoring names unless a
table explicitly sets `trigger_identity_required: true`. For an opted-in table,
the contract requires a boolean flag and every trigger must declare `name`,
`timing`, `event` and `statement`; MySQL metadata supplies `TRIGGER_NAME` and
comparison includes it.

# Documents Reviewed

* `docs/README.md`
* `docs/LF-INDEX.md`
* `docs/governance/LF-Architecture-Guardrails.md`
* `docs/LF-Development-Standards.md`
* `docs/quality/LF-Regression-Audit.md`
* `docs/database/LF-Schema-Drift.md`
* `docs/database/LF-SCHEMA-CONTRACT.json`
* `docs/database/learning/README.md`
* `docs/adr/ADR-0016-Learning-Foundation.md`
* `docs/core/LF-Core-Learning.md`

# Source Of Truth And Invariants

`LF-SCHEMA-CONTRACT.json` is the machine-readable schema contract;
`LF-Schema-Drift.md` defines comparison semantics. Learning table documents
define the approved names and purpose of their triggers. The invariant is:
an opted-in table cannot pass drift when a required trigger is missing, renamed,
or has a different timing, event or normalized statement. Existing contracts
without the opt-in must keep their previous name-agnostic behavior.

# Impact Analysis

Direct consumers are `SchemaDriftAnalyzer`, `MySqlSchemaInspector`, the
`schema:drift` Artisan command and unit tests. Indirect consumers are CI and
Architecture Review workflows that consume the gate result. The change is
read-only with respect to application data: it does not create migrations,
modify database tables, bypass tenant scope, alter authorization or change a
runtime business flow.

# Files Changed

* `app/Support/SchemaDrift/MySqlSchemaInspector.php`
* `app/Support/SchemaDrift/SchemaDriftAnalyzer.php`
* `tests/Unit/SchemaDriftAnalyzerTest.php`
* `docs/database/LF-Schema-Drift.md`
* Learning schema contract and Phase 3 table documentation

New Files: This audit document.

# Tests Added Or Updated

* A renamed trigger produces `triggers.missing` when identity is required.
* A non-boolean `trigger_identity_required` produces the blocking contract
  finding `contract.trigger_identity_required`.
* Existing semantic comparison tests retain name-agnostic indexes, foreign keys,
  checks and legacy triggers.

# Commands And Results

```text
php artisan test tests/Unit/SchemaDriftAnalyzerTest.php  PASS
php artisan test                                         PASS
php artisan docs:lint                                    PASS
php artisan schema:drift --docs-only                     PASS
./vendor/bin/pint --test <changed PHP files>             PASS
git diff --check                                         PASS
```

The test and gate commands above are rerun after the final documentation and
validator change. The repository-wide `./vendor/bin/pint --test` still reports
seven pre-existing formatting violations in unrelated Course migrations,
controllers, service and test files; it reports none in the changed schema-drift
files. A fresh MariaDB comparison is intentionally not applicable: all ten
Learning tables remain `Not Implemented`, and no migration is authorized by
Phase 3.

# Requirement-To-Test Traceability

| Requirement | Verification |
| --- | --- |
| Opted-in trigger names are enforced. | `test_trigger_name_drift_is_detected_when_the_contract_requires_identity` |
| Invalid opt-in configuration is blocked. | `test_trigger_identity_requirement_must_be_boolean` |
| Existing trigger compatibility is retained. | `test_matching_schema_passes` and semantic drift provider |
| Documentation and contract remain indexable. | `docs:lint` and `schema:drift --docs-only` |

# Unverified Items

No Phase 4 migration, executable trigger body or real Learning database exists
yet. Their MariaDB integration verification remains explicitly gated to Phase
4C and is not represented as passing implementation evidence here.

The repository-wide formatter debt above is outside this change and remains for
its owning workstream; it does not affect the scoped formatter result.

# Remaining Risks

Normalized SQL statements can only be validated against the statement recorded
by the database engine. Phase 4C must specify exact reject conditions and add
negative MariaDB tests for every trigger invariant before a trigger migration is
authorized.

# Findings By Severity

No BLOCKER, HIGH, MEDIUM or LOW finding remains for this change.

# Final Verdict

PASS — the opt-in identity rule closes the trigger-name blind spot without
changing behavior for existing contracts. Phase 3 remains documentation-only;
this verdict does not authorize migration or runtime implementation.
