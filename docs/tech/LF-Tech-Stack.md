# LF-Tech-Stack.md

Version: 1.3

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-09-05

Document Path: tech/LF-Tech-Stack.md

---

# LearnForge Technology Stack

## Overview

Tài liệu này mô tả công nghệ chính thức được sử dụng trong LearnForge.

Mục tiêu:

* Chuẩn hóa công nghệ
* Giảm độ phức tạp
* Tăng khả năng bảo trì
* Hỗ trợ AI Development
* Hỗ trợ SaaS Scaling

---

# Technology Philosophy

LearnForge áp dụng triết lý:

```text
Simple First

Stable First

AI Ready

Cloud Native

SaaS Ready
```

---

# Core Principles

## Principle 1

Không chạy theo công nghệ mới nhất.

Ưu tiên:

* ổn định
* dễ tuyển dụng
* dễ bảo trì

---

## Principle 2

Ưu tiên hệ sinh thái mạnh.

---

## Principle 3

Mọi công nghệ phải phục vụ:

```text
Multi Tenant

AI

Scaling

Developer Productivity
```

---

# Official Stack

## Backend

```text
Laravel
PHP
MySQL
Redis
Queue
Reverb
```

## Database Version Floor

Production and CI must use one of:

```text
MySQL >= 8.0.16

MariaDB >= 10.5
```

The floor is mandatory because LearnForge relies on enforced `CHECK`
constraints, composite foreign keys, JSON and database triggers. Deployment
preflight must query the server version and fail before migration when the
driver/version is outside this contract. A server that parses but does not
enforce `CHECK` is unsupported.

---

## Frontend

```text
Blade

Livewire

AlpineJS

JavaScript
```

---

## Infrastructure

```text
AWS

Linux

Nginx

Docker (Future)
```

---

## AI

```text
OpenAI

Claude

Gemini

OpenRouter
```

## Document layout runtime — A1

```text
Python 3.11
Docling 2.119.0
Poppler + Tesseract 5
```

Docling là runtime **local/offline** cho `structured_extraction`, không thay PHP
backend và không thay OCR canonical. PHP gọi một process JSON hữu hạn thời gian;
environment Python, packages và model artefacts được pin ngoài web process.

Local và AWS phải cùng major/minor Python, exact package lock, model inventory
hash và config hash. Không được deploy AWS bằng cách tự tải model khi worker
khởi động. Model phải được đóng gói/prewarm; network bị tắt trong lúc xử lý.

Docling không phải vision AI provider và không được diễn giải chart/diagram,
theo ADR-0019 v1.4 và ADR-0020.

---

# Backend Stack

## PHP

### Purpose

Ngôn ngữ backend chính.

---

### Why PHP

```text
Mature

Stable

Huge Ecosystem

Easy Hiring

Excellent Laravel Support
```

---

### Current Direction

```text
PHP 8+
```

---

# Laravel

### Purpose

Framework trung tâm của LearnForge.

---

### Why Laravel

```text
Fast Development

Strong Community

Queue

Events

Authentication

Broadcasting

Testing
```

---

### Core Usage

```text
Authentication

Multi Tenant

API

Admin Back Office

Teacher Back Office

Tenant Website

Student Personalized Experience
```

---

# Database Layer

## MySQL

### Purpose

Primary Database

---

### Why MySQL

```text
Stable

Reliable

Widely Supported

Easy Backup

Easy Scaling
```

---

### Current Strategy

Shared Database

Tenant Isolation

Thông qua:

```text
customer_id
```

---

# Caching Layer

## Redis

### Purpose

In-Memory Data Store

---

### Responsibilities

```text
Cache

Queue

Sessions

Rate Limiting

Realtime Support
```

---

# Queue System

## Laravel Queue

### Purpose

Xử lý bất đồng bộ.

---

### Examples

```text
Video Processing

Email Sending

AI Jobs

Transcript Generation

Analytics Jobs
```

---

# Realtime Layer

## Laravel Reverb

### Purpose

Realtime Communication

---

### Future Use Cases

```text
Notifications

Live Class

Realtime Chat

Progress Updates

AI Streaming
```

---

# Frontend Architecture

LearnForge sử dụng:

```text
Blade

+

Livewire

+

AlpineJS
```

---

# Why Not SPA First

Không sử dụng React hoặc Vue làm kiến trúc chính.

---

### Reason

```text
Lower Complexity

Faster Development

SEO Friendly

Smaller Team Friendly
```

---

# Blade

### Purpose

Server Side Rendering

---

### Usage

```text
Public Pages

Tenant Website Pages

Admin Pages

Teacher Pages

Student Personalized Pages
```

---

# Livewire

### Purpose

Reactive UI Without SPA Complexity

---

### Examples

```text
Course Management

Quiz Builder

Reports

Dashboards
```

---

# AlpineJS

### Purpose

Lightweight Frontend Interaction

---

### Examples

```text
Dropdown

Modal

Tabs

Small UI Behaviors
```

---

# CSS Strategy

Current Direction:

```text
Custom CSS

Bootstrap Compatible

Component Based
```

---

# Future Direction

Có thể mở rộng:

```text
Tailwind
```

nếu thực sự cần thiết.

---

# API Architecture

LearnForge hỗ trợ:

```text
Web

REST API

Future GraphQL
```

---

# API Usage

```text
Mobile App

Partner Integration

Third Party Systems
```

---

# Authentication Stack

Current:

```text
Laravel Auth

Email Verification

Password Reset
```

---

Future:

```text
OAuth

SSO

SAML
```

---

# Realtime Architecture

Current:

```text
Laravel Reverb
```

---

Future:

```text
WebRTC

Realtime Collaboration

Live Classroom
```

---

# AI Stack

## Provider Abstraction

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

# AI Capabilities

```text
AI Tutor

Question Generation

AI Grading

Analytics

Knowledge Base
```

---

# AI Embedding Layer

Future:

```text
OpenAI Embeddings

Voyage

Cohere

Other Providers
```

---

# Vector Search

Approved target for AI Foundation:

```text
Qdrant self-hosted >= 1.11
```

MariaDB 11.4 remains the relational Source Of Truth and is not used as a vector
store. Qdrant runs inside the LF-managed data boundary. Shared collections use
an indexed `customer_id` tenant payload (`is_tenant=true`); every operation also
filters tenant explicitly. Managed/cloud Qdrant and other stores remain
unapproved until a separate architecture/privacy decision.

---

# Storage Layer

## Current Strategy

```text
AWS S3
```

---

# Supported Assets

```text
Videos

Audios

Documents

Images

Recordings
```

---

# BYOC Ready

Future Support:

```text
Dedicated S3

MinIO

Custom Storage
```

---

# CDN Layer

Future:

```text
CloudFront

Cloudflare
```

---

# Infrastructure Strategy

## Current

```text
AWS
```

---

# Services

```text
EC2

S3

RDS

CloudFront

SES

Route53
```

---

# Future Services

```text
ECS

EKS

Lambda

OpenSearch
```

---

# Deployment Strategy

## Development

```text
Local

XAMPP

Laravel Serve
```

---

## Staging

```text
AWS Staging Environment
```

---

## Production

```text
AWS Production Environment
```

---

# Monitoring

Future Stack

```text
CloudWatch

Laravel Logs

Error Tracking

Performance Monitoring
```

---

# Security Stack

## Core Security

```text
Tenant Isolation

Authentication

Authorization

Rate Limiting

Signed URLs
```

---

# Development Principles

## Keep It Simple

Không thêm framework mới nếu không thật sự cần.

---

## Laravel First

Ưu tiên giải pháp có sẵn trong Laravel.

---

## AI Ready

Mọi module mới phải hỗ trợ AI Integration.

---

## Multi Tenant First

Mọi module mới phải hỗ trợ:

```text
customer_id
```

---

# Current Stack Summary

```text
Backend
    Laravel
    PHP
    MySQL
    Redis

Frontend
    Blade
    Livewire
    AlpineJS

Infrastructure
    AWS

Storage
    S3

Realtime
    Reverb

AI
    OpenAI
    Claude
    Gemini
```

---

# Strategic Direction

LearnForge không hướng tới:

```text
Technology Showcase
```

---

LearnForge hướng tới:

```text
Stable SaaS Platform

+

AI Native Learning Platform
```

---

# Final Statement

Technology Stack của LearnForge được lựa chọn dựa trên:

* tính ổn định
* khả năng mở rộng
* tốc độ phát triển
* chi phí vận hành hợp lý

Mục tiêu cuối cùng không phải là sử dụng công nghệ mới nhất.

Mục tiêu là xây dựng một nền tảng:

* Multi-Tenant
* AI-Native
* Scalable
* Maintainable

có thể phục vụ hàng nghìn khách hàng trên cùng một hệ thống.

---

End of LF-Tech-Stack
