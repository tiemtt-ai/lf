# LF-Core-Course.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF-Core Course Architecture

Course Domain là trung tâm của toàn bộ hệ thống LMS trong LearnForge.

Mọi hoạt động học tập đều xoay quanh:

* Course
* Lesson
* Enrollment
* Learning Progress

Các module khác như:

* Assessment
* Media
* Tracking
* AI

đều gắn với Course Domain.

---

# Mission

Mục tiêu của Course Domain là:

Tổ chức kiến thức thành các khóa học có cấu trúc rõ ràng.

Cho phép:

* giáo viên tạo nội dung
* học viên học tập
* hệ thống theo dõi tiến độ
* AI hiểu ngữ cảnh học tập

---

# Learning Hierarchy

LearnForge sử dụng cấu trúc phân cấp:

```text
Category

↓

Course

↓

Lesson

↓

Learning Activity
```

---

# Why This Structure

Giúp:

* dễ quản lý
* dễ mở rộng
* dễ tìm kiếm
* dễ thống kê
* dễ gắn AI

---

# Course Domain Components

Course Domain gồm:

```text
Course Category

↓

Course

↓

Lesson

↓

Enrollment

↓

Progress

↓

Completion
```

---

# Course Categories

## Purpose

Phân loại khóa học.

---

## Examples

```text
Korean

English

Japanese

Programming

Business

Soft Skills
```

---

## Database Namespace

```text
core_course_categories
```

---

# Courses

## Purpose

Đơn vị học tập chính.

---

## Course Examples

```text
TOPIK Beginner

TOPIK Intermediate

Business English

Laravel For Beginners
```

---

# Course Responsibilities

Một Course chứa:

* thông tin khóa học
* giáo viên
* bài học
* media
* bài thi
* học viên

---

## Database Namespace

```text
core_courses
```

---

# Course Ownership

Mọi Course phải thuộc:

```text
customer_id
```

---

Và được tạo bởi:

```text
teacher_id
```

hoặc

```text
customer_admin
```

---

# Course Lifecycle

```text
Draft

↓

Published

↓

Learning

↓

Completed

↓

Archived
```

---

# Draft

Đang xây dựng.

Chưa hiển thị với học viên.

---

# Published

Có thể đăng ký.

Có thể học.

---

# Completed

Khóa học kết thúc.

---

# Archived

Lưu trữ lịch sử.

---

# Course Structure

Một Course gồm nhiều Lesson.

```text
Course

1

↓

N

Lesson
```

---

# Lessons

## Purpose

Đơn vị học tập nhỏ nhất trong LMS.

---

## Examples

```text
Lesson 1

Introduction

Lesson 2

Basic Grammar

Lesson 3

Practice
```

---

## Database Namespace

```text
core_lessons
```

---

# Lesson Responsibilities

Một Lesson có thể chứa:

* Video
* Audio
* Document
* Quiz
* Assignment
* AI Context

---

# Lesson Types

LearnForge hỗ trợ:

```text
Video Lesson

Document Lesson

Live Lesson

Quiz Lesson

Assignment Lesson

Hybrid Lesson
```

---

# Learning Flow

Học viên thường đi theo:

```text
Lesson 1

↓

Lesson 2

↓

Lesson 3

↓

Quiz

↓

Completion
```

---

# Enrollment

## Purpose

Liên kết học viên với khóa học.

---

# Relationship

```text
Student

N

↓

Enrollment

↓

Course

N
```

---

# Database Namespace

```text
core_course_enrollments
```

---

# Enrollment Sources

## Admin Enrollment

Admin thêm học viên.

---

## Teacher Enrollment

Giáo viên thêm học viên.

---

## Self Enrollment

Tự đăng ký.

---

## Paid Enrollment

Mua khóa học.

---

# Enrollment Status

```text
pending

active

completed

cancelled
```

---

# Learning Progress

## Purpose

Theo dõi tiến độ học tập.

---

# Progress Sources

* Lesson Completion
* Video Completion
* Quiz Completion
* Assignment Completion

---

# Progress Formula

Ví dụ:

```text
10 Lessons

↓

7 Completed

↓

Progress = 70%
```

---

# Completion

## Purpose

Xác định người học đã hoàn thành khóa học hay chưa.

---

# Completion Conditions

Ví dụ:

```text
100% Lesson Completion

+

Required Quiz Passed
```

---

# Certificate Ready

Sau khi hoàn thành:

```text
Course

↓

Completed

↓

Certificate Eligible
```

---

# Teacher Relationship

Teacher có thể:

* tạo khóa học
* sửa khóa học
* quản lý học viên
* xem báo cáo

---

# Student Relationship

Student có thể:

* đăng ký
* học tập
* làm bài thi
* xem tiến độ

---

# Course And Assessment

Assessment luôn thuộc:

```text
Course

↓

Lesson (optional)
```

---

# Examples

```text
Course

↓

Midterm Exam

↓

Final Exam
```

---

# Course And Media

Media luôn thuộc:

```text
Course

hoặc

Lesson
```

---

# Examples

```text
Video

PDF

Audio

Image
```

---

# Course And Tracking

Tracking được sinh ra từ hoạt động học tập.

---

## Examples

```text
Video Watch

Lesson Progress

Document View

Quiz Activity
```

---

# Course And AI

Course là nguồn ngữ cảnh lớn nhất của AI.

---

# AI Context

AI phải hiểu:

```text
customer_id

course_id

lesson_id

user_id
```

---

# AI Capabilities

Từ Course Domain, AI có thể:

* trả lời nội dung khóa học
* giải thích bài học
* gợi ý nội dung liên quan
* hỗ trợ giáo viên

---

# Learning Path

Future Feature

---

# Purpose

Cho phép kết nối nhiều khóa học.

---

## Example

```text
TOPIK Beginner

↓

TOPIK Intermediate

↓

TOPIK Advanced
```

---

# Prerequisites

Future Feature

---

Cho phép:

```text
Course B

requires

Course A
```

---

# Cohort Learning

Future Feature

---

Cho phép:

```text
Course

↓

Class A

Class B

Class C
```

---

# Live Class Integration

Future Feature

---

```text
Course

↓

Live Class

↓

Replay
```

---

# Gamification

Future Feature

---

Ví dụ:

```text
XP

Badges

Achievements

Streak
```

---

# Design Rules

## Rule 1

Mọi Course phải thuộc:

```text
customer_id
```

---

## Rule 2

Mọi Lesson phải thuộc Course.

---

## Rule 3

Không lưu media trực tiếp trong Course.

Sử dụng Media Domain.

---

## Rule 4

Không lưu analytics trong Course.

Sử dụng Tracking Domain.

---

## Rule 5

Không lưu AI logic trong Course.

Sử dụng AI Domain.

---

# Current Scope

Current Version

```text
Course Categories

Courses

Lessons

Enrollments
```

---

# Planned Scope

```text
Certificates

Learning Paths

Prerequisites

Gamification

Community

Mentoring
```

---

# Relationship With Other Domains

```text
User

↓

Course

↓

Media

↓

Assessment

↓

Tracking

↓

AI
```

Course Domain là trung tâm của toàn bộ chuỗi học tập.

---

# Final Statement

Course Domain là trái tim của LearnForge LMS.

Nó tổ chức kiến thức thành các trải nghiệm học tập có cấu trúc.

Mọi dữ liệu Media, Assessment, Tracking và AI đều xoay quanh Course Domain.

Một Course Architecture tốt là nền tảng để LearnForge phát triển từ LMS truyền thống thành nền tảng Learning Intelligence trong tương lai.

---

End of LF-Core-Course
