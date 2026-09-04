# Table: media_region_languages

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-04

Document Path: database/media/media_region_languages.md

Related ADR: [ADR-0019](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)

## Purpose

Lưu **mọi** script quan sát được trong một region, thay vì ép region về một
ngôn ngữ. Một vùng chứa cả tiếng Hàn và tiếng Việt sinh hai row. Row này là
bằng chứng chữ viết quan sát được, không phải khẳng định ngôn ngữ của nội dung
và không phải confidence.

## Ownership and provenance

Mỗi row thuộc đúng một region và kế thừa tenant, Media, processing job,
language profile, source fingerprint, processing version và revision từ region
cha. Row không tồn tại độc lập và không trỏ chéo revision.

## Fields and constraints

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | Identity. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| region_id | BIGINT UNSIGNED NOT NULL | Region quan sát được. |
| ordinal | TINYINT UNSIGNED NOT NULL | Thứ hạng theo `char_count` giảm dần, 1..5. |
| script | VARCHAR(20) NOT NULL | ISO 15924 quan sát được: `Hang`, `Latn`, `Hani`, `Jpan`. |
| locale | VARCHAR(20) NULL | Locale trong profile ứng với script; NULL là `undetermined`. |
| char_count | INT UNSIGNED NOT NULL | Số ký tự thuộc script đó trong region. |
| created_at / updated_at | TIMESTAMP(6) NULL | Audit timestamps. |

```sql
UNIQUE (customer_id, region_id, ordinal);
UNIQUE (customer_id, region_id, script);
INDEX  (customer_id, locale);
FOREIGN KEY (region_id, customer_id)
  REFERENCES media_extracted_regions (id, customer_id) CASCADE;
CHECK (ordinal BETWEEN 1 AND 5);
CHECK (char_count >= 1);
```

## Quan hệ với cột dominant trên region

`media_extracted_regions.detected_locale` và `.script` giữ nguyên ngữ nghĩa cũ
và bằng đúng row `ordinal = 1`. Consumer đã đọc hai cột đó không phải đổi gì;
consumer cần bằng chứng đầy đủ thì đọc bảng này.

`char_count` là phép đếm ký tự, **không** phải điểm tin cậy. Provider không trả
confidence cho language signal, và không được suy ra một điểm số từ tỷ lệ ký tự.

Script được nhận khi region chứa ít nhất một ký tự thuộc script đó. Với Hangul,
phạm vi gồm cả Jamo độc lập (`ㄱ`, `ㄹ`, `ㅇ`) chứ không chỉ syllable hoàn chỉnh:
một câu tiếng Việt trích dẫn chữ cái Hàn sinh hai row, `Latn` và `Hang`.

`locale` chỉ được điền khi profile của job có locale tương ứng. Script quan sát
được nhưng không nằm trong profile vẫn sinh row với `locale = NULL`; đó là dữ
liệu có tên, không phải thiếu dữ liệu. Không suy ngược profile từ row này.

Region không có text, hoặc có text nhưng không nhận được script nào, thì không
sinh row — không dùng row rỗng để biểu diễn `undetermined`.

## Revision có trước v1.8

Revision tạo trước ADR-0019 v1.8 **không có row nào** ở bảng này, kể cả khi
region của nó đã mang `detected_locale`/`script`. Chúng không được backfill.

Cùng lý do với [media_processing_job_locales](media_processing_job_locales.md):
`char_count` là phép đếm ký tự quan sát được tại thời điểm extract, và suy ngược
nó từ hai cột dominant là phỏng đoán, không phải quan sát. Backfill như vậy sẽ
ghi một con số bịa vào đúng chỗ contract nói là bằng chứng.

Vì thế quan hệ "hai cột dominant bằng row `ordinal = 1`" chỉ áp dụng cho revision
sinh ra từ v1.8 trở đi. Với revision cũ, hai cột dominant là bằng chứng ngôn ngữ
duy nhất và vẫn đọc được nguyên vẹn; bảng này rỗng cho chúng. Consumer phân biệt
hai trường hợp bằng `processing_version` của chính unit đó.
