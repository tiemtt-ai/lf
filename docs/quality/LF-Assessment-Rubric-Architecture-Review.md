# LF Assessment Rubric Architecture Review

Version: 0.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-06

Document Path: quality/LF-Assessment-Rubric-Architecture-Review.md

---

# Phạm vi

Rà [ADR-0026](../adr/ADR-0026-Assessment-Rubric.md) và database docs của rubric
trước khi Owner duyệt và tạo migration:
[core_assessment_rubrics](../database/assessment/core_assessment_rubrics.md),
[core_assessment_rubric_items](../database/assessment/core_assessment_rubric_items.md),
cột mới ở [core_assessment_questions](../database/assessment/core_assessment_questions.md),
[core_assessment_quiz_version_questions](../database/assessment/core_assessment_quiz_version_questions.md),
[core_assessment_gradings](../database/assessment/core_assessment_gradings.md).
Nguồn: [LF-Assessment-Rubric-Proposal](LF-Assessment-Rubric-Proposal.md).

Người rà: AI Agent. Owner duyệt ngày 2026-11-06.

# Kết luận

**PASS.** Owner duyệt ADR-0026 và database docs ngày 2026-11-06. Không vi phạm Guardrails. Không có rủi ro mức HIGH ở
tầng dữ liệu; phần route và menu mới phải rà HIGH khi triển khai theo
[LF-Regression-Audit](LF-Regression-Audit.md).

# Kiểm tra

| Mục | Kết quả |
|---|---|
| Cô lập tenant | Mọi bảng mới có `customer_id`; mọi khoá ngoại nội bộ là cặp `(id, customer_id)`; `rubrics` có `UNIQUE (id, customer_id)` làm đích. Câu hỏi không gắn được rubric của tenant khác ở cả database lẫn service. |
| Snapshot | Chấm và xem lại đọc `rubric_snapshot` trong phiên bản đề; không đọc lại `rubrics`. Sửa, lưu trữ rubric không đổi phiên bản đã có. Đúng Snapshot Principle; chỉ đổi thời điểm chụp so với ADR-0003 (có ghi trong ADR-0026). |
| Dữ liệu cũ | Ba cột mới NULL được, không cần chuyển dữ liệu; phiên bản và bài chấm cũ chấm như trước. |
| Tính điểm | `score` vẫn là điểm câu, `0 ≤ score ≤ max_score` (tổng tiêu chí ≤ `total_points`). Tổng bài, danh sách kết quả, thống kê không đổi. |
| Xoá | Rubric đang gắn câu: `RESTRICT`. Tiêu chí: `CASCADE` theo rubric (phần con nguồn soạn thảo), không bảng nào tham chiếu tiêu chí. |
| Quyền | Admin cả tenant; giáo viên sửa của mình, dùng của mình + của admin. Dựa trên `users.role` hiện tại của người tạo; không thêm cờ chia sẻ (chia sẻ giữa giáo viên vẫn là giai đoạn 2 của ADR-0025). |
| Học viên | Snapshot rubric không chứa đáp án, gửi được; điểm tiêu chí theo `show_result_mode` như điểm câu. |
| MariaDB 11.4 | CHECK không tham chiếu cột `AUTO_INCREMENT` (lỗi 1901). Cột JSON tự có `json_valid`. Khoá ngoại kép tự tạo index nếu thiếu, như mẫu `category_id`. |

# Điểm cần lưu ý khi triển khai

1. Đổi dạng câu từ tự luận / nói sang dạng khác: service bỏ `rubric_id` trước
   khi lưu, nếu không CHECK sẽ chặn.
2. Xuất bản đề: chụp rubric trong cùng transaction với snapshot câu; câu gắn
   rubric đã lưu trữ vẫn được chụp.
3. Người tạo rubric bị đổi khỏi vai trò admin: giáo viên không chọn mới được
   rubric đó; câu đã gắn giữ nguyên.
4. Nhập / Xuất Excel câu hỏi không có cột rubric ở giai đoạn này.
5. Test phải chạy thêm migration trên MariaDB 11.4 (SQLite bỏ qua CHECK).

# Còn mở

Không có câu hỏi kiến trúc nào còn mở.
