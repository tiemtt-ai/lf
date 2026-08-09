# LF-Navigation.md

Version: 1.1

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: business/LF-Navigation.md

---

# LearnForge Navigation Architecture

> Bulk Enrollment amendment (2026-07): the Admin Enrollment index exposes one
> primary “Create enrollment” action. Its two-step wizard selects one or many
> Students and one or many Products, then configures and confirms their
> Cartesian Student–Product pairs. Cohort assignment remains a
> separate contextual workflow.

> Course Cohort Schedule amendment (2026-08-05): Admin navigation exposes one
> `Lớp học` / `Cohorts` entry. Cohort detail uses the canonical tab order:
> `Tổng quan` / `Overview`, `Học viên` / `Students`, `Giáo viên` / `Teachers`,
> `Lịch học` / `Schedules`, `Buổi học` / `Sessions`, `Điểm danh` /
> `Attendance`, `Bản ghi/Xem lại` / `Recordings/Replay`. Schedules and Sessions
> have separate LiveClass sources of truth. The Sessions tab may combine
> concrete Sessions with non-persisted planned occurrences, but must label them
> distinctly and may create a Session only after explicit selection and atomic
> confirmation. Immutable Origin, not timestamp coincidence, supplies the
> Schedule relationship. Cohort Attendance and Recordings/Replay aggregate
> records from the Cohort's concrete Sessions only.

## Overview

Tài liệu này mô tả toàn bộ cấu trúc điều hướng (Navigation) của LearnForge.

Mục tiêu:

* Chuẩn hóa UI Navigation
* Chuẩn hóa User Flow
* Hỗ trợ Product Design
* Hỗ trợ AI Development
* Hỗ trợ Documentation

---

# Navigation Philosophy

LearnForge được xây dựng theo nguyên tắc:

```text
Role Based Navigation
```

---

Mỗi vai trò sẽ nhìn thấy:

* menu khác nhau
* dashboard khác nhau
* chức năng khác nhau

---

# Core Roles

```text
customer_admin

teacher

student
```

---

Future:

```text
super_admin
```

---

# Navigation Layers

LearnForge có 4 tầng điều hướng.

```text
Public

↓

Authentication

↓

Role Experience

↓

Feature Modules
```

---

# Layer 1

Public Navigation

---

# Purpose

Cho khách chưa đăng nhập.

---

# Public Pages

```text
/

/courses

/assessments

/services

/teachers

/about

/contact

/login

/register-customer
```

---

# Public Menu

```text
Home

Courses

Assessments

Services

Teachers

About

Contact

Login
```

---

# Public Flow

```text
Visitor

↓

Home

↓

Login

↓

Personalized Tenant Website
```

---

# Layer 2

Authentication

---

# Pages

```text
/login

/forgot-password

/reset-password

/verify-email
```

---

# Authentication Flow

```text
Login

↓

Resolve Tenant

↓

Authenticate

↓

Role Redirect
```

---

# Role Redirect

```text
customer_admin

↓

/admin
```

---

```text
teacher

↓

/teacher
```

---

```text
student

↓

/
```

---

# Layer 3

Customer Admin Portal

---

# Entry Point

```text
/admin
```

---

# Admin Dashboard

```text
/admin
```

---

# Main Menu

```text
Dashboard

Users

Courses

Assessments

Live Classes

Media

Reports

AI

Settings
```

---

# Users Module

```text
/admin/users
```

---

# Sub Pages

```text
User List

Create User

Edit User

User Detail
```

---

# Course Module

```text
/admin/courses
```

---

# Sub Pages

```text
Course Template List

Create Course Template

Edit Course Template

Course Template Detail

Course Product Management
```

Course Template Edit giữ một compact authoring tree: Section là grouping
container, Lesson là primary authoring unit và Activity là row trực tiếp dưới
Lesson. Chỉ Course Template hiển thị Status trong tree. Activity title là text
thuần; actions theo thứ tự View, Edit, Delete. View mở readonly detail hoặc
signed/external target và không bao giờ là Edit. Readonly Activity detail giữ
tenant/Template authorization và cung cấp Back cùng Edit riêng cho user được phép.

---

# Assessment Module

```text
/admin/assessments
```

---

# Sub Pages

```text
Question Banks

Questions

Topics

Quizzes

Attempts

Gradings
```

---

# Live Class Module

```text
/admin/liveclasses
```

---

# Sub Pages

```text
Rooms

Sessions

Attendance

Replay
```

---

# Media Module

```text
/admin/media
```

---

# Sub Pages

```text
Files

Videos

Documents

Audios

Transcripts
```

---

# Reports Module

```text
/admin/reports
```

---

# Examples

```text
Learning Reports

Attendance Reports

Assessment Reports

Usage Reports
```

---

# AI Module

```text
/admin/ai
```

---

# Examples

```text
Knowledge Base

AI Usage

AI Analytics

AI Settings
```

---

# Settings Module

```text
/admin/settings
```

---

# Examples

```text
Tenant Settings

Branding

Users & Roles

Billing

Subscription
```

---

# Layer 4

Teacher Portal

---

# Entry Point

```text
/teacher
```

---

# Teacher Dashboard

```text
/teacher
```

---

# Main Menu

```text
Dashboard

My Courses

Assessments

Live Classes

Students

Reports

AI Assistant
```

---

# My Courses

```text
/teacher/courses
```

---

# Features

```text
Course Management

Lesson Management

Enrollments
```

---

# Assessments

```text
/teacher/assessments
```

---

# Features

```text
Question Bank

Quiz Builder

Grading
```

---

# Live Classes

```text
/teacher/liveclasses
```

---

# Features

```text
Manage Sessions

Attendance

Replay
```

---

# Students

```text
/teacher/students
```

---

# Features

```text
Student List

Progress

Scores

Attendance
```

---

# Reports

```text
/teacher/reports
```

---

# Examples

```text
Course Analytics

Assessment Analytics

Attendance Analytics
```

---

# AI Assistant

```text
/teacher/ai
```

---

# Features

```text
Generate Quiz

Generate Rubric

Generate Feedback

Analyze Students
```

---

# Layer 5

Student Experience

Student Experience là Personalized Tenant Website sau khi login.

Student không chuyển sang một portal riêng.

---

# Entry Point

```text
/
```

---

# Tenant Website After Login

```text
/
```

---

# Main Menu

```text
Home

Courses

Assessments

Services

Teachers

About

Contact

My Courses

Learning History

AI Tutor

Profile
```

---

# My Courses

```text
/my-courses
```

---

# Features

```text
Enrolled Courses

In Progress Courses

Completed Courses

Favorite Courses
```

---

# Course Detail

```text
/courses/{id}
```

---

# Course Not Enrolled

```text
View Detail

Register

Purchase
```

---

# Course Enrolled

```text
Continue Learning

Progress

Assessments

Certificate
```

---

# Assessments

```text
/assessments
```

---

# Features

```text
Take Quiz

View Results

Review Attempts
```

---

# Live Classes

```text
/liveclasses
```

---

# Features

```text
Upcoming Sessions

Join Session

Replay
```

---

# AI Tutor

```text
/ai-tutor
```

---

# Features

```text
Ask Questions

Lesson Support

Practice Support

Recommendations
```

---

# Profile

```text
/profile
```

---

# Features

```text
Personal Info

Learning Statistics

Preferences
```

---

# Student Homepage

Trang chủ sau login vẫn giữ:

```text
Banner

Featured Courses

Featured Services

Teachers

News
```

và hiển thị thêm nội dung cá nhân hóa:

```text
Continue Learning

My Courses

Upcoming Activities

Pending Assessments

AI Recommendations
```

---

# Course Navigation Flow

```text
Course List

↓

Course Product Detail

↓

Template Lesson

↓

Assessment

↓

Completion
```

---

# Assessment Navigation Flow

```text
Quiz List

↓

Quiz Detail

↓

Attempt

↓

Submission

↓

Result
```

---

# Live Class Navigation Flow

```text
Live Class List

↓

Session Detail

↓

Join Session

↓

Replay
```

---

# AI Navigation Flow

```text
Course Product + Enrollment

↓

Template Lesson

↓

AI Tutor

↓

Context Aware Answer
```

---

# Dashboard Widgets

## Admin

```text
Users

Courses

Revenue

Usage

AI Usage
```

---

## Teacher

```text
Students

Attendance

Assessments

Course Progress
```

---

## Student

```text
Learning Progress

Upcoming Classes

Pending Assessments

AI Recommendations
```

---

# Mobile Navigation

Version 1

---

# Strategy

```text
Responsive First
```

---

# Mobile Menu

```text
Dashboard

Courses

Assessments

Live Classes

Profile
```

---

# Tenant Branding

Navigation phải hỗ trợ:

```text
Logo

Theme

Language
```

---

# Future Navigation

## Super Admin

```text
/saas
```

---

# Features

```text
Customers

Subscriptions

Usage

Billing

Infrastructure
```

---

# Future Modules

```text
Certificates (*)

Learning Paths

Communities

Mentoring

Gamification
```

(*) Certificate database/architecture foundation is "Foundation Approved and
Frozen" per ADR-0011 and LF-Domain-Map.md — the schema exists and is ready.
"Future" here refers only to navigation/UI: no route, controller or view for
Certificate exists yet (verified against `routes/` and
`app/Http/Controllers/`).

---

# Design Rules

## Rule 1

Navigation phải Role-Based.

---

## Rule 2

Không hiển thị menu không có quyền.

---

## Rule 3

Navigation phải Tenant-Aware.

---

## Rule 4

AI phải xuất hiện trong mọi Role Experience.

---

## Rule 5

Admin và Teacher bắt đầu tại Dashboard.

Student bắt đầu tại Tenant Website.

---

# Current Scope

Version 1

```text
Public Website

Authentication

Admin Portal

Teacher Portal

Student Experience
```

---

# Planned Scope

```text
Super Admin

Certificates (*)

Learning Paths

Communities

Gamification
```

(*) See note under Future Modules — schema Frozen (ADR-0011), UI/navigation
not yet implemented.

---

# Relationship With Other Documents

```text
LF-Core-User

↓

LF-Core-Course

↓

LF-Core-Assessment

↓

LF-Core-LiveClass

↓

LF-Navigation
```

Navigation là lớp hiển thị của toàn bộ nghiệp vụ LearnForge.

---

# Final Statement

LF-Navigation định nghĩa cách người dùng tương tác với LearnForge.

Nó là bản đồ sản phẩm chính thức giúp:

* Product Team
* Design Team
* Development Team
* AI Agents

hiểu được:

* màn hình nào tồn tại
* chức năng nằm ở đâu
* người dùng di chuyển như thế nào

trong toàn bộ hệ sinh thái LearnForge.

---

End of LF-Navigation
