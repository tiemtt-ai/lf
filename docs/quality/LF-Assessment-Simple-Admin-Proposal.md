# LF Assessment Simple Admin Proposal

Version: 0.3

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-06

Document Path: quality/LF-Assessment-Simple-Admin-Proposal.md

---

# Mục đích

Đưa module Bài tập / Kiểm tra vào LearnForge với cách quản trị đơn giản như hệ
thống question_bank đang dùng, nhưng giữ các nguyên tắc đúng của
[ADR-0003](../adr/ADR-0003-Assessment-Foundation.md): cô lập tenant, snapshot
khi xuất bản, chấm ở server, AI chỉ đề xuất, file thuộc Media.

Tài liệu này là phân tích và đề xuất để Owner duyệt. Các quyết định chính thức
nằm ở [ADR-0025](../adr/ADR-0025-Assessment-Simple-Administration.md). Chưa có
migration nào được tạo.

Owner đã chốt hướng (2026-11-06):

* Ủng hộ cách quản trị đơn giản như question_bank.
* Đồng ý với các điểm tích cực trong tài liệu Assessment của LF.
* Không lấy code hay schema của question_bank; chỉ lấy cách tiếp cận.

---

# Hiện trạng (đã kiểm tra)

## question_bank (`htdocs/question_bank`, Laravel + Vue 2)

Màn hình quản trị gồm 3 phần:

| Phần | Cách làm | Điểm mạnh | Điểm yếu chính |
| --- | --- | --- | --- |
| Câu hỏi | Một bảng `group_questions`; câu thuộc một "topic" (đoạn đọc, audio, video dùng chung) hoặc là câu lẻ | Nhóm câu theo ngữ liệu; 4 kỹ năng; Excel import/export; AI tách câu hỏi từ PDF/DOCX | Đáp án là JSON trong cột text; không có dạng điền khuyết/tự luận rõ; AI lưu thẳng không qua duyệt; giáo viên sửa/xoá được câu của người khác; XSS, mass assignment |
| Đề thi | Bảng `exams` lưu mảng id câu hỏi và mảng điểm song song | Chọn tay hoặc lấy ngẫu nhiên theo kỹ năng × độ khó; điểm riêng từng câu; thời gian, số lượt, điểm đạt | Không có snapshot: sửa câu làm sai kết quả cũ; không có nháp/xuất bản; API công khai trả cả đáp án đúng |
| Kết quả, chấm bài | Bảng `submits`, một dòng JSON cho cả bài | Tự chấm trắc nghiệm; Writing/Speaking chuyển "chờ chấm"; giáo viên cho điểm và nhận xét từng câu; danh sách lọc, xuất Excel | Chấm sai câu nhiều đáp án; tin thời gian/lượt/điểm từ client; xem được bài người khác; chấm lại làm mất điểm; điểm kiểu số nguyên |

Đã xác minh trực tiếp trong code:

* `ExamService.php:299`: dòng xoá `isCorrect` trước khi trả đề bị comment.
* `routes/api.php:47`: `GET exam/{id}` nằm ngoài middleware đăng nhập.
* `SubmitController.php:118`: điều kiện `array_diff` cho điểm trọn khi chỉ chọn
  một phần đáp án đúng.
* `StudentsController.php:329`: cấp đề khi client gửi `res_vnpay === "00"`.

## LearnForge

* Thiết kế Assessment đã duyệt: [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md),
  [LF-Core-Assessment](../core/LF-Core-Assessment.md),
  18 bảng [`core_assessment_*`](../database/assessment/README.md).
* Chưa có migration, model hay route Assessment nào.
* Phía Course đã có loại hoạt động `quiz` với tham chiếu tạm
  `assessment_quiz_id` (`CourseTemplateActivityController.php:922`) và các luật
  hoàn thành `submit`, `pass`, `manual`. Template có hoạt động quiz đang bị chặn
  xuất bản cho tới khi Assessment xong.
* Đã có sẵn và dùng lại được: Media (thư viện, chia sẻ, OCR, trích xuất có cấu
  trúc), luồng đề xuất AI có duyệt (ADR-0017), giáo viên của lớp
  (`core_course_cohort_teachers`), giáo viên của template
  (`core_course_template_teachers`).

---

# Đánh giá thiết kế LF hiện tại

## Giữ nguyên (điểm mạnh)

* Mọi bản ghi có `customer_id`, khoá ngoại thật, điểm kiểu `DECIMAL`.
* Đáp án tách thành bảng `question_options` với `is_correct`.
* Snapshot câu hỏi khi xuất bản đề; lần làm bài đọc snapshot.
* Lần làm bài (`attempts`) và câu trả lời (`answers`) là bản ghi riêng; câu trả
  lời giữ nhãn/nội dung lựa chọn đã chọn.
* File thuộc Media; Assessment chỉ lưu `media_file_id`.
* AI chỉ đề xuất, giáo viên quyết; lưu provider/model/prompt.
* Assessment chỉ sinh bằng chứng; Course tự quyết hoàn thành.

## Chỗ phức tạp hơn mức cần

| Thiết kế hiện tại | Vì sao nặng | Đề xuất |
| --- | --- | --- |
| Quiz gắn cứng 1-1 với `version_activity_id`, bắt buộc `product_id`, `template_version_id` | Không có chỗ để soạn đề trước; mỗi lần xuất bản khoá học phải tạo đề mới; không dùng lại một đề cho nhiều khoá | Đề là tài sản độc lập của tenant, dùng lại được; xuất bản đề tạo phiên bản bất biến; hoạt động khoá học trỏ tới phiên bản |
| Question Bank bắt buộc (`question_bank_id NOT NULL`) | Giáo viên phải tạo "ngân hàng" trước khi nhập câu đầu tiên | Bỏ khỏi giai đoạn 1; câu hỏi thuộc người tạo, phân loại bằng danh mục và chủ đề |
| Nội dung đa ngôn ngữ ở mọi câu | question_bank không cần; làm form phức tạp | Giữ bảng nhưng giai đoạn 1 chỉ một ngôn ngữ mỗi câu, giao diện không hiện chọn ngôn ngữ |
| Rubric và phân công chấm là bắt buộc trong luồng chấm | question_bank chấm điểm + nhận xét là đủ dùng | Giai đoạn 1 chấm điểm + nhận xét; rubric và phân công chấm để giai đoạn sau |
| `difficulty_level` chuỗi tự do | Lọc và lấy ngẫu nhiên không tin cậy được (lỗi `LIKE` của question_bank) | Cố định 3 mức: `easy`, `medium`, `hard` |
| `question_type` có `listening` | Trùng với `skill_type`; listening là kỹ năng, không phải dạng câu | Bỏ `listening` khỏi `question_type` |

## Chỗ còn thiếu (điểm mạnh của question_bank)

* **Ngữ liệu dùng chung**: một đoạn đọc hoặc bài nghe có nhiều câu hỏi con.
  LF chưa có khái niệm này.
* **Bản nháp của đề**: chỗ để chọn câu, chia phần, đặt điểm trước khi xuất bản.
* **Đề động**: chọn danh mục + số câu, hệ thống tự ghép đề (làm sau).
* **Import/Export Excel** câu hỏi (làm sau).
* **AI tách câu hỏi từ file đề** (PDF/DOCX): bỏ khỏi đợt này. Nếu làm lại sau,
  phải đi qua Media để trích xuất và qua bước giáo viên duyệt.
* **Màn hình "Chờ chấm"** cho giáo viên và danh sách kết quả theo đề.

---

# Mô hình đề xuất

```text
Danh mục (cây, admin quản lý) ── "nhóm câu hỏi"
  ├─ Câu lẻ
  └─ Ngữ liệu (đoạn đọc / bài nghe) ── nhiều câu con
            │ chọn tay (giai đoạn 1); chọn danh mục + số câu (đề động, làm sau)
            ▼
Đề / Bài tập (bản nháp, sửa thoải mái) ── Phần ── Câu trong đề (điểm, thứ tự)
            │ Xuất bản
            ▼
Phiên bản đề (bất biến, snapshot câu, đáp án, điểm, thời gian, số lượt)
            │ Học viên tự đăng ký (miễn phí) hoặc admin/giáo viên cấp
            ▼
Ghi danh (mỗi lần = đủ số lượt) ──▶ Lần làm bài (khoá phiên bản) ── Câu trả lời ── Chấm (tự động / giáo viên)
```

Làm sau: Gói đề (nhiều đề, thời gian mở/đóng) và gắn đề hoặc gói vào Course
Template. Khi đó lần làm bài có thêm nguồn quyền là khoá học (Enrollment).

## Thay đổi bảng (chỉ để duyệt, chưa tạo)

| Bảng | Thay đổi |
| --- | --- |
| `core_assessment_categories` | Giữ; gắn trực tiếp vào câu hỏi, ngữ liệu và đề |
| `core_assessment_questions` | Bỏ `question_bank_id`; thêm `category_id`, `passage_id` (nullable), thứ tự trong ngữ liệu; `difficulty_level` 3 giá trị cố định; bỏ `listening` khỏi `question_type` |
| `core_assessment_question_contents`, `core_assessment_question_options` | Giữ; một locale mỗi câu |
| `core_assessment_passages` | **Mới**: ngữ liệu dùng chung (tiêu đề, nội dung, kỹ năng, danh mục, người tạo, trạng thái) |
| `core_assessment_question_media` | Gắn vào câu hỏi **hoặc** ngữ liệu (đúng một trong hai) |
| `core_assessment_quizzes` | Thành **bản nháp đề** dùng lại được: bỏ `product_id`, `template_version_id`, `version_activity_id`; thêm `created_by`, `category_id`, `current_version_id` |
| `core_assessment_quiz_sections`, `core_assessment_quiz_questions` | Thành cấu trúc **bản nháp**, sửa được; bỏ các cột snapshot |
| `core_assessment_quiz_versions` | **Mới**: phiên bản xuất bản bất biến |
| `core_assessment_quiz_version_questions` | **Mới**: câu đã đóng băng |
| `core_assessment_quiz_registrations` | **Mới**: ghi danh một học viên vào một đề; nguồn tự đăng ký hoặc được cấp |
| `core_assessment_attempts` | Thêm `quiz_registration_id`, `quiz_version_id`; `enrollment_id`, `version_activity_id`, `product_id`, `template_version_id` thành nullable (dành cho gắn khoá học sau) |
| `core_assessment_answers` | Tham chiếu `quiz_version_question_id` |
| `core_assessment_answer_files`, `core_assessment_gradings` | Giữ; giai đoạn 1 `gradings` dùng `teacher` và `system_auto` |
| `core_assessment_question_banks`, `core_assessment_topics`, `core_assessment_question_topics`, `core_assessment_rubrics`, `core_assessment_rubric_items`, `core_assessment_grading_assignments` | Hoãn |

Giai đoạn 1: 16 bảng (hoãn 6, thêm 4), so với 18 bảng hiện tại.

---

# Quy tắc nghiệp vụ rút ra từ lỗi của question_bank

| Lỗi ở question_bank | Quy tắc cho LF |
| --- | --- |
| Đề trả về đáp án đúng cho trình duyệt | Đáp án, giải thích và `is_correct` không bao giờ gửi cho học viên trước khi nộp; sau khi nộp chỉ hiện theo `show_result_mode` |
| Server tin điểm, thời gian, lượt từ client | Server tự chấm từ snapshot; giờ tính từ `started_at` của server; giới hạn lượt kiểm trong transaction có khoá |
| Câu trả lời không kiểm tra thuộc đề | Câu trả lời phải thuộc đúng phiên bản đề của lần làm bài |
| Câu nhiều đáp án chọn thiếu vẫn trọn điểm | Trắc nghiệm nhiều đáp án: đúng khi chọn đúng y tập đáp án; không có điểm từng phần ở giai đoạn 1 |
| Xem được bài người khác bằng id | Học viên chỉ xem lần làm bài của mình; giáo viên chỉ xem bài thuộc lớp/khoá mình dạy; admin xem cả tenant |
| Giáo viên sửa/xoá câu và đề của người khác | Giáo viên chỉ sửa câu, ngữ liệu, đề của mình; admin sửa tất cả (như Media ADR-0021) |
| Sửa câu làm sai kết quả cũ | Lần làm bài đọc snapshot của phiên bản đề; sửa câu hỏi không ảnh hưởng |
| Xoá cứng câu đang dùng | Câu hỏi và ngữ liệu chỉ lưu trữ (`archived`), không xoá khi đã có trong đề |
| Chấm lại làm mất điểm câu khác | Mỗi lần chấm là một bản ghi `gradings` theo từng câu; tổng điểm tính lại từ điểm cuối của các câu |
| Điểm chấm vượt điểm tối đa | Server kiểm `0 ≤ điểm ≤ max_score` của câu |
| Điểm số nguyên | `DECIMAL` ở mọi cột điểm (đã có trong LF) |
| AI lưu thẳng vào ngân hàng | Đợt này không có AI; nếu làm sau thì AI chỉ tạo đề xuất, giáo viên duyệt từng câu |
| Bỏ trống câu Speaking làm lệch file ghi âm | File ghi âm gắn theo `answer_id`, không theo thứ tự |
| Không có lưu nháp khi đang làm bài | Câu trả lời được lưu theo từng câu trong lúc làm; mở lại thì tiếp tục |

---

# Giao diện quản trị

Mục **Kiểm tra** trên left menu ở khu admin (`/admin/assessments`) và khu giáo
viên (`/teacher/assessments`):

1. **Danh mục**: cây danh mục (admin sửa, giáo viên chỉ xem).
2. **Câu hỏi**: danh sách lọc theo danh mục, kỹ năng, độ khó, dạng câu, trạng
   thái, câu lẻ / thuộc ngữ liệu; form thêm và sửa câu.
3. **Ngữ liệu**: danh sách đoạn đọc / bài nghe; form ngữ liệu kèm các câu con.
4. **Đề / Bài tập**: danh sách với trạng thái *Nháp / Đã xuất bản / Lưu trữ* và
   phiên bản hiện hành; màn soạn đề chia phần, chọn câu hoặc ngữ liệu, đặt điểm,
   thời gian (để trống là bài tập không giới hạn), số lượt, điểm đạt, cách hiện
   kết quả; nút *Xuất bản*.
5. **Ghi danh**: cấp đề cho một hoặc nhiều học viên; danh sách ghi danh, số lượt
   đã dùng, huỷ.
6. **Kết quả & Chấm bài**: lần làm bài lọc theo đề, lớp, học viên, trạng thái;
   tab *Chờ chấm*; màn chấm từng câu (điểm + nhận xét).

Website tenant: mục **Assessments** liệt kê đề *Mở đăng ký* (khách xem được,
đăng ký phải đăng nhập); trang **Đề thi của tôi** cho học viên.

---

# Giai đoạn

| Giai đoạn | Nội dung |
| --- | --- |
| 1 | Danh mục, câu hỏi, ngữ liệu (form); đề / bài tập thủ công; xuất bản phiên bản; ghi danh (tự đăng ký miễn phí, được cấp); Đề thi của tôi; làm bài; tự chấm; chấm tay; kết quả |
| Sau | Đề động (danh mục + số câu), gói đề có mở/đóng, gắn vào khoá học, giao theo lớp, thanh toán (chung với khoá học), chia sẻ giữa giáo viên, Excel, thống kê theo câu, xếp loại, rubric |
| Bỏ | Đề tổ hợp HSA; mọi tính năng AI trong đợt này |

---

# Tác động cần review

* Thay đổi Foundation Decision của ADR-0003: lần làm bài không còn bắt buộc
  Enrollment; đề là tài sản độc lập có phiên bản. Ghi ở
  [ADR-0025](../adr/ADR-0025-Assessment-Simple-Administration.md).
* Sau khi ADR-0025 được duyệt phải cập nhật: `LF-Core-Assessment.md`,
  `database/assessment/README.md` và các bảng bị đổi/thêm/hoãn, LF-INDEX,
  Manifest.
* Không đổi Course ở đợt này: hoạt động `quiz` và `assessment_quiz_id` giữ
  nguyên, template có quiz vẫn bị chặn xuất bản.
* Lệch tài liệu đã biết: ADR-0003 ghi `activity_type = assessment`, code dùng
  `quiz`. Xử lý khi làm phần gắn khoá học.
* Mức rà soát khi triển khai: HIGH (tenant, quyền, route và menu mới ở admin,
  giáo viên, website tenant).

---

# Quyết định Owner (2026-11-06)

1. Chia sẻ câu hỏi/đề giữa giáo viên: giai đoạn 2.
2. Đề là sản phẩm riêng, module và menu riêng. Gói đề (một hoặc nhiều đề) và gắn
   vào khoá học làm sau.
3. Như question_bank: học viên đăng ký từng đề, đề vào "Đề thi của tôi", làm
   đúng số lượt quy định; lượt tính theo mỗi lần ghi danh. Thời gian mở/đóng
   thuộc gói đề, làm sau.
4. Đề động: chọn danh mục (nhóm câu hỏi) + số câu, hệ thống tự ghép; làm sau.
   Giai đoạn 1 chỉ ghép thủ công.
5. Người chấm: admin, người tạo đề, người cấp đề.
6. Thang xếp loại: làm sau.
7. Bỏ HSA và mọi tính năng AI khỏi đợt này.
8. Chưa có thanh toán trong đợt này; làm sau, chung luồng với khoá học.
9. Ghi danh trong module Kiểm tra theo từng học viên; giao theo lớp thuộc phía
   lớp học, làm sau.
10. Khách chưa đăng nhập xem được danh sách đề *Mở đăng ký*.

---

# Bước tiếp theo

1. ~~Owner duyệt ADR-0025~~ (Approved 2026-11-06).
2. ~~Cập nhật tài liệu Domain và Database theo ADR đã duyệt~~ (xong 2026-11-06,
   database docs ở trạng thái Review).
3. Architecture Review cho database docs (hoặc Owner miễn như ADR-0021).
4. Migration giai đoạn 1, sau đó triển khai và test.
