# LF-Core-Assessment.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF-Core Assessment Architecture

Assessment Domain là hệ thống quản lý đánh giá năng lực học tập của LearnForge.

Assessment không chỉ là Quiz.

Assessment bao gồm:

* Question Bank
* Quiz
* Assignment
* Exam
* Attempt
* Answer
* Grading
* Rubric
* Analytics
* AI Assessment

---

# Mission

Đo lường kết quả học tập.

Giúp:

* giáo viên đánh giá học viên
* học viên tự đánh giá
* AI hiểu năng lực học tập
* hệ thống tạo learning insight

---

# Assessment Hierarchy

```text
Question Bank

↓

Question

↓

Quiz

↓

Attempt

↓

Answer

↓

Grading

↓

Learning Outcome
```

---

# Assessment Domains

Assessment Engine gồm:

```text
Question Bank

Topic Management

Question Management

Quiz Management

Attempt Management

Answer Management

Grading Management

Rubric Management

AI Assessment
```

---

# Database Namespace

Assessment sử dụng:

```text
core_assessment_*
```

---

# Core Tables

```text
core_assessment_categories

core_assessment_question_banks

core_assessment_questions

core_assessment_question_contents

core_assessment_question_media

core_assessment_question_options

core_assessment_topics

core_assessment_question_topics

core_assessment_quizzes

core_assessment_quiz_sections

core_assessment_quiz_questions

core_assessment_attempts

core_assessment_answers

core_assessment_answer_files

core_assessment_grading_assignments

core_assessment_gradings

core_assessment_rubrics

core_assessment_rubric_items
```

---

# Assessment Ownership

Mọi dữ liệu Assessment phải thuộc:

```text
customer_id
```

---

Và có thể liên kết với:

```text
template_id

template_lesson_id

template_activity_id

teacher_id
```

---

# Question Bank

## Purpose

Kho lưu trữ câu hỏi.

---

# Responsibilities

Quản lý:

* câu hỏi
* đáp án
* media
* topic
* độ khó

---

# Database

```text
core_assessment_question_banks
```

---

# Relationship

```text
Question Bank

1

↓

N

Questions
```

---

# Question Management

## Purpose

Đơn vị đánh giá nhỏ nhất.

---

# Database

```text
core_assessment_questions
```

---

# Question Components

Một Question có thể gồm:

```text
Title

Content

Media

Options

Correct Answer

Explanation
```

---

# Question Types

LearnForge hỗ trợ:

```text
single_choice

multiple_choice

true_false

short_answer

essay

speaking

listening

file_upload
```

---

# Single Choice

Ví dụ:

```text
1 đáp án đúng
```

---

# Multiple Choice

Ví dụ:

```text
nhiều đáp án đúng
```

---

# Short Answer

Ví dụ:

```text
trả lời ngắn
```

---

# Essay

Ví dụ:

```text
viết đoạn văn
```

---

# Speaking

Ví dụ:

```text
ghi âm trả lời
```

---

# Listening

Ví dụ:

```text
nghe audio

↓

trả lời câu hỏi
```

---

# File Upload

Ví dụ:

```text
upload document

upload image

upload audio
```

---

# Question Content

Database:

```text
core_assessment_question_contents
```

---

# Purpose

Lưu nội dung chính của câu hỏi.

---

# Question Media

Database:

```text
core_assessment_question_media
```

---

# Supported Media

```text
image

audio

video

document
```

---

# Question Options

Database:

```text
core_assessment_question_options
```

---

# Purpose

Lưu các lựa chọn trả lời.

---

# Topics

## Purpose

Phân loại kiến thức.

---

# Database

```text
core_assessment_topics
```

---

# Examples

```text
Grammar

Vocabulary

Listening

Writing

Business English
```

---

# Mapping

Database:

```text
core_assessment_question_topics
```

---

# Relationship

```text
Question

N

↓

N

Topic
```

---

# Quiz Architecture

Quiz là tập hợp câu hỏi.

---

# Database

```text
core_assessment_quizzes
```

---

# Quiz Examples

```text
Practice Quiz

Homework Quiz

Midterm Exam

Final Exam

Placement Test

Mock Test
```

---

# Quiz Structure

```text
Quiz

↓

Sections

↓

Questions
```

---

# Quiz Sections

Database:

```text
core_assessment_quiz_sections
```

---

# Purpose

Chia bài thi thành nhiều phần.

---

# Example

```text
Listening

Reading

Writing
```

---

# Quiz Questions

Database:

```text
core_assessment_quiz_questions
```

---

# Purpose

Liên kết:

```text
Quiz

↓

Questions
```

---

# Attempts

Database:

```text
core_assessment_attempts
```

---

# Purpose

Một lần làm bài.

---

# Relationship

```text
Student

↓

Quiz

↓

Attempt
```

---

# Attempt Status

```text
in_progress

submitted

graded

expired
```

---

# Answers

Database:

```text
core_assessment_answers
```

---

# Purpose

Lưu câu trả lời.

---

# Answer Types

```text
option

text

audio

file
```

---

# Answer Files

Database:

```text
core_assessment_answer_files
```

---

# Purpose

Lưu file bài làm.

---

# Examples

```text
essay document

image

audio recording
```

---

# Grading Architecture

Assessment hỗ trợ:

```text
automatic grading

manual grading

hybrid grading
```

---

# Automatic Grading

Ví dụ:

```text
single_choice

multiple_choice

true_false
```

---

# Manual Grading

Ví dụ:

```text
essay

writing

speaking
```

---

# Hybrid Grading

AI gợi ý.

Giáo viên quyết định.

---

# Grading Assignments

Database:

```text
core_assessment_grading_assignments
```

---

# Purpose

Phân công người chấm.

---

# Gradings

Database:

```text
core_assessment_gradings
```

---

# Purpose

Lưu kết quả chấm.

---

# Rubrics

Database:

```text
core_assessment_rubrics
```

---

# Purpose

Tiêu chí chấm điểm.

---

# Rubric Items

Database:

```text
core_assessment_rubric_items
```

---

# Example

Writing Rubric:

```text
Grammar

Vocabulary

Structure

Content
```

---

# Four Skill Assessment

LearnForge được thiết kế để hỗ trợ:

```text
Listening

Speaking

Reading

Writing
```

---

# Listening

Audio

↓

Question

↓

Answer

---

# Speaking

Question

↓

Student Recording

↓

Teacher Grading

---

# Reading

Passage

↓

Questions

---

# Writing

Prompt

↓

Essay

↓

Grading

````

---

# AI Question Generation

Future + Phase 2

---

# Workflow

```text
PDF

Word

Image

↓

AI Extraction

↓

Topic Detection

↓

Question Generation

↓

Question Bank
````

---

# AI Generated Content

AI có thể tạo:

```text
topics

questions

options

answers

explanations
```

---

# AI Quiz Generation

Workflow:

```text
Question Bank

↓

Difficulty Selection

↓

Quiz Assembly
```

---

# AI Grading

AI hỗ trợ:

```text
essay

writing

speaking
```

---

# AI Responsibilities

```text
score suggestion

feedback suggestion

rubric suggestion
```

---

# Final Decision

Giáo viên luôn là người quyết định cuối cùng.

---

# Assessment Analytics

Assessment là nguồn dữ liệu quan trọng cho:

```text
track_

ai_
```

---

# Examples

```text
pass_rate

average_score

difficulty_index

completion_rate
```

---

# Assessment And AI

Assessment giúp AI hiểu:

```text
strengths

weaknesses

learning gaps

skill levels
```

---

# Assessment And Course Template

```text
Course Template

↓

Template Activity

↓

Assessment

↓

Learning Outcome
```

---

# Design Rules

## Rule 1

Mọi Assessment phải thuộc:

```text
customer_id
```

---

## Rule 2

Question Bank là nguồn dữ liệu gốc.

---

## Rule 3

Quiz chỉ tham chiếu Question.

Không sao chép dữ liệu câu hỏi.

---

## Rule 4

Essay và Speaking luôn hỗ trợ chấm thủ công.

---

## Rule 5

AI chỉ hỗ trợ chấm điểm.

Giáo viên quyết định cuối cùng.

---

## Rule 6

Assessment phải hỗ trợ đầy đủ:

```text
Listening

Speaking

Reading

Writing
```

---

# Future Features

```text
Adaptive Testing

Question Difficulty Engine

Proctoring

AI Invigilation

Competency Framework

Skill Mapping

Certification Engine
```

---

# Final Statement

Assessment Engine là nền tảng đo lường năng lực học tập của LearnForge.

Nó kết nối:

Learning Content

*

Learning Outcome

*

Learning Analytics

*

AI Intelligence

để tạo thành một hệ sinh thái đánh giá hiện đại, linh hoạt và AI-Native.

---

End of LF-Core-Assessment
