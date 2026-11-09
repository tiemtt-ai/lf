# ADR-0022 — Course Type On Course Template

Version: 1.1

Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-09

Proposal Date: 2026-11-01

Approval Date: 2026-11-01

Approved By: LearnForge Business/Architecture Owner

Document Path: adr/ADR-0022-Course-Type-On-Template.md

Related ADRs:

* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md)
* [ADR-0013 — Course Template Version Duplicate to Draft](ADR-0013-Course-Template-Version-Duplicate-to-Draft.md)
* [ADR-0014 — Product Offering and Draft Content Binding](ADR-0014-Product-Offering-And-Draft-Binding.md) — phần "offering dimension" bị thay thế

---

# Context

ADR-0014 đặt chiều "loại khóa học" trên Product (`core_course_products.offering_type`
với `self_paced_course`, `live_online_course`, `blended_course`, `assessment`,
`learning_material`). Thực tế vận hành cho thấy:

* Loại khóa học quyết định cách soạn nội dung: thời điểm học của từng Activity
  (`anytime` hay Trước / Trong / Sau buổi học) chỉ có nghĩa khi biết khóa học có
  học theo lớp hay không. Product không sở hữu nội dung nên không thể ràng buộc
  điều này.
* Product có thể đóng gói một Template soạn cho học theo lịch nhưng lại khai báo
  tự học (và ngược lại); hệ thống không chặn được.
* Lớp học (Cohort) chọn được cả Product tự học.
* "Trực tuyến" mô tả hình thức của buổi học, không phải loại khóa học: buổi học
  theo lịch có thể online hoặc offline.
* `assessment` và `learning_material` chưa có nghiệp vụ; Product luôn đóng gói
  một Course Template.

# Decision

## 1. Ba loại khóa học, sở hữu bởi Course Template

| Mã | Tên hiển thị | Ý nghĩa |
| --- | --- | --- |
| `self_paced` | Tự học | Học bất kỳ lúc nào trong thời hạn học; không cần lớp. |
| `scheduled` | Theo lịch | Học theo lịch buổi học của lớp. Thay cho "Khóa học trực tuyến" cũ. |
| `blended` | Kết hợp | Có cả hoạt động học tự do và hoạt động theo buổi học; học theo lớp. |

* Working Template lưu `core_course_templates.course_type` (bắt buộc).
* Publish đóng băng giá trị vào `core_course_template_versions.course_type_snapshot`.
* Loại khóa học của mọi nơi dùng nội dung (Product, Cohort, Enrollment, runtime)
  là `course_type_snapshot` của **Version** được gắn, không phải giá trị đang
  sửa trên Template.

## 2. Chọn và đổi loại

* Bắt buộc chọn loại khi tạo Template; không có giá trị mặc định trên UI.
* Đổi loại bị **khóa** khi bất kỳ Product Item nào (mọi trạng thái Product)
  tham chiếu một Version đã xuất bản của Template này (`version_id` khác NULL).
* Khi chưa bị khóa, đổi loại phải kiểm tra toàn bộ working Activity theo giới
  hạn thời điểm của loại mới. Nếu có Activity không hợp lệ, từ chối và liệt kê
  từng Activity (Bài học + tên Activity) cần sửa. Hệ thống không tự chuyển đổi.
* Template `archived` chỉ đọc nên không đổi loại.
* Đổi loại là một thay đổi working content: tăng `working_revision`.

## 3. Giới hạn thời điểm học của Activity theo loại

| Loại | Lựa chọn hợp lệ | Hành vi |
| --- | --- | --- |
| `self_paced` | chỉ `anytime` | UI ẩn trường thời điểm, server luôn gán `anytime`; request gửi thời điểm theo buổi bị từ chối. Activity `live_class` không được phép. |
| `scheduled` | một hoặc nhiều `before_session`, `during_session`, `after_session` | `anytime` bị từ chối; không khai báo thì mặc định `during_session`. |
| `blended` | cả 4 lựa chọn (quy tắc loại trừ hiện hành giữ nguyên) | Không đổi so với hiện tại; `live_class` vẫn không được `anytime`. |

Kiểm tra ở backend khi tạo/sửa Activity, khi đổi loại Template, khi nhân bản
Version về draft và trong Publish readiness (chặn publish nếu snapshot không
nhất quán).

## 4. Product không còn sở hữu loại khóa học

* Bỏ `offering_type` khỏi form và nghiệp vụ Product. Bỏ hẳn `assessment` và
  `learning_material`. Product luôn đóng gói đúng một Course Template (phase-one
  `single_course`).
* Loại của Product = `course_type_snapshot` của Version mà Product Item gắn.
  Hiển thị chỉ đọc. Product **không lưu bản sao**; danh sách và bộ lọc đọc qua
  Product Item → Version (mỗi Product chỉ có một Item active nên chi phí join
  nhỏ, và không có nguy cơ lệch dữ liệu).
* Product quản lý tên thương mại (`title`, hiển thị cho học viên), giá, thời
  gian đăng ký, số ngày học / ôn tập. Template giữ tên nội bộ.
* `access_duration_days` / `review_duration_days` chỉ áp dụng cho `self_paced`;
  `scheduled` / `blended` lưu `NULL` cả hai.
* Product đã có Enrollment hoặc Cohort chỉ được chuyển sang Version cùng loại.
  Product chưa có Enrollment và Cohort được gắn lại tự do; loại đi theo Version mới
  và các trường số ngày không còn áp dụng được xóa trong cùng transaction.

Danh mục phân loại theo nội dung nên Product kế thừa từ Template và chỉ đọc. Nâng
Version của cùng Template được phép khi đã có học viên, danh mục theo Version mới;
Enrollment hiện có giữ Version đã đóng băng. Đổi sang Template khác chỉ khi Product
còn nháp và chưa phát sinh dữ liệu sử dụng.

## 5. Lớp học chỉ dùng khóa học theo lớp

Cohort chỉ được gắn Product có loại `scheduled` hoặc `blended` (tính từ
Version mà Product resolve được). Kiểm tra ở backend khi tạo Cohort.

## 6. Quyền học theo loại

* `self_paced`: giữ nguyên chính sách thời hạn hiện hành (ADR-0014, tính theo số
  ngày từ `enrolled_at`).
* `scheduled`: Enrollment không có thời hạn theo ngày (giữ nguyên chính sách
  "live" hiện hành); quyền học theo lớp, buổi học và Live Class.
* `blended`: Enrollment không có thời hạn theo ngày. Activity theo buổi học mở
  theo buổi; Activity `anytime` mở trong thời gian hoạt động của lớp
  (`start_date` đến hết `end_date` của Cohort mà Enrollment là thành viên).

Mọi chỗ đang đọc `offering_type` để tính quyền học chuyển sang đọc loại của
Version được gắn trên Enrollment.

## 7. Hình thức online/offline thuộc buổi học

Online / offline / hybrid là `delivery_mode` của Session, không phải loại khóa
học. Lớp "hybrid" là lớp `scheduled` (hoặc `blended`) có cả buổi online và buổi
offline. Nguyên tắc này chỉ được ghi nhận ở đợt này; chưa thay đổi code.

# Alternatives rejected

* Giữ loại trên Product: không ràng buộc được nội dung và thời điểm học.
* Lưu bản sao loại trên Product để lọc: thêm một nguồn phải đồng bộ cho lợi ích
  hiệu năng không đáng kể.
* Tự động chuyển thời điểm Activity khi đổi loại: có thể thay đổi ý đồ sư phạm
  mà người soạn không biết.
* Đặt loại chỉ trên Template (không snapshot): Version đã xuất bản phải bất biến;
  đổi loại Template không được làm đổi loại của Product đang bán.

# Compatibility and migration

Dữ liệu local hiện tại là dữ liệu test (owner xác nhận 2026-11-01). Migration:

1. Thêm `course_type` (nullable tạm thời) vào Template và `course_type_snapshot`
   vào Version.
2. Backfill suy ra từ thời điểm học của Activity, vì đây là cách duy nhất bảo
   đảm giá trị hợp lệ với nội dung: tất cả `anytime` (hoặc chưa có Activity) →
   `self_paced`; không có `anytime` → `scheduled`; lẫn lộn → `blended`. Template
   suy ra từ working Activity; mỗi Version suy ra từ Version Activity của chính
   nó. Activity `live_class` luôn tính là theo buổi học, vì các Version xuất bản
   trước quy tắc thời điểm học vẫn mang mặc định `anytime` trên buổi trực tuyến. `offering_type` cũ của Product (gồm `assessment`, `learning_material`)
   không dùng làm nguồn và bị bỏ. Bản đồ tên cũ → mới để đối chiếu:
   `self_paced_course → self_paced`, `live_online_course → scheduled`,
   `blended_course → blended`. Giai đoạn code phải báo danh sách Product có
   `offering_type` cũ khác loại suy ra.
3. Đặt NOT NULL, xóa `offering_type` và index `idx_ccp_v2_offering` khỏi Product.
4. Cohort đang gắn Product `self_paced` vẫn đọc được nhưng không được kích hoạt,
   thêm học viên hoặc thêm buổi học cho tới khi xử lý; giai đoạn code phải báo
   danh sách này.

ADR-0014 phần Draft binding (Product Item `template_id`, `version_id` nullable)
giữ nguyên hiệu lực.

# Implementation record

* `2026_11_01_000100_move_course_type_to_course_templates.php`: thêm
  `course_type` / `course_type_snapshot` (NOT NULL, CHECK trên MySQL/MariaDB,
  index `idx_cct_course_type`), backfill như trên, xóa `offering_type` và
  `idx_ccp_v2_offering`.
* `2026_11_01_000200_clear_learning_days_of_class_course_products.php`: xóa số
  ngày học / ôn tập của Product mà Version gắn không phải `self_paced`
  (Enrollment giữ nguyên snapshot).
* Code: `App\Support\CourseType`, `CourseTemplateCourseTypeService`, kiểm tra
  trong Activity, Template, Publish readiness, nhân bản Version, Product (popup
  chọn Template, nhãn và bộ lọc), Cohort, Enrollment duration và
  `VersionActivityAccessService`.
* `Partial`: cửa sổ thời gian Trước / Trong / Sau buổi học chưa có runtime (câu
  hỏi mở); hoạt động theo buổi của Theo lịch / Kết hợp hiện chỉ cần học viên là
  thành viên lớp đang hoạt động.

# Open questions

* Số ngày ôn tập sau khi lớp kết thúc cho `scheduled` / `blended`.
* Thuộc tính online/offline của buổi học: phòng, địa điểm, điểm danh, sĩ số.
* Tái sử dụng nội dung giữa các Template khác loại (hiện tại: nhân bản Template).
* Cửa sổ thời gian chính xác của Trước / Trong / Sau buổi học (runtime hiện chưa
  tính theo buổi).
* Bundle (`product_type = bundle`) chứa Version khác loại.

# Consequences

Template trở thành nguồn duy nhất của loại khóa học; Product, Cohort và
Enrollment đọc loại qua Version. Form Product đổi sang popup chọn Template.
Activity form, Template form, Product form/list, Cohort form, Enrollment
duration policy và Publish readiness cần thay đổi.

# Amendment 1.1 — Hai loại khóa học (Approved 2026-11-09)

Owner (Business/Architecture) duyệt ngày 2026-11-09. Amendment này thay mục 1, bảng `scheduled` ở mục 3 và các chỗ nêu Theo lịch ở mục 5–7; phần
còn lại giữ nguyên.

## Bối cảnh

Từ 2026-11-09, Activity `live_class` mang nghĩa "Buổi học trực tiếp" (có giáo
viên giảng, có điểm danh), mỗi Lesson tối đa một; Lesson không có nó là buổi tự
học ([LF-Core-Course](../core/LF-Core-Course.md#activity-learning-availability)).
Vì vậy "buổi nào có giáo viên" đã nằm ở từng Lesson. Phân biệt `scheduled` và
`blended` ở cấp khóa học không còn giúp người soạn; nó chỉ cấm `anytime` ở
`scheduled`, khiến buổi tự học trong khóa Theo lịch phải xếp ngày mới mở được.

## Đề xuất

| Mã | Tên hiển thị | Ý nghĩa |
| --- | --- | --- |
| `self_paced` | Tự học | Đăng ký là học, mở theo thời hạn học; không có lớp. Không đổi. |
| `blended` | **Theo lớp** | Học trong lớp, mở theo lịch của lớp. Activity chọn `anytime` (mở từ `start_date` đến hết `end_date` của lớp) hoặc Trước / Trong / Sau buổi học. Giữ đúng quy tắc `blended` hiện hành. |

* `scheduled` thôi là lựa chọn mới: form Template chỉ còn Tự học / Theo lớp;
  backend chỉ nhận `self_paced`, `blended` khi tạo hoặc đổi loại.
* Giữ mã `blended` cho "Theo lớp" để không đổi CHECK của
  `course_type` / `course_type_snapshot`.
* Working Template đang `scheduled` chuyển sang `blended` (dữ liệu nháp, sửa
  được; mọi Activity `scheduled` đều hợp lệ với `blended`).
* **Version đã xuất bản không bị sửa** (Course Guardrails Rule 7). `scheduled`
  trong `course_type_snapshot` là giá trị cũ, hiển thị là "Theo lớp" và chạy như
  hiện nay. Vì Activity của Version `scheduled` không có `anytime`, chạy theo quy
  tắc `blended` cũng cho cùng kết quả.
* Mọi chỗ so loại ("Cohort chỉ dùng khóa học theo lớp", "Product đã dùng chỉ đổi
  sang Version cùng loại", bộ lọc danh sách) coi `scheduled` và `blended` là
  cùng một loại.

## Ảnh hưởng

Template form, danh sách và bộ lọc Template / Product / Enrollment, đổi loại
Template, Product đổi Version, Cohort, nhãn vi/en, tài liệu
`core_course_cohorts.md`, `core_course_template_activities.md`,
`LF-Core-Course.md` (mục Course Type). Một migration dữ liệu chuyển Template
`scheduled` → `blended`; không đổi schema.

## Quyết định cho các câu hỏi mở

* Giữ mã `blended` cho "Theo lớp"; không đổi CHECK, không đổi schema.
* Chuyển ngay mọi working Template `scheduled` sang `blended`; không tăng
  `working_revision` vì nghĩa không đổi.

## Architecture Review (2026-11-09) — Pass

* Tenant: migration chỉ đổi giá trị trong từng dòng, không đổi `customer_id`;
  mọi truy vấn lọc vẫn theo tenant.
* Guardrails Course Rule 7: không sửa Version đã xuất bản; snapshot `scheduled`
  được đọc như Theo lớp. Activity của Version `scheduled` không có `anytime`,
  nên quy tắc `blended` cho cùng kết quả ở runtime.
* Enrollment, Progress, Product binding không đổi dữ liệu.
* Database: chỉ migration dữ liệu, không đổi bảng hay CHECK.

## Implementation record

* `App\Support\CourseType`: `SELECTABLE` (Tự học, Theo lớp), `normalize`,
  `storedValues`, `same`; `scheduled` dùng chung quy tắc thời điểm với `blended`;
  nhãn của `scheduled` là nhãn Theo lớp.
* Form Template chỉ nhận `self_paced` / `blended`; bộ lọc Template, Product,
  Enrollment và tab chọn Template của Product chỉ còn Tự học / Theo lớp, lọc
  Theo lớp gồm cả `scheduled`. Product đã dùng được chuyển giữa Version
  `scheduled` và `blended`. Nhân bản Version `scheduled` về bản nháp ghi
  `blended`. Form Activity bỏ nhánh riêng của Theo lịch.
* Migration `2026_11_09_000100_merge_scheduled_course_type_into_class_based`.

# Approval

Approved by the Business/Architecture Owner on 2026-11-01, gồm cả các diễn giải
đã báo cáo ở giai đoạn tài liệu (tên mã ngắn, không lưu bản sao loại trên Product,
điều kiện khóa đổi loại, quy tắc nhân bản, backfill suy ra từ Activity).
