# AI Authoring P3-B — Amendment and Slice Plan

Version: 1.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-10-01

Document Path: platform/LF-AI-Authoring-P3B-Amendment.md

Related Specification:

* [LF-AI-Authoring-Review-UI-Design](LF-AI-Authoring-Review-UI-Design.md) (§5–§8, §10, §13.8)
* [LF-AI-Authoring-Proposal-Contract](LF-AI-Authoring-Proposal-Contract.md) (Frozen)
* [LF-UI-Async-State-Engineering-Practice](../quality/LF-UI-Async-State-Engineering-Practice.md)

---

# 1. Trạng thái và cách đọc

**Owner đã duyệt D12–D17 theo đề xuất ngày 2026-09-30** (mục 9). Hợp đồng Bước 7 đang Frozen; hai amendment A1 và A2.1 được ghi vào hợp đồng
bằng khối "Owner amendment — P3-B" (D17). A2.2 (endpoint danh sách ứng viên) **chưa được duyệt** (D14: hoãn). Mọi thay đổi hình dạng dữ liệu hay quyền
ngoài phạm vi đã duyệt vẫn phải qua amendment mới (thiết kế §7). Tài liệu này
gồm: (2) P3-B đem lại gì, (3) sự thật đã kiểm trong mã làm nền cho hai amendment, (4) Amendment A1 (`allowed_actions`),
(5) Amendment A2 (hiển thị Node và ứng viên), (6) các việc **không** cần amendment, (7) kế hoạch lát cắt kèm bất biến,
(8) kế hoạch kiểm chứng, (9) các quyết định cần Owner. Nguồn dữ kiện được ghi kèm đường dẫn để kiểm lại.

# 2. P3-B đem lại gì

P3-A cho giáo viên xem, sửa và **chấp nhận** đề xuất, nhưng chấp nhận chỉ ghi nhận "đã xem xét". Chỉ đề xuất
**Ánh xạ Node** có đường ra dữ liệu thật; P3-B mở đường đó trên giao diện:

```text
Ánh xạ Node (dùng Node có sẵn):
  chấp nhận → xem đích (tên Node, quan hệ) → xác nhận đích → áp dụng vào Template làm việc
            → dòng mới trong "Mapping hiện có" (tab Đầu ra & năng lực) → xuất bản Template neo Mapping chính thức

Ánh xạ Node (Node mới):
  giáo viên chấp nhận → (chờ admin) → admin chọn bản nháp Framework → tạo Node → xác nhận đích → áp dụng
```

Hiện nay (P3-A) đường này bị chặn hai chỗ: giáo viên **không thấy Node nào** (payload chỉ có số, xem 3.3) nên P3-A không cho
chấp nhận loại "dùng Node có sẵn", và không có nút cho các bước sau chấp nhận.

# 3. Sự thật đã kiểm trong mã (nền của hai amendment)

## 3.1. `allowed_actions` hiện chỉ có ba giá trị, ở hai nơi

`AiAuthoringProposalService::show` (dòng 387) và `AiAuthoringHttpReadService::listing` (dòng 78) trả
`['edit','accept','reject']` khi `pending_review` và nội dung hiện được; mọi trạng thái khác trả `[]`. Hợp đồng gọi nó là gợi ý
"tính theo người dùng hiện tại, không phải capability token" nhưng chưa đóng danh sách tên.

## 3.2. `applications` chỉ có bốn trường

`AiAuthoringHttpReadService::detail` (dòng 136–148): `application_uuid`, `operation`, `status`, `approved_by`, tối đa 100,
cố ý **không** trả snapshot đích hay ngữ cảnh ("có thể giữ nội dung cũ"). Không có thông tin nào cho biết receipt nào retry/cancel
được, hay Node đích là gì.

## 3.3. Payload `reuse_existing` chỉ có số

Payload là `{mode, node_id, definition_id, role, weight}`, số nguyên. Tên Node chỉ xuất hiện ở `target-preview`
(`LearningAuthoringTargetService::targetSnapshot`, snapshot có `code`, `label`, `description`, `node_type`, `criteria`, quan hệ),
mà endpoint đó **chỉ chạy sau khi đã chấp nhận** (`AiAuthoringApplicationService::targetPreview`, dòng 130: yêu cầu `status = accepted`).
Vì vậy giáo viên không thể xem Node trước khi quyết định. Ràng buộc "Node phải nằm trong danh sách ứng viên" do
`AuthoringPayloadValidator::mapping` áp, với ứng viên lấy từ `LearningAuthoringBasisService::proposalBasis` (Version đã xuất bản,
Node `active`, tối đa `MAX_CANDIDATES = 500`).

## 3.4. Quyền đọc Learning hiện chỉ dành cho admin tenant

`LearningFrameworkReadService` (ví dụ `nodeLabels`, `activeNodesForPublishedVersion`) đều qua `customerAdminTenantId($actorId)`: **giáo viên
không đọc được** nhãn hay danh sách Node. `LearningAuthoringBasisService` và `LearningAuthoringTargetService` là đường riêng, chạy trong
ngữ cảnh tenant, không kiểm quyền người gọi; quyền do dịch vụ AI kiểm ở trên (`CourseAuthoringContextService::actorRole`). Nghĩa là:
để giáo viên thấy tên Node phải **quyết định rõ** mở một phần dữ liệu Learning cho người có quyền AI (5.1).

## 3.5. Các endpoint sau chấp nhận đã có

`target-preview`, `target-confirmations`, `target-rejections`, `context-preview`, `context-confirmations`, `intent-applications`,
`applications/{uuid}/retry`, `applications/{uuid}/cancel` (quyền "Both"), và `node-approvals` (chỉ admin). Hợp đồng HTTP cấm thêm endpoint
cho Mapping chính thức, publish, kích hoạt provider (mục "No new HTTP endpoint…"); danh sách ứng viên (A2.2) là endpoint mới và cần
amendment.

# 4. Amendment A1 — Từ vựng và ngữ nghĩa của `allowed_actions`

## 4.1. Nguyên tắc (không đổi so hợp đồng)

* Gợi ý cho giao diện, **tính lại mỗi lần đọc**, theo người dùng hiện tại; không lưu; không phải capability token.
* Mọi POST vẫn kiểm lại quyền, khoá, trạng thái; giao diện chịu 403/404/409 dù hành động từng được gợi ý.
* Không serialize snapshot của receipt; không thêm quyền; không cần schema mới.
* Chỉ **thêm** giá trị; giá trị hiện có (`edit`, `accept`, `reject`) giữ nguyên nghĩa. Client không biết giá trị lạ thì bỏ qua.

## 4.2. Từ vựng đóng đề xuất (cấp đề xuất, có trong chi tiết)

| Giá trị | Khi nào có (ai) | Endpoint tương ứng |
| --- | --- | --- |
| `edit`, `accept`, `reject` | `pending_review`, nội dung hiện được (như hiện nay) | như hiện nay |
| `preview_target` | `accepted`, `node_mapping`, đích xác định được (reuse, hoặc propose_new đã có receipt `create_node` `applied`), nguồn còn đúng (cả hai vai) | `GET target-preview` |
| `confirm_target` | như `preview_target` và chưa có receipt `apply_intent`; hoặc đích đổi và cần xác nhận lại (cả hai vai) | `POST target-confirmations` |
| `reject_target` | chỉ khi receipt `apply_intent` đã `applied` (từ chối để chặn xuất bản về sau); receipt chưa applied dùng `cancel` (cả hai vai) | `POST target-rejections` |
| `apply_intent` | có receipt `apply_intent` ở `ready_to_apply` (cả hai vai) | `POST intent-applications` |
| `reconfirm_context` | `accepted` và `context_changed = true` (cả hai vai) | `GET context-preview` rồi `POST context-confirmations` |
| `approve_node` | `accepted`, `node_mapping` `propose_new`, chưa có receipt `create_node`, người gọi là admin | `POST node-approvals` |

Tên `preview_target`/`confirm_target`/`reject_target`… trùng từ vựng thao tác duyệt đã có trong sổ review (`REVIEW_ACTIONS` ở giao diện).
Chuỗi `create_successor`, `inherit_draft`, `rebase_selection` **không** thuộc đề xuất này (xem quyết định D15).

## 4.3. Từ vựng cấp receipt (thêm vào từng phần tử `applications`)

Mỗi phần tử `applications` có thêm `allowed_actions` (mảng, có thể rỗng):

| Giá trị | Khi nào có | Endpoint |
| --- | --- | --- |
| `retry` | receipt `failed`; `apply_intent`: cả hai vai; `create_node`: chỉ admin | `POST applications/{uuid}/retry` |
| `cancel` | receipt `awaiting_publication`, `ready_to_apply` hoặc `failed`; không bao giờ `applied` | `POST applications/{uuid}/cancel` |

Người gọi biết **receipt nào** nhờ `application_uuid` đã có. Bốn trường cũ (`application_uuid`, `operation`, `status`, `approved_by`) giữ nguyên.

## 4.4. Cách tính

* Chỉ dựa trên trạng thái đã đọc (đề xuất, revision đã chấp nhận, receipt, vai người gọi, độ mới nguồn/ngữ cảnh đã tính trong `show`),
  **không** đọc thêm Learning để đoán đích còn hợp lệ. Vì thế `confirm_target` có thể có mà `target-preview` vẫn trả `proposal_target_changed`;
  giao diện xử lý như mọi 409.
* Một hàm duy nhất trong dịch vụ đề xuất, dùng cho cả `show` và `detail`; bỏ bản sao ở `listing`. **Danh sách giữ nguyên** (chỉ ba giá trị
  của `pending_review`), để không thêm truy vấn theo từng hàng; giao diện lấy tập đầy đủ từ chi tiết.
* Mất quyền đọc nguồn hay nội dung bị ẩn: trả `[]` như hiện nay.

## 4.5. Tương thích và kiểm chứng

Thêm giá trị và thêm trường `allowed_actions` trong `applications` không làm hỏng client cũ. Cần: bảng test theo (trạng thái × vai × loại × receipt)
trên MariaDB; hồi quy cho người dùng cũ (chỉ thấy ba giá trị khi pending); test rằng mỗi giá trị **có** ↔ POST tương ứng không bị chặn vì
trạng thái/quyền (không phải bảo đảm thành công, mà không bị từ chối vì chính điều kiện đã dùng để gợi ý).

# 5. Amendment A2 — Hiển thị Node và ứng viên cho người có quyền AI

## 5.1. Vấn đề quyền cần Owner quyết

Muốn duyệt một đề xuất "dùng Node có sẵn", người duyệt phải thấy Node đó là gì. Giáo viên hiện **không có quyền đọc Learning** (3.4). Amendment
mở một đường **hẹp, chỉ đọc, chỉ hiển thị** cho người có quyền AI: không nới quyền quản trị của Learning, không cho giáo viên sửa bộ chuẩn,
không thêm quyền vào trang Learning.

## 5.2. A2.1 — `mapping_node` trong chi tiết đề xuất

Với đề xuất `node_mapping` `reuse_existing` hiện được, chi tiết có thêm trường anh em của `payload` (không lồng vào `payload`, để vòng sửa
không phải mang trường chỉ đọc):

```json
"mapping_node": { "node_id": 123, "definition_id": 45, "code": "KOR-HANGUL-01", "label": "Đọc và viết Hangul cơ bản",
                  "node_type": "objective", "description": "…tối đa 500 ký tự…", "status": "active" }
```

hoặc `null` khi không xác định được (Node đã lưu trữ, Version không còn xuất bản, Framework khác lựa chọn của Template). Nguồn: một hàm mới, hẹp,
trong `LearningAuthoringBasisService`, tra **đúng** cặp `(framework_id, framework_version_id)` đã lưu trên đề xuất. Không trả `criteria`
(có thể dài và là nội dung soạn thảo); cần thì lấy từ `target-preview` sau chấp nhận. Cùng quyền và cùng kiểm toán truy cập nội dung như
`show` (kiểm quyền Media trước khi lộ, lỗi kiểm toán thì huỷ tiết lộ). Trường này không tham gia hash nào và không đổi `payload`.

## 5.3. A2.2 — Danh sách ứng viên Node (endpoint mới, tuỳ chọn)

`GET B/node-candidates` (B = tiền tố Activity hiện có), quyền "Both": danh sách ứng viên của **Version mà Template đã chọn**, không nhận
`framework_id` từ client. Mỗi phần tử: `node_id`, `definition_id`, `code`, `label`, `node_type`. Bị chặn bởi `MAX_CANDIDATES = 500`; quá lớn
trả `framework_selection_conflict`/`basis_too_large` như sinh đề xuất. `Cache-Control: no-store`; không kiểm toán Media (không đọc nội dung Media)
nhưng chịu giới hạn tần suất chung. Dùng để chọn Node khác khi sửa đề xuất `reuse_existing` (validator hiện đã chấp nhận mọi cặp nằm trong ứng viên,
nên **không đổi validator hay payload**).

Nếu Owner không muốn mở endpoint này, P3-B vẫn dùng được: chỉ cho Chấp nhận/Từ chối đề xuất `reuse_existing` sau khi xem `mapping_node`; muốn Node khác
thì từ chối và tạo yêu cầu mới. Xem D14.

## 5.4. Dữ liệu được hiển thị

Chỉ nhãn hiển thị (`code`, `label`, `node_type`, mô tả cắt ngắn) của Node thuộc bộ chuẩn **của chính tenant** mà Template đã chọn. Giáo viên có quyền AI
vốn đã thấy chính các nhãn này dưới dạng đề xuất của AI. Không lộ bộ chuẩn khác, không lộ tenant khác, không lộ quan hệ, tiêu chí hay Node ngoài
ứng viên.

# 6. Việc KHÔNG cần amendment

| Việc | Cách làm |
| --- | --- |
| Danh sách bản nháp Framework Version cho admin duyệt Node mới | Dựng ở trang Hoạt động phía máy chủ từ dịch vụ đọc Learning dành cho admin (`LearningFrameworkReadService`, đã có), chỉ cho admin, cấp vào cấu hình trang như `framework` hiện nay; không endpoint mới |
| Hiển thị receipt, trạng thái, hướng dẫn "chưa phải Mapping chính thức" | Chỉ giao diện, dữ liệu đã có (3.2) |
| Liên kết sang "Mapping hiện có" | Giao diện + route HTML có sẵn của tab; D8 đã có liên kết ngược |
| Nút thử lại, huỷ, xác nhận đích, áp dụng | Giao diện gọi endpoint có sẵn, điều khiển bởi A1 |
| Từ chối target cho receipt chưa applied | Dùng `cancel`, đúng hợp đồng (`use_cancel_application`) |

# 7. Kế hoạch lát cắt (mỗi lát review riêng)

Mỗi lát viết **bất biến và test đối kháng trước**, theo nguyên tắc 1, 5 và 9 của tài liệu thực hành. Định nghĩa dùng chung `hasDraft()`/`hasUnsent()`/
`revoke()`/`refreshOpen()` được mở rộng cho loại dữ liệu nhập mới, không viết riêng cho từng lát.

| Lát | Nội dung | Cần | Bất biến chính |
| --- | --- | --- | --- |
| **B1** | Xem Node của đề xuất `reuse_existing`; cho **Chấp nhận** loại này; Sửa vai trò/trọng số | A2.1 | Không hiện Node nếu mất quyền/nguồn; số và tên không lẫn (`node_id` không bao giờ là nhãn); accept ≠ áp dụng; `mapping_node` không đi vào payload sửa |
| **B2** | Sau chấp nhận: khối "Áp dụng" (danh sách receipt), Xem đích → Xác nhận đích → Áp dụng vào Template; liên kết tới tab Đầu ra & năng lực; ghi chú "chưa phải Mapping chính thức" | A1 (4.2) | Mỗi bước dùng đúng mã yêu cầu/hash/khoá; xác nhận đích gửi đúng hash đã xem; áp dụng thành công ≠ đã xuất bản; không có nút hoàn tác |
| **B3** | Thử lại và huỷ receipt | A1 (4.3) | Thử lại giữ nguyên `approved_by` gốc; huỷ không bao giờ trên `applied`; lệnh chưa rõ kết quả khoá biểu mẫu như P3-A |
| **B4** | Admin: duyệt Node mới (chọn bản nháp Framework), "chờ admin" cho giáo viên | A1 (`approve_node`), mục 6 | Chỉ admin thấy; bản nháp chọn tường minh, không mặc định; giáo viên thấy trạng thái chờ, không thấy hành động admin |
| **B5** | Chọn Node khác khi sửa `reuse_existing` | A2.2 | Danh sách ứng viên chỉ của Version Template đã chọn; không lộ khi mất quyền; chọn không đổi payload ngoài `node_id`/`definition_id` |
| **B6** | Xác nhận lại ngữ cảnh khi khoá học đổi | A1 (`reconfirm_context`) | `context-preview` chỉ ngữ cảnh hiện tại; không hứa so sánh khác biệt (thiết kế §4.4) |

`create_successor` (đề xuất kế thừa khi nguồn đổi), kế thừa bản nháp và rebase (P3-C) **không** nằm trong bản nháp này (D15).

# 8. Kế hoạch kiểm chứng

* **Backend (MariaDB 11.4, tạm):** bảng (trạng thái × vai × loại × receipt) cho A1; A2.1 với Node hợp lệ / lưu trữ / Version đã gỡ / Framework khác;
  A2.2 với Template chưa chọn Framework, Version quá lớn, giáo viên không có quyền AI; hồi quy Learning và Course (tab Đầu ra & năng lực, xuất bản Template).
* **Giao diện:** mỗi lát có test đối kháng (giữ phản hồi rồi trả muộn cho từng bước xác nhận/áp dụng/thử lại/huỷ; mất quyền giữa bước; bấm mọi nút), đột biến trên bản
  sao, và **đi thử từ menu** bằng dữ liệu thật: Template → Nội dung → Hoạt động (tài liệu) → Đề xuất AI → … → tab Đầu ra & năng lực thấy dòng Mapping mới.
* **Dữ liệu mẫu:** mở rộng `ai:authoring-demo-seed` chỉ bằng luồng thật (chấp nhận, xác nhận, áp dụng), không bịa receipt. Vẫn chỉ `local`/`testing`.
* **Review:** mỗi lát một lượt hẹp, chỉ gửi khi danh sách kiểm của tài liệu thực hành đã đủ.

# 9. Quyết định cần Owner

| # | Câu hỏi | Quyết định của Owner (2026-09-30) |
| --- | --- | --- |
| D12 | Duyệt từ vựng `allowed_actions` ở 4.2–4.3 (cấp đề xuất và cấp receipt), danh sách giữ nguyên | **Duyệt** |
| D13 | Mở cho người có quyền AI (kể cả giáo viên) **xem** `code`, `label`, `node_type`, mô tả cắt ngắn của Node trong bộ chuẩn của chính tenant (A2.1)? Có trả `description` không? | **Duyệt:** có `description` cắt 500 ký tự, không `criteria` |
| D14 | Thêm endpoint `GET node-candidates` (A2.2) cho lát B5, hay hoãn và chỉ cho Chấp nhận/Từ chối `reuse_existing` | **Hoãn** B5 và endpoint A2.2; quyết khi cần |
| D15 | `create_successor`, kế thừa bản nháp, rebase: để P3-C (cùng lúc với kế thừa/rebase) hay tách một lát riêng cuối P3-B | **Để P3-C** |
| D16 | Thứ tự lát: B1 → B2 → B3 → B4 → (B6) → B5 | **Duyệt** |
| D17 | Khẳng định "Owner duyệt A1 và A2.1 là amendment của hợp đồng Frozen" được ghi vào hợp đồng bằng một khối "Owner amendment", theo tiền lệ các khối "Owner … decision" | **Duyệt** |

D12, D13 và D17 đã duyệt nên B1/B2 được mở. B5 và A2.2 nằm ngoài kế hoạch cho đến khi Owner duyệt riêng.

# 10. Rủi ro và điều chưa biết

* **Provider AI chưa bật:** P3-B chỉ kiểm được với dữ liệu mẫu bằng provider giả. Chất lượng gợi ý Node thật chưa đo được.
* **Dữ liệu Learning thật lớn:** `MAX_CANDIDATES = 500` là giới hạn thiết kế, chưa đo hiệu năng ở tenant lớn.
* **Ba việc chưa chứng nhận của P3-A** (BFCache thật, hai tab, trình đọc màn hình; thiết kế §13.8) vẫn phải đóng trước khi đóng P3-B.
* **Xác nhận độc lập F1/F2 của P3-A** được đưa vào snapshot của lượt review đầu tiên của P3-B.
* **Tài liệu Frozen lỗi thời:** hai câu "chưa có UI" trong `LF-AI.md` và hợp đồng cần amendment riêng (thiết kế §13.8).

# Owner

Architecture Team

# Primary Consumers

* Backend Developers
* Frontend Developers
* Reviewers độc lập

---

End of LF-AI-Authoring-P3B-Amendment
