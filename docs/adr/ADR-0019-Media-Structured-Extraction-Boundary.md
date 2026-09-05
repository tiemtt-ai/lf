# ADR-0019 — Media Structured Extraction Boundary

Version: 1.11

Status: Approved

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-09-05

Proposal Date: 2026-08-25

Approval Date: 2026-08-25

Approved By: LearnForge Architecture Owner

Document Path: adr/ADR-0019-Media-Structured-Extraction-Boundary.md

Related ADRs:

* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](ADR-0006-AI-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0018 — Media PII And External Processing Boundary](ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved

Related Specification:

* [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md)
* [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md)
* [media_extracted_texts](../database/media/media_extracted_texts.md)

---

## Amendment v1.11 — Latin profile resolution and observed-text quality — Proposed 2026-09-05

**Status: Proposed — pending Architecture Owner approval.** Candidate runtime
đã tồn tại trong working tree nhưng không được đọc thành approval ngược; chỉ sau
approval, contract review và version bump mới được phát hành một revision theo
semantics này.

### Locale của chữ viết Latin

Language profile là tập candidate đã được actor khai, không phải kết quả
language classification. Với script `Latn`, Media chỉ resolve trong các locale
Latin-family có mặt trong profile:

* không có candidate Latin → `locale = NULL`;
* đúng một candidate Latin → dùng candidate đó;
* từ hai candidate Latin trở lên → tiếng Việt chỉ được chọn khi có dấu hiệu chữ
  Việt quan sát được; nếu không phân biệt được thì `locale = NULL`.

Trong Phase 1, `vi` và `en` là Latin-family; `ko` là Hangul-family. Vì vậy
`locales=en,ko` cho text `AI Powered EdTech Platform` resolve `en`, còn
`locales=en,ko,vi` giữ `NULL` nếu text không có bằng chứng phân biệt. Đây là
profile resolution bảo thủ, không phải confidence và không được suy rộng thành
phát hiện ngôn ngữ tự động.

Evidence `304/1531 Latn locale NULL` thuộc job 104, Media 41, profile chỉ `vi`;
nó không thuộc `docling 8`. `docling 8` là job 118, Media 45, profile `ko,vi`.
Hai corpus không được trộn trong acceptance evidence.

### Chất lượng text quan sát được

Media được phép đo một chỉ số hình thức, không diễn giải ngữ nghĩa:

```text
symbol_ratio = count(non-letter-and-non-number characters)
               / count(non-whitespace characters)
```

Threshold candidate là `0.20`, hiệu chuẩn trên 23 image region có OCR text của
job 118: các mẫu nhiễu đo được từ 20% trở lên, các control hợp lệ dưới 10%.
Threshold phải là config revisioned; thay threshold hoặc cách chuẩn hóa là thay
đổi output semantics và bắt buộc sinh `processing_version` mới.

* Với OCR candidate: nếu `symbol_ratio >= threshold`, không nhận candidate;
  crop vẫn giữ, text quan sát trước OCR và `extraction_method` vẫn giữ. Chỉ còn
  `text = NULL` khi trước OCR thật sự không có text.
* Với text đã quan sát từ provider/text layer: không xóa hoặc sửa text. Nếu vượt
  threshold, ghi `metadata.text_quality = low`; nếu không có cờ thì chất lượng
  đọc ra là `normal`.
* `low` không phải `failed`, confidence hay quyết định retrieval. Media vẫn trả
  text/crop/locator/languages; consumer quyết định có dùng nó hay không.

Language evidence chỉ được tính sau khi OCR candidate đã qua gate. Candidate bị
từ chối không được tạo `Hang/ko` hoặc signal khác từ chính chuỗi bị từ chối.

### Tesseract language pack

Thiết kế hiện hành `vi → vie+eng`, `ko → kor+eng`, `en → eng` được giữ. Khi
profile có nhiều locale, implementation phải flatten, deduplicate và
canonicalize language pack; ví dụ `ko,vi` tạo `kor+eng+vie`, không lặp `eng`.
Config hiện tại `vi → vie`, `ko → kor` chưa khớp contract và là implementation
gate của amendment này.

Không migration và không backfill revision cũ. Acceptance bắt buộc chứng minh
old/new `processing_version` khác nhau, revision cũ chuyển `archived`, và Media
Read trả `text_quality` cùng revision identity.

---

## Amendment v1.10 — Retrieval remains a consumer policy — Approved 2026-09-05

Evidence từ `docling 7` cho thấy image OCR có thể giữ text Việt hữu ích đồng
thời sinh vài ký tự Hangul nhiễu. Media không đủ context query để quyết định ký
tự nào hữu ích, nên không xóa text, không sửa `languages`, không gán confidence
giả và không đổi `reading_order`. Image/crop/locator vẫn được persist nguyên.

Role/language-aware ranking, low-signal image penalty và cross-language context
expansion thuộc AI theo ADR-0006 v1.0.1. Ranh giới này tránh làm sai Jamo thật
trong paragraph của `docling 3/6` và giữ Media output deterministic cho mọi
consumer không phải retrieval.

---

## Amendment v1.9 — Table completeness and Korean grammar guard — Approved 2026-09-05

Chất lượng bảng được ghi lúc persist và thuộc revision bất biến. Mỗi table có
`quality_status = complete|incomplete|undetermined`; revision lịch sử và bảng
spreadsheet không có hình học mang `undetermined`, nghĩa là **chưa đo**, không
phải khuyết tật và không được consumer dùng để hạ cấp revision.

Với bảng PDF, cell giữ bbox chuẩn hóa. Một vị trí lưới không có cell chỉ là
`incomplete` khi suy được cả dải hàng lẫn dải cột từ cell quan sát được và text
layer PDF có chữ trong bbox giao của hai dải. Không có chữ là ô trống hợp lệ.
Nếu thiếu neo cho cả hàng hoặc cột thì vị trí đó không đo được. Thứ tự kết luận:
`incomplete` thắng `undetermined`, `undetermined` thắng `complete`. Đây là phép
quan sát hình học và mực trên trang; Media không suy diễn ý nghĩa của ô trống.

Cell gộp được tính là chiếm toàn bộ span. Bbox của cell span không được chia để
tự tạo band: chỉ cell có span 1 theo trục tương ứng mới neo band của trục đó.
Quy tắc bảo thủ này có thể cho `undetermined`, nhưng không được tạo một kết luận
`complete` từ hình học suy đoán.

Nếu provider làm sụp một cột in trống ra khỏi `column_count`, mọi slot còn lại
vẫn có thể trông đo được. Region bbox khi đó chỉ cho biên ngoài, không chứng minh
số cột. Một khoảng giữa hai tâm band cột lớn hơn gấp đôi biên neo ngoài lớn hơn
được coi là topology không đo được và cho `undetermined`; nó không phải bằng
chứng `incomplete`. Rule hình học này được chốt từ ca thật trang 15, bảng `15#8`.

Formula evidence loại riêng mẫu dạy biến đổi ngữ pháp Hàn có hình dạng
`stem + ending = conjugation`. Guard chỉ áp dụng khi có Hangul, có cả `+` và `=`,
có ending ngữ pháp quan sát được, đồng thời không có chữ số, biến Latin/Greek hay
ký hiệu toán mạnh. Vì vậy label sai trong tài liệu ngôn ngữ không đi vào luồng
toán, còn công thức song ngữ như `삼각형의 넓이 A = 1/2 × b × h` vẫn đủ điều kiện.
Region gốc vẫn được giữ; chỉ formula evidence child bị từ chối.

---

## Amendment v1.8 — Region language evidence and formula evidence threshold — Approved 2026-09-04

Nguồn: independent review ngày 2026-09-04 trên một tài liệu thật (sách dạy tiếng
Hàn 100 trang, profile `ko,vi`). Hai phát hiện có evidence, hai quyết định.

**Region language evidence trở thành đa trị.** v1.7 cho region đúng một
`detected_locale` và một `script`. Đo trên tài liệu thật: 263 region chứa đồng
thời Hangul và tiếng Việt, tất cả bị ghi là `ko` và phần tiếng Việt biến mất
khỏi evidence. Từ v1.8, mọi script quan sát được trong region đều được ghi vào
`media_region_languages`, một row một script, xếp theo số ký tự giảm dần. Hai
cột cũ trên region giữ nguyên ngữ nghĩa và bằng row `ordinal = 1`, nên consumer
hiện tại không phải đổi.

`char_count` trên row là phép đếm ký tự quan sát được. Nó **không** phải
confidence và không được dùng để suy ra confidence. Provider không trả điểm tin
cậy cho language signal, và Media không tự tạo ra một điểm số.

Phạm vi nhận diện Hangul gồm cả Jamo độc lập — U+1100–U+11FF, U+3130–U+318F,
U+A960–U+A97F, U+D7B0–U+D7FF — chứ không chỉ syllable U+AC00–U+D7A3. Một câu
tiếng Việt trích dẫn `ㅂ`, `ㄷ`, `ㄹ` là bằng chứng của hai chữ viết, không phải
của một.

**Formula evidence cần ít nhất một toán tử quan sát được.** Provider trả label
`formula` không đủ để tạo row `media_extracted_formulas`. Đo trên cùng tài liệu:
cả 5 row formula đều do Docling gán label, và cả 5 đều là mẫu biến đổi ngữ pháp
tiếng Hàn hoặc nhiễu OCR, không phải công thức. Từ v1.8, row formula chỉ được
tạo khi `raw_text` chứa ít nhất một ký tự toán học quan sát được. Đây là phép
đếm ký tự, không phải phán đoán nội dung: Media không kết luận công thức đúng
hay sai, và ranh giới với ADR-0020 không đổi.

Region **giữ** `role = formula` theo quan sát của provider. Chỉ evidence child
bị từ chối. Vùng vẫn đọc được qua `content_type = region` cùng page, locator,
bbox và crop, nên không mất dấu vết quan sát nào.

Kết quả đo được của ngưỡng này, ghi lại để không ai hiểu nhầm là nó dọn sạch:
trên `tieng-han-so-cap-2-100.pdf` nó loại **4 trong 5** row, và trên
`de_cuong_toan_lop_10.pdf` (toán tiếng Việt) nó giữ **12/12**. Row còn sót là
`+ -아/어 보다 = 가 보다 …` — một mẫu ngữ pháp có chứa `=`, nên nó vượt ngưỡng theo
đúng định nghĩa. Đây là **residual đã biết**, không phải implementation lệch
quyết định: siết thêm đòi hỏi một rule dựa trên nội dung hoặc ngôn ngữ, tức một
quyết định Owner mới, và phải cân với rủi ro false negative cho tài liệu toán
viết bằng tiếng Hàn — rủi ro chưa đo được vì corpus hiện tại không có tài liệu
loại đó.

Language evidence **không backfill** cho revision cũ. Quan hệ "hai cột dominant
bằng row `ordinal = 1`" chỉ áp dụng cho revision sinh ra từ v1.8; revision trước
đó giữ hai cột dominant và có `languages` rỗng. Suy ngược `char_count` từ hai cột
đó là phỏng đoán, và ghi phỏng đoán vào chỗ contract gọi là bằng chứng thì tệ
hơn là để trống — cùng nguyên tắc đã áp dụng cho `media_processing_job_locales`.

---

## Amendment v1.7 — Document multilingual and STEM evidence — Approved 2026-09-03

Owner mở Document Processing cho một **tập 1–3 locale không có thứ tự do người
dùng khai báo**. Hệ thống canonicalize bằng cách chuẩn hóa BCP 47, từ chối
duplicate và sắp xếp theo byte trước khi tạo profile. Hai input chứa cùng tập
locale tạo cùng `output_profile_hash`; thay tập locale tạo revision khác.
Auto-detection chỉ là signal nullable trên output, không được đổi profile.

Vocabulary region được mở thành `heading`, `paragraph`, `list`, `table`,
`image`, `chart`, `diagram`, `geometry`, `formula`, `caption`, `note`, cùng
`header`, `footer`, `other`. Các role graphic chỉ phân loại hình dạng quan sát
được. Chúng không chứa quan hệ, kết luận, lời giải hay diễn giải; phần đó tiếp
tục thuộc ADR-0020. `figure` được giữ để đọc revision cũ nhưng provider mới
không sinh `figure` khi xác định được role hẹp hơn.

Formula là evidence child của region `role = formula`. Nó có raw text/OCR,
normalized `latex|mathml` khi provider cung cấp, confidence và trạng thái riêng.
Normalization fail hoặc confidence thấp không làm rollback canonical page text
hay region/crop hợp lệ. Formula kế thừa source, page, locator, job, profile và
revision từ region cha; không được tồn tại độc lập hoặc trỏ chéo revision.

Canonical page text vẫn là fallback theo trang và không mang bbox giả. Hình học,
reading order và crop thuộc region. Table/cell giữ mô hình đã duyệt.

---

## D2–D5 — Approved 2026-08-31

Owner duyệt D2–D5: * D2: XLS chuyển XLSX bằng LibreOffice, rồi đọc worksheet/cell trực tiếp như XLSX; `sheet` / `spreadsheet_cells`, không fallback PDF. Structured spreadsheet dùng `structure=cells`, PDF dùng `structure=layout`. Version mới; bản cũ vẫn đọc được qua citation archived.
* D3: OCR độc lập, không chờ structured. Structured chỉ materialize khi canonical OCR revision tương ứng ready; metadata `canonical_processing_job_id` ghi immutable input, processing_version hash full extractor version + canonical identity (SHA-256, không truncate). Opt-in đã ghi trên active usage được materialize sau OCR commit. On-demand chưa có canonical ready không gọi provider. Khi OCR mới ready, archive structure thuộc canonical cũ; giữ terminal jobs, citation, crop. Validate tổng canonical+region+cell <=500000 dưới Media lock; vượt cap chỉ fail structured, không truncate OCR.
* D4: PDF hỗn hợp giữ mọi page locator, kể cả text rỗng/char_count=0; extraction_method theo đường thực chạy. Toàn trắng fail no_extractable_text, không persist revision. pages_with_text chỉ tính char_count>0. Version output mới bảo vệ citation cũ.
* D5: completed-unit checkpoint monotonic: OCR page (worksheet tính một unit trong OCR); structured PDF page, structured spreadsheet sheet. Chỉ ghi khi hoàn tất quan sát unit; Docling batch hoàn tất conversion mới chứng minh các page đã xử lý, crash trước mốc này không đoán số page. Crop/validation lỗi sau đó giữ checkpoint. Checkpoint là số tuyệt đối monotonic trong từng job; retry đo riêng work thực tế. Không suy ra tiền, quota hay SaaS aggregation.


---

# Amendment Record — Version 1.1

Amendment Status: **Approved by Architecture Owner, 2026-08-25.** Mục này có hiệu
lực; ADR-0019 chuyển sang Version 1.1.

Nguồn: independent review của Database Docs ngày 2026-08-25 phát hiện mâu thuẫn
giữa ADR này và [media_table_cells](../database/media/media_table_cells.md).

**Mâu thuẫn.** § D2 viết cả ba bảng mang `processing_version` +
`source_fingerprint` "trên mỗi row". Database Doc của `media_table_cells` cố ý
**không** có hai cột đó.

**Đề xuất sửa.** Revision identity nằm ở **row sở hữu revision**, không ở mọi row
con:

* `media_extracted_regions` và `media_extracted_tables` mang `processing_version`,
  `source_fingerprint` và `status`.
* `media_table_cells` **kế thừa** cả ba từ bảng cha qua khóa ngoại, và không thể
  `archived` độc lập.

**Lý do.** Cell là owned child không có identity độc lập. Nhân bản version và
fingerprint lên hàng trăm nghìn row cell không thêm khả năng truy vết nào — chúng
luôn bằng giá trị của bảng cha — nhưng tạo ra cơ hội để hai giá trị lệch nhau, và
lúc đó không có nguồn nào đúng.

Mâu thuẫn được ghi là **DOC-CONFLICT-0016**
([LF-Documentation-Conflicts](../quality/LF-Documentation-Conflicts.md)) và đóng
`RESOLVED` bằng chính amendment này ngày 2026-08-25. Blocker migration còn lại
nằm ở resource limits và Architecture Review, không còn ở conflict này.

---

# Amendment Record — Version 1.2

Amendment Status: **Approved by Architecture Owner, 2026-08-27.** Mục này có hiệu
lực; ADR-0019 chuyển sang Version 1.2.

Nguồn: đối chiếu tài liệu ↔ source ngày 2026-08-27. ADR này để lại một khoảng
trống mà không tài liệu nào khác lấp: **structured extraction chạy dưới job nào.**

**Khoảng trống.** `media_processing_jobs` có `CHECK (job_type IN ('transcode',
'thumbnail','ocr','speech_to_text','caption','virus_scan','compress'))` và
`output_type` chỉ nhận `transcript | caption | extracted_text | variant`. Không
giá trị nào chứa được một revision region/table/cell. Một provider muốn ghi
structured output hôm nay phải hoặc đội lốt `ocr`, hoặc vi phạm CHECK.

**Quyết định.** `job_type = 'structured_extraction'`, với `output_type` là
`extracted_region` (nguồn document) hoặc `extracted_table` (nguồn spreadsheet),
`output_id` trỏ tới **điểm vào** của revision — region `reading_order = 1` hoặc
table `sequence = 1`. Chi tiết hợp đồng nằm ở
[LF-Media-Processing-Contract § 2](../platform/LF-Media-Processing-Contract.md)
Amendment v2.1.

**Lý do không tái sử dụng `ocr`.** Đúng lý do đã dùng để bác `page` cho sheet ở
§ D1: nhánh spreadsheet đọc thẳng ô với `extraction_method = 'spreadsheet_cells'`
và không gọi OCR lần nào. Gán nhãn `ocr` là một `job_type` nói dối về việc đã
làm, và là loại nói dối rẻ hôm nay đắt về sau — mọi consumer sau này phải học
ngoại lệ đó. Tách job cũng tách chuỗi retry, `output_profile_hash`,
`billable_units` và quota, nên một revision cấu trúc fail không tiêu attempt của
OCR text.

**Sửa một dòng của Consequences.** Version 1.0/1.1 viết "Không có provider,
dependency, queue hay deployment change nào phát sinh từ ADR này". Câu đó **sai**
kể từ amendment này: thêm một `job_type` là thay đổi orchestration và cần một
migration trên `media_processing_jobs` — bảng đang chạy trên development. Dòng
đó được thay ở § Consequences bên dưới.

Khoảng trống được ghi là **DOC-CONFLICT-0019**
([LF-Documentation-Conflicts](../quality/LF-Documentation-Conflicts.md)) và đóng
`RESOLVED` bằng chính amendment này ngày 2026-08-27. Blocker migration còn lại
nằm ở Gate M và ở DOC-CONFLICT-0020, không còn ở khoảng trống này.

---

# Amendment Record — Version 1.3

Amendment Status: **Approved by Architecture Owner, 2026-08-27.**

§ D4 nói Media quan sát, AI diễn giải. Amendment này trả lời câu hỏi mà § D4 để
mờ: **sơ đồ và mũi tên rơi về bên nào.** Câu hỏi phát sinh từ tiêu chí nghiệm thu
của một tài liệu học liệu thật, yêu cầu "nhận diện các khối sơ đồ, mũi tên và thứ
tự liên kết".

**Quyết định.** Media dừng ở vùng. Xem § D7.

---

# Amendment Record — Version 1.4

Amendment Status: **Approved by Architecture Owner, 2026-08-27.** Owner mở lại
A1 và phê duyệt Docling runtime sau khi Phase B/C đã có schema chứa region/table.

**Quyết định.** Provider `docling_local` chạy hybrid, offline:

* Docling 2.119.0 / Python 3.11 sinh layout, role, bbox, reading order và table
  structure.
* Poppler/Tesseract hiện hành tiếp tục sinh text/OCR canonical theo trang. Docling
  không thay `MEDIA_OCR_PROVIDER` và không được dùng auto language detection.
* Mọi graphic dùng `role = figure`; `chart`, `diagram`, `image` và quan hệ mũi
  tên không được ghi như observation của Media.
* Provider chạy qua process boundary với JSON stdin/stdout, không import Python
  vào PHP worker và không gọi network/external API.

Approval này cho phép implement và chạy local. AWS deployment chỉ được mở khi
Python/package/model/config hash parity và resource sizing được ghi nhận trong
Architecture Review; nó không phê duyệt AI Vision.

**Lý do.** "Khối A dẫn tới khối B" là một khẳng định **có thể sai**. Media phát ra
nó thì không có `ai_model_runs` để truy, không quota, không ai duyệt trước khi nó
thành nội dung dạy. AI phát ra thì có cả ba. Cùng một câu, khác nhau ở chỗ có ai
đứng sau nó hay không.

Và không mất gì: AI vision nhận crop của vùng, text nằm trong vùng, vị trí và
citation — đủ để đọc sơ đồ. Media không đọc hộ nó.

**Hệ quả cho tiêu chí nghiệm thu.** Ở tầng Media, tiêu chí phải viết ở dạng đo
được:

| Đo được ở tầng Media | Không đo được ở tầng Media |
| --- | --- |
| Mỗi khối là một region `role = 'figure'`, đúng trang, đúng `ordinal` | "Nhận diện mũi tên" |
| `bbox` đúng vị trí khối | "Thứ tự liên kết của sơ đồ" |
| Text nằm trong khối được giữ nguyên | "Sơ đồ này mô tả quy trình gì" |
| Có crop và citation trỏ đúng `page#ordinal` | |

Cột phải không sai vì thiếu engine tốt; nó sai vì **không thuộc Media**. Đổi
engine không chuyển được một dòng nào từ cột phải sang cột trái.

---

# Context

Media hiện chỉ sản xuất **text theo trang**. `media_extracted_texts` khoá cứng
điều đó ở tầng database:

```sql
CHECK (locator_type IN ('page'));
```

Hệ quả trên học liệu thật:

* Một bảng trong PDF bị `pdftotext -layout` làm phẳng thành text giữ cột bằng
  khoảng trắng. Consumer đọc vào không phân biệt được ô, hàng, header. Cấu trúc
  vẫn nhìn thấy được bằng mắt người và biến mất hoàn toàn với máy.
* Một worksheet Excel được đọc trực tiếp theo cell, nhưng vẫn phải ép về một
  unit `locator_type = 'page'` với `locator_value` là **chỉ số sheet**. Đây là
  một sự gán ghép: sheet không phải trang, và hợp đồng locator đang bị dùng sai
  nghĩa để không phải sửa schema.
* Vùng/layout (cột, khối, thứ tự đọc) không có chỗ lưu, nên không consumer nào
  trích dẫn được ở mức nhỏ hơn trang.

Đây là điều kiện chặn thật, không phải mong muốn kiến trúc. Benchmark A0 đo được
rằng một engine layout (Docling) tách cột tốt hơn baseline — boundary 0.61→0.82
trên `vi-s2`, 0.66→0.78 trên `ko-s2` — nhưng
[Closure Record của A0](../../LF-A0-Docling-Benchmark-Protocol.md) ghi rõ lợi thế
đó **không lưu được ở schema hiện hành**. Mua năng lực trước khi có chỗ chứa là
chi phí không đổi lấy gì.

Câu hỏi ADR này trả lời: **structured extraction sống ở đâu, dưới hợp đồng
locator nào, và ranh giới giữa "quan sát cấu trúc" với "diễn giải nội dung" nằm
chỗ nào.**

---

# Decision

## D1 — Locator giữ nguyên hình dạng, mở rộng vocabulary

[LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md) § 4
freeze một hợp đồng duy nhất:

```text
locator := { locator_type, locator_value }   // locator_value luôn là text
```

Hình dạng đó **không đổi**. Chỉ vocabulary mở rộng:

| `locator_type` | Áp dụng cho | `locator_value` |
| --- | --- | --- |
| `page` | extracted text (document) | Số trang, ≥ 1 |
| `timespan` | transcript (audio/video) | `<start_ms>-<end_ms>` |
| `sheet` | spreadsheet | Chỉ số sheet theo thứ tự workbook, ≥ 1 |
| `region` | vùng trên một trang | `<page>#<ordinal>`, cả hai ≥ 1 |

`region` cố ý **không** mang toạ độ. Bounding box là dữ liệu quan sát, thay đổi
khi đổi extractor hoặc DPI; nếu nhét vào locator thì mọi citation cũ vỡ mỗi lần
đổi `processing_version`. Locator phải ổn định suốt vòng đời của một
`source_fingerprint`, nên nó chỉ định danh *vùng thứ mấy trên trang nào*, còn
hình học nằm ở cột riêng.

`sheet` thay cho việc gán sheet vào `page`. Đây là **sửa một cách dùng sai đang
tồn tại**, không phải mở rộng phạm vi.

## D2 — Cấu trúc không sống trong `media_extracted_texts`

`media_extracted_texts` là text-theo-unit. Nhồi vùng, bảng và ô vào đó biến phần
lớn cột thành NULL và trộn hai loại dữ liệu khác bản chất vào một bảng.

Structured extraction là **content type mới**, không phải cột mới:

| Bảng | Nội dung | Locator |
| --- | --- | --- |
| `media_extracted_regions` | Một vùng: role, hình học, thứ tự đọc | `region` |
| `media_extracted_tables` | Một bảng, neo vào một region hoặc một sheet | `region` \| `sheet` |
| `media_table_cells` | Một ô: row, column, rowspan, colspan, text | thừa kế của bảng cha |

Ba bảng này theo đúng khuôn của substrate hiện hành: tenant composite identity
`UNIQUE (id, customer_id)`, khoá ngoại kép `(parent_id, customer_id)`,
`processing_version` + `source_fingerprint` trên mỗi row, và revision cũ chuyển
`archived` thay vì bị ghi đè.

Chi tiết cột, index và CHECK thuộc Database Docs, không thuộc ADR này.

## D3 — Read Contract nhận thêm content type, không nhận thêm đường đọc

[LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) § 2 liệt kê
`extracted_text | transcript | caption_asset | variant`. ADR này thêm:

* `region`
* `table`

Mọi thứ khác của Read Contract giữ nguyên: owner context, `actor_id` tường minh,
chọn locale, chọn revision, tập mã lỗi đóng, audit `read_derived`. Không có API
riêng cho structured data, không có đường nào cho AI đọc thẳng bảng `media_*`.

## D4 — Ranh giới: Media quan sát, AI diễn giải

Media ghi lại **những gì có trên trang**: đây là một vùng, role của nó là bảng,
nó có 4 hàng 3 cột, ô (2,1) chứa chuỗi này, thứ tự đọc là thế này.

Media **không** ghi ý nghĩa: bảng này nói về doanh thu, cột này là đơn vị tiền
tệ, biểu đồ này cho thấy xu hướng giảm. Diễn giải là việc của AI và phải để lại
`ai_model_runs`, quota và retention theo AI Foundation.

Ranh giới này không phải hình thức. Một khi Media bắt đầu gán ý nghĩa, nó trở
thành nguồn sự thật cho một business state mà nó không sở hữu, và không consumer
nào truy được quyết định đó về một model run nào.

## D6 — Job identity của structured extraction (Amendment v1.2)

Structured extraction là `job_type` riêng `structured_extraction`, không phải
một biến thể của `ocr`. Vocabulary `job_type` và `output_type` của
`media_processing_jobs` phải được mở bằng migration riêng, chịu cùng Gate M với
ba bảng mới. Hợp đồng chi tiết thuộc Processing Contract § 2, không thuộc ADR
này.

## D7 — Sơ đồ, biểu đồ và ảnh: Media dừng ở vùng (Amendment v1.3)

Với mọi khối đồ hoạ — biểu đồ, sơ đồ, ảnh chụp, hình vẽ — Media chỉ được ghi bốn
thứ:

1. **có một vùng ở đây** — `role = 'figure'`, `page`, `ordinal`, `reading_order`;
2. **vùng nằm ở đâu** — `bbox`;
3. **chữ và số nằm trong vùng** — nhãn trục, chú thích, text trong khối;
4. **crop và citation** — `page#ordinal` ổn định theo `source_fingerprint`.

Media **không** ghi: quan hệ giữa các khối, hướng của mũi tên như một quan hệ,
thứ tự liên kết, loại biểu đồ, hay ý nghĩa của bất kỳ thứ gì trong vùng. Phát
hiện một nét vẽ có đầu mũi tên tại một toạ độ là quan sát; khẳng định nó **nối**
khối A sang khối B là một quan hệ ngữ nghĩa, và thuộc
[ADR-0020](ADR-0020-AI-Vision-Interpretation-Boundary.md).

### Vocabulary `role` giữ nguyên, có chủ ý

`role` hiện có `figure` cho cả biểu đồ, sơ đồ lẫn ảnh chụp. **Không tách** thành
`chart` / `diagram` / `image`.

Tách ra không phải mở rộng vocabulary mà là yêu cầu Media **phân loại** — và
phân biệt "biểu đồ cột" với "sơ đồ luồng" là một phán đoán về nội dung, đúng thứ
§ D4 đặt ở phía AI. Một vocabulary mà chính Media không điền đúng được thì thêm
vào chỉ tạo dữ liệu sai có vẻ chính xác. AI phân loại, và kết quả đó sống ở
`ai_*` theo ADR-0020.

## D5 — Ngoài phạm vi, có lý do

| Không thuộc ADR này | Vì sao |
| --- | --- |
| Diễn giải biểu đồ/sơ đồ/ảnh | Là vision AI. Cần `ai_model_runs`, quota, retention/redaction — tức là AI Foundation phải được implement trước. Xem [Architecture Review của subset Media→AI](../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md) |
| Chọn extractor sinh ra được region/table | Là quyết định Tech Stack riêng. ADR này tạo **chỗ chứa**, không phê duyệt provider nào |
| Citation ở mức cue của caption | Đã được Read Contract ghi là contract riêng cần review (risk B2) |
| Chuỗi công thức và chart nhúng trong Excel | Cần vocabulary riêng; worksheet vào được `table` trước |

Điểm thứ hai quan trọng: ADR này **không phải cửa sau cho Docling**. Hồ sơ A0 đã
đóng với kết luận giữ Tesseract. Mở lại là một quyết định Tech Stack có bằng
chứng riêng, chạy sau khi chỗ chứa này tồn tại — không phải hệ quả tự động của
việc approve ADR này.

---

# Consequences

* Bảng trong PDF trở thành dữ liệu máy đọc được, thay vì text đã bị làm phẳng.
* **Coverage không phải 1.00, và giới hạn nằm ở trang scan — đo 2026-08-28.**
  Trên hai tài liệu thật:

  | Tài liệu | Trang có text | Trang có region | Region |
  | --- | --- | --- | --- |
  | PDF gần như toàn text-layer | 100 | **100** | 1.924 |
  | PDF hỗn hợp, 67/99 trang scan | 99 | **79** | 371 |

  Phân tách theo nguồn text của tài liệu thứ hai cho thấy nguyên nhân rõ ràng:

  ```text
  embedded_text :  0/32 trang thiếu region
  ocr (scan)    : 20/67 trang thiếu region      (~30%)
  ```

  Hai mươi trang đó **không trống** — chúng có từ 278 đến 2.017 ký tự. Runtime
  hiện cố ý chạy Docling ở chế độ layout-only (`do_ocr = false`), nên structured
  coverage trên trang scan không được bảo đảm. Evidence này không chứng minh
  rằng trang "không có cấu trúc", cũng chưa tách riêng được lỗi model, chất lượng
  ảnh và giới hạn của cấu hình adapter.

  Hệ quả cần biết: canonical text vẫn đủ cho 99 trang có nội dung, nên consumer
  đọc `extracted_text` không mất nội dung. Cái mất là khả năng trích dẫn theo vùng
  trên những trang đó. Consumer chỉ đọc `region` hiện không phân biệt được ba tình
  huống — trang trắng thật, trang có text nhưng thiếu cấu trúc, và lỗi hệ thống.

  Quyết định: **không đuổi theo coverage 1.00.** Thay vào đó độ lệch được đo và
  ghi lại — `media_processing_jobs.metadata.structure_coverage` mang
  `pages_with_text`, `pages_with_regions` và danh sách `pages_text_without_structure`.
  Việc để Media Read Contract trả một trạng thái có tên thay cho mảng rỗng là
  Amendment riêng, chưa mở ở đây.

  Tỷ lệ 47/67 (70,1%) chỉ là evidence của fixture hỗn hợp này, không phải SLA
  chung cho PDF scan. Nếu sau này muốn nâng coverage, bật OCR pipeline của chính
  Docling là một thí nghiệm cấu hình mới và phải đo/review lại trước khi cam kết.
* **Phạm vi định dạng — chốt 2026-08-27: structured extraction chỉ nhận PDF.**
  Adapter Docling hiện đăng ký duy nhất `InputFormat.PDF`, và provider từ chối
  mọi extension khác bằng `unsupported_source`. Định dạng Office cần cấu trúc
  thì tác giả tự xuất ra PDF rồi tải bản PDF lên.

  Đây không phải giới hạn tạm cho tiện. Đo trên tài liệu thật cho thấy backend
  DOCX của Docling trả về role và reading order nhưng **không** trả `page` và
  **không** trả `bbox` — vì file Office không có hình học trang trước khi được
  render. `media_extracted_regions.page` là `NOT NULL`, nên nhận native Office
  sẽ hỏng ở tầng persistence chứ không phải tầng đọc.

  Đường thay thế LibreOffice → PDF → Docling cũng đã được đo và **không**
  reproducible: cùng một DOCX, cùng binary, cùng máy cho ra hai PDF khác nhau
  ~50% số byte, kể cả khi đặt `SOURCE_DATE_EPOCH`. Nghĩa là `source_fingerprint`
  của bản derivative không thể là checksum của chính nó. Muốn mở đường đó thì
  phải: fingerprint dẫn xuất từ input (`source_fingerprint ‖ soffice_version ‖
  font_set_hash ‖ convert_options`), mở vocabulary `chk_mv_type` cho `pdf`,
  thêm provenance hai tầng, và pin LibreOffice cùng bộ font giữa mọi môi
  trường. Cả bốn việc đó cần một Amendment riêng.

  Tác giả tự xuất PDF thì bbox trỏ đúng file mà họ nhìn thấy, không phải một
  bản render do server tạo ra — đúng đắn hơn về ngữ nghĩa, ngoài chuyện rẻ hơn.
* Consumer trích dẫn được ở mức nhỏ hơn trang mà không phá hợp đồng locator.
* Cách dùng sai `page` cho sheet được sửa. Đây là **thay đổi có phá vỡ**: unit
  của spreadsheet sẽ mang `locator_type = 'sheet'`, nên phải đi kèm
  `processing_version` mới; revision cũ giữ nguyên `archived` và vẫn đọc được.
* Ba bảng mới, ba content type mới, và một Read Contract amendment cần review.
* Không có provider hay dependency change nào phát sinh từ ADR này.
* **Sửa bởi Amendment v1.2:** có một thay đổi orchestration — `job_type` mới
  `structured_extraction` và vocabulary `output_type` mở rộng — kéo theo một
  migration trên `media_processing_jobs`. Không có deployment hay queue
  topology change.
* Chi phí lưu trữ tăng theo số ô, không theo số trang. Một workbook lớn sinh ra
  nhiều row hơn hẳn một PDF cùng dung lượng; giới hạn tài nguyên cho structured
  extraction phải được chốt trong Database Docs, không để mặc định.

---

# Alternatives Rejected

1. **Thêm cột region/table vào `media_extracted_texts`:** biến phần lớn cột
   thành NULL, trộn hai loại dữ liệu khác bản chất, và buộc mọi consumer text
   phải hiểu schema của bảng.
2. **Nhét toạ độ vào `locator_value`:** phá quy tắc locator ổn định theo
   `source_fingerprint`; đổi DPI là mọi citation cũ trỏ sai.
3. **Lưu bảng dưới dạng JSON hoặc Markdown serialize:** đọc được bằng mắt, không
   truy vấn được, và mỗi extractor sinh một phương ngữ khác nhau.
4. **Giữ nguyên `page` cho spreadsheet:** rẻ hơn hôm nay, nhưng giữ lại một
   locator nói dối về nguồn, và mọi consumer sau này phải học ngoại lệ đó.
5. **Gộp cả diễn giải biểu đồ vào ADR này:** trộn quan sát với suy luận, và tạo
   một business state không truy được về model run nào.

---

# Owner Approval

```text
Role: LearnForge Architecture Owner
Date: 2026-08-25
Decision: Approved
```

Owner approval mở bước Domain/Database trong workflow, **không** mở bước
Migration. Trước khi có migration hoặc sửa Read Contract vẫn cần: Database Docs
cho ba bảng mới, Read Contract amendment, và **Architecture Review passed**.

Approval này không phê duyệt provider nào và không mở lại hồ sơ A0.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
