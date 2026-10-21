# Danh mục component

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-10-21

Document Path: ui-demo/ui-kit-developer/COMPONENTS.md

| Tên đề xuất | Mẫu / biến thể |
|---|---|
| x-ui.field | Label, required, hint, error |
| x-ui.input | Text, number, readonly, disabled, invalid |
| x-ui.input-group | Đơn vị tiền / phần trăm, prefix/suffix |
| x-ui.combobox | Chọn một / nhiều; tìm trực tiếp; xóa |
| x-ui.search-input | Ô tìm kiếm và nút icon |
| x-ui.date-input | Lịch chọn ngày, hôm nay, xóa |
| x-ui.date-range | Ngày bắt đầu/kết thúc và kiểm tra thứ tự |
| x-ui.tag-input | Thêm bằng +/Enter, xóa thẻ |
| x-ui.textarea | Nhập nhiều dòng |
| x-ui.file-upload | Ảnh, video, audio, tài liệu |
| x-ui.checkbox | Chọn, bỏ chọn, indeterminate |
| x-ui.radio-group | Một lựa chọn trong nhóm |
| x-ui.switch | Công tắc form và bảng |
| x-ui.rich-text-editor | Toolbar và vùng soạn thảo |
| x-ui.button | Primary, outline, neutral, disabled; import/export/create/delete/submit/reset |
| x-ui.tabs | Tab phẳng có gạch chân |
| x-ui.table | Số liệu, checkbox, trạng thái, thao tác, tổng |
| x-ui.pagination | Trước/sau, trang hiện tại, số dòng/trang |
| x-ui.feedback | Thông báo và lỗi |
| x-ui.modal-form | 1–3 cột, responsive, cuộn nội dung |

Tên trên mô tả API triển khai dự kiến; gói chỉ chứa bản HTML/CSS/JS thiết kế. Các biến thể được trưng bày một lần; popup dùng lại chúng để minh họa bố cục.

## Bổ sung nút theo ảnh tham chiếu

- `ui-button ui-button--outline`: Thêm mới (`plus`), Tải xuống (`download`), Tải lên CV (`upload`).
- `ui-button ui-button--danger-outline`: Xóa (`trash-2`). Dùng native `disabled` khi không có quyền hoặc chưa chọn đối tượng.
- Dùng chung base geometry; không tạo component riêng cho mỗi nghiệp vụ.
- Chỉ bổ sung vào bản thiết kế đính kèm. Khi đưa vào LF, chuyển variant vào shared CSS sau khi review; không nạp CSS scoped demo vào production.
