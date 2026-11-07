# ADR-0027 — Assessment Grader Assignment And Essay Files

Version: 1.2

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-07

Proposal Date: 2026-11-07

Approval Date: 2026-11-07

Approved By: LearnForge Architecture Owner

Document Path: adr/ADR-0027-Assessment-Grader-Assignment-And-Essay-Files.md

Related ADRs:

* [ADR-0003 — Assessment Foundation](ADR-0003-Assessment-Foundation.md) — ADR này thu gọn thiết kế `grading_assignments`
* [ADR-0025 — Assessment Simple Administration](ADR-0025-Assessment-Simple-Administration.md) — ADR này mở lại "phân công chấm" và tệp bài làm đang hoãn, sửa D9, D11
* [ADR-0026 — Assessment Rubric](ADR-0026-Assessment-Rubric.md)
* [ADR-0018 — Media PII And External Processing Boundary](ADR-0018-Media-PII-And-External-Processing-Boundary.md)

Related Specification:

* [LF-Assessment-Grader-Assignment-Architecture-Review](../quality/LF-Assessment-Grader-Assignment-Architecture-Review.md)
* [LF-Core-Assessment](../core/LF-Core-Assessment.md)
* [database/assessment](../database/assessment/README.md)

---

# Context

Module Kiểm tra (ADR-0025) cho admin, người tạo đề và người cấp đề chấm bài.
Bài học viên tự đăng ký vào đề của admin chỉ admin chấm được; trung tâm có
nhiều giáo viên chấm không chia việc được. question_bank tự chia bài cho giáo
viên "đang rảnh" và có lúc lấy cả giáo viên ngoài trung tâm; người ta không
biết vì sao bài về tay ai.

Câu tự luận của LF chỉ cho gõ chữ. Bài viết tay (ví dụ TOPIK viết) cần nộp
ảnh chụp hoặc tệp. question_bank nhận một tệp nhưng gắn tệp vào câu theo thứ tự
gửi lên, dễ lệch câu.

Owner chọn hướng trong hội thoại ngày 2026-11-07: giữ người chấm hiện có, thêm
giáo viên chấm theo danh mục, giao tay bất kỳ giáo viên nào, có "Nhận chấm";
giao diện linh hoạt, thông minh, dễ dùng.

---

# Decision

## D1 — Ba tầng người chấm

Một người dùng **chấm được** một bài làm khi thuộc một trong ba tầng:

1. **Như ADR-0025 D11** (giữ nguyên): `customer_admin`; người tạo đề; người
   cấp ghi danh của bài (`source = granted`).
2. **Giáo viên chấm của danh mục hoặc của đề**: giáo viên được admin gắn vào
   chính đề đó, vào danh mục của đề, hoặc vào một danh mục cha của nó (kế thừa
   xuống danh mục con).
3. **Người được giao**: giáo viên đang giữ bài theo D3.

Tầng 2 và 3 chỉ áp dụng cho `teacher` đang hoạt động cùng tenant. Quyền xét
theo dữ liệu hiện tại (danh mục hiện tại của đề, danh sách giáo viên chấm hiện
tại), không chụp vào phiên bản đề. Giáo viên thuộc tầng 2–3 thấy bài đó trong
Kết quả & Chấm bài và trang bài làm, không có thêm quyền sửa đề, câu hỏi hay
ghi danh.

## D2 — Giáo viên chấm của danh mục và của đề

* Bảng mới `core_assessment_grader_scopes`: mỗi dòng gắn một giáo viên vào
  **đúng một** phạm vi, hoặc một danh mục (`category_id`) hoặc một đề
  (`quiz_id`), kèm người gắn. Một giáo viên gắn một lần mỗi phạm vi.
* **Theo danh mục**: chỉ admin gắn / gỡ (Danh mục vốn chỉ admin quản lý).
* **Theo đề**: admin, và người tạo đề với đề của mình, gắn / gỡ.
* Người được gắn là bất kỳ `teacher` đang hoạt động của tenant.
* **Theo danh mục**: dùng cho chấm hằng ngày cả nhóm đề.
* **Theo đề** ("Giao chấm đề"): dùng cho đề cần người chấm riêng; có hiệu lực
  với mọi bài của đề, kể cả bài nộp sau này.
* Gắn ở danh mục cha có hiệu lực cho mọi danh mục con; trang Danh mục hiện rõ
  ai được kế thừa từ đâu.
* Gỡ giáo viên khỏi danh mục không xoá lịch sử chấm; bài đã giao cho họ (D3)
  vẫn giữ tới khi admin đổi.
* Danh mục hoặc đề lưu trữ vẫn giữ giáo viên chấm (bài cũ vẫn chấm được).

## D3 — Giao bài và Nhận chấm

* Mỗi bài làm có **tối đa một người đang giữ**. `core_assessment_grading_assignments`
  ghi từng lần giao: người giữ, người giao, cách giao (`assigned` do admin,
  `claimed` do giáo viên tự nhận), trạng thái `active` / `released` /
  `completed`, thời điểm kết thúc.
* **Giao chấm** (admin với mọi bài; người tạo đề với bài của đề mình): chọn
  một hoặc nhiều bài trong danh sách hoặc trên trang bài làm, chọn **bất kỳ
  giáo viên nào** của tenant. Chọn nhiều bài mà có bài người dùng không có quyền
  giao thì bài đó bị bỏ qua và được báo lại. Giao cho người khác
  thì lần giao cũ chuyển `released`.
* **Nhận chấm** (giáo viên tầng 1–2): bài chưa có người giữ thì giáo viên bấm
  nhận; bài thành của họ.
* **Trả lại** (người đang giữ, người có quyền giao bài đó): bỏ giữ, bài quay về
  chưa giao.
* Bài đã có người giữ: giáo viên tầng 1–2 khác **xem được, không chấm được**
  cho tới khi bài được trả lại hoặc được giao lại; trang hiện "Teacher X đang
  chấm". Admin và người tạo đề luôn chấm được.
* Chấm xong mọi câu chờ chấm thì lần giao `active` chuyển `completed`; người
  đó vẫn xem và chấm lại được bài này (tầng 3 tính cả `completed` mới nhất).
* Bài chỉ giao được khi đã nộp (`submitted` hoặc `graded`), không giao bài đang
  làm.
* Không có tự chia bài, không có hạn chấm, không có giao theo từng câu ở
  ADR này.

## D4 — Giao diện phân công (linh hoạt, dễ dùng)

* **Danh mục**: cột "Giáo viên chấm" (tên rút gọn, "+N"); popup chọn nhiều
  giáo viên có ô tìm; dòng kế thừa ghi "Từ: TOPIK".
* **Trang đề** (admin, người tạo đề): nút **Giao chấm đề** mở popup chọn nhiều giáo viên có ô tìm;
  phía dưới liệt kê (chỉ đọc) giáo viên đã chấm được nhờ danh mục, ghi "Từ danh
  mục: TOPIK I", để admin không chọn trùng. Danh sách đề hiện số giáo viên chấm.
* **Kết quả & Chấm bài**:
  * Cột "Người chấm": tên người giữ, hoặc "Chưa giao".
  * Bộ lọc "Người chấm": Tất cả / Của tôi / Chưa giao / từng giáo viên.
  * Giáo viên mở trang thì tab Chờ chấm lọc sẵn "Của tôi + Chưa giao" mà họ
    chấm được, đổi được.
  * Chọn nhiều bài bằng checkbox → nút **Giao chấm** trên thanh công cụ →
    popup chọn giáo viên. Popup **gợi ý thông minh**: đưa giáo viên chấm của
    danh mục lên đầu, hiện số bài mỗi người đang giữ chưa chấm xong để chọn
    người ít việc; có ô tìm.
  * Nút **Nhận chấm** trên dòng bài chưa giao (giáo viên).
* **Trang bài làm**: đầu trang hiện "Người chấm: …" kèm Giao chấm / Nhận chấm /
  Trả lại theo quyền.
* Theo UI Kit và quy ước Owner đã chốt (popup `ui-modal`, nút chính có dấu
  check, `data-ui-kit-select`, không nút chữ trong cột Thao tác).

## D5 — Bài tự luận nộp bằng tệp

* Câu `essay` có thiết lập **"Cho nộp tệp"** (`allow_file_answer`, mặc định
  tắt). Thiết lập được chụp vào `question_snapshot` khi xuất bản đề; sửa câu
  sau đó không đổi đề đã xuất bản.
* Bật thì học viên gõ chữ, nộp tệp, hoặc cả hai. Tối đa **3 tệp** mỗi câu.
  Định dạng: ảnh (JPG, PNG, WEBP, HEIC) và tài liệu (PDF, DOCX). Dung lượng
  theo giới hạn tải lên chung của Media.
* Tệp tải lên ngay khi chọn (như ghi âm), gắn theo `answer_id`, không theo thứ
  tự gửi. Học viên xoá được tệp khi bài còn mở; tệp không còn dùng thì bị xoá.
* `core_assessment_answer_files.file_role` thêm `essay_attachment`; unique đổi
  thành theo `(answer_id, media_file_id)`; service giữ "một bản ghi âm mỗi câu
  nói" và "tối đa 3 tệp mỗi câu tự luận". Media usage `owner_type =
  assessment_answer`, `usage_type = attachment`.
* Người chấm xem ảnh ngay trên trang chấm (bấm để phóng to), tải PDF / DOCX qua
  URL ký có hạn; quyền đọc theo quyền xem bài (D1), không qua thư viện Media.
* Tệp bài làm là dữ liệu cá nhân của học viên: không chạy OCR, trích xuất hay AI
  trên tệp này (ADR-0018).
* "Bỏ trống" của ADR-0025 D11 thành: câu tự luận không có chữ **và** không có
  tệp.
* Nhập / Xuất Excel câu hỏi thêm cột "Cho nộp tệp" (có / không); file cũ không
  có cột thì coi là không.

---

# Thay đổi so với ADR-0003 và ADR-0025

| Trước | ADR-0027 |
| --- | --- |
| ADR-0025: phân công chấm hoãn | Có: theo danh mục, theo đề, giao từng bài, nhận chấm |
| ADR-0003: assignment theo Attempt hoặc Answer, `pending`/`in_progress`/`completed`/`cancelled`, `due_at`, `metadata` | Chỉ theo Attempt; `active`/`released`/`completed`; thêm `assignment_type`, `ended_at`; bỏ `answer_id`, `due_at`, `metadata` |
| ADR-0025 D11: người chấm = admin, người tạo, người cấp | Thêm giáo viên chấm của danh mục và người được giao (D1) |
| ADR-0025: `file_role` chỉ `speaking_recording`, unique `(answer_id, file_role)` | Thêm `essay_attachment`; unique `(answer_id, media_file_id)` |
| ADR-0025 D11: tự luận bỏ trống = không có chữ | Không có chữ và không có tệp |

Dạng câu `file_upload` của ADR-0003 vẫn hoãn: nộp tệp là thiết lập của câu tự
luận, không phải dạng câu mới.

---

# Consequences

* Bảng mới: `core_assessment_grader_scopes`,
  `core_assessment_grading_assignments`. Cột mới:
  `core_assessment_questions.allow_file_answer`. Đổi ràng buộc
  `core_assessment_answer_files`. Dữ liệu cũ: không giáo viên chấm, không lần
  giao, mọi câu `allow_file_answer = false`; không cần chuyển đổi.
* Mọi truy vấn "bài tôi được xem / chấm" (danh sách, xuất Excel, Thống kê, đếm
  Chờ chấm, trang bài làm, nghe / xem tệp) đổi sang một hàm phạm vi chung trong
  `AssessmentGradingService`.
* Cập nhật: database docs các bảng liên quan và README miền,
  `LF-Core-Assessment.md`, LF-INDEX, Manifest, ADR README;
  `LF-SCHEMA-CONTRACT.json` sau migration.
* Đổi quyền đọc dữ liệu học viên của giáo viên và thêm route ở admin / giáo
  viên / học viên: rà soát HIGH theo
  [LF-Regression-Audit](../quality/LF-Regression-Audit.md).
* Giáo viên tầng 2 thấy tên, email và bài làm của học viên ngoài lớp mình trong
  danh mục được gắn: đó là chủ ý của admin khi gắn.

---

# Alternatives Rejected

* **Tự chia bài như question_bank** (giáo viên ít bài nhất): khó giải thích,
  dễ giao nhầm người nghỉ; thay bằng gợi ý ít việc trong popup để admin quyết.
* **Một người chấm mặc định duy nhất trên đề, tự giao bài khi nộp**: dồn hết
  bài cho một người; thay bằng nhóm giáo viên chấm của đề + Nhận chấm.
* **Hai bảng riêng cho danh mục và đề**: hai bộ quy tắc giống hệt nhau; một
  bảng có CHECK "đúng một phạm vi" gọn hơn.
* **Nhóm giáo viên riêng**: thêm một khái niệm mới; danh mục đã là cách nhóm đề.
* **Khoá cứng: chỉ người giữ mới thấy bài**: giáo viên khác trong nhóm không xem
  được tình hình; chọn "xem được, không chấm được".
* **Dạng câu `file_upload` mới**: thêm dạng câu, chấm, thống kê riêng; thiết lập
  trên câu tự luận đủ dùng.
* **Mọi câu tự luận đều nhận tệp**: đề gõ chữ (luyện gõ tiếng Hàn) không muốn
  nhận ảnh.

---

# Owner Decisions (2026-11-07)

* Giữ người chấm hiện có (admin, người tạo đề, người cấp đề).
* Admin gắn giáo viên chấm theo danh mục. Admin và người tạo đề giao chấm đề
  ("Giao chấm đề") và giao từng bài cho bất kỳ giáo viên nào; người tạo đề chỉ
  với đề của mình. Có Nhận chấm.
* Giao diện phân công linh hoạt, thông minh, dễ dùng.
* "Cho nộp tệp" bật theo từng câu tự luận, tối đa 3 tệp.

---

# Owner Approval

```text
Role: LearnForge Architecture Owner
Date: 2026-11-07
Decision: Approved
```

Owner chọn các quyết định trên trong hội thoại ngày 2026-11-07 rồi duyệt ADR này
cùng database docs và
[LF-Assessment-Grader-Assignment-Architecture-Review](../quality/LF-Assessment-Grader-Assignment-Architecture-Review.md)
("Duyệt, làm luôn") cùng ngày. Migration `2026_11_07_000100` đã kiểm trên
MariaDB 11.4.

---

# Amendment 1.1 — Tự nhận chấm khi lưu điểm (Approved 2026-11-07)

Chỗ hở của D3: bài chưa ai giữ thì hai giáo viên tầng 1–2 cùng mở và cùng chấm
được; điểm lưu sau thành điểm hiện hành, công chấm của người trước bị trùng.
Owner duyệt bịt chỗ hở này ngày 2026-11-07 ("bịt chỗ hở, test lại luồng này").

* Giáo viên tầng 1–2 lưu điểm một câu của bài **đang chờ chấm** mà bài **chưa
  ai giữ** thì hệ thống ghi luôn một lần giao `claimed` cho người đó, trong cùng
  giao dịch chấm (khoá dòng Attempt), như khi bấm "Nhận chấm". Từ đó giáo viên
  khác chỉ xem được, như D3.
* Không tự nhận khi người lưu là admin hoặc người tạo đề (họ luôn chấm được và
  không giữ bài của người khác), khi bài đã chấm xong (chấm lại), hoặc khi bài
  đã có người giữ.
* Chấm xong mọi câu chờ chấm thì lần giao chuyển `completed` như D3; chưa xong
  thì người đó vẫn giữ cho tới khi xong, tự Trả lại, hoặc được giao lại.
* Như mọi lần giao `completed` (D3, tầng 3), giáo viên đã chấm bài vẫn xem và
  chấm lại được bài đó kể cả khi sau này bị bỏ khỏi danh sách chấm của danh mục
  hoặc đề; các bài khác thì mất quyền như cũ.
* Không đổi schema: dùng `assignment_type = claimed` đã có.

---

# Amendment 1.2 — Giao chấm theo đề ở danh sách Đề / Bài tập (Approved 2026-11-07)

Owner thấy quá nhiều chỗ giao chấm (Danh mục, trang đề, Kết quả) và đề nghị
chỉ giao ở danh sách Đề / Bài tập (2026-11-07). Phân tích và rà hồi quy ở
[LF-Assessment-Quiz-Grader-Bulk-Proposal](../quality/LF-Assessment-Quiz-Grader-Bulk-Proposal.md).
Owner duyệt ngày 2026-11-07 ("Duyệt, có chép"): chép gán danh mục sang gán đề
trước khi bỏ. Phần dưới thay D1–D4 ở những điểm nêu ra.

* **D1 tầng 2** chỉ còn "giáo viên chấm của đề"; bỏ "của danh mục" và kế thừa
  danh mục cha.
* **D2**: bỏ gắn theo danh mục. Gắn theo đề làm ở danh sách Đề / Bài tập: chọn
  một hay nhiều đề (lọc danh mục rồi chọn tất cả để giao cả loạt), popup chọn
  nhiều giáo viên, lưu **Thêm vào** (mặc định) hoặc **Thay thế**. Admin mọi
  đề; người tạo đề chỉ đề của mình, đề khác trong lựa chọn bị bỏ qua và báo
  lại. Trang đề giữ nút Giao chấm đề mở cùng popup cho một đề.
* **D3**: bỏ **Giao chấm từng bài** (`assigned`). Giữ Nhận chấm, Trả lại (người
  giữ, admin, người tạo đề) và tự nhận khi lưu điểm (Amendment 1.1). Lần giao
  `assigned` đang `active` giữ hiệu lực tới khi xong hoặc Trả lại.
* **D4**: bỏ cột và popup giáo viên chấm ở Danh mục, bỏ nút / popup Giao chấm ở
  Kết quả và trang bài làm. Danh sách đề có cột **Người chấm** (số giáo viên,
  cảnh báo "Chưa có").
* Dữ liệu: gán theo danh mục đang có được chép thành gán theo đề cho mọi đề
  của danh mục và danh mục con trước khi tắt (lệnh một lần, báo số dòng). Không
  migration; `grader_scopes.category_id` thôi ghi mới, dọn sau bằng database
  docs riêng.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
