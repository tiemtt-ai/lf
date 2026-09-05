# Table: media_spoken_languages

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-05

Document Path: database/media/media_transcript_languages.md

Related ADR: [ADR-0004 D7](../../adr/ADR-0004-Media-Foundation.md)

## Purpose

Lưu language evidence quan sát được trên từng transcript timespan của revision
Audio/Video multilingual. Requested profile là candidate set, không tự sinh row.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | Identity. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| transcript_id | BIGINT UNSIGNED NOT NULL | Segment owner. |
| ordinal | TINYINT UNSIGNED NOT NULL | Thứ tự evidence 1..3. |
| locale | VARCHAR(20) NOT NULL | Locale quan sát được và thuộc job profile. |
| char_count | INT UNSIGNED NOT NULL | Số ký tự hỗ trợ evidence. |
| created_at / updated_at | TIMESTAMP(6) NULL | Audit timestamps. |

```sql
UNIQUE (customer_id, transcript_id, ordinal);
UNIQUE (customer_id, transcript_id, locale);
INDEX (customer_id, locale);
FOREIGN KEY (transcript_id, customer_id)
  REFERENCES media_transcripts (id, customer_id) CASCADE;
CHECK (ordinal BETWEEN 1 AND 3);
CHECK (char_count >= 1);
```

Segment có thể có 0..3 row. Revision một locale cũ không backfill. Xóa Media
purge transcript và cascade evidence; rollback migration bị từ chối khi còn row.

Migration canonical:
`2026_09_05_000200_add_media_spoken_languages.php`.
