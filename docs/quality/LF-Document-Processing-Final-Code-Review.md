# Document Processing — Final Code Review

Version: 1.6

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-31

Document Path: quality/LF-Document-Processing-Final-Code-Review.md

## 1. Executive summary

**Review closure: CLOSED — theo xác nhận của Owner ngày 2026-08-31.** Đóng đợt sửa, review và kiểm chứng Document/Docling, gồm D1–D6 và smoke test tại §18–§20. Không còn finding hoặc approval implementation đang chờ trong phạm vi này. Không bổ sung công việc mới; các giới hạn vận hành đã công bố giữ nguyên và không được coi là chứng nhận production. Approval này áp dụng cho báo cáo, không thay các gate Foundation/deployment khác.

**Verdict: APPROVE — phạm vi Document local và D1–D6.** Không còn finding mở trong phạm vi sửa: **0 Critical, 0 High, 0 Medium, 0 Low**. Sáu quyết định được Owner duyệt ngày 2026-08-31 đã được đưa vào contract, migration, runtime và kiểm chứng. Không suy rộng thành nghiệm thu Audio/Video, toàn bộ Media hoặc production.

Evidence implementation chốt ở §18; trạng thái Git/database và smoke test sau khi Owner migrate nằm tại §20. Các mô tả lỗi, test đỏ và trạng thái chưa commit/migrate ở các mục lịch sử không phải trạng thái hiện tại. `.env` không thay đổi trong lượt kiểm chứng.

### Trạng thái riêng Document và Docling

| Phần | Đã xác minh trên local | Trạng thái hoàn tất |
| --- | --- | --- |
| Document OCR/text | PDF text/scan/mixed/blank, DOCX/DOC/PPTX/PPT/XLSX/XLS; tenant/locale/read/history/retry | **DONE trong scope local.** D1–D6 đã sửa và kiểm chứng trên SQLite + MariaDB disposable. |
| Docling structured extraction | PDF table/merged cells/chart/diagram, crop, canonical input, aggregate budget và metering | **DONE trong scope local.** Spreadsheet structure dùng native cells provider theo D2, không giả làm Docling PDF. |

Kết luận hiện tại: **Document Processing và Docling hoàn tất phần implementation và kiểm chứng local trong phạm vi báo cáo, bao gồm D1–D6 đã duyệt.** Không còn lỗi đã xác định hoặc quyết định chờ duyệt trong phạm vi sửa này. Đây không phải chứng nhận production readiness hoặc toàn bộ Media.

Validation output OCR, atomic readiness, terminal-state guard, lỗi subprocess, metadata lỗi, scan/configuration race, revision/readiness, lifecycle/queue và schema-contract gaps đã được xử lý; evidence chốt tại §18. Các kết luận trước remediation được giữ làm lịch sử, không dùng để mở lại approval đã có.

Repository: `tiemtt-ai/lf`; baseline review `e154407e205754c53feb0a84c67db4a0ff11506b`. Implementation và closure report đã có trên GitHub `main` tại `72d5f8e3aac8865c7858f6e3a14bc5fe0ff4982b`, xác minh bằng `git ls-remote` ở lượt §20. Không deploy, không sửa `.env`, không backfill hay sửa migration cũ.

Audit Level trước và sau sửa: **HIGH** — thay đổi query/readiness, transaction và revision lịch sử. Theo taxonomy của Regression Audit: **PASS** trong phạm vi local/D1–D6; verdict theo đề bài: `APPROVE`.

## 2. Phạm vi và phần deferred

Review Document từ upload/attach Activity tới OCR/structured extraction, persist, retry, revision, locale, authorize và read. Kiểm tra cả migration/physical constraints, queue sync và runtime executable thực tế. Media Read hiện là internal service + CLI; không cần tạo HTTP API mới.

Không review nghiệp vụ Audio/Video. Full suite có chạy các test dùng chung để phát hiện regression, không đồng nghĩa đã nghiệm thu các capability đó. Fake virus scan chỉ dùng trong test/local theo substrate review; **không** được coi là quét virus thật.

`DEFERRED_PRODUCT_OR_PRODUCTION`: Product enablement, entitlement/quota, provider theo tenant/Product, production deployment, worker sizing/autoscaling, S3, monitoring/alerting, benchmark lựa chọn model, ngưỡng OCR cuối cùng từng ngôn ngữ và chi phí production. Các mục này không làm local E2E thất bại.

## 3. Tài liệu và implementation đã đối chiếu

### 3.1. Canonical/routing

- `AGENTS.md`; `docs/README.md`; `docs/LF-INDEX.md` — Documentation Routing Guide/Media.
- `docs/governance/LF-Architecture-Guardrails.md`; `docs/governance/LF-Architecture-Principles.md`; `docs/governance/LF-Architecture-Review-Checklist.md`.
- `docs/LF-OS.md`; `docs/prompts/LF-Implementation-Rules.md`; `docs/LF-Development-Standards.md`; `docs/quality/LF-Regression-Audit.md`.
- `docs/platform/LF-Media.md`; `docs/platform/LF-Media-Processing-Contract.md`; `docs/platform/LF-Media-Read-Contract.md`. Các amendment Audio/Video chỉ dùng để xác định boundary/shared code, không nghiệm thu Audio/Video.
- `docs/adr/ADR-0004-Media-Foundation.md`; `docs/adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md`; `docs/adr/ADR-0019-Media-Structured-Extraction-Boundary.md`; `docs/adr/ADR-0020-AI-Vision-Interpretation-Boundary.md`.
- `docs/database/media/README.md`; `media_files.md`, `media_file_usages.md`, `media_processing_jobs.md`, `media_extracted_texts.md`, `media_extracted_regions.md`, `media_extracted_tables.md`, `media_table_cells.md` trong cùng thư mục; bổ sung `media_access_logs.md` khi điều tra drift audit timestamp.
- `docs/database/LF-Schema-Drift.md`; `docs/database/LF-SCHEMA-CONTRACT.json` — đối chiếu bảy table object Media nói trên, gồm columns, indexes, FKs, CHECKs; không review các domain khác trong JSON.
- `docs/quality/LF-Media-Processing-Substrate-Architecture-Review.md`; `docs/quality/LF-Media-Structured-Extraction-Architecture-Review.md`; `docs/quality/LF-Media-Read-Contract-Architecture-Review.md`.
- `docs/quality/LF-Documentation-Conflicts.md` — đặc biệt 0015–0022 và các resolution/amendment liên quan Document.
- `docs/tech/LF-Tech-AWS.md`; `docs/tech/LF-Tech-Stack.md`; `docs/tech/LF-Tech-Runtime-Requirements.md` — local/offline provider, executable inventory, database floor và policy dọn storage.

### 3.2. Bản đồ code

| Layer | Files / symbols được đối chiếu |
|---|---|
| Foundation migrations | `2026_07_05_020000_create_media_files_table.php`, `2026_07_05_030000_create_media_file_usages_table.php` trong `database/migrations` |
| Processing migration | `database/migrations/2026_08_24_000000_create_media_processing_substrate.php` |
| Document amendments | `2026_08_26_000000_open_extracted_text_sheet_locator.php`, `2026_08_26_000100_create_media_structured_extraction.php`, `2026_08_26_000200_open_media_processing_job_structured_identity.php`, `2026_08_28_000000_open_figure_region_text.php`, `2026_08_28_000100_add_region_crop_columns.php`, `2026_08_30_000000_widen_processing_job_idempotency_key.php` trong `database/migrations` |
| Models/relationships | Không có Media Eloquent aggregate trong đường xử lý này; dùng `DB::table` và FK. `App\Models\User` chỉ dùng cho actor/test fixture |
| Dispatcher/fingerprint/profile | `app/Services/MediaProcessingOrchestrator.php::{materializeForCourseActivity,materializeOnDemandProfile,retry,createInitialJob,sourceFingerprint,versionFor}`, `MediaOutputProfile.php::{canonicalLocale,canonical,hash,parse}` |
| Queue / writer | `app/Jobs/ProcessMediaProcessingJob.php::{handle,failed,persistSuccess,archiveSupersededRevisions}`, `app/Contracts/MediaProcessingProvider.php`, `FakeMediaProcessingProvider.php` |
| Real Document provider | `app/Services/LocalDocumentProcessingProvider.php`, `DocumentProcessRunner.php`, mới: `DocumentTextUnits.php` |
| Structure | `app/Services/DoclingStructuredExtractionProvider.php`, `StructuredExtractionPersistenceService.php`, `RegionCropStorage.php`; `runtime/docling/extract.py`, `runtime/docling/README.md`; runtime version xác minh bằng Python package metadata |
| Owner integration | `app/Services/MediaService.php::{uploadMedia,attachUsage,detachUsage,generateSignedUrl,generateDerivedSignedUrl}`, `CourseMediaOwnerContextAuthorizer.php`, `CourseActivityMediaPreviewAuthorizer.php` |
| HTTP/validation | `app/Http/Controllers/CourseTemplateActivityController.php::{attachUploadedMedia,structuredExtractionRequested}` và validation Document/locale; `CourseActivityMediaPreviewController.php::show`, `MediaFileDeliveryController.php::show` |
| Reader/CLI | `app/Services/MediaReadService.php::{read,structureCoverage,activeMediaForOwner,audit}`, `app/Exceptions/MediaReadException.php`, `app/Console/Commands/MediaReadDerived.php`, `MediaReprocess.php` |
| Routes/config | `routes/web.php` signed delivery/module inclusion; `routes/modules/media.php`; `config/media.php`, `config/queue.php`, `config/filesystems.php`; whitelist Media/queue runtime keys của `.env`, `.env.example` (không thu thập secret) |
| Verification infrastructure | `composer.json`, `phpunit.xml`, `.github/workflows/application-tests.yml`, `app/Console/Commands/SchemaDrift.php`, `tests/Support/document-mariadb-review.php` |
| Tests | `tests/Feature/MediaProcessingSubstrateTest.php` (Document/shared symbols), `LocalDocumentMixedPdfTest.php`, `LocalDocumentSpreadsheetTest.php`, `MediaReadDerivedCommandTest.php`, `MediaReprocessCommandTest.php`, `MediaRevisionLifecycleTest.php` (shared revision behavior), mới `DocumentProcessingLocalReviewTest.php` |
| Physical integration | `tests/Integration/MediaProcessingSubstrateMariaDbTest.php`, `MediaStructuredExtractionMariaDbTest.php`, `MediaProcessingJobKeyWidthMariaDbTest.php` |
| Fixtures | `tests/Fixtures/document/{mixed,scan,blank,encrypted,broken}.pdf`, `generate.py`; fixture Course/tenant/user tạo trong test, không dùng customer thật |

Inventory theo symbol không tuyên bố review mọi chức năng không liên quan trong controller/service lớn. Không thêm model, enum, route, middleware, public API hay dependency production.

## 4. Contract-to-code traceability matrix

**Snapshot trước D1–D6:** các nhãn PARTIAL/NOT_IMPLEMENTED và mô tả thiếu sót trong §4–§10 ghi nhận thời điểm review ban đầu. Trạng thái đóng hiện tại xem §18–§19; không dùng snapshot này làm danh sách backlog hiện tại.

Đường dẫn viết gọn trong bảng được định nghĩa đầy đủ ở §3; symbol/test name là locator evidence.

| Contract requirement | Database | Code | Test | Trạng thái | Evidence |
|---|---|---|---|---|---|
| Lifecycle dùng `pending`, không phải literal `queued` | `chk_mpj_status` | Job `handle` | review E2E + substrate | PASS | Processing Contract §2; job pending→processing→ready/failed |
| Upload scan độc lập; dẫn xuất từ authorized active Course usage | usages + jobs | `attachUsage`, `materializeForCourseActivity` | real Course fixture | PARTIAL | HTTP path đúng; CLI/direct materialization và detach guard còn H02 |
| Published version không dispatch mới | owner type tại attach | `MediaService::attachUsage` chỉ course_activity | substrate owner tests | PASS | Processing Contract §1; nhánh dispatch owner_type |
| Tenant ownership | composite job/output FKs | customer filters + real authorizer | cross-tenant read + MariaDB FK | PASS | `CourseMediaOwnerContextAuthorizer::authorized`, `withReadyDocumentJob` |
| Usage tenant enforcement tại application | usages FK đơn | `assertMediaFileBelongsToTenant`, tenant-scoped read | substrate/shared tests | PASS | Không nhầm FK đơn của usages thành FK composite |
| Deterministic job identity | tenant+key / full profile unique | `createInitialJob`, profile hash | duplicate dispatch; key width integration | PASS | Không random job identity; correlation_id mới là UUID |
| Fingerprint checksum + file_type | CHAR(64) | `sourceFingerprint` | changed source/new Media | PASS | Đổi binary bằng Media mới; không mutate binary lịch sử |
| Retry row mới, ≤3, scope đầy đủ | supersedes unique/FK | `retry` | actual TXT retry + CLI tests | PASS | Row failed cũ được assert không đổi, delay captured |
| Concurrent delivery không làm mất work | durable pending row | delayed same-attempt envelope + recovery | busy async regression; real queue probe §17 | PASS | Real database queue probe 17 assertions xanh; xem §17 |
| Worker chết không để job processing vô hạn | started_at/completed_at | scheduled recovery + terminal/crop fencing | regression + fault probe §17 | PASS | Real SIGKILL/recovery/redelivery xanh; không auto retry/storage delete |
| Detach trước start phải cancel | cancelled vocabulary | active-usage claim/detach locking | cancel + multiple usage tests | PASS | Reattach sau cancelled là nhánh còn chờ D6 |
| Không ready nếu output rỗng/sai | ready CHECK + transaction | `DocumentTextUnits`, `persistSuccess` | invalidOutputs, late result | PASS | Validate trước insert, terminal job không resurrect |
| Tổng text OCR ≤500000 | application invariant | provider per-page budget + full revision validator | total-cap negative | PASS | Không chỉ kiểm từng page riêng |
| Text layer / scan / mixed pages | text locator+sequence | real Poppler/Tesseract | mixed + scan E2E | PASS | Vietnamese embedded text + scan text page 2 |
| Blank-only/broken/encrypted/unsupported | failed job, no rows | stable errors | real badPdfs + unsupported | PASS | corrupt_source / no_extractable_text / unsupported_source |
| XLS canonical text không bị clipping do render | legacy source | Office→PDF | fixture office.xls fail | FAIL | M08; không tính vào Office acceptance xanh |
| Spreadsheet dùng sheet/spreadsheet_cells, new version | CHECK vocabulary đã mở | xlsxUnits còn page/embedded_text | existing spreadsheet tests còn assert cũ | BLOCKED_BY_DOCUMENT_CONFLICT | M02 / DOC-CONFLICT-0029; giữ citation cũ |
| Page trống trong tài liệu có text | text nullable nhưng ready text non-null | `unit('')` bỏ trang | blank-only tested; mixed blank chưa đủ | PARTIAL | M06; chưa có quyết định rõ về representation blank page |
| OCR provider non-null + header booleans | thiếu CHECK vật lý/JSON | writer ghi provider, cast booleans | physical CHECK inventory | FAIL | M01; schema:drift không phát hiện CHECK thiếu ở cả hai phía |
| Region/table/cell scope + cascade | composite 5-column FK; cells CASCADE | structured persistence | MariaDB structured tests | PASS | Scope tenant/media/locale/version, không cascade source/job |
| Structured atomic readiness/resources | app invariant | `validate`, transaction job | existing validation tests + real Docling | PARTIAL | M03/M04; chưa đóng toàn bộ F.3–F.5/F.9 |
| Region OCR/observation, không AI interpretation | role figure + bbox/text | Docling local + figure text/crop | real regions, amended figure test | PASS | ADR-0019 D7; không infer chart meaning |
| PDF structured optional, không chặn OCR | job_type riêng | distinct dispatch / output | real OCR alone + optional Docling | PASS | Không triển khai cấu hình Product |
| Spreadsheet structured profile `structure=cells` | table/cell schema có | dispatcher luôn layout; Docling chỉ PDF | chưa có real spreadsheet structured path | NOT_IMPLEMENTED | M07; khác thiếu corpus |
| Read đúng owner/slot; ambiguity fail-closed | usages index | authorizer + limit(2) | existing ambiguity + real authorizer | PASS | Không có bare media_file_id API |
| Chỉ read output có job/revision hợp lệ | job FK chỉ tenant, app cần kiểm thêm | `withReadyDocumentJob` | failed-job / wrong-job / history | PASS | Kiểm type, status, tenant, media, version, fingerprint; giữ legacy NULL |
| Locale explicit/canonical, không fallback | processing_locale + locale | canonical locale/read | vi/en + invalid locale + version | PASS | Không dùng UI locale |
| Current vs archived revision | version + job ID | pin one revision; sequence order | revision/legacy sorting tests | PASS | Explicit version đọc archived; fingerprint mismatch không bị bỏ qua |
| pending/processing/failed errors | jobs status | job-state lookup khi chưa có output | pending+processing+failed tests | PASS | Dùng canonical lower-case, không tạo NOT_READY enum mới |
| Không leak provider diagnostics | error_code/error_message | sanitized Document errors | exception + worker timeout metadata | PASS | Không persist raw exception URL/token/source text |
| Billable usage khi đã tiêu thụ | billable pair nullable | chưa ghi lượng tiêu thụ | code inspection | NOT_IMPLEMENTED | M05; khác với deferred pricing/production cost |
| Fresh migration / JSON comparison | fresh disposable schema | `schema:drift --fresh` | supported MariaDB rehearsal | FAIL | Migration dựng được 88-file schema; drift accessed_at default trên 11.4, H03 |
| Product/production enablement, sizing, S3, quality/cost | Không đổi | Không triển khai | Ngoài scope | DEFERRED_PRODUCT_OR_PRODUCTION | §13 |

## 5. Database review

- `media_files` là source; usages là polymorphic owner reference. Usage không có FK đến từng bảng Course; owner existence/permission là việc adapter Course. FK usages→Media là **đơn**, tenant isolation ở attach/read phải được giữ nguyên.
- Jobs/extracted text/regions/tables có `(media_file_id, customer_id)` và `(processing_job_id, customer_id)` scoped FKs, RESTRICT. FK tới job không tự chứng minh cùng media/version/fingerprint; reader mới kiểm bổ sung những giá trị này. Legacy `processing_job_id IS NULL` vẫn hợp lệ theo schema.
- `media_extracted_tables`→region mang scope `(id,customer_id,media_file_id,locale,processing_version)`; cells kế thừa revision/status từ table. Cells→table CASCADE được duyệt cho owned-child purge; không áp CASCADE ngược lên source hoặc job.
- Unique output locator chứa processing_version, **không** chứa fingerprint/job ID. Điều này phù hợp binary bất biến trên một Media; không được coi thay checksum tại chỗ là luồng upload hợp lệ. New extractor version lưu row mới và archive row cũ; retry failed không overwrite output cũ.
- `revision` không phải cột độc lập. Citation/revision được nhận diện qua source_fingerprint + processing_version + locale; job row bổ sung provenance attempt/provider/time. Text dùng locator_value cho page, `sequence` cho order; region có `page`, `ordinal`, `reading_order`, normalized bbox; table/cell có grid coordinates.
- Job `output_id` là entry point, không phải một-to-một với toàn bộ text. Quan hệ một job→n page rows tồn tại thật; dòng diagram “1→0..1” trong text table doc là stale, không dùng để kết luận schema chỉ hỗ trợ một trang.
- Bbox physical CHECK dùng all-null hoặc all-present, không chỉ dựa vào SQL UNKNOWN. Crop CHECK hiện vẫn thiếu `IS NOT NULL` tường minh ở width/height/bytes trong nhánh present; partial values có thể vượt CHECK bằng UNKNOWN. Writer kiểm completeness, nhưng DB invariant chưa đủ (M01).
- Thiếu `extraction_method <> 'ocr' OR provider IS NOT NULL` ở extracted text; thiếu boolean checks `has_header IN (0,1)` / `is_header IN (0,1)` trong migrations và JSON manifest. Các yêu cầu có trong table docs. Không sửa migration cũ, không nới tài liệu để làm xanh tool.
- Job key đã được mở 320; integration kiểm storage max width và unique index không bị prefix-truncated. Migration vocabulary/rollback cũng được chạy trên database disposable. MariaDB 11.4 fresh có drift default accessed_at riêng H03; không phải failure khi apply migration.

## 6. Lifecycle / orchestration

Chuỗi canonical là `pending → processing → ready/failed`; “queued” trong đề bài được hiểu là trạng thái pending, không thêm vocabulary mới.

Upload tính checksum, private storage, materialize scan sau commit. Attach Course với locale materialize OCR; structured là opt-in. Job claim sử dụng lock Media để chặn hai job cùng `(media,job_type)` processing. Kết quả persist và cập nhật ready nằm chung transaction; rollback không để page output mới tồn tại một phần. Scan sạch không còn xóa lỗi missing canonical locale của Document.

Sửa terminal-state guard cho OCR/structured: nếu worker đã đánh failed, kết quả provider về muộn không ghi output hoặc chuyển ngược ready. Failure update chỉ chạm row còn processing. Retry new row giữ identity/profile/provider, tăng attempt, supersedes và delay; test giữ nguyên snapshot failed row.

Chưa sửa H01/H02: lock giúp không chạy song song nhưng không bảo đảm liveness; và detach không được kiểm lúc claim. Không dùng kết quả duplicate-delivery test để chứng minh concurrency nhiều worker đã đúng. Không tự chuyển ready/failed thành cancelled.

## 7. Document extraction

Real `local_document` đọc source qua Storage stream. Poppler lấy text layer; riêng từng trang thiếu text mới render/Tesseract. Fixture mixed chứng minh page 1 `embedded_text`, page 2 `ocr`; text “Nội dung tiếng Việt.” được giữ nguyên. Pure scan chạy OCR thật. Blank-only không sinh giả ready. Broken/encrypted PDF fail có kiểm soát, không để raw stderr thành public/audit metadata.

`DocumentTextUnits::validate` kiểm revision không rỗng, UTF-8, ít nhất một unit có text, page/sheet vocabulary, decimal positive locator, unique locator, sequence tăng, số unit và tổng ký tự. Provider PDF cộng ngân sách sau từng trang; writer kiểm lại trước mọi insert. Không tự phát minh representation cho trang trắng ở tài liệu có text (M06).

DOCX/XLSX parser dùng ZipArchive/XMLReader; XML expansion/page/character limits đã có. Spreadsheet thật qua OOXML đã được existing tests kiểm, nhưng locator/provenance hiện chưa theo amendment sheet (M02). Các định dạng Office legacy đi qua LibreOffice; chưa có acceptance corpus thật đầy đủ cho doc/xls/ppt/pptx trong lượt này, không tuyên bố chúng đã E2E pass.

Docling **2.119.0** đã chạy từ venv/models local, nhận source PDF, trả JSON hữu hạn và region đọc được. Fixture này không đủ để nghiệm thu toàn bộ bảng/merged cell/diagram/chart/DOCX/XLSX theo F.9. Không gọi external AI, không phân loại ý nghĩa chart. Giữ OCR không bị phụ thuộc structured provider.

## 8. Media Read

Request gồm actor, owner_type/id, usage_type, content_type và optional locale/version/fingerprint. Real Course authorizer yêu cầu actor active cùng tenant; admin hoặc assigned teacher. Published version lookup giữ tenant trong join. Exact slot không có active row trả missing/detached; nhiều row trả ambiguous_source.

Đối với Document, reader mới:

1. Kiểm file_type document.
2. Chỉ chấp nhận linked output khi job ready và khớp type/customer/media/version/fingerprint; không loại bỏ legacy NULL provenance.
3. Chọn current theo job ID rồi row ID; pin cùng job/version/fingerprint, thay vì chỉ pin version rồi trộn rows.
4. Sort text theo sequence, regions theo reading_order, tables theo sequence.
5. Đọc job state khi chưa có output; trả pending/processing/failed thay vì locale_unavailable sai.
6. Giữ explicit archived revision và mismatch behavior, không fallback locale.

`structureCoverage` cũng kiểm linked ready job và pin region revision. Read audit vẫn dùng owner context; denied read không trở thành quyền đọc. Response không đưa output_profile/provider stderr vào contract. Trang text thiếu structure dùng `structure_unavailable` theo existing behavior; các khoảng trống acceptance còn ở M06.

## 9. Tenant / security / regression boundaries

Không thay tenant resolver, auth middleware, permission model hay routes. Tenant-scoped FK tests và real cross-tenant read negative được chạy. Private source delivery đòi media.ready; preview authorizer kiểm đúng Activity + slot + active usage. Derived read luôn qua actor/owner authorize, không được mở đường đọc theo bare ID.

Exception metadata OCR/structured không còn lưu nguyên message có thể chứa URL token/PII; worker failed callback cũng được sanitize. Output text có PII không tự động bị fail/redact theo ADR-0018. Fixture toàn bộ là dữ liệu tổng hợp, không có dữ liệu người học. Temporary provider directories được finally cleanup; không triển khai auto-sweeper trái policy manual cleanup.

Regression rollback của các sửa này là revert code/tests, không rollback schema hay sửa output lịch sử. Browser/manual UI và frontend build không áp dụng vì không đổi view, CSS, navigation hoặc i18n; HTTP regression nằm trong full suite. Không thay extraction vocabulary runtime cho XLSX: concern bị STOP theo DOC-CONFLICT-0029; sau amendment vẫn cần version mới, bảo toàn citation và đóng acceptance gate. Full regression suite kiểm blast radius shared job/reader; không coi đó là review Audio/Video.

## 10. Commands và kết quả thực tế

Kết quả baseline trước sửa:

| Command | Kết quả |
|---|---|
| `php artisan test --filter='MediaProcessingSubstrateTest\|LocalDocument\|MediaRevisionLifecycleTest\|MediaReadDerivedCommandTest\|MediaReprocessCommandTest'` | 117 passed, 515 assertions, 12.80s |
| `php artisan test` | 883 passed, 8856 assertions, 1 skipped, 68.99s |
| `php artisan schema:drift --docs-only` | PASS |
| `php artisan schema:drift --fresh` trên connection `.env` | PASS về schema; server thực tế 10.4.21 thấp hơn floor, không dùng làm supported-runtime acceptance |
| `php artisan docs:lint` | PASS; tool ghi nhận 98 legacy metadata allowlist entries |

Development reruns:

- Writer/read group: 117 passed / 515 assertions sau nhóm sửa đầu.
- New real tests vòng đầu: 16 passed, 2 failed vì broken/encrypted trả processing_failed. Sau sửa: 18 passed / 73 assertions.
- Sau thêm actual Docling, retry, scan race, timeout metadata: 22 passed / 91 assertions; Docling thực tế ~19.90s, cả suite 24.86s.
- Initial physical integration trên 10.4.21: 43 tests / 141 assertions, 1 error do fixture test thiếu error_code khi gán failed và 1 failure do test figure-text cũ. Cả hai được phân loại và sửa test; không coi đây là lỗi migration cần nới CHECK. Database tạm đã drop.

### Supported local environment

PHP 8.3.33; PHPUnit 11.5.55; MariaDB 11.4.12; Poppler 26.03.0; Tesseract 5.5.1; LibreOffice 26.2.5.2; Python 3.11.16; Docling 2.119.0 (đọc version từ executable/package thực tế). Test dùng sync queue và filesystem test disk thật; fake scan, real OCR/Docling, real owner authorization. Không có mock DocumentProcessRunner trong E2E real tests.

MariaDB 11.4 được khởi động riêng với data directory/socket trong `/tmp/lf-document-mariadb-runtime`, `--skip-networking`, không sửa XAMPP server hoặc `.env`. Harness tạo tên `lf_document_review_<random>`, chạy PHPUnit rồi drop trong finally; có production guard và database-version floor guard. Secret kết nối không được ghi vào báo cáo. Kết thúc lượt review đã xác nhận không còn database `lf_document_review_*`/`lf_schema_drift_*` trên instance này và đã shutdown server disposable; giữ logs trong `/tmp` để đối chiếu. Server disposable được chuyển `innodb_flush_log_at_trx_commit=2` để giảm fsync khi các test DDL lặp fresh; không đổi CHECK/foreign keys/transaction rollback. Lượt này không kiểm chứng crash/power-loss durability, và không thay setting của XAMPP.

```bash
php artisan test tests/Feature/DocumentProcessingLocalReviewTest.php
php artisan test
php artisan docs:lint
php artisan schema:drift --docs-only

# Chỉ socket của instance disposable đã tạo ở local:
DB_SOCKET=/tmp/lf-document-mariadb-runtime/server.sock DB_HOST=localhost DB_USERNAME=root DB_PASSWORD='' \
  php tests/Support/document-mariadb-review.php
# Có thể rerun riêng các case Document bằng --feature-only.
DB_SOCKET=/tmp/lf-document-mariadb-runtime/server.sock DB_HOST=localhost DB_USERNAME=root DB_PASSWORD='' \
  php artisan schema:drift --fresh
```

Verification của bản 1.0 (trước follow-up F09) đã hoàn tất:

| Command / kiểm tra | Kết quả |
|---|---|
| `TEST_TOKEN=document-review-full php artisan test` | **907 passed, 8952 assertions, 1 skipped**, 89.96s; bao gồm 24 case review mới và real Docling |
| `vendor/bin/pint --test` trên PHP files thay đổi | PASS |
| `php -l` các services/job mới sửa; `git diff --check` | PASS |
| `php artisan docs:lint` sau đăng ký report vào README/INDEX/manifest | PASS |
| `php artisan schema:drift --docs-only` | PASS |
| `php artisan schema:drift --fresh` trên MariaDB 11.4.12 | **FAIL**: một HIGH `media_access_logs.accessed_at default differs`; 88 migration files dựng được schema |
| `tests/Support/document-mariadb-review.php --feature-only` trên 11.4.12, code cuối | **24 passed, 96 assertions**, 4:00.620 gồm fresh setup + real providers |
| Physical integration trên 11.4.12 | **25/25 passed** trong lượt 49 tests (lượt đó có một feature assertion dùng expected blank-only cũ; xem dưới) |

Follow-up review ngày 2026-08-31: evidence độc lập **do người dùng cung cấp** xác nhận một lượt harness xanh **49 tests / 165 assertions**, gồm 24 Document + 25 physical tests, trên code trước F09. Không thay thế lịch sử run cũ phía trên và không gán thành kết quả tôi vừa chạy. Sau F09, lượt Document riêng chạy **25 passed / 103 assertions**, 24.39s, không skip. Pint, PHP syntax, docs:lint, schema:drift --docs-only và git diff --check đều pass. Không khởi động lại MariaDB hay chạy lại physical suite trong follow-up; evidence physical nêu trên thuộc bản trước F09.

Full suite sau F09: `TEST_TOKEN=document-review-followup-full php artisan test` → **908 passed, 8959 assertions, 1 skipped**, 72.11s. Logs: `/tmp/lf-document-followup-tests.log`, `/tmp/lf-document-followup-full.log`, `/tmp/lf-document-followup-docs.log`. Đây là lượt chạy mới của follow-up, không cộng lẫn với evidence bản 1.0.

Suite mặc định trong `phpunit.xml` chỉ gồm Unit và Feature, **không gồm tests/Integration**. Test bị skip là `CourseTemplatePublishConcurrencyTest::test_template_lock_serializes_same_template_but_not_another_template`, vì SQLite không có SELECT FOR UPDATE row-lock semantics. Toàn bộ 24 Document tests của lượt trước đã chạy, không skip.

`TEST_TOKEN` tách root của Storage::fake theo cơ chế Laravel ParallelTesting; database SQLite và MariaDB độc lập, không để một suite dọn test disk của suite kia.

Executable probes dành riêng cho findings mở (không đưa test đỏ vào default suite):

```bash
TEST_TOKEN=document-review-probes php vendor/bin/phpunit /tmp/DocumentOpenFindingsProbeTest.php --filter=test_review_probe
```

Kết quả: **3 tests, 8 assertions, 3 failures**, xác nhận H01, H02, M03. Probe được dựng từ fixture/helper của `DocumentProcessingLocalReviewTest` với các trình tự tái hiện mô tả ở từng finding; file trong `/tmp` là working analysis, không là regression test đã sửa. Không đưa 3 failure này vào số pass của final suite.

Lượt 49 tests/161 assertions trên 11.4.12 kết thúc trong 11:37.340: 25 physical integration đều pass; một feature dataset blank-only đã được discovery với expected cũ `corrupt_source`, trong khi validator cuối trả canonical `no_extractable_text`. Sau chỉnh expectation và chốt code, rerun `--feature-only` xanh 24/24 (96 assertions). Không báo lượt 49 này là một run xanh. Bản cuối cũng xanh trong full suite SQLite.

Physical evidence: `media_access_logs.accessed_at` có `COLUMN_DEFAULT = NULL`, `IS_NULLABLE = NO`, `COLUMN_TYPE = timestamp`; server `explicit_defaults_for_timestamp = ON`. JSON contract vẫn `current_timestamp()`. Đây là xác nhận H03 độc lập với test expectation.

Lần supported harness đầu timeout 300s trong fresh setup; database vẫn được drop bằng finally. Timeout harness được nâng 900s, không đổi provider timeout, sau đó chạy lại tuần tự với schema rehearsal. `schema:drift --fresh` 11.4 đã hoàn tất: **FAIL duy nhất HIGH column.default media_access_logs.accessed_at**, không có migration missing/pending.

Logs trong session `/tmp/lf-document-*.log`; chúng là working evidence, không là canonical docs. Fixture generator cần reportlab/Pillow/pypdf và một Unicode TTF path; PDF đã render và kiểm hình page 1/page 2, không thêm thư viện Python vào dependency ứng dụng.

## 11. Findings — root cause trước D1–D6, đã đóng tại §18

Các mô tả PARTIAL/BLOCKED/cần duyệt bên dưới là root cause lịch sử. Toàn bộ H01–H04, M01–M08 và L01–L04 đã đóng trong phạm vi báo cáo; đối chiếu closure tại §19.

### Critical

Không tìm thấy Critical trong phạm vi đã kiểm. Không suy rộng thành chứng nhận security toàn hệ thống.

### High — baseline 2, hiện còn mở 0

**H01 — ĐÃ SỬA: busy claim không còn bỏ work.** Trước sửa, callback return null khiến envelope ACK trong khi row pending không còn delivery. Nay async dispatch envelope trì hoãn mới với cùng row/attempt và giữ connection/queue thực tế; sync drain pending sau completion. Recovery redeliver durable pending cũ nếu dispatch/worker chết. Regression test xác nhận attempt vẫn 1; fault test queue thật được ghi ở verification §17. Không dùng release để tiêu mất `$tries=1`.

**H02 — PARTIAL: đã sửa cancellation/active usage, còn reattach sau cancelled.** Claim, initial/on-demand/retry đều yêu cầu active Course Document usage; detach dùng Media lock cùng claim, không hủy processing job, nhiều usage vẫn active thì tiếp tục. Tests cancel-before-start và multiple-usage pass. Nhánh còn mở: reattach sau khi row đã cancelled giữ nguyên idempotency key nên chưa có đường rematerialize được contract cho phép. Cần D6 / DOC-CONFLICT-0032; không tự resurrect terminal row hoặc tăng provider retry attempt cho work chưa chạy. Đây không phải cross-tenant read bypass.

**H03 — Fresh schema phụ thuộc implicit TIMESTAMP default của server.** Supported rehearsal `schema:drift --fresh` trên MariaDB 11.4.12 dựng schema thành công nhưng fail `column.default: media_access_logs.accessed_at`. `database/migrations/2026_08_24_000000_create_media_processing_substrate.php:194` chỉ khai báo `timestamp('accessed_at')`, trong khi JSON contract chốt `current_timestamp()`. Trên 10.4 implicit default cho ra giá trị đó; trên 11.4 với explicit timestamp defaults, column không có default. Đây là default drift mức HIGH theo Schema Drift Standard, không phải migration SQL exception. Writer hiện truyền accessed_at nên real read vẫn có audit, nhưng clean supported schema chưa khớp manifest. Cần chốt explicit default phù hợp canonical intent và forward migration/manifest review; không normalize NULL thành CURRENT_TIMESTAMP để che khác biệt, không sửa migration đã phát hành.

H03 ảnh hưởng trực tiếp gate `schema-drift` trong `.github/workflows/docs-lint.yml`: workflow dùng `mariadb:11.4.3` và chạy `schema:drift --fresh`. Local 11.4.12 tái hiện failure trên cùng họ cấu hình explicit timestamp defaults. Vì vậy đây là blocker đối với gate CI, không chỉ rehearsal warning. Chưa đọc kết quả GitHub Actions thực tế nên **không khẳng định run remote đang đỏ hoặc thời điểm bắt đầu đỏ**. F07 cũng sửa assertion nằm trong suite integration CI, không chứng minh lịch sử run remote. Drift H03 được đăng ký DOC-CONFLICT-0031.

**H04 — ĐÃ TRIỂN KHAI recovery và crop fencing.** Command `media:recover-document-processing` chuyển processing quá worker timeout sang failed/provider_timeout; scheduler chạy mỗi phút, không chạm terminal job. Late writer bị guard trạng thái chặn; crop put/cleanup khóa job + Media và bảo vệ namespace của successor/committed revision. Không tự retry hay xóa storage từ reaper. Verification gồm fault test SIGKILL worker thật tại §17; phải chạy scheduler/queue của môi trường để cơ chế có tác dụng.

### Medium — baseline 7, hiện còn mở 0

**M01 — Database docs, JSON contract và physical CHECK chưa đồng nhất đầy đủ.** `media_extracted_texts.md::Constraints And Indexes` yêu cầu OCR provider; `media_extracted_tables.md`/`media_table_cells.md` yêu cầu header booleans. Migration substrate/structured và JSON không có các CHECK này. Crop all-or-none CHECK cũng cho SQL UNKNOWN nếu dimensions/bytes NULL trong present branch (`2026_08_28_000100_add_region_crop_columns.php`). App writer giảm nguy cơ nhưng không thay thế DB invariant. Cần forward migration có data preflight, cập nhật JSON và physical negative tests; không sửa old migration hoặc xóa yêu cầu trong docs. Schema Gate chưa PASS về semantic parity; tool không phát hiện nhóm CHECK thiếu này vì JSON cũng thiếu, dù có báo drift default độc lập H03.

**M02 — Spreadsheet locator/method bị chặn bởi contract chưa đồng bộ.** `LocalDocumentProcessingProvider::xlsxUnits` vẫn page/embedded_text. DOC-CONFLICT-0017/0018 có quyết định Owner mở sheet/spreadsheet_cells và version mới; nhưng Processing Contract § Phase 1 local document provider vẫn quy định bảng XLSX là embedded_text và ghi 0017 chờ Owner. Đây không chỉ là thiếu code: các canonical sources hiện không nhất quán. Trạng thái **BLOCKED_BY_DOCUMENT_CONFLICT**, đăng ký follow-up DOC-CONFLICT-0029; không hủy hay thay quyết định Owner cũ. Cần amendment đồng bộ contract và review tác động trước khi đổi locator/method/version; giữ nguyên historical citations và không backfill tại chỗ.

**M03 — PARTIAL: deadline đã sửa, aggregate budget còn mở.** Docling hiện dùng deadline tổng và không nuốt timeout trong figure text/OCR; regression deadline đã pass. `StructuredExtractionPersistenceService::canonicalTextRows/validate` chỉ cộng canonical rows đã tồn tại; OCR và structured là hai job type có thể chạy đồng thời, nên budget tổng phụ thuộc thứ tự hoàn tất. Review probe chạy structure trước OCR với cap 10 đã tái hiện tổng ready text = 13 (7 region + 6 canonical), assertion cap fail. Cần test cả thứ tự OCR-before-structure và structure-before-OCR; không tự chốt dependency/recovery policy bằng assumption. Đây là resource correctness local, không phải production sizing.

**M04 — ĐÃ SỬA: validation không mở rộng merged area.** `DocumentCellOverlap` sweep rectangles O(cells log cells), bộ nhớ phụ thuộc số cell chứ không row_span × column_span. Cell cap được kiểm trước overlap; span không dương bị reject. Test vùng gộp 1.000.000 × 1.000.000 với các cạnh tiếp xúc pass mà không dựng grid; oracle exhaustive trên 150 bộ rectangle xác minh phát hiện overlap. Không thêm cap merged-area trái contract hoặc claim không thể hết RAM ở mọi môi trường.

**M05 — PARTIAL: có OCR measurement, chưa đủ crash/structured metering.** Local Document provider checkpoint page hoàn tất vào job đang processing với tenant/media/job scope; giá trị monotonic, fallback không cộng đôi. Success/exception envelope giữ measurement cho writer; recovery giữ số page đã checkpoint khi worker chết. Không ghi giá hoặc quota, không đoán phần page chưa hoàn tất. Canonical contract chưa chốt structured unit/checkpoint; phần này còn D5.

**M06 — PARTIAL: corpus đã mở rộng, blank-page semantics còn chờ D4.** Real tests có mixed/scan, broken/encrypted/blank-only, Docling table/merged cells/chart/diagram cùng DOCX/DOC/PPTX/PPT/XLSX. XLS có failure riêng M08. PDF hỗn hợp có trang trắng vẫn bị bỏ locator; fixture mixed-blank đã chuẩn bị nhưng chưa sửa representation bằng assumption. D4 đề nghị empty string/char_count 0, không renumber, blank-only vẫn fail. Corpus tổng hợp không thay quality benchmark theo ngôn ngữ.

**M07 — Spreadsheet structured profile chưa triển khai.** Processing Contract required profile set liệt kê optional spreadsheet `locale=<...>;structure=cells`. `MediaProcessingOrchestrator::materializeForCourseActivity` tạo `structure=layout` cho mọi Document; `DoclingStructuredExtractionProvider::process` từ chối extension khác PDF. Vì vậy nhánh spreadsheet structured là **NOT_IMPLEMENTED**, dù PDF structured độc lập OCR đã pass. Đây là implementation gap riêng với M06 thiếu acceptance corpus, không nói structured là bắt buộc với mọi upload.

**M08 — XLS render-to-PDF làm mất text trong cell dài nhưng job ready.** Fixture `office.xls` có `DOCUMENT_ACCEPTANCE_ALPHA`; real LibreOffice→PDF→text chỉ trả `DOCUMENT`, cùng các giá trị còn lại. Lượt Office acceptance 6 case: 5 pass, XLS fail assertion giữ nguyên marker. Đây là data-loss evidence, không phải quality benchmark. Không sửa fixture để che clipping. XLS giữ ngoài danh sách acceptance xanh, đã đưa vào D2 để thay rendering bằng nguồn sheet/cell đúng contract sau amendment. XLSX native, DOCX/DOC/PPTX/PPT đã pass; không suy rộng sang XLS.

### Low — còn mở: 0 (các mục dưới đã được sửa trong remediation)

**L01 — ĐÃ SỬA factual documentation/test annotations.** Text table doc phản ánh migration đã mở vocabulary, provider CHECK/runtime còn partial và job→0..N pages; manifest đồng bộ Partial. Integration test comment chỉ rõ application tests đã tồn tại. Normative spreadsheet discrepancy được giữ riêng ở M02. Gate R/Owner Approval chưa ký vẫn giữ nguyên, không coi đó là lỗi để tự sửa thành YES.

**L02 — ĐÃ SỬA diagnostics an toàn.** Operator log ghi tenant/job IDs, exception category, exit code/signal có kiểu; không ghi exception message, stderr, source hoặc stack. Job error_message tiếp tục canonical code. Không nới sanitize và không tuyên bố shared runner Audio/Video đã được sanitize.

**L03 — ĐÃ SỬA subprocess classification.** Runner bổ sung typed exit/signal, giữ compatibility message cho caller khác; Document adapter dùng Poppler documented input/permission codes 1/3, output failure 2 và missing executable/signal. Unknown failure giữ processing_failed, không tự đổ lỗi input hay retry mọi Tesseract failure. Test các nhóm exit và real corrupt/encrypted PDF xác minh mapping. Không suy ra mọi ENOSPC/OOM scenario đã được fault-inject.

**L04 — ĐÃ SỬA profile values trước dispatch.** OCR layout chỉ preserve; structured value là layout/cells và phải khớp source type. Profile sai bị reject trước tạo job, không chờ unique locator collision. Test xác nhận số job không tăng. Spreadsheet capability còn M07, validation không được diễn giải là đã triển khai cells parser.

### Findings đã sửa trong lượt này

| ID | Mức ban đầu | Root cause / contract | Sửa và evidence |
|---|---|---|---|
| F01 | High | OCR units [] vẫn có thể ready; per-unit cap bỏ qua tổng | `DocumentTextUnits`, provider aggregate cap; invalidOutputs tests |
| F02 | High | Read chỉ lọc output.status/version, không xác minh job/provenance | `withReadyDocumentJob`, pin full revision, state lookup; failed/wrong job + revision tests |
| F03 | High | Late result sau timeout có thể ghi output/resurrect job | Lock/check terminal status trước persist; late-result test |
| F04 | Medium | Broken/encrypted subprocess error không thành canonical code | Local runCommand maps corrupt_source/provider_unavailable; real badPdfs |
| F05 | High | Exception message có thể persist source/token/PII | Sanitize Document handle/failed metadata; explicit secret-message tests |
| F06 | High | Scan sạch xóa required_profile_configuration_missing | Guard Document media update; queued scan/locale race test |
| F07 | Medium | Physical test còn expect figure text bị reject sau amendment | Update expectation theo ADR-0019 D7, không nới schema |
| F08 | High | Duplicate failed callback có thể purge crop của job đã ready | Chỉ cleanup Document khi callback thực sự transition processing→failed; real Docling test kiểm callback không purge revision ready |
| F09 | Medium | Coverage invalid locale ném raw exception và bỏ denied audit | Bọc canonicalLocale thành MediaReadException; test riêng kiểm code và audit tenant/media/decision |

Các High baseline đã được sửa và kiểm chứng, không chuyển thành deferred. Report này không cấp deploy approval.

## 12. Thay đổi đã thực hiện

- Thêm validator Document units và dùng ở provider/writer; giữ history và schema.
- Bảo vệ terminal job, sanitize metadata, giữ missing-locale failure qua scan completion.
- Siết Document read/coverage theo ready job/revision; stable pending/processing/failed errors; sort canonical order, locale invalid fail có tên.
- Normalize Document subprocess failures; shared runner chỉ bổ sung typed exit/signal và giữ message/RuntimeException compatibility cho caller khác, không review nghiệp vụ Audio/Video.
- Thêm fixture PDF nhỏ có thể commit, generator, real E2E và regression negatives.
- Thêm harness MariaDB disposable có cleanup/version floor; sửa hai test expectation/fixture đã lỗi.
- Thêm Document eligibility/cancellation, delayed redelivery, recovery command/schedule, crop fencing, bounded overlap validation, tổng deadline Docling và OCR measurement.
- Viết báo cáo này cùng gói amendment D1–D6; không thêm quota/provider Product hay deploy production infra.

## 13. Deferred Product / production

Tất cả dưới đây là `DEFERRED_PRODUCT_OR_PRODUCTION`: enable/disable theo Product; entitlement/quota; per-tenant provider policy; production rollout; worker count/autoscaling; S3/IAM/storage rollout; monitoring/alerting; provider/model benchmark; OCR quality threshold theo ngôn ngữ; production operating cost.

Không deferred: lifecycle/cancellation/concurrency, tenant isolation, schema parity, output validity, provenance/revision, resource caps local, metering provenance, migration/test failures. H02/H03 và M01/M02/M03/M05/M06/M07/M08 giữ trong scope.

## 14. Gate results — sau D1–D6

| Gate | Kết quả | Căn cứ |
| --- | --- | --- |
| Contract Gate | PASS | Owner duyệt D1–D6; canonical amendments và conflicts 0029–0032 đồng bộ/RESOLVED |
| Schema Gate | PASS | Fresh 90 migrations + physical CHECK/default/preflight/rollback trên MariaDB 11.4.12 |
| Code Gate | PASS | Không còn finding mở thuộc D1–D6; history, tenant scope, attempts và canonical provenance được giữ |
| Local Runtime Gate | PASS | Real Document/Docling/Office E2E và queue worker recovery trên disposable database |
| Test Gate | PASS trong scope local/D1–D6 | Full suite, physical schema/corpus/queue, build, Pint, docs lint và drift |
| Product/Production Gate | DEFERRED_PRODUCT_OR_PRODUCTION | Không deploy/chứng nhận sizing, quality benchmark hoặc virus scanner production; schema ứng dụng và smoke sau migrate được kiểm riêng tại §20 |

Không tự ký field Owner Approval hoặc đổi formal Gate R của review foundation
cũ thành production authorization. Kết quả này đóng các vấn đề local đã xác
định, theo D1–D6 được Owner duyệt; không thay các gate sản phẩm/vận hành.

## 15. Final verdict

**PASS_LOCAL_DOCUMENT_PROCESSING — Document local, D1–D6 hoàn tất.** Code và forward migrations
đã được kiểm chứng, không còn finding mở trong scope. Git/database đã tiến thêm
sau nghiệm thu ban đầu; trạng thái hiện tại và giới hạn môi trường xem §20.

## 16. Files thay đổi

- `app/Jobs/ProcessMediaProcessingJob.php`
- `app/Services/DocumentTextUnits.php` (mới)
- `app/Services/LocalDocumentProcessingProvider.php`
- `app/Services/MediaReadService.php`
- `tests/Feature/DocumentProcessingLocalReviewTest.php` (mới)
- `tests/Feature/LocalDocumentSpreadsheetTest.php`
- `tests/Integration/MediaStructuredExtractionMariaDbTest.php`
- `tests/Support/document-mariadb-review.php` (mới)
- `tests/Fixtures/document/generate.py` (mới)
- `tests/Fixtures/document/{mixed,scan,blank,encrypted,broken}.pdf` (mới)
- `docs/quality/LF-Document-Processing-Final-Code-Review.md` (mới)
- `app/Console/Commands/RecoverDocumentProcessing.php`, `routes/console.php` — recovery job-state.
- `app/Services/DocumentProcessingEligibility.php`, `MediaProcessingOrchestrator.php`, `MediaService.php` — active usage và claim/detach boundary.
- `app/Services/DocumentCellOverlap.php`, `StructuredExtractionPersistenceService.php`, `DoclingStructuredExtractionProvider.php`, `RegionCropStorage.php` — bounded validation/deadline/crop fencing.
- `app/Exceptions/DocumentCommandFailure.php`, `DocumentUsageException.php`, `app/Services/DocumentProcessRunner.php` — typed diagnostics/measurement.
- `tests/Unit/DocumentCellOverlapTest.php`, `tests/Feature/MediaProcessingSubstrateTest.php`, `LocalDocumentMixedPdfTest.php` — regression bổ sung.
- `tests/Integration/DocumentQueueRecoveryMariaDbTest.php`, `tests/Support/document-queue-worker.php` — queue fault test guarded disposable DB.
- `tests/Fixtures/document/structured.pdf`, `mixed-blank.pdf`, `office.{docx,doc,pptx,ppt,xlsx,xls}`, `generate-acceptance.py`, `generate-office.py` — synthetic corpus; XLS/mixed-blank đã được nghiệm thu trong D1–D6.
- D1–D6 bổ sung `DocumentCanonicalRevision`, `DocumentSpreadsheetReader`, hai forward migrations 2026_08_31, `runtime/docling/extract.py`, retry CLI và các contract/ADR/table amendments.
- `docs/database/media/media_extracted_texts.md` — đồng bộ runtime sheet/blank-page/version và OCR provider CHECK theo approval.
- `docs/quality/LF-Documentation-Conflicts.md` — follow-up 0029, drift 0030/0031 và GAP 0032; không đổi approval cũ.
- `docs/quality/README.md`, `docs/LF-INDEX.md`, `docs/LF-DOCUMENTATION-MANIFEST.json` — đăng ký report để không tạo orphan tài liệu.

Không sửa database data của ứng dụng, migration đã phát hành, `.env`, HTTP routes hoặc dependency lock. Canonical amendment D1–D6 dựa trên Owner approval, không viết lại các quyết định lịch sử ngoài scope. Có thêm lịch recovery job-state trong routes/console.php, không auto-delete storage. Catalog đăng ký report; registry 0029–0032 được đóng bằng approval, amendments và verification D1–D6.

## 17. Baseline risks và remediation trước approval D1–D6 — historical

1. Đóng H01/H02/H04 với recovery review và tests hai locale tranh chấp worker, detach-before-start, multiple active usage và retry backoff, không tăng attempt chỉ vì contention.
2. Bổ sung preflight/forward CHECK migration và physical negative tests; không chỉ làm khớp JSON với schema đang thiếu invariant.
3. Đóng amendment DOC-CONFLICT-0029 trước version rollout cho sheet locator; chứng minh citation cũ đọc lại đúng và no in-place backfill.
4. Đóng structured aggregate resource/merged-grid/deadline và F.3–F.5/F.9 bằng corpus có bảng/diagram/Office thật; định nghĩa blank-page representation bằng nguồn canonical phù hợp.
5. `.env` mặc định vẫn trỏ MariaDB 10.4.21 dưới floor. Instance 11.4 disposable dùng để kiểm chứng không tự nâng cấp database ứng dụng. Không dùng kết quả này để deploy lên 10.4.
6. Chưa chạy forced-process-kill race với crop của hai retry cùng revision prefix. Crop rollback/ready-callback có regression coverage, nhưng không suy rộng thành chứng nhận mọi race storage/worker.
7. Test runtime tự skip nếu executable/model không có: một CI xanh có skip không thay real local evidence trong báo cáo. Fake scan không chứng nhận binary an toàn production.

### Đối chiếu phản biện độc lập (follow-up)

N1 → M07; N2 → tác động CI của H03 với giới hạn evidence remote; N3 → H04; N4 → F09 đã sửa; N5/N6/N9 → L02/L03/L04. N8 đã được xử lý bằng registry 0029–0031; N10 bổ sung tên skipped test và ranh giới suites ở §10. M02 đổi BLOCKED, không tự sửa contract hay chọn policy mới.

N7 chưa tính thành finding đang mở: profile chỉ `locale=vi` không thuộc các exact profiles Document được contract/dispatcher hỗ trợ hiện nay. Nhận xét LIKE là debt khi mở vocabulary, chưa chứng minh lỗi với request/profile hợp lệ hiện tại. Cần mở test cùng amendment nếu tương lai cho phép locale-only.

### Remediation và các amendment còn cần duyệt (2026-08-31)

User đã yêu cầu sửa các bất cập, không chỉ cập nhật trạng thái. Lượt remediation này giữ Audit Level HIGH, không mở rộng sang Audio/Video. Các sửa chữa có contract rõ đã được thực hiện; danh sách dưới phân biệt phần đã sửa với phần còn cần authority quyết định. Chưa tạo migration, chưa tự ký Owner Approval.

| Finding | Thay đổi trong remediation | Giới hạn còn lại |
| --- | --- | --- |
| H01 | Busy async claim tạo envelope trì hoãn mới cho cùng row/attempt; sync drain pending sau completion; command recovery redeliver pending cũ | Scheduler và worker phải được chạy trong môi trường vận hành; không tạo retry attempt vì contention |
| H02 | Active Document Course usage guard ở initial/on-demand/retry/claim; detach khóa cùng Media row; nhiều usage còn active vẫn được xử lý | Không hủy job đã processing; virus scan upload giữ nguyên exception |
| H04 | `media:recover-document-processing` fail processing quá worker timeout; cron Laravel mỗi phút; ready/failed không bị viết lại | Không auto retry hoặc auto-delete storage; không suy ra crash durability |
| M04 | Sweep rectangles O(cells log cells), không mở rộng row_span × column_span; kiểm cell cap trước overlap và span dương | Không thêm merged-area cap trái contract |
| M03 (deadline) | Một deadline tổng cho Docling, gồm subprocess/crop/OCR; command timeout bị co theo ngân sách còn lại; không nuốt timeout thành partial ready | Aggregate OCR + structure vẫn chờ D3 bên dưới |
| M05 (OCR local) | Provider checkpoint page hoàn tất khi processing, carry measurement khi lỗi/ready/failed, không cộng đôi fallback | Structured unit và checkpoint còn cần D5 |
| M06 (corpus) | Thêm PDF bảng gộp ô, biểu đồ, sơ đồ; fixtures Office native và legacy; kiểm provider thật và authorized read | Blank-page semantics còn D4; fixtures không thay benchmark chất lượng theo ngôn ngữ |
| L01 | Sửa mô tả migration/cardinality/status của extracted texts và comment test; ghi đúng physical/schema gaps | Gate R NO chưa được nghiệm thu toàn bộ là trạng thái hợp lệ, không phải lỗi để sửa thành YES |
| L02 | Operator log chỉ IDs, exception category, numeric exit/signal; job metadata tiếp tục sanitized | Không log stderr, source text hoặc stack |
| L03 | Poppler input/permission 1/3 → corrupt_source; output 2, signal/missing binary → provider_unavailable; unknown → processing_failed | Không đoán hỏng file từ stderr hoặc tự retry mọi Tesseract exit |
| L04 | Reject OCR layout ngoài preserve và structure value/type không phù hợp trước tạo job | Không tự mở vocabulary spreadsheet |

Recovery đi kèm fencing crop: ghi crop phải khóa/check job processing; cleanup của attempt cũ không xóa namespace do successor processing/ready hoặc committed regions sở hữu. Không đổi đường dẫn citation/crop và không backfill. Test queue fault injection chạy real TXT extraction rồi cố ý chờ trước output persistence để SIGKILL worker thật; successful redelivery cũng dùng LocalDocumentProcessingProvider thật. Phần chờ là fault injection, không phải provider production.

Cách chạy local (chỉ môi trường test/local đã cấu hình):

```bash
php artisan schedule:work
php artisan queue:work
php artisan media:recover-document-processing --customer=<tenant-id>
# Harness chỉ tạo/drop database disposable; không chạy trên database ứng dụng:
php tests/Support/document-mariadb-review.php --queue-only
```

Không cài cron hệ điều hành, không deploy worker/service production trong lượt này. Pending recovery chờ 3900s để không vượt trước provider retry delay; processing timeout 3600s giữ contract hiện hành. Test có thể dịch timestamp/visibility để kiểm các mốc này mà không chờ một giờ.

#### Gói quyết định cụ thể — Owner duyệt 2026-08-31

**D1 — Schema parity (H03/M01, DOC-CONFLICT-0030/0031).** Đề nghị Database Owner + Architecture Owner duyệt: `media_access_logs.accessed_at` có explicit `DEFAULT CURRENT_TIMESTAMP`; thêm CHECK OCR provider non-null, `has_header/is_header IN (0,1)`; crop present branch phải có explicit `IS NOT NULL` cho width/height/bytes trước phép `> 0`. Giữ null branch toàn bộ-null. Sửa normative SQL crop để không còn SQL UNKNOWN. Không đổi tenant/FK/status vocabulary.

Preflight bắt buộc trên selected database: đếm và báo ID row OCR/provider NULL, header ngoài 0/1, crop partial/zero/negative hoặc MIME khác image/png. Nếu có row vi phạm: abort, không tự fill provider hoặc xóa crop. Timestamp default amendment không cập nhật event time lịch sử. Sau approval và Architecture Review passed mới tạo **forward migration mới**, cập nhật JSON, chạy fresh + existing-schema preflight + physical negative tests. Không sửa migrations đã phát hành và không normalize NULL default để che drift.

**D2 — Spreadsheet (M02/M07, DOC-CONFLICT-0029).** Đề nghị đồng bộ bảng local provider/ghi chú của Processing Contract với quyết định 0017/0018 đã được Owner duyệt: OOXML worksheet → `sheet` / `spreadsheet_cells`, version mới; `structure=cells` cho spreadsheet, `structure=layout` cho PDF. Giữ bản page/embedded_text cũ archived và explicit citation đọc lại được. Amendment cần ghi rõ XLS legacy conversion vào XLSX để giữ sheet/cell, không render PDF rồi giả lập cell. Sau đồng bộ contract và review, triển khai parser/persistence có bounded XML, merged ranges và shared strings; không dùng Docling PDF adapter cho spreadsheet. Không tự đóng 0029 bằng cách chỉ sửa code.

**D3 — Aggregate budget và OCR ưu tiên (phần còn lại M03).** Hiện contract vừa cộng canonical+region+cell vào cùng cap, vừa để OCR/structure độc lập nhưng chưa chốt transition khi canonical revision thay đổi. Đề xuất duyệt: OCR không chờ structure; structured materialization chỉ nhận canonical revision ready tương ứng; identity structured ghi nhận canonical revision đầu vào. Khi OCR mới ready, structure gắn canonical cũ chuyển archived, giữ job terminal/citation/crop; structured revision mới phải validate tổng dưới Media lock. Nếu vượt 500000: chỉ structured revision mới failed, OCR vẫn ready, không truncate. Cần amendment về identity/version và archive liên quan trước code, không tự archive region hiện tại chỉ để làm số tổng nhỏ hơn.

**D4 — Trang trắng trong PDF hỗn hợp (phần còn lại M06).** Đề xuất một page locator thật với `text = ''`, `char_count = 0`, extraction_method theo đường đã chạy; không fake text hay NULL-ready. Tài liệu toàn trắng vẫn `no_extractable_text`, không persist revision. `pages_with_text` chỉ đếm row char_count > 0; mixed blank vẫn giữ page 1/2/3, không renumber. Cần chốt rõ wording của table/read/processing contract trước thay output semantics đang bị giữ nguyên. Fixture `mixed-blank.pdf` đã sẵn sàng cho test; chưa coi việc tạo fixture là nghiệm thu behavior.

**D5 — Structured units/checkpoint (phần còn lại M05).** OCR local đã có monotonic checkpoint page hoàn tất trong processing, được giữ qua crash/recovery; không tính page chưa hoàn tất hoặc suy ra tiền. Đề xuất áp dụng checkpoint cho structured: PDF dùng page, spreadsheet dùng sheet, ghi observation của work thực tế, không suy ra giá/phí. Cần Owner chốt unit vocabulary và mốc tính “đã tiêu thụ” trước structured metering/checkpoint. Không triển khai quota/entitlement hoặc SaaS aggregation trong amendment này.

Lý do cần approval: AGENTS.md yêu cầu Database Docs approved và Architecture Review passed trước migration; Guardrails/conflict workflow cấm tự chọn output/revision semantics khi canonical sources còn mâu thuẫn/thiếu. User request sửa lỗi đã đủ authorization cho những fix contract rõ phía trên, nhưng chưa thay được quyết định cụ thể D1–D5. Các proposal này là review artifacts, không phải policy đã có hiệu lực.

**D6 — Reattach sau cancellation (H02 / DOC-CONFLICT-0032 còn một nhánh chưa đóng).** Guard mới đã ngăn work khi mọi usage detach trước claim. Nếu worker đã commit `cancelled`, sau đó source được attach lại với cùng fingerprint/version/profile thì key attempt 1 vẫn trỏ tới row cancelled; hiện chưa có contract cho việc mở chain mới từ cancellation. Không tự hồi sinh row terminal hoặc tính một cancellation chưa gọi provider như một failed provider attempt. Đề nghị Owner chốt chain/generation cho explicit reattach, giữ cancelled row bất biến và không dùng nó để tiêu provider retry budget. Trước quyết định này, phải báo rõ derivative cần rematerialization theo version/chain được duyệt; không claim H02 đã đóng toàn bộ lifecycle.

D6 — thiết kế đề xuất để duyệt: thêm `dispatch_generation` vào job và unique profile-attempt scope (legacy default 1). Chỉ explicit authorized reattach sau cancelled mới mở generation +1 dưới Media lock; tạo row mới, giữ cùng provider `attempt` và correlation, `supersedes_job_id` trỏ row cancelled. Vì cancelled chưa gọi provider nên không tăng hoặc reset attempt; retry sau failure vẫn giữ trần 3 xuyên các generation. Redelivery không mở generation. Gen 1 giữ key cũ; gen mới dùng SHA-256 của full identity tuple gồm generation, không truncate và không cần nới key 320. Output locator/version không đổi vì cancelled attempt không có committed output. Cần Database/ADR amendment và review unique/FK/retry preflight trước migration/implementation; đây là proposal, chưa là authority có hiệu lực.


#### Verification của remediation (mới nhất)

- Full suite: `TEST_TOKEN=document-remediation-final php artisan test` → **927 passed, 9349 assertions, 1 skipped**, 409.81s. SQLite Unit/Feature; skipped vẫn là row-lock test CourseTemplatePublishConcurrencyTest, không phải Document. Full suite này không chứa Integration.
- Real database queue probe: `--queue-only` → **1 test / 17 assertions PASS**, MariaDB 11.4.12, 7:56.487 gồm fresh setup. SIGKILL worker test, expired processing recovery, delayed busy envelope, real TXT redelivery, immutable failed row và attempt cap được kiểm qua queue thật. Sau test DB disposable đã drop.
- Standalone PDF table/merged/chart/diagram acceptance → **1 test / 16 assertions PASS**, 16.76s; source fixture đã render kiểm hình. Full suite cũng chạy case này.
- Office run ban đầu → **5 passed / 1 failed (XLS clipping)**, 27 assertions, 43.56s. XLS không bị đổi assertion hay báo skip để tạo pass; failure được giữ ở M08 và fixture còn trong repo. Default passing corpus hiện gồm 5 format còn lại.
- Reattach executable probe `/tmp/DocumentReattachProbeTest.php --filter=test_review_probe_reattach` → **1 test / 1 failure**: expected ready, actual cancelled. Đây là evidence H02/D6/0032 còn mở, không đưa vào tổng pass.
- Lượt MariaDB tổng hợp ban đầu timeout 900s, đã drop DB; không báo pass. Crop fixture có lỗi thiếu completed_at khi dựng row failed, bị physical CHECK phát hiện; đã sửa fixture để tuân thủ invariant, không nới CHECK. Harness timeout tăng 1800s cho fresh DDL, không đổi provider/worker deadline.
- Lượt queue đầu: toàn bộ 16 assertions của test body đã qua nhưng teardown `migrate:rollback` lỗi ở migration LiveClass cũ (`DROP CHECK` trên MariaDB). Không sửa migration ngoài scope; test nay dùng guarded fresh schema và harness drop toàn database. Lượt rerun 17 assertions phía trên xanh toàn bộ.
- Một lượt Document debug chạy đồng thời với full suite/DDL gặp `provider_timeout` ở Docling mixed fixture; không tăng provider deadline để che lỗi. Full suite xanh là evidence positive; các kết quả serial/physical cuối được ghi tiếp dưới đây. Không claim đã nghiệm thu concurrent model capacity.
- `docs:lint`, `schema:drift --docs-only`, Pint/PHP syntax và `git diff --check` được kiểm lại. Không tạo migration/schema change; physical timestamp evidence trong queue run vẫn `COLUMN_DEFAULT=NULL`, `IS_NULLABLE=NO`, explicit defaults ON. H03 vẫn mở, không dùng docs-only PASS thay fresh parity.

Logs: `/tmp/lf-document-remediation-final.log`, `/tmp/lf-document-queue-final.log`, `/tmp/lf-document-corpus.log`, `/tmp/lf-document-office.log`, `/tmp/lf-document-reattach-probe.log`, `/tmp/lf-document-remediation-mariadb.log`, `/tmp/lf-document-crop-final.log`, `/tmp/lf-document-debug.log`.


Lượt Document physical sau sửa crop fixture: `--feature-only` → **39 tests / 178 assertions PASS**, MariaDB 11.4.12, 4:40.102. Có real Docling/Office trong cùng lượt, không skip; CHECK failed vẫn nguyên và fixture đã có completed_at. Đây là verification của runtime repairs trước khi thêm OCR progress checkpoint; checkpoint được kiểm riêng bằng full suite và queue fault test tiếp theo. Log `/tmp/lf-document-physical-final.log`; database disposable đã drop.

Scheduler registration được kiểm bằng `CACHE_STORE=array php artisan schedule:list`: recovery mỗi phút, mutex hết hạn sau 2 phút để reaper chết không giữ lock mặc định 24 giờ. Row locks/terminal guards vẫn là lớp bảo vệ correctness nếu có hai recovery chạy đồng thời. Lượt schedule:list mặc định bị sandbox chặn kết nối Redis local; không đổi `.env` hoặc claim đã khởi động scheduler production.


Verification sau OCR checkpoint: full suite **927 passed / 9349 assertions / 1 skipped**, 143.33s (`/tmp/lf-document-checkpoint-final.log`). Queue fault test mới chạy real TXT extraction trước SIGKILL, xác nhận `billable_units=1`, `billable_unit_type=page` còn nguyên qua recovery, delayed delivery và terminal guard: **1 test / 19 assertions PASS**, 3:41.187 gồm fresh schema (`/tmp/lf-document-queue-checkpoint.log`). Lượt này thay thế probe 17 assertions về phần checkpoint; không cộng hai run thành số tests mới. Source/schema timestamp vẫn cho evidence H03 chưa đóng. Không thay provider/worker timeout để làm tests xanh.

Kết luận trước khi triển khai D1–D6: **CHANGES_REQUIRED**, còn **0 Critical / 2 High / 7 Medium / 0 Low**. High mở là H02 (chỉ nhánh reattach sau cancelled) và H03; Medium mở là M01/M02/M03/M05/M06/M07/M08. H01/H04/M04 và L01–L04 đã sửa có evidence. Phần cần authority quyết định nằm tại D1–D6, không phải Product/production backlog. Không ký Gate R/Owner Approval thay Owner và chưa tạo migration.

Cleanup cuối lượt: kiểm information_schema trả **0 database review còn lại**; MariaDB disposable shutdown complete lúc 2026-08-31 14:55:46, pid file đã biến mất. Không thay `.env`, XAMPP server/data, không commit/push/deploy. Giữ logs và fixtures để review D1–D6.


### Owner approval D1–D6 — 2026-08-31

User: “tôi duyệt, chú ý ko tự duy diễn ra nhiều hướng ko liên quan, đảm bảo các vấn đề đúng, đủ, tối ưu liên quan tới vấn đề đang giải quyết”. Các proposal trên được duyệt trong đúng phạm vi sáu mục; wording “chưa duyệt” phía trên là lịch sử trước quyết định này. Canonical amendments đã ghi ở Processing/Read Contract, ADR-0004/0019 và sáu table docs.

Architecture review trước migration, Codex, Audit Level HIGH: D1 schema constraint parity không đổi domain ownership/tenant/FK; preflight fail-closed, không backfill. D6 additive generation default1, giữ historical keys; unique successor giữ một chain, retry cap xuyên generation; rollback abort khi có generation>1. D2/D4 version mới bảo vệ old citations. D3 input identity lấy từ canonical ready tenant/media/locale/fingerprint; aggregate dưới Media lock, OCR không phụ thuộc structured. D5 measured work only, không mở quota/billing. Auth/routes/UI/Audio/Video không đổi. Review thiết kế D1–D6: PASS, migration gate mở theo Owner approval; runtime gate vẫn pending tests. Các test positive/negative/schema/fresh/history bắt buộc trước chốt done.


## 18. Remediation D1–D6 sau Owner approval — 2026-08-31

Phạm vi chỉ sáu quyết định đã duyệt. Không thay auth/tenant/role/UI/navigation, không mở quota/billing/AI interpretation và không deploy. Các lượt test/limitations trước mục này là evidence lịch sử, không cộng dồn thành số test của lượt mới.

| Quyết định | Implementation | Verification |
| --- | --- | --- |
| D1 | Forward migration explicit accessed_at default; OCR provider/header CHECK; crop present non-null; preflight count/first IDs và abort; giữ SQLite audit triggers khi rebuild | Physical CHECK/preflight/default PASS; schema:drift --fresh PASS |
| D2 | Native DocumentSpreadsheetReader dùng OOXML bounded XML/shared strings/rich text/cached values/merged spans; XLS chuyển XLSX. Sheet/spreadsheet_cells, native structure=cells; version mới, old pending fail-closed và archived citation đọc nguyên bản | XLS clipping assertion cũ đã pass; parser/legacy citation/CLI retry tests pass; physical corpus PASS |
| D3 | Structured input lấy canonical ready job cùng tenant/media/locale/fingerprint; identity hash full tuple và metadata input ID; validate lại trước provider/persistence, budget dưới Media lock; OCR mới archive structure cũ | Missing/stale canonical, aggregate failure/OCR remains ready, archive/history tests pass |
| D4 | Mixed PDF giữ blank page với empty text/char_count0; all-blank vẫn fail; coverage/fallback không coi blank như text page | Real mixed-blank + blank-only và live coverage tests pass |
| D5 | Local OCR/page; native structure/sheet; Docling/page checkpoint monotonic sau completed conversion receipt, trước crop/persistence; oversized result/failure vẫn giữ consumption đã biết; partial conversion fail-closed | Structured success và oversized/persistence failure measurement pass; real queue crash/recovery PASS |
| D6 | dispatch_generation default1 + unique scope; explicit reattach successor giữ attempt/correlation, terminal row bất biến; retry cap3 xuyên generation; key gen1 giữ nguyên, gen>1 full SHA256 | Reattach/duplicate/redelivery, cancelled attempt2 rồi retry attempt3 và cap tests pass; physical unique/rollback PASS |

D5 là measurement completed work, không phải tính tiền. Crash trước provider receipt không có chứng cứ page hoàn tất thì không đoán; crop/validation xảy ra sau checkpoint không xoá số đã ghi. Real queue SIGKILL probe dùng TXT/OCR; không gọi nó là một Docling process SIGKILL test. Resource caps spreadsheet structured đọc namespace structured_extraction; OCR không bị áp cell cap của optional structured output.

Migration design reviewed trước khi tạo migrations mới 2026_08_31_000100/000200. D1 không backfill event timestamps/provider/crop. D6 rollback abort khi có generation>1; historical jobs/output/citations không bị rewrite. Không chạy migrations lên XAMPP application DB, không đổi .env.

Lượt sửa/test trung gian được giữ trung thực: SQLite table rebuild từng làm mất append-only triggers (đã sửa và regression qua); sandbox macOS làm Office conversion thất bại (rerun ngoài sandbox với fixture); một lượt full đang chạy khi test resource namespace được cập nhật có assertion cũ thất bại, không dùng lượt đó làm PASS. CLI retry dùng thiếu media/profile context được targeted test phát hiện và đã sửa. Các thay đổi/test này trực tiếp thuộc D1/D2/D3, không mở hướng mới.


Verification mới nhất trên source đã chốt D1–D6: `TEST_TOKEN=d1-d6-closure php artisan test --compact` → **941 passed / 9416 assertions / 1 skipped**, 239.86s. Skip vẫn là SQLite row-lock CourseTemplatePublishConcurrencyTest; không có Document skip. Log `/tmp/lf-d1-d6-closure.log`. Canonical fresh schema: **PASS**, MariaDB 11.4.12, **90 migrations**, không HIGH/LOW drift, DB `lf_schema_drift_jeyxi7ts9fj5py5h` được command drop trong finally (`/tmp/lf-d1-d6-fresh.log`). `npm run build` PASS, `/tmp/lf-d1-d6-build.log`. Các schema/queue test còn lại được ghi ngay khi hoàn tất, không cộng vào số full suite.


Physical schema acceptance: **24 tests / 83 assertions PASS**, MariaDB 11.4.12, 19:23.145 (gồm các lần fresh schema do migration rollback/reapply tests). `accessed_at` có `current_timestamp()` khi explicit_defaults_for_timestamp=ON. Các negative tests chặn OCR/provider NULL, header=2, crop partial-NULL/zero/bad MIME; existing invalid data làm preflight abort và row giữ nguyên. D6 unique generation đúng và rollback từ chối xoá generation history. Log `/tmp/lf-d1-d6-physical-schema.log`; database `lf_document_review_f8dd919accb6918e` đã drop. DOC-CONFLICT-0030/0031 RESOLVED; 0029/0032 đã RESOLVED bằng Owner approval và canonical amendments, không thay resolution lịch sử 0017/0018.


Physical Document E2E: **49 tests / 234 assertions PASS**, MariaDB 11.4.12, 09:13.740 gồm fresh setup; không skip. Có provider PDF/OCR/Docling/Office thật, XLS nguyên marker, blank page, old/new citation, stale canonical, aggregate failure, generation/attempt và CLI retry. Log `/tmp/lf-d1-d6-physical-document.log`; database `lf_document_review_8b677a7b3bc2872f` đã drop. Đây là local acceptance, không phải benchmark tốc độ; các fresh schema chạy đồng thời nên không dùng elapsed time làm worker sizing.


Real database queue verification: **1 test / 19 assertions PASS**, MariaDB 11.4.12, 03:41.744. Worker chạy real TXT extraction, SIGKILL sau checkpoint; recovery giữ measurement, delayed busy envelope và terminal history; redelivery hoàn tất job khác mà không tăng attempt vì contention. Log `/tmp/lf-d1-d6-physical-queue.log`; database `lf_document_review_229b77e6f91f50b7` đã drop. Không có runtime/test blocker D1–D6 còn mở.

Final Audit Level: **HIGH**, không hạ mức. Final Verdict: **PASS / APPROVE (local Document/D1–D6)**. Full 941 tests/9416 assertions/1 unrelated SQLite skip; physical schema 24/83; physical Document 49/234; queue 1/19. Đây là các suite/run riêng, không cộng dồn thành một tổng test. Pint, docs:lint, docs-only drift, fresh drift, build và diff check được ghi nhận độc lập. Không tự ký Owner production/runtime deployment gate.


Cleanup chốt 2026-08-31 15:38:21: information_schema xác nhận **0 review database** còn lại; MariaDB disposable shutdown complete và pid file đã biến mất. Không có thay đổi `.env`, composer.lock/package-lock.json hoặc DB ứng dụng. `git diff --check` cuối PASS sau dọn blank line EOF của table docs. Không commit/push/deploy. Hai forward migrations sẵn sàng để review/apply trên môi trường được chỉ định phù hợp runtime floor; kết quả này không tự nâng cấp XAMPP MariaDB 10.4.

## 19. Closure register và cách review lại

Đối chiếu ngày 2026-08-31: đây là xác nhận trạng thái và chỉnh wording báo cáo, không phải một lượt chạy lại toàn bộ tests. Evidence runtime/schema vẫn là các lượt §18.

| Findings / quyết định | Trạng thái | Căn cứ đóng |
| --- | --- | --- |
| H01/H04, M04, L01–L04 | CLOSED | Runtime repairs và regression tại §11/§17; final suite và queue acceptance §18 |
| H03/M01 — D1 | CLOSED | Forward constraints/default/preflight; fresh drift và physical schema PASS |
| M02/M07/M08 — D2 | CLOSED | Native spreadsheet text/cells, version/history; real XLS marker và CLI/physical corpus PASS |
| M03 — D3 | CLOSED | Canonical dependency, aggregate budget và archive/history tests PASS |
| M06 — D4 | CLOSED | Mixed blank/all-blank và coverage tests PASS |
| M05 — D5 | CLOSED | Completed-unit checkpoints; structured measurement và OCR queue recovery PASS |
| H02 — D6 | CLOSED | Reattach generation, terminal history, retry cap và physical unique/rollback PASS |
| DOC-CONFLICT-0029–0032 | RESOLVED | Owner approval đã ghi tại §17; canonical amendments và verification §18 |

Không còn approval implementation D1–D6 đang chờ. Không yêu cầu Owner duyệt lại cùng quyết định chỉ vì các đoạn lịch sử dùng wording “chưa duyệt”. Không biến hạng mục Product/production ngoài phạm vi thành thiếu sót implementation của lần nghiệm thu này.

Review sau phải đối chiếu phạm vi, code và evidence đã chốt. Chỉ mở lại finding khi có bằng chứng cụ thể về lỗi còn tồn tại, regression hoặc thay đổi yêu cầu/môi trường; ghi rõ requirement, cách tái hiện và ảnh hưởng. Nếu phát hiện lỗi thuộc phạm vi đã duyệt, xử lý như defect, không tự gọi là nâng cấp mới để yêu cầu duyệt lại. Quy tắc này không cho phép che giấu lỗi mới hoặc bỏ qua approval nếu thực sự cần thay đổi architecture/contract ngoài quyết định đã có.

Giới hạn tại thời điểm đóng §19: chưa migrate DB ứng dụng/chứng nhận production; trạng thái migration đã được cập nhật sau đó tại §20. Kiểm thử không bảo đảm tuyệt đối không còn lỗi chưa phát hiện. Không có hạng mục implementation đã biết nào trong báo cáo bị để lại dưới dạng approval ẩn.

## 20. Checkpoint sau Owner migrate và smoke tài liệu thật — 2026-08-31

Mục này supersede trạng thái Git/database chưa thực hiện trong các snapshot §17–§19; không mở lại D1–D6 hoặc mở chức năng mới.

### Git và schema ứng dụng

- Đầu lượt working tree sạch, local `main` tại `72d5f8e3aac8865c7858f6e3a14bc5fe0ff4982b`. `git ls-remote origin refs/heads/main` trả đúng SHA này: implementation (`fa15f1d`) và closure report (`72d5f8e`) đã commit/push, không còn chỉ nằm trong working tree.
- Branch follow-up: `codex/document-processing-checkpoint-20260831`, từ checkpoint trên. Chỉ cập nhật evidence và `.gitattributes` khai báo PDF fixture là binary; không chỉnh byte PDF, runtime, migration hoặc dependency.
- Owner đã chạy migrate. Read-only ledger xác nhận hai migration `2026_08_31_000100_enforce_document_output_constraints` và `2026_08_31_000200_add_document_dispatch_generation` ở batch 21 trên `learnforge_db`.
- `php artisan schema:drift --connection=mysql --format=json`: **PASS**, 90 migration files, ledger `pending=[]`, `missing_source=[]`. Đây là selected-database inspection, không chạy fresh/rollback trên ứng dụng.
- Runtime thực tế: `APP_ENV=local`, MariaDB **10.4.21**, OCR `local_document`, structure `docling_local`, queue `redis`, virus scan `fake`. MariaDB này thấp hơn supported floor; schema PASS không thay đổi giới hạn đó. Không tự nâng cấp server hoặc sửa `.env`.

### Regression và smoke schema đang chạy

- Rerun `DocumentProcessingLocalReviewTest`: **49 tests / 234 assertions PASS**, 111.41s, SQLite test cô lập; log `/tmp/lf-document-checkpoint-review.log`. Không cộng vào 941 tests của lượt §18.
- Hai bài smoke real mixed-PDF/read và Docling/read chạy trên schema `learnforge_db` hiện có: **2 tests / 24 assertions PASS**, 22.288s; log `/tmp/lf-application-document-smoke.log`.
- Harness tạm `/tmp/ApplicationDocumentSmokeTest.php` override `refreshDatabase` để chỉ mở test transaction, tuyệt đối không gọi migrate/fresh/DDL. Fixture được rollback ở teardown; kiểm tra sau chạy: fixture customer=0, fixture user=0. Storage riêng của smoke đã dọn.
- Smoke dùng provider thật nhưng queue **sync** và virus scan **fake** trong test; không phải kiểm chứng Redis worker/scheduler đang vận hành. Không dùng kết quả này để chứng nhận development trên supported runtime hoặc production.

### Sáu tài liệu do Owner cung cấp

Chạy qua upload → Course usage → provider → persist → authorized read trong SQLite memory và storage test riêng. PDF/Office/OCR/Docling dùng executable local, không gửi tài liệu ra dịch vụ bên ngoài. Locale lần lượt ko/vi/en/ko/vi/ko được truyền tường minh; đây là smoke kỹ thuật, chưa phải benchmark độ chính xác OCR tiếng Hàn/Việt.

Ba PDF có 100/100/16 trang. Hai PDF 100 trang chạy canonical text toàn bộ và assert đủ 100 locators, không cắt mẫu. PDF 16 trang chạy thêm Docling structured; XLSX chạy thêm native cells theo D2. DOCX/PPTX chạy canonical extraction. Không bật Docling cho DOCX/PPTX hoặc giả gọi native spreadsheet là Docling.

File gốc và nội dung trích xuất không đưa vào Git. Harness/input manifest nằm trong `/tmp`; kết quả chỉ ghi metadata và số lượng, không log nội dung. Kết quả cuối của cả sáu test đã hoàn tất được ghi dưới đây.

| Tài liệu | Units đọc canonical | Ký tự đọc | Structured | Kết quả |
| --- | --- | --- | --- | --- |
| TOPCIT PDF | 100 | 126071 | Không yêu cầu | PASS |
| HKCA DOCX | 1 | 21666 | Không yêu cầu | PASS |
| IT Work Plan PPTX | 15 | 5174 | Không yêu cầu | PASS |
| Tiếng Hàn sơ cấp 2 PDF | 100 | 147927 | Không yêu cầu | PASS |
| ALLIVA PDF | 16 | 47852 | Docling: 16 trang | PASS |
| Báo cáo thiết bị XLSX | 1 | 23930 | Native cells: 1 sheet | PASS |

**6 tests / 52 assertions PASS**, không skip; 07:23.616, peak PHPUnit memory 66.13 MB (không phải tổng RSS subprocess hoặc benchmark capacity). Logs: `/tmp/lf-user-document-smoke.log`, `/tmp/lf-user-document-results.jsonl`. Harness: `/tmp/UserDocumentSmokeTest.php`; manifest input `/tmp/lf-user-document-inputs.json`.

DOCX trả 1 unit text canonical theo provider hiện tại, không suy ra tài liệu chỉ có 1 trang render. XLSX trả sheet locator; OCR metering vẫn dùng page theo contract, structured metering dùng sheet. Hai PDF 100 trang đều assert locator cuối là 100 và đủ 100 units. Mỗi file assert output ready, authorized read không rỗng, tổng text trong cap và SHA-256 file gốc không đổi. Hai structured cases assert job ready và có table/region được persist.

Cleanup: SQLite memory đóng cùng process; storage riêng của sáu file đã xoá. Đối chiếu lại SHA-256 cả sáu file gốc không đổi. Không đưa source/crop/text khách hàng vào repo. Smoke ứng dụng đã rollback fixture; không có worker/server mới được để chạy.

**Kết luận follow-up:** PASS_LOCAL_DOCUMENT_PROCESSING; GitHub main đã có D1–D6, schema ứng dụng sau migrate PASS, smoke Document/Docling trên schema ứng dụng PASS và sáu file thật PASS. Không có runtime defect mới trong lượt này. Vẫn không chứng nhận Redis/scheduler đang vận hành, supported-development deployment hoặc production; MariaDB 10.4.21 và fake virus scan là giới hạn môi trường đã công bố, không phải approval D1–D6 còn thiếu. Không sửa runtime hoặc thêm chức năng Document.
