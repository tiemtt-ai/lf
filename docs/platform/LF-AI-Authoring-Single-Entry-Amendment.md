# AI Authoring — Single Entry Amendment

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-10-05

Document Path: platform/LF-AI-Authoring-Single-Entry-Amendment.md

Related Specification:

* [LF-AI-Authoring-Review-UI-Design](LF-AI-Authoring-Review-UI-Design.md) (§2, §8.4, D1, D8, D11)
* [LF-AI-Authoring-P3B-Amendment](LF-AI-Authoring-P3B-Amendment.md)
* [LF-AI-Authoring-P3C-Amendment](LF-AI-Authoring-P3C-Amendment.md)
* [LF-AI-Authoring-Proposal-Contract](LF-AI-Authoring-Proposal-Contract.md) (Frozen)
* [LF-UI-Async-State-Engineering-Practice](../quality/LF-UI-Async-State-Engineering-Practice.md)

---

# 1. Trạng thái và cách đọc

Owner yêu cầu (2026-10-05): trong tab **Nội dung** của Khoá học mẫu **không gắn đề xuất AI**; mọi đề xuất AI chỉ thực hiện ở **một nơi: tab "Đầu ra & năng lực"** (`LF_learning_frameworks`). Cùng ngày Owner duyệt Lựa chọn A và D28–D31 (mục 6) và yêu cầu làm luôn; S1–S4 đã làm và kiểm cục bộ (mục 5).

Amendment này **thay thế D1 và D11** (và phần "liên kết ngược về Hoạt động" của D8) của thiết kế Phần 3. Không đổi hợp đồng Frozen, endpoint, bảng hay migration. Chưa qua review độc lập (cùng miễn trừ với P3-B/P3-C).

# 2. Đã làm

* Gỡ liên kết "Đề xuất AI" (`data-ai-authoring-entry`, D11) khỏi dòng Hoạt động; bỏ `aiAuthoringAvailable` / `aiMediaActivityTypes` khỏi `CourseTemplateController@edit`.
* Dựng cấu hình mục AI ở một service dùng chung `AiAuthoringEntryService` (trước đây là `CourseTemplateActivityController::aiAuthoringEntry`).
* Tab "Đầu ra & năng lực" hiện khi người dùng là admin **hoặc** có quyền AI; mục AI ở partial `course-templates/partials/ai-authoring-entry.blade.php`, tái dùng nguyên partial `course-template-activities/partials/ai-authoring.blade.php` và `ai-authoring.js`.
* Trang chi tiết Hoạt động không còn mục AI.

# 3. Sự thật đã kiểm trong mã

| Điều | Nguồn | Hệ quả |
| --- | --- | --- |
| Mọi endpoint nằm dưới `course-templates/{templateId}/activities/{activityId}/ai-authoring/…` (41 route) | `routes/modules/ai-authoring.php` | Một đề xuất luôn thuộc **một Hoạt động**. Tab cấp Template phải **chọn Hoạt động** rồi gọi endpoint của nó; không có hàng đợi theo Template |
| Tab "Đầu ra & năng lực" chỉ có khi `role === 'customer_admin'` | `CourseTemplateController@edit` (`$learningMappingState`), `edit.blade.php` | **Giáo viên có quyền AI (primary/assistant/reviewer) không có tab này.** Đưa AI về tab đó mà không đổi thì giáo viên mất toàn bộ quyền dùng AI |
| Giao diện đề xuất là một partial + một script (`ai-authoring.blade.php`, `resources/js/ai-authoring.js`, ~2.9k dòng), cấu hình qua `aiAuthoringEntry()` của `CourseTemplateActivityController` gắn với **một** `activityId` | các file trên | Có thể dùng lại nguyên khối; chỉ đổi nơi mount và cách chọn `activityId` |
| Tab đã chứa Framework, Mapping Intent, bản nháp, rebase thủ công | `partials/learning-mappings.blade.php` | Đúng nơi cho kết quả sau khi duyệt (Node, Intent, rebase) |
| Quyền AI khác quyền mở trang (§2.1 thiết kế) | `CourseAuthoringContextService::authoringRole` | Giữ nguyên: hiện theo quyền AI, không theo quyền vào trang |

# 4. Các lựa chọn

## Lựa chọn A — Mount cùng giao diện trong tab, chọn Hoạt động bằng bộ chọn (đề xuất)

* Trong tab "Đầu ra & năng lực", thêm mục **"Đề xuất AI"**: một bộ chọn Hoạt động liệt kê các Hoạt động có Media (`video`, `audio`, `document`) của Template (nhóm theo Bài học), mặc định rỗng. Chọn xong mới mount giao diện hiện có cho `activityId` đó.
* Đổi `activityId` là đổi ngữ cảnh: huỷ mọi request đang bay, bỏ bản sửa chưa gửi sau xác nhận (§4.1–4.2 thiết kế), dựng lại script. Đây là điểm async dễ lỗi nhất; phải theo Practice (bất biến trước, test đối kháng).
* **Quyền:** tab cần hiện cho **mọi người có quyền AI**, không chỉ admin. Với giáo viên, tab chỉ chứa mục "Đề xuất AI" (không có chọn Framework, Intent, kế thừa/rebase là việc admin). Cần mở tab cho giáo viên có quyền AI trong `CourseTemplateController@edit` và route prefix teacher.
* Trang chi tiết Hoạt động: **bỏ** mục "Đề xuất AI" (hoặc chỉ giữ ghi chú trỏ về tab), để chỉ còn một nơi.
* Ưu: một nơi duy nhất như Owner muốn; tái dùng toàn bộ logic đã nghiệm thu. Nhược: phải thêm bộ chọn Hoạt động và xử lý đổi ngữ cảnh.

## Lựa chọn B — Danh sách đề xuất theo Template trong tab

Gộp đề xuất của mọi Hoạt động thành một hàng đợi. **Không khuyến nghị:** thiết kế §2 cấm dựng hàng đợi bằng cách duyệt lần lượt mọi Hoạt động, và backend không có endpoint theo Template; cần amendment hợp đồng Frozen.

## Lựa chọn C — Chỉ gỡ lối vào (trạng thái sau bước 1)

Không đủ: không còn đường dùng AI từ giao diện.

**Đề xuất: Lựa chọn A.**

# 5. Lát cắt đã làm

```text
S1  Tab hiện cho admin hoặc người có quyền AI. Admin: Framework/Intent như cũ + mục AI. Giáo viên có quyền AI: chỉ mục AI
    (không render phần admin). Người chỉ mở được trang (assignment "teacher", observer, người tạo không có assignment,
    assignment đã kết thúc) không thấy tab.
S2  Mục "Đề xuất AI": bộ chọn Hoạt động có Media (video, audio, document), nhóm theo Bài học; chỉ nhận id thuộc đúng Template
    và đúng loại Media — id khác, của Template khác, không phải số, hay loại quiz/link đều không mở gì.
S3  Đổi Hoạt động = tải lại trang (GET ?tab=learning&ai_activity=ID#ai-authoring-entry). Chọn cách này thay vì mount lại bằng JS:
    mỗi lần tải, script được dựng cho đúng một Hoạt động nên không có request đang bay, bản sửa chưa gửi hay trạng thái
    rò giữa hai Hoạt động (rủi ro async của mục 7 bị loại từ gốc). Bản sửa chưa gửi mất khi đổi Hoạt động, như khi rời trang.
S4  Bỏ mục AI khỏi trang chi tiết Hoạt động. Nút "Mở hoạt động" ở danh sách Mapping vẫn trỏ về trang Hoạt động (trang đó
    vẫn tồn tại cho việc khác); nút "Xem trong Đầu ra & năng lực" trong script vẫn trỏ về tab.
```

Test: `AiAuthoringActivityEntryTest` (không dòng nào có liên kết; trang Hoạt động không có mục AI; bộ chọn chỉ liệt kê Media; chọn một Hoạt động dựng đúng URL của nó; lựa chọn sai không mở gì; Template không có Media; giáo viên có/không có quyền AI), `AiAuthoringHostSectionTest` (quyền, Framework, cấu hình, văn bản) đã chuyển sang trang tab. `CourseTemplateLearningMappingHttpMariaDbTest` đã chuyển helper sang trang tab nhưng **bị bỏ qua** khi không có MariaDB thử nghiệm (27 skipped), chưa chạy được ở đây.

# 6. Quyết định Owner

| # | Nội dung | Trạng thái |
| --- | --- | --- |
| D28 | Chọn Lựa chọn A: bộ chọn Hoạt động trong tab "Đầu ra & năng lực" | Owner duyệt 2026-10-05 |
| D29 | Mở tab cho giáo viên có quyền AI, chỉ chứa mục "Đề xuất AI" | Owner duyệt 2026-10-05 |
| D30 | Bỏ hẳn mục AI khỏi trang chi tiết Hoạt động | Owner duyệt 2026-10-05 |
| D31 | Thay D11 bằng bộ chọn Hoạt động; không có lối vào trên dòng Hoạt động | Owner duyệt 2026-10-05 |

# 7. Rủi ro

* Đổi Hoạt động giữa chừng: đã loại bằng tải lại trang (S3); đổi lại sang mount động sẽ phải theo Practice và test đối kháng.
* Tab hiện cho giáo viên: phần admin không được render (test kiểm không có form chọn Framework trong HTML).
* Template chưa chọn Framework: các lệnh phụ thuộc Framework đã có thông báo riêng (`LF_ai_authoring_approve_node_unavailable`); giữ nguyên, nhưng vị trí liên kết "chọn Framework" nay nằm cùng tab.
* Chưa review độc lập cho P3-B/C (miễn trừ Owner); amendment này không đổi điều đó.

---

# Owner

Architecture Team

# Primary Consumers

* Backend Developers
* Frontend Developers
* Course authors (giáo viên, admin)
