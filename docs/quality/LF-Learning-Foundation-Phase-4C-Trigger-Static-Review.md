# Learning Foundation Phase 4C Trigger Static Review

Version: 1.1

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-08-13

Document Path: quality/LF-Learning-Foundation-Phase-4C-Trigger-Static-Review.md

---

## Classification

The initial static rounds were review-only. Rounds 1 through 5 additionally
used explicitly authorized disposable database rehearsals; none targeted the
real LearnForge database.

No migration, application schema or runtime implementation was created. Every
database command was limited to an authorized disposable rehearsal instance.

## Scope

Reviewed all 24 candidate statements in
`LF-Learning-Foundation-Phase-4C-Trigger-Bodies.sql` against ADR-0016, the ten
Frozen Learning table documents, `LF-SCHEMA-CONTRACT.json` and the Phase 4C
semantic specification.

Inventory identity passed: 24 expected, 24 present, no missing, extra or
duplicate trigger name.

## Findings

### BLOCKER

| ID | Trigger | Finding | Required remediation |
| --- | --- | --- | --- |
| B1 | `trg_lrn_fw_versions_bi_validate` | The body validates JSON shape, count and duplicate keys but omits strictly increasing threshold order. A non-ordered Version scale can therefore be inserted although the Frozen contract requires the complete ordered-scale structure. | Apply the same ordinal/LAG rejection used by Framework scale validation. |
| B2 | `trg_lrn_relations_bi_validate` | The body does not validate the complete review/approval state promised by the specification. Pending/not-required audit nullability, approved actor/time/resolved policy and rejected audit/reason rules can be bypassed because the schema contract CHECK list does not contain these full predicates. | Add the complete insert-state predicate and continuity snapshot path/value equality before cycle detection. |

### MAJOR

| ID | Trigger | Finding | Required remediation |
| --- | --- | --- | --- |
| M1 | `trg_lrn_fw_versions_bu_immutable` | Draft-to-draft updates check only publish audit nullability. They can persist deprecation/archive audit on a draft row. | Require every lifecycle audit pair null for draft-to-draft. |
| M2 | `trg_lrn_fw_versions_bu_immutable` | Draft scale edits and draft-to-published transitions do not validate full scale structure/order. | Run complete scale validation on every draft payload update and publication. |
| M3 | `trg_lrn_fw_versions_bu_immutable` | `updated_by` and `updated_at`, documented as last draft editor/time and frozen at publish, remain mutable during later lifecycle transitions. | Freeze both after publication; define their value on publish explicitly. |
| M4 | `trg_lrn_relations_bu_immutable` | Draft updates are accepted after identity checks without revalidating scope/type, review audit or continuity snapshot. Invalid draft state can be persisted. | Apply the same complete state predicate as insert before accepting a draft update. |
| M5 | `trg_lrn_relations_bu_immutable` | Post-publish approval does not require `approved_by = reviewed_by` and `approved_at = reviewed_at`, although the Frozen table contract requires matching actor/time. | Require null-safe equality of both approval/review pairs. |
| M6 | `trg_lrn_calcs_bi_validate` | Carry-forward verifies relation status/policy but does not verify required continuity snapshot paths or equality to relation/source/target IDs and effective policy. | Validate all four canonical paths and exact relation/version/policy equality. |
| M7 | `trg_lrn_calcs_bi_validate` | The body checks that `mastery_level_key` exists but does not validate the candidate scale's full structure/order before using it. Equality to the Version snapshot protects normal rows but does not make malformed legacy/bypassed basis data fail closed. | Validate full ordered-scale structure before level lookup. |
| M8 | `trg_lrn_frameworks_bu_scale` | An archived Framework can retain archived status while changing scale or other authoring fields. This conflicts with the lifecycle statement that archived state preserves history and restoration requires an approved action. | Freeze authoring payload once OLD status is archived, allowing audit-identical no-op only. |

### MINOR

| ID | Area | Finding | Required remediation |
| --- | --- | --- | --- |
| m1 | Error determinism | Parent `SELECT ... INTO` statements have no `NOT FOUND` handler. Broken/corrupt parent state would emit engine SQLSTATE rather than the stable LF code. FKs normally prevent this, but the specification promises stable first-failure codes. | Use `SELECT ... INTO` with an explicit handler or `IF NOT EXISTS` plus deterministic signal. |
| m2 | Normalization | The SQL appendix starts with a comment although approved body normalization says no comments. The comment is outside trigger statements and harmless, but the digest extraction rule is not yet specified. | Define extraction as the exact `CREATE TRIGGER` statement excluding file comments. |
| m3 | Compatibility | `JSON_TABLE`, window `LAG`, recursive CTE inside trigger predicates and JSON boolean comparison have not yet been proven on both MariaDB 11.4 and the approved MySQL floor. | Add syntax probes and behavior tests during authorized disposable rehearsal. |
| m4 | Rehearsal loading | The canonical SQL file has internal semicolons but no mysql-client `DELIMITER` wrapper. Direct `mysql < file.sql` loading would split trigger bodies and create a misleading syntax failure. | The official harness must extract and submit one complete statement through PDO/`DB::unprepared()`. A generated CLI wrapper may add delimiters, but delimiters are excluded from canonical SQL and schema-drift digests. |

## Positive Findings

* Trigger inventory exactly matches all 24 Frozen identities.
* All explicit rejection branches use SQLSTATE `45000` and stable LF codes.
* Evidence source gate fails closed before generic validation and rejects every
  non-`teacher_judgment` token, including `track_events` and
  `behavioral_signal`.
* Append-only update/delete triggers are direct and deterministic.
* Profile projection uses full tenant/user/Definition/basis identity and
  null-safe score/reassessment comparison.
* No body writes another table or performs a cross-Domain generic source lookup.

## Rehearsal Gate

MariaDB rehearsal was not authorized by this review request and was not run.
It remains prohibited until all BLOCKER/MAJOR findings are remediated in a new
candidate and the Architecture Owner explicitly authorizes disposable syntax
and behavior rehearsal.

The authorized rehearsal must create a uniquely named test database, install
the prerequisite and candidate schema, execute the negative matrix, capture
engine/version evidence and drop the database in a `finally` cleanup path.

## Verdict

`BLOCKED` — 2 BLOCKER, 8 MAJOR and 4 MINOR findings. Candidate inventory and
boundary design are sound, but the SQL bodies do not yet fully enforce their
Frozen lifecycle, relation review and continuity contracts. Combined 4B/4C
implementation authorization must not be recorded from this candidate.

---

## Round 2 Static Re-review

Date: 2026-08-13

The remediated candidate was rechecked independently from the remediation
diff. Inventory remains 24/24 with balanced `BEGIN`/`END` blocks, balanced
parentheses and no duplicate identity.

### Finding Closure

| Finding | Closure evidence | Status |
| --- | --- | --- |
| B1 | Framework Version insert now rejects equal/descending threshold order with ordinal `LAG`. | CLOSED |
| B2 | Relation insert now validates semantic/transition vocabulary, continuity snapshot and pending/not-required/pre-approved audit states before cycle detection. | CLOSED |
| M1 | Draft Version update requires all later lifecycle audit pairs null. | CLOSED |
| M2 | Version update validates full scale shape, uniqueness and threshold order before draft edit/publication. | CLOSED |
| M3 | `updated_by` and `updated_at` are frozen after publication. | CLOSED |
| M4 | Draft relation update re-applies the complete relation/review predicate. | CLOSED |
| M5 | Post-publish approval requires matching approval/review actor and time. | CLOSED |
| M6 | Carry-forward validates relation ID, source/target Versions and effective policy in the calculation snapshot. | CLOSED |
| M7 | Calculation insert validates complete ordered scale before level lookup. | CLOSED |
| M8 | Archived Framework authoring/audit payload is frozen. | CLOSED |
| m1 | Parent lookups now use explicit NOT FOUND handlers and stable LF rejection. | CLOSED |
| m2 | Canonical statement extraction explicitly excludes file comments/wrappers. | CLOSED |
| m3 | Cross-engine syntax and semantics require disposable rehearsal. | OPEN — REHEARSAL |
| m4 | Official harness is PDO statement-by-statement; direct mysql-client loading is prohibited without a generated delimiter wrapper. | CLOSED |

The continuity JSON contract was clarified without changing Frozen semantics:
the relation's own pre-insert snapshot does not require its auto-increment ID;
the later carry-forward Calculation snapshot must include `relation_id`.

### Round 2 Verdict

`PASS WITH REHEARSAL REQUIRED` — no BLOCKER or MAJOR static finding remains.
The candidate is ready for an explicitly authorized disposable MariaDB/MySQL
syntax and negative-behavior rehearsal. This verdict does not authorize the
rehearsal, migration implementation, schema-contract replacement or combined
4B/4C implementation.

---

## Disposable MariaDB Rehearsal Round 1

Date: 2026-08-13

Authorization limited rehearsal to an isolated disposable database. The
harness created `lf_phase4c_rehearsal_93089ebdacb5c933`, sent each complete
trigger statement separately through PDO, and dropped the database in `finally`.
Post-cleanup inspection returned `exists_after_cleanup = false`.

### Environment And Result

```text
Engine: MariaDB 10.4.21
Candidate statements: 24
Installed triggers: 0
First failure: trg_lrn_frameworks_bi_scale
SQLSTATE: 42000
Failure point: JSON_TABLE syntax
Behavior probes: not run because compilation failed closed
Real LearnForge database: not migrated or modified
```

MariaDB 10.4.21 is below the documented repository floor of MariaDB 10.5, so
this local run cannot provide the required supported-engine acceptance evidence.
It nevertheless exposed a separate contract incompatibility: official MariaDB
documentation states that `JSON_TABLE` is available only from MariaDB 10.6,
while `docs/tech/LF-Tech-Stack.md` currently permits MariaDB 10.5.

### New BLOCKER — Engine Floor Conflict

| ID | Finding | Required decision |
| --- | --- | --- |
| B3 | Candidate trigger bodies require `JSON_TABLE`, which is unavailable on an allowed MariaDB 10.5 deployment. The SQL therefore violates the documented engine floor even if it passes CI on MariaDB 11.4. | Either rewrite scale/level validation without `JSON_TABLE` and prove it on MariaDB 10.5+, or approve a Tech Stack/Foundation change raising the MariaDB floor to 10.6+. Do not proceed based only on the unsupported local 10.4 result. |

Source: MariaDB's official `JSON_TABLE` documentation identifies MariaDB 10.6
as the first available version. MySQL 8.0 documents `JSON_TABLE`, so the newly
verified conflict is specific to the current MariaDB 10.5 floor.

### Rehearsal Verdict

`BLOCKED` — cleanup and isolation PASS, syntax FAIL on the available engine,
behavior not reached, and B3 proves the candidate is incompatible with one
currently allowed database version. Static Round 2 PASS is superseded for
implementation readiness by this engine finding.

---

## Disposable MariaDB Rehearsal Round 2

Date: 2026-08-13

The scale validator was rewritten without `JSON_TABLE` or window functions.
It now uses trigger-local counters, `WHILE`, `JSON_EXTRACT`, `JSON_SEARCH` and
null-safe shape checks. No engine-floor or governance document was changed.

Four uniquely named disposable databases were used while developing and
verifying the replacement. Every database was dropped from `finally`, including
the two failed runs. The final run used
`lf_phase4c_rehearsal_842652c082b961c3` and reported `cleanup = PASS`.

### Environment And Result

```text
Engine: MariaDB 10.4.21
Candidate statements: 24
Installed triggers: 24
Valid framework scale: PASS
Duplicate scale key rejection: PASS
Descending threshold rejection: PASS
Missing levels rejection: PASS
Valid Version scale: PASS
Final harness verdict: PASS
Real LearnForge database: not migrated or modified
```

The first procedural run exposed a SQL three-valued-logic gap for a missing
`levels` member. `COALESCE` was added to every container, key and threshold type
check, after which the negative probe failed closed with `LF_SCALE_INVALID`.

### Finding Status And Limits

| ID | Status | Evidence |
| --- | --- | --- |
| B3 | REMEDIATED | The candidate contains no `JSON_TABLE` or `LAG`; all 24 statements compile and targeted scale behavior passes on MariaDB 10.4.21, which is older than the documented 10.5 floor. |
| m3 | PARTIAL | MariaDB syntax and targeted scale behavior are proven. The complete negative matrix and the approved MariaDB 11.4 CI baseline remain required. MySQL-floor rehearsal remains separate evidence. |

### Rehearsal Verdict

`TARGETED PASS` — the MariaDB 10.5 compatibility blocker in the scale validator
is remediated, all 24 trigger bodies compile on the available older engine, and
the targeted scale probes pass. This does not approve the Trigger Specification,
authorize migration work, or replace the complete negative-behavior suite.

---

## Disposable MariaDB Negative Matrix Round 3

Date: 2026-08-13

The expanded harness installed all 24 triggers and exercised 26 representative
negative paths across Framework, Version, Definition, Node, Relation, Mapping,
Evidence, Calculation, Calculation Evidence and Mastery Profile. All 26 reached
the expected stable error code. Every disposable database was removed in the
`finally` path and post-run inspection found no `lf_phase4c_matrix_*` schema.

One engine-specific probe remains unavailable locally. The XAMPP MariaDB
10.4.21 build raises error 1046 while executing the recursive CTE in the valid
Relation insert path. The invalid Relation vocabulary path, Relation update and
Relation delete guards all pass. The recursive cycle path must therefore be
repeated on the repository's supported MariaDB 11.4 CI baseline before final
acceptance; an unsupported 10.4 behavior must not be hidden by weakening cycle
enforcement.

### Round 3 Verdict

`CONDITIONAL PASS` — 24/24 bodies compile, targeted scale behavior passes and
26/26 executable negative probes pass. Final Phase 4C acceptance remains
pending only for the recursive Relation-cycle probe and full suite execution on
MariaDB 11.4, followed by independent confirmation and Owner authorization.

---

## Disposable MariaDB 11.4 Baseline Rehearsal Round 4

Date: 2026-08-13

A local Homebrew MariaDB 11.4.12 instance was initialized in an isolated
`/private/tmp` data directory with networking disabled. The harness connected
only through its dedicated Unix socket. It installed all 24 trigger bodies and
repeated the engine-sensitive Relation-cycle path that could not execute on the
unsupported XAMPP 10.4 build.

```text
Engine: MariaDB 11.4.12
Installed triggers: 24
Missing scale levels rejection: PASS
Relation cycle rejection: PASS — LF_RELATION_CYCLE
Invalid Relation vocabulary rejection: PASS — LF_RELATION_INVALID
Harness verdict: PASS
Database cleanup: PASS
Server shutdown: PASS
Temporary data directory and harness removal: PASS
```

Combined with Round 3's 26/26 negative probes, the supported-engine rehearsal
closes the only engine-specific execution gap. The candidate did not require a
weaker cycle predicate or a database-version exception.

### Technical Verdict

`PASS` — no open technical BLOCKER or MAJOR remains in the reviewed candidate.
This review still does not authorize migrations or combined 4B/4C
implementation. Independent confirmation and an Owner-signed combined 4B/4C
authorization record remain governance gates.

---

## Independent Review Round 5 And Remediation

Date: 2026-08-13

Independent review rejected the preceding PASS because required JSON-path
comparisons in Relation, Evidence and Calculation could evaluate to SQL `NULL`
and fail open. Every required comparison is now explicitly null-safe with
`COALESCE(..., FALSE)`.

The candidate was reinstalled as 24/24 triggers on MariaDB 11.4.12. Eight
focused negative probes removed one required path at a time and all returned the
expected stable error:

| Area | Missing paths | Expected code | Result |
| --- | --- | --- | --- |
| Relation | source Version, target Version, policy | `LF_RELATION_INVALID` | 3/3 PASS |
| Evidence | rule key, rule version, source type | `LF_EVIDENCE_INVALID` | 3/3 PASS |
| Calculation | rule key, rule version | `LF_CALCULATION_INVALID` | 2/2 PASS |

Database cleanup, server shutdown and temporary data removal all passed.

The prior wording `full matrix` was too broad. Current evidence covers 26
representative cross-domain probes, the supported-engine cycle probe and eight
required-path probes. The complete matrix listed by the Trigger Specification
must be retained as a reproducible suite and executed on MariaDB 11.4 before
final independent acceptance.

### Current Verdict

`BLOCKER REMEDIATED; MAJOR TEST-COVERAGE GATE OPEN` — candidate semantics no
longer have the identified fail-open path. Final technical PASS is withheld
until the complete traceable negative matrix passes independent review.

---

## Reproducible Matrix Round 6

Date: 2026-08-13

The retained harness is
`tests/Support/Phase4CTriggerMatrixHarness.php`. It refuses the configured
application socket, creates a random database and drops it in `finally`.

MariaDB 11.4.12 result:

```text
Triggers installed: 24
Traceable probes passed: 35
Trigger identity/body check: PASS
Required JSON paths: PASS
Relation cycle: PASS
Lifecycle and immutability: PASS
Evidence source gate: PASS
Calculation/Profile lineage: PASS
CHECK enforcement: PASS
Transaction rollback: PASS
Database cleanup: PASS
Final harness verdict: PASS
```

### Current Verdict

`PENDING INDEPENDENT CONFIRMATION` — the reproducible MariaDB 11.4 matrix closes
M9 implementation evidence. Final technical PASS depends on independent review
of the retained harness, candidate bodies and recorded output. Owner
authorization remains separate and is not implied.

---

## Hardened Matrix Round 7

Date: 2026-08-13

The harness now requires a matching disposable-server sentinel and verifies
`@@datadir` under `/private/tmp`. It creates the 10 Learning tables with all 36
indexes, 51 foreign keys and documented CHECK constraints. Trigger bodies are
normalized and compared with `information_schema.TRIGGERS`; negative assertions
require SQLSTATE `45000` and the exact terminal error code.

MariaDB 11.4.12 passed 57/57 traceable probes and cleanup. Machine-readable
evidence and SHA-256 artifact identities are retained in
`LF-Learning-Foundation-Phase-4C-Rehearsal-Evidence.json`.

Round 8 added every remaining named matrix case and emits the ordered result
list used to reproduce digest
`97ca0d4d090471ed3a793e19ebc3fd9a9321b29614fa0f74902f059eb12dbf59`.

### Current Verdict

`PENDING FINAL INDEPENDENT CONFIRMATION` — all Round 6 independent findings are
remediated in the retained harness and evidence artifact. Owner authorization
remains a separate gate.

## Round 8 Independent Acceptance

Independent review confirmed `0 BLOCKER · 0 MAJOR` and closed B5 plus M9a–M9e.
The Phase 4C candidate therefore has `TECHNICAL PASS`. This does not authorize
implementation; combined 4B/4C still requires Architecture Owner approval.
