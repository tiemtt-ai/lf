# LF-Navigation.md

Version: 1.9

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-11-19

Document Path: business/LF-Navigation.md

---

# LearnForge Navigation Architecture

> Bulk Enrollment amendment (2026-07): the Admin Enrollment index exposes one
> primary “Create enrollment” action. Its two-step wizard selects one or many
> Students and one or many Products, then configures and confirms their
> Cartesian Student–Product pairs. Cohort assignment remains a
> separate contextual workflow.

> Course Cohort Schedule amendment (2026-08-05): Admin navigation exposes one
> `Lớp học` / `Cohorts` entry. Cohort detail uses the canonical tab order:
> `Tổng quan` / `Overview`, `Học viên` / `Students`, `Giáo viên` / `Teachers`,
> `Lịch học` / `Schedules`, `Buổi học` / `Sessions`, `Điểm danh` /
> `Attendance`, `Bản ghi/Xem lại` / `Recordings/Replay`. Schedules and Sessions
> have separate LiveClass sources of truth. The Sessions tab may combine
> concrete Sessions with non-persisted planned occurrences, but must label them
> distinctly and may create a Session only after explicit selection and atomic
> confirmation. Immutable Origin, not timestamp coincidence, supplies the
> Schedule relationship. Cohort Attendance and Recordings/Replay aggregate
> records from the Cohort's concrete Sessions only.

> Attendance Policy amendment (2026-11-17, Owner; ADR-0002 § Attendance Policy
> And Recording Amendment): with `liveclass.attendance_enabled` on, the Admin
> entry `Lớp học` / `Cohorts` is a group with two children, in this order:
> `Danh sách lớp học` / `Class list` (`admin.course-cohorts.index`) and
> `Quy tắc điểm danh` / `Attendance policies` (`admin.attendance-policies.index`,
> `/admin/attendance-policies`). With the switch off the policies have no effect,
> so `Lớp học` stays the single entry it was. The Cohort list carries no button
> to the policies page; the Cohort edit popup keeps a link under its policy
> field. Only `customer_admin` reaches the page.
> Admin management group (Owner 2026-11-17): the last entry of the Admin sidebar
> is the group `Quản trị` / `Administration` with the children `Người dùng` /
> `Users` (`admin.users.index`) and `Tổ chức` / `Organization`
> (`admin.organization.edit`). Neither is a row of the account dropdown any more;
> the dropdown keeps `Tài khoản của tôi` and `Đăng xuất`. This replaces the
> earlier placement of both in the dropdown. `Phân quyền` / `Permissions`
> (`admin.teacher-permissions.index`, `/admin/teacher-permissions`) is its second
> child (ADR-0033, 2026-11-17). A teacher's menu shows only what their permission
> group allows: without `assessment.authoring` the `Kiểm tra` children Câu hỏi,
> Ngữ liệu, Tiêu chí chấm, Đề and Ghi danh are hidden; `Kết quả` stays for
> `assessment.authoring` or `assessment.grading`; with neither, the whole group
> is hidden. Without `content.template.create` the Template list has no create
> button. With `content.category.manage` the teacher's `Danh mục khoá học` page
> carries the same add, edit, switch and delete controls as the admin's.
> Teacher sidebar (Owner 2026-11-17): `Lớp của tôi` and `Buổi học trực tiếp` are
> the two children of one group `Lớp học` / `Classes`, whether attendance is on
> or off. The teacher has no policies page: the attendance page of a class shows
> the policy in use and opens the whole policy, read-only, from `Xem đầy đủ`.
> Teacher: each card of `Lớp của tôi` gains `Điểm danh`
> (`teacher.cohorts.attendance`, `/teacher/cohorts/{cohort}/attendance`), and each
> row of `Buổi học trực tiếp` gains `Sửa` for the Session's own teachers
> (`teacher.live-sessions.update`). Learner: the Session card of the learner pages
> appears from the policy's opening time and posts to
> `student.live-sessions.meeting` ("Vào phòng họp") and
> `student.live-sessions.attend` (the "Điểm danh" button). No sidebar entry is
> added for any role, and all of it is absent while
> `liveclass.attendance_enabled` is off.

> Class self-management (ADR-0033 D7, Owner 2026-11-17, implemented): with
> `class.manage`, `Lớp của tôi` carries a `Tạo lớp` button
> (`teacher.course-cohorts.create`), and each class where the teacher is the active
> primary teacher carries `Quản lý lớp` (`teacher.course-cohorts.show`), the admin class
> pages under the teacher prefix, activation and completion included, without the
> admin-only controls (Ghi danh, giáo viên của lớp, quy tắc điểm danh, lưu trữ, bản ghi). With `class.delete` a draft card also
> carries `Xoá lớp`. Their back link and the highlighted menu entry are `Lớp của tôi`.

> Teacher Judgment amendment (2026-10-11, Owner): the Teacher sidebar adds
> `Lớp của tôi` / `My classes` (`teacher.cohorts.index`), listing only cohorts
> with the teacher's active assignment. Each opens `Chấm năng lực`
> (`teacher.cohorts.show`) for the competencies of the Framework Version the
> cohort's Course Version mapped. See
> [Course Scope Amendment](../quality/LF-Teacher-Judgment-Course-Scope-Amendment.md).

## Overview

Tài liệu này mô tả toàn bộ cấu trúc điều hướng (Navigation) của LearnForge.

Mục tiêu:

* Chuẩn hóa UI Navigation
* Chuẩn hóa User Flow
* Hỗ trợ Product Design
* Hỗ trợ AI Development
* Hỗ trợ Documentation

---

# Navigation Philosophy

LearnForge được xây dựng theo nguyên tắc:

```text
Role Based Navigation
```

---

Mỗi vai trò sẽ nhìn thấy:

* menu khác nhau
* dashboard khác nhau
* chức năng khác nhau

---

# Core Roles

```text
customer_admin

teacher

student
```

---

Future:

```text
super_admin
```

---

# Navigation Layers

LearnForge có 4 tầng điều hướng.

```text
Public

↓

Authentication

↓

Role Experience

↓

Feature Modules
```

---

# Layer 1

Public Navigation

---

# Purpose

Cho khách chưa đăng nhập.

---

# Public Pages

```text
/

/courses

/assessments

/services

/teachers

/about

/contact

/login

/register-customer
```

---

# Public Menu

```text
Home

Courses

Assessments

Services

Teachers

About

Contact

Login
```

---

# Public Flow

```text
Visitor

↓

Home

↓

Login

↓

Personalized Tenant Website
```

---

# Layer 2

Authentication

---

# Pages

```text
/login

/forgot-password

/reset-password

/verify-email
```

---

# Authentication Flow

```text
Login

↓

Resolve Tenant

↓

Authenticate

↓

Role Redirect
```

---

# Role Redirect

```text
customer_admin

↓

/admin
```

---

```text
teacher

↓

/teacher
```

---

```text
student

↓

/my-courses
```

Một học viên mở link điểm danh QR trước khi đăng nhập thì quay lại trang điểm
danh đó; còn lại đăng nhập xong vào "Khóa học của tôi" (Owner 2026-11-10).

---

# Layer 3

Customer Admin Portal

---

# Entry Point

```text
/admin
```

---

# Admin Dashboard

```text
/admin
```

---

# Main Menu

```text
Dashboard

Users

Courses

Assessments

Live Classes

Media

Reports

AI

Settings
```

---

# Users Module

```text
/admin/users
```

---

# Sub Pages

```text
User List

Create User

Edit User

User Detail
```

---

# Course Module

```text
/admin/courses
```

---

# Sub Pages

```text
Course Template List

Create Course Template

Edit Course Template

Course Template Detail

Course Product Management
```

Course Template Edit giữ một compact authoring tree: Section là grouping
container, Lesson là primary authoring unit và Activity là row trực tiếp dưới
Lesson. Chỉ Course Template hiển thị Status trong tree. Activity title là text
thuần; actions theo thứ tự View, Edit, Delete. View mở readonly detail hoặc
signed/external target và không bao giờ là Edit. Readonly Activity detail giữ
tenant/Template authorization và cung cấp Back cùng Edit riêng cho user được phép.

---

# Assessment Module

```text
/admin/assessments
```

---

# Sub Pages

```text
Question Banks

Questions

Topics

Quizzes

Attempts

Gradings
```

---

# Live Class Module

```text
/admin/liveclasses
```

---

# Sub Pages

```text
Rooms

Sessions

Attendance

Replay
```

---

# Media Module

```text
/admin/media
```

---

# Sub Pages

```text
Files

Videos

Documents

Audios

Transcripts
```

---

# Reports Module

```text
/admin/reports
```

---

# Examples

```text
Learning Reports

Attendance Reports

Assessment Reports

Usage Reports
```

---

# AI Module

```text
/admin/ai
```

---

# Examples

```text
Knowledge Base

AI Usage

AI Analytics

AI Settings
```

---

# Settings Module

```text
/admin/settings
```

---

# Examples

```text
Tenant Settings

Branding

Users & Roles

Billing

Subscription
```

---

# Layer 4

Teacher Portal

---

# Entry Point

```text
/teacher
```

---

# Teacher Dashboard

```text
/teacher
```

---

# Main Menu

```text
Dashboard

My Courses

Assessments

Live Classes

Students

Reports

AI Assistant
```

---

# My Courses

```text
/teacher/courses
```

---

# Features

```text
Course Management

Lesson Management

Enrollments
```

---

# Assessments

```text
/teacher/assessments
```

---

# Features

```text
Question Bank

Quiz Builder

Grading
```

---

# Live Classes

```text
/teacher/liveclasses
```

---

# Features

```text
Manage Sessions

Attendance

Replay
```

---

# Students

```text
/teacher/students
```

---

# Features

```text
Student List

Progress

Scores

Attendance
```

---

# Reports

```text
/teacher/reports
```

---

# Examples

```text
Course Analytics

Assessment Analytics

Attendance Analytics
```

---

# AI Assistant

```text
/teacher/ai
```

---

# Features

```text
Generate Quiz

Generate Rubric

Generate Feedback

Analyze Students
```

---

# Layer 5

Student Experience

Student Experience là Personalized Tenant Website sau khi login.

Student không chuyển sang một portal riêng.

---

# Entry Point

```text
/my-courses
```

---

# Tenant Website After Login

```text
/
```

---

# Main Menu

Header, menu và footer của Tenant Website theo giao diện B2B chuẩn
(`docs/ui-demo/learner-my-learning-design`, Owner duyệt 2026-11-10). Mục nào LF
chưa có trang thì hiện nhưng khóa, bấm vào chỉ báo "Sắp ra mắt".

```text
Thanh menu    Home · Khóa học (/courses?type=self_paced) · Lớp học (/courses?type=class) ·
              Bài thi (Assessments) · Services · Giới thiệu ▾ (About, Teachers, Contact)
              Khóa học và Lớp học là hai cách lọc của Courses, không thêm route (Owner 2026-11-11).
Tài khoản ▾   Khóa học của tôi · Lớp học của tôi · Bài thi của tôi ·
              Kết quả học tập · Learning History · AI Tutor ·
              Thông tin cá nhân (Profile) · Đăng xuất
Ngôn ngữ ▾    Tiếng Việt · English
Khách         thanh menu như trên + Đăng nhập
Footer        thông tin đơn vị, liên hệ, Hỗ trợ (Hỏi đáp, Quy chế, Chính sách: khóa)
Nút nổi       AI Speak (khóa), lên đầu trang
```

Trong "Khóa học của tôi": Đăng ký lại khóa hết hạn, Tư vấn 1:1 của lớp, tab Quan tâm và
nút ♡ của Lớp học / Bài thi đều khóa. Ô và mục menu "Kết quả học tập" mở
`/my-courses?tab=results` từ 2026-11-11 (ADR-0031). Quan tâm của Khóa học dùng
được từ 2026-11-11 (ADR-0030 D4). "Lọc" tìm theo tên và danh mục
trong nhóm đang xem; số đếm ở tab không đổi theo bộ lọc.

---

# My Courses

```text
/my-courses
```

---

# Features

```text
Enrolled Courses

In Progress Courses

Completed Courses

Favorite Courses
```

## My Courses Read Pages — 2026-11-10

Đợt 1 của giao diện học viên (tham khảo bố cục B2B của mk_v2, dữ liệu và cách
hiển thị theo LF). Chỉ đọc; không ghi tiến độ, không mở nội dung bài học.

```text
/my-courses?tab=courses   Khóa học của tôi: Enrollment self_paced
                          Đang học / Đã hoàn thành / Hết hạn
/my-courses?tab=classes   Lớp học của tôi: Cohort seat đang active
                          Đang học / Đã kết thúc (Cohort completed hoặc archived)
/my-courses?tab=exams     Bài thi của tôi: Quiz registration
                          Còn hiệu lực / Hết lượt hoặc đã hủy
/my-courses/{enrollment}          chi tiết khóa: mục lục Version, trạng thái mở
                                  của từng Lesson theo VersionLessonAccessService
/my-courses/classes/{cohort}      chi tiết lớp: lịch Session, điểm danh của học viên
```

Quy tắc:

* Chỉ Enrollment và Cohort seat của chính học viên trong tenant hiện tại; còn lại 404.
* Enrollment `cancelled` không hiện; Cohort `draft` và seat đã rời lớp không hiện. Lớp của
  Enrollment `cancelled` cũng không hiện ở "Đã kết thúc" hay Kết quả học tập: học viên không xem
  lại lịch và chuyên cần của lớp đó (Owner xác nhận 2026-11-19).
* Chuyển lớp dời chính seat sang lớp mới (ADR-0034), nên lớp cũ không còn ở Lớp học của học
  viên, kể cả "Đã kết thúc": học viên không xem lại lịch và kết quả điểm danh ở lớp cũ. Lịch sử
  chuyển lớp chỉ quản trị và giáo viên xem (Owner giữ như hiện tại 2026-11-19).
* Enrollment khóa theo lớp (`scheduled`, `blended`) còn `pending`/`active`/`suspended` mà
  chưa có seat active ở Cohort khác `draft` hiện ở Lớp học → Đang học với nhãn
  "Đang chờ xếp lớp", không có liên kết (Owner 2026-11-11).
* Nhóm khóa: `completed`, hoặc Course Progress `completed` khi Enrollment còn `active`
  (ADR-0030 Q2) → Đã hoàn thành; `expired` hoặc đã qua hạn học và ôn tập → Hết hạn;
  còn lại → Đang học.
* Lớp có Enrollment `expired` hoặc đã qua hạn học và ôn tập (2026-11-19, chỉ hiển thị): thẻ ở
  Lớp học có nhãn "Hết hạn học", không có nút "Vào lớp học" và không ghi buổi tới; trang lớp
  báo "Ghi danh của bạn đã hết hạn học từ {ngày}…", các buổi sắp tới không đếm ngược và không
  có nút. Học viên vẫn xem lại lịch và kết quả. Enrollment `suspended` hiện cùng cách với nhãn
  "Tạm dừng học" và dòng báo "Ghi danh của bạn đang tạm dừng… liên hệ trung tâm để học tiếp".
* Chuyên cần = Session có mặt hoặc đi muộn / Session đã diễn ra (bỏ `cancelled`,
  `no_show`, `draft`). Giờ Session đọc theo `timezone` của chính dòng đó. Từ 2026-11-18
  "đã diễn ra" là Session `completed`; buổi `live` và buổi `scheduled` đã qua giờ chưa tính
  (ADR-0031 Amendment 1.1).
* "Đề thi của tôi" (`/assessments/my`) vẫn giữ lịch sử từng lượt làm.
* Đợt 2 (ADR-0030): `/my-courses/{enrollment}/lessons/{lesson}` là trang bài học
  (Activity theo thứ tự lớp, phát video / âm thanh / video nhúng, mở tài liệu ở tab mới,
  bài trước / bài tiếp); chi tiết khóa có nút "Học tiếp" và liên kết tới bài đang mở.
  Ghi danh của lớp (2026-11-19): không có trang ẩn hay luồng ẩn. Khóa của lớp chỉ học ở
  trang lớp (tab Lớp học): `/my-courses/{enrollment}` chuyển tới `/my-courses/classes/{cohort}`,
  `/my-courses/{enrollment}/lessons/{lesson}` chuyển tới buổi của bài trên trang lớp
  (`#live-lesson-{session}`: buổi gần nhất đã bắt đầu, chưa có thì buổi đầu); chưa có lớp mở
  được thì về `/my-courses?tab=classes`. Nút "Vào học" của buổi tự học, trang làm Quiz và
  trang kết quả Quiz ("Quay lại lớp học") cũng dẫn về buổi đó; Quiz "Trước buổi học" về buổi
  đầu của bài, "Sau buổi học" về buổi cuối. Trang kết quả chỉ có "Làm lại" khi thẻ Quiz trên
  trang lớp cũng có (còn lượt và lần được tính "Chưa đạt", lớp chưa kết thúc). Nội dung đánh dấu hoàn thành
  thủ công có nút "Đánh dấu hoàn thành" ngay trên bảng nội dung của buổi.
* "Quan tâm" (ADR-0030 D4, 2026-11-11): `/my-courses?tab=courses&view=favorite` hiện thẻ
  catalogue của Product đã đánh dấu (đang trên catalogue hoặc đang ghi danh), mới đánh dấu
  trước; nút ♡ ở thẻ `/courses`, trang chủ, trang chi tiết và thẻ khóa của tôi. Không tạo
  Enrollment.
* Chi tiết khóa theo bố cục "learning course" của MK (2026-11-19): thẻ tổng quan (thời lượng,
  bài hoàn thành kèm tỷ lệ, bài đã mở khi còn bài khóa, hạn truy cập và số ngày còn lại, nút
  Học tiếp), rồi ba tab Danh sách bài học / Giới thiệu / Giảng viên. Mỗi bài là một thẻ: ô
  "BÀI 01", tên bài, nhãn Chưa xem / Đang xem / Hoàn thành (hoặc lý do khóa), thời lượng và thanh
  "Đã xem video" (bài chỉ có audio: "Đã nghe audio"; bài không có video/audio: "Đã học" theo tiến
  độ của bài). Thẻ hoàn thành viền trái xanh lá, đang xem viền trái xanh dương. Bài có Quiz có
  dòng "Kiểm tra: chưa làm / N lần" và nút "Bài kiểm tra" tới Quiz trên trang bài học.
* Tiến độ trang chi tiết khóa (theo MK, 2026-11-19): % của từng bài là phần đã phát của video
  hoặc audio bắt buộc trong bài (trung bình nếu nhiều, lấy tất cả nếu không có bài bắt buộc),
  làm tròn xuống; nhãn Hoàn thành vẫn theo quy tắc hoàn thành bài của LF (mọi nội dung bắt
  buộc, kể cả Quiz). Ô "Bài học hoàn thành" đếm bài hoàn thành, thanh "Tỷ lệ xem video" là
  trung bình % của các bài có video/audio (bài chưa xem tính 0); khóa không có video/audio
  dùng "Tỷ lệ hoàn thành". Khác MK: tiến độ thuộc riêng từng Enrollment (MK dùng chung một bản
  ghi theo video cho mọi khóa), nên cùng video ở khóa khác hoặc lần mua lại không cộng sang
  nhau; số giây tính theo vị trí xa nhất đã phát có giới hạn tốc độ, xem lại đoạn cũ không
  cộng thêm.
* "Vào học" mở popup theo popup video MK: đầu popup ô "BÀI 03" và tên bài, nút đóng tròn; giữa là
  khung đen phát video (không có video thì audio); video/audio đã dừng giữa chừng thì hỏi "Tiếp
  tục xem từ vị trí đã lưu?" với nút Xem từ đầu / Tiếp tục xem (gần cuối thì phát lại từ đầu),
  chưa xem thì tự phát; chuyển bài khi đang phát thì vị trí dừng được ghi trước; chân popup
  có lần mở gần nhất, "Tiến độ: hh:mm:ss ~ %" cập nhật khi đang phát, "Xem từ đầu", toàn màn
  hình, bài trước / bài sau. Nội dung khác của bài (tài liệu, liên kết, audio phụ) nằm dưới
  khung; Quiz có nút riêng ở thẻ bài. Trình phát, sự kiện tiến độ và điều kiện mở lấy từ trang
  bài học; đóng popup thì số liệu và thẻ bài tự cập nhật; không có JavaScript thì liên kết mở
  trang bài học. Ô tìm kiếm lọc theo tên bài ngay trên trang.
* Chi tiết lớp theo bố cục MK Live (2026-11-19): đầu trang có tiến độ hiện tại (buổi đã
  điểm danh / tổng buổi) và tiến độ khuyến nghị theo ngày, thẻ buổi sắp tới / đang diễn ra,
  tóm tắt tỷ lệ có mặt và tỷ lệ hoàn thành nội dung bắt buộc. "Lịch học theo tuần" liệt kê
  mọi Session; mỗi buổi có kết quả điểm danh, đếm ngược đến giờ vào học và bảng nội dung
  Trước / Trong / Sau buổi học (nội dung `anytime` nằm ở Trong). Nội dung mở ngay trên trang
  (âm thanh, video, video nhúng, tài liệu, liên kết, Quiz) theo VersionActivityAccessService;
  buổi đang diễn ra có nút vào lớp, bảng và phòng họp như khối "Buổi học đang diễn ra".
  Bài học dạy qua nhiều buổi: phần Trước chỉ hiện ở buổi đầu, phần Sau chỉ ở buổi cuối, phần
  Trong ở mọi buổi. Buổi đã hủy vẫn ở trong lịch với lý do hủy, không có bảng nội dung.
  Popup "Video tải lên" như MK Live: video đã xem dở hỏi "Tiếp tục xem / Xem từ đầu" (đã
  xem gần hết thì phát từ đầu); chân popup có lần mở gần nhất, "Tiến độ xem: hh:mm:ss ~ %",
  nút "Xem từ đầu" và toàn màn hình (cả video lẫn chân popup). Thời lượng và % là phần đã
  xem xa nhất của ghi danh này, làm tròn xuống, giống số % trên thẻ nội dung. Popup audio
  giữ dạng MK (tên bài, nút đóng, thanh audio); khác MK, audio phát tiếp từ chỗ đã dừng.
  Thẻ video và audio đổi ngay khi đang phát như MK: % tăng dần, đạt điều kiện hoàn thành
  thì thành "Đã xem" / "Đã nghe" và "✓ Đã hoàn thành", không cần tải lại trang.
* "Kết quả học tập" (ADR-0031, 2026-11-11): `/my-courses?tab=results&view=courses|classes|exams`,
  mỗi nhóm một thẻ tổng hợp và một bảng; cùng phạm vi và cách tính chuyên cần như trên. Điểm
  bài thi chỉ dùng lần làm học viên được xem điểm (ADR-0025 D12), còn lại "Chưa công bố".

---

# Course Detail

```text
/courses/{id}
```

> 2026-11-11: `/courses`, `/courses/{slug}` và mục khóa học ở trang chủ tenant đọc dữ liệu
> thật (`TenantCourseCatalogService`), thay dữ liệu giả. Chỉ Product `active`, `public`,
> trong `available_from`/`available_until`, gắn một Version `published`; nội dung, mục lục,
> giáo viên lấy từ Version; giá bán theo `CourseProductV2::sellingPrice` khi khuyến mại đang
> chạy. Học viên đã có Enrollment (khác `cancelled`) thấy "Vào học" tới Khóa học của tôi
> hoặc lớp. Chưa có luồng tự đăng ký/mua: nút "Đăng ký" hiện "Sắp ra mắt" khi còn trong
> thời gian đăng ký; học viên bấm ♡ để đánh dấu "Quan tâm" (ADR-0030 D4).
>
> 2026-11-11 (giao diện B2B chuẩn của mk_v2, Owner yêu cầu): `/courses` và `/assessments` có
> banner kèm ô tìm theo tên, hàng chip lọc và lưới thẻ; `/courses` lọc bằng `q`, `category`,
> `type=self_paced|class`, 12 thẻ mỗi trang. Thẻ khóa học theo Enrollment của học viên: đang
> học → tiến độ và "Vào học"; `pending`/`suspended` → nhãn trạng thái, không có nút; `expired`
> hoặc đã qua hạn học và ôn tập → "Đã hết hạn" và "Đăng ký" (Sắp ra mắt); chưa ghi danh → học
> phí và "Đăng ký" (Sắp ra mắt). `/courses/{slug}` có ảnh, 4 ô thông tin (Giáo viên, Hình thức,
> Thời hạn học, Thời lượng), trạng thái kèm nút, và 3 tab Danh sách bài học / Giới thiệu / Giáo
> viên. Cách ghi danh không đổi; trang chỉ đọc.
>
> Trang chủ tenant `/` tạm thời theo trang chủ B2B chuẩn (Owner 2026-11-11): banner, Khóa học
> đề xuất (4 khóa tự học), Lớp học đề xuất (4 khóa theo lớp), AI Speak (khóa), "Vì sao chọn
> <tenant>?". Khối số liệu cá nhân, dịch vụ, tin tức và liên hệ của bản cũ đã bỏ khỏi trang.

---

# Course Not Enrolled

```text
View Detail

Register

Purchase
```

---

# Course Enrolled

```text
Continue Learning

Progress

Assessments

Certificate
```

---

# Assessments

```text
/assessments
```

---

# Features

```text
Take Quiz

View Results

Review Attempts
```

---

# Live Classes

```text
/liveclasses
```

---

# Features

```text
Upcoming Sessions

Join Session

Replay
```

---

# AI Tutor

```text
/ai-tutor
```

---

# Features

```text
Ask Questions

Lesson Support

Practice Support

Recommendations
```

---

# Profile

```text
/profile
```

---

# Features

```text
Personal Info

Learning Statistics

Preferences
```

---

# Student Homepage

Trang chủ sau login vẫn giữ:

```text
Banner

Featured Courses

Featured Services

Teachers

News
```

và hiển thị thêm nội dung cá nhân hóa:

```text
Continue Learning

My Courses

Upcoming Activities

Pending Assessments

AI Recommendations
```

---

# Course Navigation Flow

```text
Course List

↓

Course Product Detail

↓

Template Lesson

↓

Assessment

↓

Completion
```

---

# Assessment Navigation Flow

```text
Quiz List

↓

Quiz Detail

↓

Attempt

↓

Submission

↓

Result
```

---

# Live Class Navigation Flow

```text
Live Class List

↓

Session Detail

↓

Join Session

↓

Replay
```

---

# AI Navigation Flow

```text
Course Product + Enrollment

↓

Template Lesson

↓

AI Tutor

↓

Context Aware Answer
```

---

# Dashboard Widgets

## Admin

```text
Users

Courses

Revenue

Usage

AI Usage
```

---

## Teacher

```text
Students

Attendance

Assessments

Course Progress
```

---

## Student

```text
Learning Progress

Upcoming Classes

Pending Assessments

AI Recommendations
```

---

# Mobile Navigation

Version 1

---

# Strategy

```text
Responsive First
```

---

# Mobile Menu

```text
Dashboard

Courses

Assessments

Live Classes

Profile
```

---

# Tenant Branding

Navigation phải hỗ trợ:

```text
Logo

Theme

Language
```

---

# Future Navigation

## Super Admin

```text
/saas
```

---

# Features

```text
Customers

Subscriptions

Usage

Billing

Infrastructure
```

---

# Future Modules

```text
Certificates (*)

Learning Paths

Communities

Mentoring

Gamification
```

(*) Certificate database/architecture foundation is "Foundation Approved and
Frozen" per ADR-0011 and LF-Domain-Map.md — the schema exists and is ready.
"Future" here refers only to navigation/UI: no route, controller or view for
Certificate exists yet (verified against `routes/` and
`app/Http/Controllers/`).

---

# Design Rules

## Rule 1

Navigation phải Role-Based.

---

## Rule 2

Không hiển thị menu không có quyền.

---

## Rule 3

Navigation phải Tenant-Aware.

---

## Rule 4

AI phải xuất hiện trong mọi Role Experience.

---

## Rule 5

Admin và Teacher bắt đầu tại Dashboard.

Student bắt đầu tại Tenant Website.

---

# Current Scope

Version 1

```text
Public Website

Authentication

Admin Portal

Teacher Portal

Student Experience
```

---

# Planned Scope

```text
Super Admin

Certificates (*)

Learning Paths

Communities

Gamification
```

(*) See note under Future Modules — schema Frozen (ADR-0011), UI/navigation
not yet implemented.

---

# Relationship With Other Documents

```text
LF-Core-User

↓

LF-Core-Course

↓

LF-Core-Assessment

↓

LF-Core-LiveClass

↓

LF-Navigation
```

Navigation là lớp hiển thị của toàn bộ nghiệp vụ LearnForge.

---

# Final Statement

LF-Navigation định nghĩa cách người dùng tương tác với LearnForge.

Nó là bản đồ sản phẩm chính thức giúp:

* Product Team
* Design Team
* Development Team
* AI Agents

hiểu được:

* màn hình nào tồn tại
* chức năng nằm ở đâu
* người dùng di chuyển như thế nào

trong toàn bộ hệ sinh thái LearnForge.

---

End of LF-Navigation
