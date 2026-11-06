# ADR-0026 — Assessment Rubric

Version: 1.0

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-06

Proposal Date: 2026-11-06

Approval Date: 2026-11-06

Approved By: LearnForge Architecture Owner

Document Path: adr/ADR-0026-Assessment-Rubric.md

Related ADRs:

* [ADR-0003 — Assessment Foundation](ADR-0003-Assessment-Foundation.md) — ADR này sửa thời điểm chụp rubric
* [ADR-0025 — Assessment Simple Administration](ADR-0025-Assessment-Simple-Administration.md) — ADR này mở lại phần rubric đang hoãn

Related Specification:

* [LF-Assessment-Rubric-Proposal](../quality/LF-Assessment-Rubric-Proposal.md) — đề xuất, Owner chọn "như đề xuất" ngày 2026-11-06
* [LF-Core-Assessment](../core/LF-Core-Assessment.md)
* [database/assessment](../database/assessment/README.md)

---

# Context

Module Kiểm tra (ADR-0025) chấm câu tự luận và câu nói bằng một ô điểm và nhận
xét. Owner muốn chấm theo **tiêu chí** (ví dụ Nội dung, Bố cục, Ngôn ngữ), như
các kỳ thi viết và nói thường dùng. ADR-0025 hoãn `rubrics` và `rubric_items`.
Thiết kế đích của ADR-0003 chưa có người tạo, chưa nói rubric gắn vào đâu, và
chụp rubric "khi bắt đầu chấm", trong khi ADR-0025 đã chụp toàn bộ câu hỏi vào
phiên bản đề lúc xuất bản.

Owner duyệt đề xuất ngày 2026-11-06, chọn đúng bốn đề xuất ở mục "Cần Owner
chốt".

---

# Decision

## D1 — Rubric là mẫu dùng lại của tenant, có người tạo

* `core_assessment_rubrics` (tên, mô tả, kỹ năng, tổng điểm, trạng thái, người
  tạo) và `core_assessment_rubric_items` (tên tiêu chí, mô tả cách cho điểm,
  điểm tối đa, thứ tự).
* Trạng thái `active` / `archived`. Không có `draft`: tiêu chí được kiểm khi
  lưu (ít nhất một tiêu chí, mỗi tiêu chí điểm tối đa > 0).
* `total_points` là tổng điểm tối đa các tiêu chí, service tính khi lưu.
* Rubric đang gắn vào câu hỏi không xoá được, chỉ lưu trữ. Rubric lưu trữ không
  gắn mới được; câu đã gắn vẫn giữ.

## D2 — Quyền

* Admin (`customer_admin`) thấy, sửa, lưu trữ mọi rubric của tenant.
* Giáo viên sửa rubric của mình; **dùng được** rubric của mình và rubric do
  admin tạo (bộ tiêu chí chuẩn của trung tâm). Không thấy rubric của giáo viên
  khác.
* "Do admin tạo" xét theo vai trò hiện tại của người tạo
  (`users.role = customer_admin`), không lưu thêm cờ.
* Học viên không thấy trang Tiêu chí chấm.

## D3 — Gắn vào câu tự luận và câu nói

`core_assessment_questions.rubric_id` (NULL được), chỉ cho `essay` và
`speaking`, rubric cùng tenant. Đổi hay bỏ rubric của câu không ảnh hưởng phiên
bản đề đã xuất bản.

## D4 — Chụp rubric khi xuất bản đề

Sửa quy tắc "Rubric Snapshot" của ADR-0003: rubric và tiêu chí được chụp **khi
xuất bản phiên bản đề**, vào `core_assessment_quiz_version_questions.rubric_snapshot`,
cùng transaction với snapshot nội dung câu. Mọi học viên cùng phiên bản bị chấm
theo đúng một bộ tiêu chí. Nguyên tắc gốc giữ nguyên: sửa rubric sau đó không
làm đổi bài đã chấm hay đề đã xuất bản; chấm và xem lại chỉ đọc snapshot.

## D5 — Chấm theo tiêu chí và quy đổi điểm

* Câu có `rubric_snapshot`: giáo viên nhập điểm từng tiêu chí,
  `0 ≤ điểm ≤ max_points` của tiêu chí; phải đủ mọi tiêu chí. Nhận xét chung
  giữ nguyên.
* Điểm câu = (tổng điểm tiêu chí ÷ `total_points` của snapshot) ×
  `answers.max_score`, làm tròn 2 chữ số. Một rubric dùng được cho câu có điểm
  bất kỳ trong đề.
* Điểm từng tiêu chí lưu ở `core_assessment_gradings.rubric_result`; `score`
  vẫn là điểm câu sau quy đổi, nên tổng bài, danh sách kết quả và thống kê
  không đổi cách tính. Chấm lại là dòng mới như ADR-0025 D11.
* Câu không có rubric chấm như hiện nay.

## D6 — Học viên thấy điểm từng tiêu chí

Khi phiên bản cho học viên xem điểm câu (theo `show_result_mode`), học viên
thấy thêm tên tiêu chí, điểm đạt / điểm tối đa và nhận xét. `hidden` thì không
thấy gì như hiện nay.

## D7 — Giao diện

Menu Kiểm tra có thêm mục **Tiêu chí chấm** (admin và giáo viên): danh sách,
thêm / sửa trong popup với danh sách tiêu chí, lưu trữ. Form câu hỏi có ô chọn
rubric khi dạng câu là tự luận hoặc nói. Trang chấm hiện ô điểm từng tiêu chí và
tổng sau quy đổi.

---

# Thay đổi so với ADR-0003 và ADR-0025

| Trước | ADR-0026 |
| --- | --- |
| ADR-0025: `rubrics`, `rubric_items` hoãn | Có, giai đoạn này |
| ADR-0003: rubric có `draft`, `metadata` | Chỉ `active` / `archived`; bỏ `metadata`; thêm `created_by` |
| ADR-0003: chụp rubric khi bắt đầu chấm, lưu trên grading | Chụp khi xuất bản đề, lưu trên `quiz_version_questions` |
| ADR-0003: grading có `rubric_id`, `rubric_snapshot`, `rubric_result` | Chỉ thêm `rubric_result`; snapshot đã có ở phiên bản đề |

Phần còn lại của ADR-0003 và ADR-0025 giữ nguyên. Phân công chấm, AI chấm theo
rubric vẫn hoãn.

---

# Consequences

* Bảng mới: `core_assessment_rubrics`, `core_assessment_rubric_items`. Cột mới:
  `core_assessment_questions.rubric_id`,
  `core_assessment_quiz_version_questions.rubric_snapshot`,
  `core_assessment_gradings.rubric_result`. Dữ liệu cũ có NULL ở cột mới, không
  cần chuyển đổi.
* Cập nhật: database docs 5 bảng và README miền, `LF-Core-Assessment.md`,
  LF-INDEX, Manifest, ADR README; `LF-SCHEMA-CONTRACT.json`
  sau migration.
* Route và menu mới ở khu admin và giáo viên: rà soát HIGH theo
  [LF-Regression-Audit](../quality/LF-Regression-Audit.md).
* Điểm quy đổi có thể lệch 0,01 so với phép chia chính xác do làm tròn; điểm
  câu luôn trong `[0, max_score]`.

---

# Alternatives Rejected

* **Chụp khi bắt đầu chấm (ADR-0003)**: hai học viên cùng phiên bản có thể bị
  chấm theo hai bộ tiêu chí khác nhau nếu rubric bị sửa giữa chừng; thêm một
  cột snapshot trên mỗi lần chấm.
* **Bắt điểm câu bằng tổng rubric**: một rubric chỉ dùng được cho câu đúng một
  mức điểm; sửa điểm câu trong đề sẽ phải sửa rubric.
* **Rubric của giáo viên dùng chung cho mọi giáo viên**: trái ADR-0025 (chia sẻ
  giữa giáo viên là giai đoạn 2).
* **Mức mô tả theo từng mức điểm trong tiêu chí**: chưa cần; mô tả tiêu chí đủ
  để hướng dẫn chấm. Làm sau nếu cần.

---

# Owner Decisions (2026-11-06)

* Giáo viên dùng rubric của mình và rubric do admin tạo.
* Học viên thấy điểm từng tiêu chí.
* Quy đổi điểm theo điểm của câu trong đề.
* Tên menu: "Tiêu chí chấm".

---

# Owner Approval

```text
Role: LearnForge Architecture Owner
Date: 2026-11-06
Decision: Approved
```

Owner chọn các quyết định trên trong hội thoại ngày 2026-11-06, rồi duyệt văn
bản ADR này cùng database docs và
[LF-Assessment-Rubric-Architecture-Review](../quality/LF-Assessment-Rubric-Architecture-Review.md)
("Duyệt, làm luôn") cùng ngày.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
