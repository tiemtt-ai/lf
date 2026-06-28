# LearnForge Domain Map

Version: 1.0

Status: Official Governance

Last Updated: 2026-06

---

## Purpose

Đây là bản đồ Domain Architecture toàn hệ thống LearnForge.

Tài liệu không mô tả database, API hoặc implementation. Mọi ownership và
dependency phải tuân theo
[LF-Architecture-Principles.md](LF-Architecture-Principles.md).

---

## 1. Overview

```text
LearnForge

├── Business Domains
│   ├── Course
│   ├── LiveClass
│   ├── Assessment
│   └── Certificate
│
├── Platform Domains
│   └── Media
│
├── Learning Intelligence Domains
│   └── Track
│
├── SaaS Domains
│   ├── Tenant
│   ├── Billing
│   ├── Usage
│   └── Subscription
│
├── Intelligence Domains
│   └── AI
│
└── Infrastructure Domains
    ├── Auth
    ├── User
    ├── Navigation
    ├── Notification
    ├── Logging
    ├── Search
    ├── Cache
    └── Queue
```

---

## 2. Business Domains

### Course

**Responsibility:** Authoring, immutable publication, Product learning context,
Enrollment cycle, Progress và Completion.

**Source Of Truth:** Course Template/Version, Product learning binding,
Enrollment, Course Progress và Course Completion.

**Consumes:** Tenant/User identity, Media references, LiveClass/Assessment
evidence và Track summaries khi completion policy yêu cầu.

**Produces:** Published learning context, Enrollment access, Progress,
Completion và context cho Certificate/Track/AI.

**ADR:** [ADR-0001 — Course Foundation](../adr/ADR-0001-Course-Foundation.md)
— Approved.

### LiveClass

**Responsibility:** Room, Session, Attendance, Recording reference, Replay và
Chat operational data.

**Source Of Truth:** LiveClass operational state và Attendance.

**Consumes:** Course Version Activity/Enrollment context, User identity và Media
assets.

**Produces:** Attendance/Replay evidence, operational events và recording
references.

**ADR:** [ADR-0002 — LiveClass Foundation](../adr/ADR-0002-LiveClass-Foundation.md)
— Approved.

### Assessment

**Responsibility:** Question authoring, Quiz snapshots, Attempt, Answer,
Grading, Rubric và Evaluation Evidence.

**Source Of Truth:** Assessment Result, Score, Pass/Fail, Feedback và Grading
Result.

**Consumes:** Course Version Activity/Enrollment context, Media assets và
optional AI suggestions.

**Produces:** Evaluation Evidence và assessment events for Course,
Certificate, Track và AI consumers.

**ADR:** [ADR-0003 — Assessment Foundation](../adr/ADR-0003-Assessment-Foundation.md)
— Approved.

### Certificate

**Status:** Foundation Planned.

**Responsibility:** Certificate eligibility policy, issuance, immutable issued
record và verification.

**Source Of Truth:** Certificate eligibility decision, issued Certificate và
verification outcome.

**Consumes:** Course Completion, Assessment evidence, Product/Enrollment
context và Certificate rules.

**Produces:** Issued Certificate and verification evidence.

**ADR:** Planned.

---

## 3. Platform Domains

### Media

**Type:** Platform Domain.

**Responsibility:** Digital Asset identity, metadata, storage, processing,
variants, transcripts, captions, usage mapping và access audit.

**Source Of Truth:** Media identity/metadata and processing state.

**Consumes:** Upload request, owner usage context và processing provider output.

**Produces:** Reusable Media references, derived assets, transcripts, captions
và access events.

**ADR:** [ADR-0004 — Media Foundation](../adr/ADR-0004-Media-Foundation.md)
— Approved.

---

## 4. Learning Intelligence Domains

### Track

**Status:** Foundation Approved — Version 1.0.

**Responsibility:** Append-only learning behavior events, learning sessions,
rebuildable behavior summaries, AI-ready feature records, feature snapshots và
observed learning journeys.

**Source Of Truth:** Track Event observation history. Summary, feature và
observed path records là derived read models.

**Consumes:** Events from Course, LiveClass, Assessment, Media and other
approved source Domains.

**Produces:** Behavioral summaries, analytics inputs and AI-ready signals.

**Does Not Own:** Course Progress/Completion, Assessment Result, LiveClass
Attendance, Media Processing State, Certificate Eligibility hoặc AI
Recommendation.

**ADR:** [ADR-0005 — Track Foundation](../adr/ADR-0005-Track-Foundation.md)
— Accepted.

---

## 5. Intelligence Domains

### AI

**Status:** Foundation Approved and Frozen — Version 1.0.

**Type:** Learning Intelligence & Decision Support Domain; Consumer Domain.

**Responsibility:** Authorized knowledge consumption, assistant experiences,
recommendations, insights, model-run audit, feedback and prompt governance.

**Source Of Truth:** AI-generated Recommendation, Suggestion, Prediction and
Insight records only; AI is not source of truth for consumer business state.

**Consumes:**

* Track behavior
* Assessment evidence
* Course context
* Media/transcripts
* LiveClass authorized evidence/transcripts

**Produces:**

* Recommendation
* Prediction
* Assistant response
* Suggestion
* Insight

**Does Not Own:** Progress, Completion, Attendance, Assessment Result, Media
Processing State, Certificate, Payment hoặc Track behavior state.

**ADR:** [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md)
— Accepted.

---

## 6. SaaS Domains

### Tenant

**Status:** Foundation documented; ADR pending.

**Responsibility:** Customer identity, tenant context, isolation and tenant
configuration.

**Source Of Truth:** Tenant/Customer ownership and tenant configuration.

### Billing

**Status:** Foundation documented; ADR pending.

**Responsibility:** Pricing, invoice calculation, charge and billing outcome.

**Source Of Truth:** Billing calculation and invoice state.

**Consumes:** Subscription/plan and Usage.

### Usage

**Status:** Foundation documented; ADR pending.

**Responsibility:** Tenant resource-consumption measurement.

**Source Of Truth:** Usage measurement/aggregation.

**Consumes:** Storage, bandwidth, AI and platform-consumption signals.

### Subscription

**Status:** Planned.

**Responsibility:** Plan entitlement, subscription lifecycle, quota and renewal
context.

**Source Of Truth:** Subscription state and active entitlements.

---

## 7. Infrastructure Domains

Infrastructure Domains provide system capabilities and do not own learning,
evaluation or commercial business state outside their scope.

| Domain | Responsibility | Status |
|---|---|---|
| Auth | Authentication and identity verification flow | Foundation documented |
| User | User identity, profile and role association | Foundation documented |
| Navigation | Experience navigation contracts | Foundation documented |
| Notification | Email/in-app/reminder delivery | Planned |
| Logging | Technical and security observability | Planned |
| Search | Cross-domain discovery/index capability | Planned |
| Cache | Ephemeral performance state | Infrastructure capability |
| Queue | Asynchronous job delivery | Infrastructure capability |

---

## 8. Domain Dependency

Arrows describe context/evidence consumption, not ownership transfer.

```text
Tenant
  │
  ├──────────────→ Course
  │                  │
  │                  ├── Version/Enrollment Context ──→ LiveClass
  │                  └── Version/Enrollment Context ──→ Assessment
  │
  └──────────────→ SaaS Domains

Media (Shared Platform)
  ├──────────────→ Course
  ├──────────────→ LiveClass
  ├──────────────→ Assessment
  └──────────────→ AI

Course Events ───────┐
LiveClass Events ────┼──→ Track ──→ AI
Assessment Events ───┤
Media Events ────────┘

LiveClass Evidence ──┐
Assessment Evidence ─┼──→ Course Completion Decision
Track Summary ───────┘

Course Completion ──┐
Assessment Evidence ├──→ Certificate Decision
Certificate Rules ──┘

Usage + Subscription ──→ Billing
```

No arrow permits a source Domain to update the target Domain's business state
directly.

---

## 9. Source Of Truth Matrix

| Business State | Source Of Truth Domain |
|---|---|
| Tenant ownership/context | Tenant |
| User identity/profile | User |
| Course authoring/version | Course |
| Enrollment/access | Course |
| Course Progress/Completion | Course |
| LiveClass Room/Session | LiveClass |
| Attendance/Replay operational state | LiveClass |
| Assessment Result/Score/Grading | Assessment |
| Media identity/metadata/processing | Media |
| Track Event | Track |
| AI Recommendation/Prediction | AI |
| Certificate eligibility/issuance | Certificate |
| Usage measurement | Usage |
| Subscription entitlement | Subscription |
| Invoice/Billing state | Billing |

Read models, evidence and caches do not replace these sources of truth.

---

## 10. ADR Matrix

| Domain | ADR | Status |
|---|---|---|
| Course | ADR-0001 | Approved |
| LiveClass | ADR-0002 | Approved |
| Assessment | ADR-0003 | Approved |
| Media | ADR-0004 | Approved |
| Certificate | Planned | Foundation Planned |
| Track | ADR-0005 | Approved |
| AI | ADR-0006 | Approved |
| Tenant | Planned | Foundation documented |
| Usage | Planned | Foundation documented |
| Billing | Planned | Foundation documented |
| Subscription | Planned | Planned |

---

## 11. Roadmap

### Foundation Approved

* Course
* LiveClass
* Assessment
* Media
* Track
* AI
* Architecture Principles

### Foundation Documented, ADR Pending

* Tenant
* Usage
* Billing

### Planned

* Certificate
* Subscription
* Notification
* Logging
* Search

### Future

* Adaptive Learning
* Competency/Skill Graph
* Gamification
* Peer Learning
* Advanced Learning Paths

---

End of LF-Domain-Map
