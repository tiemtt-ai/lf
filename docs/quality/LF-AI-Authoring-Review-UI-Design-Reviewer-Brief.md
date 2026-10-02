# AI Authoring Review UI — Design Reviewer Brief

Version: 1.2

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-29

Document Path: quality/LF-AI-Authoring-Review-UI-Design-Reviewer-Brief.md

---

# Vì sao có brief này

Phần 3 của lộ trình Owner là giao diện để giáo viên và admin duyệt đề xuất AI. Owner
đã duyệt hướng thiết kế D1–D8 ngày 2026-09-29, nhưng đó **không phải review độc lập**.
Theo AGENTS.md, thay đổi UI, phân quyền và route cần được review trước khi code. Brief
này giao review **thiết kế** của UI, chưa phải code.

Tài liệu cần review: [LF-AI-Authoring-Review-UI-Design](../platform/LF-AI-Authoring-Review-UI-Design.md)
v0.2. Nó không thêm domain, bảng hay endpoint nào; toàn bộ hành vi backend nằm trong
[LF-AI-Authoring-Proposal-Contract](../platform/LF-AI-Authoring-Proposal-Contract.md)
(Frozen, Owner nghiệm thu backend + HTTP 2026-09-17 dưới miễn trừ review độc lập).

Verdict yêu cầu: `APPROVE`, `APPROVE WITH CHANGES` hoặc `REJECT`, cho từng câu hỏi và
cho toàn thiết kế; riêng D1–D8 cho biết đồng ý hay đề nghị đổi.

---

# Điều reviewer phải tự kiểm chứng, không dựa vào thiết kế

Thiết kế này nêu nhiều khẳng định về code hiện có. Tự đọc và đối chiếu, đừng tin:

| Khẳng định trong thiết kế | Nơi kiểm |
| --- | --- |
| Mọi endpoint nằm dưới `…/course-templates/{templateId}/activities/{activityId}/ai-authoring/…`; tổng 41 route (18 endpoint dùng chung ở cả hai prefix admin và teacher, cộng 5 endpoint chỉ admin ở prefix admin: 18×2 + 5) | `routes/modules/ai-authoring.php`, `routes/web.php` (cờ `$registerCourseTemplateLifecycleRoutes`), `php artisan route:list --path=ai-authoring` |
| `allowed_actions` chỉ có giá trị khi `pending_review` | `AiAuthoringProposalService.php:387`, `AiAuthoringHttpReadService.php:78` |
| `competency` và `node_mapping` mới cần Framework; Framework phải đúng bản Template đang chọn | `AiAuthoringProposalService::generate` (dòng 62–85) |
| Cổng provider từ chối = HTTP 409 với mã `AI_*`, `details` rỗng, `blocked_at` không lộ ra | `AiAuthoringController::response()` (dòng 117–146), `AiAuthoringProposalService.php:108,194–226` |
| Danh sách chỉ có metadata, không có tiêu đề hay đoạn trích | Hợp đồng § Response and error mapping; `AiAuthoringHttpReadService` |
| `resources/views` hiện không dùng Livewire; partial learning-mappings có chữ Việt cứng | `resources/views`, `course-templates/partials/learning-mappings.blade.php` |

---

# Câu hỏi

1. **Vị trí và phạm vi (D1, D8).** Gắn vào Activity của Template có đúng và đủ không?
   Có màn hình nào người dùng cần mà thiết kế bỏ sót vì backend gắn theo Activity
   (ví dụ nhìn tất cả đề xuất đang chờ của một Template)? Trang Learning mapping chỉ
   đọc có đủ, hay tạo hai đường vào gây nhầm lẫn?
2. **Đúng với hợp đồng.** §3 (sáu bất biến) và §4 có mâu thuẫn hay bỏ sót điều gì
   trong hợp đồng Bước 7? Đặc biệt: kiểm quyền Media trước khi lộ nội dung (và điều gì
   hiện ra khi ghi audit thất bại lúc xem chi tiết), stale/context/target, successor
   kế thừa "chỉ quyết định", retry theo vai trò.
3. **Vai trò và phân quyền.** Bảng vai trò §2 có đúng không (teacher primary, assistant,
   reviewer; admin-only). UI ẩn/hiện nút có tạo cảm giác quyền sai không? Có đường nào
   để UI tin `allowed_actions` hoặc trạng thái thay cho service?
4. **`allowed_actions` (D3).** Xác nhận khoảng trống và đánh giá hướng mở rộng ở
   backend so với để UI tự suy. Mở rộng có nguy cơ biến `allowed_actions` thành thứ
   giống capability token không (hợp đồng nói nó chỉ là gợi ý)? Có tác động đến hợp
   đồng Frozen không, và cần amendment hay chỉ sửa code?
5. **An toàn giao diện.** XSS từ nội dung AI/Media (hiển thị văn bản thuần), CSRF,
   `Cache-Control: no-store` và bộ nhớ trình duyệt, lộ nội dung qua URL/console/log,
   hết phiên giữa lúc sửa (mất bản nháp?), nhiều tab, gửi đúp.
6. **Idempotency và xung đột.** UI dùng `request_id` sinh ở trình duyệt: khi mất mạng
   thì thử lại với cùng UUID và cùng nội dung, khi đổi nội dung thì UUID mới. Thiết kế
   đã nói đủ chưa? Xử lý 409 (đổi phiên bản, stale) và hàng loạt có kết quả từng dòng
   có rõ ràng không?
7. **Provider chưa bật (D5, D6).** Hiển thị nút tạo khi AI chưa bật, và nhận diện lỗi
   cổng theo tiền tố `AI_` có ổn không, hay lộ ra thông tin không nên lộ? Kế hoạch dữ
   liệu mẫu bằng provider giả có bảo đảm không gọi mạng, không cần secret, không lọt
   sang production không?
8. **Chia giai đoạn (D2).** P3-A độc lập thật với thay đổi backend không? Thứ tự và
   ranh giới P3-A/B/C có hợp lý? Có phụ thuộc ngầm nào bị bỏ sót?
9. **Chuẩn giao diện, i18n, truy cập.** Bám LF-Admin-Form-Design-Standard đủ chưa; i18n
   VI/EN; truy cập bàn phím và đọc màn hình cho các trạng thái động (kết quả bulk, lỗi,
   trạng thái tải).
10. **Kế hoạch kiểm chứng và mức audit (§7).** Đủ chưa? Mức audit HIGH theo
    LF-Regression-Audit có hợp lý? Thiếu ca kiểm nào?
11. **D4 (Blade + JS, không Livewire).** Có lý do kỹ thuật nào để chọn khác không?
12. **Đã kiểm chưa kiểm.** Liệt kê điều thiết kế còn hứa hẹn mà chưa kiểm được, hoặc
    phụ thuộc mockup chưa có.

---

# Ràng buộc độc lập

* **Không đủ tư cách:** session implementer của Bước 7 (backend/HTTP) và của Phần 2, và
  tác giả bản thiết kế này.
* **Đủ tư cách:** reviewer chưa sửa code hay tài liệu canonical của AI Authoring và của
  thiết kế này, kể cả reviewer đã review K3 và closure Phần 2 nếu họ chưa từng sửa
  chúng.
* Reviewer **không vá**; finding giao implementer. Reviewer có thể đề nghị đổi bất kỳ
  quyết định nào trong D1–D8.

# Ràng buộc an toàn

* Chỉ đọc. Không sửa code, test, migration, tài liệu canonical. Báo cáo ở
  `docs/quality/LF-AI-Authoring-Review-UI-Design-Review.md`.
* **Không kết nối `learnforge_db`** (`127.0.0.1:3307`) và XAMPP `:3306`.
* Nếu cần chạy code (ví dụ `route:list`, đọc lớp bằng reflection), dùng bản sao riêng
  hoặc lệnh không ghi; nếu cần database thì MariaDB 11.4 dùng một lần trong `/tmp`,
  `--no-defaults --skip-networking`, tên database bắt đầu `lf_`, tắt và xoá khi xong.
* Không gọi provider thật, không thêm secret, không gửi dữ liệu ra mạng.

# Snapshot

Ghi SHA-256 lúc bắt đầu và cuối lượt của các file sau (Owner cung cấp danh sách khi bàn
giao):

* `docs/platform/LF-AI-Authoring-Review-UI-Design.md`
* `docs/platform/LF-AI-Authoring-Proposal-Contract.md`
* `routes/modules/ai-authoring.php`
* `app/Http/Controllers/AiAuthoringController.php`
* `app/Services/AiAuthoringProposalService.php`
* `app/Services/AiAuthoringHttpReadService.php`

# Định dạng báo cáo

* Header chuẩn, reviewer, snapshot, ngày.
* Verdict từng câu hỏi 1–12 và từng quyết định D1–D8, kèm bằng chứng của chính
  reviewer.
* Findings `BLOCKER | HIGH | MEDIUM | LOW`: vị trí, tình huống, bằng chứng, đề xuất
  (không vá).
* Điều kiện để bắt đầu P3-A, nếu `APPROVE WITH CHANGES`.
* Bảng lệnh đã chạy và mục chưa kiểm.

---

# Lượt 2 — sau review lượt 1 (2026-09-29)

[Review lượt 1](LF-AI-Authoring-Review-UI-Design-Review.md): **APPROVE WITH CHANGES**,
cổng P3-A chưa mở. Tác giả đã sửa thiết kế lên v0.3; bảng đối chiếu từng finding
ở §11 của thiết kế. Owner chưa duyệt phần điều chỉnh D2, D7 và D9 (mới).

Phạm vi lượt 2:

1. Kiểm lại từng finding P3-UI-R1…R8 trên v0.3, đối chiếu với source; nêu finding nào
   đóng, mở, hoặc phát sinh mới.
2. Kiểm ba điều mà tác giả tự xác minh lại từ code khi sửa (không dựa vào tác giả):
   `competency` không có nhánh ánh xạ; `reject-target` chỉ dùng được khi Intent đã
   `applied`; chọn Framework và tab Mapping chỉ dành cho admin, nên giáo viên không có
   đường chọn Framework.
3. Đánh giá quyết định thu hẹp P3-A (D2) và tách DTO tên/ứng viên Node sang P3-B (D9)
   có hợp lý hơn phương án duyệt DTO trước P3-A không.
4. Xác nhận bảng lỗi §4.6 đủ toàn bộ mã `AI_*` trong mã nguồn (tác giả tìm được 10 mã).
5. Kết luận các điều kiện trước P3-A ở §6 của báo cáo lượt 1 đã đóng hay chưa, thêm mục
   "Lượt 2" vào báo cáo, giữ nguyên lượt 1.

---

# Lượt 3 — sau review lượt 2 (2026-09-29)

[Lượt 2](LF-AI-Authoring-Review-UI-Design-Review.md) (§8): **APPROVE WITH CHANGES**,
đóng R1–R4, R6, R8; R5 và R7 còn hai phần MEDIUM. Tác giả sửa thiết kế lên v0.4:

* R5: §8.4 tách URL HTML của liên kết ngược (route Course theo cây bài học/section) khỏi
  URL JSON của API AI; nếu chưa dựng được thì hoãn D8 khỏi P3-A.
* R7: §4.6 đổi thông điệp `AI_QUOTA_EXCEEDED` thành trung tính và chuyển
  `AI_QUOTA_RESERVATION_EXCEEDED` sang nhóm "sau khi đã gọi provider"; thêm yêu cầu test
  ba ca.
* Cộng thêm ba điểm reviewer nhắc: guard L+R và cặp Framework của duyệt Node; UI rẽ nhánh
  theo trạng thái receipt, không theo chuỗi `use_cancel_application`; Generate
  `node_mapping` có thể trả `reuse_existing` nên UI phải nhận và hoãn.

Phạm vi lượt 3 (hẹp): kiểm hai sửa trên có đóng R5 và R7 không, và ba điểm bổ sung có
đúng với source không. Kết luận điều kiện trước P3-A còn lại là gì. Thêm mục "Lượt 3" vào
báo cáo, giữ nguyên lượt 1–2. Các bổ sung D1/D3/D5/D6/D8 vẫn cần Owner xác nhận riêng;
reviewer không suy phê duyệt.
