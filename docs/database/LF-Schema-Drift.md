# LearnForge Schema Drift Standard

Version: 1.2

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-13

Document Path: database/LF-Schema-Drift.md

---

# Purpose

`schema:drift` là quality gate canonical, không phá hủy, dùng để so sánh độc
lập database documentation contract, migrations trong repository, fresh
MySQL/MariaDB tạm thời và database được chỉ định ở chế độ chỉ đọc.

Contract máy đọc được là
[`LF-SCHEMA-CONTRACT.json`](LF-SCHEMA-CONTRACT.json). Contract bổ sung dữ liệu
cho automation, không thay thế Domain policy, ADR, review, table documentation,
migration hoặc `LF-INDEX.md`.

# Source Model

```text
Documentation contract
        ↕
Migrations → Fresh ephemeral database
                 ↕
        Selected read-only database
```

Không nguồn nào tự động thắng khi có khác biệt. Documentation conflict đã xác
minh phải theo conflict-register workflow; implementation drift được báo riêng.

# Ownership and Contract

Owner: Architecture Team. Schema version: `1.0`. Record sắp xếp theo physical
table name. Mọi table document dưới `docs/database/<domain>/` phải xuất hiện
dưới dạng primary hoặc additional documentation.

Vocabulary: `implemented`, `planned`, `not_implemented`, `historical`,
`ignored`. `implemented` chỉ dùng khi migration có thể dựng physical table;
`planned`/`not_implemented` không yêu cầu table tồn tại; `historical` không là
schema authority hiện hành; `ignored` dành cho infrastructure có bằng chứng.
Không được suy đoán trạng thái thiếu evidence.

# Safe Commands

```bash
php artisan schema:drift --docs-only
php artisan schema:drift --fresh
php artisan schema:drift --connection=mysql
php artisan schema:drift --connection=mysql --format=json
```

Phải chọn đúng một mode. `--docs-only` không kết nối database. `--fresh` chỉ
chấp nhận MySQL/MariaDB, tạo database ngẫu nhiên đúng mẫu
`lf_schema_drift_<16 alphanumeric>`, chạy migrations trên database này và luôn
drop trong `finally`; không chạy migration trên database name cấu hình.
Fresh mode bị cấm hoàn toàn trong production và bỏ qua mọi `DB_URL` khi tạo
hai connection tạm để URL không thể override database name đã kiểm chứng.

`--connection` chỉ đọc schema metadata và migration ledger. Production cần
explicit `--allow-production-read`, nhưng flag này không cấp quyền mutate.
SQLite bị từ chối vì không chứng minh tương thích MySQL/MariaDB. Output không
chứa username, password, URL/DSN, record data hoặc credential.

# Comparison and Severity

So sánh theo semantics: bỏ qua tên index/constraint nhưng kiểm tra column set,
type, unsigned, null/default, auto increment/generated, PK, unique/index, FK
target/action, checks, triggers và views. Metadata ordering bị bỏ qua khi thứ tự
không phải contract. Trigger cũng bỏ qua tên theo mặc định để tương thích
contract cũ. Một table đặt `trigger_identity_required: true` phải khai báo
`name`, `timing`, `event` và `statement` cho từng trigger; khi đó drift so sánh
cả tên trigger. Giá trị này phải là boolean. MySQL inspector luôn đọc
`TRIGGER_NAME`, nên contract Foundation có thể bắt lỗi trigger đổi tên, thân
rỗng hoặc sai hành vi.

Severity độc lập với Regression Audit Level: `BLOCKER` cho missing required
object hoặc migration/ledger invalid; `HIGH` cho invariant type/null/default,
FK/unique/guard; `MEDIUM` cho index/check/unexpected table; `LOW` cho khác biệt
không ảnh hưởng runtime; `INFO` cho deferred table vắng đúng dự kiến. Gate fail
khi có `BLOCKER`, `HIGH` hoặc `MEDIUM`.

# Allowlist

Mỗi entry phải có exact `object`, `reason`, `owner`, `evidence` và
`review_condition`; wildcard bị cấm. Không được che tenant constraint, required
FK, unique invariant hoặc persistence guard. Baseline chỉ allowlist Laravel
infrastructure tables và hai released LiveClass migrations trùng timestamp
`2026_08_05_010000` nhưng có filename khác nhau và deterministic.

# Maintenance and CI

1. Route qua Guardrails, ADR/domain/database docs liên quan, Architecture
   Review và Regression Audit.
2. Cập nhật table documentation/evidence trước, rồi cập nhật contract cùng
   change và giữ deterministic ordering.
3. Chỉ dùng forward migration; không đổi migration lịch sử.
4. Chạy docs-only, fresh MySQL/MariaDB, targeted tests, docs lint và formatter.
5. Review drift mà không tự chọn nguồn thắng.

Pull request dùng MariaDB service được pin version. CI user (`lf_ci`) chỉ có
quyền trên đúng pattern `lf_schema_drift_%` (không phải `*.*`): `CREATE`,
`DROP` để tạo/xoá ephemeral database, cộng `ALTER`, `INDEX`, `REFERENCES`,
`TRIGGER`, `SELECT`, `INSERT`, `UPDATE`, `DELETE` — bắt buộc vì `--fresh`
chạy migration thật bên trong database đó (`Schema::table`, foreign key,
trigger, backfill và migrations ledger đều cần các quyền này). Không có
quyền nào vượt ra ngoài pattern đó, và không dùng production credentials. So
sánh database thật là manual/scheduled trên target read-only đã được phê
duyệt.

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
