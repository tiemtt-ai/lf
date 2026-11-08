# LiveClass Whiteboard Architecture Review

Version: 1.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-08

Review Date: 2026-11-07

Document Path: quality/LF-LiveClass-Whiteboard-Architecture-Review.md

Target: [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) § LiveClass Whiteboard Amendment,
[LF-Core-LiveClass](../core/LF-Core-LiveClass.md) § Whiteboard,
[LF-Media](../platform/LF-Media.md) § Whiteboard Rendering Exception.

**Verdict: Pass with changes. All changes applied the same day. Frozen 2026-11-07.** Đọc tài
liệu và code, không chạy database hay test. Các quyết định sản phẩm Owner đã chốt trong
[đề xuất](LF-LiveClass-Whiteboard-Proposal.md) không mở lại. Mô hình sở hữu đứng vững:
không có bảng mới, bảng không sinh evidence, Course giữ nội dung, Media giữ tệp.

---

## High

1. **"Không dùng whisper" chưa được chặn thật.** Reverb mặc định
   `accept_client_events_from = members` và `allowed_origins = *`
   (`vendor/laravel/reverb/config/reverb.php:85,89`); dự án chưa publish
   `config/reverb.php`. Học viên đã vào kênh vẫn gửi được `client-*`.
   **Đã sửa:** bắt buộc `none`, giới hạn origin theo domain tenant, bật rate limiting.
2. **Nhiều người trình chiếu, không có luật ai được gửi.** Mọi giáo viên của buổi và
   admin đều là người trình chiếu, trạng thái lại nằm ở trình duyệt từng người; hai người
   sẽ ghi đè nhau. **Đã sửa:** một người đang trình chiếu, giữ bằng khoá theo Session,
   nhận lại bằng thao tác rõ ràng; server từ chối người không giữ khoá.
3. **Học viên nhận tệp mà không qua luật truy cập hiện có.** Thiếu `left_at`, thời hạn
   học (`CourseEnrollmentLifecycleService.php:202`) và luật mở khoá
   (`VersionActivityAccessService.php:16`). **Đã sửa:** thêm `left_at` trống và
   `allowsLearningAccessAt(now)`. Luật mở khoá: xem quyết định cần Owner xác nhận ở dưới.

## Medium

4. Link có chữ ký hiện chỉ cấp cho admin (`MediaService.php:217,268`). **Đã sửa:** Media
   có hàm mới nhận ngữ cảnh LiveClass đã kiểm quyền; LiveClass không đọc storage.
5. Ngăn tài liệu chưa lọc theo `available_during_session`
   (`core_course_template_version_activities.md:79`), trong khi AllVia là "Trong giờ
   học". **Đã sửa:** lọc theo cờ này, bỏ Activity không có Media (`quiz`, `live_class`).
6. Vòng đời cache chưa rõ: không TTL, huỷ/vắng từ `live` và Cohort rời `active` không
   xoá cache; máy đang mở không bị ngắt. **Đã sửa:** TTL theo giờ kết thúc dự kiến cộng
   đệm; một điểm chung trong lifecycle service xoá cache, khoá và phát "bảng đã đóng";
   học viên bị rút khỏi lớp bị ngắt.
7. Kích thước và thứ tự tin: Reverb giới hạn 10.000 byte; broadcast qua queue có thể sai
   thứ tự. **Đã sửa:** tối đa 8 KB mỗi thao tác, `ShouldBroadcastNow`, số thứ tự nguyên
   tử, người vào sau đăng ký → giữ tạm → tải trạng thái, hụt số thì tải lại toàn bộ.
8. Giáo viên bắt đầu/kết thúc buổi: route hiện chỉ cho admin
   (`routes/modules/course.php:118-121`); luật "đúng ngày" mới là luật giao diện; chưa
   kiểm giáo viên đang hoạt động và khung `assigned_from`/`assigned_to`. **Đã sửa:**
   server từ chối ngoài ngày dự kiến; kiểm đủ các điều kiện trên; mọi vai trò được
   phân công đều được bắt đầu và kết thúc.
9. Trang học viên chưa có (`TenantWebsiteController.php:75-83`). **Đã sửa:** nêu route
   theo từng vai trò, không có nhóm trộn vai trò; lối vào học viên làm cùng trang khoá học.
10. Video trong bảng (bảng chức năng của đề xuất) chưa có luật. **Đã sửa:** theo luật
    Video preview của LF-Media; đồng bộ phát, dừng, tua.

## Low

11. pdf.js tải từng đoạn sẽ hỏng khi signed URL 10 phút hết hạn. **Đã sửa:** tải trọn
    tệp một lần, có giới hạn kích thước.
12. Link tới tài liệu review này chưa tồn tại. **Đã sửa:** tài liệu này.
13. LF-Core-LiveClass § Hybrid Learning và Future Scope còn ghi tạo buổi tự động bị
    hoãn, trái với amendment 2026-11-05. **Đã sửa.**
14. `pdfjs-dist` chưa có trong `package.json`, `LF-Tech-Stack.md` chưa nhắc canvas và
    pdf.js. **Đã làm ở giai đoạn 1** (`pdfjs-dist` 4.10.38, LF-Tech-Stack § Canvas và PDF).
15. i18n và a11y mới nói tooltip. **Đã sửa:** mọi chữ trên bảng dùng
    `LF_liveclass_whiteboard_*` đủ `vi`/`en`; bàn phím; `prefers-reduced-motion`.
16. Màn hình trong lớp là máy dùng chung. **Đã sửa:** xoá dữ liệu bảng trong trình
    duyệt khi đăng xuất.
17. Có từ trước, không thuộc bảng: `MediaFileDeliveryController.php:28` đưa
    `display_name` chưa escape vào `Content-Disposition`. **Đã sửa 2026-11-08:** cả tệp gốc và
    bản PDF/video dùng `HeaderUtils::makeDisposition` (bỏ ký tự điều khiển và dấu `/` `\`,
    `filename*` UTF-8 kèm tên ASCII dự phòng); test trong `MediaFileFoundationTest`.

Không cần sửa: `database/liveclass/README.md`, `LF-SCHEMA-CONTRACT.json`, Guardrails
(không đổi schema hay guardrail). Xác thực kênh đã chạy dưới `tenant`, `auth`,
`tenant.user` (`bootstrap/app.php:21-24`).

---

## Quyết định Owner xác nhận 2026-11-07

**Học viên xem tài liệu trên bảng có theo luật mở khoá bài không?** Văn bản đã viết:
**không**. Phần giáo viên chiếu trong giờ học do cờ `available_during_session` của
Activity quyết định, giống AllVia chiếu cho cả lớp. Việc xem trên bảng không phải truy
cập Activity và không tính Progress. **Owner xác nhận: có xem**, không theo luật mở khoá.

## Chưa kiểm chứng

* Cohort có rời `active` được khi đang có Session `live` hay không.
* pdf.js khi `Storage::response` trên S3 bỏ qua Range: kiểm khi làm đợt 4 (S3), hoãn đến
  sau production.
* ~~AllVia xử lý nhiều người trình chiếu thế nào~~: không có luật (xem
  [đánh giá Media](LF-LiveClass-Whiteboard-Media-Evaluation.md) § 3 A3).

Owner xác nhận ngày 2026-11-07: amendment Frozen, mở giai đoạn 1 (bảng của giáo viên).
