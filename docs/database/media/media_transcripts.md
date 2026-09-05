# Table: media_transcripts

Version: 1.10

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-01

Document Path: database/media/media_transcripts.md

## Purpose

Lưu transcript được tạo từ audio/video Media File, theo **từng đoạn trích dẫn**.

Một row là một `timespan`, không phải toàn bộ transcript — cùng nguyên tắc với
`media_extracted_texts` nơi một row là một trang. Ghép các đoạn lại là việc rẻ;
tách một khối văn bản liền thành đoạn có mốc thời gian sau khi đã mất ranh giới
thì không làm được.

## Relationships

```text
media_files 1 → N media_transcripts       (nhiều locale, nhiều đoạn, nhiều revision)
media_processing_jobs 1 → N media_transcripts
```

## Business Rules

### Multilingual STT extension — Approved 2026-09-05

Revision có profile 2–3 locale dự kiến dùng `media_transcripts.locale = 'mul'`
như output selector, không coi `mul` là evidence của từng segment. Mỗi row
timespan cần child language-evidence tenant/revision-scoped tương tự region
language evidence; một segment có thể có 0..N locale được quan sát.

Child schema canonical là
[media_transcript_languages](media_transcript_languages.md). Revision một locale
và mọi row lịch sử giữ nguyên; không backfill evidence bằng profile hoặc bằng
text suy đoán.

* Transcript và Media File phải cùng tenant.
* Transcript text phải nằm trong `text`, không nhét vào metadata.
* `confidence_score` từ `0.00` đến `100.00` khi có.
* Allowed `status`: `pending`, `processing`, `ready`, `failed`, `archived`.
* Transcript là Digital Asset output; AI Domain tự quyết định cách dùng cho knowledge/insight.

* Output neo vào một lần chạy cụ thể qua `processing_job_id`, và mang
  `processing_version` cùng `source_fingerprint` của lần chạy đó.
* Chạy lại **không ghi đè**: nội dung hoặc phiên bản xử lý đổi thì sinh bộ row
  mới, bộ cũ chuyển `archived`. Quy tắc stale đầy đủ nằm trong
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md).
* Locator theo hợp đồng chung: `locator_type = 'timespan'`, `locator_value` là
  `<start_ms>-<end_ms>`, đơn vị **millisecond**. Mọi nội dung trả cho consumer
  phải kèm locator.
* Trong một revision, segment phải **tăng dần theo `start_ms`** và **không chồng
  lấn**. `timespan` là khoảng **nửa mở** `[start_ms, end_ms)`, nên luật là
  `start_ms >= prev.end_ms`; segment giáp ranh là hợp lệ và trên thực tế là
  thường gặp. Với `N` segment chỉ có tối đa `N-1` cặp liền kề; mọi tỷ lệ đo phải
  dùng mẫu số này và lưu raw per-segment artefact. Độ dài 0 không hợp lệ. Vi phạm thì fail cả revision bằng
  `transcript_invalid`, không ghi row nào. Xem § Vì sao luật này nằm ở tầng
  persist.
* Với Audio và Video, `end_ms` không được vượt
  `media_files.duration_seconds * 1000`. Timestamp nằm
  ngoài binary nguồn không phải citation hợp lệ; vi phạm fail cả revision bằng
  `transcript_invalid`, không clamp hoặc giữ một revision một phần.
* Chỉ row `ready` được Media Read Service trả ra.
* Mỗi locale/diarization profile có retry chain độc lập. Một transcript locale
  hết retry không chặn enqueue/ready/retry của locale khác và không làm binary
  Media File mất `ready`; Media Read Service trả readiness của transcript riêng.

## Vì sao luật thứ tự nằm ở tầng persist — v1.5

Bảng này **không có** cột `start_ms`, `end_ms` hay `sequence`. Chỉ có
`locator_value` kiểu VARCHAR. Ba hệ quả, và cả ba đều phải nói ra:

* `UNIQUE (customer_id, media_file_id, locale, locator_type, locator_value,
  processing_version)` chặn hai segment **trùng khít**, nhưng `0-1000` và
  `500-1500` là hai chuỗi khác nhau — **chồng lấn vẫn lọt qua**.
* Không sắp xếp được bằng SQL. Sắp theo chuỗi thì `'1000-2000'` đứng trước
  `'500-1500'`, tức sai thứ tự thời gian.
* `MediaReadService` trả transcript theo `ORDER BY id`. Nghĩa là thứ tự thời gian
  hiện **đúng do tình cờ** — nó trùng thứ tự provider chèn row, và không có gì
  bảo đảm điều đó.

Vì thế luật "tăng dần, không chồng lấn" được cưỡng chế **trước khi ghi row đầu
tiên**, cùng cách `reading_order` được cưỡng chế cho `media_extracted_regions`.
Khi persist đã bảo đảm thứ tự chèn, `ORDER BY id` trở thành thứ tự thời gian
**theo cấu trúc**, không còn là may mắn.

Runtime `faster_whisper_local` validate toàn bộ unit trước insert đầu tiên. Một
locator sai định dạng, segment rỗng, độ dài 0 hoặc overlap làm transaction fail
`transcript_invalid`; revision đó để lại 0 row. Segment giáp ranh
`start_ms == previous_end_ms` vẫn hợp lệ theo khoảng nửa mở.

Đổi sang cột `start_ms`/`end_ms` thật sẽ cho phép cưỡng chế bằng schema và sắp
xếp bằng SQL. Đó là amendment riêng, cần Database review và migration; nó **không**
được ngầm hiểu là đã quyết ở đây.

## Retention — v1.8, Owner approved

Owner phê duyệt ngày 2026-08-29: transcript bị xoá khi Media nguồn bị xoá. Runtime
purge `media_transcripts` trong cùng transaction ghi tombstone
`media_files.status = 'deleted'`; không giữ transcript để phục vụ citation lịch
sử sau khi source đã bị xoá.

Luật này áp dụng cùng extracted text và structured content theo
[Processing Contract](../../platform/LF-Media-Processing-Contract.md). Processing
job cùng access log được giữ làm provenance/audit, nhưng Media Read từ chối Media
đã `deleted` và không còn row transcript nào để trả.

Đường đọc dẫn xuất **đã** được audit bằng `media_access_logs.action =
'read_derived'`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File nguồn. |
| locale | VARCHAR(20) NOT NULL | Locale transcript. |
| provider | VARCHAR(100) NULL | Speech-to-text provider. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Processing state. |
| text | LONGTEXT NULL | Nội dung transcript. |
| confidence_score | DECIMAL(5,2) NULL | Confidence 0–100. |
| metadata | JSON NULL | Timing/model provenance, không chứa transcript text. |
| processing_job_id | BIGINT UNSIGNED NULL | Lần chạy đã tạo ra row này. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản extractor/model/cấu hình. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn khi xử lý. |
| locator_type | VARCHAR(20) NOT NULL | `timespan`. |
| locator_value | VARCHAR(50) NOT NULL | `<start_ms>-<end_ms>`. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints And Indexes

`UNIQUE (customer_id, media_file_id, locale)` của Version 1.0 đã **bị loại bỏ**.
Nó cho phép đúng một transcript cho mỗi file và locale, mâu thuẫn trực tiếp với
hai điều Version 1.2 cam kết: một row là một đoạn, và processing version mới
sinh revision mới thay vì ghi đè.

```sql
UNIQUE (customer_id, media_file_id, locale, locator_type, locator_value,
        processing_version);
UNIQUE (id, customer_id);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;

CHECK (status IN ('pending','processing','ready','failed','archived'));
CHECK (locator_type IN ('timespan'));
CHECK (confidence_score IS NULL
       OR (confidence_score >= 0 AND confidence_score <= 100));
CHECK (status <> 'ready' OR text IS NOT NULL);
```

## Sample Data

`id=500, customer_id=1, media_file_id=100, locale=vi, provider=aws_transcribe, status=ready, text=Nội dung bài giảng..., confidence_score=94.20`

## Design Notes

Version 1.0 giữ một canonical transcript cho mỗi file/locale và coi
version/provider alternatives là chuyện tương lai. Processing versioning đã biến
"tương lai" thành hợp đồng hiện hành, nên giả định đó không còn đúng: nhiều
revision cùng tồn tại, bản cũ `archived`, và bản `archived` phải đọc được vĩnh
viễn để một trích dẫn cũ vẫn trỏ đúng chỗ.
