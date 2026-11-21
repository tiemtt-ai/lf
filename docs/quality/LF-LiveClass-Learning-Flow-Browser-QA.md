# Luồng lớp học và khóa tự học — bằng chứng thử trên trình duyệt

Version: 1.0

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-11-20

Document Path: quality/LF-LiveClass-Learning-Flow-Browser-QA.md

---

## Mục đích

Ghi lại các luồng đã thử thật trên trình duyệt ngày 2026-11-19 và 2026-11-20, kết quả, lỗi đã sửa, test giữ
kết quả và quyết định của Owner. Luồng nào đã có ở đây thì **không thử lại**, trừ khi sửa vào
phần code ở mục "Khi nào phải thử lại". Thời gian review dành cho phần chưa thử (mục cuối).

## Môi trường

* Tenant hkca (customer 1), `hkca.localhost:8010`, DB dev `learnforge_db` (MariaDB 11.4).
* Hai vai cùng lúc: giáo viên (teacher1) và quản trị (visang1) trên Chrome riêng, mỗi vai một
  context; học viên (student1) trong trình duyệt của app Claude.
* Dữ liệu demo: lớp chính 27 (14 buổi, ghi danh 54 của Student 1), lớp đã kết thúc 28, ghi danh
  chờ xếp lớp 58, khóa tự học (ghi danh 59).
* Mỗi lượt có ghi DB: sao lưu trước vào `/Users/amin/LF-db-backups/`, thử xong trả lại và so
  toàn bộ dump với bản sao lưu (khớp hoàn toàn), trừ chỗ ghi rõ ở từng mục.

## Kết quả từng luồng

Cột "Test giữ" là test tự động bảo vệ kết quả; chạy lại test là đủ, không cần mở trình duyệt.

### A. Học viên — khóa tự học và trang lớp

| # | Luồng | Kết quả | Lỗi đã sửa | Test giữ |
|---|---|---|---|---|
| A1 | Thẻ bài và popup "Vào học" của khóa tự học (theo MK 537), 1280px và 390px | Đạt | Bố cục thẻ, tiến độ từng bài và cả khóa | `LearnerMyLearningTest::test_the_lesson_list_shows_each_lessons_played_share_and_quiz_like_mk` |
| A2 | Hộp "Tiếp tục xem / Xem từ đầu" của khóa tự học | Đạt | Chuyển bài trước/sau làm mất vị trí đã dừng | như A1 |
| A3 | Trang chi tiết lớp 27 so với MK Live 442 | Đạt, không sửa code | — | `test_the_class_page_shows_when_each_scheduled_activity_opens` |
| A4 | Popup video ở trang lớp | Đạt; toàn màn hình chỉ kiểm phần tử, trình duyệt app không vào toàn màn hình thật | Lỗi 500 dòng "Buổi học trực tiếp"; % thẻ và popup lệch 1 | `test_the_class_video_popup_shows_its_resume_question_and_watched_share_like_mk` |
| A5 | Popup audio ở trang lớp, thẻ cập nhật khi đang nghe | Đạt (3% → 91% → "Đã nghe") | Thẻ đứng yên đến khi tải lại | như A4 và test JS |
| A6 | Quay lại từ Quiz của lớp; trang khóa/bài học của ghi danh lớp | Đạt: về đúng buổi; `/my-courses/54…` chuyển về lớp 27 | Trang ẩn, luồng ẩn (ADR-0022 Amd 1.5, ADR-0032 Amd 1.2) | `CourseQuizActivityTest`, `test_a_class_course_follows_the_class_order_and_needs_the_class` |
| A7 | Toàn bộ luồng học viên lớp: dò 50 link từ menu, 19 tệp, 4 Quiz, lớp 28 xem lại, 375px | Đạt | Quay lại sai buổi khi Quiz Trước/Sau ở bài nhiều buổi; nút "Làm lại" sai quy tắc; tràn ngang 375px | `test_a_lesson_over_several_sessions_shows_before_on_the_first_and_after_on_the_last`, `test_a_class_that_has_ended_is_review_only` |

### B. Giáo viên

| # | Luồng | Kết quả | Lỗi đã sửa | Test giữ |
|---|---|---|---|---|
| B1 | Các tab lớp 27, Kết quả & Chấm bài, Buổi học trực tiếp, điểm danh tay, 375px | Đạt; không bấm "Nhận chấm" | Popup Lịch học trỏ về `/admin` (403); nhãn "Hoạt động / Theo lớp" bị cắt ở 700–1000px | `CourseCohortManagementTest` (popup lịch ở khu giáo viên) |
| B2 | Nút "Bắt đầu buổi học" (Chrome) | Đạt: bảng mở cửa sổ mới, đồng bộ, QR, 3 tài liệu, PDF 54 trang | — | `LiveClassWhiteboardTest::test_teacher_starts_their_session_on_its_day_and_lands_on_the_board` |

### C. Hai vai giáo viên và học viên

| # | Luồng | Kết quả | Lỗi đã sửa | Test giữ |
|---|---|---|---|---|
| C1 | Điểm danh QR | Đạt; mã cũ báo hết hạn | Bảng vẫn phát mã sau giờ kết thúc; quét lại khi chờ nội dung báo sai "đã điểm danh" | `LiveClassAttendanceCheckInTest::test_a_session_started_after_its_planned_end_offers_no_code`, `test_scanning_again_while_ticked_content_waits_says_what_is_left` |
| C2 | Nút "Điểm danh" của học viên (buổi Tại lớp) | Đạt: mở trước 15 phút; bấm hai lần vẫn 1 dòng | — | `test_the_check_in_button_records_in_room_learners_from_the_planned_start` |
| C3 | Học bù (vắng buổi 4, mở bài tập Sau buổi) | Đạt: Vắng → Có mặt nguồn học bù; lịch sử "Hệ thống"; quá 7 ngày giáo viên chỉ xem | — | `test_ticked_content_decides_attendance_and_make_up`, `test_make_up_follows_the_policy_of_the_class` |
| C4 | Giáo viên ghi và sửa điểm danh tay | Đạt: lịch sử từng lần; không đưa về "Chưa điểm danh" | — | `test_manual_attendance_keeps_a_history_and_a_result_cannot_be_taken_back` |
| C5 | Ghi danh hết hạn | Đạt sau sửa | Học viên không được báo; giáo viên chọn được rồi bị bỏ qua khi lưu | `LearnerMyLearningTest::test_a_class_whose_enrollment_ran_out_says_so_and_counts_down_to_nothing`, `LiveClassAttendanceCheckInTest::test_the_roster_locks_a_learner_whose_enrollment_is_not_active` |
| C6 | Ghi danh tạm dừng (quản trị bấm thật, giáo viên bắt đầu buổi thật) | Đạt sau sửa | Học viên không được báo; "Chờ ghi nhận" cho buổi lúc tạm dừng | `test_a_suspended_class_enrollment_says_so_and_waits_for_no_result` |
| C7 | Hủy ghi danh lúc buổi đang diễn ra | Đạt sau sửa | Trang 403 trống khi bấm nút trên trang cũ | `test_a_refused_entry_says_why_instead_of_a_bare_403` |
| C8 | Kích hoạt lại ghi danh | Đạt: thẻ, trang lớp, roster sửa được lại | — | `test_learners_without_a_result_are_marked_absent_after_the_session`, `test_only_active_learners_of_the_class_are_recorded` |
| C10 | Buổi trực tuyến: phòng họp và bảng điện tử (buổi tạm 15, Google Meet) | Đạt, xem ghi chú dưới bảng | — | `LiveClassAttendanceCheckInTest::test_join_meeting_records_the_entry_before_the_teacher_starts_and_then_opens_the_meeting`, `LiveClassWhiteboardTest::test_every_way_out_of_live_ends_the_board_and_clears_its_state` |
| C9 | Chuyển lớp (lớp đích tạm, quản trị dùng popup thật) | Đạt sau sửa: lịch sử chuyển, lớp cũ 404, roster hai lớp đúng | Ngày vào lớp giữ ngày lớp cũ nên buổi trước đó của lớp mới bị tự ghi vắng | `CourseCohortStudentManagementTest::test_a_transfer_that_keeps_the_old_join_date_joins_the_new_class_now` |
| C11 | Buổi Tại lớp + từ xa có mã QR (20/11/2026, buổi tạm cho Bài 9, ba vai trên Chrome riêng) | Đạt: quản trị xếp buổi bằng popup Sửa bài học; "Bắt đầu được từ 09:50" rồi bắt đầu; bảng chiếu PDF 16 trang; QR 0/3 → quét → "Có mặt" 09:51 → bảng 1/3 kèm tên; học viên có Tôi ở lớp / Tôi học từ xa, bảng học viên theo đúng trang; "Kết thúc được từ 10:05" rồi kết thúc; chuyên cần 60% → 64%. Chưa bấm Vào phòng họp | — | `LiveClassAttendanceCheckInTest`, `LiveClassWhiteboardTest` |

Sau khi sửa C9, thử lại hai vai có lưu thật (lớp đích tạm 29: buổi 15/11 đã học, buổi 21/11 sắp
tới; quản trị chọn lớp trong popup, ghi lý do, xác nhận):

* Popup: chọn lớp 29 thì ngày đổi sang 19/11/2026 23:43; chọn lại lớp cũ hay Hủy thì về
  05/10/2026 09:00. Lưu báo "Đã cập nhật phân lớp Cohort."; lớp 29 có "Lịch sử (1)" ghi "Chuyển
  lớp, ngày vào lớp mới: 19/11/2026 23:43".
* Học viên: buổi 15/11 ghi "Trước ngày bạn vào lớp", không có "Chờ ghi nhận"; buổi 21/11 đếm
  ngược; lớp cũ 27 báo 404.
* Giáo viên: buổi 15/11 ghi "Trước ngày vào lớp: không điểm danh", không có ô chọn; lớp cũ không
  còn Student 1.
* `liveclass:mark-absent` không ghi vắng Student 1 ở lớp mới.

C10, buổi trực tuyến (giáo viên Chrome, học viên trong app):

* Trước khi giáo viên bắt đầu, học viên đã có "Vào phòng họp"; vào phòng họp được ghi ngay "Có mặt ·
  Trực tuyến · 23:48" (nội dung "Tính điểm danh" đã xong từ trước), máy chủ chuyển sang link Meet.
* Giáo viên bấm "Bắt đầu buổi học": bảng mở cửa sổ mới, "Đã kết nối đồng bộ", "Bạn đang trình
  bày", có "Vào phòng họp", không có mã QR (đúng cho buổi trực tuyến), 2 tài liệu.
* Học viên tải lại trang lớp: "Đang diễn ra", nút "Xem bảng" và "Vào phòng họp". Bảng học viên
  "Đang theo dõi Teacher 1"; nét vẽ của giáo viên hiện ngay; giáo viên mở video thì học viên phát
  cùng vị trí, có tiếng ("Từ xa"); giáo viên dừng thì học viên dừng theo (lệch dưới 1 giây). Bút
  của học viên chỉ vẽ trên máy mình.
* Trang điểm danh giáo viên: "Có mặt · Trực tuyến · Vào lớp trực tuyến lúc 23:48".
* Giáo viên "Kết thúc buổi": bảng hai bên báo "Buổi học không còn diễn ra. Bảng đã đóng."; trang
  lớp học viên "Đã kết thúc · ✓ Có mặt", thống kê cập nhật (Có mặt 4 → 5).
* Giới hạn khi thử: nút "Vào phòng họp" là form POST mở tab mới; trình duyệt app chặn tab mới và
  biến nó thành GET (405). Phía học viên đã gửi đúng POST đó từ trang, không mở Google Meet. Trình
  duyệt thật mở tab mới bình thường.

## Quyết định của Owner (không mở lại)

* Trang điểm danh giáo viên chỉ liệt kê buổi đã đến giờ bắt đầu, dù học viên bấm "Điểm danh"
  được từ 15 phút trước (giữ như hiện tại).
* Học viên bị hủy ghi danh không thấy lại lớp, lịch và chuyên cần (LF-Navigation).
* Kích hoạt lại: buổi diễn ra lúc tạm dừng vẫn bị tự ghi vắng; giáo viên sửa tay nếu cần
  (LF-Core-LiveClass, mục Tự ghi vắng). Muốn khác thì phải lưu khoảng tạm dừng: cần ADR và
  Database docs.
* Sau chuyển lớp, học viên không thấy lớp cũ (LF-Navigation).
* "Có phép" kèm "Tại lớp" vẫn lưu được (không cấm trong tài liệu).
* Không thêm AI Speak.

## Điều đã biết, không phải lỗi

* Ô thống kê của học viên chỉ tính buổi `completed`. Ở local, `liveclass:complete-overdue`
  không chạy, nên buổi quá giờ vẫn ở "Đã lên lịch"/"Đang diễn ra" đến khi bấm "Kết thúc buổi".
* Giáo viên bắt đầu được buổi bất kỳ lúc nào trong ngày dự kiến, kể cả sau giờ kết thúc.
* `liveclass:mark-absent --customer=1` chạy cho cả tenant: khi thử, nó cũng ghi vắng học viên
  khác ở buổi Owner đang thử. Dọn cùng lúc trả dữ liệu.

## Khi nào phải thử lại

Chỉ thử lại luồng tương ứng khi sửa vào:

* `LearnerMyLearningService`, `tenant/my-learning/*`, `student-site.css`, `class-detail.css`,
  `learner-lesson.js` → A1–A7, C5, C6.
* `LiveClassAttendanceCheckIn`, `LiveClassAttendanceRecorder`, `liveclass-attendance/*`,
  `LiveClassAttendanceEntryController` → C1–C8.
* `LiveClassWhiteboardService`, `liveclass-whiteboard/*`, `live-session-actions.blade.php` → B2, C1, C7, C10.
* `CourseCohortStudentController`, `membership-edit-dialog.blade.php`,
  `CourseCohortMembershipHistory` → C9.
* `course-cohort-schedules/partials/*`, `admin-ui-kit.css` → B1.

Còn lại: chạy các bộ test ở cột "Test giữ" là đủ.

## Chưa thử trên trình duyệt

* Buổi kết hợp (Tại lớp + từ xa): người ở xa bấm Vào phòng họp (phần QR và bảng đã thử ở C11); vào phòng họp thật trên Google Meet.
* Mở tài liệu ở tab mới: trình duyệt app chặn tab mới, chỉ thấy đường dự phòng.
* Nút "Đánh dấu hoàn thành" trên bảng nội dung của buổi: demo không có nội dung loại này.
* Toàn màn hình thật của popup video.
* Nút "Nhận chấm" và chấm bài thật của giáo viên.
* Safari và thiết bị thật; micro thật.
