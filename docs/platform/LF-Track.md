# LF-Track.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF Track Architecture

Track Domain là hệ thống ghi nhận hành vi học tập trong LearnForge.

Nếu:

Course Domain quản lý nội dung học

và

Assessment Domain quản lý kết quả học

thì

Track Domain quản lý quá trình học.

---

# Mission

Track Domain tồn tại để trả lời:

"Học viên học như thế nào?"

Thay vì chỉ biết:

```text
Completed = Yes
```

LearnForge muốn biết:

```text
Học viên học trong bao lâu

Học vào thời điểm nào

Xem video tới đâu

Đọc tài liệu bao nhiêu lần

Làm bài thi mất bao lâu

Có nguy cơ bỏ học không
```

---

# Learning Intelligence Philosophy

Course tạo ra:

```text
Learning Content
```

Assessment tạo ra:

```text
Learning Outcome
```

Track tạo ra:

```text
Learning Behavior
```

---

# Why Track Matters

Không có Track:

LearnForge chỉ là LMS.

Có Track:

LearnForge trở thành Learning Intelligence Platform.

---

# Learning Data Flow

```text
Course

↓

Media

↓

Student Activity

↓

Track

↓

Analytics

↓

AI

↓

Insight
```

---

# Track Domains

Track Layer gồm:

```text
Lesson Progress

Video Tracking

Audio Tracking

Document Tracking

Assessment Tracking

LiveClass Tracking

User Activity Tracking
```

---

# Database Namespace

```text
track_*
```

---

# Core Tables

Version 1

```text
track_lesson_progress

track_video_watch_logs

track_audio_listen_logs

track_document_view_logs

track_assessment_activity_logs

track_liveclass_attendance_logs

track_liveclass_replay_logs

track_user_activity_logs
```

---

# Core Principle

Track không quản lý nghiệp vụ.

Track chỉ ghi nhận hành vi.

---

Sai:

```text
Course Status
```

---

Đúng:

```text
User Watched Lesson
```

---

# Ownership Rules

Mọi dữ liệu Track phải thuộc:

```text
customer_id
```

---

Và luôn gắn với:

```text
user_id
```

---

# Tracking Hierarchy

```text
User

↓

Activity

↓

Tracking Event

↓

Analytics

↓

AI Insight
```

---

# Lesson Progress Tracking

## Purpose

Theo dõi tiến độ học bài.

---

# Database

```text
track_lesson_progress
```

---

# Examples

```text
Lesson Started

Lesson Completed

Lesson Reopened
```

---

# Example Record

```text
user_id = 100

lesson_id = 25

progress = 80%
```

---

# Progress Model

```text
Not Started

↓

In Progress

↓

Completed
```

---

# Video Tracking

## Purpose

Theo dõi hành vi xem video.

---

# Database

```text
track_video_watch_logs
```

---

# Examples

```text
Play

Pause

Seek

Resume

Completed
```

---

# Tracked Metrics

```text
Watch Duration

Completion Rate

Replay Count

Average Watch Time
```

---

# Example

```text
Video Length

100 minutes

↓

Watched

78 minutes

↓

Completion

78%
```

---

# Audio Tracking

## Purpose

Theo dõi hành vi nghe audio.

---

# Database

```text
track_audio_listen_logs
```

---

# Examples

```text
Listening Lesson

Pronunciation Practice

Podcast Learning
```

---

# Metrics

```text
Listen Duration

Completion Rate

Replay Count
```

---

# Document Tracking

## Purpose

Theo dõi hành vi đọc tài liệu.

---

# Database

```text
track_document_view_logs
```

---

# Examples

```text
PDF Opened

PDF Closed

Page Viewed

Download
```

---

# Metrics

```text
View Duration

Pages Viewed

Download Count
```

---

# Assessment Tracking

## Purpose

Theo dõi hành vi làm bài.

---

# Database

```text
track_assessment_activity_logs
```

---

# Examples

```text
Quiz Started

Question Viewed

Answer Submitted

Quiz Finished
```

---

# Metrics

```text
Attempt Duration

Question Time

Completion Rate

Pass Rate
```

---

# Live Class Tracking

## Purpose

Theo dõi hoạt động lớp học trực tuyến.

---

# Attendance Tracking

Database:

```text
track_liveclass_attendance_logs
```

---

# Replay Tracking

Database:

```text
track_liveclass_replay_logs
```

---

# Metrics

```text
Attendance Rate

Replay Usage

Participation Rate

Session Duration
```

---

# User Activity Tracking

## Purpose

Theo dõi hoạt động tổng quát.

---

# Database

```text
track_user_activity_logs
```

---

# Examples

```text
Login

Logout

Course Access

Lesson Access

AI Request
```

---

# Event-Based Architecture

Track sử dụng mô hình:

```text
Event Driven Tracking
```

---

# Example

```text
Video Played

↓

Tracking Event

↓

Track Database
```

---

# Benefits

* scalable
* analytics ready
* AI ready

---

# Tracking Granularity

LearnForge hỗ trợ nhiều mức độ chi tiết.

---

# Level 1

Summary Tracking

Ví dụ:

```text
Lesson Completed
```

---

# Level 2

Behavior Tracking

Ví dụ:

```text
Watch Duration
```

---

# Level 3

Event Tracking

Ví dụ:

```text
Play

Pause

Seek
```

---

# Learning Analytics

Track là nguồn dữ liệu cho Analytics.

---

# Examples

```text
Course Completion Rate

Student Engagement

Attendance Rate

Learning Time
```

---

# Student Engagement

Ví dụ:

```text
Weekly Active Learners

Monthly Active Learners

Daily Learning Time
```

---

# Risk Detection

Track giúp phát hiện:

```text
Low Attendance

Low Activity

Incomplete Lessons

Exam Avoidance
```

---

# At-Risk Student Model

Ví dụ:

```text
Low Attendance

+

Low Progress

+

No Login

↓

Risk Score
```

---

# AI Relationship

Track là nguồn dữ liệu quan trọng nhất cho AI.

---

# AI Needs Track

Không có Track:

AI chỉ biết nội dung.

Có Track:

AI hiểu người học.

---

# AI Data Sources

```text
Video Behavior

Learning Time

Quiz Behavior

Attendance

Replay Usage
```

---

# AI Personalization

AI có thể:

```text
Recommend Lessons

Suggest Reviews

Detect Weak Areas

Predict Dropout Risk
```

---

# AI Learning Insights

Ví dụ:

```text
Student A

↓

Excellent Listening

Weak Writing

↓

Recommend Writing Practice
```

---

# Teacher Analytics

Ví dụ:

```text
Course Engagement

Attendance Trend

Replay Dependency

Learning Effectiveness
```

---

# Usage Tracking

Track còn phục vụ SaaS.

---

# Examples

```text
Video Watch Time

Bandwidth Usage

Storage Usage

AI Requests
```

---

# Billing Relationship

Track có thể cung cấp dữ liệu cho:

```text
Usage

Quota

Billing
```

---

# Privacy Principles

## Rule 1

Chỉ track dữ liệu cần thiết.

---

## Rule 2

Tôn trọng tenant isolation.

---

## Rule 3

Không chia sẻ dữ liệu giữa tenants.

---

## Rule 4

Analytics phải aggregate trước khi hiển thị.

---

# Design Rules

## Rule 1

Track không chứa business logic.

---

## Rule 2

Track không thay thế dữ liệu gốc.

---

## Rule 3

Track phải append-only khi có thể.

---

## Rule 4

Track phải AI-ready.

---

## Rule 5

Mọi event phải gắn:

```text
customer_id

user_id
```

---

# Current Scope

Version 1

```text
Lesson Progress

Video Tracking

Audio Tracking

Document Tracking

Assessment Tracking

LiveClass Tracking

User Activity Tracking
```

---

# Future Scope

```text
Learning Journey

Engagement Score

Risk Score

Learning Pattern Detection

AI Prediction

Competency Tracking
```

---

# Relationship With Other Domains

```text
Course

↓

Media

↓

User Activity

↓

Track

↓

Analytics

↓

AI
```

---

# Final Statement

Track Domain là hệ thần kinh của LearnForge.

Nó ghi nhận toàn bộ hành vi học tập thực tế của người học.

Thông qua Track Domain, LearnForge không chỉ biết:

"Học viên đã học gì?"

mà còn biết:

"Học viên học như thế nào?"

Đây là nền tảng để xây dựng:

* Analytics
* Personalization
* AI Tutor
* Learning Insights

và hiện thực hóa tầm nhìn:

AI-Native Learning Intelligence Platform.

---

End of LF-Track
