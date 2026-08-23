# LF-Core-Learning.md

Version: 1.3

Document Status: Frozen

Implementation Status: Partial

Last Updated: 2026-08-23

Approval Date: 2026-08-12

Document Path: core/LF-Core-Learning.md

---

# LF Core Learning Architecture

Learning là Core Business Domain của LearnForge, sở hữu ngữ nghĩa học tập, bằng
chứng học tập và kết luận năng lực.

Quyết định kiến trúc nền tảng thuộc
[ADR-0016 — Learning Foundation](../adr/ADR-0016-Learning-Foundation.md).

Learning trả lời câu hỏi mà Course, Assessment và Certificate không trả lời
được:

```text
Học viên đã thật sự nắm được năng lực nào,
ở mức nào,
dựa trên bằng chứng gì.
```

Ba khái niệm sau là ba thứ khác nhau và không được đồng nhất:

```text
Completion  = đã đi hết nội dung        (Course sở hữu)
Score       = kết quả một lần đo        (Assessment sở hữu)
Mastery     = kết luận về năng lực      (Learning sở hữu)
```

---

# Domain Responsibility

Learning sở hữu:

* Learning Framework và Framework Version.
* Stable Node Definition: Objective, Concept, Competency.
* Versioned Node snapshot và quan hệ giữa các Node.
* Mapping từ immutable Course/Assessment object sang versioned Node.
* Learning Evidence.
* Mastery Calculation và Mastery Profile.
* Evidence qualification rule và version continuity policy.

Learning không sở hữu:

* Course Template, Version, Enrollment, Progress, Completion.
* Assessment Question, Quiz, Attempt, Score, Grading.
* Certificate eligibility và issuance.
* Track Event và Track Summary.
* Media binary.
* AI output.
* Job Role, Position, Career Path.

---

# Source Of Truth

| Khái niệm | Source Of Truth |
| --- | --- |
| Cấu trúc năng lực và ngữ nghĩa | Learning Framework Version |
| Thang đánh giá năng lực | mastery scale snapshot của Framework Version |
| Bằng chứng học tập | Learning Evidence |
| Kết luận năng lực | Mastery Calculation |
| Trạng thái năng lực hiện tại | Mastery Profile — read model, tái tạo được |
| Nội dung học | Course Domain |
| Điểm và kết quả đo | Assessment Domain |
| Đủ điều kiện cấp bằng | Certificate Domain |

---

# Learning Semantics

Danh tính ổn định thuộc Framework; snapshot thuộc Framework Version.

```text
Framework
    ├──→ Framework Version ──→ Versioned Node
    │
    └──→ Stable Node Definition
              ↑
              └──── Versioned Node tham chiếu về Definition
```

```text
Framework          → sở hữu Stable Node Definitions
Framework Version  → sở hữu Versioned Nodes
Versioned Node     → tham chiếu Stable Node Definition
```

Framework Version đã publish là bất biến. Node và quan hệ đã publish không sửa
tại chỗ; muốn đổi ngữ nghĩa thì publish một Framework Version mới.

Mỗi Node Definition thuộc đúng một Framework. Việc đối chiếu năng lực giữa hai
Framework khác nhau không được thực hiện bằng cách chia sẻ Definition.

---

# Node Relations

```text
                        Node Relation
                              │
              ┌───────────────┴───────────────┐
              ↓                               ↓
          semantic                    version_transition
      cùng Framework Version         khác Framework Version
              │                               │
    prerequisite / part_of /       equivalent_to / supersedes /
         supports                  splits_into / merges_into
                                              │
                                              ↓
                                    continuity policy
```

Quan hệ semantic mô tả cấu trúc năng lực trong một phiên bản. Quan hệ
version_transition mô tả ngữ nghĩa đã chuyển đổi thế nào giữa hai phiên bản của
**cùng một Framework**, và mang theo continuity policy quyết định kết luận năng
lực cũ có được mang sang hay không.

Mặc định là không mang sang. `equivalent_to` yêu cầu phê duyệt rõ ràng trước khi
carry-forward.

Version transition không phải là cơ chế đối chiếu giữa hai Framework khác nhau:

```text
Version transition        cùng Framework, khác Framework Version
Cross-framework mapping   khác Framework — hoãn khỏi Foundation v1,
                          cần cơ chế mapping, ownership và policy riêng
```

---

# Learning Lifecycle

```text
Framework authoring

↓ publish

Framework Version + mastery scale snapshot

↓ mapping tới immutable Course/Assessment object

Node Mapping

↓ hoạt động học tập phát sinh

Learning Evidence          append-only

↓ áp dụng rule của Framework Version

Mastery Calculation        append-only

↓ projection

Mastery Profile            read model, tái tạo được
```

Lịch sử được giữ lại. Đính chính Evidence tạo bản ghi mới trỏ về bản bị đính
chính. Giáo viên override và carry-forward đều tạo Calculation mới. Không sửa
lịch sử tại chỗ.

---

# Evidence Boundary

```text
    nguồn phát                        loại bằng chứng
    ──────────────────────────        ──────────────────
    Qualified immutable Course Event exposure
    Qualified immutable Course Event completion
    Assessment Attempt / Grading      evaluation
    Teacher Judgment                  expert_judgment
    Qualified Track Signal            behavioral_signal
```

Domain nguồn phát ra bằng chứng; Domain nguồn không ghi trạng thái Mastery.

Signal không tự trở thành Evidence. Track Summary chỉ trở thành Evidence khi
qualification rule của Framework Version cho phép, và Evidence phải ghi lại
rule snapshot đã áp dụng.

Evidence neo vào Versioned Node để giữ đúng ngữ nghĩa tại thời điểm phát sinh.

---

# Mastery Boundary

Mastery Calculation neo vào Stable Node Definition, nhưng luôn phân tách theo
Framework Version làm cơ sở tính.

```text
Mastery Profile được phân biệt theo:

    tenant + user + stable node definition + basis Framework Version
```

Một học viên có thể đang học nhiều Course Version khác nhau cùng lúc, vì
Enrollment khóa vào đúng một Course Template Version. Các kết luận năng lực
tương ứng không được ghi đè lẫn nhau chỉ vì cùng nói về một năng lực.

Trả lời "mastery hiện tại":

* Consumer phải nêu rõ `basis_framework_version_id`; không suy ra từ `latest`,
  version number hay thời điểm publish.
* Nếu không nêu basis: trả về nhiều trạng thái có nhãn Version hoặc fail closed.
* Trạng thái của các version cũ vẫn đọc được như lịch sử.
* Truy vấn xuyên version là hành động chủ động, không phải gộp tự động.

---

# Cross-Domain Integration

```text
Course        ─ Progress / Completion ────────┐
Assessment    ─ Attempt / Grading (Phase 2) ──┤
Teacher       ─ Judgment ─────────────────────┼──→ Learning Evidence
Track         ─ Qualified Signal (future) ────┘         │
                                                        ↓
                                              Mastery Calculation
                                                        ↓
                                              Mastery Profile
                                                        ↓ chỉ đọc
                                              AI Recommendation
                                                        ↓
                                              Human hoặc Owning Domain duyệt
                                                        ↓
                                              Learning Plan change

Certificate ── nhánh độc lập, không đi qua Mastery:
    Course Completion + Assessment Evidence + Certificate Rules → Eligibility
```

| Domain | Quan hệ với Learning |
| --- | --- |
| [Course](LF-Core-Course.md) | Progress/Completion là qualification input; chỉ append-only Course event đã review mới được làm Evidence source. Mapping chỉ trỏ Version Lesson/Activity đã publish. Course không ghi Mastery. |
| [Assessment](LF-Core-Assessment.md) | Cung cấp kết quả đánh giá làm Evidence loại `evaluation`; mapping chỉ trỏ Quiz Question Snapshot. Phase 2. |
| [Certificate](LF-Core-Certificate.md) | Độc lập hoàn toàn. Mastery không nằm trong luồng cấp Certificate của Foundation v1. |
| [LiveClass](LF-Core-LiveClass.md) | Nguồn Teacher Judgment và hoạt động lớp học; không quyết định Mastery. |
| Track | Behavioral signal; chỉ thành Evidence khi qualification policy cho phép. |
| AI | Chỉ đọc Mastery Profile để tạo Recommendation/Insight; không ghi trạng thái nghiệp vụ nào. |
| Enterprise / HR | Consumer tương lai; sở hữu mapping Job Role/Position/Requirement sang Stable Node Definition. Không tạo quan hệ sở hữu ngược vào Learning. |

---

# Database Namespace

```text
core_learning_*
```

Learning Foundation Version 1.1 gồm 10 bảng. Danh sách và trách nhiệm từng bảng
nằm trong
[ADR-0016 — Learning Foundation](../adr/ADR-0016-Learning-Foundation.md).

Table documentation Giai đoạn 3 nằm tại
[database/learning](../database/learning/README.md). Các table docs đang ở
trạng thái Review và không cấp quyền tạo migration trước Database/Architecture
Review cùng Foundation Freeze.

---

# AI-Assisted Authoring Boundary

[ADR-0017](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md) cho phép AI đề
xuất Node và Mapping từ Media gắn với Course Activity. Nó không nới ranh giới
sở hữu: Learning vẫn là Source Of Truth duy nhất cho Framework, Node Definition,
Version Node và canonical Mapping.

AI chỉ tạo Proposal. Reviewer chấp nhận Proposal là một trạng thái review, không
phải publish và không tự sinh Learning business state. Khi được chấp nhận:

* Node mới chỉ được tạo qua Learning owner service, và chỉ vào Framework Version
  đang ở `draft_snapshot`.
* Canonical Mapping chỉ materialize khi có đồng thời published Course Version
  Lesson/Activity và Node thuộc published Framework Version. Không resolve
  `latest`, không rebind âm thầm sang version mới.
* Idempotency của promotion khoá trên chính khoá duy nhất của Mapping —
  `(customer_id, source_type, source_id, source_discriminator, learning_node_id,
  mapping_role)` — không phải trên id của Proposal.
* Mapping sai được **invalidate** theo vòng đời một chiều đã duyệt, không xoá và
  không sửa.
* Proposal và Mapping không phải Evidence và không tạo Mastery side effect.

---

# Implementation Status Note

Learning Domain đã triển khai một phần. Mười bảng Foundation cùng khoá ngoại,
CHECK và trigger đã deploy trên database development, kèm các service runtime
nội bộ. Customer admin có external authoring surface cho Framework, Stable Node
Definition, Framework Version `draft_snapshot`, Versioned Node và publish
Version; Gate 2 closed on 2026-08-23 by MariaDB HTTP/service evidence and
Architecture Owner attestation. Production là gate riêng.

Surface này chỉ quản trị Foundation graph. Nó chưa làm Course tiêu thụ được
Framework: chưa có Course/Activity chọn Framework Version, Course adapter hoặc
UI ghi canonical Node Mapping tới published Course Version Lesson/Activity,
Teacher Judgment external surface, hay Course-derived Evidence. Manual fallback
đã tồn tại một phần cho taxonomy administration, nhưng manual Mapping fallback,
AI Proposal/Review/Promotion và Node Relation/lifecycle authoring vẫn chưa có.
Mọi surface đó phải dùng cùng Learning owner service cùng
Framework/Definition/Node foundation; không được tạo schema hay Source Of Truth
song song. Tài liệu này là policy đã được Architecture Owner phê duyệt ngày
2026-08-12.

## Owner Approval

```text
Role: LearnForge Architecture Owner
Date: 2026-08-12
Decision: Approved ADR-0016 and LF-Core-Learning Version 1.0; authorized Phase 3 database documentation design.
```

Amendment Version 1.1 was approved and the Learning Foundation database contract
was Frozen on 2026-08-12 after independent Round 4 re-review PASS. Migration
still requires a separate Phase 4 authorization.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
