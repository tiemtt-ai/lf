# Implicit TIMESTAMP On-Update Audit

Version: 1.0

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-09-10

Review Date: 2026-09-10

Document Path: quality/LF-Implicit-Timestamp-OnUpdate-Audit.md

---

# Purpose

Backlog cho các cột `TIMESTAMP NOT NULL` khai báo **không** có default trong
database docs. Tách riêng khỏi packet SaaS đang review: chúng thuộc nhiều domain
khác nhau, mỗi domain có gate riêng, và gộp vào một packet sẽ kéo cả những domain
không liên quan vào cùng một vòng review.

# Vấn đề

MariaDB tự gắn **cả** `DEFAULT CURRENT_TIMESTAMP` **lẫn**
`ON UPDATE CURRENT_TIMESTAMP` cho cột `TIMESTAMP NOT NULL` đầu tiên của bảng
nếu cột đó không khai cái nào — nhưng chỉ khi `explicit_defaults_for_timestamp`
tắt.

Đo ngày 2026-09-10:

```text
server deployment (XAMPP)   MariaDB 10.4.21   explicit_defaults_for_timestamp = 0
instance CI-equivalent      MariaDB 11.4.12   explicit_defaults_for_timestamp = 1
```

Hai hệ quả, và hệ quả thứ hai mới là lý do chính để sửa:

1. **Mất dữ liệu lịch sử.** Mọi `UPDATE` lên hàng — kể cả UPDATE không liên
   quan — ghi đè cột thời điểm sự kiện. Đã xảy ra thật:
   `2026_08_09_050000_remove_implicit_timestamp_on_update_from_occurrence_columns`
   ghi nhận 5/11 hàng `core_course_enrollments` và 3/7 hàng
   `core_course_cohort_students` có `enrolled_at`/`joined_at` trùng
   `updated_at` và lệch hẳn `created_at`.
2. **Schema phân kỳ giữa các môi trường.** Cùng một migration sinh ra hai schema
   khác nhau tùy server, và `schema:drift --fresh` xanh trên chính server nó vừa
   dựng nên không nhìn thấy khác biệt. Bẫy này im lặng ở CI và chỉ hiện ở
   deployment.

# Trạng thái hiện tại của database thật

Truy vấn `information_schema.COLUMNS` trên `learnforge_db` ngày 2026-09-10:
**không cột `TIMESTAMP NOT NULL` nào đang mang `on update` ngầm.** Nghĩa là
bẫy chưa cắn lại kể từ khi migration trên vá xong. Đây là backlog phòng ngừa cho
các bảng **chưa migrate**, không phải sự cố đang diễn ra.

# Inventory — 15 cột

| `ai/ai_assistant_sessions.md` | 43 | `started_at` |
| `ai/ai_conversations.md` | 39 | `started_at` |
| `ai/ai_insights.md` | 51 | `observed_at` |
| `assessment/core_assessment_attempts.md` | 39 | `started_at` |
| `saas-commercial/saas_subscriptions.md` | 37 | `starts_at` |
| `saas-usage/saas_usage_summaries.md` | 36 | `period_start` |
| `saas-usage/saas_usage_summaries.md` | 37 | `period_end` |
| `saas-usage/saas_usage_summaries.md` | 40 | `generated_at` |
| `saas/saas_audit_logs.md` | 38 | `created_at` |
| `saas/saas_customer_invitations.md` | 37 | `expires_at` |
| `track/track_activity_summaries.md` | 55 | `recalculated_at` |
| `track/track_ai_features.md` | 48 | `calculated_at` |
| `track/track_daily_summaries.md` | 55 | `recalculated_at` |
| `track/track_learning_paths.md` | 41 | `started_at` |
| `track/track_learning_sessions.md` | 36 | `started_at` |

# Đã xử lý, ngoài phạm vi backlog này

| Doc | Cột | Xử lý |
| --- | --- | --- |
| `saas-commercial/saas_entitlements.md` | `effective_from`, `effective_to` | → `DATETIME(6)` (finding P0-1) |
| `saas-usage/saas_usage_events.md` | `occurred_at`, `created_at` | → `DATETIME(6)` / default tường minh (finding E2) |
| `saas-usage/saas_usage_counters.md` | `updated_at` | default + on-update tường minh (finding C1) |

# Quy tắc đề xuất

* Mốc thời gian nghiệp vụ (thời điểm sự kiện xảy ra, biên hiệu lực, biên period):
  `DATETIME(6)`. Không có hành vi ngầm, không trần 2038.
* Cột audit `created_at`/`updated_at`: `TIMESTAMP(6)` khai default — và khai
  `ON UPDATE` tường minh khi thực sự muốn có.
* Không dựa vào `explicit_defaults_for_timestamp` ở bất kỳ chiều nào.

# Cách xử lý

Mười lăm cột trên đều thuộc bảng `not_implemented`, nên vá ở tầng doc là đủ và
**không cần migration**. Mỗi doc đi theo gate của domain sở hữu nó; audit này chỉ
là inventory và quy tắc chung, không phải quyết định thay cho domain nào.

Nếu một bảng trong danh sách được migrate trước khi doc của nó được sửa, migration
đó phải khai kiểu tường minh bất kể doc nói gì, và sửa doc trong cùng thay đổi.

---

## Owner

Architecture Team
