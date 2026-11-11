# ADR-0031 — Learner Learning Results

Version: 1.0

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-11

Proposal Date: 2026-11-11

Approval Date: 2026-11-11

Approved By: LearnForge Business/Architecture Owner

Document Path: adr/ADR-0031-Learner-Learning-Results.md

Related ADRs:

* [ADR-0030 — Learner Course Delivery And Progress](ADR-0030-Learner-Course-Delivery-And-Progress.md): D5 để "Kết quả học tập" lại; ADR này làm phần đó
* [ADR-0025 — Assessment Simple Administration](ADR-0025-Assessment-Simple-Administration.md): D12 quyết định học viên thấy gì của bài làm
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md): Attendance thuộc LiveClass

---

# Context

"Khóa học của tôi" có ô và mục menu "Kết quả học tập" nhưng đang khóa ("Sắp ra mắt",
[LF-Navigation § Tenant Website After Login](../business/LF-Navigation.md)). Owner muốn giao
diện học viên giống B2B thường của mk_v2; trang `learning-results-b2b` của mk_v2 có ba nhóm
(Khóa học, Lớp học, Bài thi), mỗi nhóm một thẻ tổng hợp, biểu đồ và bảng chi tiết.

LF đã có đủ dữ liệu, chỉ cần đọc:

* Course Progress và Lesson Progress (ADR-0030), Enrollment và hạn học.
* Session và Attendance của lớp; tỷ lệ chuyên cần đã tính ở "Lớp học của tôi"
  (có mặt hoặc đi muộn / buổi đã diễn ra).
* Ghi danh đề và lần làm bài của module Kiểm tra, với luật hiển thị D12.

Owner bảo "soạn luôn và làm luôn" (2026-11-11).

---

# Decision

## D1. Vị trí

`/my-courses?tab=results&view=courses|classes|exams`: thêm một tab của "Khóa học của tôi",
không mở route mới (Navigation Guardrails cố định nhóm route học viên). Ô "Kết quả học tập"
ở thanh danh mục và mục cùng tên trong menu Tài khoản mở trang này.

## D2. Nội dung từng nhóm

* **Khóa học** (Enrollment tự học, mọi nhóm Đang học / Đã hoàn thành / Hết hạn): thẻ tổng hợp
  gồm số khóa hoàn thành / tổng, bài đã học / tổng bài, thời gian học, lần học gần nhất. Bảng: khóa,
  trạng thái, bài đã học / tổng, tiến độ %, thời gian học, lần học gần nhất, liên kết tới khóa.
* **Lớp học** (Cohort seat đang hiện ở "Lớp học của tôi"): thẻ tổng hợp gồm tỷ lệ chuyên cần chung,
  số buổi có mặt, vắng, buổi đã diễn ra / tổng buổi. Bảng: lớp, khóa, trạng thái, buổi đã diễn
  ra / tổng, có mặt, vắng, chuyên cần %, liên kết tới chi tiết lớp (đã có danh sách từng buổi).
  Vắng = buổi đã diễn ra mà không có mặt / đi muộn.
* **Bài thi** (ghi danh đề, như "Bài thi của tôi"): thẻ tổng hợp gồm số đề đã nộp / tổng đề, số
  lần nộp, điểm trung bình của lần nộp gần nhất mà học viên được xem. Bảng: đề, trạng thái ghi
  danh, số lần nộp, lần gần nhất, cao nhất, ngày nộp gần nhất, liên kết tới kết quả. Lịch sử
  từng lượt vẫn ở "Đề thi của tôi".

Điểm hiện dạng `điểm / tối đa` và phần trăm, không quy về thang 10 như mk_v2.

## D3. Không lộ điểm chưa công bố

Mọi điểm, đạt / chưa đạt và trung bình chỉ dùng lần làm bài mà luật D12 (ADR-0025) cho học viên
xem điểm (`AssessmentLearnerService::visibility`). Lần làm chưa được xem hiện "Chưa công bố" và
không vào trung bình hay "cao nhất".

## D4. Phạm vi

* Chỉ dữ liệu của chính học viên trong tenant hiện tại, đúng phạm vi "Khóa học của tôi"
  (Enrollment `cancelled`, Cohort `draft`, seat đã rời lớp không hiện).
* Chỉ đọc; không bảng, cột hay migration mới.
* Chưa làm: biểu đồ, xuất Excel, lọc theo kỳ, "Hoạt động gần đây", chứng chỉ, điểm bài kiểm
  tra trong khóa (chờ Quiz Activity, ADR-0032).

---

# Consequences

* Học viên xem được kết quả mà không cần hỏi giáo viên; số liệu trùng với các trang đã có vì đọc
  cùng nguồn (`LearnerMyLearningService`, `AssessmentLearnerService`).
* `/learning-history` vẫn là trang riêng, chưa có nội dung (ngoài phạm vi).

# Approval

Owner duyệt 2026-11-11 ("soạn luôn và làm luôn").

# Implementation record — 2026-11-11

* `LearnerResultsService` (đọc), `LearnerMyLearningController::index` nhận `tab=results`,
  view `tenant/my-learning/results.blade.php`.
* Test: `LearnerMyLearningTest::test_learning_results_summarise_the_learners_courses_and_classes`,
  `AssessmentLearnerTest::test_learning_results_never_show_a_score_the_student_may_not_see_yet`.
* 2026-11-11: nhóm Bài thi thêm Quiz trong khóa, mỗi dòng một Enrollment + Activity
  ([ADR-0032](ADR-0032-Course-Quiz-Activity.md) D4); phần "chờ Quiz Activity" ở D4 đã xong.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
