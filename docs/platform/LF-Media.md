# LF-Media.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF Media Architecture

Media Domain là lớp quản lý toàn bộ tài nguyên số trong LearnForge.

Bao gồm:

* Images
* Documents
* Audio
* Videos
* Recordings
* Transcripts

Media là cầu nối giữa:

Learning Content

↓

Tracking

↓

AI Intelligence

---

# Mission

Quản lý toàn bộ tài nguyên học tập.

Cho phép:

* Upload
* Storage
* Processing
* Delivery
* Analytics
* AI Consumption

---

# Media Philosophy

Trong LMS truyền thống:

Media chỉ là file.

Trong LearnForge:

Media là nguồn tri thức.

Một video học tập không chỉ là video.

Nó còn có thể tạo ra:

* Transcript
* Knowledge Base
* AI Tutor Context
* Learning Analytics

---

# Media Hierarchy

```text id="media001"
File

↓

Media Asset

↓

Processing

↓

Knowledge

↓

AI Intelligence
```

---

# Media Domains

Media Layer gồm:

```text id="media002"
Files

Videos

Audios

Documents

Images

Transcripts
```

---

# Database Namespace

```text id="media003"
media_*
```

---

# Core Tables

```text id="media004"
media_files

media_videos

media_audios

media_documents

media_transcripts
```

---

# Ownership Rules

Mọi Media phải thuộc:

```text id="media005"
customer_id
```

---

Có thể liên kết với:

```text id="media006"
template_id

template_lesson_id

template_activity_id

assessment_id

liveclass_id

user_id
```

---

# Media Files

## Purpose

Bảng trung tâm quản lý metadata file.

---

# Database

```text id="media007"
media_files
```

---

# Responsibilities

Quản lý:

* file name
* storage path
* mime type
* size
* owner
* status

---

# Supported Types

```text id="media008"
image

document

audio

video

archive
```

---

# File Lifecycle

```text id="media009"
Upload

↓

Processing

↓

Ready

↓

Archived

↓

Deleted
```

---

# Media Videos

## Purpose

Quản lý video học tập.

---

# Database

```text id="media010"
media_videos
```

---

# Examples

```text id="media011"
Lesson Video

Course Trailer

Replay Video

Assessment Video
```

---

# Video Metadata

```text id="media012"
duration

resolution

thumbnail

provider

storage_path
```

---

# Future Metadata

```text id="media013"
language

subtitle_count

transcript_status

ai_status
```

---

# Media Audios

## Purpose

Quản lý audio.

---

# Database

```text id="media014"
media_audios
```

---

# Examples

```text id="media015"
Listening Audio

Pronunciation Audio

Speaking Recording

Podcast Lesson
```

---

# Audio Metadata

```text id="media016"
duration

bitrate

language
```

---

# Media Documents

## Purpose

Quản lý tài liệu học tập.

---

# Database

```text id="media017"
media_documents
```

---

# Examples

```text id="media018"
PDF

Word

PowerPoint

Excel

Text
```

---

# Supported Formats

```text id="media019"
pdf

docx

pptx

xlsx

txt

md
```

---

# Media Images

Hiện tại quản lý thông qua:

```text id="media020"
media_files
```

---

# Examples

```text id="media021"
Course Banner

Lesson Image

Question Image

Teacher Avatar
```

---

# Transcript Architecture

Transcript là một trong những thành phần quan trọng nhất của LearnForge.

---

# Purpose

Chuyển:

```text id="media022"
Audio

Video
```

thành:

```text id="media023"
Searchable Text
```

---

# Database

```text id="media024"
media_transcripts
```

---

# Transcript Sources

```text id="media025"
Lesson Video

Replay Video

Audio Lesson

Speaking Submission
```

---

# Transcript Pipeline

```text id="media026"
Video

↓

Audio Extraction

↓

Speech To Text

↓

Transcript

↓

AI Processing
```

---

# Example

Teacher nói:

```text id="media027"
Thì hiện tại trong tiếng Hàn...
```

↓

Transcript

↓

Database

↓

Knowledge Base

---

# Storage Architecture

Media sử dụng Storage Layer riêng.

---

# Default Strategy

```text id="media028"
AWS S3
```

---

# Future Options

```text id="media029"
Dedicated S3

Cloud Storage

MinIO

Custom Storage
```

---

# BYOC Compatibility

Media Domain phải hỗ trợ:

```text id="media030"
Shared Storage

Dedicated Storage
```

---

# Upload Pipeline

```text id="media031"
Upload

↓

Validation

↓

Virus Scan

↓

Storage

↓

Metadata

↓

Processing
```

---

# Processing Pipeline

Media có thể được xử lý sau khi upload.

---

# Video Processing

```text id="media032"
Thumbnail

Transcoding

Subtitle

Transcript
```

---

# Audio Processing

```text id="media033"
Speech To Text

Noise Reduction

Transcript
```

---

# Document Processing

```text id="media034"
OCR

Text Extraction

Metadata Extraction
```

---

# Image Processing

```text id="media035"
OCR

Compression

Thumbnail
```

---

# OCR Architecture

Future Phase

---

# Purpose

Đọc nội dung từ:

```text id="media036"
Image

PDF
```

---

# Output

```text id="media037"
Extracted Text
```

---

# Media Delivery

## Purpose

Phân phối nội dung đến người học.

---

# Examples

```text id="media038"
Video Streaming

Audio Streaming

PDF Viewing

Image Display
```

---

# CDN Support

Future Phase

---

```text id="media039"
CloudFront

Cloudflare

Custom CDN
```

---

# Media Security

## Rule 1

Mọi media thuộc:

```text id="media040"
customer_id
```

---

## Rule 2

Không cho tenant khác truy cập.

---

## Rule 3

Media URL không được public mặc định.

---

## Rule 4

Signed URL được ưu tiên.

---

# Media And Course Template

Relationship:

```text id="media041"
Course Template

↓

Template Lessons / Template Activities

↓

Media
```

---

# Media And Assessment

Ví dụ:

```text id="media042"
Listening Audio

Question Image

Speaking Prompt
```

---

# Media And LiveClass

Ví dụ:

```text id="media043"
Replay Recording

Session Recording
```

---

# Media And Tracking

Media tạo ra dữ liệu hành vi.

---

# Examples

```text id="media044"
Video Watch

Audio Listen

Document View
```

---

# Media And AI

Đây là mối quan hệ quan trọng nhất.

---

# AI Sources

```text id="media045"
Video

Audio

Document

Image
```

---

# AI Pipeline

```text id="media046"
Media

↓

Extract

↓

Transcript

↓

Chunk

↓

Embedding

↓

Knowledge Base

↓

AI Tutor
```

---

# Knowledge Creation

Media là nguồn sinh ra:

```text id="media047"
ai_knowledge_sources

ai_knowledge_chunks
```

---

# Future AI Features

```text id="media048"
Auto Summary

Auto Translation

Auto Quiz Generation

Auto Notes

Auto Flashcards
```

---

# Usage Tracking

Media là nguồn usage lớn nhất trong hệ thống.

---

# Examples

```text id="media049"
Storage Usage

Bandwidth Usage

Video Watch Time

Audio Listen Time
```

---

# Billing Relationship

Media ảnh hưởng trực tiếp đến:

```text id="media050"
Storage Cost

Bandwidth Cost

AI Cost
```

---

# Design Rules

## Rule 1

Media chỉ quản lý tài nguyên.

---

## Rule 2

Media không quản lý tiến độ học.

Track Domain quản lý.

---

## Rule 3

Media không quản lý AI.

AI Domain quản lý.

---

## Rule 4

Media phải hỗ trợ AI Processing từ đầu.

---

## Rule 5

Media phải hỗ trợ BYOC Storage.

---

# Current Scope

V1

```text id="media051"
Files

Videos

Audios

Documents

Transcripts
```

---

# Planned Scope

```text id="media052"
OCR

Subtitle

Translation

CDN

Advanced Processing

Media Analytics
```

---

# Relationship With Other Domains

```text id="media053"
Course Template

↓

Assessment

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

Media Domain không chỉ là nơi lưu file.

Nó là nguồn tri thức số của LearnForge.

Mọi Video, Audio, Document và Recording đều có thể được chuyển hóa thành:

* Transcript
* Knowledge Base
* AI Intelligence
* Learning Insights

để hỗ trợ mục tiêu dài hạn của LearnForge:

AI-Native Learning Intelligence Platform.

---

End of LF-Media
