# LF-Core-Assessment.md

Version: 1.3

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: core/LF-Core-Assessment.md

---

# LF-Core Assessment Architecture

Assessment là Evaluation Domain của LearnForge, xuất hiện với người dùng là
module **Kiểm tra**.

Assessment quản lý nguồn soạn thảo và bằng chứng đánh giá:

* Danh mục, câu hỏi, ngữ liệu
* Đề / bài tập và phiên bản xuất bản
* Ghi danh vào đề
* Lần làm bài và câu trả lời
* Chấm điểm, điểm số, đạt / không đạt, nhận xét

Assessment không phải Course Domain và không sở hữu Course Progress, Course
Completion, Certificate Eligibility hay thanh toán.

Quyết định nguồn:

* [ADR-0003 — Assessment Foundation](../adr/ADR-0003-Assessment-Foundation.md):
  ranh giới miền, snapshot, AI chỉ đề xuất, Media sở hữu file.
* [ADR-0025 — Assessment Simple Administration](../adr/ADR-0025-Assessment-Simple-Administration.md)
  (Approved 2026-11-06): đề là tài sản độc lập có phiên bản, ghi danh thay
  Enrollment ở giai đoạn 1, ngữ liệu, phạm vi giai đoạn 1. ADR-0025 thay các
  điểm tương ứng của ADR-0003.

---

# Phạm vi giai đoạn 1

Có:

* Danh mục, câu hỏi, ngữ liệu nhập bằng form.
* Đề / bài tập ghép thủ công; xuất bản phiên bản.
* Ghi danh: học viên tự đăng ký miễn phí đề *Mở đăng ký*, hoặc admin / giáo
  viên cấp.
* "Đề thi của tôi", làm bài, tự chấm, giáo viên chấm điểm và nhận xét, kết quả.
* Bổ sung theo yêu cầu Owner 2026-11-06 (không đổi schema):
  * Nhập câu hỏi từ Excel (.xlsx) hoặc CSV trên trang Câu hỏi: mỗi dòng một
    câu, cột cố định theo file mẫu; kiểm hết rồi lưu trong một transaction, có
    dòng lỗi thì không lưu câu nào; mỗi dòng đi qua đúng quy tắc của form; câu
    thuộc người nhập. Chỉ văn bản thuần, không nhập ngữ liệu và media. Đọc file
    bằng `App\Support\SimpleXlsx` (ZipArchive + XMLReader), không thêm thư viện.
  * Xuất Excel trên trang Câu hỏi: các câu đang thấy theo bộ lọc hiện tại
    (cùng phạm vi danh sách: admin cả tenant, giáo viên câu của mình), đúng các
    cột của file mẫu nên nhập lại được; tối đa 5.000 câu. Nút theo UI Kit:
    Nhập Excel (`file-input`), Xuất Excel (`file-output`).
  * Tab Thống kê trong Kết quả & Chấm bài: theo một đề, gộp mọi phiên bản và
    ghi danh; chỉ tính bài đã nộp mà người xem được thấy (cùng phạm vi danh sách
    bài làm). Tổng quan (số bài, điểm trung bình, thấp nhất – cao nhất, tỉ lệ
    đạt) và từng câu nguồn: tỉ lệ làm đúng (câu tự chấm) hoặc điểm trung bình
    của bài đã chấm (tự luận, nói); Dễ từ 85%, Khó từ 30% trở xuống. Chỉ đọc.
* Tiêu chí chấm (rubric) theo [ADR-0026](../adr/ADR-0026-Assessment-Rubric.md)
  (Owner duyệt 2026-11-06): bộ tiêu chí dùng lại gắn vào câu tự luận / nói,
  chụp vào phiên bản đề khi xuất bản, giáo viên chấm từng tiêu chí, điểm quy đổi
  về điểm của câu; học viên thấy điểm từng tiêu chí.

Không có (làm sau hoặc bỏ): đề động, gói đề, gắn vào Course Template, giao
theo lớp, thanh toán / bán đề, chia sẻ giữa giáo viên, xuất Excel kết quả, xếp loại,
phân công chấm, mọi tính năng AI, đề tổ hợp HSA.

---

# Architecture Flow

```text
Danh mục
↓
Câu hỏi / Ngữ liệu (nguồn soạn thảo, sửa được)
↓ chọn tay
Bản nháp đề (phần, câu, điểm, cài đặt)
↓ Xuất bản
Phiên bản đề (bất biến, snapshot)
↓
Ghi danh (tự đăng ký / được cấp) ── nguồn quyền
↓
Lần làm bài (khoá phiên bản) → Câu trả lời → Chấm
↓
Bằng chứng đánh giá (điểm, đạt / không đạt, nhận xét)
```

---

# Assessment Evaluation Principle

Assessment chỉ sinh:

```text
Attempt
Answer
Score
Pass / Fail
Grading
Feedback
```

Assessment không sở hữu:

```text
Course Progress
Course Completion
Certificate Eligibility
Promotion
Learning State
Payment
```

Domain khác đọc bằng chứng đánh giá và tự quyết định. Assessment không cập nhật
trực tiếp business state của Domain khác.

---

# Authoring And Snapshot Model

Nguồn soạn thảo:

```text
Category
Passage ── Questions
Question ── Content / Options / Media
```

Câu hỏi và ngữ liệu có lifecycle `draft` → `published` → `archived`; sửa được ở
mọi trạng thái trừ khi đã lưu trữ. Chỉ nguồn `published` được thêm vào đề.

Đề có bản nháp sửa được. Khi xuất bản:

```text
Bản nháp đề + câu hỏi / ngữ liệu hiện tại
↓ snapshot
Quiz Version + Quiz Version Questions
```

Phiên bản đóng băng: chính sách (thời gian, số lượt, điểm đạt, cách hiện kết
quả), phần, thứ tự, điểm, nội dung câu, ngữ liệu, Media reference, lựa chọn và
đáp án đúng. Phiên bản không bao giờ thay đổi. Sửa câu hỏi hay bản nháp sau đó
chỉ có hiệu lực khi xuất bản lại.

Nguồn soạn thảo đã vào một phiên bản chỉ được lưu trữ, không xoá cứng.

---

# Quiz Structure

```text
Quiz (bản nháp)
↓
Sections
↓
Quiz Questions (câu lẻ hoặc câu con của ngữ liệu, liền nhau)
```

Đề và bài tập là cùng một thực thể; `assessment_type` (`exercise`, `exam`) chỉ
là nhãn. Khác biệt nằm ở cài đặt: giới hạn thời gian (NULL = không giới hạn), số
lượt, điểm đạt, `show_result_mode`.

Dạng câu: `single_choice`, `multiple_choice`, `true_false`, `short_answer`,
`essay`, `speaking`. Kỹ năng: `reading`, `listening`, `writing`, `speaking`,
`other`. Độ khó: `easy`, `medium`, `hard`.

---

# Registration, Attempt And Answer

```text
Quiz Registration
↓
Attempt (khoá Quiz Version)
↓
Answers
↓
Answer Files (ghi âm)
```

* Mỗi lần làm bài có đúng một nguồn quyền. Giai đoạn 1 là ghi danh vào đề.
* Mỗi học viên có tối đa một ghi danh đang hiệu lực cho mỗi đề. Mỗi ghi danh có
  đủ `max_attempts` lượt; hết lượt thì đăng ký lại hoặc được cấp lại.
* Bắt đầu, đếm lượt, giờ làm bài và nộp đều do server quyết trong transaction.
* Câu trả lời lưu theo từng câu trong lúc làm; mở lại thì làm tiếp.
* Câu trả lời thuộc đúng phiên bản của lần làm bài và giữ nhãn / nội dung lựa
  chọn đã chọn.
* Đáp án đúng và giải thích không gửi cho học viên trước khi lần làm bài kết
  thúc; sau đó theo `show_result_mode`.

---

# Grading Architecture

```text
Nộp bài
↓
Tự chấm câu khách quan (server, từ snapshot)
↓
Câu essay / speaking → Chờ chấm → Giáo viên chấm điểm + nhận xét
↓
Tổng điểm, đạt / không đạt
```

* Trắc nghiệm nhiều đáp án đúng khi chọn đúng y tập đáp án; không có điểm từng
  phần ở giai đoạn 1.
* Mỗi lần chấm hoặc chấm lại là một bản ghi; một bản ghi hiện hành mỗi câu; tổng
  điểm tính lại từ điểm hiện hành của các câu.
* Điểm chấm tay trong `0..điểm tối đa của câu`, kiểm ở server.
* Người chấm: admin của tenant, người tạo đề, người đã cấp ghi danh.

Khi có AI (sau này), quy tắc của ADR-0003 áp dụng: AI chỉ là đề xuất, giáo viên
quyết. Rubric chụp vào phiên bản đề khi xuất bản (ADR-0026 D4, sửa ADR-0003);
câu có rubric: điểm câu = tổng điểm tiêu chí ÷ tổng rubric × điểm của câu.

---

# Roles And Experience

| Vai trò | Quyền |
|---|---|
| `customer_admin` | Quản lý danh mục; xem, sửa mọi câu hỏi, ngữ liệu, tiêu chí chấm, đề, ghi danh, kết quả của tenant; chấm mọi bài |
| `teacher` | Xem, sửa câu hỏi, ngữ liệu, tiêu chí chấm, đề do mình tạo (không đổi người tạo); dùng thêm tiêu chí chấm do admin tạo; chọn danh mục; cấp đề của mình cho học viên; chấm bài thuộc đề mình tạo hoặc ghi danh mình cấp |
| `student` | Xem danh sách đề *Mở đăng ký*, tự đăng ký, làm bài, xem "Đề thi của tôi" và kết quả của chính mình |
| Khách | Xem danh sách đề *Mở đăng ký* (chỉ thông tin giới thiệu) |

Khu quản trị: mục **Kiểm tra** trên left menu ở `/admin/assessments` và
`/teacher/assessments`, gồm Danh mục, Câu hỏi, Ngữ liệu, Tiêu chí chấm, Đề / Bài
tập, Ghi danh, Kết quả & Chấm bài. Học viên dùng mục **Assessments** trên website tenant; không
có portal học viên riêng.

"Đề thi của tôi" là **tab** trong trang Assessments (`/assessments/my`), không
phải mục menu: menu học viên giữ nguyên 8 mục theo Guardrails. Hai tab "Tất cả
đề" và "Đề thi của tôi" chỉ hiện với học viên đã đăng nhập. Trang làm bài và kết
quả nằm dưới `/assessments/attempts/{id}`; mục Assessments trên menu vẫn được
đánh dấu đang chọn.

---

# Media Integration

Media Domain sở hữu file thật của câu hỏi, ngữ liệu và ghi âm Speaking.
Assessment chỉ lưu `media_file_id` và ghi `media_file_usages`
(`assessment_question`, `assessment_passage`, `assessment_quiz_version`,
`assessment_answer`). Phiên bản đề giữ usage riêng để file không bị xoá khi tác
giả gỡ khỏi câu hỏi.

Ảnh, audio của câu hỏi và ảnh, audio, video của ngữ liệu được tải lên trong form
hoặc chọn từ Thư viện Media (bộ chọn chung của ADR-0021: admin chọn mọi file của
tenant, giáo viên chỉ file của mình hoặc được chia sẻ). Server kiểm file chọn
(cùng tenant, đúng loại, `ready`, quyền dùng) trước khi lưu; chọn file khác thì
chỉ gỡ usage, không xoá file của thư viện.

---

# Course Integration (làm sau)

Gắn đề hoặc gói đề vào Course Template chưa làm. Hoạt động `quiz` và
`assessment_quiz_id` ở Course giữ nguyên; template có hoạt động quiz vẫn bị chặn
xuất bản theo [LF-Core-Course](LF-Core-Course.md).

Khi làm, kiến trúc đích của ADR-0003 áp dụng cho lần làm bài có nguồn quyền là
khoá học:

* Version Activity bất biến đóng băng phiên bản đề (hoặc gói) được dùng.
* Lần làm bài tham chiếu `enrollment_id` và `version_activity_id`; Enrollment
  phải active.
* Course đọc bằng chứng đánh giá và tự quyết Activity Progress / Completion;
  Certificate tự quyết eligibility.

Thanh toán / bán đề (sau này) dùng chung luồng đơn hàng và thanh toán với khoá
học; Assessment không có cổng thanh toán riêng.

---

# AI Integration (ngoài phạm vi hiện tại)

Không có tính năng AI trong module Kiểm tra ở đợt này. Khi làm, AI chỉ tạo đề
xuất (câu hỏi, gợi ý chấm, nhận xét), giáo viên duyệt và quyết định cuối; lưu
provider / model / prompt version (ADR-0003, ADR-0017).

---

# Track Integration

Track Domain có thể ghi raw events (bắt đầu, nộp, chấm xong). Assessment giữ
business records; Track không thay Attempt, Answer hoặc Grading.

---

# Database Namespace

```text
core_assessment_*
```

Bảng giai đoạn 1 và bảng hoãn: xem
[database/assessment/README.md](../database/assessment/README.md).

---

# Design Rules

1. Mọi Assessment business data có `customer_id`; khoá ngoại nội bộ dùng khoá kép
   `(id, customer_id)`.
2. Cross-domain references cùng tenant.
3. Assessment là Evaluation Domain, không phải Course Domain.
4. Học viên chỉ làm bài trên phiên bản đề đã xuất bản, không bao giờ trên bản
   nháp.
5. Phiên bản đề và câu đã đóng băng không bao giờ thay đổi.
6. Mỗi lần làm bài có đúng một nguồn quyền (giai đoạn 1: ghi danh).
7. Câu trả lời thuộc lần làm bài và câu của đúng phiên bản.
8. Chấm, giờ làm bài, số lượt do server quyết; không tin dữ liệu chấm từ client.
9. Đáp án đúng không gửi cho học viên trước khi lần làm bài kết thúc.
10. File thật thuộc Media Domain.
11. AI (khi có) chỉ là đề xuất; rubric chấm và xem lại chỉ đọc snapshot trong
    phiên bản đề.
12. Bằng chứng Assessment không tự quyết Course completion / certificate.
13. Course Activity Progress là source of truth cho activity completion.
14. Cross-domain effect dùng Evidence / Event / Request; Domain đích tự quyết.

---

# Implementation Status

Cập nhật 2026-11-06.

| Phần | Trạng thái |
|---|---|
| Danh mục, câu hỏi (mọi dạng), ngữ liệu, media câu hỏi | Đã làm |
| Đề / bài tập: nháp, ghép câu, phần, xuất bản phiên bản | Đã làm |
| Ghi danh: admin / giáo viên cấp, học viên tự đăng ký | Đã làm |
| Học viên: danh sách đề, "Đề thi của tôi", làm bài có đồng hồ, tự lưu, ghi âm Speaking, nộp, kết quả theo `show_result_mode` | Đã làm |
| Tự chấm câu khách quan; giáo viên chấm essay / speaking, chấm lại | Đã làm |
| Media Library: tên và liên kết cho bản ghi âm, phiên bản đề | Đã làm |
| Chọn ảnh / audio / video từ Thư viện Media cho câu hỏi và ngữ liệu | Đã làm |
| Hết giờ: trình duyệt tự nộp; server đóng lần làm bài quá hạn khi đọc (`submit_reason = timeout`, ân hạn `assessment.deadline_grace_seconds`) | Đã làm |
| Kiểm chứng trình duyệt phía giáo viên | Đã làm (trừ nghe bài nói thuộc đề giáo viên) |
| Micro thật, Safari / iOS | Chưa |
| Nhập / Xuất Excel câu hỏi, tab Thống kê | Đã làm |
| Tiêu chí chấm (ADR-0026): trang quản lý, chọn trong câu hỏi, chụp khi xuất bản, chấm từng tiêu chí, học viên xem điểm tiêu chí | Đã làm |
| Mọi mục "Không có" ở Phạm vi giai đoạn 1 | Chưa làm |

Kiểm chứng: [LF-Assessment-Learner-Regression-Audit](../quality/LF-Assessment-Learner-Regression-Audit.md).

---

# Final Statement

Assessment đo lường kết quả học tập và sinh bằng chứng đánh giá. Giai đoạn 1 là
module Kiểm tra độc lập: soạn câu hỏi, ghép đề thủ công, xuất bản phiên bản,
ghi danh, làm bài, chấm. Phiên bản đề giữ lịch sử bất biến; Media giữ file;
Course và Certificate tự quyết trạng thái của mình khi được tích hợp.

Mọi thay đổi kiến trúc tiếp theo phải qua ADR mới hoặc amendment được Owner chấp
thuận.

---

End of LF-Core-Assessment
