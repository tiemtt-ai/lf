# LF-Core-User.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

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

## Portal

```text
/student
```

---

## Responsibilities

* học bài
* làm bài thi
* xem kết quả
* sử dụng AI Tutor

---

## Scope

Dữ liệu cá nhân và các khóa học đã đăng ký.

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

Course Creation

↓

Course Management
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

Tracking luôn gắn với:

```text
user_id
```

---

# Examples

```text
track_lesson_progress

track_video_watch_logs

track_document_view_logs
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

# Future RBAC

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

Course

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
