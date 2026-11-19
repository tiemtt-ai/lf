# ADR-0035 — External Link Activity

Version: 1.0

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-19

Proposal Date: 2026-11-19

Approval Date: 2026-11-19 (nghiệp vụ và thiết kế dữ liệu)

Approved By: LearnForge Business/Architecture Owner

Document Path: adr/ADR-0035-External-Link-Activity.md

Related ADRs:

* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md): Version Activity bất biến
* [ADR-0022 — Course Type On Course Template](ADR-0022-Course-Type-On-Template.md): Amendment 1.3, tiêu chí hoàn thành và "Tính vào điểm danh" của khóa Theo lớp
* [ADR-0030 — Learner Course Delivery And Progress](ADR-0030-Learner-Course-Delivery-And-Progress.md): trang bài học, `CourseProgressRecorder`

---

# Context

Căn cứ nghiệp vụ: "Nghiệp vụ luồng học tập lớp MK" mục 1.1 (học liệu "Link học trực tuyến",
nền tảng ngoài như MasterK, Wings) và mục 3.3 ("Link nền tảng ngoài: học viên bấm mở link").
Nền tảng ngoài: học viên tự đăng nhập bằng tài khoản của nền tảng đó; hệ thống chỉ ghi nhận
việc mở link, không đồng bộ kết quả học bên ngoài.

Hiện trạng đã kiểm trong code ngày 2026-11-19:

* Loại Activity có `video`, `embedded_video`, `audio`, `document`, `quiz`, `live_class`
  (`CourseTemplateActivityController::ACTIVITY_TYPES`). Không loại nào gắn được một link bất
  kỳ cho học viên mở.
* `external_video_url` chỉ nhận URL YouTube / Vimeo đã chuẩn hóa
  (`TrustedVideoUrlService`) và được phát bằng iframe.
* `live_class_url` đã ngừng nhận giá trị mới (Amendment 2026-11-09).
* Cột `activity_type` không có CHECK giới hạn giá trị; luật nằm ở server.

# Decision

## 1. Loại mới

* `activity_type = external_link`, hiển thị "Link nền tảng ngoài". Dùng được ở cả ba loại
  khóa (Tự học, Kết hợp, Theo lớp), mọi loại bài, cùng luật thời điểm mở như `document`.
* Người soạn nhập tên, link và hướng dẫn. Hướng dẫn dùng ô `description` sẵn có (ví dụ "Đăng
  nhập bằng tài khoản Wings của bạn"). Thời lượng ước tính nhập tay như `document`; không có
  Media.
* Link: bắt buộc, tối đa 1000 ký tự, chỉ `https://`, không chứa tên đăng nhập hay mật khẩu
  trong URL. Server chuẩn hóa khoảng trắng hai đầu và từ chối mọi scheme khác.
* Đổi loại Activity khỏi `external_link` thì link về `NULL`, như `live_class_url`.

## 2. Học viên mở link và hoàn thành

* Thẻ Activity ở trang bài học có nút "Mở link"; link mở ở tab mới với
  `rel="noopener noreferrer"`. Link chỉ có trong trang khi học viên được mở Activity
  (`VersionActivityAccessService`), như các nội dung khác.
* Bấm nút gửi sự kiện `opened` qua route tiến độ sẵn có rồi mới mở tab. `completion_rule =
  view`: lần mở đầu tiên là hoàn thành. Khóa Theo lớp chỉ có `view`; khóa khác có thêm
  `manual`, như `document` (`CourseType::completionRules`).
* Hệ thống không biết học viên làm gì ở nền tảng ngoài và không lấy kết quả từ đó.
* Khóa Theo lớp: tick được "Tính vào điểm danh" như `document` (phần Trong, Sau, hoặc buổi tự
  học), nên dùng được cho điểm danh theo nội dung và học bù.

## 3. Chốt khi xuất bản

* Xuất bản Template chốt link vào Version Activity. Sửa link ở Template không đổi lớp đang
  học; cần xuất bản phiên bản mới. Lớp đang dùng phiên bản cũ giữ link cũ (ADR-0012; Owner
  chốt 2026-11-17 giữ Template bất biến).
* Kiểm tra xuất bản chặn Activity `external_link` thiếu link hoặc có link không hợp lệ.

## 4. Ngoài phạm vi

* Link không nằm trong thư viện Media, không có phạm vi chia sẻ (khác MK có chủ đích).
* Không có Klass, không có link riêng cho giáo viên.
* Không đưa link lên Bảng điện tử.
* Không có link riêng theo lớp.

# Dữ liệu (Owner duyệt 2026-11-19)

Hai cột mới, nullable, không backfill, không CHECK (theo mẫu `external_video_url`; giá trị do
server kiểm):

| Bảng | Cột mới | Kiểu | Đặt sau |
| --- | --- | --- | --- |
| `core_course_template_activities` | `external_link_url` | VARCHAR(1000) NULL | `external_video_url` |
| `core_course_template_version_activities` | `external_link_url_snapshot` | VARCHAR(1000) NULL | `external_video_url_snapshot` |

* `external_link_url` chỉ có giá trị khi `activity_type = external_link`.
* `external_link_url_snapshot` được ghi đúng một lần khi xuất bản, sao từ
  `external_link_url`; Version Activity không sửa sau đó.
* Nhân bản Template và nhân bản phiên bản sao cột này như `external_video_url`.
* `core_course_activity_progress` không đổi: `view` ánh xạ sang `viewed` như hiện nay.
* Migration xuống (down) bỏ hai cột; Activity `external_link` còn lại khi chạy down cần xử lý
  trước, nên migration down từ chối nếu còn Activity loại này.
* Kiểm migration trên MariaDB 11.4 tạm; cập nhật `LF-SCHEMA-CONTRACT.json`.

# Alternatives rejected

* **Dùng lại `external_video_url`:** tên và luật của cột là cho YouTube / Vimeo phát bằng
  iframe; trộn hai nghĩa vào một cột làm sai luật kiểm và dễ nhúng nhầm link ngoài vào iframe.
* **Đưa link vào thư viện Media như MK:** cần loại Media mới, phạm vi chia sẻ, thu hồi và
  kiểm tra khi xuất bản; không cần cho nghiệp vụ "mở link là hoàn thành".
* **Cho mỗi lớp sửa link riêng:** linh hoạt khi nền tảng đổi địa chỉ, nhưng thêm một nguồn
  nội dung theo lớp, trái quyết định giữ Template bất biến; để sau nếu thực tế cần.
* **Cột JSON chung cho mọi loại link:** mất kiểu dữ liệu và không kiểm được trong schema.

# Consequences

* Nền tảng ngoài đổi địa chỉ thì trung tâm phải xuất bản phiên bản mới; lớp đang chạy giữ
  link cũ cho đến khi chuyển phiên bản.
* "Hoàn thành" chỉ chứng minh học viên đã mở link, không chứng minh đã học ở nền tảng ngoài.
  Điểm danh theo nội dung dựa trên link ngoài vì vậy chỉ kiểm việc mở.

# Implementation record — 2026-11-19

* Migration `2026_11_19_000100_add_external_link_to_course_activities`; kiểm up, down và up lại
  trên MariaDB 11.4 tạm, down từ chối khi còn Activity `external_link`; `schema:drift
  --connection=mysql` qua; `LF-SCHEMA-CONTRACT.json` có hai cột.
* Luật link: `App\Support\ExternalLinkUrl::isValid()` (HTTPS, có host, không user / password,
  tối đa 1000 ký tự), dùng ở form và khi xuất bản.
* Form Activity (`CourseTemplateActivityController`): loại `external_link`, ô "Link" bắt buộc,
  gợi ý ghi hướng dẫn ở ô mô tả; lưu loại khác thì `external_link_url = NULL`.
  `CourseType::completionRules`: `view` (khóa Theo lớp), `view` / `manual` (khóa khác).
* Xuất bản: `CourseTemplatePublishGraphValidator` báo `activity_source` khi link thiếu hoặc sai,
  hoặc loại khác có link; `CourseTemplatePublishingService` ghi `external_link_url_snapshot`;
  `CourseTemplateVersionDuplicatingService` sao lại vào Template.
* Danh sách nội dung Template và trang xem phiên bản: mắt "Xem" mở link ở tab mới.
* Trang bài học: `LearnerMyLearningService` chỉ đưa link vào trang khi Activity được mở và link
  hợp lệ; nút "Mở link" (`data-learn-open-link`) là liên kết `target="_blank"`
  `rel="noopener noreferrer"`, bấm thì `openLink()` gửi `opened` (trình duyệt tự mở tab, nên
  không bị chặn popup). Không lên Bảng điện tử (`LiveClassWhiteboardService::MATERIAL_TYPES`).
* Test: `CourseTemplateActivityManagementTest` (hai test `external_link`),
  `CourseTemplatePublishingTest::test_an_external_link_activity_freezes_its_https_link_and_duplicates_it`,
  `LearnerMyLearningTest::test_an_external_link_opens_in_a_new_tab_and_completes_when_opened`,
  `tests/js/learner-lesson.test.mjs`, `tests/Integration/CourseExternalLinkActivityMariaDbTest.php`.

# Implementation outline (đã làm theo, giữ để tra cứu)

1. Sửa tài liệu bảng `core_course_template_activities`, `core_course_template_version_activities`
   theo mục Dữ liệu; migration; kiểm trên MariaDB 11.4 tạm; schema contract.
2. Form Activity: loại mới, ô link, luật kiểm; đổi loại thì xóa link.
3. `CourseType::completionRules`, luật thời điểm mở, "Tính vào điểm danh" như `document`.
4. Xuất bản: chốt `external_link_url_snapshot`, chặn thiếu hoặc sai link; nhân bản Template và
   phiên bản.
5. Trang bài học: nút "Mở link", sự kiện `opened`; trang xem phiên bản và danh sách nội dung
   hiện loại mới.
6. Test feature và `tests/Integration/*MariaDb*` cho hai cột mới.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
