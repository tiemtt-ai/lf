# LF-AI.md

Version: 1.0

Status: Strategic Architecture

Last Updated: 2026-06

---

# LF AI Architecture

AI Domain là tầng Intelligence Layer của LearnForge.

Nếu:

```text
core_
```

quản lý việc học

và

```text
track_
```

quản lý hành vi học tập

thì

```text
ai_
```

chịu trách nhiệm:

Hiểu

↓

Phân tích

↓

Suy luận

↓

Cá nhân hóa

↓

Tối ưu việc học

---

# Mission

Biến dữ liệu học tập thành trí tuệ học tập.

AI không tồn tại để trả lời câu hỏi.

AI tồn tại để giúp người học học tốt hơn.

---

# LearnForge AI Philosophy

LearnForge không xây dựng:

```text
ChatGPT inside LMS
```

---

LearnForge xây dựng:

```text
Learning Intelligence Platform
```

---

# AI Core Objectives

AI phải giúp:

## Student

* học tốt hơn
* học nhanh hơn
* học đúng trọng tâm hơn

---

## Teacher

* tạo nội dung nhanh hơn
* đánh giá chính xác hơn
* hiểu học viên rõ hơn

---

## Customer

* hiểu hiệu quả đào tạo
* tối ưu vận hành
* tăng chất lượng đào tạo

---

# AI Hierarchy

```text
Learning Data

↓

Knowledge

↓

Intelligence

↓

Insight

↓

Personalization

↓

Better Learning Outcomes
```

---

# AI Architecture Layers

LearnForge AI gồm 5 tầng.

```text
Knowledge Layer

↓

Conversation Layer

↓

Analytics Layer

↓

Automation Layer

↓

Personalization Layer
```

---

# Layer 1

Knowledge Layer

---

# Purpose

Biến nội dung học tập thành dữ liệu AI có thể sử dụng.

---

# Sources

```text
Course Template

Template Lesson

Template Activity

Document

Video

Audio

Transcript

Assessment
```

---

# Database Namespace

```text
ai_knowledge_*
```

---

# Core Tables

```text
ai_knowledge_sources

ai_knowledge_chunks

ai_embeddings
```

---

# Knowledge Pipeline

```text
Content

↓

Extract

↓

Chunk

↓

Embedding

↓

Knowledge Base
```

---

# Example

```text
PDF

↓

Text

↓

Chunks

↓

Vector Embeddings

↓

AI Search
```

---

# Layer 2

Conversation Layer

---

# Purpose

Cho phép AI tương tác với người dùng.

---

# Core Tables

```text
ai_conversations

ai_messages
```

---

# Supported Roles

```text
Student

Teacher

Customer Admin
```

---

# Conversation Types

```text
AI Tutor

AI Teacher Assistant

AI Course Assistant

AI Support Assistant
```

---

# Context-Aware AI

AI luôn phải hiểu:

```text
customer_id

user_id

product_id

template_id

template_lesson_id

template_activity_id
```

---

# Example

Student hỏi:

```text
Thì hiện tại tiếng Hàn dùng như thế nào?
```

AI phải ưu tiên:

* khóa học đang học
* bài học hiện tại
* transcript liên quan

thay vì trả lời chung chung.

---

# Layer 3

Analytics Layer

---

# Purpose

Phân tích dữ liệu học tập.

---

# Inputs

```text
Track Data

Assessment Data

Attendance Data

Replay Data
```

---

# Outputs

```text
Learning Insights

Teacher Insights

Risk Detection

Recommendations
```

---

# Core Tables

```text
ai_learning_insights

ai_teacher_analytics

ai_risk_predictions
```

---

# Student Insights

Ví dụ:

```text
Điểm mạnh:
Listening

Điểm yếu:
Writing
```

---

# Teacher Insights

Ví dụ:

```text
Attendance Rate

78%

Replay Rate

45%

Risk Students

12
```

---

# Layer 4

Automation Layer

---

# Purpose

Giảm công việc thủ công.

---

# Examples

```text
AI Question Generation

AI Quiz Creation

AI Rubric Suggestion

AI Grading Suggestion

AI Course Summary
```

---

# Assessment Automation

Ví dụ:

```text
PDF

↓

AI Extraction

↓

Question Bank
```

---

# AI Grading

Ví dụ:

```text
Essay

↓

AI Feedback

↓

Teacher Review

↓

Final Grade
```

---

# Principle

AI hỗ trợ.

Giáo viên quyết định cuối cùng.

---

# Layer 5

Personalization Layer

---

# Purpose

Cá nhân hóa trải nghiệm học tập.

---

# Inputs

```text
Track Data

Assessment Data

Course Data

Behavior Data
```

---

# Outputs

```text
Lesson Recommendation

Review Recommendation

Practice Recommendation

Learning Path Recommendation
```

---

# Example

```text
Weak Writing

↓

Recommend Writing Exercises
```

---

# AI Tutor

## Purpose

Gia sư AI cá nhân.

---

# Responsibilities

```text
Answer Questions

Explain Concepts

Generate Examples

Suggest Practice
```

---

# AI Tutor Context

AI Tutor phải hiểu:

```text
Who

What

Where

When
```

---

Cụ thể:

```text
user_id

customer_id

product_id

template_id

template_lesson_id

template_activity_id
```

---

# AI Teacher Assistant

## Purpose

Hỗ trợ giáo viên.

---

# Features

```text
Generate Quiz

Generate Homework

Generate Rubrics

Generate Feedback

Analyze Students
```

---

# AI Admin Assistant

## Purpose

Hỗ trợ quản trị viên.

---

# Features

```text
Usage Analytics

Teacher Analytics

Course Analytics

Risk Analytics
```

---

# AI Knowledge Base

Knowledge Base là nền tảng của toàn bộ AI.

---

# Sources

```text
Videos

Documents

Audios

Transcripts

Lessons

Assessments
```

---

# Workflow

```text
Source

↓

Chunk

↓

Embedding

↓

Search

↓

RAG

↓

Answer
```

---

# RAG Architecture

LearnForge sử dụng:

```text
Retrieval Augmented Generation
```

---

# Workflow

```text
Question

↓

Retrieve

↓

Relevant Knowledge

↓

LLM

↓

Answer
```

---

# AI Provider Layer

LearnForge không phụ thuộc vào một AI Provider.

---

# Supported Providers

```text
OpenAI

Claude

Gemini

Azure OpenAI

OpenRouter
```

---

# Design Principle

Provider Abstraction.

---

# BYOK

Bring Your Own Key

---

# Supported Modes

```text
Shared Key

Dedicated Key
```

---

# AI Usage Tracking

Mọi AI Request phải ghi nhận:

```text
customer_id

user_id

provider

model
```

---

# AI Metrics

```text
input_tokens

output_tokens

total_tokens

request_cost
```

---

# AI Billing Relationship

AI là một nguồn chi phí SaaS.

---

# Examples

```text
Token Usage

AI Requests

Embedding Cost
```

---

# AI Safety Principles

## Rule 1

Tenant Isolation bắt buộc.

---

## Rule 2

Không truy cập dữ liệu tenant khác.

---

## Rule 3

Không huấn luyện chéo dữ liệu khách hàng.

---

## Rule 4

AI phải có khả năng audit.

---

## Rule 5

Mọi AI Action phải có log.

---

# AI And Course

AI hiểu:

```text
Course Product + Enrollment

Course Template

Template Lesson / Template Activity

Learning Context
```

---

# AI And Assessment

AI hỗ trợ:

```text
Question Generation

Quiz Generation

Grading Suggestion

Feedback Suggestion
```

---

# AI And Track

Track là nguồn dữ liệu quan trọng nhất.

---

Không có Track:

```text
AI biết nội dung
```

---

Có Track:

```text
AI hiểu người học
```

---

# LearnForge Intelligence Loop

```text
Learning

↓

Tracking

↓

AI Analysis

↓

Insight

↓

Recommendation

↓

Better Learning

↓

More Learning Data

↓

Tracking
```

---

# Current Scope

Version 1

```text
Knowledge Base

AI Tutor

AI Question Generation

AI Grading Suggestion

AI Analytics
```

---

# Future Scope

```text
Adaptive Learning

AI Mentor

Learning Path Optimization

Predictive Analytics

Competency Mapping

Multi-Agent AI
```

---

# Relationship With Other Domains

```text
Course Product + Enrollment

↓

Course Template

↓

Media

↓

Track

↓

Assessment

↓

AI
```

AI là tầng cao nhất trong kiến trúc LearnForge.

---

# Strategic Positioning

LearnForge không cạnh tranh bằng:

* Video Hosting
* Quiz Engine
* LMS Features

---

LearnForge cạnh tranh bằng:

```text
Learning Intelligence
```

---

# Final Statement

AI Domain là bộ não của LearnForge.

Thông qua việc kết hợp:

* Learning Content
* Learning Behavior
* Learning Outcome

AI giúp LearnForge không chỉ quản lý việc học,

mà còn hiểu việc học,

phân tích việc học,

và tối ưu việc học.

Đây là nền tảng để LearnForge phát triển thành:

AI-Native Learning Intelligence Platform.

---

End of LF-AI
