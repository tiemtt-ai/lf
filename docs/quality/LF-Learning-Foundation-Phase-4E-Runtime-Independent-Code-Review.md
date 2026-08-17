# Learning Foundation Phase 4E Runtime Independent Code Review

Version: 1.1

Document Status: Review

Implementation Status: Not Applicable

Review Status: Fail

Last Updated: 2026-08-17

Document Path: quality/LF-Learning-Foundation-Phase-4E-Runtime-Independent-Code-Review.md

---

## Gate Reference

This document records the review required by
[Phase 4E Teacher Judgment Design](LF-Learning-Foundation-Phase-4E-Teacher-Judgment-Design.md)
§ Remaining Gates Before External Surface, item 1:

> Complete independent runtime and migration code review by a reviewer who did
> not author them. Review by the author closes no gate however thorough it is.

The reviewer did not author the reviewed code. The findings below were
independently re-verified against the source by the author on 2026-08-17 and
accepted; see § Author Acceptance.

## Scope

Reviewed:

* `app/Services/TeacherJudgmentService.php`
* `app/Services/LearningFrameworkAuthoringService.php`
* `database/migrations/2026_08_15_010000_create_liveclass_teacher_judgments.php`

Read as supporting context: `LearningRuntimeAccess`,
`LearningEvidenceSourceGate`, `LearningMasteryProfileProjector`,
`App\Support\TenantContext`, the released Learning Foundation migration,
the four Course parent tables the flow authorizes against,
`CourseCohortStudentController` membership writes, `config/app.php`,
`config/database.php`, the three Phase 4E test suites and the CI MariaDB job.

Checked against: ADR-0016, ADR-0017, `core/LF-Core-Learning.md` (Frozen v1.2),
the Phase 4E design document (v1.4), `core_liveclass_teacher_judgments.md`,
Architecture Guardrails and the Architecture Review Checklist.

Written in English to match the Phase 4E document family it attaches to.

## Verdict

```text
GATE 1 FAIL — four blocking defects (B1–B4), one mandatory test gap (B5),
              one open Owner decision that must close before any route (N1)
```

No architectural defect was found. Every blocking finding sits in the
implementation layer and is fixable without touching the Frozen Learning
Foundation contract or the accepted physical Teacher Judgment contract.

## Correction To A Stated Premise

The review request stated that all seven application authorization rules have no
database backstop. Two do:

* the correction-identity rule implemented by `priorIdentityMatches()` is
  repeated in full by `trg_ltj_bi_correction`;
* the evidence source gate is closed physically by
  `trg_lrn_evidence_bi_validate`, which rejects any `source_type` other than
  `teacher_judgment`.

The genuinely application-only rules are: (1) teacher role and status;
(2) Cohort `active` plus operating window; (3) assignment coherence plus date
range; (4) membership coherence plus `joined_at`; (5) Enrollment coherence with
learner, Product and Version; (6) basis `published` plus Node and Definition
`active`; (7) level and score validity under the basis scale snapshot.

This narrows the exposure but does not reduce it: B1, B2 and B4 all land inside
these seven.

---

## B1 — MAJOR — The runtime uses UTC inside an application that runs on local wall-clock time

`config/app.php:68` sets `'timezone' => 'Asia/Ho_Chi_Minh'`.
`config/database.php` declares no connection `timezone`. Every other writer in
LearnForge therefore produces and stores local wall-clock values — including
`core_course_cohort_students.joined_at`, written from
`CourseCohortStudentController.php:721` (`$now = now();`, used at lines 752 and
769).

The Phase 4E runtime is the only writer that does otherwise:

| Location | Code |
| --- | --- |
| `TeacherJudgmentService.php:52` | `CarbonImmutable::now('UTC')` → `submitted_at`, `created_at` |
| `TeacherJudgmentService.php:217` | `CarbonImmutable::parse($command['occurred_at'], 'UTC')->utc()` |
| `TeacherJudgmentService.php:477` | `timestamp()` parses every stored value as UTC |
| `LearningFrameworkAuthoringService.php:335` | `CarbonImmutable::now('UTC')` → `created_at`, `updated_at` |

The result is a two-way bind with no correct caller convention:

| Caller sends | Failure |
| --- | --- |
| local wall clock, as every other writer stores | `TeacherJudgmentService.php:53` — `occurred_at > submittedAt` raises `LF_TEACHER_JUDGMENT_FUTURE_OCCURRENCE`, because `now('UTC')` is 7 hours behind wall clock |
| true UTC, as the service implies | `TeacherJudgmentService.php:282` — `timestamp(joined_at) > occurred_at` raises `LF_TEACHER_JUDGMENT_MEMBERSHIP_DENIED` for the first 7 hours after the learner joins, because `joined_at` is a local wall-clock string reinterpreted as UTC |

Worked example of the second row:

```text
Learner joins        2026-08-17 09:00 local  → joined_at   = '2026-08-17 09:00:00'
Teacher judges       2026-08-17 09:30+07:00  → occurred_at = '2026-08-17 02:30:00' UTC
Compare              '2026-08-17 09:00:00' > '2026-08-17 02:30:00'  → DENIED
```

Every judgment occurring less than seven wall-clock hours after the learner
joins the Cohort is refused. For a real class that is most of the first session.

The defect is not repairable after the fact. `core_liveclass_teacher_judgments`
carries `trg_ltj_bu_immutable` and `trg_ltj_bd_immutable`, so `occurred_at`,
`submitted_at` and `created_at` written under the wrong convention can never be
updated — not by application code and not by a migration. Nothing in the row
records which convention produced it, so a later system-wide normalization
cannot disambiguate these rows from correctly written ones.

The Phase 4E test suites cannot detect this: the fixture sets
`joined_at = '2026-08-01 00:00:00'` and `occurred_at = '2026-08-15 09:00:00'`
(`TeacherJudgmentRuntimeMariaDbTest.php:295` and `:382`), fourteen days apart,
which absorbs the seven-hour skew completely.

This is additionally a documentation gap under LF-INDEX Rule 2 — Never Guess. No
Phase 4E document, `LF-Core-Learning.md`, or `LF-Development-Standards.md`
states a timezone contract; a search for "UTC" and "timezone" across them
returns nothing. The time contract of an append-only table must be stated by the
Architecture Owner before the first production write, not inferred from code.

## B2 — MAJOR — Cohort and assignment date windows are evaluated in the wrong timezone

Consequence of B1 rather than an independent defect, recorded separately because
its effect is on default-deny rather than on availability.

`TeacherJudgmentService.php:265` derives the occurrence date from the
UTC-normalized value:

```php
$occurredDate = substr($payload['occurred_at'], 0, 10);
```

and compares it (lines 266–273) against `core_course_cohorts.start_date` /
`end_date` and `core_course_cohort_teachers.assigned_from` / `assigned_to`. All
four are `DATE` columns holding business dates entered in local time
(`2026_07_04_020000_create_core_course_cohorts_table.php:22-23`;
`2026_07_25_010000_create_cohort_liveclass_operations.php:17-18`).

The string comparison itself is sound. The skew enters before the substring:

```text
Cohort 2026-08-01 → 2026-08-31 (local business dates)

01/08 06:00 local = 31/07 23:00 UTC → occurredDate 07-31 → wrongly denied
01/09 06:00 local = 31/08 23:00 UTC → occurredDate 08-31 → wrongly allowed, one day past the Cohort
```

The design document requires that the assignment date range cover the approved
judgment occurrence date. As written, that boundary is off by one day in both
directions, so rules 2 and 3 do not close where the document says they close.

Fix order: B2 has no independent remedy and must follow the B1 decision.

## B3 — MAJOR — Correction silently overwrites the caller's `occurred_at`, disabling both guards

`TeacherJudgmentService.php:38-41`:

```php
$prior = $this->lockPrior($customerId, $payload['supersedes_judgment_id']);
if ($prior !== null) {
    $payload['occurred_at'] = $this->timestamp($prior->occurred_at);
}
```

Three consequences.

**1. Both correction guards become unreachable.**
`priorIdentityMatches()` compares
`$this->timestamp($prior->occurred_at) === $payload['occurred_at']`
(`TeacherJudgmentService.php:340`) — two values assigned equal to each other at
line 40, so the comparison can never fail. Because the application already
forces equality, `trg_ltj_bi_correction`'s `prior.occurred_at <=> NEW.occurred_at`
never fires either. Two layers of protection for the one field a correction must
preserve, neither of which can execute.

**2. A caller that supplies the wrong occurrence time is accepted silently.**
The design document requires that a reused key with a changed payload fail
closed. Here a *first* submission carrying a contradictory `occurred_at`
succeeds, with the value quietly replaced.

**3. A legitimate retry of a successful correction fails.**
`findExisting()` and `replay()` run at lines 33–35, *before* the overwrite, so
`replay()` compares the stored value against the caller's original payload
(`TeacherJudgmentService.php:387`):

```text
Attempt 1: uuid U, supersedes P, occurred_at X   (X ≠ P.occurred_at = Y)
           → overwritten to Y → INSERT succeeds
Attempt 2: byte-identical retry after a network timeout
           → findExisting returns the row with occurred_at = Y
           → replay() compares Y against X → LF_TEACHER_JUDGMENT_IDEMPOTENCY_CONFLICT
```

This is the exact case `submission_uuid` exists to absorb. A client receiving a
conflict on a retry is likely to resubmit as a new judgment, appending another
permanent row to the ledger.

Remedy: delete the assignment at line 40 and reject a mismatching `occurred_at`
explicitly. Line 340 and the insert trigger then both become live, and replay
becomes consistent.

## B4 — MAJOR — The Cohort end-boundary submission rule is not implemented

Phase 4E design document, § Approved Default-Deny Authorization Direction:

> The command must be submitted no later than the Cohort end boundary. There is
> no normal post-Cohort correction command in Phase 4E.

In the runtime, `submitted_at` is constrained only by `occurred_at <= submitted_at`
(`TeacherJudgmentService.php:53` and `chk_ltj_005`). No comparison of
`submitted_at` against `cohort->end_date` exists anywhere.

The only remaining barrier is `cohort->status === 'active'`, and:

* `core_course_cohorts.status` is `VARCHAR(50)` with no CHECK and no enum
  (`create_core_course_cohorts_table.php:20`);
* no scheduler or job was found that moves a Cohort out of `active` when
  `end_date` passes.

A Cohort left at `active` past its end date therefore accepts judgments
indefinitely, backdated into the historical window — a permanent Evidence write
after the class has closed, which is what the quoted rule exists to prevent.

## B5 — MAJOR — The negative matrix falls short of the design document's own requirement

`test_application_owned_authorization_rules_fail_closed()` drives five denied
states (Cohort `closed`, Enrollment `cancelled`, membership `inactive`,
assignment `inactive`, basis `deprecated`) but asserts with
(`TeacherJudgmentRuntimeMariaDbTest.php:128`):

```php
$this->assertStringStartsWith('LF_TEACHER_JUDGMENT_', $exception->getMessage());
```

This assertion cannot identify which rule denied. If an earlier rule rejects the
command for an unrelated reason the test still passes and the rule under test is
never exercised. The design document already recognised this failure mode for
the basis test — "checks that exact code rather than a prefix, so it cannot pass
on rejection by an earlier rule" — but the five-rule matrix still uses a prefix.

Measured against § Required Negative Matrix:

| Required case | State |
| --- | --- |
| cross-tenant actor / learner / Cohort / assignment / membership / Node / basis | absent |
| inactive or unassigned teacher, role tampering (rule 1) | absent — no `customer_admin` submission case |
| assignment date outside the occurrence date | absent |
| tenant-safe but mutually inconsistent rows | absent |
| invalid level or score | absent |
| duplicate key with changed payload | present, separate test |
| double-submit concurrency | absent |

Rule 1 is entirely unverified, although it is the first check in
`lockAndAuthorize()` and the only thing that denies `customer_admin` — named
explicitly in Owner decision 4 of 2026-08-15.

---

## Non-Blocking Findings

**N1 — `assertActor` admits any active user to Framework authoring.**
`LearningFrameworkAuthoringService.php:237-244` checks tenant and `status` only.
The class documents this as a deferred Owner decision, which is correct, but it
must be a blocking precondition for Gate 2 rather than a note: the day a route
reaches this service, a `student` principal can publish a Framework Version.
`assertActor` is also the only lookup in either service that omits
`lockForUpdate()`.

**N2 — the "Framework must still be `active`" rule closes on two of four write
paths.** `createDraftVersion()` and `createDefinition()` pass through
`lockFramework()` (`LearningFrameworkAuthoringService.php:246`);
`createNode()` (line 163) and `publishVersion()` (line 215) use plain
`lockRow()`. No database trigger checks Framework status on publish —
`trg_lrn_fw_versions_bu_immutable` validates only the version's own lifecycle.
Archiving a Framework therefore does not prevent its draft version from being
published and immediately becoming a valid Teacher Judgment basis. The class
docblock currently claims this rule is enforced.

**N3 — the two services disagree on the mastery scale value domain.**
`normalizeScale()` (`LearningFrameworkAuthoringService.php:279`) requires at
least two levels, unique keys and strictly increasing thresholds, but does not
bound them. `mastery_score` is bounded to `[0,1]` at
`TeacherJudgmentService.php:201` and by `chk_ltj_001`. A Framework authored on a
0/50/80 scale is accepted and then makes every scored judgment fail
`LF_TEACHER_JUDGMENT_RESULT_INVALID`. A scale whose lowest threshold exceeds 0
makes a score of 0 unusable.

**N4 — an unrelated administrative action can permanently close the correction
path.** `core_course_cohort_students` carries `UNIQUE (customer_id, enrollment_id)`,
and `CourseCohortStudentController.php:744-757` updates `cohort_id` and
`joined_at` **in place** on transfer or re-add. After a transfer,
`lockAndAuthorize()` denies any correction of an earlier judgment because the
membership row no longer matches the original Cohort. With Evidence append-only
and supersede as the only remedy, a mis-graded learner who is later transferred
can never be corrected. This should be an explicit Owner decision rather than an
emergent consequence.

**N5 — `occurred_at` parsing is not strict.** `CarbonImmutable::parse()`
(`TeacherJudgmentService.php:217`) accepts relative expressions such as `now` or
`+1 day` and raises `InvalidFormatException` rather than a `DomainException` on
malformed input. Acceptable for an internal caller; must become strict format
validation before an external surface exists.

**N6 — observation, not a defect.** Nothing binds the Learning Node to the
Cohort's Product or Course Template Version. A teacher may judge their own
learner against any published Node in the tenant. This matches the design
document, which requires only tenant and Framework coherence, but it should be
recorded as an accepted consequence rather than left implicit.

**N7 — observation.** Enrollment `access_starts_at` / `access_ends_at` are not
consulted; only `status === 'active'` is, as the design document specifies.
Recorded to confirm the omission is deliberate.

---

## What The Implementation Gets Right

Recorded so the findings above carry proportion, not to balance them.

* The migration is the strongest of the three artifacts. Ten composite
  tenant-safe foreign keys are created atomically with the table;
  `assertPrerequisites()` verifies *exact* parent candidate keys rather than
  index names; schema-wide trigger-name vacancy is checked before installation;
  rollback refuses a non-empty table or surviving Teacher Judgment Evidence; and
  `assertInstalledTriggerIdentity()` compares normalized trigger bodies before
  dropping any physical object.
* `idx_ltj_003 UNIQUE (customer_id, supersedes_judgment_id)` makes the
  correction chain linear physically, while MySQL's repeated-NULL semantics
  leave initial judgments unconstrained. `idx_lrn_024` does the same for
  Evidence, and `idx_lrn_022` / `idx_lrn_029` back the producer and calculation
  idempotency keys.
* Lock order in `submit()` is fixed, and `findExisting()` is called a **second**
  time after all locks are held (`TeacherJudgmentService.php:47`) — the correct
  handling of concurrent double submission.
* `chk_ltj_005`, `trg_ltj_bu_immutable`, `trg_ltj_bd_immutable` and
  `trg_lrn_evidence_bi_validate` cover exactly the rules the design document
  allocates to the database.
* Profile identity — tenant, user, Node Definition, basis Framework Version —
  matches `LF-Core-Learning.md` § Mastery Boundary exactly, and the projector
  orders on `[calculated_at, id]`, so no tie is possible.
* `assertScaleResult()` closes both directions: the level must exist in the
  basis snapshot, and when a score is present the level must be the band that
  score falls into, so a contradictory level/score pair cannot be recorded.

---

## Reviewer Recommendation On The Timezone Contract

The Architecture Owner decides; this section records the reviewer's
recommendation and its reasoning, not a decision.

Two options were considered:

* **(a)** the Phase 4E runtime adopts the system convention — drop `'UTC'`, use
  `now()`, parse naive values in the application timezone;
* **(b)** the whole Learning domain moves to true UTC, normalizing `joined_at`
  and every Course timestamp on read.

Recommended: **(a)**.

The controlling risk is heterogeneity, not the choice of zone. A database that
uses one convention throughout retains the option of a single wholesale
conversion later. A database in which exactly one immutable table speaks a
different convention can never be reconciled, because the rows do not record
which convention produced them. Option (b) does not remove the skew; it relocates
it from one write site to every read site that touches a Course timestamp,
multiplying the opportunities for error and making each one depend on an
assumption about `config/app.php` at the time a *different* domain wrote the row.

Two supporting observations: Vietnam observes no daylight saving, so local wall
clock is monotonic and has no repeated hour; and both `chk_ltj_005` and
`trg_lrn_evidence_bi_validate`'s `evaluated_at >= source_occurred_at` compare two
columns written under the same convention, so option (a) requires **no change to
the frozen physical contract** — no migration and no trigger edit.

One refinement if (a) is adopted: store wall clock, but do not let the future API
accept naive input. Require ISO-8601 with an explicit offset and convert to the
application timezone before writing. Ambiguous storage is tolerable because the
whole database is already ambiguous in the same way; an ambiguous *input*
contract is not, and it is where B1 originated.

## Author Acceptance

On 2026-08-17 the author independently re-verified B1 through B4 against the
source and accepted all four as defects. The author additionally identified a
fifth affected site not found by this review —
`LearningFrameworkAuthoringService.php:335`, which repeats the B1 pattern for
`created_at` and `updated_at` — and confirmed that B2 is a consequence of B1
rather than an independent defect. The author withdrew the claim that all seven
application rules lack a database backstop.

Work division agreed at acceptance: B3, B4 and the B5 test gap proceed
immediately and independently of the timezone decision; B1 and B2 wait on the
Owner decision recorded above.

## Required To Close Gate 1

1. Owner decision on the timezone contract, recorded in the Phase 4E design
   document, then B1 and B2 implemented against it.
2. B3 remedied — reject a mismatching correction `occurred_at` instead of
   overwriting it.
3. B4 resolved — implement the Cohort end-boundary submission rule, or amend the
   design document if the Owner revises the rule. The two must not diverge.
4. B5 closed — extend the negative matrix to § Required Negative Matrix and
   replace prefix assertions with exact error codes per rule.
5. N1 decided before any route, controller or UI reaches
   `LearningFrameworkAuthoringService`.

Gate 2 — external surface authorization — must not open before items 1 to 5
close. B1 and B2 in particular write mis-conventioned values into an append-only
table; the cost of correcting them after production writes is not a commit.

---

## Re-Review — 2026-08-17

Scope: commit `9f2cea9` (unpushed) plus the working state of the three reviewed
artifacts and both MariaDB suites. Verified by reading the code, not the
remediation summary.

```text
GATE 1 REMAINS FAIL — narrowed. B1 to B4 are closed in code.
                      B5 is partially closed and cannot be signed off.
```

| Finding | Re-review result |
| --- | --- |
| B1 timestamp convention | **Closed in code.** Six sites verified: `submitted_at`, inbound `occurred_at` and `timestamp()` in `TeacherJudgmentService`; `now()` in `LearningFrameworkAuthoringService`; `projected_at` and `orderingTime()` in `LearningMasteryProfileProjector`. No `'UTC'` remains in the three services. `timestamp()` parses stored values in the application timezone and formats without conversion, so it normalizes rather than shifts — correct. The offset gate `/(Z\|[+-]\d{2}:?\d{2})$/` accepts `Z`, `±HH:MM` and `±HHMM`, and correctly rejects a bare `YYYY-MM-DD`, whose trailing `-08-15` does not satisfy the pattern. One verification outstanding: see R3. |
| B2 date windows | **Closed** as a consequence of B1. |
| B3 correction rewrite | **Closed in code.** The assignment is gone and `lockPrior()` still precedes `lockAndAuthorize()`, so the assignment window is evaluated against the caller's own `occurred_at`, which a correction must now match. Test gap: see R1. |
| B4 Cohort end boundary | **Closed in code**, and the implementation avoids the trap this check invites: it compares `substr($submittedAt, 0, 10)` against a `DATE`-typed `end_date` rather than a datetime against a date, so the final day of the Cohort is not wrongly refused. Placement after the replay short-circuit is also correct — an idempotent retry submitted after the Cohort closes replays instead of raising the new code. Untested: see R2. |
| B5 test gaps | **Partially closed.** Closed: the five fail-closed cases now assert exact codes; the teacher role rule, the offset contract and the correction moment each gained a test. Not closed: see R1 and R2 below and the list that follows. |

Required Negative Matrix items still uncovered in
`TeacherJudgmentRuntimeMariaDbTest`, listed by the error code no test reaches:

* `LF_TEACHER_JUDGMENT_COHORT_WINDOW_CLOSED` — the rule B4 added in this commit
* `LF_TEACHER_JUDGMENT_RESULT_INVALID`, `..._SCORE_INVALID`, `..._SCALE_INVALID`
  — "invalid level/score"
* `LF_TEACHER_JUDGMENT_UUID_INVALID` — "malformed producer UUID"
* `LF_TEACHER_JUDGMENT_FUTURE_OCCURRENCE`
* the unassigned-but-active teacher branch of the assignment guard, named
  directly by Owner decision 4; the new role test covers `customer_admin`,
  `student` and an inactive teacher, but not a valid teacher without the
  assignment
* the `assigned_from` / `assigned_to` range branch
* cross-tenant actor, learner, Cohort, assignment, membership, Node and basis
* tenant-safe but mutually inconsistent rows
* double-submit concurrency

### Re-Review Findings

**R1 — the operative symptom of B3 has no regression test.** B3's third and most
consequential effect was that a valid retry of a *successful correction* failed
with `LF_TEACHER_JUDGMENT_IDEMPOTENCY_CONFLICT`. `test_correction_may_not_move_the_judged_moment`
covers the rejection of a moved moment, and
`test_submit_replay_and_correction_are_atomic_and_append_only` replays the
*initial* judgment, but no test resubmits a correction. The behaviour is correct
today; nothing pins it. Reintroducing any normalization of `occurred_at` on the
correction path leaves the suite green. One resubmission of the existing
correction command asserting `replayed === true` and an unchanged row count
closes it.

**R2 — B4 introduced an authorization rule with no test.** The commit that
closes a finding about missing negative coverage adds
`LF_TEACHER_JUDGMENT_COHORT_WINDOW_CLOSED` and covers it nowhere. The rule is
correctly implemented; it is simply unproven, and it is the one rule that
governs writes after a Cohort has closed.

**R3 — no evidence that the pre-fix convention left no rows behind.** The fix is
forward-only. Nothing in the commit or the verification record establishes that
`core_liveclass_teacher_judgments`, `core_learning_evidence`,
`core_learning_mastery_calculations`, `core_learning_mastery_profiles` and the
four authoring tables hold no row written under the old UTC convention on
`learnforge_db`. If any exists, the database is now in exactly the mixed state
B1 described as unreconcilable, and no row records which convention produced it.
A row count per table closes this; a non-zero count needs an Owner decision
before Gate 2, not a code change.

**R4 — the input contract is guarded but not yet strict (N5, still open).** A
string that carries an offset but is otherwise malformed passes the regex and
raises `InvalidFormatException` rather than a domain error. A two-digit ISO-8601
offset such as `+07` is valid and is refused with
`LF_TEACHER_JUDGMENT_OCCURRED_AT_OFFSET_REQUIRED`, which misdescribes the cause.
Both are fail-closed and neither blocks Gate 1, but both must close before an
external surface exists, because the caller then owns this string.

### On The Disclosed Error-Code Collision

Stating that `cohort` and `assignment` both raise `ASSIGNMENT_DENIED` is the
right instinct, and better than a prefix match hiding it. The reviewer's position
is nonetheless that it should be split rather than documented: B5 exists so that
each of the seven rules is independently provable, and while these two share a
code, rules 2 and 3 are not. Moving the four Cohort conditions into their own
guard with `LF_TEACHER_JUDGMENT_COHORT_DENIED` is mechanical and changes no
behaviour.

The argument that a caller cannot distinguish them either is an argument about
the external surface, not the internal service — and it points the other way:
keep the internal codes precise and collapse them to one opaque code at the API
boundary, rather than losing the distinction at the point where it is enforced.

### Unchanged Since Version 1.0

N1 through N4 and N6 through N7 stand as written. N1 — `assertActor()` still
admits any active user — remains the blocking precondition for Gate 2, and is an
Owner decision rather than a defect.

### To Close Gate 1

1. R1 and R2 tested, plus the Required Negative Matrix items listed above.
2. R3 answered with a row count per affected table.
3. Recommended, not required: split the Cohort guard per the section above.

R4 and N1 are Gate 2 conditions and do not hold Gate 1.

---

## Owner

Architecture Team

## Primary Consumers

* Architecture Owner
* Developer
* Reviewer
