# AI Authoring Review UI — Design (Phần 3)

Version: 0.8

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-10-01

Document Path: platform/LF-AI-Authoring-Review-UI-Design.md

Related Specification:

* [LF-AI-Authoring-Proposal-Contract](LF-AI-Authoring-Proposal-Contract.md)
* [LF-AI](LF-AI.md)
* [LF-Admin-Form-Design-Standard](../tech/LF-Admin-Form-Design-Standard.md)

---

# 1. Trạng thái và phạm vi

**Trạng thái hiện tại (2026-10-01):** P3-A, P3-B (B1–B4, B6) và P3-C (C1–C3) đã làm xong và đã kiểm cục bộ; xem §13.17 cho
những gì còn mở. P3-A có review độc lập (đóng bởi Owner, §13.8). **P3-B và P3-C chưa qua review độc lập: Owner quyết định không
gửi (2026-10-01), đó là miễn trừ chứ không phải PASS.** Các đoạn bên dưới ghi lại quá trình thiết kế và giữ nguyên.

Thiết kế đã qua review độc lập và được Owner duyệt đủ D1–D9 (§10) cho **phạm vi P3-A**.
P3-B và P3-C vẫn cần các amendment và review riêng (§7, §8). Bản v0.2 đã được review độc lập
([báo cáo](../quality/LF-AI-Authoring-Review-UI-Design-Review.md)): **APPROVE WITH
CHANGES**, 4 HIGH và 4 MEDIUM, và cổng bắt đầu P3-A chưa mở. Bản v0.3 này sửa theo báo
cáo đó (§11 ghi từng finding được xử lý ở đâu). Lượt 2 đóng 6 finding và giữ lại hai
phần MEDIUM (R5, R7); bản v0.4 xử lý hai phần đó và ba điểm reviewer nhắc thêm. Lượt 3 APPROVE v0.4, không còn
finding mở. Ngày 2026-09-29 Owner xác nhận các bổ sung còn lại (§10) nên P3-A được phép
bắt đầu.

UI dùng lại các endpoint của Bước 7 (41 route, Owner nghiệm thu backend + HTTP
2026-09-17 dưới miễn trừ review độc lập). Không thêm domain hay bảng. Hai chỗ **có thể**
cần thay đổi backend (mở rộng `allowed_actions`; dữ liệu tên Node) được tách ra §7 và
chỉ làm sau khi Owner duyệt amendment tương ứng.

Không thuộc phạm vi: trợ giảng/chat cho học viên, kích hoạt provider thật, xuất bản
Course hoặc Framework, Evidence/Mastery, mọi màn hình phía học viên.

---

# 2. UI gắn vào đâu

**Gắn vào Hoạt động (Activity) của Khoá học mẫu (Course Template), ở bản nháp làm
việc.** Đây là ràng buộc của backend: mọi endpoint nằm dưới

```text
/{admin|teacher}/course-templates/{templateId}/activities/{activityId}/ai-authoring/…
```

(`routes/modules/ai-authoring.php`; kiểm bằng `route:list`: 23 route admin + 18 route
teacher). Không có route theo tenant hay theo Template, nên **không có** hàng đợi "tất
cả đề xuất đang chờ". UI không được dựng hàng đợi đó bằng cách duyệt lần lượt mọi
Activity.

| Vị trí | Vai trò của UI |
| --- | --- |
| **Trang chi tiết Hoạt động** (`course-template-activities/show`, dùng chung admin/teacher) | Vị trí chính: mục "Đề xuất AI" |
| Trang Learning mapping của Template (chỉ admin thấy) | Không thêm thao tác duyệt. Chỉ thêm liên kết về Hoạt động (§8.4). Các nút thủ công hiện có (chọn Framework, thêm/gỡ Mapping) giữ nguyên |
| **Danh sách Hoạt động** của Template (tab Nội dung, mỗi dòng Hoạt động) | Lối vào (D11): liên kết "Đề xuất AI" mở trang chi tiết Hoạt động, đến neo `#ai-authoring`. Chỉ hiện cho Hoạt động **có Media** (`video`, `audio`, `document`) và người có quyền AI (§2.1) |
| Trang Version | Không thêm màn hình |

**Không gắn vào:** Version đã xuất bản, Cohort, trang học viên, menu điều hướng chính.

**Vì sao cần lối vào riêng (D11).** Nút **Xem** trên dòng Hoạt động chọn đích theo loại Hoạt động
(`CourseTemplateController`, `view_kind`): Hoạt động có Media đã xử lý mở **file**, có link ngoài
mở **link**, chỉ Hoạt động không có gì (thực tế là Bài kiểm tra) mới mở trang chi tiết. Nghĩa là
trang có mục AI *không với tới được* từ danh sách với chính những Hoạt động cần dùng nó. Nút Xem
giữ nguyên hành vi; lối vào AI là một liên kết riêng.

**Loại Hoạt động có nội dung cho AI đọc.** Đề xuất được sinh từ Media của Hoạt động (Media Read),
và `generate` trả `invalid_proposal` khi không có nguồn (`AiAuthoringProposalService`). Chỉ
`video`, `audio`, `document` gắn Media (`detachInactiveMedia`); `embedded_video` và `live_class`
chỉ giữ một link, `quiz` không có nội dung AI đọc được. Danh sách này nằm ở một chỗ
(`CourseAuthoringContextService::MEDIA_ACTIVITY_TYPES`). Với loại còn lại, trang chi tiết hiện
ghi chú "không có nội dung Media để AI phân tích" thay cho mục có form không thể thành công.

## 2.1. Hai loại quyền khác nhau

Trang Hoạt động mở được cho người có quyền rộng hơn quyền dùng AI: người tạo Template
hoặc mọi giáo viên đang được phân công đều vào được trang. Còn **AI chỉ nhận** giáo viên
đang active và có phân công primary, assistant hoặc reviewer hiện hành, hoặc admin
active của tenant (`CourseAuthoringContextService`, `ASSIGNMENT_ROLES`).

Vì vậy mục "Đề xuất AI" chỉ được render khi controller của trang xác nhận **quyền AI**
bằng chính service đó; thấy trang Activity hay có route đăng ký đều không đủ. Nếu không
có quyền AI, mục này không hiện (không thông báo gì khác).

| Vai trò | Được làm |
| --- | --- |
| Giáo viên có quyền AI trên Template | Tạo yêu cầu (khi Template đã có Framework nếu cần), xem, sửa, accept/reject, xác nhận target/context, áp dụng Intent, tạo successor, huỷ application |
| Admin của tenant | Mọi việc của giáo viên, cộng: chọn Framework cho Template (ngoài mục này), duyệt Node, kế thừa bản nháp, rebase. Retry application `create_node` chỉ admin |

Mã HTTP theo quy ước framework, không dùng 404 cho mọi từ chối:

| Tình huống | HTTP |
| --- | --- |
| Khách chưa đăng nhập (JSON) | 401 |
| Sai vai trò, chưa xác minh, người dùng không active, sai tenant ở middleware | 403 |
| Đối tượng không tồn tại, thuộc tenant khác, hoặc giáo viên không có quyền AI trên Template đúng prefix | 404, không tiết lộ |
| Hành động bị cấm trên đối tượng nhìn thấy được | 403 `proposal_forbidden` |
| Phiên hết hạn (419), quá tốc độ (429) | 419, 429 |

---

# 3. Bất biến phải hiển thị đúng

1. **Accept không phải publish và không phải áp dụng.** Nhãn và thông báo không bao giờ
   nói "đã áp dụng vào khoá học" cho đề xuất mới accept. Trạng thái review và trạng thái
   application hiện riêng, và mỗi loại application có nghĩa riêng (§5).
2. **AI không ghi dữ liệu chính.** Summary, concept, learning_objective và competency sau
   accept chỉ là nội dung đã được người xem xét (§5). Cập nhật mô tả hay mục tiêu của
   Course cần một lệnh Course riêng mà v1 **chưa có**; UI không được giả vờ có nút đó.
3. **Mất quyền đọc nguồn Media = mất nội dung.** Không hiện tiêu đề, nội dung, lý do hay
   trích dẫn; chỉ hiện trạng thái và lý do ẩn ở mức backend cung cấp (§4.4).
4. **Độ tin cậy (confidence) không phải trọng số.** Hiện tách nhau; `weight` của
   node_mapping là trường riêng, có thể trống.
5. **Cũ (stale), đổi ngữ cảnh, đổi target là ba tình huống khác nhau** với đường phục hồi
   khác nhau (§6). UI không gộp chúng và không tự chấp nhận lại.
6. **Mọi thao tác ghi tuân theo giao thức lệnh ở §4.5.**

---

# 4. Giao thức phía trình duyệt

## 4.1. Trạng thái của một đề xuất đang mở

Mỗi đề xuất đang mở có một máy trạng thái riêng: `loading` → `authorized` (chỉ khi GET
chi tiết hợp lệ) → `denied` | `unavailable`.

* Chỉ **render nội dung sau một GET chi tiết thành công**. Backend ghi audit truy cập
  Media trước khi lộ nội dung; nếu audit lỗi thì trả 503 `service_unavailable` với
  `data = null`, và UI phải hiện "tạm thời không xem được", không hiện bản đã tải trước.
* Khi nhận 401, 403, 404, `content_denied` khác null hoặc 503 của một lần đọc nội dung:
  **gỡ toàn bộ nội dung khỏi DOM và khỏi bộ nhớ script, vô hiệu mọi thao tác ghi**, không
  quay lại bản cũ để tiếp tục duyệt.
* Phản hồi cũ đến muộn không được nạp lại nội dung: mỗi request mang mã thế hệ
  (generation counter) gắn với cặp (tenant, người dùng, đề xuất); phản hồi có mã cũ bị bỏ.
* Đóng đề xuất, đổi đề xuất, đổi Activity: xoá bộ nhớ nội dung.
* Quay lại từ lịch sử/BFCache (`pageshow` với `persisted`) phải đọc lại và xác thực
  trước khi mở nội dung.
* **Không** lưu nội dung vào `localStorage`, `sessionStorage`, IndexedDB, Cache API hay
  service worker; không đưa nội dung vào URL, `console`, hay telemetry.
* Không hứa thu hồi quyền theo thời gian thực trong trình duyệt; máy chủ là bên quyết
  định ở lần đọc kế tiếp.

## 4.2. Bản sửa chưa gửi

Chỉ giữ **trong bộ nhớ** của tab. Lỗi mạng khi ghi mà quyền vẫn hợp lệ: giữ bản sửa để
thử lại. Mất phiên hoặc mất quyền: **không hứa khôi phục** bản sửa sau khi đăng nhập lại,
vì không có nơi lưu được phép. Báo trước cho người dùng nguy cơ mất bản sửa khi phiên sắp
hết hạn hoặc khi rời trang. Nhiều tab: guard phiên bản của máy chủ quyết định; tab nhận
xung đột phải đọc lại, không tự ghi đè.

## 4.3. Hiển thị văn bản

Mọi nội dung sinh từ AI, Media, hoặc do người dùng nhập (tiêu đề, nội dung, lý do,
tiêu chí, nhãn Node, thông báo lỗi) chỉ hiển thị bằng `textContent`/Blade escape, không
`innerHTML`, không Markdown động, không tạo URL từ nội dung. Xử lý phản hồi không phải
JSON (chuyển hướng đăng nhập, trang lỗi của framework) mà không render HTML nhận về.

## 4.4. Danh sách và chi tiết: dữ liệu có thật

Bảng khả năng theo từng màn hình (tự kiểm với `AiAuthoringHttpReadService` và
`AiAuthoringProposalService`):

| Dữ liệu | Danh sách | Chi tiết |
| --- | --- | --- |
| UUID, loại, trạng thái, `lock_version`, `revision_no` | Có | Có |
| `content_denied` | Chỉ giá trị `unavailable` | Lý do cụ thể do máy chủ tính (freshness hoặc trạng thái) |
| `allowed_actions` | Có, chỉ giá trị khi `pending_review` (§7.1) | Như danh sách |
| Tiêu đề, nội dung, confidence, lý do | **Không** (metadata-only) | Có, trong `payload` |
| Thời điểm tạo/quyết định | **Không** | **Không** ở cấp trên; lịch sử review có, tối đa 100 |
| Trích dẫn | Không | Chỉ `ordinal`, `usage_type`, `content_type`, `locale`, `locator`, `media_file_id`; **không** có phiên bản xử lý, fingerprint hay đoạn trích |
| Application | Không | UUID, thao tác, trạng thái, `approved_by`; tối đa 100 |
| So sánh ngữ cảnh cũ/mới | Không | `context-preview` chỉ trả ngữ cảnh **hiện tại** và hash |

Hệ quả: danh sách hiện *loại + trạng thái + phiên bản*, không có thời điểm hay tiêu đề.
UI **không** hiển thị thời điểm, phiên bản trích dẫn, so sánh khác biệt ngữ cảnh, hay dòng
thời gian có mốc giờ, trừ khi Owner duyệt bổ sung DTO (§7.3). Lịch sử bị giới hạn 100
phải được ghi trung thực ("hiển thị tối đa 100"), không được coi là toàn bộ. Không hiện
`media_file_id` thô làm nhãn cho người dùng; dùng "Nguồn n" cùng loại, ngôn ngữ và vị trí.

## 4.5. Lệnh ghi

* Mỗi lệnh sinh một `request_id` UUID ở trình duyệt. Trước khi gửi, **đóng băng** cả body
  và các guard (`expected_lock_version`, `expected_revision_no`, hash target/context, tuỳ
  thao tác). Guard khác nhau theo thao tác: Generate chỉ có `request_id`; Edit và
  Decision dùng L+R; target/context dùng L cộng hash; Apply, Retry, Cancel dùng L (Cancel
  thêm `reason_code`); successor, kế thừa, rebase dùng dữ liệu đã niêm phong của bản xem
  trước, **không** giả lập L/R. Duyệt Node (P3-B) dùng L+R cùng cặp `framework_id` +
  `framework_version_id`, không dùng guard của target. ID và guard dạng số là số nguyên,
  hash là hex thường.
* **Timeout hoặc huỷ kết nối là kết quả chưa biết.** Thử lại phải dùng **cùng UUID và
  cùng toàn bộ body** (kể cả guard và lý do). Nếu nội dung hoặc guard thay đổi, hoặc sau
  khi đọc lại để ra quyết định mới, phải dùng UUID mới. Không đổi guard dưới UUID cũ.
* 409 giữ thao tác chưa áp dụng: đọc lại chi tiết hiện tại (có ủy quyền) rồi để người
  dùng quyết định lại. Không tin trạng thái của một replay cũ thay cho lần đọc hiện tại.
* 202 của Generate chỉ là trạng thái đã lưu, **không** hứa có worker đang chạy; làm mới
  là thao tác bấm tay. GET trạng thái có thể trả 200 với `request_status = failed`: hiện
  "chưa hoàn tất", không hiện "đã tạo thành công" và không tự bịa nguyên nhân.
* Duyệt hàng loạt: tối đa 100 dòng, mỗi dòng có UUID riêng và đề xuất khác nhau; UUID
  ngoài chỉ để đối chiếu. Mỗi dòng đã có **chi tiết hợp lệ của đúng revision được chọn**
  trước khi vào lô; không accept từ danh sách metadata mà chưa xem nội dung. Không có
  "chọn tất cả xuyên trang". Đổi bộ lọc, con trỏ hoặc Activity thì xoá lựa chọn. Kết quả
  đọc **từng dòng** (HTTP 200 ở ngoài không có nghĩa thành công cả lô); chỉ thử lại dòng
  có kết quả chưa biết, với body gốc.
* 422 từ máy chủ có `details` rỗng: hiện lỗi mức biểu mẫu, không suy ra trường lỗi. Lỗi
  kiểm ở trình duyệt có thể hiện cạnh trường.

## 4.6. Bảng lỗi

Không hiển thị `details` thô, SQL, tên bước hay cấu hình.

| Mã | Thông điệp người dùng (nhóm) |
| --- | --- |
| 401, 419 | Phiên hết hạn: đăng nhập lại. Gỡ nội dung đang mở (§4.1) |
| 403 khung/middleware, `proposal_forbidden` | Không có quyền thực hiện |
| 404 `proposal_not_found` | Không tìm thấy |
| 422 `invalid_proposal` | Dữ liệu chưa hợp lệ (mức biểu mẫu) |
| 429 | Thử lại sau |
| 503 `service_unavailable` | Tạm thời không dùng được; không hiện nội dung cũ |
| 409 phiên bản/idempotency/stale/`proposal_*`/xung đột chọn Framework | Nội dung đã thay đổi: tải lại (mỗi mã một câu ngắn) |
| 409 `AI_APPROVAL_REQUIRED` | AI chưa sẵn sàng cho tổ chức. Đây là *mã cổng*, **không** chứng minh tổ chức chưa được kích hoạt |
| 409 `AI_QUOTA_EXCEEDED` | "Hiện chưa thể dùng AI trong hạn mức hoặc quyền lợi của tổ chức." Mã này gộp **cả** thiếu quyền lợi đang hiệu lực **lẫn** không giữ chỗ được (`AiProviderExecutionGate` dòng 78–99); vì `blocked_at` bị lược, UI không biết là nguyên nhân nào nên **không** nói "đã hết hạn mức" |
| 409 `AI_SAFETY_BLOCKED` | Bị chính sách an toàn chặn |
| 409 `AI_PROVIDER_CALL_FAILED`, `AI_QUOTA_COMMIT_FAILED`, `AI_QUOTA_RESERVATION_EXCEEDED`, `AI_ADAPTER_MISMATCH`, `AI_RUN_ALREADY_EXECUTED`, `AI_RUN_TRANSITION_CONFLICT`, `AI_RUN_PROVENANCE_CONFLICT` | Yêu cầu không hoàn tất hoặc chưa rõ kết quả: đọc lại trạng thái yêu cầu trước khi làm gì; **không tự tạo yêu cầu mới**; không hứa hoàn tiền hay "chưa gọi provider". `AI_QUOTA_RESERVATION_EXCEEDED` xảy ra **sau khi provider đã được gọi** và mức dùng thực tế đã được ghi nhận vượt phần giữ chỗ (`AiProviderExecutionGate` dòng 228–258) |
| 409 mã `AI_*` khác hoặc mã miền chưa biết | Thông báo chung an toàn; không hiện mã |

Đây là toàn bộ mã `AI_*` có trong mã nguồn ở thời điểm viết (reviewer lượt 2 đối chiếu độc
lập 10/10); nhóm theo hành vi người dùng, không suy trạng thái triển khai từ tiền tố
`AI_`. Khi thêm mã mới, phải thêm dòng vào bảng này cùng thay đổi. Test ánh xạ lỗi phải
phủ riêng ba ca: thiếu quyền lợi, giữ chỗ bị từ chối, và mức dùng thực tế vượt phần giữ
chỗ. Backend không cần đổi cho việc này.

---

# 5. Vòng đời sau accept

Có bốn thứ khác nhau, mỗi thứ có trạng thái và hành động riêng. UI phải phân biệt chúng.

| Thứ | Đơn vị | Trạng thái |
| --- | --- | --- |
| Review | Đề xuất | `pending_review` → `accepted` \| `rejected` \| `stale` \| `deletion_pending` |
| Application `create_node` | Admin ghi Node vào Framework Version nháp | `ready_to_apply` → `applied` \| `failed` \| `cancelled` (không chờ xuất bản) |
| Application `apply_intent` | Course ghi Intent vào Template làm việc | `awaiting_publication` → `ready_to_apply` → `applied` \| `failed` \| `cancelled` |
| Xuất bản Framework/Template | Owner publish riêng | Ngoài UI này |

## 5.1. Theo loại đề xuất

| Loại | Sau accept |
| --- | --- |
| summary, concept, learning_objective, **competency** | **Dừng** tại nội dung đã được xem xét. `competency` không có nhánh ánh xạ (chỉ `node_mapping` có `mapping`); không có Application nào cho bốn loại này |
| node_mapping, `reuse_existing` | Xác nhận target → `apply_intent` |
| node_mapping, `propose_new` | Giáo viên accept → "chờ admin" (tính toán, không lưu thành trạng thái) → admin chọn Framework Version **nháp** cụ thể rồi `approve-node` → `create_node`; sau đó còn cần Framework Version được xuất bản và Template chọn/rebase đúng Version, rồi mới `apply_intent` |

Ý nghĩa đúng của các trạng thái application, để nhãn không nói quá:

* `create_node` `applied`: Node đã được Learning ghi trong **Framework Version nháp**.
* `apply_intent` `awaiting_publication`: đang đợi **Framework Version** đích được xuất
  bản (và Template chọn đúng Version).
* `apply_intent` `applied`: Intent đã được **ghi vào Template làm việc**. Chưa phải
  Mapping chính thức và chưa phải Course đã xuất bản; Mapping chính thức chỉ có khi
  Template được xuất bản qua giao dịch hiện có, và giao dịch đó kiểm lại từng Intent do
  AI tạo. Không có nút hoàn tác Node, Mapping hay receipt.
* Không xuất bản Framework, không xuất bản Template, không chọn Framework cho Template
  trong mục này. Các việc đó dùng các màn hình sở hữu chúng.

## 5.2. Hành động

* **Xác nhận target** / **từ chối target**: `reject-target` chỉ dành cho Intent **đã
  `applied`** (để chặn xuất bản về sau). Với receipt chưa applied thì dùng **Cancel**;
  backend trả `use_cancel_application` cho trường hợp dùng sai. Qua HTTP đó chỉ là 422 với
  `details` rỗng, nên UI quyết định nút nào hiện dựa trên **trạng thái receipt**, không đợi
  chuỗi lỗi để rẽ nhánh.
* **Retry**: `apply_intent` cho cả hai vai trò; `create_node` chỉ admin. Người retry giữ
  nguyên `approved_by` gốc.
* **Cancel**: cho `awaiting_publication`, `ready_to_apply` và `failed`; `applied` là
  kết thúc.
* Hành động trên một receipt cụ thể mang UUID của receipt đó; không có nút retry/cancel
  "toàn đề xuất".

---

# 6. Cũ, đổi ngữ cảnh, đổi target, mất quyền

Đây là các tình huống riêng. Không gộp thành một banner "stale".

| Tình huống | Cách nhận biết | Việc người dùng có thể làm |
| --- | --- | --- |
| Đề xuất chờ duyệt bị cũ (nguồn, prompt hoặc ngữ cảnh đổi) | Trạng thái `stale`; nội dung ẩn | Không xác nhận lại được. Tạo yêu cầu sinh mới. Successor chỉ có nếu từng có accept lịch sử và đủ điều kiện dưới đây |
| Đề xuất đã accept, nguồn đổi | `stale`, nội dung ẩn | Successor `source_revision_changed`, chỉ khi thoả cả sáu điều kiện của hợp đồng (người thao tác và nguồn hiện còn quyền, cùng file và fingerprint, payload chưa bị xoá, đã có accept lịch sử, kết quả Node còn hợp lệ, nguồn hiện tại > 0). Bản xem trước chỉ kế thừa quyết định: **không** kế thừa lý do, trích dẫn hay confidence, danh sách nguồn bắt đầu rỗng; kết quả là đề xuất `pending_review` mới với nguồn chọn lại và phải accept lại |
| Đã accept, chỉ ngữ cảnh khoá học đổi (nguồn còn đúng) | `context_changed = true` | `context-preview` (chỉ ngữ cảnh hiện tại và hash) rồi `context-confirmations`. Không có so sánh khác biệt |
| Đã accept, target đổi | 409 `proposal_target_changed` khi thao tác target | `target-preview` xem target hiện tại rồi quyết định. Có thể không làm trạng thái đề xuất thành `stale` |
| Mất quyền nguồn Media | `content_denied` | Không có nội dung, không dựng successor từ dữ liệu cũ; chuyển sang soạn thủ công bằng màn hình của chủ sở hữu |
| Lý do khác của successor (`context_changed`, `target_changed`, `intent_removed`, `human_correction`) | — | Người dùng tự viết payload; không tự sao chép từ bản cũ |

Xác nhận ngữ cảnh không dùng để vượt qua tình trạng cũ của nguồn hay prompt. Banner không
hứa biết nguyên nhân chi tiết khi DTO chỉ cho `unavailable` hoặc `stale`.

---

# 7. Phụ thuộc backend — cần Owner duyệt trước khi làm

## 7.1. `allowed_actions` (D3)

`AiAuthoringProposalService.php:387` và `AiAuthoringHttpReadService.php:78` chỉ trả
`['edit','accept','reject']` khi `pending_review` và nội dung hiện được. Mọi trạng thái
khác trả rỗng. Hợp đồng Frozen coi đó là gợi ý nhưng chưa đóng danh sách tên.

Mở rộng ở backend tốt hơn để JS tự suy quyền. Nhưng đây là **thay đổi DTO nhìn thấy
được**, nên cần một **amendment/addendum do Owner duyệt** cho từ vựng và ngữ nghĩa
trước khi code, cộng hồi quy cho người dùng cũ và mới. Yêu cầu:

* Tính theo người dùng hiện tại, quyền nguồn, loại, target/context, thao tác và trạng thái
  của **từng application** (retry/cancel chỉ rõ receipt nào).
* Luôn tính mới mỗi lần đọc; không lưu; không phải capability token. POST vẫn kiểm lại
  quyền và khoá; UI chịu 403/404/409 dù hành động từng được gợi ý.
* Không serialize snapshot của receipt và không thêm quyền.
* Không cần ADR hay schema mới nếu domain và quyền không đổi.

## 7.2. Dữ liệu chọn Node (mới, từ review)

Payload `reuse_existing` chỉ có `node_id` và `definition_id` (số nguyên), và backend chỉ
nhận cặp nằm trong danh sách ứng viên của cơ sở Framework
(`AuthoringPayloadValidator::mapping`). **Không có** trong 41 endpoint: tên Node, danh
sách ứng viên, hay gợi ý để chọn Node khác; `target-preview` chỉ có sau khi accept và chỉ
trả đúng một target. Giáo viên xem một đề xuất `reuse_existing` đang chờ duyệt chỉ thấy
các số.

Hệ quả: **không thể duyệt có ý nghĩa một `reuse_existing` trước khi có dữ liệu hiển thị
tên Node**, và không thể làm bộ chọn Node nếu không có danh sách ứng viên. Lựa chọn:

* **Khuyến nghị:** P3-A không làm hai việc này (§8). Sau đó thiết kế một DTO đọc qua
  cổng của Learning/Course (tên Node, ứng viên, quyền giáo viên đọc), có amendment hợp
  đồng, Owner duyệt và test, thuộc P3-B. Không đọc chéo bảng `core_learning_*` từ JS hay
  controller AI, không nới quyền quản trị của Learning, không bắt giáo viên đoán ID.
* Thay thế: duyệt DTO này ngay trước P3-A (chậm hơn, nhưng P3-A đầy đủ hơn).

## 7.3. DTO chưa có

Nếu Owner muốn thời điểm trong danh sách, phiên bản trích dẫn, so sánh ngữ cảnh cũ/mới
hoặc dòng thời gian application có mốc giờ, mỗi thứ cần amendment DTO qua owner service,
allowlist, không dùng dòng thô hay ảnh chụp lịch sử. Mặc định v1 UI **không** có các thứ
này.

## 7.4. Chưa bật provider thì chưa có gì để duyệt

Cấu hình mặc định: allow-list rỗng và binding `Unavailable*`, nên trên môi trường mặc định
danh sách rỗng và Generate bị cổng từ chối. Đây là nguồn *mặc định*, không chứng minh mọi
môi trường: dữ liệu giả, dữ liệu cũ hay successor có thể tồn tại dù provider tắt. Hệ quả:

* Dev và test cần dữ liệu mẫu bằng provider giả (§9). UI phải đẹp ở trạng thái rỗng và
  trạng thái "AI chưa sẵn sàng".
* Không thể kiểm chất lượng nội dung AI hay trải nghiệm thật trước activation.

---

# 8. Các giai đoạn

## 8.1. P3-A — đọc và duyệt nội dung chữ

Không đổi service, API hay hợp đồng AI hiện có. Controller web của trang Hoạt động vẫn
được thay đổi (gọi service quyền của Course, cấp `selected_framework_*` và cờ hiển thị cho
Blade, §2.1); không thêm truy vấn chéo miền hay endpoint mới để lấp dữ liệu.

* Danh sách (§4.4), tạo yêu cầu, chi tiết, sửa, accept/reject, duyệt hàng loạt.
* Bốn loại chữ: summary, concept, learning_objective, competency. Chúng dừng sau accept.
* `node_mapping` `propose_new`: xem, sửa các trường tự do (code, nhãn, loại Node, tiêu
  chí, vai trò, trọng số), accept, reject. Sau accept chỉ hiện "chờ admin"; hành động
  admin thuộc P3-B.
* `node_mapping` `reuse_existing`: hiển thị như một dòng có ghi chú "cần bản xem Node để
  duyệt (giai đoạn B)"; **không** cho accept ở P3-A, có thể reject. Không sửa Node. Yêu cầu
  Generate `node_mapping` có thể trả cả hai chế độ và giao diện không có tham số chỉ sinh
  `propose_new`, nên UI phải nhận và hoãn `reuse_existing`, không giả vờ chỉ có
  `propose_new`.
* Điều kiện tạo `competency`/`node_mapping`: Template đã chọn Framework. UI điền sẵn và
  khoá theo lựa chọn đó (backend chỉ nhận đúng lựa chọn của Template). Nếu chưa chọn:
  admin thấy liên kết tới trang Learning mapping; **giáo viên không có trang đó** (chọn
  Framework và tab Mapping chỉ dành cho admin) nên chỉ thấy "cần quản trị viên chọn
  Framework".
* Khả năng khám phá: xem D11 (§2) cho lối vào từ danh sách Hoạt động. Mục "Đề xuất AI" nằm trên từng Activity; Template nhiều Activity
  không có tổng quan (§2). Có thể thêm chỉ báo số đề xuất chờ **trên chính Activity**;
  không thêm hàng đợi toàn Template.

## 8.2. P3-B — sau accept và phục hồi

> **Bản nháp amendment và kế hoạch lát cắt:** [LF-AI-Authoring-P3B-Amendment](LF-AI-Authoring-P3B-Amendment.md) (Owner duyệt D12–D17 ngày 2026-09-30; B5 hoãn). Đang làm B1.

Phụ thuộc: amendment `allowed_actions` (§7.1) và DTO tên/ứng viên Node (§7.2) đã được
Owner duyệt và làm xong.

* Duyệt `reuse_existing` và bộ chọn Node.
* Application (§5), xác nhận/từ chối target, retry, cancel, tình huống ở §6, successor.
* Admin duyệt Node cho `propose_new`. Vì bước tiếp theo còn phụ thuộc xuất bản Framework
  và chọn/rebase Template, P3-B phải nêu rõ điểm bàn giao và kịch bản hoãn, không hứa
  chạy hết đường từ đầu đến cuối chỉ với P3-B.

## 8.3. P3-C — chỉ admin

Kế thừa bản nháp và rebase, dùng bản xem trước đầy đủ (kế hoạch phải nêu cách xử lý mọi
Intent, hash chính xác, Version đích đã xuất bản; bản đồ/gỡ/huỷ rebase nguyên tử). Phụ
thuộc P3-B.

## 8.4. Liên kết ngược từ trang Learning mapping (D8)

Intent do AI tạo chỉ giữ ID nội bộ của đề xuất, không có UUID, nên **không** liên kết
thẳng tới đề xuất. Chỉ liên kết về trang Hoạt động tương ứng. Muốn liên kết trực tiếp cần
một tra cứu UUID thuộc AI, giới hạn theo tenant và cha; không quét từng Activity. Trang
Learning mapping vẫn giữ nguyên nút thủ công hiện có.

**Hai loại URL khác nhau, không dùng lẫn:**

| Việc | URL | Nguồn |
| --- | --- | --- |
| Mở **trang Hoạt động** (HTML) từ trang Learning mapping | Route HTML của Course: `{role}.course-templates.lessons.activities.show` (`templateId`, `lessonId`, `activityId`) hoặc, khi Activity nằm trong section, `{role}.course-templates.sections.lessons.activities.show` (thêm `sectionId`) | Cây Course mà controller Template đã cấp cho trang (`activitiesByLesson`, bài học và section) để đổi từ `activity_id` sang đúng route theo tenant; không cần tra cứu UUID hay gọi API AI |
| Gọi **API AI** (JSON) từ trang Hoạt động | `templateRoutePrefix + '.activities.ai-authoring.…'` với `templateId` và `activityId`, không dùng route có bài học hoặc section | `routes/modules/ai-authoring.php` |

Không bao giờ dùng URL JSON làm đích của liên kết hay làm dự phòng. Nếu chưa dựng được
liên kết HTML thì D8 bị hoãn khỏi P3-A và Owner xác nhận phạm vi đó. Acceptance phải phủ
Activity nằm trực tiếp dưới bài học và Activity trong section, Activity không còn tồn tại,
và quyền bị thu hồi.

---

# 9. Kỹ thuật, dữ liệu mẫu và kiểm chứng

* **Cách làm:** Blade + JavaScript module gọi các endpoint JSON hiện có (phiên đăng nhập,
  CSRF, `Accept: application/json`, `{data, error}`), dùng lại Alpine đã có trong
  `resources/js/app.js`. Trong `resources/views` không có Livewire; không thêm framework.
* **Tiền tố route** lấy từ tiền tố Template đang dùng; hai vai trò dùng chung view (cờ
  đăng ký ở `routes/web.php`).
* **i18n:** mọi chuỗi qua `lf.*` với đủ khóa VI và EN. Không sao chép chuỗi tiếng Việt cứng
  của partial learning-mappings.
* **Chuẩn giao diện:** LF-Admin-Form-Design-Standard (bố cục theo nghiệp vụ, tiết lộ dần,
  thanh hành động, danh sách §25, xác nhận chuẩn cho thao tác cần xác nhận, menu hành động
  chuẩn cho hàng, kiểm ma trận thanh bên mở/thu và mobile). Hiển thị số đã tải, không có
  tổng toàn cục; phân biệt "chưa có" với "không khớp bộ lọc".
* **Truy cập:** semantic heading, label liên kết control, `aria-live`/`role="status"`/
  `alert` cho kết quả, `aria-busy` khi tải, focus hợp lý khi mở chi tiết và sau lỗi, kết
  quả hàng loạt có tên truy cập cho từng dòng, không dùng màu làm tín hiệu duy nhất,
  hoạt động bằng bàn phím, không vỡ ở zoom 200% và chuỗi VI/EN dài.
* **Dữ liệu mẫu (D5):** lệnh/seeder chỉ chạy ở `local` hoặc `testing`, **từ chối** ở mọi
  môi trường khác kể cả có cờ ép (kiểm trước mọi lần ghi). Dữ liệu tổng hợp (tenant, Media
  tổng hợp), provider giả chỉ nhúng ở local/test, **không** thêm allow-list bền vững,
  credential thật hay kết nối mạng. Đi qua quy trình và cổng hiện có, dùng ledger thật khi
  thử hạn mức; không sửa schema hay nguồn gốc Run để giả trạng thái. Chỉ tạo trạng thái
  đạt được thật; ca lỗi/đồng thời dùng fixture test, không giả receipt sản xuất. Có test:
  không mạng, bị từ chối ở production.
* **Kiểm chứng:** ma trận acceptance nằm ở
  [báo cáo review §6](../quality/LF-AI-Authoring-Review-UI-Design-Review.md), gồm quyền
  (mọi vai trò/phân công, mất quyền giữa chừng, chéo tenant), lộ nội dung và XSS,
  transport, replay/đồng thời/hàng loạt, vòng đời Owner, dữ liệu mẫu, trải nghiệm và
  hồi quy. Mỗi dòng phải có test hoặc ca trình duyệt truy vết được.
* **Mức audit:** HIGH theo [LF-Regression-Audit](../quality/LF-Regression-Audit.md) (lộ
  nội dung nhạy cảm, quyền, idempotency và đồng thời, ghi chéo miền, danh tính đã xuất bản
  bất biến). Khi làm: test hẹp + module + toàn suite khi môi trường cho phép, `pint
  --test`, `npm run build`, `git diff --check`, và kiểm trình duyệt. Hồi quy phải giữ:
  Mapping thủ công, cấu trúc và media của Activity, luồng xuất bản Version hiện có.

---

# 10. Quyết định Owner

Owner đã duyệt D1–D8 ngày 2026-09-29 (theo v0.2). Review độc lập nêu điều chỉnh. Owner
duyệt điều chỉnh D2, D7 và D9 "theo đề xuất" cùng ngày. Các mục "bổ sung chờ xác nhận"
của D1, D3, D5, D6, D8 là làm rõ theo review, chưa được Owner xác nhận riêng (lượt 2 cũng
ghi điều này). Lượt 3 của reviewer APPROVE v0.4 (§11), và Owner xác nhận các bổ sung D1,
D3, D5, D6, D8 "theo đề xuất" ngày 2026-09-29. **P3-A được phép bắt đầu** trong đúng phạm
vi §8.1; P3-B và P3-C vẫn cần amendment và review riêng.

| # | Nội dung | Trạng thái |
| --- | --- | --- |
| D1 | Mục "Đề xuất AI" trên trang chi tiết Hoạt động, không thêm menu. Bổ sung: hiện theo **quyền AI**, không theo quyền vào trang (§2.1) | Đã duyệt; bổ sung Owner xác nhận 2026-09-29 |
| D2 | Chia P3-A/B/C, review từng giai đoạn. **Đã chỉnh:** P3-A chỉ gồm bốn loại chữ cộng `propose_new`; `reuse_existing`, sau accept, phục hồi, successor thuộc P3-B (§8) | Owner duyệt phần chỉnh 2026-09-29 |
| D3 | Mở rộng `allowed_actions` ở backend trước P3-B. **Bổ sung:** cần amendment do Owner duyệt trước khi code (§7.1) | Đã duyệt; bổ sung Owner xác nhận 2026-09-29 |
| D4 | Blade + JS trên endpoint JSON hiện có; dùng Alpine đã có | Đã duyệt; review APPROVE |
| D5 | Dữ liệu mẫu bằng provider giả, chỉ local/test. **Bổ sung:** từ chối ở production kể cả cờ ép; không mạng; không allow-list bền vững (§9) | Đã duyệt; bổ sung Owner xác nhận 2026-09-29 |
| D6 | Vẫn hiện nút "Tạo đề xuất" khi AI chưa bật. **Chỉnh:** thông báo theo nhóm mã lỗi ở §4.6, không suy trạng thái triển khai từ tiền tố `AI_` | Đã duyệt; chỉnh Owner xác nhận 2026-09-29 |
| D7 | Loại chữ sau accept chỉ ghi nhận, không nút cập nhật Course. **Đã chỉnh:** thêm `competency` vào nhóm này (§5.1) | Owner duyệt phần chỉnh 2026-09-29 |
| D8 | Trang Learning mapping không thêm thao tác duyệt; giữ nguyên nút thủ công. **Làm rõ:** chỉ liên kết về Hoạt động (§8.4) | Đã duyệt; làm rõ Owner xác nhận 2026-09-29 |
| D9 | Dữ liệu tên/ứng viên Node là DTO mới qua cổng của Learning/Course, cần amendment do Owner duyệt, thuộc P3-B (§7.2). P3-A không làm duyệt `reuse_existing` và bộ chọn Node | Owner duyệt 2026-09-29; **phần hiển thị được D13 mở 2026-09-30** (B1); bộ chọn Node (A2.2) hoãn theo D14 |
| D10 | Danh sách đề xuất giữ nút **Xem** hiển thị thay vì menu thao tác `⋯` của chuẩn List §25.4, vì mỗi dòng chỉ có một hành động và nó là hành động chính (mở chi tiết) | Owner duyệt lệch chuẩn 2026-09-29 (chỉ cho danh sách một hành động này) |
| D11 | Lối vào từ danh sách Hoạt động: liên kết "Đề xuất AI" ở dòng Hoạt động có Media (`video`, `audio`, `document`) cho người có quyền AI; mục AI trên trang chi tiết của loại còn lại chỉ hiện ghi chú "không có nội dung Media". Nút Xem không đổi | Owner duyệt 2026-09-30 ("làm D11") |
| D12 | Từ vựng `allowed_actions` mở rộng (cấp đề xuất và cấp receipt); danh sách giữ nguyên | Owner duyệt 2026-09-30 |
| D13 | Người có quyền AI (kể cả giáo viên) được **xem** `code`, `label`, `node_type`, mô tả cắt 500 ký tự của Node trong bộ chuẩn của chính tenant; không `criteria`. Mở phần "hiển thị" của D9 | Owner duyệt 2026-09-30 |
| D14 | Endpoint danh sách ứng viên Node và lát B5 | Hoãn (Owner 2026-09-30) |
| D15 | Đề xuất kế thừa, kế thừa bản nháp, rebase | Để P3-C (Owner 2026-09-30) |
| D16 | Thứ tự lát P3-B: B1 → B2 → B3 → B4 → B6 → (B5) | Owner duyệt 2026-09-30 |
| D17 | Ghi A1 và A2.1 vào hợp đồng Frozen bằng khối "Owner amendment — P3-B" (hợp đồng v0.9) | Owner duyệt 2026-09-30, đã ghi |

---

# 11. Xử lý finding của review độc lập

| Finding | Mức | Xử lý ở |
| --- | --- | --- |
| P3-UI-R1 Bảng sau accept sai loại/nghĩa | HIGH | §5, D7 |
| P3-UI-R2 Gộp stale/context/target | HIGH | §6 |
| P3-UI-R3 P3-A thiếu dependency, quyền host | HIGH | §2.1, §7.2, §8.1, D2, D9 |
| P3-UI-R4 Loại bỏ nội dung khi disclosure không hợp lệ | HIGH | §4.1–§4.3 |
| P3-UI-R5 UI hứa dữ liệu chưa có | MEDIUM | §4.4, §7.3, §8.4 |
| P3-UI-R6 Giao thức request_id/guard/bulk | MEDIUM | §4.5 |
| P3-UI-R7 Ma trận lỗi và `AI_*` | MEDIUM | §2.1, §4.6, D6 |
| P3-UI-R8 Acceptance và dữ liệu mẫu | MEDIUM | §9, D5 |

Lượt 2 ([báo cáo §8](../quality/LF-AI-Authoring-Review-UI-Design-Review.md)): R1–R4, R6, R8
đóng; R5 và R7 mở một phần MEDIUM, được xử lý ở v0.4:

| Phần còn mở | Xử lý ở |
| --- | --- |
| R5: URL HTML của liên kết ngược khác URL JSON của API AI | §8.4 (hai loại URL) |
| R7: `AI_QUOTA_EXCEEDED` không chứng minh "hết hạn mức"; `AI_QUOTA_RESERVATION_EXCEEDED` xảy ra sau khi đã gọi provider | §4.6 |

Reviewer cần kiểm lại v0.4 để đóng hai phần này và xác nhận các điều kiện trước P3-A
([báo cáo review §6 và §8.8](../quality/LF-AI-Authoring-Review-UI-Design-Review.md)).

---

# 12. Chưa kiểm hoặc chưa chốt

* Chưa có mockup; bố cục cụ thể trên màn hình hẹp, vị trí mục "Đề xuất AI" trong trang
  Hoạt động, chỉ báo số đề xuất chờ chốt cùng mẫu giao diện.
* Đã chốt khi implementation: controller trang Hoạt động gọi `authoringRole()` của
  `CourseAuthoringContextService` (một hàm đọc mỏng, không cache), có test giáo viên chỉ có
  quyền vào trang. Reviewer implementation kiểm lại lựa chọn này.
* Chưa đo hiệu năng danh sách lớn; hợp đồng chặn ở 100 dòng mỗi trang.
* Lệnh dữ liệu mẫu `ai:authoring-demo-seed` đã có; an toàn của nó (từ chối ngoài
  `local`/`testing`, không mạng, không allow-list bền vững) do review implementation P3-A
  kiểm độc lập, chưa được coi là đã chứng minh.
* Chưa có mockup được duyệt; bố cục hiện tại là phương án của implementer.

---

# 13. Tiến độ triển khai P3-A

Ghi nhận thực tế, không thay thế review implementation độc lập ở cuối P3-A.

| Lát | Nội dung | Trạng thái |
| --- | --- | --- |
| 1 | Mục "Đề xuất AI" trên trang Hoạt động, hiện theo quyền AI (§2.1) | Xong, có test và mutation |
| 2 | Danh sách, bộ lọc, chi tiết chỉ đọc, giao thức trình duyệt §4.1–§4.3; lệnh dữ liệu mẫu `ai:authoring-demo-seed` | Xong, đã kiểm trong trình duyệt |
| 3 | Sửa đề xuất, chấp nhận/từ chối một đề xuất qua hộp xác nhận chung, giao thức lệnh §4.5, ghi chú §5.1 | Xong, đã kiểm trong trình duyệt |
| 4 | Tạo yêu cầu (`generation-requests`), duyệt hàng loạt (tối đa 100 mỗi lệnh, mã yêu cầu riêng cho từng dòng, thử lại chỉ các dòng chưa rõ kết quả với cùng mã) | Xong, đã kiểm trong trình duyệt, gồm ca chưa rõ kết quả: máy chủ ghi một lần từ chối duy nhất |
| — | Review implementation độc lập P3-A, **đóng bởi Owner 2026-09-30 (§13.8)** | Lượt 1: **REJECT** (5 HIGH, 6 MEDIUM). Lượt 2: **REJECT** (2 HIGH, 2 MEDIUM mới). Lượt 3: **REJECT** (1 HIGH mới, N4 một phần). Lượt 4: **REJECT** (1 HIGH mới Q1, N4 một phần). Lượt 5: **REJECT** (1 HIGH mới S1, S2 và N4 MEDIUM). Lượt 6: **APPROVE WITH CHANGES** (0 HIGH; F1 MEDIUM, F2 LOW) — [lượt 1](../quality/LF-AI-Authoring-Review-UI-P3A-Review.md), [lượt 2](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round2.md), [lượt 3](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round3.md), [lượt 4](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round4.md), [lượt 5](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round5.md), [lượt 6](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round6.md). Đã sửa đến lượt 6 (§13.1–§13.7), cùng D11 (§13.5); chờ reviewer xác nhận lại F1 và neo |
| P3-B B1 | Xem Node của đề xuất `reuse_existing` (`mapping_node`); Chấp nhận, Từ chối, Sửa văn bản, vai trò và trọng số | Xong, có test và đột biến; chưa qua review độc lập (§13.9) |
| P3-B B2 | Sau chấp nhận: xem Node đích, xác nhận đích, áp dụng vào Template, từ chối đích đã áp dụng; danh sách biên lai có giải thích; liên kết tới tab Đầu ra & năng lực (admin) | Xong, có test và đột biến; chưa qua review độc lập (§13.10) |
| P3-B B3 | Thử lại biên lai thất bại; huỷ biên lai chưa áp dụng với lý do chọn từ danh sách cố định | Xong, có test và đột biến; chưa qua review độc lập (§13.11) |
| P3-B B4 | Admin duyệt Node mới (`propose_new`): chọn bản nháp của Framework rồi tạo Node vào đó | Xong, có test và đột biến; chưa qua review độc lập (§13.12) |
| P3-B B6 | Ngữ cảnh khoá học đổi: xem ngữ cảnh hiện tại rồi xác nhận lại (`reconfirm_context`); sửa `context_changed` tính cả xác nhận lại | Xong, có test và đột biến; chưa qua review độc lập (§13.13) |
| P3-C C1 | Đề xuất `stale` đã từng chấp nhận: tạo đề xuất kế thừa (lý do `source_revision_changed`) từ bản nháp do máy chủ dựng | Xong, có test và đột biến; chưa qua review độc lập (§13.14) |
| P3-C C2 | Admin tạo bản nháp bộ chuẩn kế thừa từ một bản đã xuất bản: xem kế hoạch (Node bị loại, Mapping của Template này bị ảnh hưởng), đặt mã và tên, xác nhận | Xong, có test và đột biến; chưa qua review độc lập (§13.15) |
| P3-C C3 | Admin chuyển Template sang bản bộ chuẩn khác đã xuất bản (rebase): xem kế hoạch từng Mapping, quyết định từng cái (chuyển sang Node tương ứng hoặc gỡ có lý do), xác nhận | Xong, có test và đột biến; chưa qua review độc lập (§13.16) |

## 13.1. Sửa sau review implementation lượt 1

| Finding | Đã sửa |
| --- | --- |
| P3A-R1, R2 (HIGH) | Mất quyền (401/419/403; 404 và 503 khi *đọc*) được xử lý ở một chỗ (`request()` → `revoke()`), bất kể luồng nào gọi và luồng đó còn hiện hành hay không: gỡ danh sách, chi tiết, bản sửa, ghi chú, lệnh đang đợi, lựa chọn, kết quả hàng loạt và **mọi tham chiếu phần tử** mà script còn giữ; tắt lệnh ghi cho đến khi một lần đọc danh sách thành công. Phân loại theo mã HTTP trước khi xét thân phản hồi, nên trang HTML/JSON hỏng 403/404/503 cũng là thu hồi. Bộ đếm `epoch` khiến phản hồi đến muộn (đọc, tạo yêu cầu, hàng loạt, lệnh ghi, trạng thái yêu cầu) không hiện gì và không kích hoạt đọc lại. Mở lại đề xuất luôn bắt đầu từ trạng thái sạch |
| P3A-R3 (HIGH) | Kết quả chưa biết của một lệnh **khoá biểu mẫu** (nội dung bị đóng băng đúng bằng cái đã gửi); có hai lựa chọn rõ ràng: "Thử lại đúng yêu cầu vừa gửi" (cùng UUID, cùng body) hoặc "Bỏ yêu cầu đó và sửa tiếp" (lần sau là lệnh mới). Đọc lại đề xuất bỏ định danh cũ (§4.5). Bản sửa chưa gửi: hỏi trước khi đổi bộ lọc, mở đề xuất khác, đóng, huỷ sửa; trình duyệt cảnh báo khi rời trang (§4.2); xung đột báo rõ bản sửa không được lưu |
| P3A-R4, R5 (HIGH) | Lệnh dữ liệu mẫu từ chối trước khi chạm DB nếu disk media không phải driver `local` có `root`; **chiếm** thư mục tenant bằng `mkdir` độc quyền (nguyên tử) và dừng nếu bất cứ thứ gì đã ở đó, thay cho việc chỉ kiểm thư mục Activity |
| P3A-R6 | Chuyển trang (con trỏ) xoá lựa chọn, đúng §4.5 |
| P3A-R7 | Mã cổng "chưa hoàn tất/chưa rõ" giữ mã yêu cầu và dẫn tới **trạng thái của chính yêu cầu đó**; gửi lại cùng lựa chọn dùng lại mã đó nên không tạo yêu cầu thứ hai; "không thấy yêu cầu" (404) không phải mất quyền |
| P3A-R8 | Mỗi đề xuất có mã tham chiếu ổn định `#xxxxxxxx` (8 ký tự đầu UUID) trong danh sách, nhãn truy cập, chi tiết và kết quả hàng loạt; mỗi dòng kết quả có nút Xem |
| P3A-R9 | Bảng dùng `admin-table-has-actions`, `data-label` và thẻ trên màn hình hẹp; thanh công cụ (số lượng bên trái, tạo mới bên phải). **Menu thao tác `⋯` không dùng** (một hành động duy nhất): Owner duyệt lệch chuẩn (D10) |
| P3A-R10 | Bộ test hành vi `tests/js` chạy bằng Node (DOM giả tối thiểu), 14 ca; đột biến 13 chỗ đều bị bắt. Test tĩnh thêm chặn truy cập thành viên bằng chuỗi ghép |
| P3A-R11 | Trang Learning mapping có liên kết "Mở hoạt động" về trang HTML của Activity (trực tiếp hoặc qua section) cho Mapping có nguồn là Activity còn trong cây Course; không có liên kết cho Lesson hay Activity đã mất; có test MariaDB |

## 13.2. Sửa sau review implementation lượt 2

[Báo cáo lượt 2](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round2.md): REJECT, 7 finding cũ đóng, 4 đóng một phần; 2 HIGH + 2 MEDIUM mới.

| Finding | Đã sửa |
| --- | --- |
| N1 (HIGH) — ghi chú bulk còn trong bộ nhớ sau thu hồi, và trong ô nhập lúc rời trang | `releaseBulk()` (thu hồi và `pagehide`): xoá ghi chú, **xoá giá trị của chính ô nhập**, bỏ tham chiếu ô nhập, dòng đếm và nút. `dropDetail` làm rỗng cây chi tiết trước khi tháo (giá trị ô nhập và mọi văn bản, kể cả nút văn bản) và cả ô ghi chú quyết định đã bị tháo khỏi trang nhưng vẫn được giữ |
| N2 (HIGH) — phản hồi lưu của đề xuất cũ làm mất bản nháp của đề xuất đang mở | Phản hồi đến sau khi đã chuyển đề xuất chỉ làm mới các dòng của danh sách (`loadList(true, true)`), không bao giờ động đến chi tiết hay bản sửa đang mở |
| N3 (MEDIUM) — thông báo thành công ghi đè lỗi mất quyền | Sau mọi lần đọc lại được `await` (tạo yêu cầu, lệnh một đề xuất, hàng loạt), chỉ thông báo khi `epoch` chưa đổi |
| N4 (MEDIUM) — đột biến sống sót | 6 test hành vi mới (tổng 20): ghi chú bulk (nhập thật vào ô, giữ cha), rời trang, đua lưu A→B, thông báo sau đọc lại bị từ chối (3 luồng), bulk và tạo yêu cầu trả lời sau khi mất quyền (kiểm cả không đọc lại). Test tĩnh: chỉ 4 chỗ được gán thuộc tính theo khoá tính toán, chỗ thứ năm (`node[member] = …`) làm test đỏ. 14 đột biến của lượt này đều bị bắt, gồm cả 3 con sống sót của lượt 2 |

## 13.3. Sửa sau review implementation lượt 3

[Báo cáo lượt 3](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round3.md): REJECT; N1–N3 đóng; 1 HIGH mới (O1), N4 còn một phần.

| Finding | Đã sửa |
| --- | --- |
| O1 (HIGH) — phản hồi bulk đến muộn đọc lại đề xuất đang mở và xoá bản sửa đang soạn | Mọi lần script **tự** đọc lại đề xuất đang mở đi qua `refreshOpen()`: nếu có bản sửa chưa gửi thì **không đọc**, giữ nguyên biểu mẫu, báo "đề xuất vừa đổi ở nơi khác" và để người dùng chọn "Đọc lại và bỏ bản sửa" (có hỏi xác nhận); không đọc gì khi trang đã rời (`pagehide` đến `pageshow`, xử lý luôn quan sát P18). Rà soát mọi điểm gọi `openDetail`/`dropDetail`/`loadList(true)`: các đường còn lại hoặc do người dùng (có `guardDraft`), hoặc chạy đồng bộ ngay sau lệnh của chính họ khi biểu mẫu đang bị khoá, hoặc là thu hồi quyền |
| N4 (MEDIUM) | Test tĩnh chấp nhận khoảng trắng trong `node[ member ] =` (M16); thêm test hành vi cho epoch sau hộp xác nhận bulk (M28), epoch sau đọc trạng thái yêu cầu (M29) và văn bản nằm cạnh phần tử khác trong `scrub` (M27, test đơn vị vì sản phẩm chỉ dựng văn bản ở lá). 14 đột biến chọn lọc và toàn bộ đột biến của lượt 3 đều bị bắt |

## 13.4. Sửa sau review implementation lượt 4

[Báo cáo lượt 4](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round4.md): REJECT; O1 đóng; 1 HIGH mới (Q1), N4 một phần.

| Finding | Đã sửa |
| --- | --- |
| Q1 (HIGH) — Thử lại danh sách sau lỗi tải trang kế xoá bản sửa không hỏi | Lỗi ở chỗ §13.3 rà theo từng nơi gọi và bỏ sót một. Sửa ở gốc: `loadList()` **không bao giờ đóng đề xuất đang mở** trừ khi người gọi nói rõ `{ closeDetail: true }`; chỉ đổi bộ lọc dùng nó, sau khi đã hỏi về bản sửa chưa gửi. Reload, Thử lại, làm mới sau lệnh/bulk/tạo yêu cầu đều giữ nguyên chi tiết và biểu mẫu |
| N4 (MEDIUM) | Test quét: với bản sửa đang mở và mọi câu hỏi trả lời "huỷ", bấm **mọi** nút hiện có (trừ Lưu) và xác nhận bản sửa còn nguyên, kể cả sau khi tải trang kế lỗi; test riêng cho nút "Đọc lại và bỏ bản sửa" (huỷ và đồng ý), và cho cờ `pageHidden` trở về sau `pageshow`. Quy tắc tĩnh bắt cả phép gán gộp (`+=`, `\|\|=`, `??=`) và cấm thêm `Object.assign(`, `Object.defineProperty(`, `srcdoc`. Đột biến đã thử đều bị bắt (11 đột biến chọn lọc và 3 dạng sink). `refreshOpen` không kiểm `uuid !== openUuid` là tương đương trong đồ thị gọi hiện tại (reviewer đã phân loại) |

## 13.6. Sửa sau review implementation lượt 5

[Báo cáo lượt 5](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round5.md): REJECT; Q1 đóng; S1 HIGH, S2 và N4 MEDIUM.

Nguyên nhân chung của S1/S2 (và N2, O1, Q1 trước đó): "dữ liệu người dùng đang nhập" nằm rải rác
(bản sửa, ghi chú quyết định, ghi chú bulk, lựa chọn tạo yêu cầu) và mỗi luồng tự quyết có giữ hay không.
Lần này có một định nghĩa duy nhất và mọi cách rời/làm mới đều dựa vào nó:

| Khái niệm | Nghĩa |
| --- | --- |
| `hasDraft()` | Của đề xuất đang mở: biểu mẫu sửa đã đổi, lệnh chưa rõ kết quả, **hoặc ghi chú quyết định không rỗng** |
| `hasUnsent()` | `hasDraft()`, hoặc ghi chú bulk không rỗng, hoặc còn loại đề xuất đã tích trong form tạo (thêm ở lượt 6); dùng cho cảnh báo rời trang (`beforeunload`) |

| Finding | Đã sửa |
| --- | --- |
| S1 (HIGH) — ghi chú chưa gửi ngoài bảo vệ draft | Ghi chú quyết định tính là bản nháp: đổi đề xuất, đóng, đổi bộ lọc và tự đọc lại sau bulk đều hỏi hoặc không đọc. Sửa rồi Huỷ **giữ** ghi chú. Ghi chú được giữ qua lần đọc lại sau khi lưu bản sửa (lệnh không dùng nó) và sau quyết định bị từ chối (chưa áp dụng), nhưng **không** sau quyết định đã thành công (đã dùng). `beforeunload` cảnh báo cả với ghi chú quyết định lẫn ghi chú bulk. Mất quyền vẫn xoá ngay như hợp đồng |
| S2 (MEDIUM) — trạng thái Generate cũ đóng lựa chọn mới | Form tạo đề xuất dựng **một lần** và giữ; mở/gập chỉ ẩn/hiện. Yêu cầu cũ hoàn tất chỉ cất và xoá form nếu form vẫn đúng bằng những loại yêu cầu đó được tạo từ; nếu người dùng đã chọn khác thì form giữ nguyên. Nút Huỷ của form là quyết định chủ động: xoá lựa chọn. Thu hồi quyền dựng lại form từ đầu |
| N4 (MEDIUM) | Test "quét" nay nhập cả bốn loại dữ liệu chưa gửi (bản sửa, ghi chú quyết định, ghi chú bulk, lựa chọn tạo) rồi bấm từng nút với mọi câu hỏi trả lời "huỷ" và nhìn **ngay** sau mỗi lần bấm. Thêm test cho M39, M40, M43. DOM giả từ chối mọi truy cập `innerHTML`/`outerHTML`/`insertAdjacentHTML`, nên mọi đường tới chúng, viết theo cách nào cũng thất bại khi chạy (M41 và các dạng tương tự). Quy tắc tĩnh so mọi phép gán theo khoá tính toán, mọi biểu thức khoá, với danh sách cho phép |

Kiểm tra bằng đột biến: 15 đột biến mới (bỏ từng nhánh của S1, S2, M39, M40, M43, ba dạng sink) đều bị bắt.

## 13.7. Sau review implementation lượt 6

[Báo cáo lượt 6](../quality/LF-AI-Authoring-Review-UI-P3A-Review-Round6.md): **APPROVE WITH CHANGES**, 0 BLOCKER, 0 HIGH.
S1, S2, N4 đóng (42/43 đột biến tái dựng bị bắt, M37 tương đương); D11 đúng theo source và test.

| Finding | Xử lý |
| --- | --- |
| F1 (MEDIUM) — lựa chọn tạo yêu cầu mất khi rời trang không cảnh báo | Sửa: `hasUnsent()` tính cả loại đề xuất đã tích trong form tạo, nên `beforeunload` cảnh báo (kể cả khi form đang gập); Huỷ chủ động thì không còn gì để cảnh báo. Không lưu lựa chọn vào bộ nhớ trình duyệt. Test Node mới, đột biến bỏ mệnh đề đó bị bắt |
| F2 (LOW) — chưa đo neo D11 trên trang thật | Đo trên trang Laravel thật (Template 3, Hoạt động 97, vào bằng `#ai-authoring`, header cố định cao 157px): tiêu đề mục AI ở 265px với viewport 1440, 640 (tương đương zoom 200% của 1280) và 375 (mobile); không tràn ngang (375). Không phải màn hình đọc hay zoom thật của trình duyệt |

Điều kiện đóng P3-A theo báo cáo: xử lý F1, xác nhận neo, reviewer xác nhận lại. Chưa mở P3-B.

## 13.17. Trạng thái cuối của Phần 3 (2026-10-01)

| Phần | Làm gì | Bằng chứng | Review độc lập |
| --- | --- | --- | --- |
| P3-A | Danh sách, đọc, sửa, chấp nhận/từ chối, duyệt hàng loạt, tạo yêu cầu, lối vào từ danh sách Hoạt động (D11) | Node, PHP, MariaDB, trình duyệt thật | 6 lượt, **Owner đóng** (§13.8) |
| P3-B (B1–B4, B6) | Xem Node có sẵn; xem đích, xác nhận, áp dụng; thử lại/huỷ biên lai; admin duyệt Node mới; xác nhận lại ngữ cảnh | như trên, kèm đột biến từng lát | **Không**, Owner quyết định không gửi |
| P3-C (C1–C3) | Đề xuất kế thừa khi nguồn đổi; bản nháp bộ chuẩn kế thừa; rebase Template | như trên, kèm đột biến từng lát | **Không**, Owner quyết định không gửi |

**Chạy lại cuối cùng (2026-10-01, 2026-10-02):** toàn bộ test mặc định (1458 test PHP, 23 bỏ qua), 149 test Node, `docs:lint`, `git diff --check`, `npm run build` đều đạt. MariaDB 11.4 trên instance dùng một lần, **chạy từng file một** (chạy dồn nhiều file một lượt không xong trong 35 phút trên máy này, chạy song song 6 nhóm thì tranh nhau ổ đĩa), toàn bộ 14 file liên quan đều đạt: `AiAuthoringHttp` 49, `CourseTemplateLearningMappingHttp` 27, `AiAuthoringRebase` 3, `AiAuthoringApplicationService` 15, `AiAuthoringSuccessor` 12, `AiAuthoringPublication` 7, `AiAuthoringErasure` 8, `AiAuthoringPacketApplySafety` 4, `AiAuthoringProposalService` 25, `AiAuthoringDemoSeed` 7, `AiAuthoringProposalPacket` 20, `LearningFrameworkAuthoring` 15, `LearningFrameworkAuthoringHttp` 20, `CourseTemplateLearningMappingPromotion` 12.

**Cố ý chưa làm (cần Owner duyệt riêng):**

* B5 và danh sách ứng viên Node (D14): chọn Node khác khi sửa đề xuất, khi rebase, khi kế thừa với lý do khác.
* Bốn lý do successor còn lại ngoài `source_revision_changed` (D19).

**Chưa kiểm được (không có công cụ phù hợp trong phiên làm việc):**

* BFCache `persisted=true` thật; hai tab đồng thời; trình đọc màn hình; zoom 200% thật của trình duyệt.
* Template có hàng trăm Mapping: giao diện đã kiểm với 500 Mapping bằng test Node (vẽ, quyết định, gửi đủ 500, thân lệnh dưới 64 KB); thời gian của máy chủ cho kế hoạch lớn (nhiều truy vấn cho mỗi Mapping) **chưa đo**.
* Đổi ngữ cảnh/nguồn bằng chính màn hình sửa Hoạt động/Media (các lần thử đổi bằng SQL).
* Chất lượng gợi ý của AI thật: chưa kích hoạt provider.

**Rủi ro còn lại do không review độc lập:** các lát P3-B/P3-C do cùng người viết, kiểm và viết tài liệu. Đã giảm bằng test đối kháng, đột biến trên bản sao và thử trên trình duyệt thật (phát hiện và sửa 6 lỗi chỉ lộ ở trình duyệt/DB thật), nhưng không thay được con mắt thứ hai. Nếu về sau cần PASS độc lập, snapshot nên gồm cả F1/F2 của P3-A (§13.8).

**Tài liệu Frozen:** hai câu "chưa có UI" trong `LF-AI.md` và hợp đồng đã được thay theo chỉ dẫn của Owner (hợp đồng, khối "Owner instruction — UI status", 2026-10-01).

## 13.16. P3-C C3 — chuyển Template sang bản bộ chuẩn khác (2026-10-01)

Amendment: [LF-AI-Authoring-P3C-Amendment](LF-AI-Authoring-P3C-Amendment.md) (A4, A5, D22–D26). Endpoint có sẵn: `GET rebase-preview`, `POST rebases`. Backend chỉ thêm `display` vào `rebase-preview` (admin), **ngoài** `preview` nên `preview_hash` không đổi: theo từng Mapping, tên Bài học/Hoạt động nó gắn vào, Node đang dùng và Node sẽ dùng (cùng hình dạng `mapping_node`: mã, tên, loại, mô tả cắt 500; không `criteria`), hoặc `null` nếu không hiện được. Quyết định D23: chỉ `map` tới Node tương ứng duy nhất (`proposed_node_id`); dòng không có thì chỉ gỡ; "huỷ cả lần" là bỏ kế hoạch ở phía giao diện (máy chủ không có lệnh này).

| Bất biến | Test |
| --- | --- |
| Panel chỉ hiện khi máy chủ gợi ý `rebase`; danh sách bản đích **không có bản đang dùng**; không có bộ chuẩn hoặc không có bản nào khác thì nói lý do, không có nút; không có bản mặc định | Node, Unit, MariaDB, đột biến |
| Panel và hộp xác nhận nói rõ thao tác thay Mapping của **cả Template**, không chỉ của đề xuất, và không hoàn tác được từ đây (giọng cảnh báo) | Node, đột biến |
| Kế hoạch hiện tên Bài học/Hoạt động, Node đang dùng và Node sẽ dùng, vai trò, trọng số, nguồn gốc (thủ công/AI) và ghi chú nếu ngữ cảnh của Mapping AI đã đổi; không hiện mã số hay mã băm; Node không hiện được thì nói "không hiện được", không bịa tên | Node, đột biến |
| Mỗi Mapping phải có đúng một quyết định do admin chọn, không có mặc định; gỡ thì phải chọn lý do từ danh sách cố định; dòng không có Node tương ứng chỉ được gỡ; thiếu hay sai thì không hỏi, không gửi | Node, đột biến |
| Kế hoạch sai lời hứa (mã băm, danh sách rỗng kiểu, id trùng/không nguyên, `proposed_node_id` sai kiểu) không dùng được | Node, đột biến |
| Gửi đúng `target_version_id`, `expected_preview_hash` của kế hoạch đã xem và từng quyết định; luôn hỏi trước (giọng nguy hiểm, nêu số chuyển/gỡ); "không" không gửi gì | Node, đột biến |
| Chọn bản khác (kể cả về "không chọn") bỏ kế hoạch ngay nhưng **giữ** các quyết định đã làm; bước gửi từ chối nếu bản đã chọn không khớp kế hoạch, hay kế hoạch thuộc đề xuất/phiên bản khác | Node, đột biến |
| Quyết định chưa gửi là dữ liệu chưa gửi: tính vào `hasDraft()`/`beforeunload`, giữ khi lệnh bị từ chối và đặt lại **chỉ** ở dòng có cùng mã băm Node thay thế (và gỡ thì luôn), xoá khi mất quyền, rỗng sau khi chuyển xong | Node, đột biến |
| Bỏ kế hoạch có quyết định thì hỏi trước | Node, đột biến |
| Phản hồi muộn, xem lại, câu hỏi đang mở khi kế hoạch bị thay: không quyết định gì | Node, đột biến |
| Chưa rõ kết quả: khoá mọi ô (kể cả lý do), thử lại đúng nội dung | Node |
| Sau khi chuyển xong, phiên bản đang dùng trên trang là bản mới (bản cũ thành lựa chọn, bản mới thì không) | Node |
| Lời riêng cho: kế hoạch đã đổi (`proposal_revision_conflict`), Mapping AI đổi nghĩa (`proposal_successor_required`), đề xuất cũ (`proposal_stale`) | Node, đột biến |
| Máy chủ: `display` ngoài các trường băm, tên đúng từng Mapping, không `criteria`; kế hoạch gửi lại nguyên vẹn thì chuyển được và Template dùng bản mới | MariaDB |

**Đột biến:** 50 biến thể trên bản sao; 36 bị bắt ngay, 9 bị bắt sau khi thêm test (bản đích lạ; đang có lệnh; giữ quyết định khi đổi bản; chỉ đặt lại ở dòng còn hợp lệ; ghi chú ngữ cảnh; tên không hiện được; xoá lý do; liên kết riêng của panel). 5 còn sống, **tương đương**: bộ canh phản hồi muộn chồng nhau (`detailSeq`/`epoch`/`rebaseFields`), `target_version_id` lấy từ ô chọn thay vì kế hoạch (luôn trùng vì có kiểm khớp), giá trị nhớ không gắn đề xuất (đã dọn ngay khi dựng lại panel), đặt lại quyết định "map" cho dòng không còn Node thay thế (đã bị mã băm Node thay thế chặn trước), và `hasDraft()` không đếm quyết định từng dòng (ô chọn bản đích luôn khác rỗng khi còn dòng, nên đã đủ).

**Một thay đổi nhỏ giữ giao thức:** khoá `dispositions` được dựng bằng `Object.fromEntries` chứ không gán khoá tính toán, để không phải thêm vào danh sách gán khoá tính toán của bài kiểm tĩnh.

**Kiểm trong trình duyệt thật (MariaDB 11.4 dùng một lần, tài khoản admin), đi bằng nút bấm:** chấp nhận đề xuất dùng Node có sẵn → xem đích → xác nhận → áp dụng (sinh Mapping AI) → khi bộ chuẩn chỉ có một bản, panel nói "không còn bản khác" và không có nút → tạo bản nháp `v2` bằng C2 → xuất bản `v2` bằng dịch vụ Learning (không qua màn hình) → tải lại: danh sách bản đích chỉ có `v2`; bấm Xem khi chưa chọn thì chỉ báo chọn; kế hoạch hiện "Đọc tài liệu về phân số", vai trò, "Mapping từ đề xuất AI", Node hiện dùng và Node sẽ dùng bằng tên; chọn "Gỡ" mà chưa có lý do → "Còn 1 Mapping chưa có quyết định hợp lệ", không hỏi, không gửi; chọn "Chuyển sang Node mới" → hộp xác nhận nêu "1 chuyển, 0 gỡ, không hoàn tác"; sau đó Template dùng `v2`, Mapping AI thay bằng Mapping mới trỏ Node ở `v2` có đủ liên kết xác nhận đích và ngữ cảnh, sổ review có `rebase_target` và `rebase_selection`; ô chọn bản đích về rỗng và chỉ còn `v1`.

**Lỗi tìm thấy khi thử trên trình duyệt thật:** ô lý do gỡ luôn hiện dù đã đặt `hidden`, vì kiểu của ô nhập (`display: block`) thắng thuộc tính. Sửa bằng một quy tắc `.ai-authoring [hidden] { display: none !important; }` trong CSS (cùng các khoảng cách của bốn panel sau chấp nhận); đo lại: ẩn khi chưa chọn "Gỡ", hiện khi chọn.

**Chưa làm:** danh sách Node đích khác để `map` (hoãn cùng B5, D23). **Chưa kiểm:** trình đọc màn hình; hai tab; BFCache thật; Template có hàng trăm Mapping (kế hoạch không phân trang, mọi dòng đều hiện).

## 13.15. P3-C C2 — bản nháp bộ chuẩn kế thừa (2026-10-01)

Amendment: [LF-AI-Authoring-P3C-Amendment](LF-AI-Authoring-P3C-Amendment.md) (A4, A5, D21, D22, D25–D27). Backend chỉ thêm giá trị trả về:

* `allowed_actions` của đề xuất `accepted` loại `node_mapping` có `framework_id` có thêm `inherit_draft` và `rebase` **cho admin** (C3 sẽ dùng `rebase`; giao diện bỏ qua giá trị lạ).
* `inherited-draft-preview` (admin) có thêm `display` **ngoài** `preview`, nên `source_graph_hash`/`plan_hash` không đổi: `eligible_count`, `excluded_nodes` (mã, tên, loại, lý do `node_retired` | `definition_inactive`; tối đa 100) và `affected_intents` (**chỉ Mapping của Template này** đang dùng Node bị loại, kèm tên Bài học/Hoạt động; tối đa 100).
* Cấu hình trang có `publishedVersions` (chỉ admin, các bản đã xuất bản của bộ chuẩn Template đã chọn), cùng nguyên tắc với `draftVersions` của B4.

| Bất biến | Test |
| --- | --- |
| Panel chỉ hiện khi máy chủ gợi ý `inherit_draft`; không có bộ chuẩn hoặc không có bản đã xuất bản thì nói lý do, không có nút; không có bản mặc định | Node, Unit, MariaDB, đột biến |
| Panel nói rõ thao tác thay đổi **bộ chuẩn dùng chung**, không phải Template hay đề xuất đang mở, và danh sách Mapping bị ảnh hưởng chỉ là của Template này | Node, đột biến |
| Kế hoạch chỉ hiện tên, không hiện mã số nội bộ hay mã băm; danh sách dài bị cắt ở 100; văn bản là văn bản | Node, đột biến |
| Kế hoạch sai lời hứa (mã băm không phải băm, danh sách id sai kiểu, không có Node nào để sao chép) không dùng được để tạo | Node, đột biến |
| Mã và tên được cắt khoảng trắng, không rỗng, không quá 100/255 ký tự; có Node bị loại thì phải tích xác nhận; không đủ thì không gửi gì | Node, đột biến |
| Luôn hỏi trước, câu hỏi nêu mã bản nháp; gửi đúng hai mã băm và bản cơ sở đã xem; chọn bản cơ sở khác sau khi xem thì bỏ kế hoạch ngay và bước tạo từ chối nếu giá trị không khớp | Node, đột biến |
| Bản cơ sở, mã và tên là dữ liệu chưa gửi: tính vào `hasDraft()`/`beforeunload`, được giữ khi lệnh bị từ chối, xoá khi mất quyền | Node, đột biến |
| Phản hồi muộn, xem lại, câu hỏi đang mở khi kế hoạch bị thay: không quyết định gì | Node, đột biến |
| Chưa rõ kết quả: khoá cả ba ô, thử lại đúng nội dung | Node |
| Mã bản nháp đã dùng (máy chủ trả xung đột trùng) và kế hoạch rỗng có lời riêng | Node |
| Sau khi tạo, bản nháp mới chọn được ngay trong phần duyệt Node mới (B4) mà không cần tải lại trang | Node |
| Máy chủ: `display` không đổi các mã băm; chỉ admin; Template khác dùng chung Node không bị liệt kê; không `criteria` | MariaDB |

**Đột biến:** 46 biến thể trên bản sao; 36 bị bắt ngay, 4 bị bắt sau khi thêm test (bản cơ sở lạ, đang có lệnh, đổi bản cơ sở sau khi xem). 6 còn sống, **tương đương**: ba bộ canh phản hồi muộn chồng nhau (`detailSeq`, `epoch`, và đối tượng `inheritFields` mới cho mỗi panel; bỏ riêng một cái thì hai cái còn lại vẫn chặn), `base_version_id` lấy từ ô chọn thay vì bản đã xem (luôn trùng vì có kiểm khớp), chỉ nhận id nguyên dương khi thêm vào danh sách bản nháp (`versionList` đã lọc), và không bỏ lựa chọn nhớ giữa hai đề xuất (đã dọn ngay sau lệnh).

**Một lỗi tìm thấy khi thử trên trình duyệt thật:** sau khi tạo thành công, form vẫn giữ mã và tên vừa nhập (và `hasDraft()` còn đúng), vì giá trị nhớ để đặt lại khi bị từ chối cũng được dùng khi thành công. Sửa ở gốc: lệnh thành công xoá giá trị nhớ trước khi đọc lại; thêm test (form rỗng, `hasDraft()` sai sau khi tạo xong) và đột biến bỏ dòng đó bị bắt.

**Kiểm trong trình duyệt thật (MariaDB 11.4 dùng một lần, tài khoản admin):** chấp nhận đề xuất dùng Node có sẵn; panel hiện ra sau khi chấp nhận; xem kế hoạch khi chưa chọn bản cơ sở thì chỉ báo "Chọn bản đã xuất bản…"; sau khi chọn: "Sẽ sao chép 1 Node; 0 Node bị loại"; hộp xác nhận nêu mã bản nháp; sau khi tạo có thông báo. DB: có bản nháp `v2-ke-thua` (`draft_snapshot`) với 1 Node được sao chép, bản `v1` giữ nguyên, sổ review có `inherit_draft`. Chưa thử trên trình duyệt ca có Node bị loại (dữ liệu mẫu chỉ có một Node); ca đó được kiểm bằng test Node (hiển thị) và MariaDB (dữ liệu). Việc đổi trạng thái Definition ở test làm bằng SQL.

**Chưa làm:** C3 (rebase). **Chưa kiểm:** trình đọc màn hình; hai tab; BFCache thật.

## 13.14. P3-C C1 — đề xuất kế thừa khi nguồn đổi (2026-10-01)

Amendment: [LF-AI-Authoring-P3C-Amendment](LF-AI-Authoring-P3C-Amendment.md) (D18–D27 đã duyệt). Backend thêm đúng ba thứ, đều chỉ là giá trị trả về: `create_successor` trong `allowed_actions` của đề xuất `stale` từng được chấp nhận và còn payload chưa xoá; và hai trường `inherited_decision_draft`, `successor_reason` trong chi tiết (hợp đồng yêu cầu người duyệt kế tiếp thấy cả cờ lẫn lý do mà `show()` chưa trả). Ba endpoint (`successor-preview`, `successor-source-scope`, `successors`) đã có.

| Bất biến | Test |
| --- | --- |
| Panel chỉ hiện khi máy chủ gợi ý `create_successor`; đề xuất bị ẩn không lộ gì của nội dung cũ; nút Tạo chỉ có sau khi đã xem | Node, MariaDB, Unit |
| Chỉ hiện đúng các trường máy chủ được phép trao (loại, tiêu đề, nội dung, vai trò, trọng số); lý do/độ tin cậy/trích dẫn không bao giờ hiện dù phản hồi có | Node, đột biến |
| Máy chủ từ chối kế thừa (`proposal_stale`, `proposal_successor_node_conflict`): nói rõ, không đọc nguồn, không có nút | Node |
| Phản hồi không đúng lời hứa (cờ sai, lý do khác `source_revision_changed`, không có payload, mã nguồn không phải băm, trùng mã, `anchor` không phải đối tượng) không dùng được | Node, đột biến |
| Nguồn: đọc từng trang 100, trả con trỏ đúng như nhận (mã hoá), tối đa 200; hơn 200, hoặc danh sách chưa đọc hết sau số trang cho phép, hoặc rỗng thì không gửi; không cắt bớt | Node, đột biến |
| Gửi đúng `reason`, `payload = null`, toàn bộ mã băm nguồn đã xem (sắp xếp) và mã yêu cầu; luôn hỏi trước; "không" không gửi gì | Node, đột biến |
| Bước tạo chỉ chạy từ bản xem đúng đề xuất và đúng phiên bản; xem lại làm bản xem cũ mất ngay; câu hỏi đang mở mà bản xem bị thay thì câu trả lời không quyết định gì | Node, đột biến |
| Phản hồi đến muộn (đã sang đề xuất khác, kể cả khi đề xuất kế có cùng panel; ở bước đọc nguồn; mất quyền) không hiện gì; mất quyền gỡ bản nháp khỏi mọi nơi script giữ | Node, đột biến |
| Kết quả chưa rõ khoá biểu mẫu, thử lại đúng nội dung và đúng mã, và vẫn mở đề xuất mới; phản hồi không nêu đề xuất hợp lệ thì đọc lại đề xuất đang mở | Node, đột biến |
| Đề xuất kế thừa hiện rõ "kế thừa, chưa duyệt" và lý do; đề xuất thường thì không | Node, MariaDB |
| Chỉ lý do `source_revision_changed`; bốn lý do kia hoãn cùng B5 (D19) | Node |

**Đột biến:** 35 biến thể trên bản sao; 24 bị bắt ngay, 9 bị bắt sau khi thêm test (con trỏ và cỡ trang; danh sách chưa đọc hết; xem lại; câu hỏi đang mở; đang có lệnh; cắt tiêu đề; kiểm tra phản hồi tạo). 2 còn sống, **tương đương**: (1) bỏ kiểm tra `epoch` ở bản xem, vì mất quyền luôn qua `dropDetail` làm tăng `detailSeq`; (2) không đặt `successorHost` về null khi gỡ, vì `scrub` đã làm rỗng toàn bộ nội dung nó giữ.

**Một thay đổi giao diện cần biết:** chi tiết bị ẩn trước đây không giữ `detailData`. Giờ nó được giữ **chỉ** khi máy chủ gợi ý `create_successor`, để chạy được lệnh. `payload` của nó là `null`, nên không có gì để rò.

**Kiểm trong trình duyệt thật (MariaDB 11.4 dùng một lần, tài khoản giáo viên):** đổi phiên bản xử lý của nguồn (như Media xử lý lại); mở lại: đề xuất đã chấp nhận thành `stale` kèm panel; Xem bản nháp kế thừa hiện loại, tiêu đề, nội dung và "1 nguồn hiện tại"; Tạo hỏi trước; sau đó đề xuất mới `pending_review` mở ngay, có thông báo, hiện "kế thừa, chưa duyệt" và lý do, có Sửa/Chấp nhận/Từ chối. DB: đề xuất mới `human_successor`, `inherited_decision_draft = 1`, `successor_reason = source_revision_changed`; đề xuất cũ vẫn `stale`; đúng 1 yêu cầu `human_successor`. Việc đổi nguồn làm bằng SQL, không qua màn hình Media.

**Chưa làm:** C2 (bản nháp kế thừa), C3 (rebase). **Chưa kiểm:** trình đọc màn hình; hai tab; BFCache thật; đề xuất kế thừa cho `node_mapping` (dữ liệu mẫu chỉ có `stale` loại tóm tắt).

## 13.13. P3-B B6 — xác nhận lại ngữ cảnh khoá học (2026-10-01)

Dùng hai endpoint có sẵn: `GET context-preview` và `POST context-confirmations`. Backend chỉ sửa một điều: trong chi tiết đề xuất đã chấp nhận, `context_changed` giờ bằng đúng phép so mà `allowed_actions` đã dùng (ngữ cảnh hiện tại so với ngữ cảnh của lần xác nhận lại gần nhất, hoặc với ngữ cảnh lúc sinh nếu chưa có). Trước đó nó bỏ qua xác nhận lại và vĩnh viễn `true`. Làm rõ này được ghi vào khối "Owner amendment — P3-B" của hợp đồng; **cần Owner xác nhận** vì hợp đồng là Frozen.

| Bất biến | Test |
| --- | --- |
| Panel "Ngữ cảnh khoá học đã đổi" chỉ hiện khi máy chủ gợi ý `reconfirm_context` (cả admin lẫn giáo viên); không có nút nào khi không được gợi ý | Node, đột biến |
| Chỉ xác nhận được cái đã xem: nút Xác nhận chỉ có sau khi xem; lệnh từ chối nếu bản xem không thuộc đúng đề xuất và đúng phiên bản (`lock_version`) đang mở; bản xem cũ biến mất khi đọc lại, khi xem lại và khi chuyển đề xuất | Node, đột biến |
| Mã băm gửi đi là mã của bản đã xem; phản hồi thiếu mã hợp lệ hoặc thiếu ngữ cảnh thì không có gì để xác nhận | Node, đột biến |
| Chỉ hiện tiêu đề, hướng dẫn, trình độ (cắt 255/1000/100 ký tự, là văn bản); không hiện mã khách hàng, Template, bài học, mã băm; không hứa so sánh với bản cũ | Node, đột biến |
| Luôn hỏi trước; "không" không gửi gì; trả lời đến sau khi đã chuyển đề xuất hay mất quyền không quyết định gì | Node, đột biến |
| Lệnh đóng băng như mọi lệnh: chưa rõ kết quả thì khoá, thử lại đúng mã yêu cầu và đúng mã băm | Node |
| Ngữ cảnh đổi thêm lần nữa trong lúc xem: 409 `proposal_context_changed`, nói rõ, đọc lại, phải xem lại | Node |
| `context_changed` đúng ở cả ba thời điểm: đổi (`true`), sau xác nhận lại (`false`), đổi lần nữa (`true`) và khớp với `reconfirm_context` trong `allowed_actions` | MariaDB (`AiAuthoringHttpMariaDbTest`, 43 test) |

**Tổng quát hoá:** bước xác nhận dùng chung `targetStep` với các bước đích (định nghĩa `view`/`field`/`ask` trong `TARGET_STEPS`), không viết đường riêng; vì thế quy tắc "chỉ gửi mã băm của bản đã xem, cho đúng phiên bản" có một định nghĩa duy nhất.

**Đột biến:** 16 biến thể trên bản sao; 9 bị bắt ngay, 6 bị bắt sau khi thêm test (đề xuất kế tiếp cũng có panel nên phản hồi đến muộn không được rơi vào; xem lại thì bản xem cũ phải mất ngay; cắt tiêu đề; giọng cảnh báo của từ chối đích; không đọc ngữ cảnh khi có lệnh đang gửi; so mã đề xuất). 1 còn sống, **tương đương**: bỏ kiểm tra `epoch` ở bản xem, vì mất quyền luôn đi qua `dropDetail` làm tăng `detailSeq`, nên kiểm tra `seq` đã đủ.

**Kiểm trong trình duyệt thật (MariaDB 11.4 dùng một lần):** chấp nhận đề xuất dùng Node có sẵn; đổi mô tả Hoạt động trong DB; mở lại: có banner và panel; Xem ngữ cảnh hiện tại hiện tiêu đề, hướng dẫn mới, trình độ; Xác nhận ngữ cảnh hỏi trước; sau đó banner và panel mất, lịch sử duyệt có "Xác nhận lại ngữ cảnh". Lưu ý: phần đổi mô tả làm bằng SQL, không qua màn hình sửa Hoạt động; đường bấm tới trang là đường D11 cũ.

**Chưa làm:** B5 (chọn Node thủ công, hoãn theo D14), P3-C. **Chưa kiểm:** trình đọc màn hình; hai tab; BFCache thật; đổi ngữ cảnh bằng chính màn hình sửa Hoạt động.

## 13.12. P3-B B4 — admin duyệt Node mới (2026-09-30)

Endpoint có sẵn: `POST proposals/{uuid}/node-approvals` (chỉ admin). Backend chỉ thêm dữ liệu cấu hình trang: `draft_versions` trong lối vào trang Hoạt động — các phiên bản **nháp** (`draft_snapshot`) của **Framework Template đã chọn**, của khách hàng hiện tại, tối đa 50, nhãn `version_code — title_snapshot`; giáo viên và Template chưa chọn Framework nhận danh sách rỗng. Trang chỉ nhận id và nhãn, không nhận nội dung đề xuất.

| Bất biến | Test |
| --- | --- |
| Nút "Duyệt và tạo Node" chỉ hiện khi máy chủ gợi ý `approve_node` (chỉ admin); giáo viên chỉ thấy ghi chú "đang chờ quản trị viên" | Node, đơn vị `AuthoringAllowedActionsTest` |
| Bản nháp **không có mặc định**: chưa chọn thì bấm không gửi và không hỏi; giá trị không thuộc danh sách được cấp thì không gửi | Node, đột biến |
| Không có Framework đã chọn, hoặc không có bản nháp: nói rõ lý do, có liên kết tới tab Đầu ra & năng lực, không có nút | Node |
| Luôn hỏi trước, câu hỏi nêu tên bản nháp; "không" không gửi gì; câu trả lời đến sau khi đã chuyển đề xuất không quyết định gì | Node, đột biến |
| Lệnh gửi đúng `framework_id` của Template, `framework_version_id` đã chọn, `expected_revision_no`, `expected_lock_version` đã đọc; là lệnh đóng băng (chưa rõ kết quả thì khoá lựa chọn, thử lại cùng mã và nội dung) | Node, đột biến |
| Bản nháp đã chọn là dữ liệu chưa gửi: tính vào `hasDraft()` (hỏi khi rời, cảnh báo khi đóng trang), được giữ khi lệnh bị từ chối, không mang sang đề xuất khác, bị xoá khi mất quyền | Node, đột biến |
| Nhãn bản nháp là văn bản (`textContent`); id không phải số nguyên dương bị bỏ | Node, đột biến |
| Trang cấp danh sách đúng: chỉ nháp, chỉ Framework đã chọn, không lộ cho giáo viên | Trang thật trên MariaDB (`CourseTemplateLearningMappingHttpMariaDbTest`), Feature (khoá cấu hình) |

**Đột biến:** 19 biến thể trên bản sao; 14 bị bắt ngay, 2 bị bắt sau khi thêm test (nhận trả lời muộn sau khi chuyển đề xuất; bỏ giá trị của ô chọn khi thay). 5 biến thể còn sống được ghi là **tương đương**, không ép đỏ: (1) bỏ kiểm tra Framework trong lệnh — panel đã không tạo ô chọn khi thiếu Framework nên lệnh trả về sớm; (2)–(3) không dọn `pendingDraft` khi `pagehide`/khi quên đề xuất, và (4) không kiểm mã đề xuất khi đặt lại lựa chọn — vì lựa chọn được dọn ngay sau lệnh (`approveNode`), không còn gì để mang đi; (5) bỏ việc xoá giá trị ô chọn khi gỡ — `scrub` của `dropDetail` đã làm việc đó.

**Kiểm trong trình duyệt thật (MariaDB 11.4 dùng một lần, Template dữ liệu mẫu):** đi từ Template → tab Nội dung → "Đề xuất AI" → mở đề xuất Node mới → Chấp nhận → panel hiện chỉ sau khi đã chấp nhận; bấm khi chưa chọn không gửi gì; chọn bản nháp thì hộp xác nhận nêu tên bản nháp; sau khi xác nhận: 1 biên lai `create_node` đã áp dụng, Node mới nằm trong bản nháp (không phải bản đã công bố), 0 ý định Mapping. Ghi chú của biên lai `create_node` được nói riêng ("Node đã được tạo trong bản nháp ... chưa có Mapping nào được ghi") vì ghi chú chung của biên lai đã áp dụng nói về ý định Mapping.

**Chưa làm:** xác nhận lại ngữ cảnh (B6, kèm sửa `context_changed` vẫn `true` sau khi xác nhận lại), chọn Node thủ công (B5, hoãn theo D14), P3-C. **Chưa kiểm:** trình đọc màn hình; hai tab; BFCache thật.

## 13.11. P3-B B3 — thử lại và huỷ biên lai (2026-09-30)

Không cần thay đổi backend: dùng `allowed_actions` cấp biên lai của B2 và hai endpoint có sẵn (`applications/{uuid}/retry`, `/cancel`).

| Bất biến | Test |
| --- | --- |
| Nút Thử lại/Huỷ chỉ hiện khi **biên lai đó** được máy chủ gợi ý; mã biên lai không phải UUID thì không có nút (không dựng đường dẫn từ chuỗi lạ) | Node: biên lai chỉ `retry`, mã lạ, `create_node` giáo viên |
| Cả hai lệnh luôn có câu hỏi trước; trả lời "không" không gửi gì và giữ lựa chọn | Node |
| Lệnh là lệnh đóng băng như mọi lệnh khác: đang gửi và kết quả chưa rõ đều **khoá cả nút của biên lai**; thử lại gửi đúng mã yêu cầu | Node |
| Lý do huỷ là mã hợp lệ từ danh sách cố định (mặc định là mã đầu); không gửi mã khác, không gửi khi không có lựa chọn | Node |
| Lý do đã chọn khác mặc định là dữ liệu chưa gửi: tính vào `hasDraft()` (nên hỏi khi rời, cảnh báo khi đóng trang), được giữ khi lệnh bị từ chối, bị xoá khi mất quyền | Node, đột biến |
| Thử lại không đổi người duyệt ban đầu; giao diện không hiển thị `approved_by` | Backend đã có test; giao diện chỉ nêu câu trong hộp xác nhận |
| Kết quả bị từ chối vì đích/ngữ cảnh đổi được nói rõ và đề xuất được đọc lại | Node |

**Chưa làm:** admin duyệt Node mới và thử lại `create_node` bằng nút riêng của admin (B4 dùng cùng cơ chế), xác nhận lại ngữ cảnh (B6).

## 13.10. P3-B B2 — sau chấp nhận: xem đích, xác nhận, áp dụng (2026-09-30)

Thực hiện amendment A1 ([amendment](LF-AI-Authoring-P3B-Amendment.md), hợp đồng v0.9). Backend: một định nghĩa duy nhất
`AuthoringAllowedActions` (hàm thuần, dùng cho chi tiết, danh sách và `show`); chi tiết tính đầy đủ từ trạng thái đã đọc,
không đọc thêm Learning. Giao diện: khối "Áp dụng vào Template" chỉ hiện các bước máy chủ gợi ý.

**Bất biến của lát này**

| Bất biến | Test |
| --- | --- |
| Không xác nhận được thứ chưa xem: nút xác nhận/từ chối chỉ xuất hiện trong khối đích đã hiện; bước xác nhận tự từ chối nếu đích không được xem cho **đúng phiên bản** đề xuất | Node: thứ tự bước, hai ca gọi thẳng `targetStep`, đột biến |
| Hash gửi đi là hash của đích đã hiện, khoá là khoá đã đọc; hash không đúng dạng thì không có đích xác nhận được | Node: nội dung POST, 4 dạng hash sai |
| Áp dụng luôn có câu hỏi nói rõ "chưa phải Mapping chính thức, chỉ chính thức khi xuất bản, không hoàn tác từ đây"; huỷ thì không gửi gì | Node |
| Sau mỗi bước đọc lại đề xuất; đích cũ biến mất cùng lần đọc lại; đích đổi thì báo đổi và phải xem lại | Node: 409 ở xem và ở xác nhận |
| Kết quả chưa rõ khoá biểu mẫu, thử lại đúng mã yêu cầu và đúng hash | Node |
| Phản hồi xem đích đến muộn (đã sang đề xuất khác) hoặc sau khi mất quyền không hiện gì | Node, kiểm tham chiếu còn giữ |
| Không hiện định danh nội bộ (tenant, Framework, id Node); văn bản đích là văn bản | Node |
| Mỗi biên lai nói nghĩa của trạng thái, không bao giờ gọi là Mapping chính thức; liên kết tới tab Mapping chỉ cho admin và chỉ theo địa chỉ web thường (bắt được `//host`) | Node, Host, đột biến |
| `allowed_actions` chỉ đưa ra hành động mà lệnh không từ chối vì chính trạng thái đó; danh sách giữ nguyên ba giá trị chờ duyệt | MariaDB: 8 ca; Unit: 21 ca của bảng đầy đủ |

**Phát hiện cần xử lý ở B6:** `context_changed` trong chi tiết so ngữ cảnh hiện tại với hash ban đầu của đề xuất và **không tính lần
xác nhận lại ngữ cảnh**, nên vẫn `true` sau `reconfirm_context`. `allowed_actions` của B2 dùng phép so có tính xác nhận lại; trường
`context_changed` (và ghi chú hiện có ở giao diện) sẽ sửa cùng lát B6.

**Chưa làm:** thử lại và huỷ biên lai (B3), admin duyệt Node mới (B4), xác nhận lại ngữ cảnh (B6).

## 13.9. P3-B B1 — xem và duyệt đề xuất dùng Node có sẵn (2026-09-30)

Thực hiện theo [amendment đã duyệt](LF-AI-Authoring-P3B-Amendment.md) (D12–D17) và hợp đồng v0.9.

**Bất biến của lát này** (viết trước khi cài đặt, mỗi cái có test):

| Bất biến | Test |
| --- | --- |
| `mapping_node` chỉ có ở chi tiết của `node_mapping` `reuse_existing` hiện được, là trường anh em của `payload`, không có `criteria`, mô tả tối đa 500 ký tự | MariaDB: 6 ca trong `AiAuthoringHttpMariaDbTest` (giáo viên, admin, loại khác, danh sách, mô tả cắt, khác tenant) |
| Chỉ lộ Node khi payload được lộ (mất quyền nguồn hay hết quyền thì không) | MariaDB: detach Media, phân công đã kết thúc |
| Chỉ thấy khi khớp **đúng** cặp đã lưu: Framework đang hoạt động, Version đã xuất bản, Node đang hoạt động, đúng Definition | MariaDB: cổng đọc với id sai từng thành phần |
| Giao diện coi `mapping_node` là hợp lệ chỉ khi `node_id`/`definition_id` trùng payload và có nhãn; không thì chỉ còn **Từ chối** và có ghi chú | Node: 5 dạng không hợp lệ, id không khớp |
| Số `node_id`/`definition_id` không bao giờ hiện như một nhãn | Node: id không khớp không hiện nhãn của Node khác |
| Sửa `reuse_existing` chỉ đổi văn bản, vai trò, trọng số; giữ nguyên `node_id`/`definition_id`; `mapping_node` không bao giờ gửi ngược | Node: nội dung PATCH, đột biến đổi id |
| Bulk chấp nhận loại này chỉ khi Node đã được nhìn thấy | Node: bulk hai đề xuất |
| Sửa `propose_new` không đổi hành vi | Node: nội dung PATCH đầy đủ |
| Chấp nhận vẫn ≠ áp dụng (ghi chú, hộp xác nhận không đổi) | Không đổi so P3-A |

Điều cần lưu ý cho reviewer: quyền xem nhãn Node cho giáo viên là quyết định D13; đọc qua `LearningAuthoringBasisService::nodeDisplay`,
không qua `LearningFrameworkReadService` (chỉ admin). Bộ chọn Node khác (B5) **chưa làm** (D14). Sau chấp nhận vẫn chưa có nút nào (B2).

## 13.8. Đóng P3-A (Owner, 2026-09-30)

**Owner đóng P3-A ngày 2026-09-30, không chạy lượt review thứ 7.** Căn cứ: lượt 6 của reviewer độc lập là
APPROVE WITH CHANGES với 0 BLOCKER, 0 HIGH; quy tắc của [brief](../quality/LF-AI-Authoring-Review-UI-P3A-Reviewer-Brief.md)
chỉ bắt xác nhận lại các finding HIGH trở lên, còn MEDIUM/LOW được đăng ký.

Đây **không** phải một PASS độc lập cho phần đã sửa sau lượt 6: F1 (MEDIUM) đã sửa và có test, F2 (LOW) mới được
implementer đo, cả hai **chưa được reviewer xác nhận lại**. Bản đóng này là quyết định của Owner kèm rủi ro còn lại
dưới đây; nó không thay cho PASS (waiver ≠ PASS).

| Mục còn lại | Mức | Chủ | Hạn / cách xử lý |
| --- | --- | --- | --- |
| Xác nhận độc lập F1 (`hasUnsent` tính lựa chọn tạo yêu cầu) và F2 (neo D11, số đo của implementer) | MEDIUM / LOW | Architecture Team | Đưa vào snapshot của lượt review đầu tiên của P3-B, không mở lượt riêng |
| BFCache `persisted=true` thật; hai tab đồng thời; zoom 200% và trình đọc màn hình thật | MEDIUM | Architecture Team | Ca kiểm thủ công có ghi lại, trước khi đóng P3-B |
| Chưa chạy lại test MariaDB (seed, Learning mapping, backend AI HTTP) ở các lượt cuối | LOW | Architecture Team | CI job `integration-mysql` đã liệt kê các file này; chạy trước khi đóng P3-B |
| Lỗi media 7 ca của full suite trong bản sao reviewer (thiếu runtime) | Ngoài P3-A | — | Không liên quan P3-A; baseline của repo gốc không đổi |
| Hai câu "chưa có UI" trong `LF-AI.md` (dòng 75 và 412, Frozen) và trong hợp đồng (Frozen) đã lỗi thời so với P3-A | Tài liệu | Architecture Team | Cần amendment do Owner duyệt; không sửa tài liệu Frozen ở đây |

**Trạng thái sau đóng:** P3-A gồm bốn lát, D11 và các sửa theo lượt 1–6; `Implementation Status` của tài liệu này vẫn
`Partial` vì P3-B và P3-C chưa làm. **P3-B chưa mở**: cần Owner duyệt hai amendment (`allowed_actions` mở rộng; DTO tên và
ứng viên Node) và tuân theo [nguyên tắc kỹ thuật giao diện bất đồng bộ](../quality/LF-UI-Async-State-Engineering-Practice.md)
(bất biến và test đối kháng trước khi viết logic, lát nhỏ, danh sách kiểm trước khi gửi review).

## 13.5. D11 — lối vào từ danh sách Hoạt động (2026-09-30)

Thay đổi nhỏ, ngoài các finding review: `CourseTemplateController@edit` tính một lần `aiAuthoringAvailable`
(cùng `authoringRole` mà mọi lệnh đề xuất kiểm lại) và chuyển cho danh sách Hoạt động; dòng Hoạt động
có Media có liên kết `data-ai-authoring-entry` tới `…/activities/{id}#ai-authoring` (qua section khi Bài học nằm
trong section). `CourseTemplateActivityController` thêm `media_supported`; khi `false`, partial chỉ hiện ghi chú, không có
cấu hình script, danh sách hay form. Test: `AiAuthoringActivityEntryTest` (12 ca: theo loại, section, ba vai trò AI,
người chỉ mở được trang, phân công đã kết thúc, ghi chú và mục AI trên trang chi tiết). Chưa qua review độc lập;
nằm trong snapshot của lượt review kế tiếp (lượt 6).

Điều khác với thiết kế, để reviewer biết:

* Sửa `node_mapping` kiểu `propose_new` cho phép sửa cả tiêu chí dưới dạng JSON (kiểm ở
  trình duyệt, máy chủ là bên quyết định); không sửa `confidence` và `source_refs`.
* Đề xuất `reuse_existing` chỉ có nút Từ chối, không có Sửa hay Chấp nhận (D9).
* Lệnh dữ liệu mẫu từ chối ghi khi thư mục media của tenant mẫu đã tồn tại, sau một lần
  suýt ghi nhầm vào thư mục của tenant thật khi thử trên database tạm.

---

# Owner

Architecture Team

# Primary Consumers

* Backend Developers
* Frontend Developers
* Course authors (giáo viên, admin)
