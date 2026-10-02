# AI Authoring P3-C — Amendment and Slice Plan

Version: 1.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-10-01

Document Path: platform/LF-AI-Authoring-P3C-Amendment.md

Related Specification:

* [LF-AI-Authoring-Review-UI-Design](LF-AI-Authoring-Review-UI-Design.md) (§8.3, §10, D15)
* [LF-AI-Authoring-P3B-Amendment](LF-AI-Authoring-P3B-Amendment.md)
* [LF-AI-Authoring-Proposal-Contract](LF-AI-Authoring-Proposal-Contract.md) (Frozen; "Remediation contract v2" P1-1, P1-2, "Restricted decision inheritance")
* [LF-UI-Async-State-Engineering-Practice](../quality/LF-UI-Async-State-Engineering-Practice.md)

---

# 1. Trạng thái và cách đọc

**Owner đã duyệt D18–D27 theo đề xuất ngày 2026-10-01** (mục 8). Hợp đồng Bước 7 vẫn Frozen; A4 và A5 được ghi vào hợp đồng bằng khối
"Owner amendment — P3-C". Tài liệu gồm: (2) P3-C đem lại gì, (3) sự thật đã kiểm trong mã (kèm đường dẫn để kiểm lại), (4) các khoảng cách giữa
hợp đồng, mã và giao diện, (5) amendment A4 (từ vựng `allowed_actions` và hai trường của chi tiết) và A5 (dữ liệu hiển thị), (6) kế hoạch lát cắt C1–C3,
(7) kế hoạch kiểm chứng, (8) quyết định của Owner, (9) rủi ro. Mọi thay đổi ngoài phạm vi đã duyệt vẫn phải qua amendment mới.

# 2. P3-C đem lại gì

P3-A và P3-B xử lý đường **bình thường**: sinh → duyệt → áp dụng. P3-C xử lý ba tình huống **khi thế giới đổi sau khi đã duyệt**:

```text
C1  Nguồn Media của Hoạt động đổi (đề xuất đã chấp nhận thành "cũ"):
      → tạo đề xuất kế thừa (successor): quyết định cũ (tiêu đề, nội dung, Node, vai trò, trọng số)
        trở thành BẢN NHÁP chưa duyệt, gắn lại vào nguồn hiện tại; người duyệt chấp nhận lại như mọi đề xuất.

C2  Muốn dùng Node từ bộ chuẩn mới nhưng chưa có bản đang soạn:
      → admin tạo bản nháp kế thừa từ một bản đã xuất bản (sao chép các Node còn hoạt động), sau đó soạn và xuất bản
        bằng màn hình Learning có sẵn.

C3  Template đang dùng bản bộ chuẩn cũ, đã có bản mới xuất bản:
      → admin rebase: mỗi Mapping hiện có được map sang Node tương ứng ở bản mới, hoặc gỡ có lý do, hoặc huỷ cả lần rebase.
```

C2 và C3 thuộc cùng một chuỗi "bộ chuẩn đổi bản": C2 tạo bản nháp, Learning xuất bản, C3 chuyển Template sang bản đó.

# 3. Sự thật đã kiểm trong mã

## 3.1. Bảy endpoint đã có, hai nhóm quyền

Trong `routes/modules/ai-authoring.php` và `app/Http/Controllers/AiAuthoringController.php`:

| Endpoint | Quyền | Ghi chú |
| --- | --- | --- |
| `GET proposals/{uuid}/successor-source-scope` | cả hai vai | trả `anchors` (`anchor_hash` + `anchor`), `selection_limit` = 200, phân trang `cursor`/`limit` (≤ 100); **không có văn bản nguồn** |
| `GET proposals/{uuid}/successor-preview` | cả hai vai | chỉ khi đủ sáu điều kiện kế thừa; trả `inherited_decision_draft`, `successor_reason`, `payload` đã lọc (title, body, Node, vai trò, trọng số); ghi audit |
| `POST proposals/{uuid}/successors` | cả hai vai | `reason`, `payload`, `selected_anchor_hashes` (1–200); với `source_revision_changed` `payload` **phải null** (máy chủ tự dựng) |
| `GET proposals/{uuid}/inherited-draft-preview?base_version_id=` | **admin** | gọi `LearningAuthoringInheritanceService::preview` |
| `POST proposals/{uuid}/inherited-drafts` | **admin** | `base_version_id`, `version_code`, `title`, `expected_source_graph_hash`, `expected_plan_hash` |
| `GET proposals/{uuid}/rebase-preview?target_version_id=` | **admin** | `preview` + `preview_hash` |
| `POST proposals/{uuid}/rebases` | **admin** | `target_version_id`, `dispositions` (khoá là id Intent), `expected_preview_hash` |

Ba endpoint cuối chỉ đăng ký trong nhóm route "vòng đời Template" (`$registerCourseTemplateLifecycleRoutes`, `routes/web.php`). Mọi lệnh cần
mã yêu cầu UUID; tất cả đã được kiểm thử ở tầng dịch vụ và HTTP.

## 3.2. Điều kiện của từng lệnh (từ mã)

* **Successor** (`AiAuthoringSuccessorService`): cần revision đã chấp nhận từ trước (`acceptedRevision`), đề xuất không ở `deletion_pending`/`deleted`.
  Với `source_revision_changed`, kế thừa chỉ thành công khi sáu điều kiện hợp đồng đều đạt cho **mọi** nguồn, nếu không trả `proposal_stale` hoặc
  `proposal_successor_node_conflict`, và **không lộ nội dung cũ**. Kết quả là đề xuất mới `pending_review`, bản sửa 1, `creation_mode = human_successor`,
  không gọi provider. Các lý do khác (`context_changed`, `target_changed`, `intent_removed`, `human_correction`) bắt buộc người dùng **tự viết payload**
  cho mọi loại, kể cả `node_mapping` (cần chọn Node cụ thể).
* **Kế thừa bản nháp** (`AiAuthoringRebaseService::inheritDraft`): đề xuất khởi tạo phải `accepted`, `node_mapping`, có `framework_id`; admin tenant đang hoạt động.
  Học tập sao chép Node `active` có Definition `active`, các quan hệ còn đủ hai đầu; loại phần còn lại. Không Node nào hợp lệ → `proposal_inheritance_empty`.
  Thay đổi đồ thị giữa lúc xem và lúc gửi → `proposal_revision_conflict` (hash).
* **Rebase** (`AiAuthoringRebaseService::rebaseSelection`, `CourseAuthoringRebaseService`): đề xuất khởi tạo cũng phải là `accepted` `node_mapping`. Bản đích phải đã **xuất bản**
  và khác bản Template đang chọn. **Mỗi** Intent của Template (không chỉ của Hoạt động này) phải có đúng một xử lý (`map`, `remove_explicit` kèm `reason` mã `[a-z][a-z0-9_]{0,63}`,
  hoặc `cancel_rebase`). Với Intent do AI tạo, `map` yêu cầu đề xuất của nó còn `accepted`, nguồn còn đúng, và ghi một review `rebase_target` mới. Một giao dịch duy nhất;
  mọi thứ khác trả `proposal_revision_conflict` (kể cả `preview_changed`).

## 3.3. Cái giao diện nhìn thấy được hôm nay

* `status = stale` hiện trong danh sách; chi tiết trả `content_denied`, `reviews` (không có nội dung) nhưng `allowed_actions = []` (`AuthoringAllowedActions::pending`).
  Giao diện P3-A chỉ nói "đề xuất đã cũ" và gợi ý tạo yêu cầu mới (§5.1 thiết kế).
* `inherited-draft-preview` trả **chỉ id**: `eligible_node_ids`, `excluded_node_ids`, `excluded_relation_ids`, `source_graph_hash`, `plan_hash`
  (`LearningAuthoringInheritanceService::plan`). Không có nhãn Node.
* `rebase-preview` trả theo từng Intent: `intent_id`, `origin`, `source_type`, `source_id`, `mapping_role`, `weight`, `old_node_id`, `definition_id`, `old_target_hash`,
  `proposed_node_id` (chỉ khi **đúng một** Node cùng Definition ở bản đích), `new_target_hash`, `ai_proposal_id`, `ai_proposal_revision_id`, `course_context_hash`,
  `accepted_course_context_hash`. Không có tên Node, không có tiêu đề Bài học/Hoạt động, không có danh sách Node ứng viên khác.
* `preview_hash` là băm của **toàn bộ** `preview`; thêm trường vào `preview` đổi băm. Dữ liệu hiển thị thêm phải nằm **ngoài** `preview` (5.2).

# 4. Khoảng cách cần Owner quyết

| # | Khoảng cách | Hệ quả nếu không xử lý |
| --- | --- | --- |
| G1 | Hợp đồng (P1-1) nói bản xem trước kế thừa "phơi bày phụ thuộc bị mất và **mọi Intent bị ảnh hưởng**"; mã chỉ trả danh sách id và hash, không có Intent. | Admin "xác nhận loại trừ" mà không biết loại trừ ảnh hưởng ai. |
| G2 | Cả bản xem trước kế thừa lẫn rebase chỉ có số: không có nhãn Node, tiêu đề Bài học/Hoạt động. | Admin không thể đánh giá kế hoạch; giao diện phải hiện "Node #123". |
| G3 | Lý do successor khác `source_revision_changed` cần payload tự viết cho mọi loại; riêng `node_mapping` cần **chọn Node** (danh sách ứng viên, A2.2/B5 đang hoãn). | Không làm được nếu không có B5. |
| G4 | `source-scope` chỉ có định danh nguồn, không có văn bản; `selected_anchor_hashes` tối đa 200. | Người dùng không có gì để "chọn" ngoài việc chọn tất cả; hơn 200 nguồn thì không chọn hết được. |
| G5 | Rebase chỉ cho map sang `proposed_node_id`; muốn map sang Node khác cần danh sách Node đích (cùng loại thiếu với B5). | Dòng không có đề xuất chỉ gỡ được hoặc huỷ cả lần. |
| G6 | `remove_explicit` cần `reason` là mã snake_case tự do; không có danh sách lý do chuẩn. | Giao diện phải định danh sách cố định (như lý do huỷ biên lai). |
| G7 | Cả `inherit_draft` và `rebase` được khởi tạo từ **một đề xuất đã chấp nhận** nhưng tác động rộng hơn đề xuất: kế thừa thêm một Version vào Framework dùng chung nhiều Template; rebase thay Intent của **cả Template**. | Người dùng có thể tưởng thao tác chỉ liên quan đề xuất đang mở. |
| G8 | Chọn bản cơ sở/đích cần danh sách Version đã xuất bản của Framework; không endpoint nào trả. | Cần danh sách trong cấu hình trang như `draft_versions` của B4. |

# 5. Amendment

## 5.1. A4 — Từ vựng `allowed_actions` mở rộng (cấp đề xuất, có trong chi tiết)

Cùng nguyên tắc 4.1 của amendment P3-B (gợi ý, tính lại mỗi lần đọc, không phải quyền). Thêm ba giá trị đóng:

| Giá trị | Khi nào có (ai) | Endpoint tương ứng |
| --- | --- | --- |
| `create_successor` | `stale` và đã có revision từng được chấp nhận, nội dung đề xuất không bị xoá (cả hai vai) | `GET successor-source-scope`, `GET successor-preview`, `POST successors` |
| `inherit_draft` | `accepted`, `node_mapping`, có `framework_id`, người gọi là admin | `GET inherited-draft-preview`, `POST inherited-drafts` |
| `rebase` | `accepted`, `node_mapping`, có `framework_id`, người gọi là admin | `GET rebase-preview`, `POST rebases` |

`create_successor` **không** đảm bảo kế thừa được: sáu điều kiện chỉ được kiểm khi xem trước (và ghi audit). Khi xem trước từ chối (`proposal_stale`, `proposal_successor_node_conflict`),
giao diện nói rõ không kế thừa được và gợi ý tạo yêu cầu sinh mới; không lộ nội dung cũ. Danh sách giữ nguyên ba giá trị `pending_review`.

**A4.1 — hai trường của chi tiết.** Hợp đồng ("Restricted decision inheritance", điều 4) yêu cầu người duyệt kế tiếp thấy cả cờ lẫn lý do. Cột
`inherited_decision_draft` và `successor_reason` đã có trên đề xuất nhưng `show()` chưa trả. Chi tiết (không phải danh sách) có thêm hai trường: `inherited_decision_draft`
(bool) và `successor_reason` (chuỗi hoặc null); đề xuất sinh bằng model trả `false`/`null`. Không lộ nội dung gì thêm.


## 5.2. A5 — Dữ liệu hiển thị cho admin (cộng thêm, ngoài các trường được băm)

Chỉ cho admin, chỉ khi đọc `inherited-draft-preview` và `rebase-preview`; thêm khoá cấp cao `display` (cho phép trong danh sách khoá của `AiAuthoringController::response`),
**ngoài** `preview` nên không đổi `plan_hash` hay `preview_hash`:

* `inherited-draft-preview`: `display.excluded_nodes` (tối đa 100: `node_id`, `code`, `label`, `node_type`, `reason` ∈ {`node_retired`, `definition_inactive`}), `display.eligible_count`,
  `display.affected_intents` (**chỉ của Template này**, tối đa 100: `intent_id`, `source_label`, `node_label`), có ghi rõ "các Template khác không được liệt kê".
* `rebase-preview`: `display.intents[intent_id]` = `source_label` (tiêu đề Bài học hoặc Hoạt động, cắt 255), `old_node`, `proposed_node` (cùng hình dạng `mapping_node`: code, label, node_type, mô tả cắt 500).

Mọi nhãn đọc qua đường hẹp `LearningAuthoringBasisService::nodeDisplay` và truy vấn Course theo `customer_id`, chỉ cho `actor_role = admin`, theo đúng ranh giới của A2.1. Không `criteria`, không thêm
endpoint, không ghi.

## 5.3. Cấu hình trang (không phải amendment)

Giống `draft_versions` (B4): `published_versions` (id và nhãn, tối đa 50) của Framework Template đã chọn, chỉ cho admin, làm danh sách chọn bản cơ sở của C2 và bản đích của C3 (bản đích ≠ bản đang chọn).

# 6. Kế hoạch lát cắt (mỗi lát review riêng)

Theo nguyên tắc 1, 5 và 9 của tài liệu thực hành: bất biến và test đối kháng trước; mở rộng `hasDraft()`/`hasUnsent()`/`revoke()` cho loại dữ liệu mới (ví dụ bản đã chọn, tên bản nháp, xử lý từng Intent), không viết riêng.

| Lát | Nội dung | Cần | Bất biến chính |
| --- | --- | --- | --- |
| **C1** | Đề xuất `stale` đã từng chấp nhận: "Tạo đề xuất kế thừa" (chỉ lý do `source_revision_changed`): xem trước bản nháp kế thừa (văn bản), xác nhận dùng **tất cả nguồn hiện tại** (đếm, ≤ 200), gửi, mở đề xuất mới | A4 (`create_successor`), D19, D20 | Không lộ nội dung cũ nếu xem trước từ chối; nói rõ là **bản nháp chưa duyệt**, không có lý do/trích dẫn/độ tin cậy; phải chấp nhận lại; trả lời muộn không rơi vào đề xuất khác; nguồn quá 200 thì không gửi; mã yêu cầu và danh sách nguồn đóng băng khi chưa rõ kết quả |
| **C2** | Admin: "Tạo bản nháp kế thừa": chọn bản cơ sở đã xuất bản, xem kế hoạch (Node bị loại, quan hệ bị loại, Intent bị ảnh hưởng của Template này), đặt mã và tên, xác nhận, kết quả là bản nháp mới (liên kết tới màn hình Learning) | A4 (`inherit_draft`), A5, 5.3, D21 | Gửi đúng hai hash đã xem; kế hoạch rỗng hay đã đổi thì báo, không tạo; nói rõ tác động lên **Framework** dùng chung, không tự đổi Template; lỗi giữa chừng không để Version nửa vời (do máy chủ giao dịch) |
| **C3** | Admin: rebase Template sang bản đã xuất bản: xem kế hoạch từng Intent (bản cũ → bản mới), chọn xử lý cho **từng** Intent (`map` tới đề xuất duy nhất, `remove_explicit` với lý do từ danh sách, hoặc huỷ cả lần), xác nhận, kết quả thay thế | A4 (`rebase`), A5, 5.3, D22–D24 | Mỗi Intent đúng một xử lý và nút Xác nhận chỉ bật khi đủ; gửi đúng `preview_hash` đã xem; kế hoạch đổi (409 `preview_changed`) buộc xem lại; nói rõ tác động lên **cả Template**; không mất Intent âm thầm |

Thứ tự đề xuất C1 → C2 → C3. C2 và C3 độc lập về mã nhưng C3 cần một bản đã xuất bản (qua C2 và màn hình Learning).

# 7. Kế hoạch kiểm chứng

* **Backend (MariaDB 11.4, tạm):** bảng (trạng thái × vai × loại × điều kiện) cho A4; A5 với Node đã loại / Definition ngưng / Intent thuộc Template khác / tenant khác / giáo viên (phải bị từ chối);
  hồi quy: `plan_hash` và `preview_hash` không đổi khi có `display`; nhãn không lộ cho giáo viên.
* **Giao diện:** mỗi lát có test đối kháng (giữ phản hồi rồi trả muộn cho từng bước xem/gửi, mất quyền giữa chừng, quét mọi nút), đột biến trên bản sao, và **đi thử từ menu** bằng dữ liệu thật.
* **Dữ liệu mẫu:** `ai:authoring-demo-seed` hiện không có đề xuất `stale` hay bộ chuẩn nhiều bản; mở rộng chỉ bằng luồng thật (đổi nguồn Media qua dịch vụ Media; tạo, xuất bản bản mới qua dịch vụ Learning), không bịa trạng thái.
* **Review:** mỗi lát một lượt hẹp, chỉ khi danh sách kiểm của tài liệu thực hành đã đủ.

# 8. Quyết định cần Owner

| # | Câu hỏi | Quyết định của Owner (2026-10-01) |
| --- | --- | --- |
| D18 | Thứ tự và phạm vi lát: C1 → C2 → C3 như mục 6? | **Duyệt** |
| D19 | Successor chỉ hỗ trợ lý do `source_revision_changed` (máy chủ dựng bản nháp); bốn lý do còn lại (cần tự viết payload, riêng `node_mapping` cần chọn Node) **hoãn** cùng B5 (G3)? `context_changed` đã có đường `reconfirm_context` (B6). | **Hoãn** bốn lý do kia |
| D20 | Nguồn của successor: giao diện chỉ cho **"dùng tất cả nguồn hiện tại"** (đếm và nêu loại, ≤ 200, lấy hết qua phân trang); hơn 200 thì không gửi và nói lý do; không chọn từng nguồn vì không có gì để nhìn (G4)? | **Duyệt** |
| D21 | A4: ba giá trị `create_successor`, `inherit_draft`, `rebase` đúng như 5.1, ghi vào hợp đồng bằng khối "Owner amendment — P3-C"? | **Duyệt** |
| D22 | A5: thêm `display` ngoài các trường băm, chỉ admin, như 5.2 (G1, G2)? Gồm danh sách Intent bị ảnh hưởng **chỉ của Template này** (G1: hợp đồng nói "mọi Intent", mã không có). | **Duyệt**, ghi rõ giới hạn |
| D23 | Rebase chỉ cho `map` tới `proposed_node_id` duy nhất; dòng không có đề xuất chỉ `remove_explicit` hoặc huỷ cả lần; chọn Node khác hoãn cùng B5 (G5)? | **Duyệt** |
| D24 | Lý do `remove_explicit` là danh sách cố định: `no_equivalent_node`, `no_longer_needed`, `other` (G6)? | **Duyệt** |
| D25 | Cả hai lệnh admin khởi tạo từ chi tiết đề xuất `node_mapping` đã chấp nhận (như hợp đồng), với lời cảnh báo tác động rộng hơn đề xuất (G7); không tạo trang cấp Template riêng trong P3-C? | **Duyệt** |
| D26 | Cấu hình trang `published_versions` cho admin (5.3, G8), không thêm endpoint? | **Duyệt** |
| D27 | Sau C2/C3 giao diện chỉ liên kết sang màn hình Learning/Đầu ra & năng lực, không tự xuất bản bộ chuẩn hay Template (xuất bản vẫn ở màn hình có sẵn)? | **Duyệt** |

# 9. Rủi ro và điều chưa biết

* **Không có dữ liệu thật cho `stale`:** mọi kiểm chứng C1 dùng dữ liệu mẫu do luồng thật tạo; chất lượng bản nháp kế thừa trên nguồn thật chưa đo.
* **Rebase là thao tác Template-wide không hoàn tác:** giao diện chỉ làm theo kế hoạch đã xem; không có "hoàn tác" (máy chủ không có). Nếu `rebase` gặp Intent của đề xuất khác đã `stale`, cả lần bị từ chối (`proposal_stale`, `intent_<id>`); giao diện phải nói rõ Intent nào.
* **Kế hoạch lớn:** một Template có thể có hàng trăm Intent; kế hoạch rebase không phân trang; giao diện phải xử lý danh sách dài (gấp, đếm, trạng thái từng dòng) mà không mất lựa chọn đã làm — đây là họ lỗi "làm mới xoá dữ liệu chưa gửi" của P3-A.
* **Ba việc chưa chứng nhận của P3-A/P3-B** (BFCache thật, hai tab, trình đọc màn hình, zoom) và hai câu "chưa có UI" trong tài liệu Frozen vẫn mở.
* **Hợp đồng nói nhiều hơn mã (G1):** nếu Owner không duyệt D22, hợp đồng cần được làm rõ rằng bản xem trước chỉ nêu id.

# Owner

Architecture Team

# Primary Consumers

* Backend Developers
* Frontend Developers
* Reviewers độc lập

---

End of LF-AI-Authoring-P3C-Amendment
