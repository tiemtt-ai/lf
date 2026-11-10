# AGENTS.md

Before modifying LearnForge code:

1. Read docs/README.md
2. Read docs/LF-INDEX.md
3. Read docs/governance/LF-Architecture-Guardrails.md
4. Follow the Documentation Routing Guide in LF-INDEX.md
5. Load only the documents relevant to the current task
6. For major refactors or changes involving auth, tenant, navigation, UI, i18n,
   permissions, routes, or middleware, also read
   docs/quality/LF-Regression-Audit.md

Governance rules:

- LF-Architecture-Guardrails.md has higher priority than module documentation
  when they conflict.
- If a proposed change risks violating the Guardrails, stop and report the
  risk before writing code.

LearnForge is a multi-tenant AI-native LMS SaaS.

Preserve:

- tenant isolation
- customer_id ownership
- LF-OS principles
- LF architecture standards

## Architecture Workflow

```text
Governance

↓

ADR

↓

Domain

↓

Database

↓

Review

↓

Freeze

↓

Migration

↓

Implementation

↓

Testing
```

## Database Rule

Không tạo migration trước khi:

- Database Docs approved
- ADR approved nếu thay đổi là Foundation
- Architecture Review passed

## Documentation Rule

Không tạo:

- report
- review
- temporary analysis

trong:

- `docs/governance`
- `docs/database`
- `docs/core`
- `docs/platform`
- `docs/saas`

Review artifacts phải nằm trong:

```text
docs/quality
```

hoặc working directory.

## Testing Rule

- Chạy test đúng dạng `php artisan test <tệp>`. Không thêm `--env=...`: cờ này
  làm phpunit.xml (SQLite `:memory:`) không còn áp dụng, test chạy vào DB thật
  và `RefreshDatabase` xoá sạch dữ liệu (2026-11-04 đã mất `learnforge_db`).
- Không chạy test vào `learnforge_db` (DB dev). Test chạy lâu bất thường
  (giây → phút) là dấu hiệu đang vào MariaDB thật.
- SQLite bỏ qua CHECK, độ rộng cột và kiểu thời gian. Schema, migration và test
  `tests/Integration/*MariaDb*` phải kiểm trên một instance MariaDB 11.4 tạm
  (`mariadb-install-db` + `mariadbd --skip-networking --socket=...`), rồi chạy
  với `DB_CONNECTION=mysql DB_SOCKET=<sock> DB_HOST=localhost DB_USERNAME=root DB_PASSWORD=`.
  Tắt instance và xoá datadir khi xong.
- `BulkEnrollmentMysqlConcurrencyTest` cần DB tên
  `lf_bulk_enrollment_concurrency_test_20260721` đã migrate sẵn và `CACHE_STORE=database`.
- Kiểm schema: `php artisan schema:drift --docs-only` và
  `php artisan schema:drift --connection=mysql`.
- Trước mọi thao tác ghi vào `learnforge_db`: `mysqldump --single-transaction
  --routines --triggers --events` ra thư mục ngoài repo.
