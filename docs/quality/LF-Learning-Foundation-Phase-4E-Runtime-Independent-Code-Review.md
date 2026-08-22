# Learning Foundation Phase 4E Runtime Independent Code Review

Version: 1.5

Document Status: Review

Implementation Status: Not Applicable

Review Status: Pass

Last Updated: 2026-08-22

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

## Third Pass — 2026-08-17 (commit `60e0c85`)

```text
GATE 1 REMAINS FAIL — code-clean. No code defect remains in the three
                      artifacts. What holds the gate is a finite test list.
```

Closed and verified by reading:

| Item | Result |
| --- | --- |
| R1 correction retry | **Closed.** The correction command is resubmitted and asserted `replayed === true` against the same `judgment_id`, with the row count unchanged. This is the branch that was never reached before. |
| R2 Cohort end boundary | **Closed**, and the fixture is built correctly: the Cohort period is shortened to `2026-08-01 → 2026-08-10` while the occurrence stays at `2026-08-05`, so the test proves the *new* submission bound rather than passing on the occurrence bound that already existed. |
| R3 legacy-convention rows | **Closed by data.** Zero rows across all nine affected tables on `learnforge_db`. Nothing to reconcile and no Owner decision arises. |
| R4 input contract | **Closed.** The anchored pattern was exercised independently on sixteen inputs, including four the remediation table does not list: `Z` with microseconds, the seconds-less `HH:MMZ` form, a whitespace-separated offset, and `+0700`. All sixteen behave correctly — `+07` accepted, bare `YYYY-MM-DD` refused, `not-a-date+07:00` refused at the regex as a domain error. |
| Cohort guard split | **Done.** `LF_TEACHER_JUDGMENT_COHORT_DENIED` separates rules 2 and 3, and the fail-closed matrix asserts the two distinct codes. |
| Unassigned teacher, assignment date range | **Covered** by `test_assignment_scope_and_range_fail_closed`. |

### Third-Pass Finding

**One verification claim is not supported by the repository.** The remediation
record states that every `LF_TEACHER_JUDGMENT_*` code is touched by at least one
test. Seven have zero occurrences anywhere under `tests/`:

```text
LF_TEACHER_JUDGMENT_FUTURE_OCCURRENCE     LF_TEACHER_JUDGMENT_RESULT_INVALID
LF_TEACHER_JUDGMENT_LINEAGE_INCOMPLETE    LF_TEACHER_JUDGMENT_SCALE_INVALID
LF_TEACHER_JUDGMENT_REQUIRED              LF_TEACHER_JUDGMENT_SCORE_INVALID
                                          LF_TEACHER_JUDGMENT_UUID_INVALID
```

Four of them — `RESULT_INVALID`, `SCORE_INVALID`, `SCALE_INVALID` and
`UUID_INVALID` — are the "invalid level/score" and "malformed producer UUID"
rows of § Required Negative Matrix, listed as open in Version 1.1 of this
document. Accepted at face value, the claim would have closed them without the
work being done. The code is unaffected; this is a finding about the
verification record, and it is the only finding of this pass.

### Still Required To Close Gate 1

Unchanged from Version 1.1 item 1, which required R1 and R2 *plus* the listed
Required Negative Matrix items. Two of those were closed this pass; these remain:

* `RESULT_INVALID`, `SCORE_INVALID`, `SCALE_INVALID` — invalid level or score
* `UUID_INVALID` — malformed producer UUID
* `FUTURE_OCCURRENCE`
* cross-tenant actor, learner, Cohort, assignment, membership, Node and basis
* tenant-safe but mutually inconsistent rows
* double-submit concurrency

The first three are input-validation paths reachable in a few lines each. The
last three are the substantial ones. No code change is expected for any of them.

Trivial, not a finding: the correction assertion block repeats
`assertSame(2, …->count())` on consecutive lines.

---

## Fourth Pass — 2026-08-17 (commit `1e6ad05`)

```text
GATE 1 PASS — the bar published in Version 1.1 and restated in Version 1.2
              is met. Gate 2 remains closed on its own conditions.
```

The commit touches tests and documentation only; no source file changed, so the
code verified in the second and third passes still stands. The set difference is
empty in both directions: all 18 service codes appear in `tests/`, and the only
code present in tests but not in the service is `LF_TEACHER_JUDGMENT_IMMUTABLE`,
which belongs to the two migration triggers.

Each new test was read rather than counted. They prove rules rather than touch
codes:

* `test_command_validation_rejects_malformed_input` separates the two
  `RESULT_INVALID` paths — an empty field rejected before any lookup, and a level
  outside the frozen basis scale rejected after it.
* `test_future_occurrence_is_rejected` places the occurrence inside both the
  Cohort period and the assignment range, so only the future rule can reject it.
* `test_correction_without_prior_lineage_is_rejected` builds an orphan source row
  whose `occurred_at` matches the command exactly, so it passes
  `priorIdentityMatches()` and actually reaches `lockPriorLineage()`.
* `test_cross_tenant_and_cross_inconsistent_rows_fail_closed` is the strongest
  addition: every identifier inside one tenant with the membership on a different
  Cohort is precisely what the cross-row equalities exist for and what no foreign
  key can express.
* `test_malformed_scale_snapshot_is_rejected_by_the_service_guard` reaches an
  otherwise unreachable guard by reflection and states why. It fails if the guard
  is deleted, which is the property that matters.
* `test_duplicate_producer_uuid_is_rejected_by_the_physical_key` asserts the
  physical key and states in the docblock that in-process parallelism is not
  exercised and why. Accepted as closed with that limitation recorded: a single
  connection inside one transaction cannot observe another connection's
  uncommitted rows, so a test shaped like concurrency would assert nothing. What
  would actually prove it is a two-process harness, which is out of scope here.

### Fourth-Pass Findings

**F1 — the MariaDB suite has a hard expiry date. Not blocking Gate 1; due before
Gate 2 and worth doing this week.** The fixture Cohort runs
`2026-08-01 → 2026-08-31` (`context()`), the default command occurs
`2026-08-15`, and no test pins the clock. B4 now refuses any submission whose
`submitted_at` date is past the Cohort `end_date`, so from **2026-09-01** every
happy-path test in the suite fails with
`LF_TEACHER_JUDGMENT_COHORT_WINDOW_CLOSED`. `test_future_occurrence_is_rejected`
breaks sooner — on **2026-08-30**, when `2026-08-30T09:00+07:00` stops being in
the future.

The failures are loud rather than silent, so nothing passes for a wrong reason.
The risk is what happens next: a suite that goes red for a reason unrelated to
any change invites the cheapest-looking repair, and the cheapest-looking repair
here is to relax the fixture window or the rule — and that rule is the one
preventing permanent Evidence writes after a Cohort closes.

The B4 remediation coupled the suite to wall-clock time, and this reviewer did
not catch it while verifying B4 in the second pass or its test in the third.
`Carbon::setTestNow()` in `setUp()`, or fixture dates derived from now, removes
the coupling.

**F2 — trivial.** `test_duplicate_producer_uuid_is_rejected_by_the_physical_key`
expects `QueryException` broadly. Asserting SQLSTATE `23000` or naming
`idx_ltj_001` would pin which constraint did the work.

### On Not Moving The Bar

F1 is a real defect in the verification apparatus and was found in the same pass
that closes the gate. It is recorded as a Gate 2 item rather than used to hold
Gate 1, because the closing conditions were published in advance, in writing, in
Version 1.1, and they are met. A reviewer who adds conditions once they are
satisfied makes the gate unfalsifiable, and an unfalsifiable gate is a worse
failure than a dated fixture.

### Gate 1 Verdict

```text
Role: Independent Reviewer
Date: 2026-08-17
Decision: GATE 1 PASS — independent runtime and migration code review complete.
Basis:   B1-B5 closed and verified by reading; R1-R4 closed; the Required
         Negative Matrix items enumerated in Version 1.1 are covered; R3
         answered with data.
Excluded: Gate 2 (external surface), production deployment. This verdict
          authorizes neither.
```

Owner acknowledgement of this document is a separate act; `Document Status`
stays `Review` until the Architecture Owner records it.

### Gate 2 Preconditions

Unchanged and still open, in the order they should be settled:

1. **N1** — `assertActor()` admits any active user, so a `student` principal
   could author and publish a Framework Version the day a route exists. This is
   an Owner decision on the authoring role, not a defect.
2. **F1** — remove the suite's dependency on wall-clock time.
3. **N2** — Framework `active` is unchecked on `createNode()` and
   `publishVersion()`, so archiving a Framework does not stop its draft version
   from being published into a valid judgment basis.
4. **N3** — authoring accepts mastery scales outside the `[0,1]` domain that
   judgments require.
5. **N4** — a Cohort transfer updates the membership row in place and
   permanently closes the supersede path for earlier judgments. Owner decision.
6. **F2**, and strict format validation at the HTTP boundary once a caller owns
   the request body.

---

## Authorship Change — 2026-08-17

At the Architecture Owner's direction, the Phase 4E external surface
(FormRequest, controller, route, UI) is being written by the same reviewer who
produced this document. The consequence is recorded here rather than left to be
noticed later:

**This session cannot close Gate 2.** A reviewer who authors the surface has the
same disqualification the Phase 4E design document already states for the
runtime — "Review by the author closes no gate however thorough it is." The Gate
2 review must be performed by a reviewer who did not write the surface; a fresh
session carrying none of this session's context satisfies that, a continuation of
this one does not.

Two things are unaffected. The **Gate 1 verdict stands**: it covered three
artifacts this reviewer did not write, the closing conditions were published in
advance, and they were met. And the **service layer remains the authorization
decider**: the surface may deny early for UX through `RoleMiddleware` and
`FormRequest::authorize()`, but `TeacherJudgmentService` and
`LearningFrameworkAuthoringService` keep every rule they enforce today. A later
change that removes a service check because "the route already checks" is a
regression, not a cleanup.

The first Gate 2 reviewer should read this section before anything else, and
should treat the surface with the same suspicion this document applied to the
runtime — starting with whether any of the seven application-owned rules got
restated, relaxed or bypassed at the HTTP boundary.

---

## Gate 2 Review — 2026-08-22 (commits `3150c98`, `67aafcf`, `8e695d8`)

The external surface anticipated by § Authorship Change now exists. This section
records what shipped, the Gate 2 review it received, and why Gate 2 is still not
closed. It is written for the independent reviewer that section calls for.

### On Finding Labels

The Gate 2 review numbered its findings `B1`–`B4` and `N1`–`N7`, colliding with
the Gate 1 labels above, which are different findings entirely. To keep the two
readable side by side, the Gate 2 findings are prefixed `G2-` throughout this
section. Two collisions are worth naming explicitly, because both concern rules
that sound similar:

* Gate 1 **N1** (`assertActor` admits any active user) is closed — `f6dd97d`
  restricted authoring to `customer_admin`. Gate 2 **G2-N1** is a different
  question: which layer owns the *read* path.
* Gate 1 **N3** (mastery scale value domain) is closed. Gate 2 **G2-N3** asks
  whether publishing should require at least one Node.

### What Shipped

* `3150c98` — FormRequest classes for the five authoring commands, plus
  `app/Rules/TimestampWithOffset.php`. `TeacherJudgmentService::OCCURRED_AT_OFFSET_PATTERN`
  became a public constant so the HTTP rule and the service guard share one
  pattern rather than drifting.
* `67aafcf` — `LearningFrameworkController`, `routes/modules/learning.php`, the
  Blade surface, and four new mutation methods on
  `LearningFrameworkAuthoringService`: `updateFramework`, `updateDraftVersion`,
  `updateDefinition`, `updateDraftNode`.
* `8e695d8` — the surface rendered in the admin design system.

The middle commit is the one that matters for this gate. It is **not** an
additive "surface only" change: `LearningFrameworkAuthoringService` changed by
+195/-4 lines and gained four mutation paths that did not previously exist.

### Gate 2 Verdict — FAIL

The architectural boundary held. ADR-0017 is respected, no write path reaches
Mapping, Evidence or Mastery, a published version is read-only at two layers
(service and `trg_lrn_nodes_bu_immutable`), and the tenant/framework/version
containment checks are transitive and closed. Most importantly for the question
this document told the Gate 2 reviewer to ask first: **no application-owned rule
was restated, relaxed or bypassed at the HTTP boundary.**

Four findings blocked the gate.

**G2-B1 — `updateDefinition()` had no database backstop.**
`trg_lrn_definitions_bu_identity` freezes only `customer_id` and `framework_id`;
`code`, `node_type` and `canonical_name` are unprotected, and `chk_lrn_006`
checks the enum but not the transition. Changing `node_type` on a Definition
already snapshotted into a published version silently changes the meaning of
every `core_learning_mastery_calculations` and `core_learning_mastery_profiles`
row anchored to it. The row-immutability trigger protects the row; it does not
protect the meaning of what the row points at.

**G2-B2 — the UI invited an edit the database forbids.** The Node edit form
rendered a `<select name="node_definition_id">`, while
`trg_lrn_nodes_bu_immutable` freezes that column. One click produced
`LF_NODE_IDENTITY_IMMUTABLE`, swallowed into a generic conflict message with no
code and no log line.

**G2-B3 — the stated verification did not cover the reviewed code.**
`phpunit.xml` registers only `tests/Unit` and `tests/Feature`, pins
`DB_CONNECTION=sqlite`, and the Learning migration returns early on a non-MySQL
driver, so the ten `core_learning_*` tables do not exist under the default
suite. "732 passed" was true and contained no assertion about this surface.

**G2-B4 — `catch (QueryException)` bound no variable and logged nothing.** Every
trigger-enforced invariant disappeared without trace.

Seven non-blocking findings were also raised, two of which are Owner decisions
rather than implementation defects; they are carried in § Gate 2 Remaining Items.

### Remediation And A Regression The Remediation Introduced

G2-B1, G2-B2, G2-B4 and two non-blocking items were remediated. G2-B1 is now an
application guard: identity freezes once a non-draft version references the
Definition, `description` stays editable, and draft Node snapshots are
synchronised in the same transaction. G2-B2 refuses a forged request with
`LF_FRAMEWORK_AUTHORING_NODE_DEFINITION_IMMUTABLE` before it can reach the
trigger. G2-B4 logs correlation ID, SQLSTATE, driver code, constraint, route,
tenant and actor.

The remediation pass reported itself verified. It was not. Running the
integration suite against a real MariaDB — which the pass had written 14 tests
for but never executed — produced **5 failed, 9 passed**:

```text
ErrorException: Undefined variable $node
  at app/Services/LearningFrameworkAuthoringService.php:308  (createNode)
```

A copy-paste error had replaced `createNode()`'s Definition lookup with
`updateDraftNode()`'s. `$node` does not exist in `createNode()`'s scope, so
`(int) null` = 0, `lockRow(..., 0)` threw `RecordsNotFoundException`, and the
controller returned **404 for every Node creation over HTTP** — the one
capability the service exists to provide. It was fixed alongside a second
trigger mismatch found in the same run: `version_code` is immutable
unconditionally, not merely after a version leaves draft, so
`updateDraftVersion()` now refuses the change with
`LF_FRAMEWORK_AUTHORING_VERSION_CODE_IMMUTABLE` instead of letting the form
offer an edit the database rejects.

**The lesson belongs in this document, not only in a commit message.** The
defect was invisible to every check the remediation ran, because all of them ran
on SQLite where these tables do not exist. Counting tests is not running them.
A claim of verification for Learning code is only meaningful when the connection
is MySQL or MariaDB; CI does this
(`.github/workflows/application-tests.yml`, job `integration-mysql`), the
default local suite does not.

### UI Pass — `8e695d8`

The surface shipped using class names with no CSS behind them —
`admin-page-header`, `admin-btn`, `admin-btn-primary` match zero rules, and
`admin-table` was used where the house pattern is `admin-table-wrap` around a
plain `table` — so the pages rendered as unstyled markup. `index` and `show`
also omitted `@section('page_title')`, making every page announce itself as the
dashboard. Both pages were rebuilt on the classes the other admin screens use.

Two defects surfaced only by loading the pages: `trans_choice` dropped the
count, because Vietnamese has one plural form and Laravel resolved to segment
zero after stripping its `{0}` condition; and the create action ran its glyph
into its label. Both fixed.

One thing was deliberately **not** done. Disabling the publish button when a
version carries no Node would settle G2-N3 at the UI layer while the decision is
still open, and would create the same UI/database mismatch as G2-B2 in the
opposite direction. The button stays enabled and the page warns instead.

### Verification Standing Behind This Section

* MariaDB integration suite, real connection: **14 passed, 83 assertions**.
* Default suite: **733 passed**, 8259 assertions.
* Both pages rendered through the HTTP kernel with `actingAs` against seeded
  data inside a rolled-back transaction — HTTP 200, Alpine initialised,
  collapsed forms expanding, a locked Definition still submitting its frozen
  identity through the hidden fields the service compares against, no
  horizontal overflow at 375px. No development row was written.
* Pint clean; `git diff --check` clean.

### Gate 2 Remaining Items

**Gate 2 is not closed.** Two independent reasons:

1. **No independent reviewer has read the surface.** The Gate 2 review recorded
   above was performed by a reviewer who then remediated the findings, and who
   had also written the roadmap that scoped the work. § Authorship Change
   already states the rule this violates. The next review must be by someone who
   wrote neither the surface nor its remediation.
2. **Two Owner decisions are open.**

   * **G2-N1 — read-service ownership.** `LearningFrameworkController::index()`
     and `show()` read `core_learning_*` directly through `DB::table()` with
     `TenantContext::customerId()`, never through `LearningRuntimeAccess`. The
     reads are tenant-scoped and correct; the question is whether the runtime
     access service should own them. As things stand
     `LearningRuntimeAccess::denyExternalRead()` has no production caller —
     verified 2026-08-22, its only reference is
     `tests/Feature/LearningRuntimeFoundationTest.php:86`.
   * **G2-N3 — whether publishing requires at least one Node.** Publishing is
     one-way and cannot be corrected, so a version published empty stays empty.
     The service does not refuse it and the UI only warns.

Neither is a defect. Both are decisions, and both should be settled before the
gate closes rather than discovered by the next reviewer.

---

## Owner

Architecture Team

## Primary Consumers

* Architecture Owner
* Developer
* Reviewer
