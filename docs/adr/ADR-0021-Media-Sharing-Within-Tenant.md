# ADR-0021 — Media Sharing Within Tenant

Version: 1.0

Status: Approved

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-10-21

Proposal Date: 2026-10-21

Approval Date: 2026-10-21

Approved By: LearnForge Architecture Owner

Document Path: adr/ADR-0021-Media-Sharing-Within-Tenant.md

Related ADRs:

* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0007 — SaaS Tenant Foundation](ADR-0007-SaaS-Tenant-Foundation.md)
* [ADR-0018 — Media PII And External Processing Boundary](ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved

Related Specification:

* [media_files](../database/media/media_files.md)
* [media_file_usages](../database/media/media_file_usages.md)
* [media_file_shares](../database/media/media_file_shares.md) — Draft
* [LF-Media-Sharing-Proposal](../quality/LF-Media-Sharing-Proposal.md) — nguồn các quyết định Owner (2026-10-21)

---

# Context

Media File thuộc một tenant (`customer_id`) và có chủ sở hữu là người tải lên
(`uploaded_by`). Hôm nay thư viện Media chỉ tồn tại ở khu admin (`/admin/media`),
mọi admin thấy mọi Media của tenant, còn giáo viên chưa có thư viện Media riêng và
chỉ gắn Media qua form nội dung. Không có cơ chế để một giáo viên cho giáo viên
khác dùng lại một Media của mình.

`media_files.visibility` đã có (`private`, `organization`, `public`) nhưng không
mô tả được "chia sẻ cho một số giáo viên cụ thể" và tên `public` dễ bị hiểu là
công khai ra Internet. Một tenant có thể có nhiều admin, nên "admin chỉ thấy của
mình" làm mất khả năng quản trị và dọn dẹp; Owner đã chốt admin thấy toàn bộ.

Thay đổi này đụng schema, quyền truy cập, dữ liệu lịch sử và thao tác xoá Media,
nên cần ADR trước khi có database docs duyệt và migration.

---

# Decision

## D1 — Chia sẻ là quan hệ riêng, `visibility` giữ nguyên

Chia sẻ được ghi bằng bảng tenant-scoped mới `media_file_shares`
([database doc](../database/media/media_file_shares.md)). `media_files.visibility`
không dùng cho chia sẻ và không đổi ý nghĩa; việc có ngừng dùng nó hay không
thuộc một ADR sau. Mục đích: một quyền không có hai nguồn sự thật.

## D2 — Ai thấy gì

* **Admin (`customer_admin`):** luôn thấy toàn bộ Media của tenant và có toàn
  quyền quản lý (xem, xoá Media chưa dùng, đổi chia sẻ, chuyển quyền sở hữu khi
  tính năng đó được làm). Chia sẻ không áp cho admin.
* **Giáo viên (`teacher`):** chỉ thấy Media do mình tải lên và Media được chia sẻ
  còn hiệu lực cho mình, trực tiếp hoặc qua nhóm vai trò `teacher`.
* **Học viên:** không thấy thư viện Media.

## D3 — Phạm vi chia sẻ

Ba trạng thái trên giao diện: *Chỉ mình tôi* (không có chia sẻ còn hiệu lực),
*Giáo viên được chọn* (từng người), *Tất cả giáo viên* (nhóm theo vai trò
`teacher`; giáo viên thêm sau này tự thấy). Không có "Tất cả admin" (admin đã
thấy hết) và không dùng tên "Public".

## D4 — Quyền của người được chia sẻ

Chỉ xem và gắn Media vào nội dung của mình (ghi `media_file_usages` như hiện
nay). Không sửa, không xoá, không đổi chia sẻ. Chỉ chủ sở hữu và admin sửa, xoá
Media chưa dùng và đổi chia sẻ.

## D5 — Quyền thư viện không phải quyền phát nội dung

Phạm vi trong D2 chỉ áp cho thư viện và việc chọn Media. Học viên và giáo viên
đang học hoặc dạy một hoạt động vẫn mở được Media gắn trong hoạt động đó theo
quyền xem nội dung (đường Read Contract hiện có), không phụ thuộc quyền xem thư
viện.

## D6 — "Đang dùng" tính cả nơi dùng của người khác

Chặn xoá Media dựa trên usage đang hoạt động của **bất kỳ ai** trong tenant.
Chủ sở hữu thấy số nơi dùng của người khác ("đang dùng ở N nơi, có M nơi của
người khác") nhưng không thấy tên nội dung của họ.

## D7 — Thu hồi chia sẻ bị chặn khi đang được dùng

Thu hồi một chia sẻ (hoặc đưa Media về *Chỉ mình tôi*) bị từ chối với 409 nếu
người nhận (hoặc, với nhóm vai trò, bất kỳ giáo viên nào) còn usage đang hoạt
động của Media đó; phản hồi nêu ai đang dùng. Kiểm tra và thu hồi nằm trong một
transaction có khoá để không vượt qua việc gắn mới đồng thời.

## D8 — Cô lập tenant

Chia sẻ chỉ trong cùng `customer_id`. Người nhận phải thuộc tenant và có vai trò
`teacher`. Mọi truy vấn đọc và ghi lọc theo `customer_id`.

## D9 — Dữ liệu lịch sử

Khi bật tính năng không tạo bản ghi chia sẻ nào: Media hiện có mặc định riêng tư
theo người tải lên; admin vẫn thấy hết. Không backfill, không sửa usage cũ.

## D10 — Audit

Tạo và thu hồi chia sẻ giữ lại (thu hồi đặt `revoked_at`, không xoá cứng) và ghi
ai, khi nào.

## D11 — Ghi chú của chủ sở hữu cho người được chia sẻ (Amendment 2026-10-21, Owner duyệt)

Chủ sở hữu (hoặc admin) có thể viết một ghi chú ngắn (tối đa 300 ký tự) khi chia
sẻ, ví dụ mục đích hoặc chỗ dùng của Media. Ghi chú nằm ở `media_files.share_note`
(một ghi chú cho mỗi Media, không theo từng người nhận), hiện cho mọi người đang
thấy Media ở danh sách thư viện và hộp chọn, và bị xoá khi Media về *Chỉ mình
tôi*. Đây là cách chủ sở hữu tự quyết điều gì được tiết lộ: hệ thống không tự suy ra
tên nội dung đang dùng Media (D6 giữ nguyên: chỉ số lượng, không tên). Đổi riêng
ghi chú không phải thu hồi chia sẻ nên không chạy kiểm tra D7.

---

# Consequences

* Cần bảng mới, chỉ mục và ràng buộc tenant; truy vấn thư viện giáo viên phức tạp
  hơn (Media của mình ∪ Media được chia sẻ).
* "Đang dùng" và chặn xoá phải đọc usage của người khác; Read/Delete path của
  Media thêm kiểm tra này.
* Thu hồi cần transaction và khoá, chịu rủi ro đồng thời với việc gắn mới.
* Giáo viên cần một nơi để thấy và chọn Media (trang thư viện hoặc hộp chọn trong
  form hoạt động): **phần việc UI mới**, chưa có.
* PII ([ADR-0018](ADR-0018-Media-PII-And-External-Processing-Boundary.md)): chia
  sẻ không được mở dữ liệu PII đã phân loại; cần review riêng trước khi cho chia
  sẻ Media có PII.
* Mức rà soát khi triển khai: HIGH ([LF-Regression-Audit](../quality/LF-Regression-Audit.md)).

---

# Alternatives Rejected

* **Dùng lại `media_files.visibility`:** không diễn đạt được "giáo viên cụ thể" và
  tạo hai nguồn sự thật cho cùng một quyền.
* **Admin cũng chỉ thấy của mình và được chia sẻ:** mất khả năng quản trị và dọn
  dẹp khi tenant có nhiều admin; cần thêm cơ chế cứu khi chủ sở hữu rời đi.
* **Cho thu hồi và để nội dung hỏng hoặc giữ lần dùng cũ:** chọn chặn thu hồi để
  không làm hỏng nội dung đã dùng và để hành vi dễ hiểu.
* **Chia sẻ chéo tenant:** vi phạm cô lập tenant.

---

# Open Items (chưa quyết, không chặn bản Review)

* Thao tác "chuyển quyền sở hữu" khi giáo viên nghỉ.
* Nơi giáo viên thấy và chọn Media.
* Kiểu ràng buộc tenant của `media_file_shares` (khoá kép `(media_file_id, customer_id)`
  như các bảng Media khác) — xác nhận ở Architecture Review.
* Có ngừng dùng `media_files.visibility` hay không.

---

# Owner Approval

```text
Role: LearnForge Architecture Owner
Date: 2026-10-21
Decision: Approved
```

Owner phê duyệt ADR trong hội thoại ngày 2026-10-21 sau khi đọc ADR này và
[media_file_shares](../database/media/media_file_shares.md), và chọn triển khai
ngay thay vì lập tài liệu Architecture Review riêng: bước Architecture Review
riêng được Owner **miễn** cho phạm vi này (dữ liệu dev local, không có dữ liệu
production). Phê duyệt này không phê duyệt phạm vi ngoài các quyết định D1–D10;
các Open Items còn lại vẫn cần quyết định riêng.

Implementation Status `Partial`: bảng, service chia sẻ, popup chia sẻ ở khu admin,
thư viện Media cho giáo viên và hộp chọn Media trong form hoạt động đã được triển
khai; chưa có chuyển quyền sở hữu và bộ lọc "Nguồn" cho admin.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
