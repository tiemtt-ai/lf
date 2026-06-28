# LearnForge Data Flow

Version: 1.0

Status: Official Governance

Last Updated: 2026-06

---

## Purpose

Tài liệu mô tả Business Data Flow giữa các LearnForge Domains.

Tài liệu không mô tả database tables, APIs hoặc implementation. Mọi flow phải
tuân theo:

* [LF-Architecture-Principles.md](LF-Architecture-Principles.md)
* [LF-Domain-Map.md](LF-Domain-Map.md)

Arrow thể hiện dữ liệu/context/evidence được chuyển cho consumer. Arrow không
cho phép source Domain cập nhật trực tiếp business state của target Domain.

---

## 0. Tenant Resolution (Foundation Approved and Frozen — Version 1.0)

```text
Request Host

↓

Customer Domain Registry

↓

Customer / Tenant Context

↓

Authentication

↓

Active Customer Membership

↓

Authorized Experience
```

Tenant Domain owns Customer identity, domain mapping, settings, membership,
invitations and tenant audit trail. User/Auth owns identity and authentication.
Tenant context scopes every downstream Domain but does not own their business
state.

---

## 1. Course Authoring

```text
Teacher

↓ author

Course Template

↓ publish

Immutable Template Version

↓ offer

Course Product
```

Teacher chỉnh working content. Publish tạo immutable learning context. Product
chỉ sử dụng published Version; thay đổi authoring không silent-update existing
Enrollment.

---

## 2. Enrollment

```text
Registration / Purchase / Assignment

↓

Enrollment

↓ validate access window

Student Access

↓

Locked Template Version
```

Enrollment là một learning cycle và khóa Version. Product đổi Version không
làm thay đổi flow của Enrollment hiện có.

---

## 3. Learning

```text
Student

↓ access

Version Activity

↓ learning evidence

Activity Progress Decision

↓ aggregate

Course Progress

↓ rules

Completion
```

Course Domain là source of truth cho Progress/Completion. Media access,
LiveClass Attendance hoặc Assessment Result chỉ là evidence/input.

---

## 4. LiveClass

```text
Student

↓ join

LiveClass Session

↓

Attendance

↓ optional

Replay

↓

Attendance / Replay Evidence

↓ consumed by Course

Progress Decision
```

LiveClass owns Session, Attendance and Replay operational state. LiveClass
không tự complete Course Activity.

Recording flow:

```text
LiveClass Session

↓ recording reference

Media

↓ delivery

Replay
```

---

## 5. Assessment

```text
Enrollment

↓ start

Attempt

↓

Answer

↓

Grading

↓

Evaluation Evidence

↓ consumed by Course

Completion Decision
```

Assessment owns Result/Score/Pass-Fail/Grading. Course owns the completion
decision. AI grading may suggest; teacher or approved Assessment policy decides
the final Assessment Result.

---

## 6. Media

```text
Upload

↓

Digital Asset

↓

Processing

↓

Derived Variants

↓

Transcript / Caption

↓

Authorized Delivery
```

Media owns asset identity, metadata and processing state. Consumer Domains use
Media references and retain their own business-state authority.

```text
Course / LiveClass / Assessment / AI

↓ usage context

Media

↓ reference / delivery

Consumer Experience
```

---

## 7. Track (Foundation Approved)

```text
Course Activity

↓ event

Track
```

```text
LiveClass Activity

↓ event

Track
```

```text
Assessment Activity

↓ event

Track
```

```text
Media Activity

↓ event

Track
```

Track stores append-only behavioral events and rebuildable summaries. Track
does not replace Course, LiveClass, Assessment or Media source records.

```text
Track Events

↓ group/project

Learning Sessions + Activity/Daily Summaries

↓ derive

AI-ready Features + Historical Snapshots + Observed Paths

↓ consume

Analytics / Dashboard / AI
```

Correction tạo event mới. Summary, feature và observed path có thể rebuild;
chúng không quyết định Progress, Result, Attendance, Processing State,
Certificate Eligibility hoặc AI Recommendation.

---

## 8. AI (Foundation Approved and Frozen)

```text
Course Context
        +
Track Behavior
        +
Assessment Evidence
        +
Media / Transcript

↓

AI Feature

↓

Recommendation / Prediction / Assistant / Suggestion

↓

Human or Owning Domain Decision
```

AI output is not the source of truth for Completion, Final Grade, Certificate
or Enrollment.

```text
Authorized Knowledge Sources

↓

Chunks + Embedding References

↓

Prompt Template + Model Run

↓

Conversation / Recommendation / Insight / Suggestion

↓

Human or Owner Domain Decision
```

AI owns its output and execution provenance only. AI does not update Progress,
Attendance, Assessment Result, Certificate, Payment or Track state.

---

## 9. Certificate

```text
Course Completion

        +

Assessment Evidence

        +

Certificate Rules

↓

Eligibility Decision

↓

Issued Certificate

↓

Verification
```

Certificate Domain owns eligibility/issuance/verification. Course and
Assessment provide inputs but do not issue Certificate directly.

---

## 10. Reporting

```text
Course State

Assessment Results

LiveClass Operations

Track Events / Summaries

Media Usage

↓

Analytics / Read Models

↓

Dashboard / Reporting / AI Input
```

Reporting read models may denormalize data but do not replace source-of-truth
Domains. Billing and Certificate decisions must not rely on display-only cache.

---

## 11. End-to-End Flow

```text
Teacher

↓

Authoring

↓

Publish

↓

Product

↓

Enrollment

↓

Learning Activities

├── Media Consumption
├── LiveClass Evidence
└── Assessment Evidence

↓ Course decision

Progress / Completion

↓

Certificate Eligibility / Issuance

↓

Track / Analytics

↓

AI Recommendation / Assistant
```

The flow preserves one source of truth for each business state.

---

## 12. Future Flows

### Adaptive Learning

```text
Track + Assessment Evidence

↓

AI Recommendation

↓ owning Domain/User decision

Next Learning Activity
```

### Learning Path

```text
Course Completion / Prerequisite Evidence

↓

Learning Path Decision

↓

Next Product / Activity Access
```

### Recommendation

```text
Context + Behavior + Outcome

↓

AI Recommendation

↓

User / Course Decision
```

### Peer Learning

```text
Learner Participation

↓

Peer Interaction / Review Evidence

↓ owning policy

Learning Experience
```

### Gamification

```text
Verified Learning Events

↓

Gamification Rules

↓

Points / Badge / Challenge State
```

### Skill Graph And Competency

```text
Course + Assessment + Track Evidence

↓

Competency Decision

↓

Skill Profile / Recommendation
```

Future flows require their own Domain ownership, source-of-truth decision and
ADR before implementation.

---

End of LF-Data-Flow
