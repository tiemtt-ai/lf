# LearnForge

LearnForge (LF) là LMS SaaS đa tenant, AI-native, phục vụ trải nghiệm public,
học viên, giáo viên và quản trị khách hàng trên cùng một Laravel monolith.

## Stack chính

- PHP 8.3+, Laravel 12
- Blade, Livewire, Alpine.js, Vite
- MySQL/MariaDB cho runtime; Redis cho cache, queue và session
- PHPUnit; SQLite in-memory là baseline test mặc định

## Yêu cầu local

Cài PHP 8.3 với các extension Laravel cần thiết, Composer 2, Node.js/npm,
MySQL hoặc MariaDB và Redis. FFmpeg/ffprobe cần thiết cho chức năng đọc metadata
media. Host tenant local dùng dạng `<tenant>.localhost`.

## Cài đặt tối thiểu

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

`.env` không bao giờ được commit (nằm trong `.gitignore`); `.env.example` là
template để tạo ra nó, không phải bản sao lưu. Tạo một database local riêng, sau
đó chỉnh `.env`. Không commit credential:

```dotenv
APP_ENV=local
APP_URL=http://localhost:8000
APP_BASE_DOMAIN=localhost
APP_TENANT_SCHEME=http

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_local_database
DB_USERNAME=your_local_user
DB_PASSWORD=your_local_password
```

Chỉ chạy migration khi đã xác minh database đích là database local/test phù
hợp và migration đã qua quy trình tài liệu/review của LF:

```bash
php artisan migrate
```

## Chạy ứng dụng

Chạy backend và frontend riêng:

```bash
php artisan serve --host=0.0.0.0 --port=8000
npm run dev
```

Hoặc dùng script local tổng hợp, có thêm queue và Reverb:

```bash
npm run dev:all
```

Sau khi có tenant, truy cập `http://<tenant>.localhost:8000/login`.

## Quality gates

```bash
php artisan test
php artisan docs:lint
php artisan schema:drift --docs-only
php artisan schema:drift --connection=mysql
```

`schema:drift --connection=mysql` chỉ nên chạy trên connection đã xác minh;
command đọc schema metadata và migration ledger, không tự chạy migration.

> **Cảnh báo dữ liệu:** không chạy `migrate:fresh`, `db:wipe`, `DROP`,
> `TRUNCATE` hoặc migration thử nghiệm trên database LF thật. Các lệnh này có
> thể xóa dữ liệu không thể phục hồi.

## Tài liệu và quyết định

Bắt đầu mọi công việc architecture, schema hoặc implementation tại
[`docs/LF-INDEX.md`](docs/LF-INDEX.md), rồi đi theo Documentation Routing Guide.

`Document/Policy Status` cho biết tài liệu đã được duyệt đến đâu;
`Implementation Status` cho biết mức triển khai đã được xác minh. Hai trạng
thái độc lập. Khi tài liệu, source hoặc database xung đột: **STOP**, ghi nhận
bằng chứng và không tự đoán nguồn thắng.

## Workflow tối giản cho một developer

1. Kiểm tra `git status`, đọc `docs/LF-INDEX.md` và tài liệu được route tới.
2. Xác nhận hành vi hiện tại từ source, tests và database khi áp dụng.
3. Thay đổi nhỏ nhất có thể; giữ tenant isolation và `customer_id` ownership.
4. Chạy targeted tests trong lúc sửa, sau đó chạy các quality gate ở trên.
5. Review diff, commit theo một mục đích rõ ràng và chỉ push khi gate bắt buộc
   đã PASS hoặc rủi ro còn lại đã được ghi nhận.
