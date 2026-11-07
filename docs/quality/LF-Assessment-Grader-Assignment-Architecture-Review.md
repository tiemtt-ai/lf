# LF Assessment Grader Assignment Architecture Review

Version: 0.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-07

Document Path: quality/LF-Assessment-Grader-Assignment-Architecture-Review.md

---

# Phạm vi

Rà [ADR-0027](../adr/ADR-0027-Assessment-Grader-Assignment-And-Essay-Files.md)
và database docs trước khi Owner duyệt và tạo migration:

* bảng mới [core_assessment_grader_scopes](../database/assessment/core_assessment_grader_scopes.md);
* bỏ hoãn, thu gọn [core_assessment_grading_assignments](../database/assessment/core_assessment_grading_assignments.md);
* cột mới `allow_file_answer` ở [core_assessment_questions](../database/assessment/core_assessment_questions.md);
* khoá snapshot mới ở [core_assessment_quiz_version_questions](../database/assessment/core_assessment_quiz_version_questions.md);
* đổi ràng buộc [core_assessment_answer_files](../database/assessment/core_assessment_answer_files.md).

Nguồn so sánh: question_bank (`SubmitController@submit`, `markAnswerWriting`):
tự chọn giáo viên "rảnh", có thể lấy giáo viên ngoài trung tâm; tệp bài viết
gắn theo thứ tự gửi.

Người rà: AI Agent. Owner duyệt ngày 2026-11-07.

# Kết luận

**PASS.** Owner duyệt ADR-0027 và database docs ngày 2026-11-07. Không vi phạm Guardrails. Rủi ro chính nằm ở tầng quyền đọc
dữ liệu học viên (D1), không ở schema; triển khai phải rà HIGH theo
[LF-Regression-Audit](LF-Regression-Audit.md).

# Kiểm tra

| Mục | Kết quả |
|---|---|
| Cô lập tenant | Hai bảng mới có `customer_id`; khoá ngoại tới danh mục, đề, bài làm là cặp `(id, customer_id)`. Khoá ngoại tới `users` chỉ theo `id` (như rubric, gradings) nên service phải kiểm `users.customer_id` và vai trò `teacher` khi gắn / giao. Không lấy giáo viên ngoài tenant như question_bank. |
| Vai trò | Không thêm vai trò; vẫn `customer_admin`, `teacher`, `student` qua middleware hiện có. Gắn theo danh mục chỉ admin; gắn theo đề và giao bài: admin, người tạo đề (chỉ đề của mình, service kiểm `quizzes.created_by`); nhận / trả lại là giáo viên. |
| Quyền đọc | Mở rộng người xem bài làm (tầng 2–3). Mọi chỗ đọc bài (danh sách, đếm Chờ chấm, xuất Excel, Thống kê, trang bài làm, URL ghi âm / tệp) phải dùng một hàm phạm vi chung; thiếu một chỗ là lộ hoặc thiếu dữ liệu. Có test cho từng chỗ. |
| Snapshot | `allow_file_answer` chụp vào `question_snapshot` khi xuất bản; làm bài và chấm đọc snapshot. Quyền chấm theo dữ liệu hiện tại, không phải snapshot: là quyền truy cập, không phải nội dung đề. |
| Trạng thái | Assignment `active` → `released` / `completed`, `ended_at` theo CHECK. "Một active mỗi bài": cột sinh `active_flag` + unique (như `gradings.current_flag`), service khoá Attempt khi giao. Chấm cuối cùng đóng assignment trong cùng transaction. |
| Dữ liệu cũ | Không backfill: không có scope, không có assignment, `allow_file_answer = false`. Ghi âm cũ thoả unique mới vì mỗi câu một tệp. Đổi unique của `answer_files` cần drop / add index trong migration, có `down()` khôi phục (rollback được khi chưa có câu nào hơn một tệp). |
| PII | Tệp bài làm là dữ liệu học viên: không OCR / trích xuất / AI (ADR-0018), giao qua URL ký có hạn theo quyền xem bài, không hiện trong thư viện Media. |
| Kiểm schema | Chạy migration trên MariaDB 11.4 tạm (SQLite bỏ qua CHECK), thử CHECK "đúng một phạm vi", CHECK `allow_file_answer` chỉ cho essay, CHECK `ended_at`, unique mới của `answer_files`. |

# Rủi ro còn lại

* Giáo viên tầng 2 thấy học viên ngoài lớp mình trong danh mục được gắn: chủ ý,
  ghi trong ADR-0027 Consequences.
* Gỡ giáo viên khỏi danh mục khi họ đang giữ bài: bài vẫn giữ tới khi admin đổi
  (D2), nên vẫn chấm được bài đó; trang hiện tên người giữ để admin thấy.
* Gợi ý "ít việc" trong popup đếm bài đang giữ chưa chấm xong; cần index
  `(customer_id, assigned_to_user_id, status)` (đã có).
