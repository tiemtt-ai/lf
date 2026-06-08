# LF-DATABASE.md

# LearnForge Database Blueprint

Version: 1.0

---

# Database Domains

## SaaS Layer

Prefix:

saas_*

Examples:

* saas_customers
* saas_plans
* saas_subscriptions
* saas_customer_quotas
* saas_usage_logs
* saas_billing_summaries

Purpose:

Business SaaS management.

---

## Core Layer

Prefix:

core_*

Examples:

* core_course_*
* core_assessment_*
* core_liveclass_*
* core_user_*

Purpose:

Learning platform foundation.

---

## Media Layer

Prefix:

media_*

Examples:

* media_files
* media_videos
* media_documents
* media_audios
* media_transcripts

Purpose:

Learning resources.

---

## Tracking Layer

Prefix:

track_*

Examples:

* track_lesson_progress
* track_video_watch_logs
* track_document_view_logs
* track_audio_listen_logs
* track_user_activity_logs

Purpose:

Capture real learning behavior.

---

## AI Layer

Prefix:

ai_*

Examples:

* ai_knowledge_sources
* ai_knowledge_chunks
* ai_conversations
* ai_messages
* ai_learning_insights
* ai_teacher_analytics

Purpose:

Transform data into intelligence.

---

# Design Principles

Rule 1

customer_id is mandatory.

Rule 2

Every business record belongs to a customer.

Rule 3

Track before AI.

Rule 4

AI depends on:

* core_
* media_
* track_

Rule 5

Billing depends on:

* usage
* media
* track
* AI

---

# Database Design Order

1. saas_

2. core_

3. media_

4. track_

5. ai_

6. billing

---

# Assessment Engine

Official Namespace:

core_assessment_*

Includes:

* Categories
* Question Banks
* Questions
* Options
* Topics
* Quizzes
* Attempts
* Answers
* Grading
* Rubrics

Supported Skills:

* Listening
* Speaking
* Reading
* Writing

---

# AI Context Isolation

All AI records should support:

* customer_id
* teacher_id
* course_id
* lesson_id
* user_id

---

# Future Growth

The database should support:

* Multi Tenant SaaS
* AI Tutor
* AI Analytics
* AI Grading
* Billing
* Quotas
* Enterprise Customers
