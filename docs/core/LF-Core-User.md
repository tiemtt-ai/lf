# LF-Core-User.md

Version: 1.3

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-11-17

Document Path: core/LF-Core-User.md

---

# LF-Core User Domain

User Domain là một trong những domain quan trọng nhất của LearnForge.

Mọi hoạt động trong hệ thống đều được thực hiện bởi một người dùng.

Ví dụ:

* đăng nhập
* tạo khóa học
* tạo bài thi
* học tập
* làm bài thi
* sử dụng AI
* xem báo cáo

đều bắt đầu từ User.

---

# Mission

Mục tiêu của User Domain là:

Quản lý toàn bộ vòng đời người dùng trong hệ sinh thái LearnForge.

Bao gồm:

* xác thực
* hồ sơ
* vai trò
* phân quyền
* trạng thái
* dữ liệu liên quan

---

# Core Principle

Mọi người dùng đều thuộc về một tenant.

Không tồn tại user độc lập ngoài tenant.

---

# Ownership Model

```text
LearnForge

↓

Customer (Tenant)

↓

Users

↓

Activities
```

---

# User Table

LearnForge sử dụng bảng:

```text
users
```

làm bảng người dùng trung tâm.

---

# Core Fields

```text
id

customer_id

role

status

name

email

password

phone

date_of_birth

gender

email_verified_at

created_at

updated_at
```

---

# Customer Ownership

Mọi user phải thuộc:

```text
customer_id
```

---

# Example

```text
KAHA

customer_id = 1

Users:

- Admin
- Teacher
- Student
```

---

```text
VISANG

customer_id = 2
```

sẽ có tập người dùng riêng.

---

# User Lifecycle

```text
Create User

↓

Activate

↓

Use System

↓

Update Profile

↓

Deactivate

↓

Archive
```

---

# User Status

Hiện tại:

```text
active

inactive
```

---

# Active

Có quyền đăng nhập.

Có quyền sử dụng hệ thống.

---

# Inactive

Không thể đăng nhập.

Không thể sử dụng hệ thống.

Dữ liệu lịch sử vẫn được giữ lại.

---

# User Roles

LearnForge hiện hỗ trợ:

```text
customer_admin

teacher

student
```

---

# Future Role

```text
super_admin
```

---

# Role Philosophy

Vai trò xác định:

* quyền truy cập
* phạm vi dữ liệu
* khu vực làm việc

---

# Customer Admin

## Purpose

Quản trị tenant.

---

## Portal

```text
/admin
```

---

## Responsibilities

Quản lý:

* giáo viên
* học viên
* khóa học
* bài thi
* báo cáo
* billing
* settings

---

## Scope

Toàn bộ tenant.

---

# Teacher

## Purpose

Giảng dạy.

---

## Portal

```text
/teacher
```

---

## Responsibilities

* tạo khóa học
* tạo bài học
* tạo bài thi
* chấm bài
* theo dõi học viên

---

## Scope

Các tài nguyên được phân công.

---

# Student

## Purpose

Học tập.

---

## Student Experience

```text
Tenant Website
```

Student không sử dụng:

```text
/student
```

làm main portal entry.

Student login qua single authentication endpoint:

```text
/login
```

Sau login, Student redirect về:

```text
/
```

Student sử dụng Tenant Website ở Personalized Student Mode.

Student không có portal riêng.

Không recreate:

```text
/student
```

làm primary student portal.

Admin sử dụng:

```text
/admin
```

Teacher sử dụng:

```text
/teacher
```

---

## Responsibilities

* học bài
* làm bài thi
* xem kết quả
* sử dụng AI Tutor
* xem lịch sử học tập

---

## Scope

* dữ liệu cá nhân
* khóa học đã đăng ký
* lịch sử học tập
* AI Tutor

---

# User Profile

Mỗi người dùng có hồ sơ riêng.

---

# Basic Profile

```text
name

email

phone

date_of_birth

gender
```

---

# Future Profile

```text
avatar

address

country

language

timezone

bio
```

---

# User Categories

## Internal Users

Người vận hành hệ thống.

Ví dụ:

```text
customer_admin

teacher
```

---

## Learning Users

Người học.

Ví dụ:

```text
student
```

---

# Data Ownership

User không sở hữu tenant.

Tenant sở hữu user.

User Domain sở hữu identity/profile/status. SaaS Tenant Domain sở hữu
User–Customer membership tại `saas_customer_members`.

Current `users.customer_id` và `users.role` vẫn là compatibility contract cho
simple tenant-owned identity. Multi-customer User policy chưa được Foundation
approve và không được suy diễn chỉ từ membership table.

Physical tenant identity contract yêu cầu `users.customer_id NOT NULL` và
`UNIQUE (id, customer_id)`. Composite key này không tạo business identity mới;
`id` vẫn là primary key. Nó cho phép các Domain tenant-owned, bao gồm Learning,
tạo foreign key chứng minh user/actor và bản ghi nghiệp vụ cùng tenant.

## Learning Phase 4A Implementation Record

Phase 4A được triển khai bằng forward migration
`2026_08_13_000000_add_user_tenant_composite_unique.php` với named unique index
`uk_users_id_customer` trên `(id, customer_id)`.

Migration fail closed khi `users.customer_id` nullable, có user thiếu tenant,
có orphan tenant hoặc index cùng tên mang definition khác. Nếu một composite
unique tương đương đã tồn tại, migration không tạo duplicate. Rollback chỉ xóa
`uk_users_id_customer` sau khi xác minh đúng columns và uniqueness; không đổi
primary key `users.id`, user data, Auth flow hoặc tenant resolution.

Preflight chỉ đọc trên database hiện hành và migration/rollback rehearsal trên
database test `lf_schema_drift_*` đã PASS ngày 2026-08-13. Regression Audit đạt
`PASS WITH DOCUMENTED RISKS` vì repository còn formatter debt ngoài phạm vi
Phase 4A. Database test đã bị xóa sau rehearsal. Phase 4A không tạo bảng
`core_learning_*` và không cấp quyền thực hiện Phase 4B.

---

# Relationship Model

```text
Customer

1

↓

N

Users
```

---

# User And Course

```text
Teacher

↓

Course Template Creation

↓

Course Template / Product Management
```

---

```text
Student

↓

Enrollment

↓

Learning
```

---

# User And Assessment

Teacher:

* tạo đề thi
* chấm bài

---

Student:

* làm bài
* xem kết quả

---

# User And Media

Teacher:

* upload nội dung

---

Student:

* xem nội dung

---

# User And Tracking

User-specific Tracking gắn với:

```text
user_id
```

System/anonymous event chỉ được để `user_id NULL` khi event taxonomy và privacy
policy cho phép.

---

# Examples

```text
track_events

track_learning_sessions

track_activity_summaries
```

---

# User And AI

AI luôn phải hiểu:

```text
customer_id

user_id
```

---

# AI Personalization

AI có thể:

* hiểu tiến độ học
* hiểu điểm mạnh
* hiểu điểm yếu
* gợi ý nội dung

dựa trên user cụ thể.

---

# User Activity

Mọi hoạt động của người dùng đều có thể được ghi nhận.

Ví dụ:

```text
login

logout

course_access

lesson_access

quiz_submission

ai_request
```

---

# Future User Analytics

Có thể xây dựng:

```text
engagement_score

completion_score

learning_score

activity_score
```

---

# User Provisioning

Người dùng có thể được tạo bởi:

---

## Customer Admin

Tạo:

* teacher
* student

---

## Self Registration

Tương lai:

student có thể tự đăng ký.

---

## Bulk Import

Tương lai:

* Excel
* CSV

---

# User Security Rules

## Rule 1

Mọi user phải thuộc:

```text
customer_id
```

---

## Rule 2

Không được truy cập dữ liệu tenant khác.

---

## Rule 3

Role xác định quyền.

---

## Rule 4

Status xác định khả năng đăng nhập.

---

## Rule 5

Email Verification được khuyến khích sử dụng.

---

# Last Active Customer Admin Rule

Mỗi tenant luôn phải có:

ít nhất một

```text
customer_admin
```

đang hoạt động.

---

# Teacher Permissions And Tenant Owner — 2026-11-17 (Approved, chưa triển khai)

Nguồn: [ADR-0033](../adr/ADR-0033-Teacher-Permissions-And-Tenant-Owner.md). Ba vai trò giữ
nguyên.

**Giữa các quản trị**

* Mọi quản trị quản lý đầy đủ giáo viên và học viên của trung tâm, không phụ thuộc ai tạo
  tài khoản.
* Mỗi trung tâm có đúng một Chủ trung tâm. Quản trị thường chỉ xem tài khoản quản trị
  khác. Chỉ Chủ trung tâm tạo tài khoản quản trị, nâng một người dùng lên quản trị, sửa hồ
  sơ, khoá, đổi vai trò, đặt lại mật khẩu của quản trị khác, và chuyển vai trò Chủ trung
  tâm.
* Chủ trung tâm không bị khoá và không bị hạ vai trò. Chốt chặn "quản trị cuối cùng còn
  hoạt động" giữ nguyên.
* Chủ trung tâm mất quyền truy cập: đội vận hành LF đổi ngoài ứng dụng, có ghi audit.

**Quyền của giáo viên**

* Bảy quyền cố định (ADR-0033 D3), cấp theo nhóm quyền có tên; mỗi giáo viên thuộc một
  nhóm, để trống là nhóm mặc định. Đổi sang vai trò khác thì nhóm được xoá về trống. Nhóm
  còn giáo viên, kể cả đã khoá, không xoá được.
* Nhóm mặc định giữ đúng những gì giáo viên làm được trước ngày 2026-11-17.
* Quyền quyết định loại việc; phân công vẫn quyết định làm ở Template, lớp, buổi nào.
* Luôn có: xem và dạy những gì được phân công (bắt đầu buổi, mở bảng, hiện mã QR), đánh
  giá năng lực học viên, hồ sơ cá nhân. Media đi theo việc soạn, không là quyền riêng.
  Không bao giờ cấp: Người
  dùng, Tổ chức, Phân quyền, Sản phẩm, Ghi danh, Quy tắc điểm danh.

**Cấu hình trên giao diện:** chỉ tập quyền của nhóm và nhóm của giáo viên. Quy tắc giữa các
quản trị và hai danh sách "luôn có", "không bao giờ cấp" là cố định.

---

# Future RBAC

> Phần quyền của giáo viên đã được quyết ở mục trên (ADR-0033). Mục này còn lại cho các
> hướng chưa quyết.

Hiện tại:

Role-Based Access Control

---

Tương lai:

Permission-Based Access Control

---

Ví dụ:

```text
course.create

course.edit

course.delete

exam.create

exam.grade

report.view
```

---

# Future User Types

Có thể mở rộng:

```text
parent

mentor

reviewer

content_editor

support_agent
```

---

# User Domain Responsibilities

User Domain chịu trách nhiệm:

* User Identity
* User Profile
* User Role
* User Status
* User Lifecycle

---

Không chịu trách nhiệm:

* Course Logic
* Assessment Logic
* Billing Logic
* AI Logic

---

# Relationship With Other Domains

```text
User

↓

Course Product + Enrollment

↓

Course Template

↓

Assessment

↓

Tracking

↓

AI
```

User là điểm bắt đầu của toàn bộ chuỗi dữ liệu học tập.

---

# Final Statement

User Domain là nền tảng định danh của LearnForge.

Mọi hoạt động học tập, giảng dạy, quản trị và AI đều bắt đầu từ User.

Một thiết kế User Domain rõ ràng giúp hệ thống:

* dễ mở rộng
* dễ bảo trì
* dễ phân quyền
* dễ cá nhân hóa

và là nền móng cho mọi module trong LF-Core và LF-SaaS.

---

End of LF-Core-User
