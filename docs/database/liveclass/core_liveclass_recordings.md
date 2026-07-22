# Table: core_liveclass_recordings

## Purpose

Quản lý liên kết và metadata vận hành của một recording được tạo từ LiveClass
Session.

File thật thuộc Media Domain. LiveClass không lưu binary file và không sở hữu
media lifecycle.

## Relationships

```text
Customer 1 → N LiveClass Recordings

LiveClass Session 1 → N LiveClass Recordings

LiveClass Room 1 → N LiveClass Recordings

Media File 1 → 0..N LiveClass Recordings

Version Activity 1 → N LiveClass Recordings

LiveClass Recording 1 → N LiveClass Replays
```

## Business Rules

* Recording phải thuộc `customer_id`.
* Recording, Session và Room phải thuộc cùng tenant và cùng Course context.
* `version_activity_id` phải có `activity_type = live_class`.
* File thật và storage lifecycle thuộc Media Domain.
* `media_file_id` là canonical media reference sau khi recording được import
  vào Media.
* Khi có `media_file_id`, Media File phải thuộc cùng tenant.
* Transcript/subtitle file thật thuộc Media Domain.
* Nếu có `transcript_media_id` hoặc `subtitle_media_id`, Media File phải cùng
  `customer_id` với Recording.
* AI summary và transcript processing thuộc Media/AI Domain, không ghi trực
  tiếp kết quả xử lý vào Recording.
* `recording_url` chỉ là provider/source URL; không phải canonical learning
  media khi đã có `media_file_id`.
* Provider URL hoặc access token không được mặc định public và không được lưu
  signing secret trong metadata.
* Recording không tự ghi hoặc quyết định Course progress.
* Allowed `status`: `processing`, `ready`, `failed`, `archived`, `deleted`.

## Fields

### id

```text
BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
```

Khóa chính của Recording.

### customer_id

```text
BIGINT UNSIGNED NOT NULL
```

Tenant sở hữu Recording metadata.

### session_id

```text
BIGINT UNSIGNED NOT NULL
```

Session tạo ra Recording.

### room_id

```text
BIGINT UNSIGNED NOT NULL
```

Room chứa Session.

### product_id

```text
BIGINT UNSIGNED NOT NULL
```

Course Product context của Recording.

### template_version_id

```text
BIGINT UNSIGNED NOT NULL
```

Published Template Version context của Recording.

### version_activity_id

```text
BIGINT UNSIGNED NOT NULL
```

Version Activity `live_class` liên quan.

### media_file_id

```text
BIGINT UNSIGNED NULL
```

Canonical reference tới `media_files.id` sau khi Media Domain tiếp nhận file.

### transcript_media_id

```text
BIGINT UNSIGNED NULL
```

Reference tới transcript file đã được Media Domain quản lý.

### subtitle_media_id

```text
BIGINT UNSIGNED NULL
```

Reference tới subtitle file đã được Media Domain quản lý.

### provider_recording_id

```text
VARCHAR(255) NULL
```

Định danh recording tại provider.

### title

```text
VARCHAR(255) NOT NULL
```

Tên hiển thị của Recording.

### duration_seconds

```text
BIGINT UNSIGNED NOT NULL DEFAULT 0
```

Thời lượng recording theo metadata đã chuẩn hóa.

### recording_url

```text
TEXT NULL
```

Provider/source URL tạm thời để import hoặc đối soát.

### status

```text
VARCHAR(50) NOT NULL DEFAULT 'processing'
```

Trạng thái tiếp nhận và khả dụng của Recording trong LiveClass.

### available_at

```text
TIMESTAMP NULL
```

Thời điểm Recording sẵn sàng cho consumer được phép.

### metadata

```text
JSON NULL
```

Metadata provider/import. Không chứa file binary, access secret hoặc Course
completion state.

### created_at

```text
TIMESTAMP NULL
```

Thời điểm tạo Recording record.

### updated_at

```text
TIMESTAMP NULL
```

Thời điểm cập nhật Recording gần nhất.

## Indexes

```sql
INDEX (customer_id);

INDEX (customer_id, session_id);

INDEX (customer_id, media_file_id);

INDEX (customer_id, transcript_media_id);

INDEX (customer_id, subtitle_media_id);

INDEX (customer_id, version_activity_id);

INDEX (customer_id, status);
```

Nếu provider bảo đảm một ID duy nhất trong Session, implementation phase có thể
thêm conditional uniqueness cho `(customer_id, session_id,
provider_recording_id)` sau khi xác nhận sync contract.

## Sample Data

```text
id = 4001
customer_id = 1
session_id = 2001
room_id = 1001
product_id = 10
template_version_id = 30
version_activity_id = 9003
media_file_id = 7001
transcript_media_id = 7002
subtitle_media_id = 7003
provider_recording_id = rec-987654321-1
title = TOPIK Beginner Live Class - Session 1 Recording
duration_seconds = 5240
recording_url = NULL
status = ready
available_at = 2026-07-04 09:15:00
metadata = {"import_status": "completed"}
```

## Design Notes

* `media_file_id` là bridge sang Media Domain; delivery, signed URL,
  transcoding, retention của file và privacy enforcement thuộc Media.
* `transcript_media_id` và `subtitle_media_id` cũng là Media references; nội
  dung, processing lifecycle và AI outputs không thuộc Recording.
* `duration_seconds` có source of truth là Media metadata sau import; trước đó
  có thể lấy tạm từ provider và phải được cập nhật khi Media processing hoàn
  tất.
* `status = deleted` mô tả operational reference; chính sách xóa file thật do
  Media Domain thực thi.
