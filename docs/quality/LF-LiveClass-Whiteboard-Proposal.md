# LF LiveClass Whiteboard Proposal

Version: 0.3

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-08

Document Path: quality/LF-LiveClass-Whiteboard-Proposal.md

---

# Mục đích

Đề xuất đưa **Bảng điện tử** (whiteboard) vào LearnForge cho buổi học trực tiếp: giáo viên chiếu tài liệu của buổi học lên màn hình cảm ứng, viết vẽ trên đó; học viên có thể mở bảng trên máy mình và thấy theo giáo viên. Nghiệp vụ lấy theo bảng điện tử của AllVia (dự án tham khảo, chỉ đọc).

**Trạng thái (2026-11-08):** Owner duyệt 2026-11-07; nội dung đã chuyển thành [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) § LiveClass Whiteboard Amendment, [LF-Core-LiveClass](../core/LF-Core-LiveClass.md) § Whiteboard và [LF-Media](../platform/LF-Media.md) § Whiteboard Rendering Exception, qua [Architecture Review](LF-LiveClass-Whiteboard-Architecture-Review.md). Giai đoạn 1 và 2 đã có code. Thiết kế hiện hành nằm ở các tài liệu đó; tài liệu này chỉ giữ quyết định ban đầu, bảng chức năng chuẩn nghiệm thu và kết quả của các điểm vướng.

# Quyết định Owner đã chốt (2026-11-07)

1. **Công nghệ: cách A.** Viết lại bằng Blade + Alpine + canvas 2D + `pdfjs-dist`, không nhúng React. Chỉ thêm một thư viện npm (`pdfjs-dist`).
2. **Làm cả hai:** màn hình chiếu của giáo viên và đồng bộ sang máy học viên.
3. **Mọi nút công cụ có tooltip.**
4. **Bỏ QR** (không có mã QR mời vào lớp, không có tài khoản tạm).
5. **Nghiệp vụ giống bảng của AllVia** (bảng chức năng ở dưới là chuẩn để đối chiếu khi làm và khi nghiệm thu).
6. **Giao diện:** bố cục và chức năng theo AllVia; màu, icon, tooltip theo UI Kit của LF; không chép code hay icon của AllVia.
7. **Ngăn tài liệu:** tài liệu của bài học trong buổi, cộng mở PDF từ máy như AllVia.
8. **Duyệt đề xuất (2026-11-07):** chuyển thành [amendment ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) § LiveClass Whiteboard Amendment, mục [Whiteboard của LF-Core-LiveClass](../core/LF-Core-LiveClass.md) và [LF-Media](../platform/LF-Media.md) § Whiteboard Rendering Exception.

# Bảng điện tử của AllVia (đã đọc code)

Nguồn: `allviacl-frontend-dev/packages/e-board`, `packages/e-board-sync`, `apps/teacher-app/src/app/(e-board)`, `apps/student-app/src/app/(e-board)`; backend `allviacl-backend-dev` (eBoard schedules, attendance SSE, quiz).

* **Lối vào:** trên thẻ lớp, giáo viên bấm "Lớp học trực tiếp" (chỉ bật khi lớp đang hoạt động, hôm nay có buổi, lớp có hình thức trực tiếp). Hệ thống ghi giờ bắt đầu buổi, rồi mở bảng trong cửa sổ popup 1280×800 với mã lớp và mã buổi.
* **Tên trong code:** "Bảng điện tử" (전자칠판, e-board); backend gọi phần AI là "올비아보드". Dịch vụ "smartboard" là dịch vụ AI RAG riêng, chưa gắn vào bảng ở frontend.
* **Đồng bộ:** phía giáo viên gửi qua Amazon Chime (đi chung đường với lớp video); phía học viên **đang tắt** (NACL-443) nên thực tế bảng của AllVia hiện chỉ là màn hình chiếu.
* **Phần backend chưa có giao diện:** theo dõi học viên vào bảng (SSE), AI quiz trên bảng (tạo, làm, thống kê, top 3).

## Danh sách chức năng làm chuẩn

| Nhóm | Chức năng AllVia | Đưa vào LF |
| --- | --- | --- |
| Thanh trên | Quay lại; tên lớp (sửa tạm được); tên và ID người dùng | Có (tên sửa tạm chỉ trên máy, không lưu) |
| Thanh trên | Hoàn tác, làm lại (10 bước); xoá trang hiện tại; xoá tất cả (hỏi xác nhận, xoá mọi trang) | Có |
| Thanh trên | Thu nhỏ, đặt lại chế độ xem, phóng to | Có |
| Thanh trên | Nút "Tài liệu bài học" mở ngăn tài liệu | Có |
| Thanh trên | Mã QR mời vào lớp | **Bỏ** (Owner chốt) |
| Công cụ | Con trỏ laser (chỉnh kích thước) | Có |
| Công cụ | Di chuyển (kéo màn hình, kéo ghi chú) | Có |
| Công cụ | Bút: 6 màu, chỉnh độ dày | Có |
| Công cụ | Bút dạ quang: chỉnh độ dày | Có |
| Công cụ | Tẩy: chỉnh kích thước | Có |
| Công cụ | Ghi chú dán: màu ngẫu nhiên trong 6 màu, nhập chữ, xoá | Có |
| Mặt bảng | Nền lưới; kéo bằng chuột giữa hoặc Alt + kéo; cuộn chuột để phóng 0,3–3 lần | Có |
| Mặt bảng | Chuột, chạm tay, bút (Pointer Events, giữ con trỏ cho bút và chạm) | Có |
| PDF | Hiển thị bằng pdf.js; trang trước / sau; nhập số trang; phóng 0,5–3 lần; vừa chiều rộng; vừa chiều cao (mặc định); đóng | Có |
| PDF | Nét vẽ và ghi chú gắn theo từng trang, bám theo kích thước gốc của PDF nên không lệch khi phóng | Có |
| PDF | Mở một tệp PDF từ máy | Có |
| Video | Phát video trong bảng | Có |
| Ngăn tài liệu | Danh sách tài liệu "Trong giờ học" của buổi: loại, số trang / số câu / thời lượng, tổng số; làm mới | Có, nguồn là hoạt động của bài học (xem dưới) |
| Ngăn tài liệu | Gói tài liệu (bundle) mở vào trong, có Quay lại | Không có trong LF, bỏ |
| Ngăn tài liệu | Link mở popup; Quiz mở bản xem trước; AI Speak | Link: LF chưa có loại hoạt động này. Quiz: mở theo cách LF đang mở hoạt động quiz. AI Speak: bỏ |
| Lưu | Lưu nét vẽ, ghi chú, trang, độ phóng trong trình duyệt theo từng buổi và từng tài liệu | Có (localStorage, giống AllVia) |
| Lưu | Mở lại tài liệu đang dở: "Bạn đã học đến trang N, có muốn tiếp tục không?" (chỉ giáo viên, trang > 1) | Có |
| Đồng bộ | Giáo viên gửi: nét vẽ thêm / xoá, xoá trang, hoàn tác, ghi chú thêm / sửa / xoá, đổi trang, đổi tài liệu, con trỏ, vị trí cuộn | Có, qua Reverb |
| Đồng bộ | Học viên vào sau: xin trạng thái hiện tại | Có |
| Đồng bộ | Học viên vẽ, ghi chú riêng trên máy mình, không gửi đi | Có |
| Đồng bộ | Báo "Đã kết nối đồng bộ" / "Mất kết nối đồng bộ" | Có |
| Học viên | Theo dõi tiến độ xem video được giao | Chưa đưa vào (thuộc Course Progress, xem riêng) |

# Điểm vướng lúc đề xuất và kết quả

| Điểm vướng | Kết quả |
| --- | --- |
| Luật hiển thị Media "mở tab mới" | LF-Media § Whiteboard Rendering Exception: PDF, video, âm thanh, video nhúng hiện trong bảng; link có chữ ký |
| Tài liệu Office pdf.js không đọc được | Xong 2026-11-08: chuyển sang PDF bằng LibreOffice ([đánh giá Media](LF-LiveClass-Whiteboard-Media-Evaluation.md) đợt 3) |
| Giáo viên, học viên chưa có trang buổi học | Xong ở giai đoạn 1 (`/teacher`) và 2 (`/my-courses`) |
| Điểm danh khi bắt đầu buổi | Chưa làm: điểm danh đang tắt, QR đã bỏ |
| Reverb production | Còn: chạy ổn định, qua proxy WebSocket, domain riêng của tenant thêm vào `REVERB_ALLOWED_ORIGINS` |
| Thiết bị thật (iPad, màn hình cảm ứng lớn, bút) | Còn: mới thử Chrome máy tính và iPhone giả lập |

# Lộ trình

| Giai đoạn | Nội dung | Trạng thái |
| --- | --- | --- |
| 0. Tài liệu | Amendment ADR-0002, LF-Core-LiveClass, LF-Media; Architecture Review | Xong 2026-11-07 |
| 1. Bảng giáo viên | Buổi học của giáo viên, bắt đầu buổi, bảng đủ chức năng, tooltip, lưu trong trình duyệt | Xong 2026-11-07 |
| 2. Đồng bộ học viên | "Xem bảng", kênh Reverb, endpoint phát thao tác, cache trạng thái | Xong 2026-11-07 |
| Media trên bảng | Âm thanh, YouTube/Vimeo, Office sang PDF, video faststart; S3 hoãn đến sau production | Xem [đánh giá Media](LF-LiveClass-Whiteboard-Media-Evaluation.md) |
| 3. Bảng thông minh AI | AI sinh quiz từ tài liệu buổi → giáo viên duyệt → đẩy lên bảng → học viên trả lời → thống kê (tỷ lệ đúng, phân bố đáp án, top 3). Dựa trên AI Knowledge, module Kiểm tra, [ADR-0017](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md), [ADR-0006](../adr/ADR-0006-AI-Foundation.md) | Hoãn; cần ADR "Live Quiz", Database Docs, review; làm khi Owner nhắc |

Nút chọn từ thư viện Media của giáo viên (`/teacher/media`) không làm ở giai đoạn này.

---

## Owner

Architecture Team

## Related Documents

* [ADR-0002 — LiveClass Foundation](../adr/ADR-0002-LiveClass-Foundation.md)
* [LF-Core-LiveClass](../core/LF-Core-LiveClass.md)
* [LF-Media](../platform/LF-Media.md)
* [LF Architecture Guardrails](../governance/LF-Architecture-Guardrails.md)
