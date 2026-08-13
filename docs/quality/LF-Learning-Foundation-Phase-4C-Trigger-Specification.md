# Learning Foundation Phase 4C Trigger Specification

Version: 0.9

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-08-13

Document Path: quality/LF-Learning-Foundation-Phase-4C-Trigger-Specification.md

---

## Purpose And Authority

This review artifact specifies the 24 Learning Foundation database triggers
required by ADR-0016 and the Frozen Phase 3 table contract. It is not a
migration authorization and does not change a Frozen table document.

No trigger or Learning table may be implemented from this draft until an
independent review returns PASS and the Architecture Owner records combined
Phase 4B/4C implementation authorization.

## Scope And Non-goals

In scope: exact trigger identity, reject ordering, stable error code, JSON
contract, transaction assumption and negative verification.

Out of scope: migrations, models, services, routes, UI, AI, Track, production
deployment and any Evidence source other than `teacher_judgment`.

## Execution Contract

* Engine baseline is MariaDB 11.4. Compatibility must also be verified against
  the repository's approved MySQL floor before implementation approval.
* Every trigger is `FOR EACH ROW` and executes in the caller transaction.
* Every rejection uses `SIGNAL SQLSTATE '45000'` with one stable message below.
* FK and CHECK constraints run independently. Triggers must fail closed and
  must not repair, normalize or silently discard input.
* Cross-table reads include all identity columns (`id`, `customer_id`, and
  Framework/user keys where applicable). No cross-tenant fallback is allowed.
* Trigger bodies contain no dynamic SQL, autonomous transaction, network call,
  cross-Domain generic-source lookup or write to another table.
* The migration must install all 24 triggers atomically with the ten tables in
  the combined 4B/4C gate. Partial trigger installation is a failed migration.

## Canonical JSON Paths

These paths make the previously narrative snapshot rules deterministic:

| Snapshot | Required paths |
| --- | --- |
| Mastery scale | `$.levels` array; each item has non-empty `$.key` and numeric `$.threshold` |
| Qualification rule | `$.rule_key`, `$.rule_version`, `$.source_type`; optional boolean `$.delayed_source_approved` |
| Calculation rule | `$.rule_key`, `$.rule_version` |
| Relation continuity policy | `$.policy`, `$.source_framework_version_id`, `$.target_framework_version_id` |
| Calculation carry-forward continuity | All relation continuity paths plus `$.relation_id` |

Mastery-scale levels must contain at least two rows, unique keys and strictly
increasing thresholds in array order. Numeric comparison uses DECIMAL(18,6).
Unknown required path, wrong JSON type, duplicate key or non-increasing
threshold fails closed.

## Stable Error Catalog

| Code | Meaning |
| --- | --- |
| `LF_SCALE_INVALID` | Scale JSON/ordering/key contract invalid |
| `LF_FRAMEWORK_LIFECYCLE_INVALID` | Framework archive transition/audit invalid |
| `LF_VERSION_LIFECYCLE_INVALID` | Version lifecycle or lifecycle audit invalid |
| `LF_VERSION_IMMUTABLE` | Published Version payload mutation attempted |
| `LF_VERSION_DELETE_FORBIDDEN` | Non-draft Version deletion attempted |
| `LF_NODE_IDENTITY_IMMUTABLE` | Node identity mutation attempted |
| `LF_NODE_IMMUTABLE` | Published Node mutation attempted |
| `LF_NODE_DELETE_FORBIDDEN` | Published Node deletion attempted |
| `LF_DEFINITION_IDENTITY_IMMUTABLE` | Definition tenant/Framework mutation attempted |
| `LF_RELATION_INVALID` | Relation vocabulary/review/policy contract invalid |
| `LF_RELATION_CYCLE` | Forbidden prerequisite/part-of cycle detected |
| `LF_RELATION_IMMUTABLE` | Relation mutation outside its one allowed transition |
| `LF_RELATION_DELETE_FORBIDDEN` | Published-owner relation deletion attempted |
| `LF_MAPPING_IMMUTABLE` | Mapping mutation/delete outside one invalidation |
| `LF_EVIDENCE_SOURCE_CLOSED` | Evidence source is not `teacher_judgment` |
| `LF_EVIDENCE_INVALID` | Evidence source/rule/correction contract invalid |
| `LF_EVIDENCE_IMMUTABLE` | Evidence update/delete attempted |
| `LF_CALCULATION_INVALID` | Calculation source/rule/scale/continuity invalid |
| `LF_CALCULATION_IMMUTABLE` | Calculation update/delete attempted |
| `LF_CALC_EVIDENCE_INVALID` | Calculation–Evidence lineage invalid |
| `LF_CALC_EVIDENCE_IMMUTABLE` | Calculation Evidence update/delete attempted |
| `LF_PROFILE_MISMATCH` | Profile values do not exactly project its Calculation |
| `LF_PROFILE_STALE` | Profile would regress deterministic Calculation ordering |

## Trigger Inventory And Normative Reject Order

The order listed for each trigger is normative. The first failed condition
supplies the stable error code. Exact SQL is generated from these predicates
without adding a permissive branch.

### Frameworks — 2

`trg_lrn_frameworks_bi_scale` — BEFORE INSERT:

1. Reject invalid mastery-scale structure/ordering with `LF_SCALE_INVALID`.
2. Reject any status other than `active`, or non-null archive audit, with
   `LF_FRAMEWORK_LIFECYCLE_INVALID`.

`trg_lrn_frameworks_bu_scale` — BEFORE UPDATE:

1. Reject invalid mastery scale with `LF_SCALE_INVALID`.
2. Permit `active → active` only with null archive audit.
3. Permit `active → archived` only with non-null `archived_at` and
   `archived_by`.
4. Reject every `archived → active` or audit rewrite with
   `LF_FRAMEWORK_LIFECYCLE_INVALID`.

### Framework Versions — 3

`trg_lrn_fw_versions_bi_validate` — BEFORE INSERT:

1. Validate mastery scale.
2. Require `draft_snapshot` with publish/deprecate/archive audit columns null.
3. Reject otherwise with `LF_VERSION_LIFECYCLE_INVALID`.

`trg_lrn_fw_versions_bu_immutable` — BEFORE UPDATE:

1. Always reject change to `customer_id`, `framework_id`, `version_number`,
   `version_code`, `created_by` or `created_at`.
2. While OLD is `draft_snapshot`, permit draft payload edits or exactly one
   transition to `published`; publish requires `published_at/published_by` and
   null deprecate/archive audit.
3. After publish, require every snapshot/business field byte-for-byte equal.
4. Permit only `published → deprecated` and `deprecated → archived`, with the
   matching new audit pair and all earlier audit pairs unchanged.
5. Reject all other cases with `LF_VERSION_IMMUTABLE` or
   `LF_VERSION_LIFECYCLE_INVALID` as applicable.

`trg_lrn_fw_versions_bd_immutable` — BEFORE DELETE:

* Permit only `draft_snapshot`; otherwise signal
  `LF_VERSION_DELETE_FORBIDDEN`.

### Node Definitions — 1

`trg_lrn_definitions_bu_identity` — BEFORE UPDATE:

* Reject any change to `customer_id` or `framework_id` with
  `LF_DEFINITION_IDENTITY_IMMUTABLE`; other authoring fields remain governed by
  CHECK/FK/application authorization.

### Nodes — 2

`trg_lrn_nodes_bu_immutable` — BEFORE UPDATE:

1. Always reject change to tenant, Framework, Version or Definition identity
   with `LF_NODE_IDENTITY_IMMUTABLE`.
2. Read the exact owning Framework Version by the composite identity.
3. Permit remaining edits only while the Version is `draft_snapshot`.
4. Otherwise signal `LF_NODE_IMMUTABLE`.

`trg_lrn_nodes_bd_immutable` — BEFORE DELETE:

* Permit only when the exact owning Version is `draft_snapshot`; otherwise
  signal `LF_NODE_DELETE_FORBIDDEN`.

### Node Relations — 3

`trg_lrn_relations_bi_validate` — BEFORE INSERT:

1. Validate scope/type, endpoint/version/owner relationship, continuity fields
   and complete review/approval audit; reject with `LF_RELATION_INVALID`.
2. For `semantic` `prerequisite` or `part_of`, reject if the target already
   reaches the source through the same relation type, tenant and Framework
   Version. The recursive graph search must be bounded by the finite Node set;
   signal `LF_RELATION_CYCLE`.
3. `supports` and all transition types do not run cycle rejection.

`trg_lrn_relations_bu_immutable` — BEFORE UPDATE:

1. Always freeze tenant, Framework, owner, scope/type, endpoints, endpoint
   Versions, continuity policy/key/version/snapshot and creator fields.
2. While owning Version is draft, permit only non-identity authoring updates
   that continue to satisfy the full relation/review predicate.
3. After publish, permit exactly one `pending → approved|rejected` transition.
   It must populate `reviewed_by`, `reviewed_at`, non-empty `review_reason`; an
   approval also requires matching `approved_by/approved_at` and a resolved
   policy in `no_carry_forward|allow_as_input|carry_forward`; rejection requires
   approval fields and resolved policy null.
4. Reject all other updates with `LF_RELATION_IMMUTABLE`.

`trg_lrn_relations_bd_immutable` — BEFORE DELETE:

* Permit only while owning Version is draft; otherwise signal
  `LF_RELATION_DELETE_FORBIDDEN`.

### Node Mappings — 2

`trg_lrn_mappings_bu_lifecycle` — BEFORE UPDATE:

1. Require all semantic/identity/creator fields equal to OLD.
2. Permit exactly one transition where all invalidation fields change from null
   to non-null and reason is non-empty.
3. Reject every other update with `LF_MAPPING_IMMUTABLE`.

`trg_lrn_mappings_bd_immutable` — BEFORE DELETE:

* Always signal `LF_MAPPING_IMMUTABLE`.

### Evidence — 3

`trg_lrn_evidence_bi_validate` — BEFORE INSERT:

1. Require `source_type = 'teacher_judgment'`; explicitly reject
   `track_events`, `behavioral_signal` and every other token with
   `LF_EVIDENCE_SOURCE_CLOSED`.
2. Require `evidence_type = 'expert_judgment'`, non-null `recorded_by`, positive
   `source_id`, non-empty discriminator/idempotency/rule keys, valid rule JSON,
   and exact snapshot key/version/source equality.
3. Require `evaluated_at >= source_occurred_at` unless snapshot boolean
   `$.delayed_source_approved` is true.
4. If superseding, require the exact prior Evidence to exist for the same
   tenant/user and forbid a self-reference.
5. Reject invalid cases with `LF_EVIDENCE_INVALID`.

`trg_lrn_evidence_bu_immutable` and
`trg_lrn_evidence_bd_immutable` — BEFORE UPDATE/DELETE:

* Always signal `LF_EVIDENCE_IMMUTABLE`.

### Mastery Calculations — 3

`trg_lrn_calcs_bi_validate` — BEFORE INSERT:

1. Lock/read the exact basis Version; require its scale key/version/snapshot to
   equal NEW and require `mastery_level_key` to exist in `$.levels`.
2. Require calculation rule snapshot key/version equality.
3. `system`: require null actor/reason/relation/continuity; source Calculation
   is optional correction lineage.
4. `teacher_override`: require actor and non-empty reason; forbid relation and
   continuity payload.
5. `carry_forward`: require source Calculation, approved transition relation,
   relation target equal basis Version, effective policy allowing input/carry,
   and exact continuity snapshot paths.
6. Reject with `LF_CALCULATION_INVALID`.

`trg_lrn_calcs_bu_immutable` and `trg_lrn_calcs_bd_immutable` — BEFORE
UPDATE/DELETE:

* Always signal `LF_CALCULATION_IMMUTABLE`.

### Calculation Evidence — 3

`trg_lrn_calc_evidence_bi_validate` — BEFORE INSERT:

1. Resolve Evidence Node to its stable Definition.
2. Permit direct lineage when that Definition equals the Calculation
   Definition.
3. Otherwise require Calculation source `carry_forward`, its exact approved
   relation, source/target Definitions matching Evidence/Calculation, and an
   effective policy permitting the declared `continuity_input` role.
4. Reject every other path with `LF_CALC_EVIDENCE_INVALID`.

`trg_lrn_calc_evidence_bu_immutable` and
`trg_lrn_calc_evidence_bd_immutable` — BEFORE UPDATE/DELETE:

* Always signal `LF_CALC_EVIDENCE_IMMUTABLE`.

### Mastery Profiles — 2

Both profile triggers read the exact `current_calculation_id` using the full
tenant/user/Definition/basis identity and require level, null-safe score,
status, calculated time and reassessment time to match it exactly.

`trg_lrn_profiles_bi_projection` — BEFORE INSERT:

* Reject mismatch with `LF_PROFILE_MISMATCH`.

`trg_lrn_profiles_bu_projection` — BEFORE UPDATE:

1. Freeze tenant/user/Framework/Definition/basis identity.
2. Apply the exact projection-match predicate.
3. Require NEW Calculation ordering `(calculated_at, id)` to be greater than or
   equal to OLD; equality is an idempotent replay only when all projected values
   match. Reject regression with `LF_PROFILE_STALE`.

## Normalized SQL Requirements

The exact candidate statements are maintained in
[LF-Learning-Foundation-Phase-4C-Trigger-Bodies.sql](LF-Learning-Foundation-Phase-4C-Trigger-Bodies.sql).
The appendix contains exactly 24 `CREATE TRIGGER` statements and is review-only.
It must not be copied to a migration until the checks below pass.

Independent review must convert each predicate above into an exact standalone
`BEGIN ... END` body and attach the resulting normalized SQL digest. Approval
is prohibited while any body contains `PLANNED CONTRACT`, pseudocode,
unspecified JSON path, generic message text or a permissive `ELSE` branch.

The approved body normalization is: lowercase SQL keywords, backtick-free
canonical identifiers, one ASCII space between tokens, no comments, no
delimiter statement and one terminal semicolon per statement. Schema drift
compares the normalized executable body and trigger identity.

The canonical appendix is not a mysql-client input file. The official rehearsal
harness extracts each complete `CREATE TRIGGER ... BEGIN ... END` statement and
submits it separately through PDO/`DB::unprepared()`. A generated CLI wrapper
may add `DELIMITER`, but wrapper directives are never canonical and never enter
the schema-drift digest.

## Required Negative-Test Matrix

Minimum matrix:

| Area | Required failures |
| --- | --- |
| Scale | malformed JSON, missing levels, duplicate key, descending/equal threshold |
| Version | skipped/reversed lifecycle, missing actor/time, published payload mutation/delete |
| Node/Definition | cross-identity update, published update/delete |
| Relation | cross-scope vocabulary, wrong owner Version, cycle, second review resolution, published delete |
| Mapping | semantic edit, partial invalidation, second invalidation, delete |
| Evidence | `track_events`, `behavioral_signal`, every non-teacher source, malformed rule, cross-user correction, update/delete |
| Calculation | scale/rule mismatch, invalid source fields, unapproved/wrong transition, update/delete |
| Calculation Evidence | cross-user/Definition lineage, unapproved continuity, update/delete |
| Profile | unrelated Calculation, value mismatch, stale ordering, identity mutation |

Every test asserts SQLSTATE `45000` and the exact stable error code. The suite
also proves CHECK enforcement, trigger body/identity drift, transaction rollback
and cleanup of the fresh `lf_schema_drift_*` database after success or failure.

## Review Gates

```text
Draft completeness: COMPLETE — 24 semantic bodies specified
Exact executable SQL bodies: REMEDIATED CANDIDATE
Static review Round 1: BLOCKED — findings remediated
Static re-review Round 2: PASS
MariaDB 10.4 representative matrix: PASS — 26 probes; cycle CTE engine gap isolated
MariaDB 11.4 baseline rehearsal: PASS — cycle CTE and 24-body install verified
Required JSON-path probes on MariaDB 11.4: PASS — 8/8
Hardened MariaDB 11.4 matrix: PASS — 57/57
Physical schema: PASS — 36 indexes, 51 foreign keys and CHECK constraints
Machine-readable evidence/digests: RECORDED
Independent technical review: PASS — 0 BLOCKER, 0 MAJOR
Architecture Owner approval: PENDING
Combined 4B/4C implementation authorization: NOT RECORDED
```

## Verdict

`TECHNICAL PASS; NOT AUTHORIZED FOR IMPLEMENTATION` — the retained matrix and
independent review pass. Architecture Owner authorization remains mandatory. No
migration is authorized.
