# LF-Core-LiveClass.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF-Core Live Class Architecture

LiveClass Domain quản lý toàn bộ hoạt động học trực tuyến theo thời gian thực.

Bao gồm:

* Online Classes
* Hybrid Classes
* Attendance
* Recording
* Replay
* Live Analytics

---

# Mission

Cho phép giáo viên và học viên tương tác trực tiếp trong quá trình học tập.

Đồng thời tạo dữ liệu phục vụ:

* Tracking
* Analytics
* AI
* Learning Insights

---

# Live Learning Philosophy

Course giúp người học:

học nội dung.

Assessment giúp người học:

được đánh giá.

LiveClass giúp người học:

tương tác trực tiếp.

---

# Live Class Hierarchy

```text
Course

↓

Live Class

↓

Attendance

↓

Recording

↓

Replay

↓

Analytics
```

---

# Core Objectives

LiveClass phải hỗ trợ:

* dạy trực tiếp
* điểm danh
* ghi hình
* xem lại
* theo dõi tương tác
* AI analytics

---

# Database Namespace

```text
core_liveclass_*
```

---

# Proposed Tables

```text
core_liveclass_rooms

core_liveclass_sessions

core_liveclass_attendances

core_liveclass_recordings

core_liveclass_chat_logs

core_liveclass_replays
```

---

# Live Class Rooms

## Purpose

Định nghĩa phòng học trực tuyến.

---

# Database

```text
core_liveclass_rooms
```

---

# Examples

```text
TOPIK Beginner Room

Business English Room

Laravel Training Room
```

---

# Core Fields

```text
customer_id

course_id

teacher_id

title

description

provider

meeting_id

meeting_url

status
```

---

# Provider Support

LearnForge không phụ thuộc vào một nhà cung cấp duy nhất.

---

# Supported Providers

```text
Zoom

Google Meet

Microsoft Teams

Custom RTMP

Future WebRTC
```

---

# Design Principle

Provider Abstraction.

LiveClass Domain không phụ thuộc trực tiếp vào Zoom hoặc Meet.

---

# Live Sessions

## Purpose

Một lần học thực tế.

---

# Database

```text
core_liveclass_sessions
```

---

# Relationship

```text
Room

1

↓

N

Sessions
```

---

# Examples

```text
Session #1

Session #2

Session #3
```

---

# Session Lifecycle

```text
Scheduled

↓

Started

↓

Ended

↓

Recorded

↓

Replay Available
```

---

# Session Fields

```text
room_id

start_time

end_time

duration_minutes

status

recording_status
```

---

# Session Status

```text
scheduled

live

ended

cancelled
```

---

# Attendance Management

Attendance là một trong những dữ liệu quan trọng nhất.

---

# Database

```text
core_liveclass_attendances
```

---

# Purpose

Theo dõi sự tham gia của học viên.

---

# Attendance States

```text
registered

present

late

absent
```

---

# Attendance Sources

## Automatic

Thông qua Zoom / Meet.

---

## Manual

Giáo viên điểm danh.

---

## Replay Completion

Attendance có thể được bù bằng replay.

---

# Replay Attendance Logic

Ví dụ:

```text
Live Session

↓

Student Absent

↓

Watch Replay

↓

Replay Progress >= Threshold

↓

Attendance Updated
```

---

# Example Threshold

```text
80%
```

---

# Business Rule

Tenant có thể cấu hình ngưỡng riêng.

---

# Recording Architecture

## Purpose

Lưu video ghi hình của buổi học.

---

# Database

```text
core_liveclass_recordings
```

---

# Flow

```text
Live Session

↓

Recording

↓

Media Processing

↓

Replay
```

---

# Recording Sources

```text
Zoom Recording

Meet Recording

Uploaded Recording
```

---

# Replay Architecture

Replay là thành phần cực kỳ quan trọng.

---

# Database

```text
core_liveclass_replays
```

---

# Purpose

Cho phép học viên xem lại buổi học.

---

# Replay Flow

```text
Session

↓

Recording

↓

Replay

↓

Progress Tracking
```

---

# Replay Features

```text
Resume Watching

Playback Speed

Progress Tracking

Attendance Recovery
```

---

# Replay Progress

Ví dụ:

```text
100 Minutes Video

↓

Watch 75 Minutes

↓

Replay Progress = 75%
```

---

# Live Chat

Future Module

---

# Database

```text
core_liveclass_chat_logs
```

---

# Purpose

Lưu lịch sử tương tác.

---

# Examples

```text
Questions

Answers

Teacher Feedback

Student Discussion
```

---

# Live Class And Course

Relationship:

```text
Course

1

↓

N

Live Classes
```

---

# Live Class And Lesson

Future Support

---

Ví dụ:

```text
Lesson

↓

Live Session
```

---

# Live Class And Media

Recording luôn thuộc Media Domain.

---

# Relationship

```text
Live Class

↓

Recording

↓

media_videos
```

---

# Design Rule

Không lưu video trực tiếp trong LiveClass Domain.

Media Domain quản lý file.

---

# Live Class And Tracking

LiveClass sinh ra dữ liệu Tracking.

---

# Examples

```text
Attendance

Replay Progress

Watch Duration

Participation
```

---

# Future Tracking Tables

```text
track_liveclass_attendance_logs

track_liveclass_replay_logs
```

---

# Live Class And AI

LiveClass là nguồn dữ liệu rất giá trị cho AI.

---

# AI Data Sources

```text
Transcript

Attendance

Replay Behavior

Questions

Participation
```

---

# AI Teacher Analytics

AI có thể phân tích:

```text
Attendance Rate

Student Engagement

Replay Rate

Session Completion
```

---

# AI Learning Insights

AI có thể phát hiện:

```text
Students At Risk

Low Attendance

Low Engagement

Replay Dependency
```

---

# Transcript Pipeline

Future Phase

---

```text
Recording

↓

Speech To Text

↓

Transcript

↓

Knowledge Base

↓

AI Tutor
```

---

# Example

Teacher giảng:

```text
Thì hiện tại của tiếng Hàn...
```

↓

Transcript

↓

Chunk

↓

AI Knowledge Base

↓

AI Tutor trả lời theo nội dung bài học

---

# Hybrid Learning

LearnForge hỗ trợ:

```text
Online

Offline

Hybrid
```

---

# Online

Học trực tuyến.

---

# Offline

Học trực tiếp tại lớp.

---

# Hybrid

Kết hợp:

* online
* offline
* replay

---

# Class Schedule

LiveClass hỗ trợ lịch học định kỳ.

---

# Examples

```text
Sat 13:00 - 14:30

Sun 13:00 - 14:30
```

---

# Future Scheduling Features

```text
Recurring Sessions

Calendar Sync

Google Calendar

Outlook Calendar
```

---

# Notifications

Future Phase

---

Ví dụ:

```text
Class Reminder

Session Started

Replay Available
```

---

# Design Rules

## Rule 1

Mọi LiveClass phải thuộc:

```text
customer_id
```

---

## Rule 2

Mọi Session phải thuộc Room.

---

## Rule 3

Recording thuộc Media Domain.

---

## Rule 4

Analytics thuộc Track Domain.

---

## Rule 5

AI Logic thuộc AI Domain.

---

## Rule 6

Attendance là dữ liệu học tập quan trọng.

Không được bỏ qua.

---

# Current Scope

V1

```text
Rooms

Sessions

Attendance

Recording

Replay
```

---

# Planned Scope

```text
Live Chat

Whiteboard

Breakout Rooms

Calendar Sync

Transcript

AI Teacher Analytics

AI Learning Insights
```

---

# Relationship With Other Domains

```text
User

↓

Course

↓

LiveClass

↓

Media

↓

Tracking

↓

AI
```

---

# Final Statement

LiveClass Domain không chỉ là nơi tạo link Zoom.

Nó là trung tâm của trải nghiệm học tập trực tiếp trong LearnForge.

Thông qua:

* Attendance
* Recording
* Replay
* Tracking
* AI

LiveClass trở thành nguồn dữ liệu quan trọng giúp LearnForge tiến tới mô hình Learning Intelligence Platform trong tương lai.

---

End of LF-Core-LiveClass
