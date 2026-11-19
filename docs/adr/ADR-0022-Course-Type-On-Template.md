# ADR-0022 — Course Type On Course Template

Version: 1.4

Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-19

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
* ~~`Partial`: cửa sổ thời gian Trước / Trong / Sau buổi học chưa có runtime~~ —
  có từ Amendment 1.3, Đợt 2 (2026-11-18): Activity theo buổi mở theo lịch của lớp.

# Open questions

* ~~Số ngày ôn tập sau khi lớp kết thúc cho `scheduled` / `blended`~~ — Amendment 1.4
  (2026-11-18): xem lại không giới hạn thời gian.
* Thuộc tính online/offline của buổi học: phòng, địa điểm, điểm danh, sĩ số.
* Tái sử dụng nội dung giữa các Template khác loại (hiện tại: nhân bản Template).
* ~~Cửa sổ thời gian chính xác của Trước / Trong / Sau buổi học~~ — đã chốt ở
  Amendment 1.3, mục Đợt 2 — Mở theo lịch.
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

# Amendment 1.2 — Trường của Lesson theo loại khóa học (Approved 2026-11-18)

Owner chốt ngày 2026-11-18. Amendment này thêm quy tắc cho form và dữ liệu Lesson;
quy tắc thời điểm của Activity giữ nguyên.

## Quyết định

| Trường của Lesson | Tự học | Theo lớp |
| --- | --- | --- |
| Cho phép học thử (`is_preview`) | Có | Không; luôn `false` |
| Quy tắc mở khóa (`unlock_rule`, `prerequisite_match`, `unlock_at`, bài điều kiện) | Có | Không; luôn `none`, không có bài điều kiện |
| Loại bài học (`lesson_type`) | Không; luôn `regular` | Có |

Căn cứ:

* Nội dung của lớp đi theo lịch của lớp, không theo điều kiện "xong bài trước mới mở bài
  sau": với điều kiện đó, học viên vắng một buổi sẽ không mở được buổi sau dù lớp đã học
  tới (MK Live mục 3.2). Runtime mở theo lịch: Amendment 1.3, mục Đợt 2 — Mở theo lịch.
* Học thử là xem trước để quyết định mua, hợp với khóa đăng ký là học. Ở khóa theo
  lớp, học thử là dự một buổi của lớp, là việc khác.
* Loại bài học là nhãn của buổi trên lịch lớp; khóa tự học không có lịch.

## Quy tắc

* Form Lesson chỉ hiện các trường của loại khóa học; server tự đặt các trường còn lại
  theo bảng trên, kể cả khi request gửi giá trị khác, và không kiểm tra quy tắc mở
  khóa của khóa theo lớp.
* Đổi loại Template (khi được phép) và nhân bản Version về bản nháp đưa các trường mà
  loại mới không dùng về giá trị trong bảng, và xóa bài điều kiện khi sang Theo lớp.
* **Version đã xuất bản không bị sửa** (Course Guardrails Rule 7). Version theo lớp
  xuất bản trước 2026-11-18 vẫn chạy quy tắc mở khóa đã đóng băng; chi tiết buổi ở
  lớp chỉ hiện quy tắc khi nó khác "Không giới hạn".
* Không đổi schema, không cần migration. Dữ liệu dev ngày 2026-11-18: 9 Lesson của
  Template theo lớp, không bài nào có học thử, quy tắc mở khóa hay loại khác
  `regular`; 13 Lesson của Template tự học, không bài nào có loại khác `regular`.

## Implementation record

* `CourseType::usesLessonPreviewAndUnlock()`, `CourseType::usesLessonType()`.
* `CourseTemplateLessonController` đặt lại các trường theo loại trước khi kiểm tra và
  một lần nữa dưới khóa Template.
* `CourseTemplateCourseTypeService::resetUnusedLessonFields()`, gọi khi đổi loại
  Template và khi nhân bản Version về bản nháp.
* Form Lesson và chi tiết buổi ở tab Buổi học của lớp.
* Test: `CourseTemplateLessonManagementTest::test_lesson_fields_follow_the_template_course_type`,
  `CourseTemplateManagementTest::test_changing_the_course_type_clears_the_lesson_fields_the_new_type_does_not_use`.

# Amendment 1.3 — Loại buổi học và hoạt động của khóa Theo lớp (Approved 2026-11-18)

Owner duyệt ngày 2026-11-18: ba loại buổi học; buổi có người phụ trách thiếu Buổi học
trực tiếp thì chặn xuất bản; bỏ thời lượng ước tính và tiêu chí thủ công; các điều chỉnh
sau lượt rà soát cùng ngày (bảng điện tử, đổi loại Template, thứ tự triển khai).
Căn cứ nghiệp vụ: "Nghiệp vụ luồng học tập lớp MK" (16/11/2026) mục 2.3, 3.2, 3.3, 3.4.
Amendment này thay câu "Lesson không có Activity `live_class` là buổi tự học"
(LF-Core-Course, Amendment 2026-11-09): buổi tự học nay do người soạn chọn.

## Loại buổi học

Chỉ khóa Theo lớp có trường này (Amendment 1.2); nhãn trên form là "Loại buổi học".
Mỗi loại ứng với một cách hệ thống xử lý buổi.

| Mã | Tên | Người phụ trách | Bảng điện tử, link | Activity |
| --- | --- | --- | --- | --- |
| `regular` | Buổi học (mặc định) | Giáo viên | Có, theo hình thức của buổi | Trước / Trong / Sau |
| `practice` (mới) | Thực hành, ngoại khóa | Có | Không; nhập "phòng học hoặc địa điểm" | Trước / Trong / Sau |
| `self_study` (mới) | Tự học | Không | Không | Không chia phần |

* **Buổi có người phụ trách** (`regular`, `practice`) có **đúng một** Activity `live_class`;
  mỗi Activity khác thuộc **đúng một** phần Trước / Trong / Sau buổi học (một trong ba cờ
  `available_*_session` bằng 1, `available_anytime` bằng 0). `live_class` luôn là Trong buổi
  học (Amendment 2026-11-17, giữ nguyên).
* **Buổi tự học** (`self_study`) không có Activity `live_class` và không chia phần; server
  lưu `available_anytime = 1`.
* Ôn tập và kiểm tra không còn là loại riêng: mục đích của buổi nằm ở tên bài; buổi có
  giáo viên chọn `regular`, buổi học viên tự làm chọn `self_study`. Phân biệt giữa kỳ, cuối
  kỳ để tính điểm (nếu cần) thuộc về Quiz, không thuộc về buổi học.
* Giá trị cũ `review`, `midterm_exam`, `final_exam`, `other_exam` không còn chọn được;
  đọc và chạy như `regular` ở cả bản nháp lẫn Version đã xuất bản. Dữ liệu dev ngày
  2026-11-18 không có dòng nào dùng bốn giá trị này (22 Lesson, 24 Version Lesson đều
  `regular`).
* `lesson_type` là VARCHAR không có CHECK: không đổi schema.

## Form Activity của khóa Theo lớp

1. Loại và học liệu.
2. Tên, mô tả.
3. Phần: chọn một trong Trước / Trong / Sau, chọn sẵn theo tab đang mở của bài. Buổi
   tự học không có ô này. "Học bất kỳ lúc nào" không còn là lựa chọn.
4. Tiêu chí hoàn thành theo loại và ô "Tính vào hoàn thành" (`is_required`):

| Loại Activity | Tiêu chí (`completion_rule`) |
| --- | --- |
| Tài liệu | `view`: học viên bấm mở |
| Video tải lên, audio | `watch_percent`: tỷ lệ xem/nghe, mặc định 80, từ 1 đến 100 |
| Quiz | `submit` (đã nộp) hoặc `pass` (lần chấm cao nhất đạt điểm tối thiểu) |
| Video từ liên kết | `view`: học viên bấm mở |
| Buổi học trực tiếp | `join`: Có mặt hoặc Muộn |

Không có ở khóa Theo lớp; server tự đặt giá trị, kể cả khi request gửi khác:
"Cho phép học thử" (`is_preview = 0`), "Quy tắc mở khóa" (`unlock_rule = none`, không
`unlock_after_activity_id`, `unlock_at`), "Thời lượng ước tính"
(`estimated_duration_seconds = NULL`). Tiêu chí `manual` bị từ chối.

## Bảng điện tử

Bảng điện tử của buổi hiện **mọi học liệu của bài**, không phân biệt phần. Trước Amendment
này bảng chỉ lấy Activity có `available_during_session`; khi mỗi Activity chỉ thuộc một
phần, quy tắc cũ làm slide đặt ở "Trước buổi học" không trình chiếu được. Amendment này
thay điều kiện đó trong mục Material của ADR-0002 LiveClass Whiteboard Amendment; văn bản
ADR-0002 đã Frozen nên giữ nguyên, LF-Core-LiveClass ghi quy tắc mới.

## Server và xuất bản

* Tạo/sửa Activity bị từ chối khi không đúng các quy tắc trên (lỗi ở trường tương ứng).
* Đổi loại buổi sang `self_study`: bị từ chối khi bài còn Activity `live_class`; các Activity
  còn lại được chuyển sang `available_anytime = 1`. Đổi từ `self_study` sang loại có người
  phụ trách: Activity giữ nguyên và được nêu ở kiểm tra xuất bản cho đến khi chọn phần.
* Kiểm tra xuất bản (Theo lớp) chặn và nêu tên bài, hoạt động: buổi có người phụ trách
  thiếu `live_class` (`lesson_live_class_missing`); buổi tự học có `live_class`
  (`lesson_self_study_live_class`); Activity không thuộc đúng một phần hoặc dùng tiêu chí
  không hợp lệ (`activity_class_phase`, `activity_class_completion`).
* Đổi loại Template Tự học → Theo lớp: mọi bài nhận `self_study`, nội dung giữ nguyên và
  hợp lệ ngay; người soạn đổi loại từng bài khi cần. (Thay quy tắc của Amendment 1.2 ở
  chiều này; chiều Theo lớp → Tự học vẫn đưa về `regular`.)
* **Version đã xuất bản không bị sửa** (Course Guardrails Rule 7).

## Hiện trạng runtime trước Đợt 2

Trước Đợt 2, quyền học **chưa tính Trước / Trong / Sau**: Activity theo buổi mở
cho mọi học viên đang thuộc lớp, không phụ thuộc buổi đã diễn ra hay chưa; Activity
`available_anytime` mở từ `start_date` đến hết `end_date` của lớp. Vì vậy sau Đợt 1, phần
Trước / Trong / Sau là cách sắp nội dung trên trang bài học; trình tự theo lịch chỉ có từ
Đợt 2.

## Đợt 2 — Mở theo lịch

Owner duyệt chi tiết ngày 2026-11-18 (MK Live mục 3.2). Đợt 2 thay quy tắc "Activity theo buổi
mở cho mọi học viên đang thuộc lớp" ở mục Hiện trạng runtime trước Đợt 2. Đợt 2 không thay
đổi schema.

**Phạm vi.** Quy tắc chỉ áp dụng cho khóa Theo lớp. Trong đó chỉ tính Activity không phải
`live_class` của buổi có người phụ trách.

**Giữ nguyên:**

* Buổi tự học mở suốt thời gian lớp cho đến Đợt 4; từ Đợt 4, Bước 3 mở lúc buổi tự học bắt đầu.
* Activity `available_anytime` của Version cũ vẫn theo thời gian lớp.
* `live_class` vẫn mở cho học viên của lớp. Giờ vào phòng do chính sách điểm danh quyết định
  (`opens_before_minutes`).
* Điều kiện về ghi danh, thành viên lớp và trạng thái lớp vẫn kiểm tra trước, như hiện nay.

**Buổi của bài.** Buổi của bài là các Session `curriculum` của lớp gắn với `version_lesson_id`
của bài.

* Session `cancelled` không tính: bài đã chuyển sang Session mới (ADR-0023 §9), nên giờ mở đi
  theo Session mới. Session `draft` chưa phải lịch của lớp nên cũng không tính.
* Session `no_show` vẫn tính, như buổi đã qua theo lịch.
* Đổi lịch thì giờ mở đổi theo giá trị hiện tại.
* Bài có nhiều buổi: Trước và Trong tính theo buổi đầu, Sau tính theo buổi cuối, xếp theo
  `scheduled_start_at`.

**Thời điểm mở.** Mọi thời điểm chỉ tính theo giờ trên lịch (`scheduled_start_at`,
`scheduled_end_at`), không dùng giờ giáo viên bắt đầu hay kết thúc thực tế. Các giờ này là
giờ địa phương theo cột `timezone` của Session, được đổi sang UTC trước khi so sánh.

| Phần | Mở lúc |
| --- | --- |
| Trước buổi học | Khi buổi liền trước kết thúc, nhưng không muộn hơn giờ bắt đầu của buổi đầu. Buổi liền trước là Session `curriculum` được tính (trên) của lớp, ngay trước buổi đầu của bài theo `scheduled_start_at`. Nếu không có buổi liền trước: 00:00 ngày của buổi đầu, theo `timezone` của buổi đó |
| Trong buổi học | Giờ bắt đầu của buổi đầu |
| Sau buổi học | Giờ kết thúc của buổi cuối |

* Một Activity cũ thuộc nhiều phần thì mở theo phần sớm nhất.
* Bài chưa có buổi nào thì Activity khóa, với lý do "Chưa có lịch".
* Đã mở thì vẫn mở đến hết lớp, trong giới hạn trạng thái lớp như hiện nay. Học viên vào
  lớp muộn thấy ngay nội dung đã mở.

**Nơi áp dụng.** Quy tắc nằm trong quyết định quyền học của Activity
(`VersionActivityAccessService`), nên tự áp dụng ở trang bài học, ghi tiến độ, làm Quiz và
link tệp học liệu của học viên.

* Bảng điện tử do giáo viên trình chiếu không theo quy tắc này (mục Bảng điện tử).
* Giáo viên và admin xem trước không bị khóa.
* Tiến độ, tiêu chí hoàn thành và điểm danh không đổi (MK 3.3).

**Hiển thị cho học viên.** Activity chưa mở hiện "Mở lúc HH:mm, dd/mm" theo giờ của lớp, hoặc
"Chưa có lịch" khi bài chưa có buổi. Quyết định quyền học trả về thời điểm mở để trang hiện
được dòng này.

## Đợt 3 — Tính vào điểm danh và học bù

Owner duyệt nghiệp vụ và thiết kế dữ liệu (một cột mới ở hai bảng Activity) ngày 2026-11-18
(MK Live mục 2.3, 3.3, 3.4, 3.5). Đã triển khai cùng ngày; xem Implementation record.

**Ô "Tính vào điểm danh"** (`counts_for_attendance`). Học viên phải đạt tiêu chí hoàn thành
của Activity được tick thì mới được tính điểm danh (phần Trong) hoặc học bù (phần Sau).

* Chỉ có ở khóa Theo lớp, buổi có người phụ trách, phần Trong hoặc Sau, và Activity không phải
  `live_class`. Ở mọi trường hợp khác server lưu 0, kể cả khi request gửi khác.
* Không tick thì điểm danh như hiện nay: chỉ xét lần vào lớp (mã QR, nút Điểm danh, link
  phòng họp).
* `live_class` luôn tính vào điểm danh theo bộ quy tắc của lớp; form hiện ô này tick sẵn
  và khóa, cột lưu 0.
* Buổi tự học chưa có ô này cho đến Đợt 4, Bước 3.
* Version đã xuất bản trước Đợt 3 có giá trị 0, nên lớp đang chạy điểm danh như cũ.

**Form Activity của khóa Theo lớp.** Khung tiêu chí đổi tên thành "Cài đặt tiêu chí hoàn
thành và điểm danh" và có khối "Áp dụng cho" gồm hai ô tick:

| Ô | Cột | Ý nghĩa |
| --- | --- | --- |
| Tính vào hoàn thành | `is_required` | Tính vào tiến độ hoàn thành buổi học. Thay công tắc "Bắt buộc để hoàn thành bài học" |
| Tính vào điểm danh | `counts_for_attendance` | Phải hoàn thành mới được tính điểm danh hoặc học bù. Không hiện ở phần Trước |

Dòng ghi chú của `live_class` không nêu tên bộ quy tắc hay số phút: Activity soạn ở
Template dùng chung cho nhiều lớp, mỗi lớp có thể dùng bộ quy tắc khác.

**Cách xét kết quả một buổi có người phụ trách.** Quy tắc dưới đây chỉ áp dụng cho kết quả
do hệ thống ghi. Kết quả giáo viên hoặc quản trị ghi tay là kết quả sau cùng, hệ thống
không tính lại.

1. **Vào lớp.** Giờ vào so với các mốc của bộ quy tắc cho ra Có mặt, Muộn hoặc Vắng, như
   hiện nay. Vào sau mốc vắng thì ghi Vắng ngay, kèm giờ vào.
2. **Buổi có Activity phần Trong được tick.** Chỉ ghi Có mặt hoặc Muộn khi học viên đã
   xong mọi Activity đó trước giờ kết thúc trên lịch (`scheduled_end_at`), không phụ thuộc
   thứ tự giữa vào lớp và làm bài.
   * Vào lớp mà chưa xong: hệ thống lưu giờ vào và hình thức, kết quả ở trạng thái chờ
     (`registered` có `joined_at`). Khi xong trong giờ, kết quả tính theo giờ vào đã lưu.
   * Hết giờ mà chưa xong: kết quả vẫn chờ cho đến khi học bù hoặc tự ghi vắng.
3. **Học bù.** Sau giờ kết thúc trên lịch, học viên xong mọi Activity phần Sau được tick
   thì được ghi Có mặt, nguồn Học bù (`attendance_source = makeup`).
   * Điều kiện: bộ quy tắc của lớp bật học bù (`makeup_enabled`), lớp chưa kết thúc và còn
     trong hạn. Hạn `makeup_within_days = N` là hết 23:59 ngày thứ N sau ngày của buổi,
     theo `timezone` của buổi; `NULL` là đến khi lớp kết thúc.
   * Áp dụng cho: chưa có kết quả, kết quả đang chờ, và Vắng do hệ thống ghi (kể cả vào
     sau mốc vắng và tự ghi vắng).
   * Không đè kết quả ghi tay, Có phép, Có mặt hoặc Muộn đã có.
   * Buổi không có Activity phần Sau nào được tick thì không có học bù. Buổi kết thúc
     trước ngày học viên vào lớp cũng không có học bù.
   * Giờ vào lớp và hình thức đã lưu được giữ. Lịch sử sửa ghi một dòng do hệ thống.
4. **Tự ghi vắng.** Như hiện nay, áp dụng cả cho kết quả đang chờ, và giữ giờ vào đã lưu.
5. **Hoàn thành `live_class`.** Vẫn theo kết quả sau cùng: Có mặt hoặc Muộn, kể cả Có mặt
   do học bù.

**Một Activity được tính là xong khi nào** (chỉ cho việc xét điểm danh và học bù):

| Loại | Xong khi |
| --- | --- |
| Tài liệu, video, audio, video từ liên kết | Đạt tiêu chí hoàn thành; lấy thời điểm hoàn thành (`completed_at`) |
| Quiz tiêu chí `submit` | Đã nộp; lấy giờ nộp, kể cả khi bài còn chờ chấm |
| Quiz tiêu chí `pass` | Một lần làm đạt điểm tối thiểu; lấy giờ nộp của lần đó, chấm muộn vẫn tính |

Phần Trong so thời điểm đó với giờ kết thúc trên lịch; phần Sau so với hạn học bù. Bài có
nhiều buổi thì mỗi buổi xét riêng theo giờ của chính nó, trên cùng một tiến độ.

**Quiz chờ chấm và tiến độ.** Quiz tiêu chí `submit` chỉ tính hoàn thành khi lần làm đã
chấm xong (`graded`), thay quy tắc "nộp là hoàn thành" của ADR-0032 D3. Quiz tự chấm được
chấm ngay khi nộp nên không đổi. Quy tắc áp dụng cho mọi loại khóa; tiến độ đã ghi không
bị thu hồi.

**Hiển thị.**

* Học viên: Activity được tick có nhãn "Tính vào điểm danh". Kết quả buổi hiện "Chờ ghi nhận
  điểm danh" khi đang chờ, và "Có mặt · Học bù" khi học bù.
* Giáo viên và quản trị: kết quả đang chờ hiện "Chưa điểm danh" kèm giờ vào; kết quả học bù
  có nhãn "Học bù". Ghi tay vẫn như hiện nay.
* Bộ quy tắc điểm danh: form hiện lại hai ô "Cho học bù" và "Hạn học bù" (đã ẩn từ
  2026-11-18 vì chưa có nghiệp vụ); hai thiết lập bắt đầu có hiệu lực.

**Dữ liệu.** Cột `counts_for_attendance` ở `core_course_template_activities` và
`core_course_template_version_activities` (tài liệu Database của hai bảng, Amendment
2026-11-18). Bảng điểm danh không đổi schema: `attendance_source` nhận thêm giá
trị `makeup`, kết quả đang chờ dùng `registered` kèm `joined_at`.

## Đợt 4 — Buổi thực hành và buổi tự học có lịch

Owner duyệt nghiệp vụ ngày 2026-11-18 (MK Live mục 2.4, 3.2, 3.4, 3.5) và chọn làm ba bước,
báo sau mỗi bước. Bước 2 đổi schema bảng buổi học: thiết kế dữ liệu chờ Owner duyệt ở tài
liệu Database trước khi có migration.

**Bước 1 — Buổi thực hành, ngoại khóa** (`practice`; không migration; đã triển khai 2026-11-18).

* Buổi của lớp gắn với bài `practice` không có bảng điện tử và không có link phòng họp.
* Form buổi không có ô hình thức, link, công cụ điểm danh; có ô bắt buộc "Phòng học hoặc
  địa điểm" và ô địa chỉ. Server lưu `delivery_mode = offline`, không link, và
  `attendance_tool = button`, kể cả khi request gửi khác.
* Buổi tạo tự động (Lưu lịch, kéo thả) cho bài `practice` mang cùng các giá trị đó, địa
  điểm để trống cho đến khi người xếp lịch nhập.
* Điểm danh bằng nút Điểm danh của học viên, vì không có bảng thì không có mã QR. Bộ quy tắc
  đặt "giáo viên ghi tay" cho buổi tại lớp thì giáo viên ghi tay. Các mốc Có mặt, Muộn, Vắng
  như buổi thường.
* Giáo viên, quản trị và học viên không có lối mở bảng của buổi này; server từ chối khi mở
  trực tiếp bằng địa chỉ.
* Giáo viên vẫn bắt đầu và kết thúc buổi ở trang Buổi học trực tiếp; bắt đầu xong thì ở lại
  trang đó, không mở cửa sổ bảng.

**Bước 2 — Buổi tự học có ngày trên lịch lớp** (`self_study`; thay đổi Foundation; đã triển
khai 2026-11-18, migration `2026_11_18_000200`).

* Buổi tự học là một Session loại riêng: gắn với bài, không gắn Activity `live_class`. Không
  có hình thức, phòng, link, bảng điện tử; không bắt buộc giáo viên.
* Có giờ bắt đầu và kết thúc như buổi thường (Owner chốt 2026-11-09).
* Lịch học xếp ngày cho mọi bài theo thứ tự chương trình: mỗi bài có người phụ trách một vị
  trí (Activity `live_class` của nó), mỗi bài tự học một vị trí. Tạo buổi từ lịch, kéo thả
  và hủy buổi dùng chung cách tính đó. Lịch không đủ ngày cho mọi bài thì không lưu được.
* Buổi tự học đổi được ngày giờ và hủy được (hủy thì các bài sau lùi một ngày như buổi
  thường); không có form sửa chung, không bắt đầu và không đánh dấu "không diễn ra" bằng tay.
  Hết giờ kết thúc theo lịch thì tự chuyển Đã kết thúc. Kích hoạt lớp không đòi giáo viên cho
  buổi tự học.
* Lớp đang chạy không bị ảnh hưởng: loại `self_study` có từ 2026-11-18, chưa Version đã xuất
  bản nào dùng.
* Phần này thay quy tắc "chỉ Activity `live_class` được xếp lịch" của ADR-0002 (Session
  binding) và "vị trí là danh sách Activity `live_class`" của ADR-0023 mục 3. ADR-0002 đã
  Frozen nên giữ nguyên văn bản; LF-Core-LiveClass và ADR-0023 ghi quy tắc mới khi triển khai.

**Bước 3 — Mở nội dung và điểm danh buổi tự học** (MK 3.4; dùng cột `counts_for_attendance` đã có;
đã triển khai 2026-11-18, migration `2026_11_18_000300`).

* **Mở nội dung.** Activity của buổi tự học mở lúc buổi bắt đầu theo lịch, như phần Trong của
  buổi thường (Owner chốt 2026-11-18). Bài tự học chưa có buổi thì Activity khóa, "Chưa có
  lịch". Quy tắc này thay "mở suốt thời gian lớp" của Đợt 2.
* **Ô "Tính vào điểm danh".** Form Activity của buổi tự học có ô này; CHECK của cột được nới
  cho Activity `available_anytime = 1`. Đổi bài sang Tự học giữ nguyên các ô đã tick (thay quy
  tắc "đưa về 0" của Đợt 3).
* **Buổi có Activity được tick.** Học viên xong mọi Activity đó trước giờ kết thúc trên lịch
  thì được ghi Có mặt, do hệ thống ghi, giờ ghi nhận là lúc xong Activity cuối. Không có Muộn.
* **Buổi không tick Activity nào.** Học viên bấm nút Điểm danh trong giờ của buổi thì được ghi
  Có mặt.
* **Học bù** (Owner chốt 2026-11-18). Buổi có Activity được tick: xong mọi Activity đó sau giờ
  kết thúc, trong hạn học bù của bộ quy tắc, thì được ghi Có mặt, nguồn Học bù; các điều kiện
  khác như mục Đợt 3. Buổi không tick Activity nào không có học bù.
* **Tự ghi vắng** như buổi thường, không cần buổi có giáo viên và không phụ thuộc thiết lập
  "giáo viên ghi tay" của bộ quy tắc.
* **Ghi tay.** Quản trị và giáo viên của lớp ghi tay được cho buổi tự học dù buổi không có
  giáo viên riêng.
* **Buổi liền trước.** Buổi tự học được tính là một buổi của lớp khi xét "buổi liền trước" cho
  phần Trước của bài kế tiếp (mục Đợt 2).
* **Trang học viên.** Trong giờ của buổi, trang Khóa học của tôi hiện thẻ "Buổi tự học đang
  diễn ra" với nút Vào bài học, kèm nút Điểm danh (buổi không tick Activity nào) hoặc dòng
  nhắc hoàn thành nội dung trước giờ kết thúc.
* Kết quả ghi tay của giáo viên hoặc quản trị vẫn là kết quả sau cùng.

## Dữ liệu dev ngày 2026-11-18

9 Lesson của Template Theo lớp, mỗi bài đúng một `live_class`; 15 Activity, không Activity
`anytime` hay nhiều phần; 2 Activity video/audio đang dùng `view` sẽ được nêu khi xuất bản;
14 Activity có thời lượng ước tính, giá trị bị xóa khi sửa.

## Thứ tự triển khai

| Đợt | Nội dung | Điều kiện |
| --- | --- | --- |
| 1 | Loại buổi học, form Activity, quy tắc server, kiểm tra xuất bản, bảng điện tử hiện mọi học liệu | Không migration |
| 2 | Mở theo lịch (MK 3.2): Trước mở khi buổi liền trước kết thúc (buổi đầu: 00:00 ngày học), Trong mở lúc bắt đầu, Sau mở lúc kết thúc; đã mở thì mở đến hết lớp (mục Đợt 2 — Mở theo lịch) | Đã triển khai 2026-11-18; không migration |
| 3 | Ô "Tính vào điểm danh", xét điểm danh theo nội dung, học bù (MK 2.3, 3.4, 3.5). Khung tiêu chí đổi tên thành "Cài đặt tiêu chí hoàn thành và điểm danh" (mục Đợt 3 — Tính vào điểm danh và học bù) | Đã triển khai 2026-11-18; migration `2026_11_18_000100` |
| 4 | Ba bước (mục Đợt 4 — Buổi thực hành và buổi tự học có lịch): (1) form buổi của lớp cho `practice`; (2) buổi tự học có ngày trên lịch lớp; (3) mở nội dung và điểm danh buổi tự học | Nghiệp vụ đã duyệt 2026-11-18. Bước 1 đã triển khai 2026-11-18, không migration; Bước 2 đã triển khai 2026-11-18 (migration `2026_11_18_000200`, ADR-0023 bản 1.6); Bước 3 đã triển khai 2026-11-18 (migration `2026_11_18_000300`) |


## Câu hỏi còn mở

* ~~Quiz tiêu chí `submit`: hoàn thành khi nộp hay khi chấm xong~~ — Owner chốt 2026-11-18
  theo MK 3.3: hoàn thành khi chấm xong, điểm danh tính khi nộp (mục Đợt 3).
* ~~Học bù ở buổi tự học~~ — Owner chốt 2026-11-18: hoàn thành muộn trong hạn học bù thì
  ghi Có mặt, nguồn Học bù (mục Đợt 4, Bước 3).

## Implementation record

Đợt 1 triển khai ngày 2026-11-18, không migration:

* `App\Support\LessonType` (`SELECTABLE`, `normalize`, `isLed`, `labelKey`);
  `CourseType::isClassBased()`, `CourseType::completionRules()`, `CourseType::CLASS_WATCH_PERCENT`.
* Form Lesson: ô "Loại buổi học" ba lựa chọn; giá trị cũ mở là "Buổi học". Server chỉ nhận
  ba mã; `CourseTemplateLessonController::startSelfStudy()` từ chối khi bài còn `live_class`
  và chuyển Activity còn lại sang `available_anytime = 1`.
* Form Activity: ô "Phần của buổi học" (ba thẻ), khối "Cài đặt tiêu chí hoàn thành"; không
  còn "Học bất kỳ lúc nào", học thử, quy tắc mở khóa, thời lượng ước tính, tiêu chí thủ công;
  loại có một tiêu chí được chọn sẵn, `watch_percent` điền sẵn 80. Buổi tự học không có ô
  phần và không có loại Buổi học trực tiếp.
* `CourseTemplateActivityController`: `fitClassRules()` trước khi kiểm tra và
  `fitClassActivity()` dưới khóa Template; thời lượng tự lấy từ tệp video/audio không ghi vào
  `estimated_duration_seconds` của khóa Theo lớp.
* `CourseTemplatePublishGraphValidator::validateClassSessions()`: bốn mã ở mục Server và
  xuất bản.
* `CourseTemplateCourseTypeService`: `resetUnusedLessonFields()` sang Theo lớp còn xóa học thử,
  quy tắc mở khóa, thời lượng ước tính của Activity và đưa `duration_seconds` của bài về 0
  (cả khi nhân bản Version về bản nháp); `startLessonsAsSelfStudy()` khi đổi Tự học → Theo lớp.
* Bảng điện tử: `LiveClassWhiteboardService::materials()` và
  `MediaService::generateLiveClassBoardSignedUrl()` bỏ điều kiện `available_during_session`.
* Danh sách bài của Template hiện nhãn loại khi khác "Buổi học"; tab Buổi học của lớp đọc
  nhãn qua `LessonType::labelKey()`.
* Test: `CourseTemplateActivityManagementTest` (một phần, buổi tự học, form lớp),
  `CourseTemplateLessonManagementTest` (ba loại, đổi sang Tự học),
  `CourseTemplatePublishingTest::test_class_sessions_block_publish_until_each_follows_its_session_type`,
  `CourseTemplateManagementTest` (Tự học → Theo lớp), `LiveClassWhiteboardTest` (học liệu Sau
  buổi học lên bảng). Các test về media và về trường của khóa Tự học dựng Template Tự học.

Đợt 2 triển khai ngày 2026-11-18, không migration:

* `VersionActivityAccessService::scheduleAccess()`: tính giờ mở theo mục Đợt 2, đọc giờ qua
  `LiveClassSessionTime`; lý do từ chối `session_not_open` (kèm giờ mở) và `session_unscheduled`.
* `ActivityAccessDecision::$unlockAt`: thời điểm mở, theo `timezone` của buổi đầu của bài.
* Trang bài học của học viên hiện "Mở lúc HH:mm, dd/mm" hoặc "Chưa có lịch".
* Test: `CourseActivityProgressRuntimeTest::test_class_activities_open_by_the_planned_schedule_of_their_lesson`,
  `LearnerMyLearningTest::test_a_class_lesson_page_shows_when_each_scheduled_activity_opens`.

Đợt 3 triển khai ngày 2026-11-18:

* Migration `2026_11_18_000100_add_counts_for_attendance_to_course_activities`: cột và CHECK ở
  hai bảng Activity; kiểm trên MariaDB 11.4.
* Soạn: `CourseTemplateActivityController::fitClassRules()` chỉ giữ giá trị 1 ở phần Trong / Sau
  của buổi có người phụ trách; đổi bài sang Tự học và đổi Template sang Tự học đưa về 0; xuất
  bản và nhân bản Version chép giá trị; kiểm tra xuất bản có mã `activity_attendance_scope`.
* Form Activity: khối "Áp dụng cho" với hai ô tick thay công tắc "Bắt buộc để hoàn thành bài
  học" ở khóa Theo lớp; khóa Tự học giữ công tắc cũ.
* `LiveClassAttendanceContent` đọc Activity được tick và thời điểm học viên làm xong.
  `LiveClassAttendanceRecorder`: `enter()` giữ kết quả chờ, `contentChanged()` chốt kết quả
  chờ và ghi học bù, `makeupClosesAt()` tính hạn; `markAbsent()` giữ giờ vào của kết quả chờ;
  `reevaluateAfterStart()` đưa kết quả về chờ khi nội dung chưa xong.
* `CourseProgressRecorder`: gọi `contentChanged()` sau khi Activity được tick hoàn thành và sau
  mỗi lần Quiz được nộp hoặc chấm; Quiz tiêu chí `submit` hoàn thành khi có lần làm `graded`.
* Hiển thị: nhãn "Tính vào điểm danh" ở trang bài học; "Chờ ghi nhận điểm danh", "Có mặt · Học
  bù" ở trang lớp của học viên; dòng chờ và nhãn "Học bù" ở bảng điểm danh; form bộ quy tắc
  hiện lại hai ô học bù.
* Test: `LiveClassAttendanceCheckInTest` (`test_ticked_content_decides_attendance_and_make_up`,
  `test_make_up_follows_the_policy_of_the_class`), `CourseTemplateActivityManagementTest`
  (`test_counts_for_attendance_is_kept_only_in_the_during_and_after_parts_of_a_led_class_session`),
  `CourseQuizActivityTest::test_submit_completes_once_the_attempt_is_graded`,
  `LiveClassAttendancePolicyManagementTest::test_the_make_up_settings_are_shown_and_saved`; mở
  rộng test xuất bản và test đổi bài sang Tự học.

Đợt 4, Bước 1 triển khai ngày 2026-11-18, không migration:

* `LiveClassPracticeSession`: nhận biết bài `practice` và giữ các giá trị server đặt cho buổi
  (`BOX`: `offline`, không link, `attendance_tool = button`).
* `CourseCohortOperationController::fitPracticeSession()` áp các giá trị đó trước khi kiểm tra
  form tạo và sửa buổi; `LiveClassScheduleSessionGenerator`, `CourseCohortContentTree` và
  `CourseCohortContentReorder` dùng cùng giá trị cho buổi tạo tự động và ngày gợi ý;
  `LiveClassSessionOperations` không cho giáo viên đổi công cụ điểm danh.
* `LiveClassWhiteboardService`: `context()` trả 404 cho buổi `practice`; danh sách buổi của
  giáo viên có `is_practice`, không có nút mở bảng; `startForTeacher()` cho biết buổi có bảng
  hay không để chuyển hướng.
* Form buổi của lớp và popup sửa của giáo viên: ô "Phòng học hoặc địa điểm", không có hình
  thức, link, công cụ điểm danh; tab Buổi học của quản trị không có link mở bảng.
* Test: `CourseCohortManagementTest::test_a_practice_session_is_held_at_a_place_without_a_link_and_checked_in_by_the_button`,
  `LiveClassWhiteboardTest::test_a_practice_session_starts_without_a_board`.

Đợt 4, Bước 2 triển khai ngày 2026-11-18:

* Migration `2026_11_18_000200_add_self_study_sessions`: `delivery_mode` nullable, CHECK
  `chk_lcs_type_binding` nhận `self_study`, CHECK mới `chk_lcs_self_study_box`; kiểm trên
  MariaDB 11.4.
* `LiveClassSelfStudySession` giữ loại buổi và các giá trị server đặt (`BOX`).
* `CourseCohortContentTree`: `positions()` (thay `liveActivities()`) gồm Activity `live_class`
  và bài `self_study`; `positionKey()`, `slotsOfLesson()`, `selfStudySessionsByLesson`; ngày
  gợi ý của bài tự học có khóa `l{lesson}`.
* `LiveClassScheduleSessionGenerator` tạo buổi tự học khi Lưu lịch, không gắn giáo viên.
  `CourseCohortContentReorder`: kéo thả và dời sau hủy theo `position_key`; box mang cả
  `session_type`.
* `LiveClassSessionLifecycleService`: buổi tự học chỉ đi `scheduled → completed` hoặc
  `cancelled`; `completeOverdue()` kết thúc buổi tự học đã hết giờ. Read model khóa sửa chung
  và phân giáo viên; kích hoạt lớp bỏ qua buổi tự học khi đếm buổi chưa có giáo viên.
* Tab Buổi học: dòng bài tự học có ngày, nhãn "Tự học", nút xem chi tiết và đổi lịch; buổi đã
  hủy nằm trong bài. Trang lớp của học viên hiện "Tự học" thay cho hình thức.
* Test: `CourseCohortManagementTest::test_a_self_study_lesson_holds_a_date_like_a_live_activity`,
  `LearnerMyLearningTest::test_the_class_page_lists_a_self_study_session_without_a_delivery_mode`.

Đợt 4, Bước 3 triển khai ngày 2026-11-18:

* Migration `2026_11_18_000300_allow_attendance_tick_on_self_study_activities`: nới CHECK
  `counts_for_attendance` ở hai bảng Activity; kiểm trên MariaDB 11.4.
* Soạn: `fitClassRules()` giữ ô tick cho Activity của buổi tự học; đổi bài sang Tự học không
  xóa ô tick; kiểm tra xuất bản chấp nhận ô tick ở buổi tự học; form có ô tick với ghi chú riêng.
* `VersionActivityAccessService`: Activity của bài `self_study` mở lúc buổi tự học bắt đầu
  (`session_unscheduled` khi chưa có buổi); buổi tự học tính vào "buổi liền trước".
* `LiveClassAttendanceContent`: `tickedSelfStudy()`, `lastDoneAt()`.
  `LiveClassAttendanceRecorder`: `selfStudyContentChanged()` ghi Có mặt hoặc học bù theo nội
  dung, `enterSelfStudy()` cho nút Điểm danh, `markAbsent()` tự ghi vắng buổi tự học,
  `tool()` trả `self_study`.
* Ghi tay: quản trị và giáo viên của lớp ghi được cho buổi tự học.
* Hiển thị: thẻ "Buổi tự học đang diễn ra" ở trang học viên; bảng điểm danh ghi "Tự học, ghi
  nhận lúc HH:mm".
* Test: `LiveClassAttendanceCheckInTest` (`test_a_self_study_session_records_attendance_by_its_ticked_content`,
  `test_a_self_study_session_without_ticked_content_is_checked_in_by_its_button`),
  `CourseActivityProgressRuntimeTest::test_self_study_content_opens_when_its_session_starts`; cập
  nhật test form Activity và test đổi bài sang Tự học.

# Amendment 1.4 — Lớp kết thúc thì chỉ xem lại (Approved 2026-11-18)

Owner duyệt nghiệp vụ ngày 2026-11-18. Căn cứ: "Nghiệp vụ luồng học tập lớp MK" mục 3.6.
Không đổi schema. Đã triển khai cùng ngày.

## Hiện trạng

Sau khi lớp chuyển `completed`, học viên vẫn học như khi lớp đang chạy: tiến độ vẫn tăng và
Quiz vẫn bắt đầu, nộp được. Vào lớp điểm danh và học bù đã dừng (cần lớp `active`); tự ghi
vắng vẫn chạy cho các buổi cuối.

## Quyết định

* **Lúc kết thúc.** Lớp kết thúc khi chuyển sang `completed`, tức khi người quản lý lớp bấm
  "Hoàn thành lớp học". LF không tự kết thúc lớp theo ngày (khác MK, Owner chốt).
  *Owner đổi quyết định này ngày 2026-11-19 và đã triển khai cùng ngày: xem mục "Lớp tự kết
  thúc theo ngày" bên dưới.*
* **Chỉ xem lại.** Từ lúc đó học viên của lớp:
  - vẫn mở tài liệu, xem video, nghe audio, xem video từ liên kết và xem kết quả các bài đã nộp;
  - không được ghi tiến độ mới: mở, xem, nghe không đổi tiến độ Activity, Lesson và Course;
  - không bắt đầu lần làm Quiz mới;
  - không điểm danh và không học bù (đã có).
* **Quiz đang làm dở.** Lần làm đã mở trước lúc lớp kết thúc được nộp đến hết giờ làm bài
  của nó và vẫn được chấm; kết quả được lưu nhưng không làm tăng tiến độ.
* **Chấm muộn.** Bài nộp trước lúc lớp kết thúc mà chấm sau đó vẫn cập nhật điểm; tiến độ
  cũng không tăng.
* **Thời gian xem lại** không giới hạn: đến khi lớp bị lưu trữ hoặc Enrollment không còn
  quyền học. Quyết định này đóng câu hỏi "số ngày ôn tập sau khi lớp kết thúc" của ADR này.
* **Nội dung chưa mở** theo lịch (bài chưa có buổi) vẫn khóa.
* **Hiển thị.** Trang khóa học, trang bài học và trang lớp của học viên hiện thông báo lớp
  đã kết thúc và đang ở chế độ xem lại; nút bắt đầu Quiz không còn.
* Giáo viên, quản trị, tự ghi vắng và ghi tay điểm danh không đổi. Lớp `archived` như hiện
  nay: học viên không còn truy cập.
* Khóa Tự học không bị ảnh hưởng, kể cả Enrollment Tự học còn giữ chỗ trong một lớp cũ.
* Sửa điểm danh bằng tay sau khi lớp kết thúc vẫn cập nhật hoàn thành của Buổi học trực
  tiếp: đó là sửa của người dạy, không phải hoạt động của học viên.

## Bổ sung 2026-11-19 — Sửa điểm danh sau khi lớp kết thúc (Approved, đã triển khai)

Owner duyệt nghiệp vụ ngày 2026-11-19 và cho code cùng ngày. Căn cứ: "Nghiệp vụ luồng học tập
lớp MK" mục 1.4 và 2.5. Không đổi schema.

Hiện trạng đã kiểm trong code ngày 2026-11-19: lớp `completed` thì cả quản trị
(`CourseCohortOperationController::saveAttendance`) lẫn giáo viên
(`TeacherCohortAttendanceController::save`) đều bị từ chối ghi tay vì hai chỗ này đòi lớp
`active`; lớp `completed` không mở lại được. Hai gạch đầu dòng cuối của mục Quyết định ở
trên mô tả điều chưa đúng với code. Nút "Hoàn thành lớp học" không chặn; hộp xác nhận đã
liệt kê số buổi chưa kết thúc (`CourseCohortLifecycleService::completionWarnings`) nhưng không
nói hệ quả cho học viên.

Quyết định:

* **Ai sửa.** Ở lớp `completed`, quản trị ghi và sửa điểm danh tay không giới hạn thời gian.
  Giáo viên của lớp ghi và sửa đến hết hạn sửa của bộ quy tắc (`teacher_edit_days`, tính từ
  giờ kết thúc theo lịch của buổi), như khi lớp đang `active`.
* **Buổi nào.** Cùng điều kiện như lớp `active`: buổi đã tới giờ bắt đầu, có giáo viên (buổi
  tự học thì không cần), không nằm ngoài thời gian thuộc lớp của học viên (ADR-0034).
* **Lớp `archived`** chỉ xem, không ai sửa.
* **Hệ quả của một lần sửa.** Lưu lịch sử như thường. Chuyên cần và hoàn thành của Buổi học
  trực tiếp tính lại theo kết quả mới, kéo theo tiến độ bài học và khóa học. Đây là sửa cho
  đúng sự thật của buổi, không phải hoạt động của học viên, nên không trái quy tắc "chỉ xem
  lại".
* **Vẫn đóng.** Lối vào lớp, mã QR, nút Điểm danh và học bù không mở lại.
* **Cảnh báo khi hoàn thành lớp.** Khi lớp còn buổi chưa diễn ra, hộp xác nhận của "Hoàn
  thành lớp học" nói rõ còn bao nhiêu buổi, rằng học viên chỉ còn xem lại và lớp không mở
  lại được. Người dùng vẫn bấm tiếp được; hệ thống không chặn.

Triển khai ngày 2026-11-19, không migration:

* `LiveClassAttendanceRecorder::MANUAL_COHORT_STATUSES` (`active`, `completed`) thay điều kiện
  "lớp `active`" ở `CourseCohortOperationController::saveAttendance`,
  `TeacherCohortAttendanceController::show/save` và hai màn điểm danh. Hạn sửa của giáo viên,
  điều kiện buổi và ADR-0034 giữ nguyên trong `saveManual()`; tính lại tiến độ đi qua
  `CourseProgressRecorder::attendanceChanged()` như trước.
* Lối vào lớp, QR, nút Điểm danh và học bù không đổi: các lối này vẫn đòi lớp `active`.
* Màn điểm danh của lớp `completed` hiện `LF_attendance_completed_class_note`; lớp `archived`
  và lớp chưa hoạt động hiện thông báo chỉ xem.
* Hộp xác nhận "Hoàn thành lớp học": cảnh báo số buổi nói thêm học viên không vào được các
  buổi đó, chỉ còn xem lại; nội dung chính nói điểm danh tay vẫn sửa được.
* Test: `LiveClassAttendanceCheckInTest::test_a_completed_class_is_still_corrected_by_hand_and_an_archived_one_only_reads`,
  `CourseCohortManagementTest::test_complete_confirmation_warns_about_what_is_left_without_blocking`.

### Dọn buổi khi hoàn thành lớp (Approved 2026-11-19, đã triển khai)

Owner duyệt ngày 2026-11-19 và cho code cùng ngày. Không đổi schema.

Hiện trạng đã kiểm trong code ngày 2026-11-19, khi lớp bị hoàn thành mà còn buổi chưa xong:

* Buổi chưa tới giờ giữ `scheduled` mãi: mọi lần đổi trạng thái buổi đòi lớp `active`
  (`LiveClassSessionLifecycleService::transition`), nên sau đó không ai hủy được.
* `LiveClassAttendanceRecorder::markAbsent()` quét cả lớp `completed`, nên khi qua giờ của
  từng buổi, cả lớp bị ghi Vắng cho một buổi không diễn ra.
* Trang học viên vẫn lấy buổi đó làm "Buổi tiếp theo" và tính vào tổng số buổi, nên tiến độ
  lịch học không đủ 100%. Tỉ lệ chuyên cần không đổi vì chỉ tính trên buổi `completed`.
* Buổi đang `live` giữ `live` mãi: bảng đóng, nhưng `completeOverdue()` chỉ quét lớp `active`.

Quyết định: trong cùng transaction của "Hoàn thành lớp học", trước khi lớp rời `active`, hệ
thống dọn các buổi của lớp theo giờ của từng buổi:

| Buổi lúc hoàn thành lớp | Xử lý |
|---|---|
| `scheduled`, chưa tới giờ bắt đầu | `cancelled`, `cancellation_reason` = "Lớp hoàn thành trước khi buổi diễn ra" |
| `live`; buổi tự học `scheduled` đang trong giờ | `completed`, `actual_end_at` = thời điểm hoàn thành lớp |
| `scheduled`, đã tới giờ bắt đầu, không phải buổi tự học | Giữ nguyên: vẫn ghi tay được, vẫn tự ghi Vắng |

* Hủy ở đây không dời lịch: không buổi nào nhận `superseded_by_session_id`
  ([ADR-0023 §9](ADR-0023-Cohort-Content-Order.md) chỉ áp dụng khi lớp còn chạy).
* Buổi bị hủy theo quy tắc sẵn có của `cancelled`: không nhận điểm danh, không bị ghi Vắng,
  không tính vào tổng số buổi; học viên thấy "Đã hủy".
* Buổi bị kết thúc giữ điểm danh đã có; học viên chưa có kết quả đi theo lệnh tự ghi vắng
  như mọi buổi `completed`.
* Việc hủy không hoàn tác được: buổi `cancelled` là trạng thái cuối và lớp `completed` không
  mở lại. Hộp xác nhận nói rõ số buổi chưa diễn ra sẽ bị hủy và số buổi đang diễn ra sẽ kết
  thúc, thay cho câu ":count buổi học chưa kết thúc".
* Dữ liệu cũ: DB dev ngày 2026-11-19 không có buổi `scheduled` hay `live` nào trong lớp
  `completed` hoặc `archived`, nên không cần chạy bù.

### Lớp tự kết thúc theo ngày (Approved 2026-11-19, đã triển khai)

Owner duyệt ngày 2026-11-19, thay gạch đầu dòng "Lúc kết thúc" của mục Quyết định, và cho
code cùng ngày. Căn cứ: "Nghiệp vụ luồng học tập lớp MK" mục 3.6. Không đổi schema.

Hiện trạng đã kiểm trong code ngày 2026-11-19:

* Lớp chỉ sang `completed` khi có người bấm. Quên bấm thì lớp `active` mãi: học viên không
  chuyển sang chỉ xem lại, hạn học bù "không giới hạn" không đóng.
* Giáo viên chính có `class.manage` bấm được "Kết thúc lớp" bất cứ lúc nào
  ([ADR-0033](ADR-0033-Teacher-Permissions-And-Tenant-Owner.md), đợt 3b); việc này không hoàn
  tác được.
* Mọi buổi chưa hủy phải nằm trong `start_date`–`end_date` của lớp
  (`core_course_cohorts.md` § end_date), nên trường hợp "buổi bị dời ra sau ngày kết thúc"
  của MK không xảy ra ở LF.

Quyết định:

* **Tự kết thúc.** Lớp `active` tự chuyển sang `completed` sau 23:59 ngày `end_date`. Ngày
  tính theo `config('app.timezone')`, cùng quy ước với khoảng lịch của buổi
  (`CourseCohortSessionWindow`) và khoảng mở nội dung của khóa Kết hợp. Một lệnh định kỳ làm
  việc này qua `CourseCohortLifecycleService::complete()`, nên hệ quả giống hệt bấm tay: chỉ
  xem lại, dọn buổi theo mục trên, đóng bảng.
* **Lớp cũ không có `end_date`** không tự kết thúc; chỉ kết thúc khi quản trị bấm.
* **Kéo dài lớp.** Sửa `end_date` trước khi tới hạn, theo quyền sửa lớp hiện có (quản trị,
  hoặc giáo viên chính có `class.manage`).
* **Kết thúc sớm.** Nút "Hoàn thành lớp học" chỉ còn cho quản trị. Giáo viên không còn nút
  này và route từ chối giáo viên.
* **Rút ngắn lớp.** Người sửa lớp đưa `end_date` về sớm hơn; quy tắc sẵn có buộc hủy trước
  mọi buổi nằm sau ngày mới. Ở lớp `active`, `end_date` mới không được trước ngày hôm nay,
  để một lần nhập nhầm không làm lớp kết thúc ngay.
* **Báo trước.** Trang lớp của quản trị và của giáo viên hiện "Lớp tự kết thúc sau 23:59
  ngày dd/mm/yyyy" khi lớp `active`; trong 7 ngày cuối dòng này hiện ở dạng cảnh báo.
* **Không mở lại.** `completed` vẫn là trạng thái không quay về `active`.
* **Lần chạy đầu.** Lớp `active` đã quá `end_date` lúc bật lệnh sẽ kết thúc ở lần quét đầu.
  DB dev ngày 2026-11-19 không có lớp nào như vậy (3 lớp `active`, đều còn hạn).

Triển khai hai mục trên ngày 2026-11-19, không migration:

* `LiveClassSessionLifecycleService::cohortCompletionPlan()` xếp buổi theo bảng của mục "Dọn
  buổi khi hoàn thành lớp"; `settleForCohortCompletion()` áp dụng qua `transition()` sẵn có
  (đóng bảng, tính lại tiến độ khi hủy). Buổi tự học đã qua giờ mà chưa đóng thì kết thúc ở
  giờ kết thúc theo lịch, như lệnh `liveclass:complete-overdue` lẽ ra đã làm.
* `CourseCohortLifecycleService::transition()` gọi phần dọn buổi trong cùng transaction,
  trước khi lớp rời `active`. `completionWarnings()` lấy số buổi từ cùng kế hoạch: "N buổi
  chưa diễn ra sẽ bị hủy", "N buổi đang diễn ra sẽ kết thúc ngay".
* Lệnh `course:complete-ended-cohorts` (mỗi phút, `routes/console.php`) gọi
  `CourseCohortLifecycleService::completeEnded()`: lớp `active` có `end_date` trước hôm nay
  theo `config('app.timezone')`.
* Route `course-cohorts.complete` chuyển từ `routes/modules/course-cohort-manage.php` sang
  khu quản trị (`routes/modules/course.php`); `CourseCohortController::complete()` dùng
  `authorizeAdmin()`. Màn của giáo viên không còn nút.
* `CourseCohortController::validatedUpdateData()`: lớp `active` đổi `end_date` thì ngày mới
  không trước hôm nay (`LF_course_cohort_validation_end_date_past`).
* Trang lớp (quản trị và giáo viên) hiện `LF_course_cohort_auto_end_notice` khi lớp `active`
  có `end_date`; dạng cảnh báo khi còn từ 7 ngày trở xuống (`data-cohort-auto-end`).
* Khi tự kết thúc theo ngày, mọi buổi đều đã tới giờ bắt đầu (buổi nằm trong thời gian vận
  hành của lớp), nên thường không có buổi nào bị hủy.
* Test: `LiveClassAttendanceCheckInTest::test_completing_a_class_cancels_sessions_not_held_and_ends_running_ones`,
  `LiveClassAttendanceCheckInTest::test_a_class_ends_by_itself_after_its_end_date`,
  `CourseCohortManagementTest::test_a_running_class_shows_when_it_ends_and_never_moves_that_date_into_the_past`,
  `CourseCohortManagementTest::test_a_primary_teacher_activates_their_class_but_never_completes_or_archives_it`.

## Implementation record

Triển khai ngày 2026-11-18, không migration:

* `CourseClassReview::only()`: Enrollment của khóa Theo lớp có chỗ đang hoạt động trong lớp
  `completed`.
* `CourseProgressRecorder`: `record()` trả dòng tiến độ hiện có mà không ghi; `openLesson()`
  và `quizChanged()` không ghi gì khi chỉ xem lại.
* `LearnerCourseQuizService::start()`: không mở lần làm mới; lần đang mở vẫn tiếp tục được.
* `LearnerMyLearningService::course()` trả `review_only`; trang khóa học, bài học và lớp của
  học viên hiện thông báo; thẻ Quiz báo lớp đã kết thúc thay cho nút bắt đầu.
* Test: `LearnerMyLearningTest::test_a_class_that_has_ended_is_review_only`,
  `CourseQuizActivityTest::test_a_class_that_has_ended_starts_no_new_attempt_and_adds_no_progress`.

# Approval

Approved by the Business/Architecture Owner on 2026-11-01, gồm cả các diễn giải
đã báo cáo ở giai đoạn tài liệu (tên mã ngắn, không lưu bản sao loại trên Product,
điều kiện khóa đổi loại, quy tắc nhân bản, backfill suy ra từ Activity).
