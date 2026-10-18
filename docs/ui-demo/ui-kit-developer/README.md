# UI Kit — Developer handoff

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-10-18

Document Path: ui-demo/ui-kit-developer/README.md

Bản duy nhất, đầy đủ, theo thiết kế được chốt ngày 16/10/2026 lúc 10:47 (Việt Nam). Bao gồm sửa đổi cuối: radio, upload và editor có focus nhẹ; công tắc trạng thái bảng 32×18px, không có dấu ✓/×.

## Mở demo
Giữ nguyên cấu trúc thư mục và mở index.html trong trình duyệt hiện đại hỗ trợ dialog, popover và container queries. CSS, JavaScript và Lucide 0.468.0 đều có sẵn, không cần CDN. Chỉ có một file HTML.

## Cấu trúc
- index.html — toàn bộ thiết kế và nội dung mẫu.
- assets/ui-kit.css — toàn bộ kiểu dáng, responsive, hover/focus và trạng thái.
- assets/ui-kit.js — tương tác demo.
- assets/lucide.min.js — icon offline.
- COMPONENTS.md — danh mục và quy ước component.

## Nhúng demo trong Laravel
Copy assets vào public/ui-kit. Tạo Blade view, đưa markup bên trong body của index.html vào view. Nạp CSS bằng asset('ui-kit/ui-kit.css'), nạp asset('ui-kit/lucide.min.js') trước asset('ui-kit/ui-kit.js'). Không nạp trùng script.

Đây là gói bàn giao thiết kế frontend, chưa phải package Blade production. Các nhãn x-ui.* là tên chuẩn đề xuất để developer xây component. JavaScript của catalogue hiện dùng ID cố định cho một trang demo; không nhúng nguyên catalogue nhiều lần trong cùng trang.

## Tách thành component dùng chung
Dùng root riêng cho mỗi instance, khởi tạo theo data-ui thay vì document ID cố định. Mỗi control nhận id do caller truyền hoặc tự sinh UUID. Dùng cùng ID cho label/aria, không lặp ID khi thêm nhiều form. name là khóa gửi dữ liệu, không phải ID DOM. Radio cùng nhóm có chung name, khác nhóm phải có name khác.

Props chung: id, name, label, value, required, disabled, readonly, hint, error. Combobox dùng một component với multiple=false/true. Modal dùng columns=1/2/3. CSS class dùng BEM; giữ các design token ở một nơi.

Các CSS hiện có được scope theo #ui-catalog-soft, gồm cả lớp cũ mk-* và ux-* trong demo. Khi tách component, chuyển scope sang root dùng chung và hợp nhất selector theo component; không xem gói này là thư viện đã hoàn thành việc refactor production.

## Quy chuẩn đã duyệt
- Màu nhấn #485cc7; label 14px regular; ô nhập, nút và combobox 36px trên desktop.
- Touch targets có thể lớn hơn theo media query.
- Tab phẳng; hover/focus nhẹ; radio không có khung vuông; editor nhấn khung ngoài.
- Popup 1/2/3 cột rộng mục tiêu 480/800/1120px, giới hạn theo viewport; nội dung cuộn khi cần; header/footer luôn nhìn thấy.
- Bảng: checkbox, trạng thái switch 32×18px, thao tác sửa, dòng tổng và phân trang.
- Ảnh/audio/tài liệu: 200,000,000 byte/tệp. Video: 1,500,000,000 byte/tệp. Đơn vị MB/GB thập phân.

## Phần cần nối backend
Nhập/xuất Excel, submit, thao tác bảng và upload hiện chỉ minh họa. Developer nối route, CSRF, Form Request, phân quyền, trạng thái chờ/thất bại/thành công. Phải kiểm tra MIME và dung lượng phía server; thiết lập giới hạn upload server phù hợp. Preview dùng object URL, không upload tệp.

Calendar demo hiển thị dd/mm/yyyy, ngày ISO lưu trong data-iso: cần đồng bộ hidden input/value ISO khi tích hợp form. Combobox/tag cần serialize giá trị thành hidden inputs hoặc state ứng dụng. Editor là prototype dùng contenteditable/execCommand; cần thay bằng editor production và sanitize nội dung phía server. Dữ liệu demo không được lưu bền vững.

## Kiểm tra đã thực hiện
Đối chiếu nội dung xuất với bản xem cuối, kiểm tra cú pháp JavaScript, ID tĩnh và đường dẫn asset. Chưa chạy kiểm thử tích hợp Laravel hoặc kiểm thử trình duyệt tự động.
