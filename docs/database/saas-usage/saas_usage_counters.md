# Table: saas_usage_counters

Version: 1.1

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-09-12

Document Path: database/saas-usage/saas_usage_counters.md

Implementation evidence: migration
`2026_09_12_000100_create_saas_usage_quota_packet.php`, verified on an isolated
MariaDB 11.4.12 database. Schema contract harvested from that database; this is
not a claim that the migration was applied to `learnforge_db`. No provider was
activated and no historical Media evidence was modified.

**Owner approval — 2026-09-12:** Owner yêu cầu thực hiện migration và hoàn tất
store theo thiết kế hiện tại sau khi bỏ điều kiện review độc lập. Bản thiết kế
này Approved/Frozen theo quyết định đó; không ghi nhận review PASS giả.
Các ghi chú giữ Review/chờ PASS bên dưới là lịch sử đã được thay thế.

> **Owner waiver — 2026-09-12 (Bước 4):** Review có thực hiện hay không do
> Owner quyết định. Yêu cầu review/PASS độc lập bắt buộc trước khi Frozen,
> migration hoặc đóng Bước 4 đã được Owner bỏ; các khối yêu cầu đó bên dưới
> chỉ còn là lịch sử. Đây không phải review PASS, không tự chứng nhận
> implementation và không thay đổi schema. Phê duyệt thiết kế và kiểm chứng
> kỹ thuật vẫn cần được ghi đúng. Xem
> [quyết định hiện hành](../../quality/LF-SaaS-Commercial-Usage-Packet-Reviewer-Brief.md#owner-confirmation--2026-09-12).

## Owner Freeze — 2026-09-10 — superseded by schema amendment

```text
Role: LearnForge Architecture Owner
Date: 2026-09-10
Decision: APPROVED AND FROZEN
Scope: saas_usage_counters projection and watermark schema in this version
```

**Superseded 2026-09-10.** Khối trên giữ nguyên làm lịch sử: Owner đã quyết đúng
như vậy trên **bản tại thời điểm đó**. Bản hiện tại đã đổi schema sau finding C1
(khai tường minh default và on-update của `updated_at`) của lượt review độc lập,
nên nó **không** nằm trong phạm vi chữ ký trên. Tài liệu trở lại `Review`; cần
một reviewer độc lập PASS rồi Owner phê duyệt lại bản sửa trước khi Frozen.

Implementation remains `Not Implemented`. Freeze does not itself authorize or
apply a migration without the required Architecture Review PASS.

## Purpose

Current accumulated Usage projection by Customer, feature, period and unit.

## Relationships

Counter belongs to one Customer and is derived exclusively from
`saas_usage_events`.

## Business Rules

* Every Counter belongs to one `customer_id`.
* Allowed `period_type`: `daily`, `monthly`, `yearly`, `lifetime`.
* `(customer_id, feature_key, period_type, period_key, unit)` is unique.
* Counter is derived and rebuildable from Usage Events.
* Counter is not Source Of Truth.
* Business/source Domain and Billing cannot update Counter directly.
* Counter stores consumed quantity, not allowed Entitlement value.
* `period_key` must follow the approved timezone/format contract.
* `usage_quantity` must be recalculated when an included late/correction event
  arrives.
* `updated_at` khai default và on-update tường minh. Hành vi on-update ở đây là
  **muốn có** — đây là projection. Vấn đề không phải hành vi mà là nguồn của
  nó: MariaDB chỉ gắn ngầm khi `explicit_defaults_for_timestamp` tắt, nên nếu
  để trần thì cột này **có** on-update ở nơi này và **không có** ở nơi khác. Đo
  ngày 2026-09-10: server deployment (MariaDB 10.4.21) đặt
  `explicit_defaults_for_timestamp = 0`, còn một bản MariaDB 11.4.12 cài mặc
  định đặt `= 1`. Nghĩa là **cùng một migration sinh ra hai schema khác nhau**
  tùy nơi chạy, và `schema:drift --fresh` xanh trên chính server nó vừa dựng
  nên không nhìn thấy khác biệt đó. Khai kiểu/default tường minh làm schema độc
  lập với biến cấu hình này.
* `last_usage_event_id` is the projection watermark. It is not used for quota
  authorization; Commercial reservation enforcement must remain correct while
  this projection is stale.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| feature_key | VARCHAR(100) NOT NULL | Aggregated feature identifier. |
| period_type | VARCHAR(50) NOT NULL | Daily, monthly, yearly or lifetime period. |
| period_key | VARCHAR(50) NOT NULL | Canonical period identifier. |
| usage_quantity | DECIMAL(20,6) NOT NULL DEFAULT 0 | Accumulated consumed quantity. |
| last_usage_event_id | BIGINT UNSIGNED NULL | Highest Usage Event included in this projection. |
| unit | VARCHAR(50) NOT NULL | Unit matching source metric contract. |
| updated_at | TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) | Last projection update/rebuild time. |

## Indexes

```sql
PRIMARY KEY (id);
UNIQUE (customer_id, feature_key, period_type, period_key, unit);
INDEX (customer_id, period_type, period_key);
INDEX (customer_id, feature_key);
INDEX (updated_at);

FOREIGN KEY (customer_id) REFERENCES saas_customers(id) RESTRICT;
```

## Sample Data

`id=20001, customer_id=1, feature_key=ai_tutor, period_type=monthly, period_key=2026-06, usage_quantity=2500000, unit=token, updated_at=2026-06-28T08:20:00Z`

## Design Notes

Counter update requires concurrency-safe projection logic. Timezone,
late-arrival window and full/incremental rebuild strategy require owner
approval. A cache may accelerate reads but cannot become another Source Of
Truth.
