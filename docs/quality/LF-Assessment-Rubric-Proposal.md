# LF Assessment Rubric Proposal

Version: 0.2

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-06

Document Path: quality/LF-Assessment-Rubric-Proposal.md

---

# Mục đích

Đề xuất để Owner duyệt: thêm **Rubric (tiêu chí chấm)** cho câu tự luận và câu
nói của module Kiểm tra. Tài liệu này **chưa phải ADR** và **không cho phép tạo
migration**. Khi Owner duyệt, nội dung chuyển thành ADR-0026 (mở lại phần rubric
mà [ADR-0025](../adr/ADR-0025-Assessment-Simple-Administration.md) hoãn), rồi
cập nhật database docs, Architecture Review, Freeze, migration, triển khai.

# Hiện trạng (đã kiểm tra 2026-11-06)

* Giáo viên chấm câu `essay` / `speaking` bằng **một ô điểm + nhận xét**
  ([core_assessment_gradings](../database/assessment/core_assessment_gradings.md)).
  Mỗi lần chấm là một dòng, dòng mới nhất là hiện hành.
* `core_assessment_rubrics` và `core_assessment_rubric_items` đã có tài liệu từ
  [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) nhưng **hoãn**, chưa có
  bảng. Thiết kế đó chưa có người tạo, chưa nói rubric gắn vào câu nào, và chụp
  rubric "khi bắt đầu chấm".
* Đề có bản bất biến khi xuất bản: `core_assessment_quiz_version_questions`
  chụp nội dung câu; điểm của câu trong đề sửa được
  (`core_assessment_quiz_questions.points`), nên có thể khác điểm mặc định.

# Cách dùng (góc nhìn người dùng)

1. Menu Kiểm tra có thêm mục **Tiêu chí chấm**. Tạo một rubric, ví dụ
   "Viết TOPIK II câu 54": Nội dung 12 điểm, Bố cục 12 điểm, Ngôn ngữ 26 điểm.
   Mỗi tiêu chí có tên, điểm tối đa, mô tả cách cho điểm.
2. Khi soạn câu tự luận / nói, chọn rubric (không bắt buộc).
3. Xuất bản đề: rubric được **chụp lại vào phiên bản đề** cùng nội dung câu.
4. Chấm bài: câu có rubric hiện từng tiêu chí với ô điểm riêng; hệ thống cộng và
   **quy đổi về điểm của câu trong đề**. Nhận xét chung giữ nguyên.
5. Học viên xem kết quả: thấy điểm từng tiêu chí và nhận xét.

Câu không gắn rubric chấm như hiện nay. Không có AI.

# Thiết kế đề xuất

## R1. Rubric là mẫu dùng lại, thuộc tenant và người tạo

* Admin thấy, sửa mọi rubric trong tenant. Giáo viên sửa rubric của mình.
* Trạng thái chỉ `active` / `archived` (bỏ `draft` của ADR-0003): tiêu chí
  được kiểm khi lưu nên không cần trạng thái nháp.
* Không xoá rubric đang gắn vào câu hỏi; lưu trữ thì câu cũ vẫn giữ, không gắn
  mới được.

## R2. Gắn vào câu hỏi, chỉ `essay` và `speaking`

* `core_assessment_questions.rubric_id` (NULL được).
* Đổi rubric của câu không ảnh hưởng đề đã xuất bản.

## R3. Chụp rubric khi xuất bản đề (khác ADR-0003)

* ADR-0003 chụp khi bắt đầu chấm. Đề xuất chụp **khi xuất bản phiên bản đề**,
  vào cột mới `core_assessment_quiz_version_questions.rubric_snapshot`.
* Lý do: mọi học viên cùng phiên bản bị chấm theo đúng một bộ tiêu chí, khớp
  cách chụp nội dung câu hiện có, và không cần thêm cột chụp trên mỗi lần chấm.
  Quy tắc gốc của ADR-0003 vẫn giữ: sửa rubric sau đó không làm đổi bài đã chấm.

## R4. Điểm: cộng tiêu chí rồi quy đổi

* Điểm câu = (tổng điểm tiêu chí ÷ tổng điểm tối đa của rubric) × điểm của câu
  trong đề, làm tròn 2 chữ số.
* Ví dụ: rubric 50 điểm, câu trong đề 10 điểm, chấm 40/50 → câu được 8 điểm.
* Nhờ quy đổi, một rubric dùng được cho câu 10 điểm hay 50 điểm.

## R5. Kết quả chấm lưu trên dòng chấm

* Cột mới `core_assessment_gradings.rubric_result`: điểm từng tiêu chí của lần
  chấm đó. `score` vẫn là điểm câu sau quy đổi, nên tổng bài, thống kê, danh
  sách kết quả không đổi cách tính.
* Chấm lại tạo dòng mới như hiện nay, lịch sử điểm từng tiêu chí giữ nguyên.

# Thay đổi dữ liệu

| Bảng | Thay đổi |
|---|---|
| `core_assessment_rubrics` | **Mới** (bỏ hoãn): `id`, `customer_id`, `created_by`, `title`, `description`, `skill_type` NULL, `total_points` (tổng tiêu chí, tính khi lưu), `status` (`active`/`archived`), timestamps. Bỏ `metadata`. |
| `core_assessment_rubric_items` | **Mới** (bỏ hoãn): `id`, `customer_id`, `rubric_id`, `title`, `description`, `max_points` (> 0), `sort_order`, timestamps. Bỏ `metadata`. |
| `core_assessment_questions` | Thêm `rubric_id` NULL, FK `(rubric_id, customer_id)`. |
| `core_assessment_quiz_version_questions` | Thêm `rubric_snapshot` JSON NULL: `{"rubric_id":..,"title":..,"total_points":50,"items":[{"key":1,"title":"Nội dung","description":"..","max_points":12}]}`. |
| `core_assessment_gradings` | Thêm `rubric_result` JSON NULL: `[{"key":1,"score":10}]`. |

Mọi khoá ngoại đi theo cặp `(id, customer_id)` như các bảng Kiểm tra hiện có.
Không đổi dữ liệu cũ: câu, đề, bài chấm đã có đều có giá trị NULL ở cột mới.

# Phạm vi giai đoạn này

Có: trang Tiêu chí chấm (danh sách, thêm/sửa trong popup, lưu trữ), chọn rubric
trong form câu hỏi, chấm theo tiêu chí, học viên xem điểm từng tiêu chí.

Không có: mức mô tả theo từng mức điểm (Giỏi/Khá…) trong một tiêu chí, thống kê
theo tiêu chí, nhập rubric từ Excel, cột rubric trong file nhập câu hỏi, AI
chấm theo rubric, chia sẻ rubric cho từng người.

# Cần Owner chốt

> Owner chọn "như đề xuất" cho cả bốn câu (2026-11-06). Đã chuyển thành
> [ADR-0026](../adr/ADR-0026-Assessment-Rubric.md) và database docs, rà ở
> [LF-Assessment-Rubric-Architecture-Review](LF-Assessment-Rubric-Architecture-Review.md).

1. **Giáo viên dùng rubric của ai?** Đề xuất: rubric của mình **và** rubric do
   admin tạo (bộ tiêu chí chuẩn của trung tâm). Rubric của giáo viên khác thì
   không thấy.
2. **Học viên có thấy điểm từng tiêu chí không?** Đề xuất: có.
3. **Quy đổi điểm (R4)** hay bắt buộc điểm của câu bằng tổng rubric? Đề xuất:
   quy đổi.
4. **Tên menu:** "Tiêu chí chấm" hay "Rubric"? Đề xuất: "Tiêu chí chấm".

# Sau khi Owner duyệt

1. Viết ADR-0026 (R1–R5, sửa phần hoãn rubric của ADR-0025 và thời điểm chụp
   của ADR-0003).
2. Cập nhật database docs: `core_assessment_rubrics`, `core_assessment_rubric_items`
   (bỏ đánh dấu hoãn), `core_assessment_questions`,
   `core_assessment_quiz_version_questions`, `core_assessment_gradings`,
   `database/assessment/README.md`, `LF-SCHEMA-CONTRACT.json`.
3. Cập nhật `core/LF-Core-Assessment.md`, `business/LF-Navigation.md`, LF-INDEX,
   Manifest.
4. Architecture Review → Freeze → migration → triển khai → test (kể cả MariaDB
   11.4 cho CHECK và khoá ngoại).
