# LF Assessment Quiz Grader Bulk Proposal

Version: 0.2

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-07

Document Path: quality/LF-Assessment-Quiz-Grader-Bulk-Proposal.md

---

# Mục đích

Đề xuất để Owner duyệt: gom việc **giao chấm** về một chỗ là danh sách
**Đề / Bài tập**, giao theo đề (một hay nhiều đề một lần), bỏ giao chấm theo
danh mục và bỏ giao từng bài ở Kết quả. Owner nêu hướng này ngày 2026-11-07
("bỏ giao chấm trong danh mục, bỏ giao chấm trong kết quả, chỉ giao chấm ở danh
sách Đề/Bài thi … giao 1 hay nhiều, hoặc lọc theo danh mục giao 1 loạt") và
đồng ý phần chi tiết bên dưới ("ok update").

Quyết định nằm ở
[ADR-0027 Amendment 1.2](../adr/ADR-0027-Assessment-Grader-Assignment-And-Essay-Files.md)
(Approved). Owner duyệt ngày 2026-11-07 ("Duyệt, có chép"): chép gán danh mục
sang gán đề trước khi bỏ.

---

# Hiện trạng (ADR-0027 D2–D4, Amendment 1.1)

Ba chỗ giao chấm:

| Chỗ | Ai | Gán gì |
| --- | --- | --- |
| Danh mục → popup giáo viên chấm | Admin | Giáo viên chấm mọi đề của danh mục và danh mục con |
| Trang đề → tab Người chấm → Giao chấm đề | Admin, người tạo đề | Giáo viên chấm mọi bài của một đề |
| Kết quả & Chấm bài → chọn bài → Giao chấm; trang bài làm | Admin, người tạo đề | Một giáo viên giữ một bài cụ thể |

Cộng thêm Nhận chấm / Trả lại và tự nhận khi lưu điểm (Amendment 1.1). Owner
thấy quá nhiều chỗ, khó nhớ ai được chấm vì đâu.

Dữ liệu demo ngày 2026-11-07: 0 dòng gán theo danh mục, 0 dòng gán theo đề,
4 lần giao từng bài (`assigned`).

---

# Đề xuất

## 1. Danh sách Đề / Bài tập: nút "Giao chấm"

* Ô chọn đầu dòng đã có (dùng cho Xoá). Thêm nút **Giao chấm** trên thanh công
  cụ, sáng khi có ít nhất một đề được chọn.
* Giao cả loạt: lọc theo danh mục (bộ lọc đã có), tick "chọn tất cả", bấm Giao
  chấm. Chọn tất cả chỉ lấy các đề trên trang đang xem, như Xoá.
* Popup giống popup Giao chấm của Kết quả: ô tìm, gợi ý giáo viên ít việc lên
  trước (số bài đang giữ chưa chấm xong), chọn **nhiều** giáo viên.
* Hai cách lưu: **Thêm vào** (mặc định: giữ giáo viên đã có trên mỗi đề) và
  **Thay thế** (đặt lại đúng danh sách vừa chọn; chọn rỗng + Thay thế = gỡ hết).
* Người tạo đề chỉ giao đề của mình; chọn lẫn đề khác thì đề đó bị bỏ qua và
  popup báo "Đã giao N đề, bỏ qua M đề" như Kết quả đang làm. Admin giao mọi đề.
* Cột mới **Người chấm**: số giáo viên của đề; đề chưa có ai hiện cảnh báo
  "Chưa có" (chỉ admin và người tạo đề chấm được) để không bỏ sót.

## 2. Trang đề, tab Người chấm

* Giữ bảng "ai chấm được, vì sao" và tooltip vai trò.
* Nút **Giao chấm đề** mở cùng popup ở mục 1, áp cho một đề (sẵn danh sách
  hiện tại, mặc định Thay thế để sửa trực tiếp).
* Bỏ dòng "Nguồn: từ danh mục".

## 3. Bỏ

* Popup và cột giáo viên chấm ở trang **Danh mục**; route
  `categories.graders.edit|update`.
* **Giao chấm từng bài** ở Kết quả (nút trên thanh công cụ, popup, nút trên
  trang bài làm); route `results.assign.create|store`. Bộ lọc "Người chấm" và
  cột "Người chấm" giữ nguyên (vẫn cần cho Nhận chấm).
* Quyền tầng 2 theo danh mục trong `AssessmentGradingService::scoped` và
  `AssessmentGraderService::coverage`.

## 4. Giữ

* **Nhận chấm**, **Trả lại**, **tự nhận khi lưu điểm** (Amendment 1.1): nhiều
  giáo viên cùng chấm một đề tự chia bài, không chấm trùng.
* **Admin và người tạo đề vẫn bấm Trả lại** trên bài đang có người giữ, để gỡ
  bài khỏi giáo viên nghỉ cho người khác nhận.
* Tầng 1 (admin, người tạo đề, người cấp đề) không đổi.

---

# Điểm phải chấp nhận

* Đề tạo sau **không tự có** giáo viên chấm như khi gán theo danh mục; cột cảnh
  báo "Chưa có" bù chỗ này.
* Admin không chỉ định một bài cụ thể cho một người nữa; chỉ Trả lại để người
  khác nhận.
* Lần giao từng bài đang `active` (4 dòng demo) vẫn có hiệu lực tới khi chấm
  xong hoặc Trả lại; lịch sử giữ nguyên.

---

# Dữ liệu và schema

* Không migration ở bước này. `core_assessment_grader_scopes.category_id` và
  `assignment_type = assigned` không còn được ghi mới; giữ để đọc lịch sử.
* Dòng gán theo danh mục đang có (0 trên demo; tenant thật cần đếm trước khi
  phát hành): **chép thành dòng gán theo đề** cho mọi đề của danh mục và danh
  mục con, bằng lệnh một lần có báo số dòng, rồi mới tắt quyền theo danh mục.
  Không chép thì giáo viên mất quyền chấm âm thầm.
* Dọn cột / CHECK không dùng là việc sau, cần database docs riêng.

---

# Rà hồi quy (LF-Regression-Audit)

Audit Level: **HIGH** — đổi mô hình quyền chấm (bỏ tầng 2 theo danh mục), bỏ
route, sửa ADR đã duyệt, có thể chép dữ liệu quyền.

| Rủi ro | Cách giữ an toàn | Kiểm thử |
| --- | --- | --- |
| Giáo viên mất quyền chấm âm thầm | Chép gán danh mục → gán đề trước khi tắt; báo số dòng | Test: có gán danh mục → sau lệnh chép, giáo viên vẫn mở được bài |
| Giao lố đề của người khác | Lọc `managesQuiz` từng đề trong giao dịch; báo bỏ qua | Test: người tạo đề chọn lẫn đề admin → chỉ đề mình được giao |
| Lộ chéo tenant | Mọi truy vấn theo `customer_id`; giáo viên phải cùng tenant, đang hoạt động | Test tenant khác → 404 / 422 |
| Thay thế xoá nhầm | Thay thế chỉ trên đề được chọn; Thêm vào là mặc định | Test hai chế độ |
| Route cũ còn bị gọi | Bỏ route, bỏ nút; link cũ trả 404 | Test 404 cho `categories/{id}/graders`, `results/assign` |
| Nhận chấm, tự nhận, Trả lại hỏng | Không đổi code phần này | Giữ test hiện có |
| Menu, điều hướng | Không đổi menu; chỉ đổi thanh công cụ danh sách đề | Xem trình duyệt admin + giáo viên, desktop + mobile |

Lệnh tối thiểu sau khi làm: `php artisan test` toàn bộ, `php artisan docs:lint`,
xem trình duyệt bằng admin và teacher1 (chặn submit khi thử).

---

# Việc cần Owner duyệt

1. ADR-0027 Amendment 1.2 như trên.
2. Chép gán danh mục sang gán đề trước khi bỏ (khuyến nghị) hay bỏ thẳng.

---

# Đã làm (2026-11-07)

* `AssessmentGraderService`: `setQuizGraders` (nhiều đề, `add` / `replace`),
  `graderCounts`, `suggestions` theo đề, `copyCategoryScopes`; bỏ quyền và
  hàm theo danh mục, bỏ `assign`.
* Lệnh `php artisan assessment:copy-category-graders [--dry-run] [--customer=]`.
* Route mới `quizzes.graders.create|store` (`/quizzes/graders`); bỏ
  `categories.graders.*`, `quizzes.graders.edit|update` theo từng đề,
  `results.assign.*`.
* Danh sách đề: nút Giao chấm, cột Người chấm; đề đã xuất bản chọn được để giao
  chấm nhưng nút Xoá tắt (`data-ui-bulk-no-delete`).
* Test: `AssessmentGraderAssignmentTest` viết lại theo luồng mới; test JS chọn
  nhiều thêm ca `data-ui-bulk-no-delete`.
