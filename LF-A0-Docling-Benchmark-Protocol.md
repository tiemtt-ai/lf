# LF A0 Docling Benchmark Protocol

Version: 1.1

Document Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-08-25

Document Path: LF-A0-Docling-Benchmark-Protocol.md

Related ADR:

* [ADR-0004 — Media Foundation](docs/adr/ADR-0004-Media-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](docs/adr/ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0018 — Media PII And External Processing Boundary](docs/adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved

Related Specification:

* [LF-Media-Processing-Contract](docs/platform/LF-Media-Processing-Contract.md)
* [LF-Media-Read-Contract](docs/platform/LF-Media-Read-Contract.md)
* [media_extracted_texts](docs/database/media/media_extracted_texts.md)

---

# Scope

Đây là **protocol đánh giá offline (A0)**. Nó không phải ADR, không phải database
doc, không phải architecture review.

Mục tiêu duy nhất: trả lời được bằng bằng chứng câu hỏi **"Docling có đủ điều
kiện thay thế implementation của `ocr` provider hiện hữu không?"** — trước khi
bất kỳ dòng code runtime nào được deploy.

Không thuộc phạm vi A0:

* Deploy Docling như provider (đó là A1, cần Tech Stack amendment).
* Region/layout persistence, table/chart extraction (Phase C, cần ADR Phase B).
* Vision interpretation (chặn bởi R1/R2 và Future Extensions của ADR-0006).
* Sửa `media_extracted_texts`, Read Contract, hay bất kỳ vocabulary nào.

## Quy tắc cô lập

Harness A0 **không được** nằm trong `app/` và **không được** đăng ký vào bất kỳ
service provider nào. Vị trí đề xuất:

```text
benchmarks/a0-docling/
```

Lý do không phải hình thức: `config('media.processing.providers.ocr')` phân giải
provider theo tên. Một class Docling nằm trong `app/Services` với binding sẵn có
thể được bật bằng đúng một dòng `.env`, tức là A1 xảy ra mà không ai duyệt.

---

# 1. Hai nhóm gate bắt buộc

Hai nhóm này độc lập. **Citation parity là điều kiện loại trừ**: không đạt thì
Docling không đủ tư cách thay thế, dù chất lượng OCR cao hơn bao nhiêu.

| Nhóm | Bản chất | Ngưỡng |
|---|---|---|
| G1 — Contract parity | Binary | 100%, không có ngoại lệ |
| G2 — Citation parity | Binary | 100%, không có ngoại lệ |
| G3 — Chất lượng trích xuất | Threshold | Xem §5 |
| G4 — Tài nguyên | Threshold | Xem §6 |

G1 và G2 fail ⇒ báo cáo A0 verdict `FAIL`, dừng. Không có "fail nhưng chấp nhận
được" cho hai nhóm này.

---

# 2. G1 — Contract parity

`LF-Media-Processing-Contract` §3 tuyên bố resource controls là **contract, chứ
không phải tuning tuỳ ý**. Trong code hiện tại chúng nằm ở namespace
`media.processing.local_document.*` — nghĩa là một provider mới đọc namespace
khác sẽ khởi động với **không giới hạn nào**, và các error code dưới đây im lặng
biến mất.

Harness phải chứng minh Docling path áp dụng đúng các giới hạn sau, đọc từ cấu
hình chứ không hard-code:

| Config key | Giá trị hiện tại | Nguồn |
|---|---|---|
| `max_pages` | 100 | `config/media.php` |
| `max_extracted_characters` | 500000 | `config/media.php` |
| `max_docx_xml_bytes` | 8000000 | `config/media.php` |
| `ocr_dpi` | 200 | `config/media.php` |
| `max_processing_seconds` (provider deadline) | 3300 | Contract §3 |
| `command_timeout_seconds` | 300 | Contract §3 |
| `office_timeout_seconds` | 900 | Contract §3 |

## 2.1. Ma trận error code

Mỗi dòng cần ít nhất một fixture tái lập được. Error code lấy từ
`LocalDocumentProcessingProvider` thực tế trên `main`:

| Error code | Fixture kích hoạt |
|---|---|
| `unsupported_source` | Extension ngoài `txt/docx/pdf/doc/xls/xlsx/ppt/pptx`; và locale ngoài `vi/ko/en` |
| `source_unavailable` | Storage stream không mở được |
| `corrupt_source` | PDF cắt cụt; DOCX zip hỏng; TXT không phải UTF-8; `pdfinfo` không trả `Pages:` |
| `source_expansion_limit_exceeded` | DOCX có `word/document.xml` > 8.000.000 bytes (kiểm cả ZIP metadata **và** đếm byte trong vòng copy) |
| `extracted_text_too_large` | Document sinh > 500.000 ký tự |
| `page_limit_exceeded` | PDF 101 trang |
| `provider_timeout` | Deadline còn < 1 giây khi vào command kế tiếp |
| `provider_command_failed` | LibreOffice không sinh PDF |
| `provider_unavailable` | Không tạo được thư mục tạm |
| `no_extractable_text` | Document hợp lệ nhưng không unit nào có text |
| `missing_canonical_locale` | `output_profile` không có key `locale` |

## 2.2. Fail-closed locale

Map hiện hành là tường minh và **không có language detection**:

```text
vi → vie+eng
ko → kor+eng
en → eng
khác → unsupported_source
```

Docling tự phát hiện ngôn ngữ. Harness phải chứng minh khả năng đó **bị tắt hoặc
bị ghi đè** bởi locale canonical từ `output_profile`. Một provider tự đoán ngôn
ngữ vi phạm nguyên tắc "locale canonical là source of truth" trong Contract §1 —
và đây chính là loại lỗi mà §4 của Read Contract mô tả là *"không ai phát hiện
được từ output"*.

---

# 3. G2 — Citation parity

`source_fingerprint = SHA-256(checksum || ':' || file_type)`. Nó **không đổi** khi
đổi engine. Nên nếu Docling đánh số trang khác Poppler, không có tín hiệu tự
động nào báo: revision cũ vẫn đọc đúng theo Read Contract §4.1, nhưng citation
*mới* mang nghĩa khác citation *cũ* trong khi mọi metadata đều nói chúng cùng
nguồn.

Bốn phép so, tất cả binary:

## C1 — Tổng số trang

`COUNT(DISTINCT locator_value)` và `MAX(sequence)` phải khớp tuyệt đối với
`pdfinfo Pages:` của cùng file.

Lưu ý hành vi hiện tại: `unit()` trả `[]` khi text rỗng, nên **trang trắng không
sinh row**. `sequence` bằng đúng số trang chứ không phải chỉ số dày đặc. Docling
phải giữ nguyên tính chất này — một trang trắng ở giữa tài liệu không được làm
các trang sau lùi số.

## C2 — Ranh giới trang

Với mỗi trang `k`, nội dung Docling trả về phải là nội dung trang `k` của ground
truth. Đo bằng token-level alignment (không so chuỗi thô, vì OCR sai chính tả là
chuyện bình thường):

```text
boundary_score(k) = |LCS(tokens_docling(k), tokens_truth(k))|
                    / max(|tokens_docling(k)|, |tokens_truth(k)|)
```

Đạt khi `boundary_score(k) >= 0.85` với **mọi** `k`, và không tồn tại `k` nào mà
`boundary_score(k, k±1) > boundary_score(k, k)` — điều kiện thứ hai bắt lỗi lệch
trang, loại lỗi mà điều kiện thứ nhất một mình có thể bỏ sót.

## C3 — Reading order đa cột

Corpus phải có tài liệu 2 và 3 cột cho cả VI và KR. Ground truth ghi thứ tự
paragraph anchor. Đo bằng Kendall tau giữa thứ tự anchor do engine trả về và thứ
tự ground truth.

Đạt khi `tau >= 0.95` trên mọi tài liệu đa cột, và Docling **không thấp hơn**
đường Poppler `pdftotext -layout` hiện tại trên bất kỳ tài liệu nào.

## C4 — Rotation và landscape

Tài liệu có trang xoay 90/180/270 và trang landscape phải:

* giữ đúng số trang (C1),
* sinh text không rỗng,
* không làm đảo thứ tự trang.

---

# 4. Corpus

## 4.1. Phân tầng

Mỗi tầng cần đủ mẫu cho **cả `vi` và `ko`**. `en` là đối chứng, không phải mục
tiêu.

| # | Tầng | Tối thiểu / locale | Mục đích |
|---|---|---|---|
| S1 | PDF text layer, 1 cột | 10 | Baseline |
| S2 | PDF text layer, đa cột | 10 | C3 |
| S3 | PDF scan sạch (≥300 DPI) | 15 | Chất lượng OCR |
| S4 | PDF scan kém (ảnh chụp, nghiêng, nhiễu) | 15 | Chất lượng OCR biên |
| S5 | **PDF hỗn hợp** (một số trang có text layer, một số là scan) | 10 | Xem §4.2 |
| S6 | Trang xoay / landscape | 5 | C4 |
| S7 | DOCX có text | 5 | Đường `embedded_text` |
| S8 | DOCX không có text (chỉ ảnh) | 3 | Đường fallback `officeUnits` |
| S9 | Legacy Office (`doc`, `xls`, `xlsx`, `ppt`, `pptx`) | 5 | LibreOffice conversion |
| S10 | Trang trắng xen kẽ | 3 | C1, hành vi `sequence` |
| S11 | Bảng dày đặc | 10 | **Chỉ đo, không chấm** (A+) |
| S12 | Biểu đồ / sơ đồ | 10 | **Chỉ đo, không chấm** (A+) |
| S13 | Biên: đúng 100 trang, 101 trang, ~500k ký tự | 6 | G1 |
| S14 | Hỏng / không hỗ trợ / locale `ja` | 6 | G1 |

S11 và S12 tồn tại để tạo dữ liệu cho Phase B — **không** được dùng làm tiêu chí
đạt/không đạt của A0, vì layout không phải contract ở thời điểm này.

## 4.2. Tầng S5 là tầng quan trọng nhất

Đường PDF hiện tại là **all-or-nothing**: nếu `pdftotext -layout` trả về bất kỳ
unit nào, nhánh OCR fallback không bao giờ chạy. Hệ quả cụ thể trên `main` hôm
nay: một PDF có text layer ở trang 1 và ảnh scan ở trang 2–10 chỉ sinh **một**
row — trang 2–10 không có gì cả, và Read Service trả về một tài liệu "ready" mà
thiếu 90% nội dung.

Đây là lỗ hổng chất lượng lớn nhất của implementation hiện tại và là chỗ Docling
có khả năng thắng rõ nhất. Nó phải được đo tường minh, không gộp vào S1/S3.

Ghi chú schema: `extraction_method` là cột **theo từng row**, và
`CHECK (extraction_method IN ('ocr','embedded_text'))` cho phép cả hai giá trị
cùng tồn tại trong một revision. Nên một provider xử lý hỗn hợp theo từng trang
là hợp lệ với schema hiện hành và **không cần amendment**.

## 4.3. Ràng buộc dữ liệu corpus

Theo ADR-0018, `contains_pii: true` không tự làm candidate corpus
bị từ chối hoặc trở thành `DECISION_REQUIRED`. Corpus offline có PII chỉ eligible
khi manifest/evidence ghi đủ:

* Owner approval có identity/date và evidence về nguồn/quyền sử dụng;
* phạm vi lưu trữ local, danh sách/quy tắc access hạn chế;
* `external_processing_allowed: false`, không upload, remote API/model hay
  external provider call;
* retention/deletion date cụ thể;
* source hash, approval evidence và provenance của redacted derivative nếu dùng.

Thiếu một điều kiện trên, hoặc workflow yêu cầu external processing chưa được
policy approve, mới là `DECISION_REQUIRED`. Khi đó dùng nguồn không PII hoặc
redacted derivative riêng. Redaction không sửa source gốc; derivative có hash,
version và provenance riêng.

Candidate có thể được chuẩn bị trước với approval fields pending, nhưng không
được chạy benchmark chính thức như corpus approved.

PII eligibility và resource parity là hai gate độc lập. Ví dụ PDF KO 121 trang
có PII, dù có corpus approval đầy đủ, vẫn phải được ghi làm negative/boundary
candidate với expected error `page_limit_exceeded`, vì `121 > max_pages = 100`.
Không được cắt file hoặc nới page limit để biến case đó thành pass.

## 4.4. Ground truth

| Loại tài liệu | Cách lập ground truth |
|---|---|
| Born-digital (S1, S2, S5-phần-text, S7, S9, S10, S13) | Sinh xác định từ nguồn; page mapping chính xác, chi phí gần bằng 0 |
| Scan (S3, S4, S5-phần-scan, S6, S8) | Người gõ lại theo trang; ≥20% mẫu double-key bởi hai người, sai lệch phải được xử lý và ghi lại |

Quy ước gõ phải được chốt trước và ghi trong corpus README: chuẩn hoá khoảng
trắng, xử lý gạch nối cuối dòng, có/không tính header–footer, có/không tính số
trang in trên giấy. Ground truth không có quy ước là ground truth không so sánh
được.

Định dạng:

```json
{
  "doc_id": "vi-s04-012",
  "locale": "vi",
  "stratum": "S4",
  "page_count": 14,
  "pages": [
    { "page": 1, "text": "...", "anchors": ["a1", "a2", "a3"] }
  ]
}
```

---

# 5. G3 — Metric chất lượng

## 5.1. Character Error Rate

CER theo từng trang, tổng hợp theo tầng và theo locale.

**Bắt buộc báo cáo hai biến thể cho `vi`:**

| Biến thể | Cách tính |
|---|---|
| `CER_raw` | So nguyên văn, có dấu |
| `CER_stripped` | So sau khi bỏ dấu thanh và dấu phụ |

Lý do: một engine OCR đọc đúng chữ nhưng mất dấu sẽ có `CER_stripped` rất đẹp và
hoàn toàn vô dụng cho học liệu tiếng Việt. Chênh lệch giữa hai con số **là** phép
đo chất lượng tiếng Việt, không phải phụ lục.

Với `ko`, báo cáo CER ở mức âm tiết Hangul; nêu rõ trong báo cáo là mức âm tiết
chứ không phải mức jamo, vì hai con số không so sánh được với nhau.

## 5.2. Coverage

```text
coverage(doc) = số trang có nội dung theo ground truth và sinh unit có text
                / tổng số trang có nội dung theo ground truth
```

Đây là metric bắt lỗi S5. Một engine có CER thấp trên các trang nó xử lý nhưng
bỏ qua 9/10 trang thì coverage sẽ phơi bày điều đó, còn CER trung bình thì không.

Trang trắng không nằm trong mẫu số coverage. Nó được kiểm tra riêng trong G2:
không sinh text unit nhưng locator/page sequence của các trang sau vẫn phải giữ
đúng số trang thật. Coverage không được dùng để che lỗi blank-page locator.

## 5.3. Ngưỡng đề xuất

> Các số dưới đây là **đề xuất, cần Owner chốt**. Chúng chưa được phê duyệt.

| Metric | Ngưỡng đề xuất |
|---|---|
| `CER_raw` mỗi tầng | Không hồi quy so với đường hiện tại trên **bất kỳ** tầng nào |
| `CER_raw` trên S3, S4 | Giảm tương đối ≥ 20% |
| `CER_raw − CER_stripped` (vi) | Không nới rộng so với đường hiện tại |
| `coverage` mỗi tài liệu có nội dung | Mục tiêu đề xuất `1.00`; đồng thời ≥ đường hiện tại, không có ngoại lệ |
| `coverage` trên S5 | Mục tiêu đề xuất `1.00` |
| Tỉ lệ `no_extractable_text` sai (tài liệu có text nhưng bị báo rỗng) | 0 |

Nguyên tắc đằng sau: A0 cho phép Docling **thắng ở nơi nó mạnh**, nhưng không cho
phép nó **thua ở bất kỳ đâu**. Một provider tốt hơn trung bình nhưng tệ hơn trên
một tầng sẽ làm hỏng đúng những tài liệu đang chạy được hôm nay.

`1.00` ở trên là policy đề xuất, chưa phải threshold Owner-approved. Cho tới khi
Owner chốt, `page_coverage_min` phải giữ trạng thái `OWNER_DECISION_REQUIRED`
(biểu diễn bằng `null` trong config máy đọc). Kết quả `< 1.00` chỉ dùng để cảnh
báo/điều tra và không đủ bằng chứng cho A0 pass.

---

# 6. G4 — Tài nguyên

Ngân sách suy ra trực tiếp từ contract, không phải chọn tuỳ ý:

```text
provider deadline 3300s ÷ max_pages 100 = 33s / trang
```

| Metric | Ngưỡng đề xuất |
|---|---|
| Thời gian xử lý mỗi trang, p95 | ≤ 33s |
| Thời gian xử lý mỗi tài liệu, p99 | ≤ 3300s |
| Peak RSS mỗi worker | Owner chốt theo sizing của AWS worker |
| Dung lượng đĩa tạm mỗi job | ≤ ngân sách ephemeral disk của worker |

Đo cả hai engine trên **cùng phần cứng**, cùng lúc, và ghi lại cấu hình phần cứng
trong bản ghi run. Một con số latency không kèm phần cứng không so sánh được.

Ghi chú: Docling tải model. Thời gian tải model **không** được tính vào latency
mỗi tài liệu, nhưng phải được báo cáo riêng — nó là chi phí khởi động worker và
ảnh hưởng thiết kế autoscaling ở A1.

---

# 7. Test runner

## 7.1. Yêu cầu

* Chạy **cả hai** engine trên cùng corpus trong cùng một lần chạy.
* Xác định (deterministic): ghim version Docling, version model, DPI, và mọi cấu
  hình ảnh hưởng output.
* Không phụ thuộc Laravel runtime. Đọc `config/media.php` như dữ liệu, không
  boot application.
* Không ghi vào bất kỳ bảng `media_*` nào. A0 không chạm database.

## 7.2. Bản ghi run

Mỗi lần chạy sinh một `run_id` và một bản ghi mô phỏng ngữ nghĩa
`processing_version` của contract:

```json
{
  "run_id": "a0-2026-08-26-01",
  "engine": "docling",
  "engine_version": "docling-<x.y.z>",
  "model_versions": { "layout": "...", "ocr": "..." },
  "config_hash": "<sha256 của tập cấu hình ảnh hưởng output>",
  "hardware": { "cpu": "...", "ram_gb": 0, "gpu": null },
  "corpus_revision": "<git sha của corpus>"
}
```

Lý do bắt buộc: A1 sẽ đặt `MEDIA_OCR_VERSION` từ đúng bộ giá trị này. Một kết quả
benchmark không truy được về version cụ thể thì không dùng để biện minh cho một
`processing_version` cụ thể.

## 7.3. Output

```text
benchmarks/a0-docling/results/<run_id>/
  per_document/<doc_id>.json     ← unit thô của cả hai engine
  metrics.json                   ← CER, coverage, tau, boundary_score
  gates.json                     ← G1..G4 pass/fail từng mục
  resources.json                 ← latency, RSS, disk
  report.md                      ← theo mẫu §8
```

---

# 8. Mẫu báo cáo quyết định A0 → A1

Verdict dùng đúng vocabulary đang dùng trong `docs/quality/`:
`PASS` / `PASS WITH DOCUMENTED RISKS` / `FAIL`.

```markdown
# A0 Docling Benchmark — Decision Report

Run ID:
Corpus revision:
Engine versions (baseline / candidate):
Hardware:
Ngày chạy:
Người chạy:

## Verdict

<PASS | PASS WITH DOCUMENTED RISKS | FAIL>

## Kết quả gate

| Gate | Kết quả | Bằng chứng |
|---|---|---|
| G1 Contract parity | | gates.json §g1 |
| G2 Citation parity — C1 tổng số trang | | |
| G2 — C2 ranh giới trang | | |
| G2 — C3 reading order | | |
| G2 — C4 rotation | | |
| G3 Chất lượng | | metrics.json |
| G4 Tài nguyên | | resources.json |

## Chất lượng theo tầng

| Tầng | locale | CER_raw hiện tại | CER_raw Docling | CER_stripped Docling | coverage hiện tại | coverage Docling |
|---|---|---|---|---|---|---|

## Hồi quy phát hiện được

Liệt kê mọi tầng/tài liệu mà Docling **thua** đường hiện tại, kể cả khi tổng thể đạt.

## Quan sát A+ (không chấm điểm)

Chất lượng phát hiện region/bảng/biểu đồ trên S11, S12 — dữ liệu đầu vào cho ADR Phase B.

## Điều kiện A1

Nếu verdict không phải FAIL, A1 vẫn cần, tách bạch:

1. Tech Stack amendment: `LF-Tech-Stack.md` liệt kê `Docker (Future)` ở Infrastructure;
   Official Stack không có runtime Python. Deploy Docling chạm cả hai.
2. Chứng minh parity binary/config version giữa local worker và AWS worker
   (Processing Contract §3).
3. Resource controls được áp dụng tường minh trong namespace của provider mới (§2).
4. `MEDIA_OCR_VERSION` mới; kế hoạch re-run và chuyển bộ output cũ sang `archived`.
5. Xác nhận không có Proposal/citation nào đang phụ thuộc bản cũ mà mất khả năng đọc lại
   (Read Contract §4.1 cho phép đọc `archived` khi nêu đích danh `processing_version`).

## Rủi ro còn mở

| # | Rủi ro | Ảnh hưởng |
|---|---|---|
```

---

# 9. Những gì A0 cố ý KHÔNG trả lời

Ghi ở đây để báo cáo A0 không bị đọc rộng hơn phạm vi của nó:

* Docling có nên là provider cho AWS production không — cần A1 + sizing thật.
* Region/table/chart persistence có hình dạng gì — cần ADR Phase B.
* Vision interpretation — chặn bởi R1, R2, và mục Future Extensions của ADR-0006
  cùng `LF-AI.md`.
* PaddleOCR PP-StructureV3 — chỉ xét sau khi A0 cho thấy Docling **không đủ**, và
  xét trong cùng protocol này với cùng corpus.

---

# Owner

Domain Owner (Media)

# Primary Consumers

* Developer
* Reviewer
