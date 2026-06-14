# LF-Core-Overview.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF-Core

LF-Core là nền tảng kỹ thuật cốt lõi của LearnForge.

Đây là lớp chịu trách nhiệm cho toàn bộ hoạt động học tập, đánh giá, nội dung, dữ liệu hành vi và trí tuệ AI của hệ thống.

LF-Core không bao gồm:

* Billing
* Subscription
* Quota
* Customer Management

Các thành phần đó thuộc LF-SaaS.

---

# Mission

LF-Core tồn tại để trả lời câu hỏi:

"Làm thế nào để người học học tốt hơn?"

Toàn bộ các module trong LF-Core đều phục vụ mục tiêu:

Learning

↓

Tracking

↓

Intelligence

↓

Personalization

↓

Better Learning Outcomes

---

# LF-Core Architecture

LF-Core bao gồm 4 lớp chính:

```text
core_
media_
track_
ai_
```

Đây là chuỗi giá trị học tập hoàn chỉnh của LearnForge.

---

# Layer 1

core_

Learning Core

---

# Purpose

Đây là lớp LMS nền tảng.

Nơi định nghĩa:

* Người học
* Khóa học
* Bài học
* Lớp học
* Bài thi

Nếu không có core_ thì LearnForge không thể hoạt động như một LMS.

---

# Responsibilities

Quản lý:

* Course
* Course Commerce
* Lesson
* Live Class
* Enrollment
* Assessment
* Certificate

core_ phục vụ cả Course Commerce trên Tenant Website và Learning Experience sau khi Student đăng ký.

---

# Example Domains

```text
core_user_*
core_course_*
core_liveclass_*
core_assessment_*
core_certificate_*
```

---

# Example Flow

Teacher

↓

Create Course

↓

Create Lesson

↓

Publish Course

↓

Tenant Website

↓

Student Register / Purchase

↓

Student Learning

---

# Layer 2

media_

Media Layer

---

# Purpose

Quản lý toàn bộ tài nguyên học tập.

Bao gồm:

* Video
* Audio
* PDF
* Image
* Document

---

# Responsibilities

Upload

↓

Storage

↓

Processing

↓

Distribution

---

# Example Domains

```text
media_files
media_videos
media_audios
media_documents
media_transcripts
```

---

# Example Flow

Teacher Upload Video

↓

S3

↓

Transcript

↓

AI Processing

↓

Learning Content

---

# Future Capabilities

* OCR
* Auto Transcript
* Translation
* Subtitle Generation
* Content Extraction

---

# Layer 3

track_

Learning Analytics Layer

---

# Purpose

Theo dõi hành vi học tập thực tế.

Track không quản lý nội dung học.

Track ghi nhận việc học diễn ra như thế nào.

---

# Questions Answered By Track

Người học:

* học bao lâu?
* học bài nào?
* học đều không?
* xem video đến đâu?
* bỏ giữa chừng ở đâu?
* mở tài liệu bao nhiêu lần?

---

# Responsibilities

Track:

* Lesson Progress
* Video Watch
* Audio Listen
* Document View
* Quiz Activity
* User Activity

---

# Example Domains

```text
track_lesson_progress

track_video_watch_logs

track_audio_listen_logs

track_document_view_logs

track_user_activity_logs
```

---

# Example Flow

Student

↓

Watch Video

↓

Track Watch Progress

↓

Store Activity

↓

Analytics

---

# Why Track Matters

Nếu không có track_:

LearnForge chỉ là LMS.

Nếu có track_:

LearnForge hiểu được hành vi học tập thực tế.

---

# Layer 4

ai_

AI Intelligence Layer

---

# Purpose

Biến dữ liệu thành trí tuệ.

Nếu:

track_ = ghi nhận

thì:

ai_ = hiểu

---

# Responsibilities

AI chịu trách nhiệm:

* Knowledge Base
* AI Tutor
* AI Analytics
* AI Grading
* AI Insights
* Personalization

---

# Example Domains

```text
ai_knowledge_sources

ai_knowledge_chunks

ai_conversations

ai_messages

ai_learning_insights

ai_teacher_analytics
```

---

# AI Pipeline

Document

Video

Audio

Quiz

↓

Extract

↓

Chunk

↓

Embedding

↓

Knowledge Base

↓

AI Assistant

---

# Intelligence Examples

AI có thể:

* phát hiện học viên yếu phần nào
* phát hiện nguy cơ bỏ học
* gợi ý lộ trình học
* phân tích hiệu quả giáo viên
* hỗ trợ tạo đề thi
* hỗ trợ chấm bài

---

# The LearnForge Intelligence Loop

Đây là chu trình quan trọng nhất của LF-Core.

```text
Learning

↓

Tracking

↓

AI Intelligence

↓

Insight

↓

Personalization

↓

Better Learning Experience

↓

More Learning Data

↓

Tracking

...
```

Đây là lợi thế cạnh tranh cốt lõi của LearnForge.

---

# Relationship Between Layers

```text
core_
```

tạo nội dung học

↓

```text
media_
```

lưu trữ tài nguyên học tập

↓

```text
track_
```

ghi nhận hành vi học tập

↓

```text
ai_
```

biến dữ liệu thành trí tuệ

---

# Core Design Rules

## Rule 1

core_ phải tồn tại trước.

Không có nội dung học thì không có gì để track.

---

## Rule 2

media_ phụ thuộc vào core_.

Media phải gắn với:

* course
* lesson
* assessment

---

## Rule 3

track_ phụ thuộc vào core_ và media_.

Track chỉ ghi nhận hành vi.

Track không được trở thành nguồn dữ liệu nghiệp vụ chính.

---

## Rule 4

ai_ phụ thuộc vào:

* core_
* media_
* track_

AI không được thiết kế trước khi có dữ liệu thực tế.

---

## Rule 5

Mọi lớp đều phải gắn với:

customer_id

để đảm bảo tenant isolation.

---

# LF-Core Modules

Current Modules

```text
Auth

User

Course

Assessment

Live Class
```

---

# Future Modules

```text
Certificate

Learning Path

Community

Gamification

Mentoring

Mobile Learning

AI Tutor

AI Analytics
```

---

# LF-Core and LF-SaaS

LF-Core trả lời:

"Learner học như thế nào?"

LF-SaaS trả lời:

"Nền tảng được vận hành và kinh doanh như thế nào?"

Hai lớp này bổ sung cho nhau nhưng không thay thế nhau.

---

# Final Statement

LF-Core là trái tim kỹ thuật của LearnForge.

Nó không chỉ quản lý việc học.

Nó được thiết kế để:

hiểu việc học,

phân tích việc học,

và cải thiện việc học

thông qua sự kết hợp giữa:

Learning Content

*

Learning Behavior

*

AI Intelligence

---

End of LF-Core-Overview
