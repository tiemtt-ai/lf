# LF-Navigation.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LearnForge Navigation Architecture

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

Portal

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

/about

/pricing

/contact

/login

/register-customer
```

---

# Public Menu

```text
Home

About

Pricing

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

Portal
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

/student
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
Course List

Create Course

Edit Course

Course Detail
```

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

Student Portal

---

# Entry Point

```text
/student
```

---

# Student Dashboard

```text
/student
```

---

# Main Menu

```text
Dashboard

My Courses

Assessments

Live Classes

AI Tutor

Profile
```

---

# My Courses

```text
/student/courses
```

---

# Features

```text
Current Courses

Completed Courses
```

---

# Course Detail

```text
/student/courses/{id}
```

---

# Features

```text
Lessons

Media

Assignments

Progress
```

---

# Assessments

```text
/student/assessments
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
/student/liveclasses
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
/student/ai
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
/student/profile
```

---

# Features

```text
Personal Info

Learning Statistics

Preferences
```

---

# Course Navigation Flow

```text
Course List

↓

Course Detail

↓

Lesson

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
Course

↓

Lesson

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
Certificates

Learning Paths

Communities

Mentoring

Gamification
```

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

AI phải xuất hiện ở mọi Portal.

---

## Rule 5

Dashboard là điểm bắt đầu của mọi Role.

---

# Current Scope

Version 1

```text
Public Website

Authentication

Admin Portal

Teacher Portal

Student Portal
```

---

# Planned Scope

```text
Super Admin

Certificates

Learning Paths

Communities

Gamification
```

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
