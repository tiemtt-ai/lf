# ADR-0016 — Learning Foundation

Version: 1.0

Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-11

Proposal Date: 2026-08-11

Document Path: adr/ADR-0016-Learning-Foundation.md

Related ADRs:

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)
* [ADR-0003 — Assessment Foundation](ADR-0003-Assessment-Foundation.md)
* [ADR-0005 — Track Foundation](ADR-0005-Track-Foundation.md)
* [ADR-0006 — AI Foundation](ADR-0006-AI-Foundation.md)
* [ADR-0011 — Certificate Foundation](ADR-0011-Certificate-Foundation.md)
* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md)

---

# Context

LearnForge hiện ghi nhận việc học bằng ba loại dữ liệu tách rời:

```text
Course Progress / Completion   → học viên đã đi hết nội dung chưa
Assessment Score / Result      → học viên trả lời đúng bao nhiêu
Certificate                    → học viên có đủ điều kiện cấp bằng không
```

Không loại nào trả lời được câu hỏi nghiệp vụ: **học viên đã thật sự nắm được
năng lực nào, ở mức nào, dựa trên bằng chứng gì.**

Ba khái niệm này khác nhau và không được đồng nhất:

```text
Completion  ≠  Score  ≠  Mastery

Completion  = đã đi hết nội dung
Score       = kết quả một lần đo
Mastery     = kết luận về năng lực, tổng hợp từ nhiều bằng chứng
```

Nếu suy ra Mastery trực tiếp từ dữ liệu mutable hiện tại:

* Course Template sửa nội dung có thể làm đổi nghĩa kết luận năng lực cũ.
* Framework năng lực ra phiên bản mới có thể ghi đè lịch sử đánh giá.
* Không truy được một mức Mastery đã được tính từ chính xác những bằng chứng nào.
* Giáo viên override không phân biệt được với kết quả hệ thống tính.
* Behavioral signal của Track có thể lặng lẽ trở thành bằng chứng năng lực.
* AI có thể ghi trực tiếp vào trạng thái nghiệp vụ.

LearnForge cần một Domain độc lập sở hữu ngữ nghĩa học tập, bằng chứng học tập
và kết luận năng lực, tách khỏi nội dung (Course), phép đo (Assessment) và
chứng nhận (Certificate).

---

# Decision

Learning được xác định là:

```text
Core Business Domain

+

Semantics Authority  (Framework / Objective / Concept / Competency)

+

Evidence and Mastery Authority
```

Learning Foundation Version 1.0 gồm **10 tables**.

Learning tiêu thụ bằng chứng do các Domain khác phát ra, áp dụng rule của
Framework Version, và tự đưa ra kết luận Mastery của riêng mình. Không Domain
nào khác được ghi trạng thái Mastery.

---

# Canonical Decisions

## D1 — Domain Ownership

Learning là Core Business Domain độc lập, ngang cấp Course, LiveClass,
Assessment và Certificate.

Enterprise/HR là **consumer tương lai** và là owner của mapping Job Role /
Position / Requirement sang stable Learning Node Definition. Enterprise/HR
không được tạo quan hệ sở hữu ngược vào Learning.

## D3 — Mastery Scale

Tenant có mastery scale mặc định, chỉ dùng để **prefill khi authoring**.

Mỗi Framework Version khi publish phải đóng băng mastery scale snapshot của
riêng nó. Không runtime inheritance từ tenant default. Không hardcode một enum
chung cho toàn LF.

Sửa tenant default không được ảnh hưởng bất kỳ Framework Version đã publish nào.

## D4 — Node Reference Strategy

```text
Evidence                      →  core_learning_nodes          (versioned)
Mastery Calculation / Profile →  core_learning_node_definitions (stable)
                                 + basis_framework_version_id
```

Evidence neo vào định nghĩa Node **tại thời điểm phát sinh** để bảo toàn
lineage. Calculation và Profile neo vào danh tính ổn định để có thể truy vấn
xuyên version — nhưng luôn phân tách theo `basis_framework_version_id`.

Danh tính ổn định **không** đồng nghĩa tự động chuyển Evidence hoặc Mastery
sang version mới.

## D5 — Relation Scopes

Một bảng relation, hai scope, ràng buộc version ngược nhau.

Semantic relation bắt buộc cùng Framework Version. Version transition relation
bắt buộc khác Framework Version, cùng tenant và **cùng Framework**. Learning
Foundation v1 không cho phép `version_transition` nối các Node thuộc hai
Framework khác nhau. Xem [D11](#d11--version-transition-framework-boundary).

## D6 — Mastery Profile Identity

```text
UNIQUE (
    customer_id,
    user_id,
    node_definition_id,
    basis_framework_version_id
)
```

Enrollment Version Lock cho phép một học viên đồng thời học nhiều Course
Template Version khác nhau. Các Calculation tương ứng không được ghi đè lên
cùng một Profile chỉ vì dùng chung stable Node Definition.

## D7 — Continuity Policy

Continuity policy trú trên **từng dòng version_transition relation**, kèm policy
key/version/snapshot bất biến. Carry-forward tạo Calculation mới và giữ lineage;
không sửa lịch sử.

## D8 — Relation Enforcement

Bảng relation lưu `source_framework_version_id` và `target_framework_version_id`.
`CHECK` enforce quy tắc cùng/khác version. Composite foreign key (ưu tiên) hoặc
persistence guard/trigger tương đương phải xác minh Node–Version integrity.

Application validation là bắt buộc nhưng không được là lớp bảo vệ duy nhất khi
database có thể enforce.

## D9 — Relation Vocabulary v1

```text
semantic           : prerequisite, part_of, supports
version_transition : equivalent_to, supersedes, splits_into, merges_into
```

Relation type `related` bị loại khỏi v1: nó không có ngữ nghĩa xác định nên
không validate được và sẽ tích tụ mọi quan hệ không phân loại được.

## D10 — Documentation Sequence

Quyết định này chỉ mở Giai đoạn 2A. Xem [Approval Sequence](#approval-sequence).

## D11 — Version Transition Framework Boundary

Trong Learning Foundation v1, mọi `version_transition` relation:

* phải thuộc cùng `customer_id`;
* phải có `source_framework_version_id` khác `target_framework_version_id`;
* phải có Node nguồn và Node đích thuộc **cùng một** `framework_id`;
* không được biểu diễn quan hệ giữa hai Framework khác nhau.

Áp dụng cho toàn bộ `equivalent_to`, `supersedes`, `splits_into`, `merges_into`.
Không có ngoại lệ cho `equivalent_to`.

Hai loại quan hệ phải được phân biệt dứt khoát:

```text
Version transition
    → cùng Framework
    → khác Framework Version
    → theo dõi sự chuyển đổi Node qua các version của một Framework

Cross-framework mapping
    → khác Framework
    → không thuộc version_transition
    → hoãn khỏi Learning Foundation v1
    → sau này phải có cơ chế mapping, ownership và policy riêng
```

Nếu không có ranh giới này, `equivalent_to` trở thành cửa sau cho cross-framework
mapping — thứ mà [D1](#d1--domain-ownership) và quy tắc một-Definition-một-Framework
đã hoãn lại một cách có chủ đích.

---

# Domain Responsibility

Learning sở hữu:

* Learning Framework và Framework Version.
* Stable Node Definition (Objective / Concept / Competency).
* Versioned Node snapshot và Node Relations.
* Mapping từ immutable Course/Assessment object sang versioned Node.
* Learning Evidence.
* Mastery Calculation và Mastery Profile.
* Evidence qualification rule và continuity policy.

Learning không sở hữu:

* Course Template, Version, Enrollment, Progress, Completion.
* Assessment Question, Quiz, Attempt, Score, Grading.
* Certificate eligibility và issuance.
* Track Event và Track Summary.
* Media binary.
* AI output.
* Job Role, Position, Career Path.

---

# Learning Foundation Architecture

```text
                    LEARNING FRAMEWORK / SEMANTICS
    ┌──────────────────────────────────────────────────────────────┐
    │                                                              │
    │   [1] core_learning_frameworks                               │
    │       danh tính ổn định của một bộ khung năng lực            │
    │            │                                                 │
    │            ├──────────────────────┬──────────────────────┐   │
    │            ↓ 1:N                  │                      │   │
    │   [2] core_learning_              │                      │   │
    │       framework_versions          │                      │   │
    │       immutable + mastery         │                      │   │
    │       scale snapshot              │                      │   │
    │            │                      │                      │   │
    │            ↓ 1:N                  ↓ 1:N                   │   │
    │   [4] core_learning_nodes ──────► [3] core_learning_      │   │
    │       node snapshot trong      N:1    node_definitions    │   │
    │       đúng một version                danh tính ổn định   │   │
    │            │                          xuyên version       │   │
    │            ↓                                              │   │
    │   [5] core_learning_node_relations                        │   │
    │       ├── semantic          → cùng version                │   │
    │       └── version_transition → khác version               │   │
    │                                + continuity policy        │   │
    └──────────────────────────────────────────────────────────────┘
                                 │
                                 ↓
                    IMMUTABLE CONTENT / ASSESSMENT OBJECT
    ┌──────────────────────────────────────────────────────────────┐
    │   Working Template Lesson / Activity ──┐                     │
    │   Assessment Question (authoring) ─────┤  mutable            │
    │                                        │  KHÔNG map trực tiếp│
    │                          publish / snapshot                  │
    │                                        ↓                     │
    │   Version Lesson / Version Activity  (Course)                │
    │   Quiz Question Snapshot             (Assessment — Phase 2)  │
    │                                        ↓                     │
    │   [6] core_learning_node_mappings                            │
    │       "object này dạy hoặc đánh giá Node nào"                │
    │       mapping_role + weight/contribution                     │
    └──────────────────────────────────────────────────────────────┘
                                 │
                                 ↓
                            EVIDENCE SOURCES
    ┌──────────────────────────────────────────────────────────────┐
    │   Course Activity Progress / Completion ──┐  exposure         │
    │                                           │  completion       │
    │   Assessment Attempt / Grading (Phase 2) ─┤  evaluation       │
    │                                           │                   │
    │   Teacher Judgment ───────────────────────┤  expert_judgment  │
    │                                           │                   │
    │   Qualified Track Signal (future) ────────┘  behavioral_signal│
    │                     │                                         │
    │                     ↓  Evidence Qualification Rule            │
    │                        (signal KHÔNG tự thành Evidence)       │
    │                     ↓                                         │
    │   [7] core_learning_evidence                                  │
    │       append-only · versioned node lineage · rule snapshot    │
    │       correction tạo Evidence mới, không sửa bản cũ           │
    └──────────────────────────────────────────────────────────────┘
                                 │
                                 ↓
                          MASTERY FOUNDATION
    ┌──────────────────────────────────────────────────────────────┐
    │   [8] core_learning_mastery_calculations                      │
    │       append-only · stable node + basis framework version     │
    │       rule / scale / policy snapshot                          │
    │                     │                                         │
    │                     ↓ đúng tập Evidence đã dùng               │
    │   [9] core_learning_calculation_evidence                      │
    │       evidence_id · effective_weight · contribution · reason  │
    │                     │                                         │
    │                     ↓ projection                              │
    │   [10] core_learning_mastery_profiles                         │
    │        UNIQUE customer + user + stable node + basis version   │
    │        rebuildable read model                                 │
    └──────────────────────────────────────────────────────────────┘
                                 │
                                 ↓  chỉ đọc
    ┌──────────────────────────────────────────────────────────────┐
    │   AI Recommendation / Insight                                 │
    │                     ↓                                         │
    │   Human hoặc Owning Domain phê duyệt   (bắt buộc)             │
    │                     ↓                                         │
    │   Learning Plan change                                        │
    └──────────────────────────────────────────────────────────────┘

    Certificate giữ nhánh ownership và eligibility hoàn toàn độc lập:
    Course Completion + Assessment Evidence + Certificate Rules → Eligibility
```

---

# Framework and Node Ownership

Danh tính ổn định thuộc Framework, **không** thuộc Framework Version. Nếu Node
Definition treo dưới một version, nó không còn ổn định và toàn bộ lý do tồn tại
của nó sụp đổ.

```text
core_learning_frameworks
    ├──→ core_learning_framework_versions
    │         └──→ core_learning_nodes
    │
    └──→ core_learning_node_definitions
              ↑
              └──── core_learning_nodes  (mỗi versioned node trỏ về 1 definition)
```

```text
Framework          → sở hữu Stable Node Definitions
Framework Version  → sở hữu Versioned Nodes
Versioned Node     → tham chiếu Stable Node Definition
```

Quy tắc v1:

* Mỗi `node_definition` thuộc đúng một Framework.
* Không dùng chung một `node_definition` giữa hai Framework.
* Mỗi Versioned Node phải tham chiếu Definition thuộc **cùng Framework** với
  Framework Version của Node đó.
* Việc đối chiếu năng lực giữa các Framework khác nhau không được thực hiện bằng
  cách chia sẻ Definition, và cũng không được thực hiện qua `version_transition`
  relation theo [D11](#d11--version-transition-framework-boundary).
  Cross-framework mapping bị hoãn khỏi Foundation v1.

---

# Node Relations and Continuity Policy

```text
                       core_learning_node_relations
                                    │
              ┌─────────────────────┴─────────────────────┐
              ↓                                           ↓
        relation_scope                              relation_scope
          = semantic                              = version_transition
              │                                           │
    source_framework_version_id                 source_framework_version_id
              =                                           ≠
    target_framework_version_id                 target_framework_version_id
              │                                           │
    prerequisite / part_of / supports      equivalent_to / supersedes /
                                           splits_into / merges_into
                                                          │
                                             source.framework_id
                                                          =
                                             target.framework_id
                                                          │
                                                          ↓
                                            continuity_policy snapshot
```

Cả hai scope bắt buộc cùng `customer_id`. Version transition còn bắt buộc cùng
`framework_id` theo [D11](#d11--version-transition-framework-boundary): nó theo
dõi Node chuyển đổi qua các version của **một** Framework, không phải quan hệ
giữa hai Framework.

| Scope | Relation type | Version rule | Continuity mặc định |
| --- | --- | --- | --- |
| semantic | `prerequisite` | cùng version | không áp dụng |
| semantic | `part_of` | cùng version | không áp dụng |
| semantic | `supports` | cùng version | không áp dụng |
| version_transition | `equivalent_to` | khác version, cùng framework | `requires_review` |
| version_transition | `supersedes` | khác version, cùng framework | `no_carry_forward` |
| version_transition | `splits_into` | khác version, cùng framework | `no_carry_forward` |
| version_transition | `merges_into` | khác version, cùng framework | `no_carry_forward` |

`equivalent_to` mặc định là `requires_review`, không phải carry-forward tự động:
hai Node được tác giả Framework coi là tương đương không đủ để mang kết luận
năng lực của học viên sang version mới.

Dữ liệu continuity tối thiểu trên relation:

```text
relation_scope, relation_type
source_learning_node_id, target_learning_node_id
source_framework_version_id, target_framework_version_id
continuity_policy: no_carry_forward | allow_as_input | carry_forward | requires_review
continuity_policy_version, continuity_policy_snapshot
approved_by, approved_at
```

Carry-forward hợp lệ **không** sửa Calculation hoặc Profile lịch sử. Khi policy
cho phép, hệ thống tạo Mastery Calculation mới cho target basis Framework
Version, chỉ rõ Calculation/Evidence nguồn và đóng băng policy snapshot đã áp
dụng.

---

# Mapping Architecture

Mapping chỉ trỏ vào object bất biến đã tồn tại:

```text
ĐƯỢC map                          KHÔNG được map
─────────────────────────────     ─────────────────────────────
Version Lesson                    Working Template Lesson
Version Activity                  Working Template Activity
Quiz Question Snapshot (Phase 2)  Assessment Question (authoring)
```

Mapping không được tự tìm "latest version". Quan hệ nội bộ Learning dùng hard
foreign key; tham chiếu cross-domain dùng generic reference có whitelist và
phải validate owner theo Generic Reference Principle.

---

# Evidence Architecture

```text
                 nguồn phát            evidence_type
    ─────────────────────────────      ──────────────────
    Course Activity Progress           exposure
    Course Completion / Progress       completion
    Assessment Attempt / Grading       evaluation
    Teacher Judgment                   expert_judgment
    Qualified Track Signal             behavioral_signal
```

Evidence là append-only. Sửa sai không được update tại chỗ: hệ thống ghi một
Evidence mới trỏ về bản bị đính chính, kèm lý do — cùng cơ chế correction chain
mà Track Foundation đã dùng.

Evidence lưu tối thiểu: nguồn phát (domain, loại object, id), versioned
`learning_node_id`, evidence rule key/version/snapshot, thời điểm phát sinh,
và liên kết correction.

Signal không tự trở thành Evidence. Track Summary chỉ trở thành Evidence khi
qualification rule của Framework Version cho phép.

---

# Mastery Architecture

```text
    Evidence (nhiều)
        │
        ↓  Framework Version rule + mastery scale snapshot
    [8] Mastery Calculation           append-only
        │   node_definition_id
        │   basis_framework_version_id
        │   rule / scale / policy snapshot
        │   calculation_source: system | teacher_override | carry_forward
        ↓
    [9] Calculation Evidence          junction, không giấu lineage trong JSON
        │   evidence_id, effective_weight, contribution, included_reason
        ↓
    [10] Mastery Profile              projection, rebuildable
         UNIQUE (customer_id, user_id, node_definition_id,
                 basis_framework_version_id)
```

Trả lời câu hỏi "Mastery hiện tại là gì?":

* Mặc định: chọn Profile có `basis_framework_version_id` bằng Framework Version
  đang active/published theo policy được phê duyệt.
* Lịch sử: giữ và đọc được Profile của các version cũ.
* Xuyên version: truy vấn **chủ động** qua `node_definition_id`; không tự gộp.
* Khi không có active-version resolution rõ ràng: trả về nhiều trạng thái có
  nhãn version, hoặc fail closed. Không được chọn "dòng mới nhất" một cách mù
  quáng.

Teacher override và carry-forward đều tạo Calculation mới. Không sửa lịch sử.

---

# Enforcement Requirements

Các ràng buộc sau là một phần của quyết định kiến trúc, không phải chi tiết
triển khai tùy chọn:

```text
CHECK  relation_scope = 'semantic'
         AND source_framework_version_id = target_framework_version_id
       OR
       relation_scope = 'version_transition'
         AND source_framework_version_id <> target_framework_version_id
```

```text
UNIQUE (id, framework_version_id)  trên core_learning_nodes
```

`UNIQUE (id, framework_version_id)` tồn tại để phục vụ referential integrity của
composite foreign key:

```text
(source_learning_node_id, source_framework_version_id)
    → core_learning_nodes (id, framework_version_id)

(target_learning_node_id, target_framework_version_id)
    → core_learning_nodes (id, framework_version_id)
```

Đây **không** phải một business identity mới. `id` vẫn là khóa chính duy nhất
của Node.

Một số phiên bản MySQL/MariaDB chấp nhận foreign key trỏ tới indexed non-unique
key. LF chủ động quy định `UNIQUE` để contract rõ ràng, portable và không phụ
thuộc hành vi legacy của database engine.

Nếu schema vật lý không đáp ứng được composite foreign key, table documentation
phải chốt trigger hoặc persistence guard tương đương. Không được tuyên bố `CHECK`
một mình là đủ, vì `CHECK` chỉ so hai cột denormalized với nhau mà không chứng
minh chúng đúng với Node được trỏ tới.

Mọi nguồn và đích phải cùng `customer_id`. Cross-tenant luôn bị cấm.

Version transition còn phải xác minh được ràng buộc cùng Framework của
[D11](#d11--version-transition-framework-boundary):

```text
source Node → source Framework Version → source Framework
target Node → target Framework Version → target Framework

source.framework_id = target.framework_id
```

`CHECK` một mình chỉ làm được điều này nếu `framework_id` của hai đầu được lưu
denormalized trên chính dòng relation. Nếu không lưu, phải dùng composite foreign
key, trigger hoặc persistence guard trong transaction.

ADR này quy định invariant bắt buộc. Cơ chế vật lý cụ thể được chốt ở Giai đoạn 3
cùng table documentation, và không được hạ xuống chỉ còn application validation.

---

# Relationship With Course

Course sở hữu Template, Version, Enrollment, Progress và Completion.

Learning tiêu thụ Course Progress/Completion làm Evidence và map vào Node thông
qua **Version Lesson/Activity đã publish**. Learning không đọc working Template
và không quyết định Progress.

Course không ghi Mastery.

---

# Relationship With Assessment

Assessment sở hữu Question, Quiz, Attempt, Score và Grading.

Learning tiêu thụ kết quả đánh giá làm Evidence loại `evaluation` và map vào
Node thông qua **Quiz Question Snapshot bất biến**, không phải Question đang
authoring.

Phần này thuộc Phase 2 và bị chặn cho tới khi Assessment có physical object.

---

# Relationship With Certificate

Certificate giữ ownership và eligibility hoàn toàn độc lập:

```text
Course Completion + Assessment Evidence + Certificate Rules → Eligibility
```

Mastery **không** nằm trong luồng cấp Certificate của Foundation v1. Trong tương
lai Mastery có thể trở thành input bổ sung, nhưng Certificate vẫn là decision
owner.

---

# Relationship With Track

Track sở hữu Event và Summary.

Track Summary là behavioral signal. Nó chỉ trở thành Learning Evidence khi
qualification policy cho phép, và khi đó Evidence phải ghi rõ rule snapshot đã
áp dụng.

Track không quyết định Mastery.

---

# Relationship With AI

AI chỉ đọc Mastery Profile để tạo Recommendation/Insight.

AI không tự thay đổi Mastery, Course, Certificate hoặc Learning Plan. Mọi thay
đổi Learning Plan phải qua phê duyệt của giáo viên hoặc owning Domain.

---

# Relationship With Enterprise / HR

Enterprise/HR là consumer tương lai. Enterprise/HR sở hữu mapping Job Role /
Position / Requirement sang stable Node Definition.

Learning không biết tới khái niệm Job Role, Position hay Career Path, và
Enterprise/HR không được tạo quan hệ sở hữu ngược vào Learning.

---

# Database Namespace

```text
core_learning_*
```

---

# Foundation Tables

| # | Bảng | Vai trò |
| --- | --- | --- |
| 1 | `core_learning_frameworks` | Danh tính ổn định của bộ khung học tập/năng lực trong tenant. |
| 2 | `core_learning_framework_versions` | Phiên bản publish bất biến; đóng băng policy và mastery scale. |
| 3 | `core_learning_node_definitions` | Danh tính ổn định xuyên version của Objective/Concept/Competency. |
| 4 | `core_learning_nodes` | Snapshot Node trong đúng một Framework Version. |
| 5 | `core_learning_node_relations` | Quan hệ semantic nội-version và chuyển đổi liên-version; chứa continuity policy. |
| 6 | `core_learning_node_mappings` | Ánh xạ immutable Course/Assessment object vào versioned Node. |
| 7 | `core_learning_evidence` | Evidence append-only theo user; giữ source lineage, versioned Node và rule snapshot. |
| 8 | `core_learning_mastery_calculations` | Mỗi lần tính/override/carry-forward là một record; append-only. |
| 9 | `core_learning_calculation_evidence` | Junction audit chính xác Evidence, trọng số, contribution và lý do. |
| 10 | `core_learning_mastery_profiles` | Read model trạng thái hiện tại theo user + stable Node + basis version. |

Canonical table documentation sẽ đặt tại `docs/database/learning/` và **chưa
tồn tại** tại thời điểm ADR này ở trạng thái Review. Xem
[Approval Sequence](#approval-sequence).

---

# Invariants

1. Cả 10 bảng có `customer_id NOT NULL`; mọi liên kết chống cross-tenant.
2. Learning là owner của Framework, Evidence và Mastery; Domain khác chỉ cung
   cấp nguồn hoặc tiêu thụ output.
3. Framework Version đã publish là immutable; Node và Relation đã publish không
   sửa tại chỗ.
4. Stable Node Definition tách khỏi Versioned Node snapshot.
5. Node Definition thuộc Framework, không thuộc Framework Version; mỗi
   Definition thuộc đúng một Framework.
6. Mapping chỉ trỏ immutable snapshot/historical object đã tồn tại và được
   whitelist; không tự tìm latest version.
7. Evidence tham chiếu versioned Node; Evidence append-only; correction tạo
   Evidence mới.
8. Signal không tự trở thành Evidence; qualification rule phải cho phép.
9. Calculation tham chiếu stable Node Definition và `basis_framework_version_id`;
   lưu rule/scale/policy snapshot.
10. Mỗi Calculation chỉ rõ đúng tập Evidence qua junction table; Calculation
    append-only.
11. Teacher override và carry-forward đều tạo Calculation mới; không sửa lịch sử.
12. Mastery Profile là rebuildable read model, phân tách theo
    `basis_framework_version_id`.
13. Tenant default mastery scale chỉ prefill; runtime authority là Framework
    Version snapshot.
14. Không carry-forward giữa version nếu continuity policy chưa cho phép.
15. Semantic relation cùng version; version_transition relation khác version;
    Node–Version integrity phải được enforce bằng composite foreign key dựa trên
    `UNIQUE (id, framework_version_id)`, hoặc bằng cơ chế tương đương đã được
    phê duyệt.
16. Version transition relation phải nối hai Node thuộc cùng `framework_id`;
    v1 không cho phép relation này biểu diễn quan hệ giữa hai Framework khác
    nhau, và không có ngoại lệ cho `equivalent_to`. Ràng buộc này phải xác minh
    được ở tầng persistence, không chỉ ở application.
17. Relation type `related` không thuộc v1.
18. Evidence v1 không tự hết hạn; Mastery v1 không tự suy giảm.
19. AI không tự thay đổi Mastery, Course, Certificate hoặc Learning Plan.
20. Phase 1 không tham chiếu Assessment hoặc Track physical object chưa tồn tại.

---

# Implementation Scope

## Phase 1

* Đủ 10 bảng Learning Foundation ở mức domain contract.
* Mapping vào Course Version Lesson/Activity đã publish.
* Evidence từ Course Activity Progress/Completion và Teacher Judgment.
* Whitelist chỉ mở cho physical object đã được xác minh tồn tại.

## Phase 2 — sau khi Assessment được triển khai

* Quiz/Question Snapshot mapping.
* Attempt, Answer, Score, Rubric và Grading Evidence.
* Assessment-based Mastery calculation.

## Future

* Track Summary là behavioral signal; chỉ thành Evidence khi qualification
  policy cho phép.
* AI đọc Mastery Profile để tạo Recommendation/Insight.
* Enterprise/HR mapping Job Role sang stable Node Definition.
* Cross-framework competency mapping.

---

# Deferred Decisions

Hai quyết định được hoãn có chủ đích và phải chốt trước khi table documentation
được duyệt:

**E2 — Evidence validity/expiry.** Foundation v1 không tự suy ra Evidence hết
hạn từ `occurred_at`. Ngữ nghĩa validity/expiry phải được chốt ở Giai đoạn 3.

**E3 — Mastery decay.** Không đưa automatic decay vào v1. Nếu tương lai cần
decay, policy phải tạo Calculation mới với rule snapshot và cập nhật Profile
projection; không sửa score hay Evidence lịch sử.

---

# Approval Sequence

| Giai đoạn | Deliverable | Gate |
| --- | --- | --- |
| 2A | ADR-0016 + [LF-Core-Learning](../core/LF-Core-Learning.md) | Architecture review; ADR Approved |
| 2B | Cập nhật Domain Map, Architecture Roadmap, Glossary, Data Flow, LF-INDEX, documentation manifest | Đồng bộ canonical routing sau decision |
| 3 | 10 table docs; chốt E2 và physical constraints; schema contract | Database/Architecture review |
| 4 | Forward migrations, implementation, tests | `docs:lint`, `schema:drift`, regression gate |

Không được tuyên bố Foundation sẵn sàng và không được tạo migration trước khi 10
table docs hoàn chỉnh, review pass và schema contract được cập nhật.

---

# Applied Principles

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Immutable Principle.
* Snapshot Principle.
* Versioning Principle.
* Evidence Principle.
* Generic Reference Principle.
* Tenant Isolation Principle.
* Read Model Principle.
* Append Only Principle.
* AI Consumer Principle.
* Backward Compatibility Principle.
* Simplicity Principle.
* ADR Principle.

ADR này tham chiếu định nghĩa canonical và không định nghĩa lại.

---

# Consequences

## Benefits

* Ownership của ngữ nghĩa học tập, bằng chứng và năng lực là tường minh.
* Completion, Score và Mastery không còn bị đồng nhất.
* Framework ra phiên bản mới không viết lại kết luận năng lực lịch sử.
* Truy được một mức Mastery đã tính từ chính xác những Evidence nào, trọng số
  nào, rule nào.
* Giáo viên override và carry-forward đều để lại dấu vết.
* Behavioral signal không âm thầm trở thành bằng chứng năng lực.
* Course, Assessment, Certificate, Track và AI giữ nguyên độc lập.

## Trade-offs

* Tách stable identity khỏi versioned snapshot làm tăng số bảng và số join.
* Profile phân tách theo basis version khiến câu hỏi "mastery hiện tại" cần một
  resolution rule tường minh thay vì một dòng duy nhất.
* Append-only Calculation làm dữ liệu tăng theo thời gian và cần chính sách lưu
  trữ.
* Continuity policy đòi hỏi quy trình phê duyệt vận hành, không chỉ cấu hình.
* Composite foreign key kéo theo index bổ sung không mang ý nghĩa nghiệp vụ.
* Phase 1 không có Evidence từ Assessment nên độ phủ Mastery ban đầu hạn chế.

---

# Foundation Change Control

Learning Foundation Version 1.0 **chưa** được Approved và chưa Frozen tại thời
điểm ADR này ở trạng thái Review.

Khi được approved, thay đổi Domain Boundary, ownership, Source Of Truth, chiến
lược snapshot/evidence, hoặc bộ 10 bảng Foundation sẽ yêu cầu ADR Amendment
hoặc ADR mới.

---

# Result

```text
Learning Foundation

Version 1.0

Conceptual Architecture          PASS
Architecture Decision Readiness  READY
Database Documentation           chưa tạo — Giai đoạn 3
Migration                        chưa cho phép — Giai đoạn 4

Ready for implementation

NO
```
