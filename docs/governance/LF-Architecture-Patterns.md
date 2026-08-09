# LearnForge Architecture Patterns

Version: 1.0

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

Document Path: governance/LF-Architecture-Patterns.md

---

# Purpose

Tài liệu này định nghĩa các Pattern kiến trúc chuẩn được phép sử dụng trong
LearnForge.

Pattern không phải Principle và không phải Rule. Pattern là giải pháp lặp lại
cho một loại vấn đề đã biết. Việc áp dụng Pattern phải tuân theo
[Architecture Principles](LF-Architecture-Principles.md) và
[Architecture Guardrails](LF-Architecture-Guardrails.md).

Không phải Pattern nào cũng bắt buộc cho mọi Domain. Chọn Pattern theo vấn đề
thực tế; thay đổi kiến trúc lớn hoặc biến thể làm đổi ownership phải có ADR.

---

# Pattern 1 — Template → Version

## Intent

Tách authoring có thể chỉnh sửa khỏi revision đã publish và được sử dụng trong
runtime.

## Structure

```text
Template

↓ Publish

Version
```

## Applies To

* Course Template và Template Version.
* Assessment authoring và published snapshot/version.

Version đã publish không bị thay đổi bởi chỉnh sửa tiếp theo của Template.

---

# Pattern 2 — Snapshot Pattern

## Intent

Bảo toàn dữ liệu lịch sử tại boundary nơi kết quả phải được tái hiện chính
xác.

## Structure

```text
Mutable Source

↓ Decision Boundary

Immutable Snapshot

↓

Historical Runtime
```

Snapshot chứa dữ liệu cần thiết tại thời điểm chốt, không chỉ reference đến
nguồn có thể thay đổi. Dùng cho Course Version, Assessment Question, option,
rubric và grading context khi phù hợp.

---

# Pattern 3 — Evidence Pattern

## Intent

Cho phép một Domain ảnh hưởng Domain khác mà không cập nhật trực tiếp business
state thuộc ownership bên ngoài.

## Structure

```text
Source Domain

↓

Evidence

↓

Target Domain Decision
```

Ví dụ: LiveClass phát sinh Attendance Evidence; Course tự quyết định Progress.
Assessment phát sinh Pass/Fail Evidence; Certificate tự quyết định issuance.

---

# Pattern 4 — Append Only Pattern

## Intent

Giữ lịch sử sự kiện đầy đủ và tránh viết lại sự thật đã quan sát.

## Structure

```text
Event 1

↓ append

Event 2

↓ append

Event N
```

Áp dụng chính cho Track Event. Correction được biểu diễn bằng event mới, không
update hoặc delete event cũ. Summary có thể rebuild từ event stream.

---

# Pattern 5 — Generic Mapping Pattern

## Intent

Cho Platform Domain liên kết một asset với nhiều consumer mà không thêm foreign
key riêng cho từng Domain.

## Structure

```text
Media File

↓

Usage Mapping

↓

Consumer Type + Consumer ID + Usage Role
```

Áp dụng cho Media Usage. Mapping chỉ mô tả usage; nó không chuyển ownership,
không bỏ tenant boundary và không thay thế validation của consumer.

---

# Pattern 6 — Read Model Pattern

## Intent

Tối ưu truy vấn, reporting hoặc dashboard mà không làm biến dạng write model
canonical.

## Structure

```text
Source Records / Events

↓ Project

Summary Read Model

↓

Query
```

Read Model là derived data, có thể rebuild và không được dùng làm Source of
Truth khi source canonical vẫn tồn tại.

---

# Pattern 7 — Platform Domain Pattern

## Intent

Đóng gói năng lực dùng chung để nhiều Business Domain tái sử dụng mà không làm
Platform Domain sở hữu business decision của consumer.

## Structure

```text
Business Domains

↓ References / Requests

Platform Domain

↓ Shared Capability

Business Domains
```

Media là ví dụ approved: sở hữu Digital Asset, processing, storage metadata và
delivery; không sở hữu Course Progress, Attendance hoặc Assessment Result.

---

# Pattern 8 — Operational Domain Pattern

## Intent

Tách dữ liệu vận hành của một trải nghiệm khỏi learning structure và learning
decision.

## Structure

```text
Learning Structure

↓

Operational Domain

↓ Evidence

Learning Decision
```

LiveClass là ví dụ approved: sở hữu Room, Session, Attendance, Replay,
Recording reference và Chat; Course vẫn sở hữu Progress và Completion.

---

# Pattern 9 — Evaluation Domain Pattern

## Intent

Tách authoring, execution và grading của evaluation khỏi decision của Domain
tiêu thụ kết quả.

## Structure

```text
Assessment Definition

↓

Attempt + Answer + Grading

↓

Evaluation Evidence

↓

Consumer Decision
```

Assessment sở hữu Score và evaluation result, nhưng không trực tiếp complete
Course hoặc issue Certificate.

---

# Pattern 10 — Versioned Authoring Pattern

## Intent

Cho phép teacher tiếp tục author nội dung mà không thay đổi trải nghiệm của
student đang học.

## Structure

```text
Teacher

↓ edits

Draft

↓ publish

Published Version

↓ learns

Student
```

Runtime và Enrollment phải chỉ đến Published Version phù hợp, không đọc trực
tiếp Draft đang thay đổi.

---

# Pattern 11 — Immutable Publishing Pattern

## Intent

Giữ nội dung Published ổn định, có thể audit và tái hiện.

## Structure

```text
Published Version

↓ Change Requested

New Draft

↓ Publish

New Version
```

Không update nội dung của Published Version tại chỗ. Sửa lỗi hoặc cải tiến
được phát hành bằng Version mới và quan hệ thay thế rõ ràng.

---

# Pattern 12 — Shared Infrastructure Pattern

## Intent

Cung cấp năng lực kỹ thuật hoặc platform dùng chung qua boundary ổn định, thay
vì mỗi Domain tự triển khai một bản riêng.

## Shared Capabilities

* Media
* Notification
* Search
* Cache
* Queue

Consumer giữ business ownership. Shared capability không suy diễn tenant,
authorization hoặc business outcome ngoài request đã được xác thực.

---

# Pattern 13 — AI Consumer Pattern

## Intent

Cho AI tạo insight và hỗ trợ quyết định mà không trở thành Source of Truth của
business state.

## Structure

```text
Approved Domain Data

↓

AI

↓

Recommendation / Prediction / Assistant Output

↓

User or Owner Domain Decision
```

AI chỉ đọc dữ liệu được phép và sinh output. AI không tự enroll user, complete
Course, issue Certificate hoặc ghi đè dữ liệu canonical của Domain khác.

---

# Pattern 14 — Tenant Boundary Pattern

## Intent

Giữ mọi Business Data và operation trong đúng tenant boundary.

## Structure

```text
Request

↓

Resolve Tenant

↓

Tenant Context

↓

Tenant-Scoped Query and Write
```

Business data có `customer_id` hoặc ownership chain tenant-scoped được
Guardrails cho phép. Generic mapping, event, snapshot và read model không được
bỏ qua tenant isolation.

---

# Pattern 15 — ADR Driven Evolution

## Intent

Ghi lại lý do, trade-off và hệ quả trước khi thay đổi kiến trúc lớn.

## Structure

```text
Architecture Change

↓

ADR Proposal

↓ Review

Approved Decision

↓

Documentation and Implementation
```

ADR được yêu cầu khi thay đổi Domain boundary, ownership, Source of Truth,
cross-domain contract hoặc foundation pattern. ADR không phải review report và
không được dùng để hợp thức hóa implementation đã vi phạm Guardrails.

---

# Pattern Selection

Khi thiết kế một capability:

```text
Identify Problem

↓

Confirm Domain Owner

↓

Select the Smallest Applicable Pattern

↓

Validate Principles and Guardrails

↓

Create ADR if Architecture Changes
```

Ưu tiên thiết kế đơn giản nhất đáp ứng đúng ownership, history, tenant boundary
và khả năng audit.
