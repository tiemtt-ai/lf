# LF-Tech-AWS.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

Document Path: tech/LF-Tech-AWS.md

---

# LearnForge AWS Architecture

## Overview

Tài liệu này mô tả kiến trúc AWS chính thức của LearnForge.

Mục tiêu:

* Chuẩn hóa hạ tầng
* Hỗ trợ Multi-Tenant SaaS
* Hỗ trợ AI Platform
* Hỗ trợ Media Platform
* Hỗ trợ Enterprise Scaling

---

# AWS Philosophy

LearnForge áp dụng nguyên tắc:

```text
Shared Infrastructure First

BYOC Ready

Cost Efficient

Scalable

Cloud Native
```

---

# Infrastructure Vision

AWS là nền tảng vận hành chính của LearnForge.

AWS chịu trách nhiệm:

```text
Compute

Storage

Database

Networking

Email

Security

Monitoring
```

---

# High Level Architecture

```text
Users

↓

CloudFront

↓

Load Balancer

↓

EC2 / Containers

↓

Laravel Application

↓

MySQL

Redis

S3

AI Providers
```

---

# Infrastructure Layers

LearnForge chia AWS thành:

```text
Network Layer

Compute Layer

Storage Layer

Data Layer

Messaging Layer

Monitoring Layer
```

---

# Network Layer

## Purpose

Quản lý kết nối mạng.

---

# Services

```text
VPC

Subnets

Security Groups

Route53

Load Balancer
```

---

# Route53

## Purpose

Quản lý DNS.

---

# Examples

```text
learnforge.vn

kaha.learnforge.vn

visang.learnforge.vn
```

---

# Multi Tenant DNS

Ví dụ:

```text
tenant.learnforge.vn
```

↓

Resolve Tenant

↓

TenantContext

---

# Load Balancer

Future Phase

---

# Purpose

Phân phối lưu lượng.

---

# Benefits

```text
High Availability

Horizontal Scaling

SSL Termination
```

---

# Compute Layer

## Purpose

Chạy ứng dụng LearnForge.

---

# Current Strategy

```text
EC2
```

---

# Services

```text
Laravel

PHP

Nginx

Queue Workers

Reverb
```

---

# Example Architecture

```text
EC2

├── Nginx

├── PHP

├── Laravel

├── Queue Workers

└── Reverb
```

---

# Scaling Strategy

Phase 1

```text
Single EC2
```

---

Phase 2

```text
Multiple EC2

+

Load Balancer
```

---

Phase 3

```text
Containers
```

---

# Container Strategy

Future

---

# Services

```text
ECS

EKS
```

---

# Purpose

Enterprise Scaling

---

# Data Layer

## Primary Database

```text
MySQL
```

---

# Current Service

```text
RDS MySQL
```

---

# Responsibilities

```text
Users

Course Templates + Course Products

Assessments

Tracking

AI

SaaS
```

---

# Database Philosophy

```text
Shared Database

Tenant Isolation
```

---

# Isolation Key

```text
customer_id
```

---

# Backup Strategy

```text
Daily Backup

Point-In-Time Recovery
```

---

# Redis Layer

## Purpose

In-Memory Data Store

---

# Current Service

```text
Redis
```

---

# Responsibilities

```text
Cache

Queue

Sessions

Realtime
```

---

# Future Service

```text
ElastiCache Redis
```

---

# Storage Layer

## Purpose

Quản lý file.

---

# Current Strategy

```text
AWS S3
```

---

# Assets

```text
Videos

Audios

Documents

Images

Recordings
```

---

# Media Pipeline

```text
Upload

↓

S3

↓

Processing

↓

Transcript

↓

AI
```

---

# S3 Structure

Ví dụ:

```text
/customer-1/

    course-templates/

    template-lessons/

    assessments/

    media/
```

---

# Security

Không public bucket mặc định.

---

# Access Method

```text
Signed URL
```

---

# CDN Layer

## Purpose

Phân phối nội dung toàn cầu.

---

# Current

Optional

---

# Future

```text
CloudFront
```

---

# Benefits

```text
Faster Video Delivery

Lower Latency

Reduced S3 Load
```

---

# Email Layer

## Purpose

Email giao dịch.

---

# Current Service

```text
SES
```

---

# Examples

```text
Email Verification

Password Reset

Notifications

Billing
```

---

# Queue Architecture

## Purpose

Xử lý bất đồng bộ.

---

# Flow

```text
Action

↓

Queue

↓

Redis

↓

Worker

↓

Processing
```

---

# Jobs

```text
Transcript Generation

Email Sending

AI Processing

Analytics Aggregation

Video Processing
```

---

# Realtime Architecture

## Current

```text
Laravel Reverb
```

---

# Future

```text
Realtime Notifications

Realtime Chat

Live Class Events

AI Streaming
```

---

# AI Infrastructure

## Purpose

Kết nối LLM Providers.

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

# Architecture

```text
Laravel

↓

AI Abstraction Layer

↓

Provider

↓

Response
```

---

# AI Storage

Lưu:

```text
Knowledge Sources

Embeddings

AI Logs

AI Analytics
```

---

# AI Cost Tracking

Theo dõi:

```text
Input Tokens

Output Tokens

Request Cost
```

---

# Monitoring Layer

## Current

```text
Laravel Logs
```

---

# Future

```text
CloudWatch

Performance Monitoring

Error Tracking

AI Cost Monitoring
```

---

# Metrics

```text
CPU

Memory

Storage

Bandwidth

Queue Size

AI Usage
```

---

# Security Architecture

## Rule 1

Least Privilege Access.

---

## Rule 2

Không hardcode credentials.

---

## Rule 3

Sử dụng Environment Variables.

---

## Rule 4

Mọi bucket phải private mặc định.

---

## Rule 5

Tenant Isolation là bắt buộc.

---

# Disaster Recovery

## Backup

```text
Database Backup

Storage Backup

Configuration Backup
```

---

## Recovery

```text
Database Restore

Media Restore

Infrastructure Restore
```

---

# BYOC Architecture

## Purpose

Bring Your Own Cloud.

---

# Examples

```text
Dedicated AWS Account

Dedicated S3

Dedicated CloudFront

Dedicated Database
```

---

# Ownership Model

```text
Customer

Owns

↓

Cloud Infrastructure
```

---

```text
LearnForge

Owns

↓

Platform

Workflow

AI Intelligence
```

---

# Shared SaaS Architecture

Default Mode

---

```text
Shared AWS

Shared Database

Shared Redis

Shared Storage
```

---

# Enterprise Architecture

Future Mode

---

```text
Dedicated AWS

Dedicated Database

Dedicated Storage

Dedicated AI Keys
```

---

# Infrastructure Evolution

## Phase 1

Startup

```text
Single EC2

Single Database

Shared S3
```

---

## Phase 2

Growing SaaS

```text
Multi EC2

Load Balancer

Redis

CloudFront
```

---

## Phase 3

Enterprise SaaS

```text
Containers

Dedicated Infrastructure

BYOC
```

---

## Phase 4

Global Platform

```text
Multi Region

Global CDN

Enterprise AI
```

---

# Design Rules

## Rule 1

AWS phải Multi-Tenant Ready.

---

## Rule 2

AWS phải BYOC Ready.

---

## Rule 3

Storage phải hỗ trợ AI Pipeline.

---

## Rule 4

Mọi hạ tầng phải hỗ trợ Scaling.

---

## Rule 5

Chi phí phải được theo dõi theo tenant.

---

# Current AWS Stack Summary

```text
Route53

EC2

RDS MySQL

Redis

S3

SES

Laravel Reverb
```

---

# Future AWS Stack

```text
CloudFront

ElastiCache

ECS

EKS

OpenSearch

CloudWatch
```

---

# Strategic Direction

LearnForge không xem AWS là nơi deploy code.

AWS là nền tảng vận hành của:

```text
Learning Platform

Media Platform

AI Platform

SaaS Platform
```

---

# Final Statement

AWS Architecture của LearnForge được thiết kế để hỗ trợ:

* Multi-Tenant SaaS
* AI-Native Learning Platform
* Media Processing
* Enterprise Scaling

trong khi vẫn duy trì:

* chi phí hợp lý
* khả năng mở rộng
* khả năng tùy biến cho khách hàng doanh nghiệp

thông qua chiến lược:

```text
Shared First

BYOC Ready

Enterprise Ready
```

---

End of LF-Tech-AWS
