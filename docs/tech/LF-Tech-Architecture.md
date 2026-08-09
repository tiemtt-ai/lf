# LF-Tech-Architecture.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

Document Path: tech/LF-Tech-Architecture.md

---

# LearnForge Technical Architecture

## Overview

Tài liệu này mô tả kiến trúc kỹ thuật tổng thể của LearnForge.

Mục tiêu:

* Chuẩn hóa kiến trúc
* Làm tài liệu tham chiếu dài hạn
* Hỗ trợ phát triển module mới
* Hỗ trợ AI Development
* Hỗ trợ mở rộng hệ thống

---

# Architecture Philosophy

LearnForge được xây dựng theo nguyên tắc:

```text
Monolith First

Multi-Tenant First

AI Native

Cloud Native

Async First

Simplicity First
```

---

# System Vision

LearnForge không phải chỉ là LMS.

LearnForge hướng tới:

```text
AI-Native Learning Intelligence Platform
```

---

# High-Level Architecture

```text
Browser

↓

Frontend Layer

↓

Application Layer

↓

Domain Layer

↓

Infrastructure Layer

↓

AWS Cloud
```

---

# Full Architecture Overview

```text
Users

↓

Web Browser

↓

Blade + Livewire + AlpineJS

↓

Laravel Application

↓

LF-Core

↓

LF-Media

↓

LF-Track

↓

LF-AI

↓

LF-SaaS

↓

MySQL

Redis

S3

AI Providers
```

---

# Layer Architecture

LearnForge được chia thành 6 tầng.

```text
Presentation Layer

Application Layer

Domain Layer

AI Layer

SaaS Layer

Infrastructure Layer
```

---

# Layer 1

Presentation Layer

---

# Purpose

Giao diện người dùng.

---

# Technologies

```text
Blade

Livewire

AlpineJS

JavaScript

CSS
```

---

# Responsibilities

```text
Admin UI

Teacher UI

Tenant Website

Student Personalized Experience

Public Website
```

---

# User Entry Points

```text
/

/login

/admin

/teacher
```

Student sau login sử dụng:

```text
/

Personalized Tenant Website
```

---

# Layer 2

Application Layer

---

# Purpose

Điều phối request.

---

# Technologies

```text
Laravel Controllers

Middleware

Services

Jobs

Events
```

---

# Responsibilities

```text
Authentication

Authorization

Validation

Routing

Workflow Orchestration
```

---

# Request Flow

```text
Request

↓

Middleware

↓

Controller

↓

Service

↓

Database
```

---

# Layer 3

Domain Layer

---

# Purpose

Chứa nghiệp vụ cốt lõi.

---

# Core Domains

```text
Auth

User

Course

Assessment

LiveClass
```

---

# Domain Namespace

```text
core_*
```

---

# Core Architecture

```text
User

↓

Course Product + Enrollment

↓

Course Template

↓

Template Lesson / Template Activity

↓

Assessment

↓

Learning Outcome
```

---

# Layer 4

Media Layer

---

# Purpose

Quản lý tài nguyên học tập.

---

# Namespace

```text
media_*
```

---

# Components

```text
Files

Videos

Audios

Documents

Transcripts
```

---

# Media Pipeline

```text
Upload

↓

Storage

↓

Processing

↓

Delivery
```

---

# Layer 5

Tracking Layer

---

# Purpose

Theo dõi hành vi học tập.

---

# Namespace

```text
track_*
```

---

# Components

```text
Lesson Progress

Video Tracking

Audio Tracking

Assessment Tracking

LiveClass Tracking
```

---

# Tracking Flow

```text
User Activity

↓

Tracking Event

↓

Analytics

↓

AI
```

---

# Layer 6

AI Layer

---

# Purpose

Biến dữ liệu thành trí tuệ.

---

# Namespace

```text
ai_*
```

---

# Components

```text
Knowledge Base

AI Tutor

AI Analytics

AI Grading

AI Insights
```

---

# AI Flow

```text
Content

↓

Knowledge Base

↓

RAG

↓

LLM

↓

Answer
```

---

# SaaS Layer

## Purpose

Quản lý khách hàng và vận hành SaaS.

---

# Namespace

```text
saas_*
```

---

# Components

```text
Tenant

Usage

Billing

Quota

Subscription
```

---

# SaaS Flow

```text
Tenant

↓

Usage

↓

Quota

↓

Billing
```

---

# Multi-Tenant Architecture

LearnForge sử dụng:

```text
Shared Database

Shared Infrastructure

Tenant Isolation
```

---

# Core Principle

Mọi dữ liệu đều thuộc:

```text
customer_id
```

---

# Tenant Resolution Flow

```text
Request

↓

Subdomain

↓

ResolveTenant

↓

TenantContext

↓

Application
```

---

# Example

```text
kaha.learnforge.vn

↓

customer_id = 1
```

---

# Authentication Architecture

```text
Resolve Tenant

↓

Authenticate

↓

Verify User

↓

Verify Tenant

↓

Verify Role

↓

Role Experience Access
```

Role Experience:

```text
customer_admin

↓

/admin

teacher

↓

/teacher

student

↓

/

Personalized Tenant Website
```

---

# Security Layers

```text
Tenant Isolation

Authentication

Authorization

Email Verification

Password Reset

Signed URLs
```

---

# Data Architecture

## Primary Database

```text
MySQL
```

---

# Data Groups

```text
core_*

media_*

track_*

ai_*

saas_*
```

---

# Database Philosophy

```text
Shared Database

Tenant Scoped Data
```

---

# Relationship Model

```text
Tenant

↓

Users

↓

Course Templates + Course Products

↓

Enrollments + Template Lessons

↓

Assessments

↓

Tracking

↓

AI
```

---

# Event Driven Architecture

LearnForge áp dụng Event-Driven Design ở mức nghiệp vụ.

---

# Examples

```text
Video Uploaded

Quiz Submitted

Lesson Completed

AI Request Created
```

---

# Event Flow

```text
Action

↓

Event

↓

Queue

↓

Processing
```

---

# Async Architecture

Các tác vụ nặng luôn xử lý bất đồng bộ.

---

# Examples

```text
Transcript Generation

OCR

AI Embedding

AI Grading

Email Sending

Analytics Aggregation
```

---

# Queue Architecture

```text
Laravel Queue

↓

Redis

↓

Workers
```

---

# Realtime Architecture

Current:

```text
Laravel Reverb
```

---

# Future

```text
Realtime Notifications

Live Class Chat

AI Streaming

Realtime Dashboards
```

---

# Media Architecture

Storage Strategy:

```text
S3 First
```

---

# Flow

```text
Upload

↓

S3

↓

Processing

↓

Transcript

↓

Knowledge Base
```

---

# AI Architecture

## Knowledge Layer

```text
ai_knowledge_sources

ai_knowledge_chunks

ai_embeddings
```

---

## Conversation Layer

```text
ai_conversations

ai_messages

ai_assistant_sessions
```

---

## Insight Layer

```text
ai_recommendations

ai_insights
```

---

## Operations And Governance

```text
ai_model_runs

ai_feedback

ai_prompt_templates
```

---

# AI Provider Abstraction

Supported:

```text
OpenAI

Claude

Gemini

Azure OpenAI

OpenRouter
```

---

# BYOK Architecture

Supported:

```text
Shared AI Key

Dedicated AI Key
```

---

# BYOC Architecture

Supported:

```text
Shared AWS

Dedicated AWS

Dedicated S3
```

---

# Infrastructure Architecture

## Current

```text
AWS
```

---

# Services

```text
EC2

RDS

S3

SES

CloudFront

Route53
```

---

# Future

```text
ECS

EKS

Lambda

OpenSearch
```

---

# Deployment Architecture

```text
Developer

↓

Git

↓

CI/CD

↓

Staging

↓

Production
```

---

# Environment Strategy

```text
Local

Staging

Production
```

---

# Monitoring Architecture

Current:

```text
Laravel Logs
```

---

Future:

```text
CloudWatch

Performance Monitoring

Error Tracking

AI Cost Monitoring
```

---

# Scaling Strategy

## Phase 1

Monolith

Shared Infrastructure

---

## Phase 2

Horizontal Scaling

---

## Phase 3

Enterprise Infrastructure

---

## Phase 4

Global SaaS Platform

---

# Design Rules

## Rule 1

Tenant First

---

## Rule 2

Laravel First

---

## Rule 3

AI Ready

---

## Rule 4

Async First

---

## Rule 5

No Premature Microservices

---

## Rule 6

Everything Belongs To A Customer

---

# Current Architecture Summary

```text
Frontend
    Blade
    Livewire
    AlpineJS

Backend
    Laravel
    PHP

Database
    MySQL

Cache / Queue
    Redis

Realtime
    Reverb

Storage
    S3

AI
    OpenAI
    Claude
    Gemini

SaaS
    Multi-Tenant
```

---

# Strategic Direction

LearnForge không được thiết kế như một LMS truyền thống.

Kiến trúc của LearnForge được xây dựng để hỗ trợ:

```text
Learning

↓

Tracking

↓

Intelligence

↓

Personalization

↓

Better Learning Outcomes
```

---

# Final Statement

LearnForge Technical Architecture là sự kết hợp giữa:

* Multi-Tenant SaaS
* Learning Platform
* Media Platform
* Analytics Platform
* AI Platform

trên cùng một codebase thống nhất.

Kiến trúc này cho phép LearnForge phát triển từ một LMS hiện tại thành một AI-Native Learning Intelligence Platform trong tương lai mà không cần thay đổi nền tảng cốt lõi.

---

End of LF-Tech-Architecture
