# Centralized Media Library and Teacher Sharing

Version: 1.0

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-10-06

Document Path: quality/LF-Media-Centralized-Library-Change-Proposal.md

## Approval and gate

Owner approved centralized management, Course selection and three-level categories in the conversation on 2026-10-06. Teacher sharing was explicitly requested. This approval is requirements approval; the database design below and Foundation amendment still require approval before migration under AGENTS.md. No migration or production implementation is included in this proposal.

Audit Level: HIGH. Changes affect Course × Media, authorization, schema and compatibility. Architecture review outcome: pending approval; schema and permission design must be ratified before implementation. This document does not claim an independent review or a passed migration gate.

## Verified current behavior

- `admin_fe/pages/master_korean_live/media.tsx` re-exports `b2b_workspace/media.tsx`.
- Reference sharing values are `private`, `selected`, `center`. `center` means sharing within the business, not anonymous Internet access. The reference master admin create form currently offers only private; LF's requested sharing is therefore an intentional extension, not an exact copy of that restriction.
- LF Media categories already have tenant-owned `parent_id`; files already have `category_id`, `uploaded_by` and `visibility`. No teacher-share table exists in the current documented model.
- LF library currently exposes listing and deletion; Course forms upload directly through MediaService.
- MediaService owns checksum deduplication, private storage, virus scanning and generic usages. Processing is activated through authorized Activity attachment.
- Existing signed delivery is a tenant-scoped bearer URL validated by signature; it has no library ACL check. Course preview authorizers remain a separate owner-context boundary.
- Approved Media upload policy currently requires upload at point of use. An amendment must explicitly supersede that policy for Course fields.

## Requested behavior and proposed decisions

1. Upload and manage files in Media. Course Category/Template/Product and Activity file fields select existing compatible, ready files. Existing embedded URLs retain their owner-domain behavior.
2. Central library supports image, video, audio and document assets, name, description, category, preview, usage inspection and sharing. Quiz, meeting and e-learning package examples from the reference remain owned by Assessment/LiveClass/Course; they must not be represented as fake binary Media Files. Moving their creation into a unified resource catalog is a separate domain design, not implied by this file-library change.
3. Categories form a visual tree with a maximum of three levels. Moving a parent must count its entire descendant subtree; reject cycles, fourth levels and cross-tenant parents. Existing deeper trees are not truncated or silently rewritten; inventory them before activation and block incompatible moves while retaining access to historical records.
4. Sharing choices: private, public within the tenant, selected teachers. UI should explain that public means the tenant's authoring library. It does not create anonymous access or cross-tenant visibility.
5. Private assets are selectable by their uploader. Tenant customer_admin retains administrative management access, with that exception stated in the UI. Teachers see their own, tenant-public and explicitly shared assets. Sharing grants view/select, not edit/delete/re-share authority. Only uploader or tenant admin manages an asset; only admin manages category taxonomy.
6. Students do not browse the authoring library. Their access derives from existing Course/Enrollment authorization. Revoking a teacher share prevents new selection; it does not detach existing usages, rewrite published snapshots or remove student access to an already authorized course.
7. Removing an asset from a Course detaches usage only. Remove automatic deletion of now-unused source files from owner flows; deletion remains an explicit Media lifecycle operation protected by usage and retention rules.
8. Preserve existing `media_files.visibility` semantics (`private`, `organization`, `public`). Library sharing is a distinct field, because existing delivery visibility must not be repurposed silently.

## Database design for approval

### Additive column on media_files

`library_scope VARCHAR(32) NOT NULL DEFAULT 'private'`, allowed values `private`, `tenant`, `selected`. Add `(customer_id, library_scope)` index. This expresses authoring discovery/reuse, not storage access. Keep `uploaded_by` as owner and keep legacy visibility unchanged.

Legacy records start private. No visibility-to-library-scope backfill and no fabricated shares. Tenant admins can manage them; existing Course usages continue to work.

### New table media_file_teacher_shares

| Field | Definition |
| --- | --- |
| id | BIGINT UNSIGNED primary key |
| customer_id | BIGINT UNSIGNED NOT NULL |
| media_file_id | BIGINT UNSIGNED NOT NULL |
| teacher_id | BIGINT UNSIGNED NOT NULL, users.id |
| created_by | BIGINT UNSIGNED NOT NULL, users.id |
| created_at / updated_at | timestamps |

Unique `(customer_id, media_file_id, teacher_id)`; lookup index `(customer_id, teacher_id, media_file_id)`. Enforce same-tenant file and users using composite foreign keys where supported; add required `(id, customer_id)` parent unique indexes after verifying existing constraints. Validate selected users have role `teacher` and status `active` on write and access; do not grant authority to a deactivated user or a user whose role has changed. Deletion policy must preserve asset/usage history; shares may be removed explicitly when scope changes.

Select scope requires at least one teacher; other scopes have no share rows. Saving scope and share rows is atomic under an asset row lock. Share identifiers never live in free-form metadata JSON. No storage folder table or binary replacement is introduced.

### Deduplication boundary

Use the existing tenant checksum/MIME/size strategy. If upload returns another user's identical asset, do not overwrite its owner, category, description or sharing and do not disclose it without library access. Return a generic validation error if inaccessible. If accessible, allow reuse; metadata/sharing changes require management authority. Do not create another physical file or circumvent deduplication.

## Implementation and compatibility plan

- Ratify an ADR-0004 amendment, upload-policy exception and the database design; update canonical documentation after approval. Record the architecture review result before migration creation.
- Introduce one library authorization service used by list/search, preview, upload deduplication, metadata/share updates and all Course selectors. Never rely on hidden UI options to enforce access.
- Add admin CRUD and teacher-owned file CRUD, shared browsing and a reusable searchable picker with category paths and type filters. Authorize category and teacher options in tenant context.
- Selector requests send a file ID; the owner service validates library permission, ready state, type/MIME and owner permissions before attaching via MediaService. Preserve processing locale, STT qualification and structured-extraction choices at Activity attach.
- Cover Course Category images, Template cover/introduction, Product cover/introduction and Activity video/audio/document. Preserve existing attached files even if the current actor cannot newly discover them; removal/replacement still requires owner permission. Do not alter published Version records.
- Distinguish library preview grants from Course delivery grants. New library preview links must be issued only after ACL validation; revocation behavior for already issued signed URLs must be explicitly tested and documented. Do not add global ACL checks to delivery that break authorized Course consumers.
- Keep existing internal upload services for other domains. Define a transition for legacy Course upload submissions, with existing API/test consumers identified before removal. UI becomes select-only; do not silently break external contracts.
- Migration execution and deployment are separate from migration creation. Do not run a destructive migration or backfill production data.

## Verification requirements

- Baseline: MediaCategoryManagementTest, MediaLibraryManagementTest, CourseMediaIntegrationTest.
- Baseline executed on 2026-10-06: `php artisan test tests/Feature/MediaCategoryManagementTest.php tests/Feature/MediaLibraryManagementTest.php tests/Feature/CourseMediaIntegrationTest.php` — 90 passed, 885 assertions. This verifies current behavior only, not the proposed implementation. The existing test requiring exclusive source deletion after Activity deletion must be deliberately updated for centralized retention.
- Cross-tenant parent/file/teacher tampering; private/tenant/selected scopes; multi-teacher sharing; share revoke; inactive/wrong-role users; owner/admin management and teacher read-only sharing.
- Create/move depth limits, descendant depth, cycles, archived parents and legacy deeper data.
- Duplicate upload with accessible and inaccessible existing owners; metadata preservation; transaction rollback.
- Select wrong type/not-ready/inaccessible files; attach idempotency; processing activation; detach without source deletion.
- Existing published snapshots, duplicate-to-draft, enrollment delivery and share changes preserve historical behavior.
- UI checks for upload/edit, category tree and picker search, Vietnamese/English translations, errors and keyboard use.
- Required HIGH checks: targeted/shared tests, full `php artisan test`, Pint, `npm run build`, route inspection and `git diff --check`; record failures or unavailable checks honestly.

## Findings and verdict

Findings By Severity: BLOCKER — schema approval and Foundation amendment for library sharing are not yet recorded. Public meaning is proposed as tenant-wide, matching the reference `center` behavior.

Final Verdict: BLOCKED for migration/production implementation; requirements and design preparation completed. No code or migration has been changed.
