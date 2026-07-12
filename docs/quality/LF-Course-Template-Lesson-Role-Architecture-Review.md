# Course Template Lesson Role Architecture Review

Version: 1.0

Status: Approved Review

Review Date: 2026-07-12

## Scope

Add tenant-owned semantic `lesson_type` to working and immutable Version
Lessons, with publish and duplicate mappings. Product approval is recorded in
the implementation request for this Foundation amendment.

## Review

- Domain boundary: PASS — classification remains Course-owned metadata.
- Tenant isolation: PASS — no ownership or query boundary changes.
- Versioning: PASS — publish freezes the value; duplicate reads without
  mutating the Version.
- Cross-domain effects: PASS — no scheduling, grading, completion, unlock,
  Assessment, Activity or Cohort behavior is introduced.
- Database: PASS — additive non-null VARCHAR(50), safe `regular` default and
  reversible removal on rollback.
- Documentation: PASS — Course, draft/version database contracts and ADR-0012/
  ADR-0013 amendments define the complete boundary.

## Decision

Foundation Ready — additive migration and implementation authorized for
`lesson_type` only.

Owner Approval: Product-approved prompt, 2026-07-12.
