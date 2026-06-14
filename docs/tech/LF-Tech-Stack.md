# LF-Tech-Stack.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

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

Future:

```text
pgvector

OpenSearch

Pinecone

Qdrant
```

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
