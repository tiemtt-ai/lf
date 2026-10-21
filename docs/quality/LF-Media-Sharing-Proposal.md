# LF Media Sharing Proposal

Version: 0.2

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-10-21

Document Path: quality/LF-Media-Sharing-Proposal.md

---

# Mục đích

Đề xuất để Owner duyệt trước khi có ADR: cho phép giáo viên chia sẻ Media của mình trong cùng tenant, với nguồn chia sẻ hiển thị rõ và bộ lọc theo nguồn. Tài liệu này **chưa phải ADR** và **không cho phép tạo migration**. Khi được duyệt, nội dung chuyển thành ADR mới mở rộng [ADR-0004](../adr/ADR-0004-Media-Foundation.md) và cập nhật [media_files](../database/media/media_files.md); sau đó mới tới Architecture Review, Freeze, migration và triển khai.

Triển khai (2026-10-21): bảng `media_file_shares`, `MediaShareService` và popup "Quyền chia sẻ" ở `/admin/media` đã chạy thật. Phía giáo viên đã có: thư viện `/teacher/media` và hộp chọn Media trong form hoạt động. Chưa có: chuyển quyền sở hữu và bộ lọc "Nguồn" cho admin.

# Hiện trạng (đã kiểm tra)

* `media_files.uploaded_by` là chủ sở hữu. `media_files.visibility` đã tồn tại (`private`, `organization`, `public`) nhưng chưa có bảng chia sẻ theo người nhận ([media_files](../database/media/media_files.md)).
* `media_file_usages` ghi quan hệ Media với nơi dùng; "đang dùng" của danh sách Media và việc khoá xoá đều tính từ bảng này.
* Vai trò trong tenant: `customer_admin`, `teacher`, `student`.
* Thư viện Media chỉ có ở `/admin/media`; giáo viên chưa có trang thư viện hoặc hộp chọn media riêng.

# Các quyết định Owner đã chốt (2026-10-21)

1. **Quyền sửa:** chỉ chủ sở hữu (người tải lên). Người được chia sẻ chỉ xem và dùng Media trong nội dung của mình, không sửa, không xoá.
2. **Xoá Media đang dùng:** vẫn khoá xoá khi đang dùng; "đang dùng" tính cả nơi dùng của người khác. Chủ sở hữu thấy "đang dùng ở N nơi (có M nơi của người khác)", không thấy tên nội dung của người khác vì không có quyền xem.
3. **Thu hồi chia sẻ:** nếu người nhận đang dùng Media trong nội dung thì **chặn thu hồi** và báo ai đang dùng.
4. **Ai thấy gì:**
   * **Admin (`customer_admin`) luôn thấy toàn bộ Media trong tenant** và có toàn quyền quản lý (xem, xoá Media chưa dùng, đổi chia sẻ, chuyển quyền sở hữu). Chia sẻ không áp cho admin.
   * **Giáo viên (`teacher`) chỉ thấy:** Media do mình tải lên, Media được chia sẻ cho mình (người cụ thể hoặc nhóm "Tất cả giáo viên").
   * Học viên không thấy thư viện Media.
5. **Cô lập tenant:** chia sẻ chỉ trong cùng `customer_id`, không bao giờ chéo tenant.
6. **Media cũ:** mặc định riêng tư theo người tải lên (không có bản ghi chia sẻ nào được tạo khi bật tính năng; admin vẫn thấy hết).
7. **"Tất cả giáo viên" là nhóm theo vai trò:** giáo viên được thêm sau này tự thấy Media đã chia sẻ cho nhóm.

# Phạm vi chia sẻ

| Giá trị trên giao diện | Ý nghĩa |
| --- | --- |
| Chỉ mình tôi | Không có bản ghi chia sẻ nào còn hiệu lực |
| Giáo viên được chọn | Từng giáo viên cụ thể (có thể chọn nhanh "Tất cả giáo viên") |
| Tất cả giáo viên | Nhóm theo vai trò `teacher` của tenant, gồm cả giáo viên mới thêm sau |

Không còn lựa chọn "Tất cả admin" (admin đã thấy hết) và không dùng tên "Public" (dễ hiểu nhầm là công khai ra Internet); dùng "Tất cả giáo viên".

# Mô hình dữ liệu đề xuất (chỉ để duyệt, chưa tạo)

Bảng tenant-scoped `media_file_shares`:

| Cột | Ý nghĩa |
| --- | --- |
| id, customer_id | khoá và tenant (bắt buộc, mọi truy vấn lọc theo cột này) |
| media_file_id | Media được chia sẻ (cùng customer_id) |
| grantee_type | `user` hoặc `role` |
| grantee_user_id | người nhận khi `grantee_type = user`, ngược lại NULL |
| grantee_role | `teacher` khi `grantee_type = role`, ngược lại NULL |
| shared_by | người tạo chia sẻ |
| created_at, revoked_at | thời điểm tạo và thu hồi (không xoá cứng, giữ audit) |

Ràng buộc: CHECK để đúng một trong `grantee_user_id` và `grantee_role` có giá trị; duy nhất theo (media_file_id, grantee_type, grantee_user_id, grantee_role) khi chưa thu hồi; người nhận phải cùng tenant và có vai trò `teacher`. `media_files.visibility` giữ nguyên ý nghĩa hiện tại, không dùng cho chia sẻ (tránh hai nguồn sự thật); sẽ xem xét ngừng dùng ở một ADR riêng.

# Quy tắc nghiệp vụ

* **Hiển thị:** truy vấn thư viện của giáo viên = Media của mình ∪ Media có chia sẻ còn hiệu lực cho mình (trực tiếp hoặc qua nhóm vai trò). Admin: toàn bộ Media của tenant.
* **Phát nội dung:** quyền xem thư viện chỉ áp cho thư viện và việc chọn Media. Học viên, giáo viên đang học hoặc dạy một hoạt động vẫn mở được Media gắn trong hoạt động đó theo quyền xem nội dung, không phụ thuộc quyền xem thư viện.
* **Dùng Media được chia sẻ:** giáo viên nhận được phép gắn Media vào nội dung của mình; mỗi lần dùng ghi vào `media_file_usages` như hiện nay.
* **Thu hồi:** chỉ cho phép khi không có nơi dùng đang hoạt động của người nhận (hoặc của bất kỳ giáo viên nào nếu thu hồi nhóm); nếu không, trả 409 kèm danh sách người đang dùng.
* **Xoá:** chủ sở hữu và admin chỉ xoá được Media chưa dùng ở bất kỳ đâu (kể cả nơi dùng của người khác). Người nhận không xoá được.
* **Dọn kho lưu trữ:** giữ nguyên trong tenant; chỉ dọn file của Media đã xoá.
* **Admin đổi chia sẻ:** admin đổi chia sẻ của Media bất kỳ; mọi thay đổi ghi audit (ai, khi nào, từ gì sang gì).

# Hành vi giao diện

* Danh sách Media: dòng nhỏ hoặc cột "Nguồn": "Của tôi (riêng tư)" / "Chia sẻ bởi {tên}"; bộ lọc "Nguồn": Của tôi / Được chia sẻ với tôi / Tất cả (admin thêm "Của giáo viên …").
* Media được chia sẻ mà chưa dùng: ghi "Chưa được dùng".
* Popup "Nơi sử dụng": chủ sở hữu thấy số nơi dùng của người khác nhưng không thấy tên nội dung của họ.
* Popup "Quyền chia sẻ": chọn phạm vi, chọn giáo viên; thu hồi bị chặn thì hiện danh sách người đang dùng.
* Giáo viên cần một nơi để thấy và chọn Media (trang thư viện cho giáo viên hoặc hộp chọn trong form hoạt động): **phần việc mới, chưa có**.

# Tác động cần review

* Cô lập tenant và `customer_id` ownership ([Guardrails](../governance/LF-Architecture-Guardrails.md)); policy cho xem, dùng, đổi chia sẻ, xoá theo vai trò.
* Truy vấn thư viện theo vai trò (hiệu năng, chỉ mục theo `customer_id`, `grantee_user_id`, `grantee_role`).
* "Đang dùng" và chặn xoá phải tính nơi dùng của người khác; chặn thu hồi cần giao dịch và khoá (đồng thời với việc dùng mới).
* Audit chia sẻ.
* Tương thích Media đang xử lý hoặc có PII ([ADR-0018](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md)): chia sẻ không được vô tình mở dữ liệu PII đã phân loại.
* Mức rà soát: HIGH (schema, quyền, dữ liệu lịch sử, thao tác xoá).

# Chưa quyết (làm sau)

* "Chuyển quyền sở hữu" khi giáo viên nghỉ: admin đã thấy và xử lý được Media của họ; thao tác chuyển quyền là tính năng bổ sung.
* Nơi giáo viên chọn Media chia sẻ (thư viện hoặc hộp chọn).
* Có ngừng dùng `media_files.visibility` hay không.

# Bước tiếp theo

Đã soạn: [ADR-0021](../adr/ADR-0021-Media-Sharing-Within-Tenant.md) (Approved 2026-10-21) và [media_file_shares](../database/media/media_file_shares.md). Owner đã duyệt và miễn Architecture Review riêng; đang triển khai.
