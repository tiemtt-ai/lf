# LF Assessment Dynamic Quiz Proposal

Version: 0.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-07

Document Path: quality/LF-Assessment-Dynamic-Quiz-Proposal.md

---

# Mục đích

Đề xuất để Owner duyệt: **đề động** của module Kiểm tra theo cách "ghép khi
soạn" mà Owner chọn ngày 2026-11-07. Nội dung quyết định nằm ở
[ADR-0025 Amendment 1.2 (D6a)](../adr/ADR-0025-Assessment-Simple-Administration.md);
tài liệu này ghi phần tham khảo question_bank và lý do chọn. Owner duyệt
ngày 2026-11-07 ("Duyệt, làm luôn").

---

# question_bank làm thế nào

Nguồn: `htdocs/question_bank`, màn `frontend/vuejs/src/views/Exam/ModalAuto.vue`,
API `POST exam/random-question` (`GroupQuestionController@getAutoQuestion`).

1. Người soạn mở "tạo đề tự động" từ một danh mục, nhập thời gian, giá.
2. Điền bảng: hàng là kỹ năng (Reading, Listening, Writing, Speaking), cột là các
   độ khó; mỗi ô là số câu.
3. Bấm "Tạo": server lọc `group_questions` theo kỹ năng, độ khó, danh mục, trạng
   thái, rồi `inRandomOrder()->limit(n)` cho từng ô.
4. Màn hình hiện danh sách câu: sửa điểm từng câu, bỏ câu, hoặc bấm "Tạo" lại để
   bốc lại cả bộ. Không đủ câu thì chỉ hiện cảnh báo.
5. "Lưu" gửi danh sách ID câu và điểm, tạo một đề thường (`exams.question_id`).
   Học viên nào cũng làm cùng bộ câu đó.

Không nên chép:

* Hàng Speaking lấy số lượng của hàng Listening (lỗi).
* Lọc danh mục, kỹ năng, độ khó bằng `LIKE` trên chuỗi / JSON.
* Không kiểm quyền người soạn trên từng câu, không kiểm câu đã có trong đề.
* Thiếu câu chỉ cảnh báo ở trình duyệt; server không nói ô nào thiếu.

---

# Đối chiếu LF

| question_bank | LF hiện có |
| --- | --- |
| Danh mục (JSON trên câu) | `category_id`, cây danh mục (ADR-0025 D3) |
| Kỹ năng trong `type` | `skill_type`: reading, listening, writing, speaking, other |
| Bảng `difficulties` | `difficulty_level`: easy, medium, hard |
| `group_questions` có nhóm | Câu lẻ và ngữ liệu (`passage_id`, D4) |
| Lưu ID câu vào đề | Bản nháp `quiz_questions`, xuất bản thành phiên bản (D6) |

LF đã có đủ trường để làm bảng kỹ năng × độ khó; chỉ cần một bước bốc ngẫu
nhiên trả về bản xem trước, rồi thêm câu bằng đường "Thêm câu hỏi" có sẵn
(`AssessmentQuizDraftService::addQuestions`).

---

# Hai cách đã cân nhắc

**A. Ghép khi soạn (Owner chọn).** Không đổi schema; bản nháp, xuất bản,
snapshot, thống kê giữ nguyên. Bốc xong người soạn vẫn kiểm từng câu.

**B. Mỗi lượt làm một bộ câu khác.** Chống chép bài tốt hơn nhưng cần bảng luật
ghép và bảng câu theo lượt làm, đổi cách snapshot, Thống kê theo câu không còn
so được giữa học viên. Để sau, cần ADR riêng.

---

# Rủi ro và cách xử lý

* **Lộ câu ngoài quyền:** dùng đúng phạm vi `AssessmentQuestionService::scoped`
  như danh sách "Thêm câu hỏi"; xác nhận đi qua `addQuestions` kiểm lại.
* **Câu đổi trạng thái giữa xem trước và xác nhận:** `addQuestions` từ chối câu
  không còn `published`; bản xem trước không lưu gì.
* **Tenant lớn:** giới hạn 200 câu một lần; bốc theo từng ô trên tập đã lọc có
  index `(customer_id, skill_type, difficulty_level)`.
* **Câu chưa ghi kỹ năng / độ khó:** không bốc; giao diện nói rõ để người soạn
  bổ sung.

Kiểm thử dự kiến: bốc đúng số theo ô, không vượt quyền giáo viên, không lấy câu
đã có trong đề hay câu của ngữ liệu, báo ô thiếu, danh mục con được tính, xác
nhận thêm đúng điểm mặc định, tenant khác không thấy.
