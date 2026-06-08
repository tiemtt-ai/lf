# LF-ARCHITECTURE.md

# LearnForge Master Architecture

Version: 1.0

Status: Approved Foundation

---

# Vision

LearnForge (LF) is an AI-Native Multi-Tenant LMS SaaS platform.

Target customers:

* Schools
* Training Centers
* Independent Teachers
* Corporate Training Organizations

LearnForge enables organizations to:

* Manage learning
* Manage assessments
* Manage media
* Use AI-powered learning tools
* Analyze learning behavior
* Scale through SaaS

---

# Three Pillars

## LF-Core

Technical Foundation

Includes:

* Source Code
* Laravel Architecture
* Authentication
* User Management
* Course Management
* Assessment Engine
* Media Engine
* Tracking Engine
* AI Engine

---

## LF-SaaS

Business Foundation

Includes:

* Multi Tenant
* Subscription
* Billing
* Quota
* Usage Tracking
* Customer Management
* Tenant Isolation

---

## LF-OS

System Philosophy

Includes:

* AI Native
* Async First
* Simplicity First
* Multi Tenant First
* Long-Term Scalability

---

# Architecture Layers

LF-Core
├── core_
├── media_
├── track_
├── ai_

LF-SaaS
├── saas_
├── billing
├── quota
├── usage

LF-OS
├── philosophy
├── design principles
├── scaling mindset

---

# Multi-Tenant Architecture

Core Principle:

One codebase.

One platform.

Many customers.

Isolation through:

customer_id

Every business record belongs to a customer.

---

# Tenant Resolution

Subdomain

↓

ResolveTenant

↓

saas_customers

↓

TenantContext

↓

customer_id

---

# User Roles

Official roles:

customer_admin

teacher

student

Future:

super_admin

---

# Portal Structure

Tenant Public Website

Manager Portal

Student Portal

Manager Portal:

/admin

/teacher

Student Portal:

/student

---

# Business Model

LearnForge sells SaaS platform access.

Tenant sells educational services.

Student is the tenant's end customer.

LearnForge
↓
Tenant
↓
Teacher
↓
Student

---

# AI-Native Model

Learning Activity

↓

track_*

↓

AI Understanding

↓

ai_*

↓

Insights

↓

Personalization

---

# Infrastructure Strategy

Default:

Shared Infrastructure

Enterprise:

BYOC

Bring Your Own Cloud

BYOK

Bring Your Own Key

Supported:

* Shared AWS
* Dedicated AWS
* Shared AI Keys
* Customer AI Keys

Principle:

Customer owns infrastructure choices.

LearnForge owns platform intelligence.

---

# Long-Term Direction

Track learning behavior.

Generate AI intelligence.

Personalize learning.

Scale as SaaS.

This is the long-term foundation of LearnForge.
