# Learning Gate 2 Independent Review

Version: 1.0

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-09-08

Document Path: quality/LF-Learning-Gate-2-Independent-Review.md

---

# Review Result

```text
Initial Audit Level: HIGH
Final Audit Level: HIGH
Findings By Severity: BLOCKER 0 | HIGH 0 | MEDIUM 1 | LOW 0
Final Verdict: PASS WITH DOCUMENTED RISK
Gate Decision: GATE 2 PASS
Excluded: production deployment; AI Proposal persistence/review/promotion;
          Course Mapping implementation; remediation of this review's finding
```

Gate 2 is technically closed for the existing customer-admin Learning
Framework authoring surface. The surface preserves Learning ownership, explicit
Framework Version selection, tenant isolation, customer-admin authority,
draft-only authoring and one-way publication. This verdict does not authorize
the separate ADR-0017 AI Authoring Proposal implementation or production
deployment.

# Reviewer Independence And Method

This pass was performed as the requested independent `0b` review. The reviewer
did not author or remediate the reviewed Learning surface and made no production
code, schema, migration, policy or conflict-register change. Repository changes
from this pass are this review artifact and its required catalog/manifest links.

The review did not accept the previous closure report as proof. It traced the
approved decisions through Governance, ADR-0016, LF-Core-Learning, the owner
services, HTTP boundary, routes, Blade surface and MariaDB tests, then ran the
released Gate 2 suites on a fresh disposable MariaDB 11.4.12 instance.

# Scope

Included:

* customer-admin Learning Framework reads and writes;
* Framework, draft Framework Version, Stable Node Definition and Versioned Node
  authoring;
* non-empty, one-way Framework Version publication;
* tenant, role, lifecycle, immutable snapshot and explicit-version boundaries;
* the Teacher Judgment service path needed to prove an authored and published
  Node is usable by Learning;
* request tampering, browser-shaped payloads and cross-tenant denial;
* the documentation evidence used to claim Gate 2 closure.

Excluded:

* AI Authoring Proposal schema, API, prompts, UI and promotion, which ADR-0017
  still places behind its own Implementation Gate;
* Course Mapping implementation and canonical promotion for AI proposals;
* a Teacher Judgment submission controller/route/UI, which LF-Core-Learning
  still records as unimplemented;
* production deployment.

# Canonical Decisions Verified

| Decision | Independent evidence | Result |
| --- | --- | --- |
| Learning remains Source of Truth | Writes go through `LearningFrameworkAuthoringService`; the controller does not write `core_learning_*` tables | PASS |
| G2-N1 authoring reads use a separate owner service | `LearningFrameworkController` delegates all graph reads to `LearningFrameworkReadService`; runtime access is not repurposed | PASS |
| Only active `customer_admin` may author or publish | Admin route middleware, four FormRequests and the service-level `assertActor()` guard agree; teacher/student and cross-tenant denials are tested | PASS |
| Tenant isolation | Every service query is tenant-scoped; route/body parent IDs are compared; cross-tenant read/write tests fail closed | PASS |
| Draft-only mutation and immutable publication | Node/version writes lock and require `draft_snapshot`; publication is one-way; published mutation tests pass | PASS |
| G2-N3 non-empty publication | Service requires at least one active Node and UI uses the same predicate to disable publish | PASS |
| G2-N2 explicit basis, no inferred current Version | No reviewed Learning service/controller/view resolves `latest`, publish time or version number as the current Framework Version | PASS |
| Authored Node is operationally usable | MariaDB service test authors and publishes the graph, submits a Teacher Judgment and verifies Evidence, Calculation and Profile lineage | PASS |
| Browser request shape cannot bypass the service | HTTP suite covers numeric JSON normalization, blank optional fields, prohibited ownership/lifecycle/audit fields and parent-ID tampering | PASS |

# Physical Verification

A private MariaDB 11.4.12 server was initialized under
`/tmp/lf-gate2-review-20260908`, exposed only through its Unix socket and used
with the disposable database `lf_gate2_review`. The server was shut down and the
entire temporary runtime removed after the tests. The application database and
XAMPP MariaDB were not used.

```text
LearningFrameworkAuthoringMariaDbTest      15 passed
LearningFrameworkAuthoringHttpMariaDbTest  20 passed
LiveClassSchemaConstraintMysqlTest          11 passed
EnrollmentBindingTriggerMysqlTest            1 passed
LearningRuntimeMariaDbTest                    1 passed
TeacherJudgmentRuntimeMariaDbTest            17 passed
-----------------------------------------------------
Total                                       65 passed
Assertions                                  304
Failures                                      0
```

Additional gates:

```text
docs:lint                    PASS
schema:drift --docs-only     PASS (95 migration files)
Pint, selected Gate 2 files PASS
git diff --check             PASS after this review artifact and catalog update
```

# Finding

## MEDIUM — Superseded Gate 2 text still states the opposite verdict

The current closure section in
`LF-Learning-Foundation-Phase-4E-Runtime-Independent-Code-Review.md` states
`GATE 2 PASS — closed 2026-08-23`. Immediately afterwards, a section titled
`Gate 2 Remaining Items (superseded)` still begins with the unqualified sentence
`Gate 2 is not closed` and says an independent review is the remaining
condition.

The heading and later Owner amendment make the intended chronology recoverable,
so this does not invalidate the implementation or the current Gate 2 closure.
It is nevertheless contradictory prose in an official review and can be quoted
out of context as a current blocker. This matches the previously planned Phase
4E documentation-contradiction cleanup.

Required remediation in a separate task:

1. Preserve the historical record, but mark the old sentence itself as
   superseded at sentence level or move it into an explicitly dated historical
   block.
2. Keep the current canonical outcome unambiguous: Gate 2 closed on 2026-08-23;
   production, Course Mapping and AI implementation gates remain separate.
3. Register or resolve the verified documentation contradiction according to
   `LF-Documentation-Conflicts.md`, then run `docs:lint`.

# Architecture Checklist Result

| Section | Score | Notes |
| --- | ---: | --- |
| A — Domain Boundary | 15/15 | Learning ownership remains explicit |
| B — Data Ownership | 15/15 | Tenant chain and denial paths verified |
| C — Versioning | 15/15 | Draft/publish/immutability and explicit basis verified |
| D — Business Rules | 15/15 | Owner-service writes and no cross-domain shortcut |
| E — Database | 15/15 | MariaDB constraints and runtime path exercised |
| F — Architecture | 10/10 | Guardrails and approved ADR-0016 preserved |
| G — Documentation | 7.5/10 | One superseded-text contradiction remains |
| H — Ready For Existing Gate | 5/5 | Existing Gate 2 surface is technically ready |
| **Total** | **97.5/100** | **Foundation Ready for the reviewed scope** |

# Conditions For Phase 2 Step 7

This review satisfies the planned independent `0b` prerequisite. Step 7 may
use the existing Learning owner-service boundary only after its own ADR-0017
implementation prerequisites are complete. In particular:

* human `accept` remains a proposal review state, never publication;
* Node creation targets an explicitly selected `draft_snapshot` Framework
  Version through Learning's service;
* canonical Mapping promotion waits for both exact published identities;
* neither side may resolve `latest` or silently rebind;
* Proposal acceptance creates no Evidence or Mastery side effect;
* production remains separately gated.

# Remaining Risks And Unverified Items

* No browser was manually driven in this pass. Browser-shaped HTTP tests render
  and submit the relevant forms, and the existing review records responsive UI
  evidence; visual regression was not repeated.
* The whole application suite was not rerun. The exact six-file MariaDB Gate 2
  selection was rerun and reproduced its canonical 65-test/304-assertion result.
  Unrelated full-suite order-dependent Media failures previously documented in
  the repository are outside this gate.
* No concurrency stress process was added in this review. The reviewed service
  uses row locks for version-number allocation and publication, while physical
  uniqueness/immutability tests remain part of the MariaDB selection.

# Final Decision

```text
GATE 2 PASS

The existing Learning Framework authoring boundary is suitable as the owning
domain target for later AI Proposal promotion design. Resolve the MEDIUM
documentation contradiction during Step 1 documentation synchronization. Do
not treat this PASS as authorization to implement ADR-0017 persistence or to
deploy Learning to production.
```
