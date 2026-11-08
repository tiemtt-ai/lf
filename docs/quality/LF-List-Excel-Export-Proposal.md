# Đề xuất xuất Excel cho các danh sách Admin / Teacher

Version: 1.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-07

Document Path: quality/LF-List-Excel-Export-Proposal.md

---

Ngày rà soát: 2026-11-07 · Loại: rà soát + đề xuất (không sửa code) · Nguồn:
`php artisan route:list`, `routes/web.php`, `routes/modules/*.php`,
`resources/views/**/index.blade.php` và các tab trong `course-cohorts/partials/tabs`.

## Hiện trạng

- `app/Support/SimpleXlsx.php` có sẵn `read()` (tối đa 2000 dòng) và `write()` — đủ dùng cho xuất, không cần thư viện mới.
- Đã có **Nhập + Xuất Excel**: duy nhất **Câu hỏi kiểm tra** (`assessments.questions.export|import|import.template`, cả admin và teacher). Xuất theo bộ lọc đang chọn, lọc `customer_id` của tenant hiện tại.
- Ghi danh hàng loạt (`course-enrollments.bulk-*`) là chọn trên giao diện, **không** nhập từ file.
- Không danh sách nào khác có xuất/nhập Excel.

## Bảng đề xuất

| Danh sách | Route | Đã có xuất? | Đề xuất | Cột nên xuất | Ưu tiên |
| --- | --- | --- | --- | --- | --- |
| Người dùng (tab Quản trị / Giáo viên / Học viên) | `admin.users.index` | Chưa | **Nên** | Họ tên, Email, Điện thoại, Vai trò, Trạng thái, Ngày tạo | P1 |
| Ghi danh khóa học | `admin.course-enrollments.index` | Chưa | **Nên** | Học viên, Email, Sản phẩm, Nguồn, Ngày ghi danh, Trạng thái | P1 |
| Học viên trong lớp (toàn tenant) | `admin.course-cohort-students.index` | Chưa | **Nên** | Học viên, Email, Điện thoại, Lớp, Sản phẩm, Trạng thái, Ngày vào lớp | P1 |
| Học viên của một lớp (tab Học viên) | `admin.course-cohorts.students.index` / tab trong `course-cohorts.show` | Chưa | **Nên** (danh sách lớp gửi giáo viên, điểm danh giấy) | STT, Học viên, Email, Điện thoại, Trạng thái, Ngày vào lớp | P1 |
| Điểm danh (tab Điểm danh của lớp) | tab trong `admin.course-cohorts.show` | Chưa | **Nên** — dạng ma trận cả lớp | Học viên × từng buổi (ngày): Trạng thái; cột tổng Có mặt / Vắng / Tỉ lệ; Ghi chú | P1 |
| Kết quả kiểm tra | `admin\|teacher.assessments.results.index` | Chưa | **Nên** | Học viên, Email, Đề, Lần làm, Nộp lúc, Điểm, Đạt/Không đạt, Trạng thái chấm | P1 |
| Đăng ký kiểm tra | `admin\|teacher.assessments.registrations.index` | Chưa | **Nên** | Học viên, Email, Đề, Nguồn, Số lần làm / tối đa, Ngày đăng ký, Trạng thái | P2 |
| Thống kê kết quả (theo câu) | `admin\|teacher.assessments.results.statistics` | Chưa | **Cân nhắc** — hữu ích cho báo cáo chất lượng đề | Câu hỏi, Loại, Số trả lời, Tỉ lệ đúng, Điểm TB, Chờ chấm | P3 |
| Đánh giá năng lực của giáo viên | `teacher.cohorts.show` (judgments) | Chưa | **Cân nhắc** — khi cần gửi phụ huynh/báo cáo | Học viên, Kỹ năng/năng lực, Mức, Người đánh giá, Ngày | P3 |
| Lớp học | `admin.course-cohorts.index` | Chưa | **Cân nhắc** | Tên lớp, Sản phẩm, Phiên bản, Sĩ số / Sức chứa, Ngày bắt đầu–kết thúc, Trạng thái | P2 |
| Buổi học / lịch (tab Buổi học) | tab trong `admin.course-cohorts.show` | Chưa | **Cân nhắc** — lịch gửi học viên/giáo viên | Ngày, Giờ, Bài, Giáo viên, Hình thức, Trạng thái | P3 |
| Sản phẩm khóa học | `admin.course-products.index` | Chưa | **Cân nhắc** (bảng giá) | Tên, Loại, Giá, Hiển thị, Trạng thái | P3 |
| Lớp của giáo viên | `teacher.cohorts.index` | Chưa | Không cần — chỉ là lối vào chấm đánh giá | — | — |
| Câu hỏi kiểm tra | `admin\|teacher.assessments.questions.index` | **Có** (nhập + xuất + file mẫu) | Đã đủ | — | — |
| Đề kiểm tra | `admin\|teacher.assessments.quizzes.index` | Chưa | Không cần — nội dung soạn trong hệ thống | — | — |
| Bài đọc, Rubric, Danh mục câu hỏi | `*.assessments.passages\|rubrics\|categories.index` | Chưa | Không cần | — | — |
| Khung chương trình, Phần / Bài / Hoạt động | `admin.course-templates.*`, `teacher.course-templates.*` | Chưa | Không cần — cấu trúc nội dung, không phải dữ liệu vận hành | — | — |
| Giáo viên của khung chương trình | `*.course-templates.teachers.index` | Chưa | Không cần — danh sách ngắn | — | — |
| Khung năng lực | `admin.learning-frameworks.index` | Chưa | Không cần | — | — |
| Danh mục khóa học, Danh mục media | `*.course-categories.index`, `admin.media-categories.index` | Chưa | Không cần | — | — |
| Thư viện media | `admin\|teacher.media.index` | Chưa | Không cần | — | — |
| Đề xuất AI | `*.ai-authoring.proposals.index` | Chưa | Không cần | — | — |
| Bản ghi buổi học (tab Bản ghi) | tab trong `admin.course-cohorts.show` | Chưa | Không cần | — | — |

## Trạng thái triển khai (2026-11-07)

P1 đã làm: Người dùng, Ghi danh, Học viên của một lớp, Điểm danh, Kết quả kiểm tra. "Học viên trong lớp (toàn tenant)" không làm vì trang không có lối vào. Chi tiết xem [LF-List-Excel-Export-P1-Regression-Audit.md](LF-List-Excel-Export-P1-Regression-Audit.md).

## Lưu ý khi triển khai

1. **Cách ly tenant**: truy vấn xuất dùng lại đúng query của danh sách, lọc theo `TenantContext::customerId()`; không nhận `customer_id` từ request.
2. **Phạm vi giáo viên**: Kết quả và Đăng ký kiểm tra phía teacher chỉ chứa đề của chính giáo viên (đúng như danh sách hiện tại). File xuất phải giữ nguyên phạm vi đó.
3. Xuất theo **bộ lọc đang chọn** (từ khóa, trạng thái, tab vai trò…), không chỉ trang hiện tại; đặt giới hạn số dòng hợp lý.
4. **Dữ liệu cá nhân**: email/điện thoại chỉ cho vai trò đang được xem trên màn hình; không xuất mật khẩu/token. Cân nhắc ghi log ai xuất.
5. Dùng lại mẫu `AssessmentQuestionImportController::xlsx()` (header `no-store`, tên file có thời gian); nên tách thành helper chung khi có từ hai màn hình trở lên.
6. Nếu triển khai, đây là Existing-Feature Change → làm audit theo `LF-Regression-Audit.md`.
