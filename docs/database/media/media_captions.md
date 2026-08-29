# Table: media_captions

Version: 1.10

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-24

Document Path: database/media/media_captions.md

## Purpose

Lưu metadata và storage locator của caption/subtitle asset.

## Relationships

`Media File 1 → N Captions`; `Customer 1 → N Captions`.

## Business Rules

* Caption và Media File phải cùng tenant.
* Allowed `caption_type`: `vtt`, `srt`, `ass`.
* Allowed `status`: `pending`, `processing`, `ready`, `failed`, `archived`.
* Caption binary không lưu trong database; `storage_key` là canonical locator.

* Output neo vào một lần chạy cụ thể qua `processing_job_id`, và mang
  `processing_version` cùng `source_fingerprint` của lần chạy đó.
* Chạy lại **không ghi đè**: nội dung hoặc phiên bản xử lý đổi thì sinh bộ row
  mới, bộ cũ chuyển `archived`. Quy tắc stale đầy đủ nằm trong
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md).
* Caption **không mang locator**. Một row là **một file asset** VTT/SRT/ASS, và
  một file caption chứa nhiều cue với nhiều mốc thời gian khác nhau. Gán một
  `timespan` duy nhất cho cả file là một mốc bịa, và một trích dẫn sai chỗ còn
  tệ hơn không có trích dẫn.
* Trích dẫn theo thời gian dùng `media_transcripts`, nơi một row đã là một đoạn
  có `timespan` thật. Nếu Media Read Contract về sau cần trích dẫn ở mức cue của
  chính caption asset, đó là một derived contract riêng — mỗi cue một row với
  `{timespan, text}` — không phải một cột nhét thêm vào bảng này.
* **Caption được dựng từ transcript** — Owner quyết định 2026-08-29,
  [DOC-CONFLICT-0024](../../quality/LF-Documentation-Conflicts.md). Không chạy
  model riêng trên binary: hai đường độc lập sẽ cho hai nội dung khác nhau trên
  cùng một video, và tốn gấp đôi chi phí model.
* Vì thế mỗi caption do job sinh ra **phải ghi transcript revision đã dùng** ở
  `transcript_processing_version`. Xem § Provenance và stale dây chuyền.
* Phase 1 caption **cùng locale** với transcript nguồn. Dịch caller sang locale
  khác là một quyết định riêng, chưa được duyệt; không được suy ra từ quyết định
  này.
* Chỉ row `ready` được Media Read Service trả ra.
* Mỗi locale/format profile có retry chain độc lập. `vi-VTT` hết retry không
  chặn enqueue/ready/retry của `vi-SRT` và không làm binary Media File mất
  `ready`; Media Read Service trả readiness của caption riêng.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File nguồn. |
| locale | VARCHAR(20) NOT NULL | Locale caption. |
| caption_type | VARCHAR(20) NOT NULL | VTT/SRT/ASS. |
| storage_key | VARCHAR(1024) NOT NULL | Canonical object key. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Processing state. |
| metadata | JSON NULL | Provider/timing metadata. |
| processing_job_id | BIGINT UNSIGNED NULL | Lần chạy đã tạo ra row này. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản extractor/model/cấu hình. |
| transcript_processing_version | VARCHAR(100) NULL | `processing_version` của transcript revision đã dùng để dựng caption này. NULL chỉ hợp lệ cho caption không do job sinh ra. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn khi xử lý. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints And Indexes

`UNIQUE (customer_id, media_file_id, locale, caption_type)` của Version 1.0 đã
**bị loại bỏ**: nó cho phép đúng một caption cho mỗi file/locale/định dạng, chặn
đúng cơ chế revision mà processing versioning cam kết.

```sql
UNIQUE (customer_id, media_file_id, locale, caption_type, processing_version);
UNIQUE (customer_id, storage_key);
UNIQUE (id, customer_id);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;

CHECK (status IN ('pending','processing','ready','failed','archived'));
CHECK (caption_type IN ('vtt','srt','ass'));
CHECK (processing_job_id IS NULL OR transcript_processing_version IS NOT NULL);
CHECK (status <> 'ready' OR storage_key IS NOT NULL);
```

## Provenance và stale dây chuyền — v1.6

Caption dựng từ transcript, nên nó phụ thuộc **một transcript revision cụ thể**.
`source_fingerprint` không diễn đạt được điều đó: theo
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)
§ Fingerprint, nó là vân tay của **binary gốc** —
`SHA-256(checksum || ':' || file_type)` — nên nó **không đổi** khi transcript sinh
revision mới.

Nếu không ghi thêm gì, kịch bản sau xảy ra mà không tín hiệu nào phát hiện:

1. Video upload → transcript v1 → caption dựng từ v1
2. Nâng engine STT → transcript v2 ready, transcript v1 chuyển `archived`
3. Caption vẫn `ready`, `source_fingerprint` vẫn khớp

Người học xem phụ đề của bản phiên âm đã lỗi thời trong khi AI đọc bản mới — hai
nội dung khác nhau cho cùng một video. Và không có dữ liệu nào cho biết caption
đó đến từ v1, nên kể cả khi phát hiện cũng không truy được bằng SQL.

Cột `metadata` **không** thay thế được. Nó lưu được bằng chứng phụ, nhưng không
định kiểu, không cưỡng chế bằng constraint, và không dùng làm revision identity
hay stale dependency: một luật stale phải truy vấn và cưỡng chế được, không thể
dựa vào một khoá JSON tuỳ chọn mà không gì bảo đảm có mặt.

`transcript_processing_version` đóng khoảng trống đó. Cùng với `locale` và
`source_fingerprint` đã có sẵn, nó xác định duy nhất transcript revision nguồn —
không cần thêm cột nào khác, vì caption và transcript nguồn luôn cùng binary và
Phase 1 luôn cùng locale.

### Luật stale

Khi một transcript revision chuyển `archived`, mọi caption có
`transcript_processing_version` bằng version đó **cũng phải chuyển `archived`**
trong cùng transaction. Đây là quy tắc revision đã có của Media áp cho một quan
hệ dẫn xuất bắc cầu, không phải cơ chế mới.

Đây là tác dụng phụ **trong** ranh giới của Media: một job STT chạm tới row
caption của chính Media đó. Nó không chạm Course, Assessment hay AI output, nên
không vi phạm § Ranh giới tác dụng phụ. Nhưng vì là hiệu ứng **liên output type**
đầu tiên, nó phải được viết ra chứ không được ngầm hiểu.

Caption cũ **không bị xoá**: revision cũ vẫn đọc được khi consumer nêu đích danh
`processing_version`, đúng luật § 4.1 của Media Read Contract.

### Gate M — đã đóng 2026-08-29

Migration `2026_08_29_000000_add_caption_transcript_provenance`:

| Yêu cầu Gate M | Trạng thái |
| --- | --- |
| Schema contract đầy đủ | `LF-SCHEMA-CONTRACT.json`: +1 cột, +1 CHECK |
| Test đọc CHECK **vật lý** từ `information_schema` | `tests/Integration/MediaCaptionProvenanceMariaDbTest.php` — 5 test, 9 assertion, xanh trên MariaDB |
| Chạy xanh trên MariaDB | ✅ |
| `schema:drift --fresh` | ✅ |
| Đã nối vào CI | ✅ `.github/workflows/application-tests.yml` |

Test đọc constraint vật lý chứ **không** đọc danh sách migration: một migration
quên `ADD CONSTRAINT` vẫn qua được phép kiểm inventory rồi hỏng ở row đầu tiên.

**Gate M: ĐÓNG — Owner attestation 2026-08-29.**

Đóng bằng chỉ thị trực tiếp của Architecture Owner trong phiên làm việc
2026-08-29 ("tôi cho phép đóng"), trên bộ bằng chứng ở bảng trên.

Ghi rõ để người đọc sau không hiểu nhầm: **không có independent Architecture
Review nào được thực hiện** cho migration này. Đây là Owner attestation. Thẩm
quyền là chỉ thị Owner trực tiếp, **không** viện dẫn amendment của ADR-0017 —
amendment đó có phạm vi Course Template Mapping Intent và không cấp quyền cho
miền Media.

Phạm vi đóng đúng bằng phạm vi Gate M: **migration và schema**. Nó **không** đóng:

* bất biến kiểm-tồn-tại transcript revision ở tầng persist — chưa có runtime để đặt;
* caption runtime;
* provider `caption`, vẫn `unconfigured`.

### CHECK không chứng minh transcript revision tồn tại — giới hạn đã biết

`CHECK (processing_job_id IS NULL OR transcript_processing_version IS NOT NULL)`
chỉ bắt **có khai một chuỗi**. Nó không chứng minh chuỗi đó ứng với một transcript
revision có thật, và cũng không ngăn khai sai version.

**FOREIGN KEY không dùng được ở đây.** Định danh một transcript revision là bộ
`(customer_id, media_file_id, locale, processing_version, source_fingerprint)`,
nhưng UNIQUE duy nhất của `media_transcripts` là
`(customer_id, media_file_id, locale, locator_type, locator_value,
processing_version)` — có `locator_value`, vì một revision gồm **nhiều row**, mỗi
row một segment. Không có khoá nào đại diện cho *một revision*, nên không có đích
để FK trỏ tới. Muốn có FK thật thì phải tách một bảng revision riêng; đó là
amendment khác, chưa được duyệt và **không** được ngầm hiểu từ quyết định này.

Vì thế bất biến sau là **bắt buộc ở tầng persist** của caption runtime, và phải
có test trước khi provider caption được cấu hình:

* Trước khi ghi một caption row do job sinh ra, phải tồn tại ít nhất một row
  `media_transcripts` `ready` với cùng `customer_id`, `media_file_id`, `locale`,
  `source_fingerprint` và `processing_version = transcript_processing_version`.
* Không thoả thì fail cả revision caption, không ghi row nào.

Ghi lại giới hạn này thay vì để trống là có chủ đích: một CHECK "trông như" đang
bảo đảm quan hệ mà thực ra không, là thứ dễ khiến người đọc sau tin nhầm.

### Vì sao ràng buộc là `processing_job_id IS NULL OR …`

`processing_job_id` nullable, nên bảng này chứa được caption **không** do job
sinh ra. Buộc `transcript_processing_version` NOT NULL cho mọi row sẽ chặn luôn
trường hợp đó. Ràng buộc vì thế neo vào *có job hay không*: caption do Media sinh
ra thì bắt buộc khai nguồn; caption đến từ đường khác thì không có gì để khai.

## Sample Data

`id=600, customer_id=1, media_file_id=100, locale=vi, caption_type=vtt, storage_key=tenants/1/captions/lesson-1-vi.vtt, status=ready`

## Design Notes

Caption thuộc Media processing/delivery; Course/LiveClass chỉ tham chiếu Media File hoặc Usage.
