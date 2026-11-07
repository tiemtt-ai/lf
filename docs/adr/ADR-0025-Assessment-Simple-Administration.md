# ADR-0025 — Assessment Simple Administration

Version: 1.1

Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-07

Proposal Date: 2026-11-06

Approval Date: 2026-11-06

Approved By: LearnForge Architecture Owner

Document Path: adr/ADR-0025-Assessment-Simple-Administration.md

Related ADRs:

* [ADR-0003 — Assessment Foundation](ADR-0003-Assessment-Foundation.md) — ADR này sửa một phần
* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0021 — Media Sharing Within Tenant](ADR-0021-Media-Sharing-Within-Tenant.md)

Related Specification:

* [LF-Assessment-Simple-Admin-Proposal](../quality/LF-Assessment-Simple-Admin-Proposal.md) — phân tích question_bank và LF, các quyết định Owner
* [LF-Core-Assessment](../core/LF-Core-Assessment.md)
* [database/assessment](../database/assessment/README.md)

---

# Context

ADR-0003 đã duyệt kiến trúc Assessment nhưng chưa có dòng code nào. Owner muốn
đưa module Kiểm tra vào LF với cách quản trị đơn giản như hệ thống question_bank
đang dùng: giáo viên nhập câu hỏi, gom câu theo đoạn đọc hoặc bài nghe, ghép đề,
giao cho học viên, xem kết quả và chấm Writing/Speaking.

Đối chiếu với question_bank cho thấy:

1. ADR-0003 đúng ở các nguyên tắc (tenant, snapshot, chấm ở server, file thuộc
   Media) và question_bank hỏng chính ở những chỗ đó.
2. ADR-0003 chỉ cho làm bài trong khoá học (Attempt bắt buộc Enrollment và
   Version Activity) và Quiz chỉ sinh ra khi gắn vào Course đã xuất bản. Owner
   chốt đề là **sản phẩm riêng**, có module và menu riêng, học viên đăng ký
   từng đề như question_bank; gắn vào khoá học tính sau.
3. ADR-0003 bắt buộc một số lớp (ngân hàng câu hỏi, chủ đề, rubric, phân công
   chấm, đa ngôn ngữ) mà question_bank cho thấy chưa cần để vận hành.

Cho làm bài ngoài Enrollment là thay đổi Foundation Decision của ADR-0003, nên
cần ADR mới trước database docs và migration.

---

# Phạm vi

**Giai đoạn 1 (ADR này):** danh mục, câu hỏi và ngữ liệu nhập bằng form; đề /
bài tập ghép thủ công; xuất bản phiên bản; ghi danh học viên vào đề (học viên tự
đăng ký miễn phí hoặc admin/giáo viên cấp); trang "Đề thi của tôi"; làm bài; tự
chấm; giáo viên chấm điểm và nhận xét; màn kết quả.

**Ngoài phạm vi (Owner chốt 2026-11-06):**

* Đề tổ hợp kiểu HSA: bỏ.
* Mọi tính năng AI (tạo câu hỏi, tách câu từ file đề, gợi ý chấm): bỏ khỏi đợt
  này.
* Đề động (chọn danh mục + số câu, hệ thống tự ghép): làm sau.
* Gói đề (một hoặc nhiều đề, có thời gian mở/đóng): làm sau.
* Gắn đề hoặc gói vào Course Template: làm sau.
* Thanh toán, bán đề hoặc gói: làm sau, dùng chung luồng đơn hàng và thanh
  toán với khoá học; không dựng cổng thanh toán riêng cho đề.
* Giao đề theo lớp: thuộc phía lớp học / khoá học, làm sau.
* Thang xếp loại (Giỏi/Khá…): làm sau.
* Chia sẻ câu hỏi/đề giữa giáo viên: giai đoạn 2, theo mô hình ADR-0021.
* Import/Export Excel, rubric, phân công chấm, chủ đề (tag), ngân hàng câu hỏi:
  làm sau.

---

# Decision

## D1 — Giữ các nguyên tắc nền của ADR-0003

Assessment vẫn là Evaluation Domain; mọi bản ghi có `customer_id`; câu hỏi là
nguồn soạn thảo sửa được; lần làm bài đọc snapshot bất biến; file thuộc Media;
Assessment chỉ sinh bằng chứng, không tự đổi trạng thái miền khác. ADR này chỉ
thay các điểm D2–D12.

## D2 — Kiểm tra là module riêng

Một mục **Kiểm tra** trên left menu ở khu admin (`/admin/assessments`) và khu
giáo viên (`/teacher/assessments`), gồm:

1. Danh mục
2. Câu hỏi
3. Ngữ liệu
4. Đề / Bài tập
5. Ghi danh
6. Kết quả & Chấm bài (có tab *Chờ chấm*)

Trên website tenant, mục **Assessments** (đã có trong menu Visitor và học viên
theo Guardrails) liệt kê các đề đang *Mở đăng ký*; khách chưa đăng nhập xem được
danh sách, muốn đăng ký hoặc làm bài phải đăng nhập. Học viên có trang **Đề thi
của tôi**. Không tạo portal học viên riêng.

## D3 — Danh mục là cách nhóm câu hỏi duy nhất ở giai đoạn 1

`core_assessment_categories` là cây danh mục của tenant, gắn trực tiếp vào câu
hỏi, ngữ liệu và đề (mỗi bản ghi một danh mục). Đây là "nhóm câu hỏi" mà đề
động sẽ dùng sau này. Không tạo ngân hàng câu hỏi (`question_banks`) và chủ đề
(`topics`, `question_topics`) ở giai đoạn 1.

## D4 — Ngữ liệu dùng chung

Thêm `core_assessment_passages`: một đoạn đọc, bài nghe hoặc video có nhiều câu
hỏi con. Câu hỏi có `passage_id` (nullable) và thứ tự trong ngữ liệu. Khi thêm
ngữ liệu vào đề, toàn bộ câu con đi cùng nhau và giữ thứ tự. Media gắn vào câu
hỏi hoặc ngữ liệu (đúng một trong hai).

## D5 — Dạng câu, kỹ năng, độ khó cố định

* `question_type`: `single_choice`, `multiple_choice`, `true_false`,
  `short_answer`, `essay`, `speaking`. Bỏ `listening` (đó là kỹ năng).
  `file_upload` làm sau.
* `skill_type`: `reading`, `listening`, `writing`, `speaking`, `other`.
* `difficulty_level`: `easy`, `medium`, `hard`.
* Mỗi câu một bản nội dung theo locale mặc định của tenant; giữ cột `locale`
  để mở rộng sau, giao diện không hiện chọn ngôn ngữ.

## D6 — Đề là tài sản độc lập, xuất bản thành phiên bản

* `core_assessment_quizzes` là **bản nháp đề** của tenant, có người tạo và danh
  mục, sửa được. Bỏ `product_id`, `template_version_id`, `version_activity_id`.
* Đề và bài tập là cùng một thực thể; khác nhau ở cấu hình: có hay không giới
  hạn thời gian (đếm ngược), số lượt làm, cách hiện kết quả. `assessment_type`
  (`exercise`, `exam`) chỉ là nhãn để lọc.
* Phần và câu trong bản nháp (`quiz_sections`, `quiz_questions`) là cấu trúc
  soạn thảo, không chứa snapshot. Đề có ít nhất một phần; đề không chia phần
  dùng một phần mặc định ẩn trên giao diện.
* Ghép đề giai đoạn 1 là **thủ công**: chọn câu lẻ hoặc ngữ liệu, đặt thứ tự và
  điểm từng câu (mặc định lấy điểm của câu).
* **Xuất bản** tạo một bản ghi bất biến trong `core_assessment_quiz_versions`
  (số phiên bản, thời gian làm bài, số lượt, điểm đạt, cách hiện kết quả, tổng
  điểm, người và thời điểm xuất bản) cùng các câu đã đóng băng trong
  `core_assessment_quiz_version_questions` (phần, thứ tự, điểm, snapshot nội
  dung câu, ngữ liệu, lựa chọn, đáp án đúng).
* Sửa đề sau khi xuất bản là sửa bản nháp; muốn áp dụng phải xuất bản lại, sinh
  phiên bản mới. Phiên bản cũ không đổi.
* Lifecycle bản nháp đề: `draft`, `published` (có ít nhất một phiên bản),
  `archived` (không ghi danh mới được).

## D7 — Ghi danh vào đề thay cho Enrollment

* Thêm `core_assessment_quiz_registrations`: một lần một **học viên** được quyền
  làm một đề. Ghi nguồn (`source`): `self_registered` (học viên tự đăng ký miễn
  phí) hoặc `granted` (admin/giáo viên cấp); sau này thêm `purchase` và nguồn từ
  khoá học. Có người cấp (nếu có), thời điểm, trạng thái `active` /
  `exhausted` (hết lượt) / `cancelled`.
* Học viên chỉ tự đăng ký được đề đã xuất bản và đang *Mở đăng ký*
  (`registration_open` trên đề). Admin/giáo viên cấp được mọi đề đã xuất bản
  mà mình có quyền, chọn nhiều học viên một lúc; mỗi học viên một bản ghi.
* Một học viên có tối đa một ghi danh `active` cho mỗi đề.
* "Đề thi của tôi" đọc từ bảng này.
* Lần làm bài có `quiz_registration_id`. `enrollment_id`, `version_activity_id`,
  `product_id`, `template_version_id` trở thành nullable, dành cho lúc gắn vào
  khoá học; mỗi lần làm bài phải có đúng một nguồn quyền (giai đoạn 1 luôn là
  ghi danh vào đề).
* Huỷ ghi danh chặn bắt đầu lần làm bài mới; không xoá lần làm bài đã có.
* Không có thời gian mở/đóng ở giai đoạn 1 (thuộc gói đề, làm sau).

## D8 — Số lượt làm bài

`max_attempts` nằm trong phiên bản đề. Số lượt tính theo **từng ghi danh**,
giống question_bank: mỗi lần ghi danh có đủ số lượt; dùng hết thì ghi danh chuyển
`exhausted` và học viên đăng ký lại (hoặc được cấp lại) để có lượt mới. Đang có
ghi danh `active` thì không đăng ký thêm. Kiểm tra trong transaction có khoá khi
bắt đầu lần làm bài.

## D9 — Quyền trong tenant

* **Admin (`customer_admin`)**: thấy và sửa mọi danh mục, câu hỏi, ngữ liệu, đề,
  ghi danh, kết quả của tenant.
* **Giáo viên (`teacher`)**: thấy và sửa câu hỏi, ngữ liệu, đề do mình tạo; khi
  sửa không đổi người tạo. Cấp được đề của mình cho học viên. Danh mục do admin
  quản lý, giáo viên chỉ chọn.
* **Học viên**: không thấy khu quản trị; thấy danh sách đề *Mở đăng ký*, đề mình
  đã ghi danh và lần làm bài của chính mình.
* Câu hỏi hoặc ngữ liệu đã nằm trong một phiên bản đề chỉ được lưu trữ, không
  xoá cứng. Đề đã có phiên bản chỉ được lưu trữ.

## D10 — Làm bài và chấm tự động hoàn toàn ở server

* Không gửi đáp án đúng, `is_correct` hay giải thích cho học viên trước khi lần
  làm bài kết thúc.
* Lần làm bài khoá phiên bản hiện hành của đề tại lúc bắt đầu; xuất bản phiên
  bản mới không đổi bài đang làm.
* Đề có giới hạn thời gian: giờ tính từ `started_at` của server; hết giờ thì
  lần làm bài kết thúc với phần đã lưu. Bài tập không giới hạn thời gian thì nộp
  khi học viên bấm nộp.
* Câu trả lời lưu theo từng câu trong lúc làm (mở lại thì làm tiếp) và phải
  thuộc đúng phiên bản đề của lần làm bài.
* Tự chấm từ snapshot: một đáp án và đúng/sai so khớp; nhiều đáp án đúng khi
  chọn đúng y tập đáp án; `short_answer` so với danh sách đáp án chấp nhận sau
  khi bỏ khoảng trắng thừa và không phân biệt hoa thường. Không có điểm từng
  phần.

## D11 — Chấm tay

* `essay` và `speaking` cần giáo viên chấm. Lần làm bài có câu cần chấm ở trạng
  thái chờ chấm cho tới khi mọi câu đó có điểm cuối.
* Giáo viên nhập điểm và nhận xét cho từng câu; server kiểm
  `0 ≤ điểm ≤ max_score`. Mỗi lần chấm hoặc chấm lại là một bản ghi
  `core_assessment_gradings`; tổng điểm lần làm bài tính lại từ điểm cuối của
  các câu.
* Người chấm được: admin của tenant; người tạo đề; người đã cấp đề cho học
  viên đó (với ghi danh `granted`).
* File ghi âm và bài nộp gắn theo câu trả lời (`answer_id`) qua Media.
* Câu essay / speaking bỏ trống (essay không có chữ, speaking không có bản ghi
  âm) được hệ thống chấm 0 điểm ngay khi nộp: một bản ghi
  `core_assessment_gradings` loại `system_auto`, không có người chấm; câu đó
  không vào Chờ chấm. Người chấm vẫn chấm lại được như mọi câu đã chấm
  (Owner, 2026-11-07).

## D12 — Kết quả hiển thị cho học viên

`show_result_mode` trong phiên bản đề: `immediately` (điểm và đáp án ngay khi
nộp), `after_grading` (sau khi chấm xong), `score_only` (chỉ điểm), `hidden`.
Đáp án đúng chỉ hiện ở `immediately`, hoặc ở `after_grading` khi đã chấm xong.

---

# Thay đổi so với ADR-0003

| ADR-0003 | ADR-0025 |
| --- | --- |
| Attempt bắt buộc Enrollment và Version Activity | Attempt có đúng một nguồn quyền; giai đoạn 1 là ghi danh vào đề |
| Quiz gắn 1-1 với Version Activity, bất biến khi publish | Đề nháp độc lập + phiên bản bất biến |
| `quiz_questions` chứa snapshot | Snapshot ở `quiz_version_questions` |
| Attempt tham chiếu `quiz_id` | Attempt tham chiếu `quiz_version_id`; Answer tham chiếu `quiz_version_question_id` |
| Question thuộc Question Bank, phân loại bằng Topic | Hoãn Bank và Topic; câu có một Danh mục |
| Không có ngữ liệu dùng chung | Thêm `passages` |
| `question_type` có `listening`, độ khó tự do | Danh sách cố định (D5) |
| Rubric, phân công chấm, AI trong Foundation | Hoãn |

Các Foundation Decisions khác của ADR-0003 giữ nguyên. Khi làm phần gắn khoá
học, quy tắc của ADR-0003 về Enrollment, Version Activity và việc Course tự
quyết hoàn thành áp dụng cho lần làm bài có nguồn quyền là khoá học.

---

# Consequences

* Bảng giai đoạn 1 (16): `categories`, `questions`, `question_contents`,
  `question_options`, `question_media`, `passages` (mới), `quizzes`,
  `quiz_sections`, `quiz_questions`, `quiz_versions` (mới),
  `quiz_version_questions` (mới), `quiz_registrations` (mới), `attempts`,
  `answers`, `answer_files`, `gradings`.
* Bảng hoãn (6): `question_banks`, `topics`, `question_topics`, `rubrics`,
  `rubric_items`, `grading_assignments`. Tài liệu của chúng giữ lại, đánh dấu
  hoãn.
* Sau khi duyệt phải cập nhật: `LF-Core-Assessment.md`,
  `database/assessment/README.md`, tài liệu các bảng bị đổi/thêm/hoãn, LF-INDEX,
  Manifest. Không đổi Course: hoạt động `quiz` và `assessment_quiz_id` giữ
  nguyên, template có quiz vẫn bị chặn xuất bản như hiện tại.
* Route và menu mới ở khu admin, giáo viên và website tenant: mức rà soát khi
  triển khai là HIGH ([LF-Regression-Audit](../quality/LF-Regression-Audit.md)).
* Một đề có nhiều ghi danh và nhiều phiên bản, nên thống kê theo đề gom qua cả
  hai.
* Route công khai mới (danh sách đề *Mở đăng ký* trên website tenant) phải chỉ
  trả thông tin giới thiệu đề, không trả câu hỏi hay đáp án.

---

# Alternatives Rejected

* **Giữ nguyên ADR-0003 (chỉ làm bài trong khoá học)**: trái quyết định Owner
  rằng đề là sản phẩm riêng.
* **Không snapshot, đọc câu hỏi hiện tại như question_bank**: sửa câu làm sai
  kết quả cũ; vi phạm Snapshot Principle.
* **Tách bài tập và đề thi thành hai thực thể**: trùng toàn bộ phần làm bài và
  chấm; khác biệt chỉ là cấu hình.
* **Đếm lượt cộng dồn theo (đề, học viên)**: không cho mua/đăng ký lại để có
  thêm lượt như question_bank.
* **Cổng thanh toán riêng cho đề**: tạo hệ thống bán hàng thứ hai cạnh khoá học.
* **Port schema question_bank**: ID dạng chuỗi, JSON trong cột text, không có
  `customer_id`; Owner đã loại.

---

# Owner Decisions (2026-11-06)

* Bỏ HSA và mọi tính năng AI khỏi đợt này.
* Chia sẻ giữa giáo viên: giai đoạn 2.
* Đề là sản phẩm riêng, module và menu riêng; gói đề và gắn khoá học làm sau.
* Như question_bank: học viên đăng ký từng đề, đề vào "Đề thi của tôi", làm
  đúng số lượt quy định; lượt tính theo mỗi lần ghi danh. Thời gian mở/đóng
  thuộc gói đề, làm sau.
* Chưa có thanh toán trong đợt này; đăng ký miễn phí hoặc admin/giáo viên cấp.
* Ghi danh trong module Kiểm tra là theo từng học viên; giao theo lớp thuộc phía
  lớp học, làm sau.
* Khách chưa đăng nhập xem được danh sách đề *Mở đăng ký*.
* Đề động là "chọn danh mục + số câu, hệ thống tự ghép"; làm sau.
* Người chấm: admin, người tạo đề, người cấp đề.
* Thang xếp loại: làm sau. Bán đề: ngoài phạm vi.

# Owner Decisions (2026-11-07)

* Câu tự luận / nói bỏ trống tự được 0 điểm khi nộp, không bắt giáo viên chấm
  ô trống (bổ sung D11).

---

# Owner Approval

```text
Role: LearnForge Architecture Owner
Date: 2026-11-06
Decision: Approved
```

Owner phê duyệt ADR trong hội thoại ngày 2026-11-06 sau khi đọc ADR này và
[LF-Assessment-Simple-Admin-Proposal](../quality/LF-Assessment-Simple-Admin-Proposal.md).
Database docs giai đoạn 1 được viết sau phê duyệt; Owner duyệt chúng trong hội
thoại cùng ngày 2026-11-06, không lập tài liệu Architecture Review riêng (như
ADR-0021).

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
