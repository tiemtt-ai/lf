# Kiểm tra (Assessment) — Regression Audit phía học viên, chấm bài và Media

Version: 1.0

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: quality/LF-Assessment-Learner-Regression-Audit.md

---

Date: 2026-11-06

## Classification

Existing-Feature Change (website tenant, menu học viên, Media Library, upload
Media) kèm tính năng mới theo ADR-0025 (làm bài, ghi âm, kết quả).

## Audit Level

* Initial Audit Level: HIGH
* Final Audit Level: HIGH
* Audit Level Escalation: None
* Rationale: thay đổi route có auth/role, quyền sở hữu dữ liệu học viên, menu
  học viên trên website tenant, kiểm tra nội dung file upload trong
  `MediaService`, đồng hồ làm bài do server quyết và giấu đáp án trước khi nộp.

## Escalation Triggers Reviewed

| Trigger | Có | Ghi chú |
| --- | --- | --- |
| auth / role / middleware | Có | route mới trong nhóm `role:student`; không đổi middleware |
| tenant isolation | Có | mọi truy vấn kèm `customer_id`; test chéo tenant |
| navigation | Có | chỉ thêm route con vào trạng thái active của mục Assessments |
| i18n | Có | khoá `LF_assessment_learner_*` VI/EN |
| Media validation | Có | ngoại lệ hẹp cho `.webm` audio |
| schema / migration | Không | dùng bảng đã có của ADR-0025 |

## Current Behavior (trước thay đổi)

* `/assessments` là trang giữ chỗ trong `TenantWebsiteController::assessments()`.
* Học viên chưa xem được danh sách đề, chưa đăng ký, làm bài hay xem kết quả.
* Media Library hiện usage `assessment_answer` không có tên chủ sở hữu và không
  có liên kết.
* File WebM ghi bằng `MediaRecorder` của Chrome bị từ chối (422) vì libmagic
  nhận mọi WebM là `video/webm`.

## Requested Behavior

* Khách và học viên xem danh sách đề *Mở đăng ký*; học viên tự đăng ký miễn phí.
* Tab "Đề thi của tôi" (`/assessments/my`), làm bài có đồng hồ, tự lưu từng câu,
  ghi âm câu Speaking, nộp bài, xem kết quả theo `show_result_mode`.
* Không thêm mục menu, không có portal học viên riêng.
* Media Library hiển thị bản ghi âm có tên và liên kết tới trang kết quả quản trị.

## Documents Reviewed

* `docs/README.md`, `docs/LF-INDEX.md`
* `docs/governance/LF-Architecture-Guardrails.md` (menu học viên cố định 8 mục,
  không `/student`, không Student Portal)
* `docs/quality/LF-Regression-Audit.md`
* `docs/adr/ADR-0025-Assessment-Simple-Administration.md`, `ADR-0003`
* `docs/core/LF-Core-Assessment.md`, `docs/database/assessment/*`
* `docs/platform/LF-Media.md`, `LF-Media-Processing-Contract.md`

## Source Of Truth

ADR-0025 và `LF-Core-Assessment.md` cho hành vi; Guardrails cho menu và route;
`LF-Media.md` cho tập MIME được phép.

## Invariants

1. Học viên chỉ đọc / ghi ghi danh và lần làm bài của chính mình; khác chủ → 404.
2. Mọi truy vấn có `customer_id` của tenant hiện tại.
3. Đáp án đúng, giải thích và đáp án tham khảo không có trong HTML / JSON trước
   khi lần làm bài kết thúc; đáp án tham khảo không bao giờ hiện cho học viên.
4. Bắt đầu, đếm lượt, hạn giờ và nộp do server quyết trong transaction; quá hạn
   (cộng `assessment.deadline_grace_seconds`) thì đóng với `submit_reason = timeout`.
5. Tự đăng ký có `source = self_registered`, `granted_by = NULL`; tối đa một ghi
   danh hiệu lực mỗi đề (lặp lại trả về ghi danh cũ).
6. Menu học viên giữ nguyên 8 mục; không có route `/student`.
7. Ngoại lệ MIME chỉ áp dụng khi đồng thời `file_type = audio`, extension `webm`,
   MIME `video/webm`; mọi tổ hợp khác giữ luật cũ.

## Impact Analysis

| Khu vực | Ảnh hưởng |
| --- | --- |
| Website tenant | `/assessments` đổi controller; giữ tên route `tenant.assessments` |
| Menu học viên | Mục Assessments active thêm cho `student.assessments.*` |
| Media upload | Thêm một nhánh cho phép WebM audio; các loại khác không đổi |
| Media Library | Thêm tên / liên kết cho `assessment_answer`, `assessment_quiz_version` |
| Quản trị Kết quả | Căn lề form chấm (`.ui-result-grade`) |

## Dependencies And Consumers

`MediaService` (upload, usage, xoá file không còn dùng), `UploadLimit`,
`TenantContext`, layout `layouts.tenant`, `resources/js/app.js` (nạp động
`tenant-assessment-take.js` chỉ khi có `[data-exam-attempt]`).

## Files Changed

* `app/Http/Controllers/MediaFileController.php`
* `app/Http/Controllers/TenantWebsiteController.php` (bỏ trang giữ chỗ)
* `app/Services/MediaService.php` (ngoại lệ WebM audio)
* `app/Services/Assessment/AssessmentAttemptService.php` (`assertOpen`, `saveRecording`)
* `routes/web.php`
* `resources/views/layouts/tenant.blade.php`
* `resources/css/tenant-site.css`, `resources/css/admin/admin-ui-kit.css`
* `resources/js/app.js`
* `resources/lang/vi/lf.php`, `resources/lang/en/lf.php`
* `tests/Feature/Assessment/AssessmentResultGradingTest.php`

## New Files

* `app/Services/Assessment/AssessmentLearnerService.php`
* `app/Http/Controllers/Assessment/AssessmentLearnerController.php`
* `resources/views/tenant/assessments/{index,mine,take,result}.blade.php` và
  `partials/{tabs,media,passage-and-section}.blade.php`
* `resources/js/tenant-assessment-take.js`
* `resources/css/tenant/assessment.css`
* `tests/Feature/Assessment/AssessmentLearnerTest.php`
* tài liệu này

## Implementation Summary

* Route học viên nằm trong nhóm `role:student` đã có, tiền tố `/assessments`
  (không `/student`), tên `student.assessments.*`, tham số `whereNumber`.
* `AssessmentLearnerService` lọc theo `customer_id` + `user_id`; đóng lần làm bài
  quá hạn khi đọc.
* Controller dựng "paper" từ snapshot của phiên bản; chỉ kèm `correct` và
  `explanation` khi `reveal`.
* Ghi âm: upload qua `MediaService`, gắn usage `assessment_answer/recording`,
  thay bản cũ thì gỡ usage và xoá file nếu không còn dùng.
* Trang kết quả hiển thị theo `visibility()` (`score`, `answers`, `feedback`).

## Tests Added Or Updated

`AssessmentLearnerTest` (5 test): danh sách cho khách; đăng ký lặp và đề đóng;
làm bài + tự lưu + ghi âm (cả `video/webm`) + thay bản ghi + tên / liên kết trong
Media Library + nộp + lộ đáp án đúng lúc; chế độ `after_grading` và `hidden`;
quá hạn → `timeout`. `AssessmentResultGradingTest`: sửa điểm câu Speaking về
trong khoảng tối đa 1 điểm (test cũ đặt 2 điểm, vượt tối đa).

## Commands And Results

| Lệnh | Kết quả |
| --- | --- |
| `php artisan test tests/Feature/Assessment tests/Feature/TenantWebsiteUiTest.php` | 83 passed (1176 assertions) |
| Test Media (`--filter Media`) | 347 passed |
| `php artisan test` (toàn bộ) | 1747 passed, 23 skipped, 1 failed — `tests/Unit/AiAuthoringScriptBehaviorTest.php`, lỗi có sẵn, không liên quan |
| MariaDB 11.4 tạm (`lf_asm_check`), test học viên + chấm bài | 9 passed |
| `./vendor/bin/pint --test` trên file đã đổi | passed |
| `./vendor/bin/pint --test` toàn repo | fail ở 43 file Course / AI / test cũ, không file nào thuộc thay đổi này |
| `npm run build` | OK |
| `git diff --check` | sạch |
| `php artisan route:list --path=assessments` | 9 route website / học viên; nhóm học viên có `RoleMiddleware:student` |
| `php artisan docs:lint` | passed |

Required searches:

| Tìm | Kết quả |
| --- | --- |
| `/student` | chỉ `course-cohorts/{cohort}/students…` (quản trị lớp), không có route học viên |
| `Student Portal`, `student portal` | không có |
| `admin-login`, `teacher-login` | không có |
| `student-login` | view đăng nhập học viên có sẵn, không đổi |

## Requirement-To-Test Traceability

| Yêu cầu | Kiểm chứng |
| --- | --- |
| Khách xem danh sách, không đăng ký được | test catalog + `forgetGuards` |
| Đăng ký idempotent, đề đóng bị từ chối | test register |
| Học viên khác / tenant khác → 404 | test take / result |
| Không lộ đáp án trước khi nộp | test take (kiểm HTML) |
| Ghi âm WebM, thay bản cũ, usage | test recording + trình duyệt (MediaRecorder thật) |
| `after_grading`, `hidden` | test result modes |
| Quá hạn → `timeout` | test overdue |
| Menu 8 mục không đổi | `TenantWebsiteUiTest` + trình duyệt |
| Media Library tên / liên kết | test + trình duyệt (`/admin/assessments/results/8`) |
| Giáo viên chỉ thấy bài của đề mình; không có Danh mục; không vào được khu admin | trình duyệt teacher1 (2026-11-06): danh sách 3 bài của đề mình, `results/8` (đề admin) → 404, `categories` → 404, `/admin/...` → 403; trang chấm hiện form điểm / nhận xét (không bấm Lưu) |

## Unverified Items

1. Giáo viên nghe bản ghi âm trên trình duyệt — dev chưa có bài nói thuộc đề của
   giáo viên test; admin đã nghe được, test HTTP phủ quyền giáo viên.
2. Thu âm bằng micro thật do người dùng nói — đã thử MediaRecorder trong Chrome,
   chưa thử với giọng nói thật.
3. Safari / iOS (`audio/mp4`, `.m4a`) — chưa thử trên thiết bị.

## Remaining Risks

| Rủi ro | Mức | Lý do chấp nhận / việc tiếp |
| --- | --- | --- |
| Bản ghi WebM lưu `mime_type = video/webm`; pipeline speech-to-text chỉ nhận MIME `audio/*` | LOW | chưa có luồng phiên âm cho bài nói; khi làm AI chấm nói phải chuẩn hoá MIME hoặc mở rộng allowlist |
| Đồng hồ phía trình duyệt tự nộp; nếu tab đóng, server đóng lần làm bài khi đọc lại | LOW | đúng thiết kế lazy close; điểm tính từ câu đã lưu |
| Hai tab cùng làm một lần làm bài | LOW | lưu theo câu, bản sau ghi đè; nộp khoá bằng transaction |

## Findings By Severity

* BLOCKER: không có.
* HIGH: không có.
* MEDIUM: không có.
* LOW: test chấm bài cũ dùng điểm vượt tối đa — đã sửa; form chấm lệch hàng — đã sửa.

## Follow-up 2026-11-06 — chọn Media từ thư viện, dọn pint, test JS

Audit Level: HIGH (sửa bộ chọn Media dùng chung và quyền dùng file của giáo viên).

* Câu hỏi và ngữ liệu có nút "Chọn từ thư viện" dưới mỗi ô media
  (`x-ui-media-slot` thêm `library-name`; JS `initUiKitMediaSlot` giữ lựa chọn như
  một file chờ, id nằm trong ô ẩn `{role}_library_id`).
* `AssessmentPromptMediaService::libraryChoices()` kiểm trước khi lưu: cùng tenant,
  đúng `file_type`, `status = ready`, giáo viên chỉ file của mình hoặc được chia sẻ
  (`MediaShareService::visibleToTeacher`). Sai → 422, không lưu gì. File tải lên
  trong cùng lần gửi thắng lựa chọn từ thư viện.
* Bộ chọn chung `x-media-library-picker`: thêm loại `image` (có ảnh thu nhỏ), chỉ mở
  trong form đã hỏi, trả lựa chọn kèm `target` để đúng ô nhận; tiêu đề phụ theo ô.
  `/media/picker?type=image` nay hợp lệ (test cũ khẳng định 422 đã đổi sang loại
  `archive`). Hoạt động Khoá học vẫn chạy như cũ (đã thử trên trình duyệt).
* Pint: định dạng 43 file Course / AI / test cũ (85 dòng, chỉ khoảng trắng, thứ tự
  import, dấu `!`); bản sao trước khi sửa ở scratchpad. `pint --test` toàn repo đạt.
* `AiAuthoringScriptBehaviorTest`: luật CSS `body.sorting …` vi phạm quy tắc phạm vi
  UI Kit; viết lại `.lf-admin-ui.ui-structure:is(.sorting *) …`, cùng tác dụng.
  `node --test tests/js`: 337 đạt.
* Test thêm: 4 test câu hỏi, 1 test ngữ liệu, 2 test JS ô media.
* Toàn bộ test PHP: 1753 đạt, 23 bỏ qua, 0 lỗi (lỗi có sẵn trước đây đã hết); `npm run build`, `git diff --check`, `docs:lint` đạt.
* Trình duyệt (admin): chọn ảnh và audio cho câu hỏi mới, mỗi ô nhận đúng file;
  bộ chọn video của ngữ liệu; không bấm Lưu.

## Follow-up 2026-11-06 — giả lập điện thoại và máy tính bảng

Audit Level: LOW (chỉ CSS phía học viên và một class trong `take.blade.php`).

Đã giả lập Chrome ở 320, 375 (dọc), 768 (dọc) và 1024 (ngang), có chạm; tài khoản
student1, lần làm bài mới (attempt 9, đăng ký lại đề TOPIK; backup
`learnforge_db-before-mobile-test-20261106-160844.sql`), chặn mọi lần nộp bài.

Đã sửa:

* Thanh đồng hồ + Nộp bài không dính khi cuộn vì `.student-page` có
  `overflow-x: hidden`; trên trang làm bài đổi sang `clip` nên header và thanh đều
  dính. Trên điện thoại / máy tính bảng dọc, thanh hiện thêm "Đã trả lời x / n" và
  giờ lưu gần nhất.
* Câu nói (ghi âm) tràn 2px ở màn 320 do ô chọn tệp: cột lưới đổi sang
  `minmax(0, 1fr)`.
* Câu hỏi được cuộn tới không bị thanh dính che (`scroll-margin-top`).
* Trang kết quả: ghi chú "Bạn chọn · Đáp án đúng" xuống dòng dưới lựa chọn; liên kết
  "Xem kết quả", "← Đề thi của tôi", "Giải thích" có vùng chạm lớn hơn; dấu "·" không
  còn đứng đầu dòng khi dòng thông tin xuống hàng.

Còn lại (cần thiết bị thật): micro trên iPhone / Android, bàn phím ảo, khoá màn hình
giữa giờ.

Owner đồng ý sửa chung (2026-11-06): `.student-page` trong `resources/css/tenant/tenant.css`
dùng `overflow-x: clip` (giữ `hidden` làm dự phòng cho trình duyệt cũ), bỏ luật riêng
của trang làm bài. Header dính trên mọi trang website; đã kiểm 11 trang học viên ở
375px, 9 trang ở 1280px và trang đăng nhập / trang chủ / danh sách đề của khách:
header ở đỉnh khi cuộn, không trang nào cuộn ngang. Khu admin / teacher không đổi.

## Final Verdict

PASS WITH DOCUMENTED RISKS — mọi lệnh bắt buộc đạt (lỗi toàn suite và lỗi pint
toàn repo đều có sẵn, ngoài phạm vi); còn 3 mục chưa kiểm chứng trên trình duyệt /
thiết bị ghi ở trên.
