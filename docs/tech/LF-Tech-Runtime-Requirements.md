# LF-Tech-Runtime-Requirements.md

Version: 1.5

Document Status: Draft

Implementation Status: Partial

Last Updated: 2026-08-31

Document Path: tech/LF-Tech-Runtime-Requirements.md

Related Specification:

* [LF-Tech-Stack](LF-Tech-Stack.md)
* [LF-Tech-AWS](LF-Tech-AWS.md)
* [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md)

---

# Scope

Tài liệu này liệt kê **mọi thứ phải được cài đặt và cấu hình** để LearnForge
chạy được, từ runtime nền tảng tới binary xử lý Media. Nó là tài liệu tham chiếu
triển khai: khi dựng môi trường production, đọc file này.

Nguồn của mọi con số dưới đây là source code và CI trên `main` tại ngày cập
nhật, không phải trí nhớ hội thoại. Cột "Nguồn" ghi rõ file để kiểm chứng lại.

Tài liệu này **không** quyết định công nghệ. Quyết định nằm ở
[LF-Tech-Stack](LF-Tech-Stack.md) và các ADR. Ở đây chỉ ghi lại yêu cầu cài đặt
tương ứng với quyết định đã có.

---

# 1. Runtime nền tảng

| Thành phần | Yêu cầu | Nguồn |
|---|---|---|
| PHP | `^8.2` (floor bắt buộc); CI chạy `8.3` | `composer.json`, `.github/workflows/*` |
| Composer | 2.x | CI `tools: composer:v2` |
| Laravel Framework | `^12.0` — lock hiện tại `v12.61.0` | `composer.json`, `composer.lock` |
| Livewire | `^4.3` — lock `v4.3.0` | `composer.lock` |
| Laravel Reverb | `^1.0` — lock `v1.10.2` | `composer.lock` |
| Predis | `^3.4` — lock `v3.4.2` | `composer.lock` |
| Node.js | `20` (baseline CI) | `.github/workflows/application-tests.yml` |
| PHPUnit | `^11.5.50` — lock `11.5.55` | `composer.lock` |

## 1.1. PHP extension

Bộ CI cài tường minh:

```text
ctype  curl  dom  fileinfo  mbstring  openssl  tokenizer  xml
pdo_sqlite   (job sqlite)
pdo_mysql    (job MariaDB)
```

Production cần thêm, do code thật sử dụng:

| Extension | Dùng ở đâu |
|---|---|
| `pdo_mysql` | Runtime database |
| `zip` | `ZipArchive` trong `LocalDocumentProcessingProvider::docxUnits()` |
| `xmlreader` | `XMLReader` khi đọc `word/document.xml` |

> **Khoảng trống đã ghi nhận:** `zip` **không** nằm trong danh sách extension của
> bất kỳ job CI nào. Test suite không phát hiện được vì `phpunit.xml` đặt
> `MEDIA_OCR_PROVIDER=fake`, nên đường DOCX thật không bao giờ chạy trong CI.
> Production thiếu `ext-zip` sẽ hỏng ở đúng nhánh DOCX và không có test nào báo.

`ext-redis` **không** bắt buộc: `REDIS_CLIENT=predis`, và Predis là thư viện
thuần PHP.

---

# 2. Database

| Hạng mục | Yêu cầu | Nguồn |
|---|---|---|
| MySQL | `>= 8.0.16` | [LF-Tech-Stack](LF-Tech-Stack.md#database-version-floor) |
| MariaDB | `>= 10.5` | như trên |
| CI integration | MariaDB `11.4.3` | `.github/workflows/*` |

Floor là bắt buộc vì LearnForge dựa vào `CHECK` constraint **được thi hành**,
composite foreign key, JSON và trigger. Preflight deployment phải truy vấn version
server và fail **trước** migration nếu ngoài hợp đồng. Server parse được `CHECK`
nhưng không thi hành là không được hỗ trợ.

SQLite in-memory chỉ là baseline test mặc định (`phpunit.xml`). Nó **không** tạo
CHECK constraint, tenant-first unique key hay RESTRICT foreign key của các
migration đã phát hành — các migration đó guard DDL sau một driver check. Một
lần chạy test mặc định xanh **không** chứng minh gì về các ràng buộc này; chỉ job
MariaDB mới chứng minh.

---

# 3. Hạ tầng phụ trợ

| Thành phần | Vai trò | Cấu hình mặc định |
|---|---|---|
| Redis | cache, queue, session | `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, port `6379` |
| Reverb | broadcasting | `BROADCAST_CONNECTION=reverb`, port `8080` |
| Nginx | web server | [LF-Tech-Stack](LF-Tech-Stack.md) |
| SMTP | mail | `MAIL_MAILER=smtp` |

Tenant local dùng dạng `<tenant>.localhost`; production dùng `APP_BASE_DOMAIN`
và `APP_TENANT_SCHEME`.

---

# 4. Binary bên ngoài

Đây là phần hay bị bỏ sót nhất khi dựng môi trường mới. Không có các binary này
thì upload vẫn chạy nhưng output dẫn xuất fail im lặng theo từng profile.

| Binary | Cần cho | Env override | Mặc định |
|---|---|---|---|
| `ffprobe` | Đọc metadata media | `MEDIA_FFPROBE_BINARY` | `ffprobe` |
| `pdftotext` | Poppler — PDF text layer | `MEDIA_PDFTOTEXT_BINARY` | `pdftotext` |
| `pdftoppm` | Poppler — render trang thành ảnh | `MEDIA_PDFTOPPM_BINARY` | `pdftoppm` |
| `pdfinfo` | Poppler — đếm trang | `MEDIA_PDFINFO_BINARY` | `pdfinfo` |
| `tesseract` | OCR | `MEDIA_TESSERACT_BINARY` | `tesseract` |
| `soffice` | LibreOffice headless — convert Office sang PDF | `MEDIA_SOFFICE_BINARY` | `soffice` |

## 4.1. Tesseract language data

Bắt buộc có, vì map locale là tường minh và **không có language detection**:

```text
vi → vie+eng
ko → kor+eng
en → eng
locale khác → unsupported_source (fail-closed)
```

Nghĩa là cần cài đủ `vie`, `kor`, `eng` traineddata.

## 4.2. Quy tắc ghim version

Local worker và AWS worker **phải ship cùng binary/config version**. Thay binary,
language data, DPI hoặc conversion config có ảnh hưởng output thì **phải tăng**
`MEDIA_OCR_VERSION` — đó là điều kiện để output cũ chuyển `archived` thay vì bị
ghi đè, và để một citation cũ vẫn đọc lại được đúng bản nó đã đọc.

Worker cần ephemeral disk đủ cho source cộng PDF/ảnh trung gian, và quyền IAM đọc
source object khi chạy trên S3.

---

# 5. Biến môi trường

`.env` không bao giờ được commit. `.env.example` là template, không phải bản sao
lưu.

## 5.1. Provider và version — cổng fail-closed

```dotenv
MEDIA_VIRUS_SCAN_PROVIDER=      MEDIA_VIRUS_SCAN_VERSION=
MEDIA_OCR_PROVIDER=             MEDIA_OCR_VERSION=
MEDIA_SPEECH_TO_TEXT_PROVIDER=  MEDIA_SPEECH_TO_TEXT_VERSION=
MEDIA_CAPTION_PROVIDER=         MEDIA_CAPTION_VERSION=
MEDIA_THUMBNAIL_PROVIDER=       MEDIA_THUMBNAIL_VERSION=
MEDIA_TRANSCODE_PROVIDER=       MEDIA_TRANSCODE_VERSION=

MEDIA_STRUCTURED_EXTRACTION_PROVIDER=   MEDIA_STRUCTURED_EXTRACTION_VERSION=
```

Cặp cuối thêm ở v1.2 (2026-08-27) theo `job_type = 'structured_extraction'` của
[Processing Contract § 2](../platform/LF-Media-Processing-Contract.md). Bỏ trống
thì job đó **không được enqueue**; vì nó là job optional nên `media_files` vẫn
`ready` và delivery không bị ảnh hưởng — khác `virus_scan`.

Bỏ trống = `unconfigured`. Khi đó `virus_scan` trả `provider_unavailable`, và vì
`virus_scan` là job bắt buộc cho **mọi** file type, file vừa upload chuyển
`failed` và delivery trả 404. Đây là hành vi fail-closed có chủ ý.

Giá trị `fake` chỉ dùng cho development và test. Nó **không** quét virus và
**không** gọi provider thật. Production bắt buộc dùng provider đã được duyệt và
cấp credential.

Provider tài liệu self-hosted hiện có:

```dotenv
MEDIA_OCR_PROVIDER=local_document
MEDIA_OCR_VERSION=local-document-v1
```

## 5.2. Resource control — là contract, không phải tuning

| Env | Mặc định | Ý nghĩa |
|---|---|---|
| `MEDIA_DOCUMENT_MAX_PROCESSING_SECONDS` | `3300` | Provider deadline |
| `MEDIA_DOCUMENT_COMMAND_TIMEOUT_SECONDS` | `300` | Timeout một command đơn lẻ |
| `MEDIA_OFFICE_TIMEOUT_SECONDS` | `900` | Timeout LibreOffice |
| `MEDIA_DOCUMENT_MAX_PAGES` | `100` | Trang tối đa cho một OCR revision |
| `MEDIA_DOCX_MAX_XML_BYTES` | `8000000` | `word/document.xml` expanded |
| `MEDIA_OCR_DPI` | `200` | DPI render trang |
| `MEDIA_MAX_EXTRACTED_CHARACTERS` | `500000` | Text dẫn xuất tối đa mỗi revision |

### Namespace `structured_extraction` — v1.2, 2026-08-27

| Env | Mặc định | Ý nghĩa |
|---|---|---|
| `MEDIA_STRUCTURED_MAX_PAGES` | `100` | Trang tối đa một revision cấu trúc |
| `MEDIA_STRUCTURED_MAX_EXTRACTED_CHARACTERS` | `500000` | Tổng text page/sheet + region + cell |
| `MEDIA_STRUCTURED_MAX_REGIONS_PER_PAGE` | `50` | Trần region theo trang |
| `MEDIA_STRUCTURED_MAX_REGIONS_PER_DOCUMENT` | `5000` | Trần region toàn tài liệu |
| `MEDIA_STRUCTURED_MAX_TABLE_CELLS_PER_DOCUMENT` | `200000` | Row cell thực persist |
| `MEDIA_STRUCTURED_MAX_PROCESSING_SECONDS` | `3300` | Deadline provider, giữ bất biến `3300 < 3600 < 3900` |
| `MEDIA_STRUCTURED_COMMAND_TIMEOUT_SECONDS` | `900` | Một lần gọi engine trên cả tài liệu |

Các giá trị này nằm ở namespace `media.processing.local_document.*` và
`media.processing.structured_extraction.*` tương ứng. Một provider
mới đọc namespace khác sẽ khởi động với **không giới hạn nào**, và
`page_limit_exceeded` cùng `extracted_text_too_large` sẽ im lặng biến mất. Provider
mới phải áp dụng lại chúng tường minh.

## 5.3. Thứ tự timeout của queue

Bất biến bắt buộc:

```text
provider deadline (3300s) < worker timeout (3600s) < queue visibility (3900s)
```

```dotenv
REDIS_QUEUE_RETRY_AFTER=3900
DB_QUEUE_RETRY_AFTER=3900
BEANSTALKD_QUEUE_RETRY_AFTER=3900
```

`ProcessMediaProcessingJob::$timeout = 3600`, `$tries = 1`. Nếu
`retry_after` nhỏ hơn, queue có thể phát lại đúng job đó trong khi worker đầu vẫn
đang OCR.

Với **SQS**, deployment phải đồng thời chứng minh:

* visibility timeout `> 3600s`,
* supervisor `--timeout >= 3600s`,
* message retention lớn hơn visibility timeout.

Đây là điều kiện triển khai, không phải runbook ngầm.

## 5.4. Storage

| Env | Ghi chú |
|---|---|
| `MEDIA_DISK` | `media_local` cho local; S3 disk cho production |
| `MEDIA_SIGNED_URL_TTL_MINUTES` | `10` |
| `MEDIA_MAX_UPLOAD_KILOBYTES` | `.env.example` đặt `1048576` (1 GB); mặc định trong `config/media.php` là `102400` (100 MB) |
| `MEDIA_AWS_*` | bucket, region, endpoint, path-style |

Storage luôn riêng tư; mọi truy cập qua signed delivery có thời hạn.

---

# 6. Điểm dispatch job

`config/queue.php` đặt `after_commit => false` trên **mọi** connection. Hợp đồng
được thoả bằng code chứ không bằng cấu hình:

* `ProcessMediaProcessingJob` đặt `$this->afterCommit = true` trong constructor;
* `MediaService` và `MediaProcessingOrchestrator` bọc dispatch trong
  `DB::afterCommit()`.

Không được đổi `after_commit` ở đâu đó rồi cho rằng cấu hình đang gánh việc này.
Enqueue trong transaction tạo Activity sẽ khiến worker đi tìm một usage đã bị
rollback.

---

# 7. Môi trường CI

CI là định nghĩa có thẩm quyền của "môi trường tối thiểu chạy được".

| Job | Runtime | Database |
|---|---|---|
| Laravel Application Tests | PHP 8.3, Node 20 | SQLite in-memory |
| Database Constraint Integration | PHP 8.3 | MariaDB 11.4.3 |
| Documentation Lint | PHP 8.3 | — |
| Schema Drift | PHP 8.3 | MariaDB 11.4.3 |

Job application-tests chạy `npm run build` trước test: layout backend/auth/tenant
render `@vite(...)` ở mọi request, thiếu build thật sẽ ném
`ViteManifestNotFoundException` ở phần lớn suite.

Quality gate chạy được tại local:

```bash
php artisan test
php artisan docs:lint
php artisan schema:drift --docs-only
php artisan schema:drift --connection=mysql
```

---

# 8. Điều kiện bắt buộc trước khi deploy production

Không được deploy media processing runtime trước khi đồng thời đạt:

1. Forward migration Media Processing đã apply và có trong migration ledger.
2. `MEDIA_VIRUS_SCAN_PROVIDER` trỏ tới provider production đã được duyệt, có
   credential và runtime contract hợp lệ.

Không được ship với giá trị rỗng hoặc `unconfigured` rồi cấu hình sau: mọi upload
mới sẽ lập tức `failed/provider_unavailable` và delivery trả 404. Provider
OCR/STT/caption chưa có chỉ chặn capability tương ứng; virus scan provider là điều
kiện của toàn bộ đường upload.

Các gate còn mở, phải đóng trước khi mở cho tenant thật:

| # | Gate |
|---|---|
| R1 | `saas_usage_*` chưa triển khai; không hạn mức nào được thi hành. Chặn media processing self-service |
| R2 | Chưa có chính sách retention/redaction cho extracted text và transcript |
| R3 | Media Read Contract chưa có independent reviewer |
| R8 | Chưa có stale-processing sweeper; `SIGKILL`/OOM/eviction để row treo `processing` và giữ concurrency guard |

---

# 9. Môi trường benchmark A0 — đã gỡ, giữ làm bản ghi lịch sử

Ghi lại để tái lập được, **không** phải hạng mục cài đặt của production. Docling
chưa được deploy, và deploy nó cần Tech Stack amendment: Official Stack không có
runtime Python và Infrastructure ghi `Docker (Future)`.

Môi trường đã dựng (macOS x86_64):

| Thành phần | Version |
|---|---|
| Python | `3.12.13` |
| `docling` | `2.119.0` |
| `docling-core` | `2.92.0` |
| `docling-parse` | `7.15.0` |
| `docling-ibm-models` | `3.14.0` |
| PyTorch | `2.2.2` |
| NumPy | `1.26.4` |
| Transformers | `4.57.6` |
| psutil | `7.0.0` |

> **Đã gỡ 2026-08-25.** A0 đóng với kết luận giữ Poppler/Tesseract; harness,
> models và virtualenv đã bị xoá khỏi repository và khỏi máy. Mục này giữ lại làm
> bản ghi những gì đã được xác minh, **không** phải yêu cầu runtime còn hiệu lực.
> Xem [LF-A0-Docling-Closure-Evidence](../quality/LF-A0-Docling-Closure-Evidence.md).

Model offline từng nằm tại `benchmarks/a0-docling/models/`:

* Layout Heron và TableFormer.
* 60 files, khoảng 669 MB.
* Inventory SHA-256: `c3ffba780d5d4dffa6e4f469fc82bedbce1839d7d9e71deba60934196d1284b4`.

Trạng thái đã xác minh:

* Offline `DocumentConverter` khởi tạo thành công.
* Locale smoke `vie+eng`; **không** auto-detect.
* **Không** cài EasyOCR, RapidOCR, PaddleOCR hay vision model.

Quy tắc cô lập vẫn còn hiệu lực cho bất kỳ harness nào dựng lại sau này: không
nằm trong `app/` và không bind vào service provider nào — một class extractor
trong `app/Services` có binding sẵn có thể được bật bằng đúng một dòng `.env`.

Protocol và tiêu chí đạt: `LF-A0-Docling-Benchmark-Protocol.md` ở thư mục gốc
repository, hiện ở trạng thái **Archived**.

---

# 10. Xung đột và khoảng trống đã ghi nhận

Ghi ở đây thay vì tự chọn bên:

| # | Nội dung |
|---|---|
| 1 | `README.md` gốc ghi "PHP 8.3+"; `composer.json` yêu cầu `^8.2`; CI chạy `8.3`. Ba nguồn, hai con số |
| 2 | `ext-zip` bắt buộc cho đường DOCX nhưng không có trong extension list của bất kỳ job CI nào |
| 3 | `MEDIA_MAX_UPLOAD_KILOBYTES`: `.env.example` `1048576` khác mặc định `config/media.php` `102400` |
| 4 | Stale-processing sweeper (R8) chưa implement; bắt buộc trước AWS production |
| 5 | STT, caption, thumbnail, transcode provider đều `unconfigured`; các capability đó chưa chạy được |

---

# 11. Structured extraction — cần cài thêm gì

Cập nhật 2026-08-27, sau khi
[ADR-0019](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) được duyệt.
Câu trả lời khác nhau theo từng giai đoạn, nên tách rõ.

## 11.1. Giai đoạn spreadsheet (region/table/cell từ XLSX) — không cần cài gì mới

Đọc ô Excel trực tiếp dùng đúng hai extension đã liệt kê ở § 1.1:

| Cần | Trạng thái |
|---|---|
| `ext-zip` | Đã dùng trong `LocalDocumentProcessingProvider`; **thiếu trong mọi job CI** — xem § 10 mục 2 |
| `ext-dom` | D2 native worksheet parser `DocumentSpreadsheetReader`; đã thuộc extension nền ở §1.1. DOCX vẫn dùng XMLReader. |
| Binary ngoài | XLSX không cần binary; XLS legacy dùng LibreOffice đã có để chuyển XLSX, không render PDF. |
| Database | MariaDB `11.4.3` của job `integration-mysql` — đã có |

Nghĩa là năng lực bảng/ô đầu tiên của LearnForge **không phụ thuộc một phần mềm
mới nào**. Việc cài đặt duy nhất phải làm là đóng khoảng trống `ext-zip`: thêm
nó vào extension list của CI và xác nhận nó có trên production. Hiện
`phpunit.xml` đặt `MEDIA_OCR_PROVIDER=fake` nên đường đọc file thật không bao giờ
chạy trong CI, và một production thiếu `ext-zip` sẽ hỏng đúng ở nhánh DOCX/XLSX
mà không test nào báo.

## 11.2. Layout PDF bằng Docling — runtime đã có, production chưa deploy

[LF-Tech-Stack](LF-Tech-Stack.md) đã nhận Python 3.11 + Docling 2.119.0 làm
runtime local/offline cho `structured_extraction`. Provider đã implemented tại
`DoclingStructuredExtractionProvider`; checkbox PDF là opt-in và Docling **không
thay** OCR canonical Poppler/Tesseract.

Footprint đo từ A0 dùng để sizing worker:

| Chỉ số | Poppler + Tesseract | Docling |
|---|---:|---:|
| p95 giây/trang | 1.73 | 4.01 |
| Peak RSS | 148 MB | 2.23 GB |

Chênh lệch bộ nhớ 15× nghĩa là worker hiện tại **không** chạy được engine đó ở
cùng cấu hình; đây là thay đổi hạ tầng có chi phí, không phải một dependency.

### Inventory phải giữ parity

| Thành phần | Giá trị đã xác minh local |
|---|---|
| Python | 3.11 x86_64 |
| Docling | 2.119.0 |
| `requirements.lock` SHA-256 | `6b2ae5e4e8055705ee693d924db7b83660ab6d6e990096e10477fd083583fa30` |
| `extract.py` SHA-256 | `70a48024487d263860b9b49dfccb4a44ad340f3016b2b39ea674408c4a19f226` |
| Model inventory | 60 files, khoảng 669 MB |
| Model inventory SHA-256 | `53656bed209f4d3300eb5e4ba8e2c4c93b60e768eff9a1ee0bb132e5340b655e` |
| Execution | CPU, offline, remote services disabled, `do_ocr=false` |

Model hash được tính từ danh sách SHA-256 của từng file sau khi sort theo path.
Không so hash của tar/zip vì metadata đóng gói có thể thay đổi dù nội dung model
không đổi.

### Blocker đóng gói trước production

`runtime/docling/requirements.lock` hiện pin dependency trực tiếp nhưng **chưa
phải full transitive lock có hash**. Chạy `pip install -r` trực tiếp từ Internet
có thể resolve một dependency chuyển tiếp khác với local.

Trước production phải tạo, review và lưu trong private deployment artifact:

1. full lock cho đúng Python 3.11 và architecture của worker;
2. wheelhouse chứa toàn bộ wheel chuyển tiếp;
3. checksum manifest của wheelhouse và model;
4. SBOM/vulnerability scan theo quy trình deployment;
5. build provenance gắn với commit LF và `processing_version`.

Production cài bằng `--no-index --find-links`, không tải package/model lúc worker
khởi động. Không commit `.venv`, wheelhouse hoặc model lớn vào Git.

## 11.3. Runbook cài production Docling

### A. Chuẩn bị image/host

Worker cần Python 3.11 đúng architecture, Poppler (`pdfinfo`, `pdftoppm`,
`pdftotext`), Tesseract cùng language pack được dùng (`vie`, `kor`, `eng`), và
disk tạm đủ cho render PDF/crop. LibreOffice vẫn cần cho nhánh Office canonical,
không phải cho Docling PDF.

Không dùng Python hệ thống của web process. Dùng virtualenv riêng dưới release
hoặc shared runtime có version, ví dụ:

```bash
PY311=/absolute/path/to/python3.11
$PY311 --version
$PY311 -m venv /srv/learnforge/runtime/docling/.venv
/srv/learnforge/runtime/docling/.venv/bin/python -m pip install \
  --no-index --find-links=/srv/learnforge/artifacts/docling-wheelhouse \
  -r /srv/learnforge/current/runtime/docling/requirements.lock
/srv/learnforge/runtime/docling/.venv/bin/python -m pip check
```

`PY311` phải là đường dẫn tuyệt đối đã được quản lý bởi image/host. Không
`pip install` system-wide, không `brew link`/sửa PATH toàn hệ thống và không dùng
virtualenv benchmark làm production runtime.

### B. Đặt model offline

Copy model đã duyệt từ private build artifact vào thư mục chỉ worker đọc được:

```text
/srv/learnforge/runtime/docling/models/
```

Xác minh đủ 60 file và inventory SHA-256 ở trên. Worker production không cần
quyền ghi vào model directory. Không cho phép fallback tải Hugging Face. Adapter
gán cưỡng bức:

```text
HF_HUB_OFFLINE=1
TRANSFORMERS_OFFLINE=1
DOCLING_SERVE_ENABLE_REMOTE_SERVICES=false
```

và `enable_remote_services=false`, `allow_external_plugins=false` trong Docling.

### C. Biến môi trường production

Các đường dẫn sau chỉ là mẫu; thay bằng absolute path của release thực tế:

```dotenv
MEDIA_STRUCTURED_EXTRACTION_PROVIDER=docling_local
MEDIA_STRUCTURED_EXTRACTION_VERSION=docling-2.119.0-layout-v5
MEDIA_DOCLING_PYTHON_BINARY=/srv/learnforge/runtime/docling/.venv/bin/python
MEDIA_DOCLING_SCRIPT=/srv/learnforge/current/runtime/docling/extract.py
MEDIA_DOCLING_ARTIFACTS_PATH=/srv/learnforge/runtime/docling/models
MEDIA_DOCLING_TIMEOUT_SECONDS=3300
MEDIA_DOCLING_MAX_OUTPUT_BYTES=67108864

MEDIA_STRUCTURED_MAX_PAGES=100
MEDIA_STRUCTURED_MAX_EXTRACTED_CHARACTERS=500000
MEDIA_STRUCTURED_MAX_REGIONS_PER_PAGE=100
MEDIA_STRUCTURED_MAX_REGIONS_PER_DOCUMENT=5000
MEDIA_STRUCTURED_MAX_TABLE_CELLS_PER_DOCUMENT=200000
MEDIA_STRUCTURED_MAX_PROCESSING_SECONDS=3300
MEDIA_STRUCTURED_COMMAND_TIMEOUT_SECONDS=900

MEDIA_STRUCTURED_CROP_ENABLED=true
MEDIA_STRUCTURED_CROP_DPI=200
MEDIA_STRUCTURED_CROP_OCR_ENABLED=true
MEDIA_STRUCTURED_CROP_OCR_MIN_TEXT_CHARACTERS=2
MEDIA_STRUCTURED_MAX_CROP_BYTES_PER_DOCUMENT=67108864

REDIS_QUEUE_RETRY_AFTER=3900
DB_QUEUE_RETRY_AFTER=3900
BEANSTALKD_QUEUE_RETRY_AFTER=3900
```

Không đổi `MEDIA_STRUCTURED_EXTRACTION_VERSION` cho một redeploy byte-identical.
Phải đổi version khi package, model, adapter hoặc config làm thay đổi output;
đó là revision boundary, không phải nhãn release trang trí.

### D. Queue, process và tài nguyên

Giữ bất biến:

```text
provider deadline 3300s < Laravel job timeout 3600s < visibility/retry_after 3900s
```

Supervisor/systemd không được override thấp hơn 3600 giây. Với SQS, visibility
timeout phải lớn hơn 3600 giây và message retention phải lớn hơn visibility.

Không đặt Docling worker chung concurrency với worker nhẹ nếu tổng RAM không đủ.
Peak đã đo khoảng 2,23 GB/job; cộng headroom cho PHP, render crop, OS và file tạm.
Sizing/concurrency phải được load-test trên instance AWS thực tế, không lấy số
macOS làm SLA.

### E. Storage và quyền

Worker cần quyền tối thiểu để:

* đọc source Media private;
* ghi/đọc/xoá crop dưới prefix tenant/media tương ứng;
* ghi file JSON tạm và ảnh render trong system temp;
* không đọc bucket/prefix tenant khác ngoài đường xử lý đã scope.

Nếu dùng S3, cấu hình `MEDIA_DISK` và `MEDIA_AWS_*` theo § 6. IAM phải cho phép
delete crop vì rollback/failure cleanup và sweeper cần quyền đó. Lifecycle S3
không thay thế database-aware purge.

### F. Migration và thứ tự rollout

1. Backup và kiểm migration ledger.
2. Apply migration structured extraction/crop trên MariaDB.
3. Chạy `schema:drift --connection=mysql`.
4. Deploy PHP code, adapter, wheelhouse/venv và model inventory.
5. Cấu hình env rồi clear/rebuild config cache.
6. Khởi động worker Docling với concurrency đã sizing.
7. Chạy smoke bằng PDF fixture đã được phép, không PII hoặc có approval cụ thể.
8. Chỉ sau smoke mới cho admin sử dụng checkbox.

Không bật provider trước khi Python executable, script và model path đều tồn
tại; preflight sẽ fail `provider_unavailable`, nhưng đó vẫn là deployment lỗi.

### G. Dọn rác storage — Owner quyết định 2026-08-28 (bản thay thế)

**Thay thế policy "hourly + cảnh báo + mục tiêu 24 giờ" ghi trước đó cùng ngày.**

**Không đăng ký sweeper vào scheduler.** Không có tác vụ nào tự động xoá file.
Deployment nào thêm cron/scheduler cho lệnh này là **sai policy**, không phải bổ
sung thiếu sót.

Lý do: xoá file không hoàn tác được, và thứ bị xoá được nhận diện bằng suy luận
"không còn row nào trỏ tới". Sai sót trong suy luận đó khi chạy tự động sẽ xoá
dữ liệu thật mà không ai kịp thấy. Rác này lại không ai với tới được — không row
thì không ký được URL — nên dọn sớm không mua thêm an toàn.

Hai lối chạy, đều do người khởi động:

* Nút **Dọn ngay** trong Quản lý Media (chỉ tenant hiện tại, có bước xác nhận).
* Operator chạy tay, nên xem trước bằng `--dry-run`:

```bash
php artisan media:purge-deleted-storage --dry-run
php artisan media:purge-deleted-storage
```

Vì không có job tự động nên **không có yêu cầu alerting hay lock single-server**.
Hệ quả được chấp nhận: một lần dọn thất bại chỉ lộ ra qua log mức error cho tới
khi có người bấm lại.

### H. Smoke/verification bắt buộc

Chạy dưới đúng OS user của queue worker:

```bash
test -x "$MEDIA_DOCLING_PYTHON_BINARY"
test -f "$MEDIA_DOCLING_SCRIPT"
test -d "$MEDIA_DOCLING_ARTIFACTS_PATH"
"$MEDIA_DOCLING_PYTHON_BINARY" -m pip check
php artisan migrate:status
php artisan schema:drift --connection=mysql
php artisan docs:lint
```

Smoke chức năng phải xác nhận:

* PDF không tick: chỉ canonical OCR, không structured job;
* PDF tick: job `structured_extraction` thành `ready` và có region/table/cell khi
  fixture chứa các cấu trúc đó;
* structured failure không làm Media mất `ready`;
* PDF trên 100 trang trả `page_limit_exceeded` trước model processing;
* crop private có signed delivery và tenant khác không đọc được;
* failure/timeout không để crop mồ côi; sweeper dọn được residue chủ động tạo;
* outbound network bị chặn mà Docling vẫn chạy từ model offline.

### I. Monitoring và rollback

Theo dõi ít nhất: số job pending/processing/ready/failed, latency p50/p95,
`provider_unavailable`, `provider_timeout`, `structured_extraction_too_large`,
RAM/OOM, temp disk, crop bytes và kết quả của mỗi lần dọn storage thủ công.

Rollback an toàn:

1. ngừng enqueue structured extraction/ẩn checkbox;
2. drain hoặc dừng worker có kiểm soát;
3. giữ model/venv cho tới khi không còn job đang chạy;
4. không rollback crop columns khi còn row tham chiếu — migration cố ý fail-closed;
5. không xoá canonical OCR text; Docling là optional derivative.

## 11.4. Không thuộc phạm vi cài đặt

Diễn giải ảnh/biểu đồ là AI theo
[ADR-0020](../adr/ADR-0020-AI-Vision-Interpretation-Boundary.md): provider AI,
`ai_model_runs`, quota. Không có binary nào cài lên worker Media giải quyết việc
đó, và không được cài với lý do "để Media đọc biểu đồ".

---

# Owner

Architecture Team

# Primary Consumers

* Backend Developers
* DevOps
* Reviewer
* AI Agents
