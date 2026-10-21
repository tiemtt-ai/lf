# LF Media Sharing Proposal

Version: 0.1

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-10-21

Document Path: quality/LF-Media-Sharing-Proposal.md

---

# Mục đích

Đề xuất để Owner duyệt trước khi có ADR: cho phép một Media được chia sẻ trong cùng tenant (tất cả admin, tất cả giáo viên, hoặc giáo viên cụ thể), với nguồn chia sẻ hiển thị rõ và bộ lọc theo nguồn. Tài liệu này **chưa phải ADR** và **không cho phép tạo migration**; nếu được duyệt, nội dung chuyển thành ADR mới mở rộng [ADR-0004](../adr/ADR-0004-Media-Foundation.md) và cập nhật [media_files](../database/media/media_files.md).

Giao diện chỉ đã có tab "Chia sẻ" dạng chỗ giữ chỗ trong popup Quản lý của `/admin/media`; chưa có hành vi.

# Hiện trạng (đã kiểm tra)

* `media_files.uploaded_by` là chủ sở hữu; `media_files.visibility` đã tồn tại với giá trị `private`, `organization`, `public` (xem [media_files](../database/media/media_files.md), mục Fields). Chưa có bảng chia sẻ theo người nhận.
* `media_file_usages` ghi quan hệ Media với nơi dùng ([media_file_usages](../database/media/media_file_usages.md)); danh sách Media hiện tính "đang dùng" từ bảng này.
* Vai trò trong tenant: `customer_admin`, `teacher`, `student`.

# Câu hỏi cần Owner quyết

1. **Quan hệ với `visibility`:** dùng lại cột `visibility` (`organization` = mọi người trong tenant) hay giữ `visibility` cho ý nghĩa hiện tại và thêm bảng chia sẻ riêng? Đề xuất: bảng chia sẻ riêng, `visibility` giữ nguyên, tránh hai nguồn sự thật cho cùng một quyền.
2. **Phạm vi chia sẻ:** `owner_only` (mặc định), `all_admins`, `all_teachers`, `specific_users` (người cụ thể có thể là giáo viên hoặc admin, vì một tenant có nhiều admin). Học viên không bao giờ nhận media chia sẻ qua cơ chế này.
3. **Quyền của người nhận:** chỉ xem và gắn vào nội dung của mình; không sửa, không xoá, không đổi chia sẻ. Chủ sở hữu và admin được đổi chia sẻ.
4. **Thu hồi khi người nhận đang dùng:** chọn một trong (a) chặn thu hồi và nêu ai đang dùng; (b) thu hồi nhưng giữ các lần dùng hiện có, chỉ chặn lần dùng mới. Đề xuất (b) để không làm hỏng nội dung đã xuất bản, kèm cảnh báo rõ.
5. **Xoá media được chia sẻ:** đã khoá khi đang dùng; "đang dùng" phải tính cả nơi dùng của người khác. Chủ sở hữu chỉ thấy số lượng nơi dùng của người khác, không thấy tên nội dung họ không có quyền xem.
6. **Admin thấy gì:** đề xuất admin luôn thấy toàn bộ Media của tenant; giáo viên chỉ thấy của mình và được chia sẻ.

# Mô hình dữ liệu đề xuất (chỉ để duyệt, chưa tạo)

Bảng tenant-scoped, mỗi dòng có `customer_id`:

| Cột | Ý nghĩa |
| --- | --- |
| id, customer_id | khoá và tenant (bắt buộc) |
| media_file_id | Media được chia sẻ (cùng customer_id) |
| scope | `all_admins`, `all_teachers`, `user` |
| user_id | NULL trừ khi scope = `user` |
| shared_by | người tạo chia sẻ |
| created_at, revoked_at | thời điểm tạo và thu hồi (không xoá cứng, giữ audit) |

Ràng buộc: duy nhất theo (media_file_id, scope, user_id) khi chưa thu hồi; mọi truy vấn lọc theo `customer_id`; không chia sẻ chéo tenant.

# Hành vi giao diện

* Cột "Nguồn" hoặc dòng nhỏ: "Của tôi (riêng tư)" / "Chia sẻ bởi {tên}".
* Bộ lọc "Nguồn": Của tôi / Được chia sẻ với tôi / Tất cả.
* Media được chia sẻ mà chưa dùng: trạng thái "Chưa được dùng".
* Popup Quản lý: tab "Nơi sử dụng" (đã có) và tab "Chia sẻ" (chọn phạm vi, danh sách người nhận).

# Tác động cần review

* Cô lập tenant và `customer_id` ownership ([Guardrails](../governance/LF-Architecture-Guardrails.md)).
* Quyền (policy) cho xem, dùng, đổi chia sẻ, xoá.
* "Dọn kho lưu trữ" và chặn xoá phải tính đúng nơi dùng của người khác.
* Audit: ai chia sẻ, ai thu hồi, khi nào.
* Tương thích Media đang xử lý/AI (xem [ADR-0018](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md)): chia sẻ không được vô tình mở dữ liệu PII đã phân loại.

# Bước tiếp theo

Owner trả lời các câu hỏi ở trên, sau đó: ADR → tài liệu miền và database → Architecture Review → Freeze → migration → triển khai → test.
