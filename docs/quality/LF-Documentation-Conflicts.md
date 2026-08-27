# LearnForge Documentation Conflict Register

Version: 1.6

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-27

Document Path: quality/LF-Documentation-Conflicts.md

---

# Purpose

Register chính thức để ghi nhận, theo dõi và lưu bằng chứng resolution cho các
xung đột đã được xác minh giữa Governance, ADR, Domain Policy, Database
Documentation, Quality Review và implementation của LearnForge.

Register này không phải Governance source, không tạo business/architecture
policy, không chọn bên thắng và không hợp thức hóa inconsistency. Khi cùng một
concern có yêu cầu đối nghịch, implementation phải dừng cho tới khi authority
phù hợp phê duyệt resolution.

---

# Scope

Áp dụng khi rà soát:

* Governance ↔ ADR.
* ADR ↔ Domain Policy.
* Domain Policy ↔ Database Documentation.
* Database Documentation ↔ migration/schema/implementation.
* Approved/Frozen Quality Review ↔ canonical source hiện hành.
* LF-INDEX routing ↔ file thực tế.
* Document Status ↔ Implementation Status.
* Terminology, supersession và amendment synchronization.

Register chỉ theo dõi inconsistency đã được phân loại. Nó không thay thế ADR,
Architecture Review, Regression Audit, issue tracker hoặc implementation test.

---

# What Counts as a Documentation Conflict

Một item chỉ là `CONFLICT` khi hai hoặc nhiều nguồn có thẩm quyền mô tả cùng
một concern nhưng đưa ra các yêu cầu không thể đồng thời thỏa mãn.

Ví dụ: hai source of truth khác nhau cho cùng state; ADR và schema yêu cầu các
lifecycle không tương thích; hai tài liệu Approved/Frozen định nghĩa cùng một
term theo hai nghĩa loại trừ nhau; hoặc Quality Review khóa contract khác với
ADR canonical hiện hành.

Không kết luận conflict dựa trên keyword, khác wording, thiếu implementation,
hoặc một nguồn chưa được đọc trực tiếp. Người đăng ký phải trích được yêu cầu
trung lập của từng phía và giải thích vì sao chúng không thể cùng đúng.

---

# Classification Model

| Classification | Meaning |
| --- | --- |
| `CONFLICT` | Hai nguồn chính thức quy định cùng concern theo cách không thể đồng thời đúng |
| `GAP` | Chưa có tài liệu hoặc chưa quy định đủ |
| `AMBIGUITY` | Nội dung tồn tại nhưng có nhiều cách hiểu |
| `STALE` | Nội dung có bằng chứng không còn phản ánh trạng thái mới |
| `IMPLEMENTATION_DRIFT` | Code/schema không phù hợp tài liệu, nhưng các tài liệu không xung đột nhau |
| `DUPLICATION` | Nội dung lặp, chưa có bằng chứng về yêu cầu đối nghịch |
| `ROUTING_GAP` | Tài liệu tồn tại nhưng catalog/routing chưa dẫn tới nó |
| `UNVERIFIED` | Có dấu hiệu bất thường nhưng chưa đủ bằng chứng để phân loại |

Không dùng `conflict` làm nhãn chung cho missing policy, deferred scope,
superseded history hoặc implementation chưa tồn tại.

---

# Status Lifecycle

Conflict ID có dạng tuần tự và ổn định:

```text
DOC-CONFLICT-0001
DOC-CONFLICT-0002
```

ID đã cấp không được tái sử dụng, kể cả record đã resolved hoặc invalidated.

| Status | Meaning |
| --- | --- |
| `OPEN` | Conflict đã xác minh, resolution chưa bắt đầu |
| `UNDER_REVIEW` | Owner/authority đang phân tích |
| `DECISION_REQUIRED` | Cần quyết định nghiệp vụ hoặc kiến trúc có thẩm quyền |
| `RESOLVED` | Canonical sources đã đồng bộ và có verification evidence |
| `ACCEPTED_TEMPORARILY` | Chấp nhận tạm; bắt buộc có owner, lý do và Target Review Date |
| `INVALIDATED` | Điều tra xác định không phải conflict; giữ nguyên lý do và lịch sử |

Không dùng `RESOLVED` chỉ vì implementation chọn một nhánh. Mọi canonical
source liên quan phải được cập nhật hoặc có supersession/backlink rõ ràng.

---

# Conflict Impact

`Conflict Impact` độc lập với Audit Level, Finding Severity và Final Verdict.

| Impact | Meaning |
| --- | --- |
| `BLOCKER` | Không thể triển khai/kết luận an toàn vì source of truth hoặc foundation chưa xác định |
| `HIGH` | Nguy cơ với tenant/auth, historical data, lifecycle, public contract, schema hoặc architecture boundary |
| `MEDIUM` | Ảnh hưởng behavior trong một module/flow, chưa chạm foundation |
| `LOW` | Wording, terminology hoặc routing có thể gây hiểu nhầm nhưng chưa đổi runtime behavior |

Impact thấp không cho phép bỏ qua resolution.

---

# Detection and Registration Workflow

1. Phát hiện dấu hiệu inconsistency.
2. Đọc trực tiếp mọi source được cho là đối nghịch, gồm section/heading liên quan.
3. Xác nhận các source nói về cùng concern và không thể đồng thời đúng.
4. Phân loại `CONFLICT` hoặc một classification không phải conflict.
5. Tìm duplicate record theo concern/source trước khi cấp ID mới.
6. Cấp Conflict ID kế tiếp và điền toàn bộ schema bắt buộc.
7. Thêm record vào Active Conflict Register.
8. Dừng implementation chỉ trong affected concern và thông báo authority.

Search result, automated warning hoặc implementation khác documentation là đầu
mối điều tra, không tự động là `CONFLICT`.

---

# Temporary Safety Rule

Mặc định cho mọi confirmed conflict:

```text
STOP implementation for the affected concern. Do not guess.
```

Documentation priority không tự giải quyết hai yêu cầu đối nghịch về cùng một
concern. Không triển khai theo một phía cho tới khi có approved decision.

---

# Resolution Workflow

```text
Detect
→ Verify both sources
→ Classify
→ Register
→ Stop affected implementation
→ Assign resolution authority
→ Approve decision
→ Update canonical documents
→ Update superseded/backlinks/routing
→ Verify implementation impact
→ Run documentation checks
→ Record evidence
→ Mark RESOLVED
```

Resolution phải ghi decision được duyệt, source được sửa, source
superseded/archived, implementation impact, test/lint đã chạy, commit/PR/ADR
hoặc review evidence và remaining risks. Record không bị xóa sau resolution;
nó được chuyển sang Resolved Conflict Register.

---

# Resolution Authority

Chọn authority theo concern thực tế:

| Concern | Resolution Authority |
| --- | --- |
| Architecture, foundation, cross-domain source of truth | Architecture Team |
| Domain lifecycle hoặc business responsibility | Domain Owner |
| Product/business rule | Product/Business Owner |
| Physical schema, constraint hoặc migration contract | Database Owner cùng authority của policy liên quan |
| Authentication, tenant isolation hoặc ownership | Security/Tenant Owner và Architecture Team khi chạm boundary |

Không bịa tên cá nhân, owner hoặc deadline. Nếu authority chưa xác định, Status
phải là `DECISION_REQUIRED` và concern vẫn bị STOP.

---

# Conflict Record Template

Template chỉ chứa placeholder; không phải conflict record thật.

```text
Conflict ID: DOC-CONFLICT-NNNN
Title: <neutral title>
Classification: CONFLICT
Status: OPEN | UNDER_REVIEW | DECISION_REQUIRED | RESOLVED | ACCEPTED_TEMPORARILY | INVALIDATED
Impact: BLOCKER | HIGH | MEDIUM | LOW
Detected At: YYYY-MM-DD
Detected By: <role/team/tool; do not invent a person>
Owner: <authorized role/team or Unassigned>
Affected Domain: <domain/cross-cutting>
Affected Concern: <single concern>
Sources In Conflict:
Source A: <repository/path.md#section>
Source B: <repository/path.md#section>
Additional Sources: <repository paths or None>
Contradictory Requirements:
- Source A requires: <neutral statement>
- Source B requires: <neutral statement>
Why They Cannot Both Be True: <verified explanation>
Runtime/Business Impact: <impact or None verified>
Affected Implementation: <paths/components or None verified>
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: <decision needed>
Resolution Authority: <authorized role/team>
Resolution Plan: <steps; do not choose an outcome prematurely>
Target Review Date: YYYY-MM-DD | Not set
Resolved At: YYYY-MM-DD | Not resolved
Resolution: <approved decision/evidence or Not resolved>
Superseded/Updated Documents: <paths or None>
Verification Evidence: <commands/tests/review/commit or None>
Related ADR/Review/Issue/PR: <references or None>
Notes: <history and remaining risks>
```

`Sources In Conflict` phải dùng path repository chính xác và ưu tiên heading
ổn định thay cho line number. `Contradictory Requirements` phải mô tả từng phía
trung lập.

---

# Active Conflict Register

Active items: 9. DOC-CONFLICT-0016 và DOC-CONFLICT-0017 đóng 2026-08-25;
DOC-CONFLICT-0018 và DOC-CONFLICT-0019 đóng 2026-08-27 bằng đợt amendment tài
liệu của miền Media. Còn **DOC-CONFLICT-0020** đang mở: bốn CHECK của
`media_processing_jobs` không tồn tại vật lý, và Owner chưa chọn giữa "tạo mới
CHECK" và "sửa § Keys". Nó chặn migration thứ ba, không chặn phần tài liệu.

| ID | Title | Classification | Status | Impact | Domain | Owner | Target Review |
| --- | --- | --- | --- | --- | --- | --- | --- |
| DOC-CONFLICT-0006 | Recording table doc không khớp migration | IMPLEMENTATION_DRIFT | ACCEPTED_TEMPORARILY | MEDIUM | LiveClass × Media | Database Owner | Trước khi phát triển tab Ghi hình |
| DOC-CONFLICT-0007 | Attendance table doc không khớp migration | IMPLEMENTATION_DRIFT | ACCEPTED_TEMPORARILY | MEDIUM | LiveClass | Database Owner | Trước khi phát triển tab Điểm danh |
| DOC-CONFLICT-0008 | Attendance/Recording chưa có Architecture Review chuyên biệt | GAP | ACCEPTED_TEMPORARILY | MEDIUM | LiveClass | Architecture Team | Trước khi phát triển hai tab |
| DOC-CONFLICT-0011 | Attendance ghi được cho Enrollment không `active` | IMPLEMENTATION_DRIFT | ACCEPTED_TEMPORARILY | LOW (giảm từ HIGH) | LiveClass × Course | Domain Owner (LiveClass) | Trước khi mở lại tab Điểm danh |
| DOC-CONFLICT-0014 | `course_category` được dùng làm `owner_type` nhưng không tài liệu nào đặt tên nó | IMPLEMENTATION_DRIFT | DECISION_REQUIRED | LOW | Media × Course | Domain Owner (Media) | Immediate Owner decision |
| DOC-CONFLICT-0015 | `owner_type` không có ràng buộc vật lý nên vocabulary trôi không bị phát hiện | GAP | DECISION_REQUIRED | MEDIUM | Media | Database Owner | Immediate, after 0014 |
| DOC-CONFLICT-0016 | Revision identity của `media_table_cells` mâu thuẫn với ADR-0019 § D2 | DOCUMENT_CONTRADICTION | RESOLVED | MEDIUM | Media | Architecture Owner | Đóng 2026-08-25 |
| DOC-CONFLICT-0017 | `extraction_method` của đọc cell trực tiếp có hai tên trong hai bảng | DOCUMENT_CONTRADICTION | RESOLVED | MEDIUM | Media | Domain Owner (Media) | Đóng 2026-08-25 |
| DOC-CONFLICT-0018 | Processing Contract § 4 chưa mở locator sang `sheet`/`region` dù cùng tài liệu đã có resource control cho region | DOCUMENT_CONTRADICTION | RESOLVED | MEDIUM | Media | Architecture Owner | Đóng 2026-08-27 |
| DOC-CONFLICT-0019 | `job_type`/`output_type` không có giá trị nào chứa được một revision structured | GAP | RESOLVED | HIGH | Media | Architecture Owner | Đóng 2026-08-27 |
| DOC-CONFLICT-0020 | Bốn CHECK trong doc `media_processing_jobs` không tồn tại trong schema vật lý | IMPLEMENTATION_DRIFT | DECISION_REQUIRED | MEDIUM | Media | Database Owner | Trước migration job_type |

---

## DOC-CONFLICT-0006

```text
Conflict ID: DOC-CONFLICT-0006
Title: Recording table documentation không khớp migration đã chạy
Classification: IMPLEMENTATION_DRIFT
Status: ACCEPTED_TEMPORARILY
Impact: MEDIUM
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Database Owner
Affected Domain: LiveClass × Media
Affected Concern: Hợp đồng vật lý của core_liveclass_recordings
Sources In Conflict:
Source A: docs/database/liveclass/core_liveclass_recordings.md#fields
Source B: database/migrations/2026_07_25_010000_create_cohort_liveclass_operations.php
Additional Sources: docs/adr/ADR-0002-LiveClass-Foundation.md#media-integration
Contradictory Requirements:
- Source A requires: room_id, product_id, template_version_id, version_activity_id NOT NULL; transcript_media_id, subtitle_media_id, available_at, metadata tồn tại; status default `processing`; tập status là processing/ready/failed/archived/deleted
- Source B requires: không có bảy cột trên; có thêm provider, replay_available_from, replay_available_until, visibility; status default `pending`
Why They Cannot Both Be True: Không áp dụng — đây là drift giữa tài liệu và schema, hai tài liệu không xung đột với nhau
Runtime/Business Impact: `pending` nằm ngoài tập status được duyệt và đã lan vào lớp ngôn ngữ (khóa LF_course_cohort_recording_status_pending). media_file_id có FK nhưng không đường code nào ghi, nên ranh giới Media của ADR-0002 Rule 5 hiện chỉ tồn tại trên giấy
Affected Implementation: app/Http/Controllers/CourseCohortOperationController.php::storeRecording, resources/views/course-cohorts/partials/tabs/recordings.blade.php
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Viết lại hợp đồng bảng cho khớp Foundation hiện hành, và chốt `pending` hợp lệ hay phải chuẩn hóa về `processing`
Resolution Authority: Database Owner cùng Domain Owner (LiveClass)
Resolution Plan: Hoãn cho tới khi tab Ghi hình được phát triển; khi đó viết lại § Fields, chốt tập status, và thiết kế đường import Media
Target Review Date: Not set — gắn với thời điểm phát triển tab Ghi hình
Resolved At: Not resolved
Resolution: Not resolved
Superseded/Updated Documents: None
Verification Evidence: None
Related ADR/Review/Issue/PR: ADR-0002 Media Integration; ADR-0004
Notes: Chấp nhận tạm theo quyết định phạm vi ngày 2026-08-10 — tab Ghi hình được xác nhận là chưa phát triển nên không sửa code lẫn hợp đồng trong đợt này. Rủi ro tồn dư: bảng đang nhận dữ liệu thật qua UI admin trong khi hợp đồng chưa chuẩn.
```

## DOC-CONFLICT-0007

```text
Conflict ID: DOC-CONFLICT-0007
Title: Attendance table documentation không khớp migration đã chạy
Classification: IMPLEMENTATION_DRIFT
Status: ACCEPTED_TEMPORARILY
Impact: MEDIUM
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Database Owner
Affected Domain: LiveClass
Affected Concern: Hợp đồng vật lý của core_liveclass_attendances
Sources In Conflict:
Source A: docs/database/liveclass/core_liveclass_attendances.md#fields
Source B: database/migrations/2026_07_25_010000_create_cohort_liveclass_operations.php
Additional Sources: None
Contradictory Requirements:
- Source A requires: room_id, product_id, template_version_id NOT NULL; attendance_method NOT NULL default provider; provider_participant_id và metadata tồn tại; version_activity_id NOT NULL
- Source B requires: không có năm cột đầu; version_activity_id nullable; có thêm attendance_mode, notes, recorded_by
Why They Cannot Both Be True: Không áp dụng — drift giữa tài liệu và schema
Runtime/Business Impact: duration_seconds và attendance_percentage tồn tại nhưng không đường code nào ghi, luôn giữ giá trị mặc định 0. Consumer tương lai đọc attendance_percentage sẽ kết luận sai rằng học viên không tham gia
Affected Implementation: app/Http/Controllers/CourseCohortOperationController.php::saveAttendance
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Viết lại § Fields cho khớp schema, và chốt producer cho duration_seconds/attendance_percentage hoặc ghi rõ manual attendance không sinh hai giá trị này
Resolution Authority: Database Owner cùng Domain Owner (LiveClass)
Resolution Plan: Hoãn cho tới khi tab Điểm danh được phát triển
Target Review Date: Not set — gắn với thời điểm phát triển tab Điểm danh
Resolved At: Not resolved
Resolution: Not resolved
Superseded/Updated Documents: None
Verification Evidence: None
Related ADR/Review/Issue/PR: ADR-0002 Course Integration
Notes: Chấp nhận tạm theo quyết định phạm vi ngày 2026-08-10. Lưu ý version_activity_id nullable là ĐÚNG theo Cohort-Centered Amendment 2026-07-25; chính tài liệu mới là bên lỗi thời ở điểm này.
```

## DOC-CONFLICT-0008

```text
Conflict ID: DOC-CONFLICT-0008
Title: Attendance và Recording chưa có Architecture Review chuyên biệt
Classification: GAP
Status: ACCEPTED_TEMPORARILY
Impact: MEDIUM
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Architecture Team
Affected Domain: LiveClass
Affected Concern: Review gate cho Attendance và Recording
Sources In Conflict:
Source A: docs/LF-INDEX.md#3-cohort--liveclass
Source B: docs/quality/
Additional Sources: docs/governance/LF-Architecture-Review-Checklist.md
Contradictory Requirements:
- Source A requires: LF-INDEX ghi nhận Attendance và Recording "Chưa có review chuyên biệt"
- Source B requires: docs/quality/ không chứa review nào cho hai chức năng này
Why They Cannot Both Be True: Không áp dụng — đây là khoảng trống, không phải xung đột. LF-INDEX mô tả đúng hiện trạng
Runtime/Business Impact: Hai chức năng đã có code chạy và route mở cho customer_admin nhưng chưa từng qua Architecture Review gate. Cụm phát hiện DOC-CONFLICT-0006, 0007 và 0011 đều tập trung ở đây
Affected Implementation: app/Http/Controllers/CourseCohortOperationController.php (saveAttendance, storeRecording), CourseCohortController (attendanceTabData, recordingTabData)
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Thực hiện Architecture Review cho Attendance và Recording trước khi phát triển hai tab
Resolution Authority: Architecture Team
Resolution Plan: Chạy Architecture Review Checklist cho hai chức năng ngay trước pha phát triển; đưa 0006, 0007, 0011 vào phạm vi review đó
Target Review Date: Not set — gắn với thời điểm phát triển hai tab
Resolved At: Not resolved
Resolution: Not resolved
Superseded/Updated Documents: None
Verification Evidence: None
Related ADR/Review/Issue/PR: ADR-0002; LF-Architecture-Review-Checklist.md
Notes: Chấp nhận tạm theo quyết định phạm vi ngày 2026-08-10.
```

## DOC-CONFLICT-0011

```text
Conflict ID: DOC-CONFLICT-0011
Title: Attendance ghi được cho Enrollment không còn active
Classification: IMPLEMENTATION_DRIFT
Status: ACCEPTED_TEMPORARILY
Impact: LOW (giảm từ HIGH sau khi endpoint bị khóa fail-closed ngày 2026-08-10)
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Domain Owner (LiveClass)
Affected Domain: LiveClass × Course
Affected Concern: Điều kiện Enrollment khi tạo Attendance
Sources In Conflict:
Source A: docs/core/LF-Core-LiveClass.md#rule-3--enrollment-binding
Source B: app/Http/Controllers/CourseCohortOperationController.php::saveAttendance
Additional Sources: docs/adr/ADR-0002-LiveClass-Foundation.md#course-integration; docs/database/liveclass/core_liveclass_attendances.md#business-rules; docs/core/LF-Core-Course.md (Enrollment lifecycle)
Contradictory Requirements:
- Source A requires: Enrollment phải `active` khi tạo Attendance record
- Source B requires: chỉ kiểm membership `active`, không kiểm trạng thái Enrollment
Why They Cannot Both Be True: Không áp dụng — code không thực thi một quy tắc đã được ba tài liệu quy định
Runtime/Business Impact: LF-Core-Course quy định rõ Enrollment transition KHÔNG làm thay đổi Membership, nên trạng thái "membership active + enrollment cancelled" là hành vi thiết kế chứ không phải bất thường. Attendance tạo trong trạng thái đó là evidence gắn vào một learning cycle đã chấm dứt, và sẽ được Course Domain đọc khi recalculation
Affected Implementation: CourseCohortOperationController::saveAttendance:521-527; CourseCohortController::attendanceTabData:536-550
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Đã chốt 2026-08-10 — phương án (B): khóa tab Điểm danh cho tới khi chức năng được phát triển
Resolution Authority: Domain Owner (LiveClass)
Resolution Plan: Khóa endpoint ghi và trạng thái tab bằng một cờ cấu hình phía server; giữ nguyên dữ liệu đã ghi nhận; mở lại cùng lúc với việc thiết kế lại chức năng dưới DOC-CONFLICT-0008, và bắt buộc sửa điều kiện Enrollment trước khi mở
Target Review Date: Not set — gắn với thời điểm phát triển tab Điểm danh
Resolved At: Not resolved
Resolution: Not resolved — đường tới lỗi đã bị đóng, nhưng khiếm khuyết gốc vẫn còn nguyên trong code
Superseded/Updated Documents: docs/database/course/core_course_cohorts.md § Feature Availability Lock Amendment — 2026-08-10
Verification Evidence: config/liveclass.php `attendance_enabled` mặc định false; CourseCohortOperationController::saveAttendance abort 403 fail-closed trước mọi kiểm tra khác; tests/Feature/CourseCohortManagementTest::test_attendance_and_recording_are_locked_at_the_server_while_unfinished; `php artisan test`
Related ADR/Review/Issue/PR: DOC-CONFLICT-0008
Notes: Cố ý KHÔNG dùng RESOLVED. Điều kiện thiếu (`enrollments.status = 'active'`) vẫn chưa được thêm vào saveAttendance — chỉ có đường tới nó bị đóng. Bật lại cờ mà chưa sửa sẽ làm lỗi ghi evidence cho learning cycle đã đóng quay lại nguyên vẹn; đây là điều kiện chặn bắt buộc của pha phát triển tab Điểm danh. Câu hỏi `suspended` có được điểm danh hay không vẫn chưa chốt.
```

---

## DOC-CONFLICT-0014

```text
Conflict ID: DOC-CONFLICT-0014
Title: `course_category` được dùng làm `owner_type` nhưng không tài liệu nào đặt tên nó
Classification: IMPLEMENTATION_DRIFT
Status: DECISION_REQUIRED
Impact: LOW
Detected At: 2026-08-23
Detected By: Independent review — Media Processing readiness audit
Owner: Domain Owner (Media)
Affected Domain: Media × Course
Affected Concern: Vocabulary hợp lệ của media_file_usages.owner_type
Sources In Conflict:
Source A: docs/database/media/media_file_usages.md#business-rules
Source B: app/Http/Controllers/CourseCategoryController.php
Additional Sources: docs/platform/LF-Media.md; docs/adr/ADR-0004-Media-Foundation.md
Contradictory Requirements:
- Source A requires: `owner_type` chỉ nhận các giá trị được liệt kê, và `course_category` không nằm trong đó
- Source B requires: Course Category attach thumbnail và banner bằng `owner_type = course_category`
Why They Cannot Both Be True: Không áp dụng — hai tài liệu không mâu thuẫn nhau. Code ghi một giá trị mà không tài liệu có thẩm quyền nào cho phép, khác hẳn DOC-CONFLICT-0013 nơi giá trị đã được hai ADR phê duyệt
Runtime/Business Impact: None verified. Thumbnail và banner của Category hoạt động bình thường; rủi ro là vocabulary tăng thêm mà không qua phê duyệt, và mọi consumer đọc theo danh sách tài liệu sẽ bỏ sót giá trị này
Affected Implementation: app/Http/Controllers/CourseCategoryController.php:60,129,130,442,455; media_file_usages — 3 dòng `course_category` trong database development
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: `course_category` có phải giá trị `owner_type` hợp lệ không. Nếu có thì bổ sung vào danh sách canonical và nêu trong LF-Media.md; nếu không thì quyết định xử lý ba dòng hiện có và đường ghi trong CourseCategoryController
Resolution Authority: Domain Owner (Media)
Resolution Plan: Không tự bổ sung vào danh sách. Trình Owner cùng lúc với DOC-CONFLICT-0015 vì hai việc chia chung nguyên nhân
Target Review Date: Immediate Owner decision — substrate đã chạy trên development
Resolved At: Not resolved
Resolution: Not resolved
Superseded/Updated Documents: None
Verification Evidence: `SELECT owner_type, COUNT(*) FROM media_file_usages GROUP BY owner_type` trên learnforge_db trả về `course_category` = 3; `grep -rn "course_category" docs/platform/LF-Media.md docs/database/media/ docs/adr/ADR-0004-Media-Foundation.md` không có kết quả
Related ADR/Review/Issue/PR: DOC-CONFLICT-0013; DOC-CONFLICT-0015
Notes: Cố ý KHÔNG gộp vào DOC-CONFLICT-0013. Hai giá trị cùng vắng mặt trong một danh sách nhưng có tư cách hoàn toàn khác nhau: một cái đã được ADR phê duyệt và chỉ thiếu ở table doc, một cái chưa từng được phê duyệt ở đâu. Gộp lại sẽ khiến việc sửa doc lần này trông như đã hợp thức hóa cả hai. Câu Owner cần trả lời: **`course_category` có phải canonical `owner_type` hợp lệ không — Có hay Không?**
```

---

## DOC-CONFLICT-0015

```text
Conflict ID: DOC-CONFLICT-0015
Title: `owner_type` không có ràng buộc vật lý nên vocabulary trôi không bị phát hiện
Classification: GAP
Status: DECISION_REQUIRED
Impact: MEDIUM
Detected At: 2026-08-23
Detected By: Independent review — Media Processing readiness audit
Owner: Database Owner
Affected Domain: Media
Affected Concern: Thi hành vocabulary của media_file_usages.owner_type
Sources In Conflict:
Source A: docs/database/media/media_file_usages.md#business-rules
Source B: learnforge_db — information_schema.CHECK_CONSTRAINTS
Additional Sources: docs/database/learning/core_learning_node_mappings.md#constraints-and-indexes
Contradictory Requirements:
- Source A requires: `owner_type` chỉ nhận một tập giá trị đóng
- Source B requires: Không áp dụng — không tồn tại ràng buộc nào trên cột này
Why They Cannot Both Be True: Không áp dụng — đây là GAP. Một tập đóng chỉ được mô tả trong Markdown và không có cơ chế nào từ chối giá trị ngoài tập đó
Runtime/Business Impact: None verified trực tiếp. Nhưng đây là nguyên nhân gốc của DOC-CONFLICT-0013 và DOC-CONFLICT-0014: danh sách đã trôi hai lần mà không công cụ nào phát hiện, kể cả `schema:drift`, vì contract không mô tả giá trị cột
Affected Implementation: media_file_usages (cột `owner_type`); mọi consumer đọc vocabulary theo tài liệu
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Có thêm CHECK constraint cho `owner_type` theo tiền lệ `chk_lrn_014` trên core_learning_node_mappings.source_type hay không; nếu có thì chốt tập giá trị trước, vì DOC-CONFLICT-0014 còn mở
Resolution Authority: Database Owner
Resolution Plan: Chốt DOC-CONFLICT-0014 trước để biết tập giá trị đúng; sau đó đánh giá migration bổ sung CHECK, kèm kiểm tra dữ liệu hiện có không vi phạm; không thực hiện trước khi vocabulary được phê duyệt
Target Review Date: Immediate, sau quyết định DOC-CONFLICT-0014
Resolved At: Not resolved
Resolution: Not resolved
Superseded/Updated Documents: None
Verification Evidence: `SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'learnforge_db' AND CHECK_CLAUSE LIKE '%owner_type%'` trả về rỗng
Related ADR/Review/Issue/PR: DOC-CONFLICT-0013; DOC-CONFLICT-0014
Notes: Media cố ý không tạo hard foreign key tới owner domain, và điều đó đúng. Nhưng "không FK" không bắt buộc kéo theo "không CHECK": Learning giữ đúng ranh giới đó mà vẫn khoá `source_type` bằng chk_lrn_014. Quyết định ở đây là tính đánh đổi giữa tính linh hoạt khi thêm owner mới và khả năng phát hiện trôi. Sau khi 0014 chốt vocabulary, câu Database Owner cần trả lời: **có thêm CHECK constraint cho `media_file_usages.owner_type` không — Có hay Không?**
```

---

# Resolved Conflict Register

Resolved confirmed items: 9.

| ID | Title | Classification | Status | Impact | Domain | Resolved At | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| DOC-CONFLICT-0001 | Tập status Session `ended` vs `completed` | CONFLICT | RESOLVED | HIGH | LiveClass | 2026-08-10 | `php artisan docs:lint` |
| DOC-CONFLICT-0002 | Quy ước thời gian Session chưa được định nghĩa | GAP | RESOLVED | HIGH | LiveClass | 2026-08-10 | `php artisan docs:lint` |
| DOC-CONFLICT-0003 | Mục Room-owned lỗi thời trong core_liveclass_sessions.md | STALE | RESOLVED | MEDIUM | LiveClass | 2026-08-10 | `php artisan docs:lint` |
| DOC-CONFLICT-0004 | Chưa quy định hệ quả khi vô hiệu hóa Cohort Teacher | GAP | RESOLVED | MEDIUM | Course × LiveClass | 2026-08-10 | `php artisan docs:lint`; `php artisan test` |
| DOC-CONFLICT-0005 | Phạm vi cấm replacement Session trong ADR-0002 | AMBIGUITY | RESOLVED | LOW | LiveClass | 2026-08-10 | `php artisan docs:lint` |
| DOC-CONFLICT-0010 | So sánh thời gian Session chưa khớp quy ước canonical | IMPLEMENTATION_DRIFT | RESOLVED | HIGH | LiveClass | 2026-08-10 | `php artisan test` (678 passed) |
| DOC-CONFLICT-0009 | Session status implementation chưa khớp hợp đồng canonical | IMPLEMENTATION_DRIFT | RESOLVED | MEDIUM | LiveClass | 2026-08-10 | `php artisan test` (689 passed) |
| DOC-CONFLICT-0012 | Learning/AI/Media metadata và manual-fallback wording không phản ánh implementation đã xác minh | STALE | RESOLVED | MEDIUM | Learning × AI × Media × Course | 2026-08-22 | `php artisan docs:lint`; `DocsManifestLintCommandTest` |
| DOC-CONFLICT-0013 | `course_version_activity` thiếu trong danh sách `owner_type` của media_file_usages.md | STALE | RESOLVED | MEDIUM | Media × Course | 2026-08-23 | `php artisan docs:lint`; ADR-0012; ADR-0013; 13 dòng dữ liệu development |

---

## DOC-CONFLICT-0012

```text
Conflict ID: DOC-CONFLICT-0012
Title: Learning/AI/Media metadata và manual-fallback wording không phản ánh implementation đã xác minh
Classification: STALE
Status: RESOLVED
Impact: MEDIUM
Detected At: 2026-08-22
Detected By: AI-assisted Learning Authoring readiness audit
Owner: Architecture Team
Affected Domain: Learning × AI × Media × Course
Affected Concern: Phân biệt approved architecture, development implementation và external authoring surface
Sources In Conflict:
Source A: docs/adr/ADR-0016-Learning-Foundation.md; docs/database/learning/*.md
Source B: database/migrations/2026_08_13_010000_create_learning_foundation_tables_and_triggers.php; internal Learning services; development migration status
Additional Sources: docs/adr/ADR-0017-AI-Assisted-Learning-Authoring.md; docs/core/LF-Core-Course.md; docs/core/LF-Core-Learning.md; docs/platform/LF-AI.md; docs/platform/LF-Media.md; docs/LF-DOCUMENTATION-MANIFEST.json
Contradictory Requirements:
- Source A reported Learning database implementation as Not Implemented after the ten-table migration/triggers and internal runtime had been deployed on development
- ADR-0017 wording could be read as OCR/transcript/caption and a manual Node form already existed, while the repository has neither processing runtime nor Learning route/controller/UI
Why They Cannot Both Be True: The metadata and present-tense wording described a verified implementation state different from source/database reality
Runtime/Business Impact: No runtime behavior changed. The stale wording could incorrectly authorize downstream work or cause policy to be mistaken for an available end-to-end flow
Affected Implementation: Documentation and machine-readable manifest only
Temporary Safety Rule: Preserve ADR-0016/0017 ownership and Implementation Gates; do not infer external readiness from development schema deployment
Required Decision: Apply the approved principle: AI proposes from processed Media plus Activity/Course/Framework context; human approves; Learning owner service writes; manual entry is a fallback on the same canonical foundation
Resolution Authority: LearnForge Architecture Owner direction supplied 2026-08-22
Resolution Plan: Mark ADR-0016 Partial; mark physical Learning table contracts Implemented on development; mark AI Not Implemented and Media Partial; clarify that processing and manual external surfaces are not implemented; preserve ADR-0017 and ADR-0006 gates
Target Review Date: 2026-08-22
Resolved At: 2026-08-22
Resolution: Canonical ADR/domain/database wording and manifest metadata synchronized. Manual fallback is required but must use the same Framework → Framework Version → Stable Node Definition → Versioned Node foundation. ADR-0017 remains Not Implemented; ADR-0006 Mastery Profile Amendment remains Proposed
Superseded/Updated Documents: docs/adr/ADR-0006-AI-Foundation.md; docs/adr/ADR-0016-Learning-Foundation.md; docs/adr/ADR-0017-AI-Assisted-Learning-Authoring.md; docs/core/LF-Core-Course.md; docs/core/LF-Core-Learning.md; docs/database/learning/*.md; docs/platform/LF-AI.md; docs/platform/LF-Media.md; docs/LF-DOCUMENTATION-MANIFEST.json
Verification Evidence: php artisan docs:lint; php artisan test --filter=DocsManifestLintCommandTest; git diff --check
Related ADR/Review/Issue/PR: ADR-0006; ADR-0016; ADR-0017
Notes: This resolution changes documentation truthfulness only. It does not authorize migration, API, prompt, UI, provider runtime, Proposal persistence, review workflow, Mapping promotion or production deployment under ADR-0017.
```

---

## DOC-CONFLICT-0013

```text
Conflict ID: DOC-CONFLICT-0013
Title: `course_version_activity` thiếu trong danh sách `owner_type` của media_file_usages.md
Classification: STALE
Status: RESOLVED
Impact: MEDIUM
Detected At: 2026-08-23
Detected By: Independent review — Media Processing readiness audit
Owner: Database Owner
Affected Domain: Media × Course
Affected Concern: Vocabulary hợp lệ của media_file_usages.owner_type
Sources In Conflict:
Source A: docs/database/media/media_file_usages.md#business-rules
Source B: docs/platform/LF-Media.md#course-integration
Additional Sources: docs/adr/ADR-0012-Course-Template-Published-Version-Snapshot.md; docs/adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md; docs/database/course/core_course_template_version_activities.md; docs/quality/LF-Version-Activity-Media-Snapshot-Architecture-Review.md
Contradictory Requirements:
- Source A requires: `owner_type` chỉ nhận mười hai giá trị được liệt kê, không có `course_version_activity`
- Source B requires: published Version Activity phải có active usage với owner `course_version_activity`
Why They Cannot Both Be True: Hai câu không thể cùng đúng theo nghĩa đen. Nhưng chúng không cùng thẩm quyền: ADR-0012 và ADR-0013 đều Approved và đều quy định giá trị này, nên đây là table doc lạc hậu chứ không phải một quyết định còn mở
Runtime/Business Impact: None verified. Đường ghi đã hoạt động đúng theo ADR từ trước; rủi ro thật là một reviewer đọc table doc rồi kết luận nhầm rằng vocabulary chưa được chốt, và chặn công việc để xin một quyết định đã có
Affected Implementation: app/Http/Controllers/MediaFileController.php:204,235,282,335,344; media_file_usages — 13 dòng `course_version_activity` trong database development
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Không cần quyết định mới. Thẩm quyền đã có tại ADR-0012 và ADR-0013; chỉ cần đồng bộ table doc
Resolution Authority: Database Owner
Resolution Plan: Bổ sung `course_version_activity` vào danh sách canonical trong media_file_usages.md kèm dẫn chiếu hai ADR; không đụng tới schema, migration hay runtime
Target Review Date: 2026-08-23
Resolved At: 2026-08-23
Resolution: Thực thi 2026-08-23. media_file_usages.md lên Version 1.2, danh sách `owner_type` bổ sung `course_version_activity` kèm dẫn chiếu ADR-0012 và ADR-0013. Không thay đổi schema, migration hoặc code
Superseded/Updated Documents: docs/database/media/media_file_usages.md
Verification Evidence: `php artisan docs:lint` passed; `SELECT owner_type, COUNT(*) FROM media_file_usages GROUP BY owner_type` trên learnforge_db trả về `course_version_activity` = 13; ADR-0012 § Media Usage; ADR-0013 § Duplicate Rules
Related ADR/Review/Issue/PR: ADR-0012; ADR-0013; DOC-CONFLICT-0014; DOC-CONFLICT-0015
Notes: Đăng ký như STALE chứ không phải CONFLICT là điểm quan trọng nhất của record này. Một bản đánh giá trước đó đã phân loại nó là conflict và đề nghị dừng toàn bộ Media pipeline để Architecture Owner chốt vocabulary — trong khi vocabulary đã được chốt qua hai ADR đã duyệt, đã implement, và đã có dữ liệu sống. Nhãn sai ở đây không chỉ tốn thời gian: nó mời Owner "chốt lại" một hướng mà mười ba dòng dữ liệu và hai ADR đã đi theo.
```

---

## DOC-CONFLICT-0001

```text
Conflict ID: DOC-CONFLICT-0001
Title: Tập giá trị status của Session mâu thuẫn giữa Domain Policy và Database Documentation
Classification: CONFLICT
Status: RESOLVED
Impact: HIGH
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Domain Owner (LiveClass)
Affected Domain: LiveClass
Affected Concern: Tập giá trị hợp lệ của core_liveclass_sessions.status
Sources In Conflict:
Source A: docs/core/LF-Core-LiveClass.md#liveclass-sessions
Source B: docs/database/liveclass/core_liveclass_sessions.md#cohort-centered-amendment--2026-07-25
Additional Sources: docs/database/liveclass/core_liveclass_sessions.md#business-rules
Contradictory Requirements:
- Source A requires: status ∈ (scheduled, live, ended, cancelled, no_show)
- Source B requires: status ∈ (draft, scheduled, live, completed, cancelled, no_show)
Why They Cannot Both Be True: `ended` và `completed` là hai giá trị dữ liệu loại trừ nhau trong cùng một cột. Một Session đã diễn ra xong chỉ mang được một trong hai. Tương tự, `draft` hoặc là giá trị hợp lệ hoặc không. Cả hai tài liệu đều ở trạng thái Approved và cùng mô tả một concern
Runtime/Business Impact: Chặn mọi implementation của Session lifecycle. Lớp ngôn ngữ chỉ có khóa cho `completed`, không có cho `ended`, nên chọn sai nhánh sẽ làm vỡ nhãn UI
Affected Implementation: app/Services/LiveClassSessionPolicy.php; database/migrations/2026_07_25_010000_create_cohort_liveclass_operations.php; resources/lang/{vi,en}/lf.php; resources/views/course-cohorts/partials/tabs/sessions.blade.php
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Chọn một tập status canonical duy nhất và đồng bộ cả hai tài liệu
Resolution Authority: Domain Owner (LiveClass) cùng Architecture Team
Resolution Plan: Chốt tập giá trị; cập nhật cả hai canonical source; ghi amendment có backlink; tách phần thực thi thành record riêng
Target Review Date: 2026-08-10
Resolved At: 2026-08-10
Resolution: Approved 2026-08-10 bởi LearnForge Architecture Owner. Tập canonical là scheduled/live/completed/cancelled/no_show. `ended` bị loại vì là ngoại lệ từ vựng duy nhất trong hệ thống và không có trong danh sách status canonical tại LF-Development-Standards.md § Status Fields. `draft` bị loại ở cấp Session vì chưa từng được định nghĩa ý nghĩa nghiệp vụ, điều kiện vào hay transition ra
Superseded/Updated Documents: docs/core/LF-Core-LiveClass.md (v2.5 → v2.6); docs/database/liveclass/core_liveclass_sessions.md (thêm § Session Status And Time Convention Amendment — 2026-08-10); docs/adr/ADR-0002-LiveClass-Foundation.md (thêm § Session Status Vocabulary And Replacement Scope Clarification)
Verification Evidence: `php artisan docs:lint`; `php artisan test --filter=CourseCohortManagementTest`; `./vendor/bin/pint --test`
Related ADR/Review/Issue/PR: ADR-0002; DOC-CONFLICT-0009
Notes: Chốt tại thời điểm chi phí bằng không — do chưa có transition nào được triển khai, toàn bộ Session trong dữ liệu chỉ mang giá trị `scheduled`, nên không cần data migration. Phần thực thi còn lại được theo dõi tại DOC-CONFLICT-0009 và cố ý giữ OPEN.
```

## DOC-CONFLICT-0002

```text
Conflict ID: DOC-CONFLICT-0002
Title: Quy ước thời gian của Session chưa được định nghĩa và ghi chú hiện có mâu thuẫn implementation
Classification: GAP
Status: RESOLVED
Impact: HIGH
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Architecture Team
Affected Domain: LiveClass
Affected Concern: Quy ước lưu trữ và so sánh thời gian của Session
Sources In Conflict:
Source A: docs/database/liveclass/core_liveclass_sessions.md#design-notes
Source B: app/Services/LiveClassSessionOriginService.php
Additional Sources: docs/adr/ADR-0002-LiveClass-Foundation.md#schedule-to-session-origin-amendment
Contradictory Requirements:
- Source A requires: "Thời gian nên được lưu theo UTC và render theo timezone"
- Source B requires: scheduled_start_at được ghi bằng giờ địa phương chưa chuyển UTC, trong khi source_start_at của Origin được ghi bằng UTC
Why They Cannot Both Be True: Không áp dụng — đây là khoảng trống chính sách kèm một ghi chú lỗi thời. ADR-0002 chỉ định nghĩa quy ước cho Origin và tham chiếu "the existing Session time convention" mà không tài liệu nào định nghĩa
Runtime/Business Impact: Thiếu quy ước canonical là nguyên nhân gốc của DOC-CONFLICT-0010 — cùng một cột đang được ba nhóm code đọc theo hai cách loại trừ nhau
Affected Implementation: app/Services/LiveClassSessionPolicy.php; CourseCohortOperationController; CourseCohortController::cohortSessionsQuery
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Định nghĩa tường minh quy ước lưu trữ và so sánh thời gian Session
Resolution Authority: Architecture Team cùng Domain Owner (LiveClass)
Resolution Plan: Ghi quy ước vào cả Domain Policy và Database Documentation; giữ nguyên quy ước lưu trữ hiện hành để không phá dữ liệu lịch sử và không phá Origin classification đang đúng
Target Review Date: 2026-08-10
Resolved At: 2026-08-10
Resolution: Approved 2026-08-10 bởi LearnForge Architecture Owner. Session lưu giờ địa phương (wall-clock) diễn giải theo cột timezone của chính dòng đó; mọi so sánh phải parse theo timezone rồi chuẩn hóa UTC trước khi so. Không đổi quy ước lưu trữ
Superseded/Updated Documents: docs/core/LF-Core-LiveClass.md § Session Time Convention — 2026-08-10; docs/database/liveclass/core_liveclass_sessions.md § Canonical Time Convention và § Design Notes
Verification Evidence: `php artisan docs:lint`; `php artisan test --filter=CourseCohortManagementTest`; `./vendor/bin/pint --test`
Related ADR/Review/Issue/PR: ADR-0002 Schedule-To-Session Origin Amendment; DOC-CONFLICT-0010
Notes: Chọn giữ wall-clock thay vì chuyển sang UTC vì đổi quy ước lưu trữ sẽ phá dữ liệu Session hiện có và phá logic phân loại Origin vốn đang diễn giải đúng.
```

## DOC-CONFLICT-0003

```text
Conflict ID: DOC-CONFLICT-0003
Title: Các mục Room-owned lỗi thời vẫn còn hiệu lực hình thức trong core_liveclass_sessions.md
Classification: STALE
Status: RESOLVED
Impact: MEDIUM
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Database Owner
Affected Domain: LiveClass
Affected Concern: Hiệu lực của các mục lịch sử trong tài liệu bảng Session
Sources In Conflict:
Source A: docs/database/liveclass/core_liveclass_sessions.md#business-rules
Source B: docs/database/liveclass/core_liveclass_sessions.md#cohort-centered-amendment--2026-07-25
Additional Sources: docs/database/liveclass/core_liveclass_sessions.md (§ Fields, § Indexes, § Sample Data, § Design Notes)
Contradictory Requirements:
- Source A requires: Session phải thuộc một Room; room_id, product_id, teacher_id, version_activity_id NOT NULL; session_no duy nhất trong một Room
- Source B requires: Session thuộc Cohort; room_id nullable; unique là (customer_id, cohort_id, session_no)
Why They Cannot Both Be True: Hai mục nằm trong CÙNG một tài liệu và quy định trái ngược nhau về cùng các cột. Amendment tuyên bố supersede nhưng các mục cũ không được đánh dấu, nên người đọc không có cách xác định mục nào còn hiệu lực
Runtime/Business Impact: Rủi ro đọc sai hợp đồng ở mọi tác vụ chạm bảng Session
Affected Implementation: None verified
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Đánh dấu tường minh phạm vi hiệu lực của các mục lịch sử
Resolution Authority: Database Owner
Resolution Plan: Thêm superseding notice liệt kê chính xác các phát biểu đã hết hiệu lực, giữ nội dung lịch sử để tra cứu quyết định cũ
Target Review Date: 2026-08-10
Resolved At: 2026-08-10
Resolution: Approved 2026-08-10 bởi LearnForge Architecture Owner. Thêm § Historical Sections — Superseding Notice liệt kê bốn amendment theo thứ tự thời gian và năm phát biểu cụ thể đã hết hiệu lực
Superseded/Updated Documents: docs/database/liveclass/core_liveclass_sessions.md
Verification Evidence: `php artisan docs:lint`
Related ADR/Review/Issue/PR: ADR-0002
Notes: Không xóa nội dung lịch sử. Việc viết lại § Fields thống nhất được hoãn tới khi hợp đồng vật lý của bảng được soạn lại hoàn chỉnh.
```

## DOC-CONFLICT-0004

```text
Conflict ID: DOC-CONFLICT-0004
Title: Chưa quy định hệ quả với Session Teacher khi vô hiệu hóa Cohort Teacher assignment
Classification: GAP
Status: RESOLVED
Impact: MEDIUM
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Domain Owner (LiveClass)
Affected Domain: Course × LiveClass
Affected Concern: Vòng đời assignment giáo viên giữa hai cấp Cohort và Session
Sources In Conflict:
Source A: docs/database/liveclass/core_liveclass_session_teachers.md#cohort-session-team-policy--2026-08-04
Source B: docs/database/course/core_course_cohort_teachers.md
Additional Sources: app/Http/Controllers/CourseCohortOperationController.php::removeTeacher
Contradictory Requirements:
- Source A requires: mọi dòng session_teachers phải resolve qua một assignment cohort_teachers đang active
- Source B requires: không quy định gì về việc chuyển assignment sang inactive khi giáo viên còn phụ trách Session
Why They Cannot Both Be True: Không áp dụng — khoảng trống chính sách. Source A đặt ra invariant nhưng không nguồn nào định nghĩa cách bảo toàn nó khi assignment cấp trên bị vô hiệu hóa
Runtime/Business Impact: Gỡ giáo viên để lại dòng session_teachers mồ côi. Gate activation và kiểm tra runtime chỉ xét sự tồn tại dòng nên vẫn coi Session là đã có giáo viên, cho phép activate Cohort với các Session không còn giáo viên hợp lệ
Affected Implementation: app/Http/Controllers/CourseCohortOperationController.php::removeTeacher; app/Services/CourseCohortLifecycleService.php::collectActivationIssues; ::sessionHasAssignedTeacher
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Chọn hành vi khi gỡ giáo viên còn phụ trách Session — chặn hay cascade
Resolution Authority: Domain Owner (LiveClass) cùng Domain Owner (Course)
Resolution Plan: Chốt hành vi; ghi vào tài liệu bảng của cả hai phía kèm cross-reference
Target Review Date: 2026-08-10
Resolved At: 2026-08-10
Resolution: Approved 2026-08-10 bởi LearnForge Architecture Owner. Chọn fail-closed: chặn thao tác gỡ khi giáo viên còn phụ trách Session chưa diễn ra và chưa có evidence, kèm danh sách Session chặn. Không cascade delete. Assignment của Session đã diễn ra hoặc đã có evidence là bằng chứng lịch sử, được giữ nguyên và không chặn thao tác gỡ
Superseded/Updated Documents: docs/database/course/core_course_cohort_teachers.md (v1.1 → v1.2); docs/database/liveclass/core_liveclass_session_teachers.md (v1.3 → v1.4)
Verification Evidence: `php artisan docs:lint`
Related ADR/Review/Issue/PR: ADR-0001 (nguyên tắc activation không âm thầm loại bỏ membership không hợp lệ)
Notes: Cascade bị loại vì là thao tác phá hủy khó đảo ngược và đẩy lỗi ra xa nguyên nhân. Thực thi thuộc Phase 1.3.
```

## DOC-CONFLICT-0005

```text
Conflict ID: DOC-CONFLICT-0005
Title: Phạm vi lệnh cấm replacement Session trong ADR-0002 không rõ ràng
Classification: AMBIGUITY
Status: RESOLVED
Impact: LOW
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Architecture Team
Affected Domain: LiveClass
Affected Concern: Phạm vi áp dụng của lệnh cấm replacement Session
Sources In Conflict:
Source A: docs/adr/ADR-0002-LiveClass-Foundation.md#cohort-centered-session-amendment
Source B: docs/adr/ADR-0002-LiveClass-Foundation.md#schedule-to-session-origin-amendment
Additional Sources: docs/quality/LF-LiveClass-Cohort-Schedule-Architecture-Review.md#deferred-boundary
Contradictory Requirements:
- Source A requires: replacement Session dùng superseded_by_session_id; Session cũ chuyển sang cancelled
- Source B requires: replacement và superseded occurrence reuse không được authorize, đồng thời tự giới hạn chỉ supersede các điều khoản về deferred provenance
Why They Cannot Both Be True: Không áp dụng — đây là ambiguity, không phải conflict. Câu cấm ghép "replacement" với "superseded occurrence reuse" nên có thể đọc là chỉ cấm dùng lại occurrence identity, hoặc cấm replacement nói chung. Phạm vi tự giới hạn của Source B khiến hai cách đọc đều có căn cứ
Runtime/Business Impact: None verified — cột superseded_by_session_id tồn tại nhưng chưa từng được ghi
Affected Implementation: database/migrations/2026_07_25_010000_create_cohort_liveclass_operations.php (cột superseded_by_session_id)
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Làm rõ replacement có được mở cho Session không có Origin hay không
Resolution Authority: Architecture Team
Resolution Plan: Ghi clarification vào ADR-0002 nêu rõ phạm vi
Target Review Date: 2026-08-10
Resolved At: 2026-08-10
Resolution: Approved 2026-08-10 bởi LearnForge Architecture Owner. Replacement vẫn deferred cho MỌI Session, có hay không có Origin. Câu cấm trong Origin Amendment được đọc theo phạm vi occurrence identity, nhưng không vì thế mà tái mở workflow replacement. Cột superseded_by_session_id là reserved, implementation hiện tại không được ghi. Xử lý buổi hỏng chỉ qua hai đường: đổi lịch chính Session đó, hoặc tạo Session thủ công mới. Mở lại replacement cần amendment riêng và Architecture Review
Superseded/Updated Documents: docs/adr/ADR-0002-LiveClass-Foundation.md
Verification Evidence: `php artisan docs:lint`
Related ADR/Review/Issue/PR: LF-LiveClass-Cohort-Schedule-Architecture-Review.md § Deferred Boundary
Notes: Không drop cột — đó sẽ là destructive schema change không mang lại lợi ích vận hành. Nhu cầu vận hành thật (buổi bù) đã được đáp ứng bởi Session lifecycle tại Phase 2.2: khi cancel được, khung giờ được giải phóng và Session thủ công mới tạo được bình thường.
```

---

# Baseline Scan Disposition

Baseline repository scan ngày 2026-08-09 không xác minh được hai official
sources có yêu cầu loại trừ nhau. Các nhóm sau không bị ghi sai thành conflict:

| Observation | Classification | Evidence |
| --- | --- | --- |
| Approved/Frozen specifications chưa có migration/model tương ứng cho Assessment, Track, AI và một số SaaS domains | Không phải conflict/drift; trạng thái tài liệu độc lập với implementation và việc chưa triển khai đã được ghi rõ | `LF-INDEX.md` feature routing và các `database/<domain>/README.md` ghi rõ “Chưa triển khai” |
| Một số feature chưa có Quality/Architecture Review chuyên biệt | `GAP` | `LF-INDEX.md` ghi “Chưa có review chuyên biệt” |
| Course Learning Path chưa có domain-policy section riêng dù database docs đã tồn tại | `GAP`, không phải routing conflict | `LF-INDEX.md` ghi rõ domain policy “Chưa xác định” và route tạm tới database docs |
| Product Items review lịch sử đã bị thay thế nhưng header metadata vẫn là `Document Status: Approved` | `STALE`, không phải conflict; supersession và canonical replacement đã rõ | `quality/LF-Course-Product-Items-Architecture-Review.md`, `quality/README.md`, `LF-INDEX.md` đều route sang Integrated Product v2 Review |
| Deferred feature scope trong ADR/review/schema docs | Không phải conflict nếu các nguồn không yêu cầu implementation hiện tại | Các section `Deferred`/`Explicitly Deferred` được giữ ngoài implementation authority |
| Legacy metadata debt | `GAP`, không phải semantic conflict | `config/docs-lint.php` allowlist có path và lý do chính xác |

Không có `AMBIGUITY`, `DUPLICATION`, `ROUTING_GAP` hoặc `UNVERIFIED`
item nào trong baseline scan đủ bằng chứng để đăng ký thành confirmed conflict.

---

# Relationship With Other Processes

## Documentation priority

LF-INDEX priority xác định reading order và authority. Nó không cho phép âm
thầm chọn bên thắng khi hai official sources đưa ra yêu cầu loại trừ nhau về
cùng concern; trường hợp đó phải đăng ký và STOP.

## ADR process

Resolution làm thay đổi architecture phải đi qua ADR process. Register ghi
decision evidence nhưng không thay thế hoặc tự approve ADR.

## Architecture Review

Conflict chạm architecture boundary phải qua
[Architecture Review Checklist](../governance/LF-Architecture-Review-Checklist.md).
Register không tự xác nhận Foundation Ready.

## Regression Audit

Implementation/documentation change để resolve conflict phải dùng
[LF Regression Audit](LF-Regression-Audit.md) với Final Audit Level cao nhất
áp dụng. Conflict Impact không phải Audit Level hoặc Finding Severity.

## docs:lint

`docs:lint` kiểm tra metadata, catalog/orphan, dead links, vocabulary và
superseded backlinks. Nó không phân tích semantic conflict trong văn xuôi;
lint pass không chứng minh register không có conflict.

---

# Maintenance Rules

* Không sửa/xóa lịch sử record; ghi transition và evidence.
* Không tái sử dụng ID hoặc renumber để lấp khoảng trống.
* Record `ACCEPTED_TEMPORARILY` phải có owner, lý do và Target Review Date.
* Record `RESOLVED` phải có updated/superseded sources và verification evidence.
* Record `INVALIDATED` phải giữ lý do phân loại sai ban đầu.
* Cập nhật bảng index và detail section trong cùng thay đổi.
* Chạy `php artisan docs:lint` sau mọi cập nhật register.
* Không ghi secret, production data hoặc tên cá nhân chưa được xác minh.

---

# Owner and Review Cadence

Owner: Architecture Team.

Review register:

* Khi một inconsistency mới được xác minh.
* Khi status, owner, impact hoặc evidence của record thay đổi.
* Trước implementation của affected concern.
* Tối thiểu mỗi quý đối với record đang active.

Target Review Date vẫn phải được đặt riêng cho từng conflict active; cadence
không thay thế deadline của record.

## DOC-CONFLICT-0009

```text
Conflict ID: DOC-CONFLICT-0009
Title: Session status implementation chưa khớp hợp đồng canonical đã chốt
Classification: IMPLEMENTATION_DRIFT
Status: RESOLVED
Impact: MEDIUM
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Domain Owner (LiveClass)
Affected Domain: LiveClass
Affected Concern: Giá trị và lifecycle của core_liveclass_sessions.status
Sources In Conflict:
Source A: docs/database/liveclass/core_liveclass_sessions.md#session-status-and-time-convention-amendment--2026-08-10
Source B: database/migrations/2026_07_25_010000_create_cohort_liveclass_operations.php
Additional Sources: app/Services/LiveClassSessionPolicy.php, resources/lang/vi/lf.php, resources/lang/en/lf.php
Contradictory Requirements:
- Source A requires: tập status là scheduled/live/completed/cancelled/no_show; draft và ended bị loại bỏ; tồn tại transition workflow
- Source B requires: cột status có default `draft`
Why They Cannot Both Be True: Không áp dụng — hợp đồng vừa được chốt, implementation chưa được đưa về khớp
Runtime/Business Impact: Không có Session nào trong dữ liệu hiện tại mang giá trị ngoài `scheduled`, nên chưa có tác động dữ liệu. Nhánh xử lý `draft` trong LiveClassSessionPolicy::canEdit và các khóa ngôn ngữ `_status_draft` là code chết
Affected Implementation: app/Services/LiveClassSessionPolicy.php:12; resources/lang/{vi,en}/lf.php khóa LF_course_cohort_session_status_draft; resources/views/course-cohorts/partials/tabs/sessions.blade.php statusLabels
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Đã chốt hợp đồng. Chỉ còn thực thi
Resolution Authority: Domain Owner (LiveClass)
Resolution Plan: Phase 2.2 — forward migration đổi default sang `scheduled` (không sửa migration lịch sử), gỡ nhánh draft, gỡ khóa ngôn ngữ, triển khai LiveClassSessionLifecycleService
Target Review Date: 2026-08-10
Resolved At: 2026-08-10
Resolution: Thực thi 2026-08-10. Forward migration 2026_08_10_010000_retire_liveclass_session_draft_status chuẩn hóa mọi dòng `draft` còn sót về `scheduled` rồi đổi default cột; migration lịch sử không bị sửa. Thêm app/Services/LiveClassSessionLifecycleService với transition map canonical và bốn route start/complete/cancel/no-show, mỗi transition yêu cầu Cohort `active` và ghi actual_start_at/actual_end_at/cancellation_reason đúng chỗ. Gỡ nhánh `draft` chết khỏi LiveClassSessionPolicy, khỏi statusLabels của view và khỏi hai file ngôn ngữ
Superseded/Updated Documents: None — hợp đồng tài liệu đã đúng từ DOC-CONFLICT-0001
Verification Evidence: tests/Feature/CourseCohortManagementTest — test_session_lifecycle_transitions_follow_the_canonical_map, test_cancelling_a_session_records_the_reason_and_releases_its_time_range, test_session_lifecycle_requires_an_active_cohort_and_admin_role, test_cancelled_sessions_no_longer_block_cohort_activation; `php artisan test` 689 passed; `php artisan migrate --pretend`
Related ADR/Review/Issue/PR: ADR-0002 § Session Status Vocabulary And Replacement Scope Clarification; DOC-CONFLICT-0001
Notes: Tách khỏi DOC-CONFLICT-0001 có chủ đích. 0001 là xung đột tài liệu; 0009 là phần thực thi. Việc mở khóa các nhánh cancelled/no_show làm sống lại hai đoạn code trước đây không thể chạy tới: loại trừ khỏi overlap validation và loại trừ khỏi gate activation — cả hai đều đã có test bao phủ.
```

## DOC-CONFLICT-0010

```text
Conflict ID: DOC-CONFLICT-0010
Title: So sánh thời gian Session chưa khớp quy ước canonical
Classification: IMPLEMENTATION_DRIFT
Status: RESOLVED
Impact: HIGH
Detected At: 2026-08-10
Detected By: Architecture Review — Course Cohort module
Owner: Domain Owner (LiveClass)
Affected Domain: LiveClass
Affected Concern: Diễn giải scheduled_start_at/scheduled_end_at khi so sánh
Sources In Conflict:
Source A: docs/database/liveclass/core_liveclass_sessions.md#canonical-time-convention
Source B: app/Services/LiveClassSessionPolicy.php, app/Http/Controllers/CourseCohortOperationController.php
Additional Sources: docs/core/LF-Core-LiveClass.md#session-time-convention--2026-08-10
Contradictory Requirements:
- Source A requires: mọi so sánh phải parse theo timezone của dòng rồi chuẩn hóa UTC
- Source B requires: so sánh chuỗi thô giữa các Session và với now() theo timezone mặc định của server
Why They Cannot Both Be True: Không áp dụng — quy ước được chốt tại DOC-CONFLICT-0002, code chưa được đưa về khớp tại thời điểm phát hiện
Runtime/Business Impact: Cohort có Schedule ở timezone khác Asia/Ho_Chi_Minh nhận kết quả overlap detection sai theo cả hai chiều, và các gate canEdit/canRecordAttendance/canCreateRecording lệch đúng bằng chênh lệch múi giờ
Affected Implementation: app/Services/LiveClassSessionPolicy.php (4 method); CourseCohortOperationController::validateSessionDoesNotOverlap, ::validateSessionScheduleWindow
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: Đã chốt quy ước tại DOC-CONFLICT-0002. Chỉ còn thực thi
Resolution Authority: Domain Owner (LiveClass)
Resolution Plan: Phase 1.2 — chuyển mọi so sánh sang instant UTC chuẩn hóa; viết characterization test đa múi giờ TRƯỚC khi sửa
Target Review Date: 2026-08-10
Resolved At: 2026-08-10
Resolution: Thực thi 2026-08-10. Thêm app/Services/LiveClassSessionTime làm reader canonical duy nhất; LiveClassSessionPolicy chuyển cả bốn gate sang so sánh instant; validateSessionDoesNotOverlap thay so sánh SQL bằng prefilter thô ±2 ngày cộng so sánh chính xác trong PHP; validateSessionScheduleWindow neo khoảng vận hành Cohort và "hôm nay" vào timezone của Session; cohortSessionsQuery dùng chung helper. Quy ước lưu trữ giữ nguyên. Availability của giáo viên cố ý KHÔNG chuẩn hóa UTC vì so theo ngày lịch địa phương
Superseded/Updated Documents: None — hợp đồng tài liệu đã đúng từ DOC-CONFLICT-0002
Verification Evidence: tests/Unit/LiveClassSessionPolicyTest.php (6 test, 3 fail trước khi sửa); tests/Feature/CourseCohortManagementTest::test_session_overlap_is_evaluated_across_different_session_timezones (fail trên code cũ, xác minh bằng git stash); `php artisan test` 678 passed / 1 skipped; `./vendor/bin/pint --test`
Related ADR/Review/Issue/PR: DOC-CONFLICT-0002
Notes: Trước khi sửa, CourseCohortController::cohortSessionsQuery đã diễn giải ĐÚNG quy ước khi phân loại Origin, nên trong cùng một module tồn tại hai cách đọc mâu thuẫn cho cùng một cột. Bộ test cũ không phát hiện được vì mọi test đều dùng timezone mặc định.
```

## DOC-CONFLICT-0016

```text
Conflict ID: DOC-CONFLICT-0016
Title: Revision identity của media_table_cells mâu thuẫn với ADR-0019 § D2
Classification: DOCUMENT_CONTRADICTION
Status: RESOLVED
Impact: MEDIUM
Detected At: 2026-08-25
Detected By: Independent review — Media Structured Extraction Database Docs
Owner: Domain Owner (Media)
Affected Domain: Media
Affected Concern: Nơi lưu processing_version và source_fingerprint của structured extraction
Sources In Conflict:
Source A: docs/adr/ADR-0019-Media-Structured-Extraction-Boundary.md#d2
Source B: docs/database/media/media_table_cells.md#fields
Additional Sources: docs/database/media/media_extracted_tables.md
Contradictory Requirements:
- Source A requires: cả ba bảng structured extraction mang processing_version + source_fingerprint trên mỗi row
- Source B requires: media_table_cells cố ý không có hai cột đó; cell kế thừa revision identity từ bảng cha
Why They Cannot Both Be True: Một cell hoặc mang revision identity của riêng nó, hoặc kế thừa. Làm cả hai tạo ra hai nguồn cho cùng một giá trị và không có quy tắc nào nói bên nào đúng khi chúng lệch
Runtime/Business Impact: Chưa có runtime impact — cả ba bảng đều not_implemented. Nếu migration được viết theo Source A, mỗi document sinh hàng trăm nghìn cell mang bản sao version/fingerprint không bao giờ khác giá trị cha, và mọi purge theo retention phải cập nhật cả ba bảng thay vì hai
Affected Implementation: Không có. Migration chưa được viết
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess. Không tạo migration cho media_extracted_regions, media_extracted_tables, media_table_cells cho tới khi conflict đóng
Required Decision: Owner chọn giữa (a) approve ADR-0019 Amendment v1.1 — revision identity nằm ở row sở hữu, cell kế thừa; hoặc (b) giữ § D2 nguyên văn và thêm hai cột vào media_table_cells
Resolution Authority: Architecture Owner
Resolution Plan: ADR-0019 Amendment Record Version 1.1 đã được soạn ở trạng thái Proposed; chờ Owner approval
Target Review Date: 2026-09-01
Resolved At: 2026-08-25
Resolution: Owner approve ADR-0019 Amendment v1.1 ngày 2026-08-25. Revision identity nằm ở row sở hữu revision (media_extracted_regions, media_extracted_tables); media_table_cells kế thừa processing_version/source_fingerprint/status từ bảng cha và không archived độc lập
Superseded/Updated Documents: docs/adr/ADR-0019-Media-Structured-Extraction-Boundary.md chuyển Version 1.1
Verification Evidence:
Related ADR/Review/Issue/PR: ADR-0019
Notes: Phát hiện bởi independent review trước khi Architecture Review chạy, nên chi phí đóng bằng không — không có dữ liệu nào phải backfill
```

## DOC-CONFLICT-0017

```text
Conflict ID: DOC-CONFLICT-0017
Title: extraction_method của đọc cell trực tiếp có hai tên trong hai bảng
Classification: DOCUMENT_CONTRADICTION
Status: RESOLVED
Impact: MEDIUM
Detected At: 2026-08-25
Detected By: Status verification — Phase 5 spreadsheet pipeline
Owner: Domain Owner (Media)
Affected Domain: Media
Affected Concern: Vocabulary của extraction_method cho worksheet đọc trực tiếp
Sources In Conflict:
Source A: docs/database/media/media_extracted_texts.md#constraints-and-indexes
Source B: docs/database/media/media_extracted_tables.md#constraints-and-indexes
Additional Sources: docs/platform/LF-Media-Processing-Contract.md#phase-1-local-document-provider; app/Services/LocalDocumentProcessingProvider.php::xlsxUnits
Contradictory Requirements:
- Source A requires: CHECK (extraction_method IN ('ocr','embedded_text')) — đã migrate; worksheet đọc trực tiếp buộc phải persist 'embedded_text'
- Source B requires: CHECK (extraction_method IN ('ocr','embedded_text','spreadsheet_cells')) — worksheet đọc trực tiếp persist 'spreadsheet_cells'
Why They Cannot Both Be True: Cùng một thao tác trích xuất — đọc cell từ OOXML — sẽ mang hai tên phương pháp khác nhau tùy nó rơi vào bảng nào. Consumer đọc provenance của cùng một revision nhận hai câu trả lời mâu thuẫn
Runtime/Business Impact: Đang có ở runtime. xlsxUnits hiện persist 'embedded_text' vào media_extracted_texts, làm mất phân biệt giữa "lớp text của một PDF" và "đọc cấu trúc nguồn" — đúng phân biệt mà media_extracted_tables được thiết kế để giữ. Không sai dữ liệu, nhưng provenance không truy được phương pháp thật
Affected Implementation: app/Services/LocalDocumentProcessingProvider.php::xlsxUnits; app/Jobs/ProcessMediaProcessingJob::persistSuccess
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess. Giữ nguyên 'embedded_text' ở runtime cho tới khi Owner quyết; không tự mở CHECK
Required Decision: Owner chọn giữa (a) mở CHECK của media_extracted_texts thêm 'spreadsheet_cells' — cần migration riêng và một processing_version mới; hoặc (b) bỏ 'spreadsheet_cells' khỏi media_extracted_tables và chấp nhận 'embedded_text' cho cả hai
Resolution Authority: Domain Owner (Media)
Resolution Plan: Gộp cùng quyết định locator page→sheet của media_extracted_texts v1.2, vì cả hai đều cần đúng một migration và một processing_version mới
Target Review Date: 2026-09-01
Resolved At: 2026-08-25
Resolution: Owner chọn phương án (a) ngày 2026-08-25. media_extracted_texts v1.3 mở CHECK (extraction_method IN ('ocr','embedded_text','spreadsheet_cells')). Đi chung một migration với locator page→sheet của v1.2 và một processing_version mới cho spreadsheet
Superseded/Updated Documents: docs/database/media/media_extracted_texts.md chuyển Version 1.3; docs/platform/LF-Media-Processing-Contract.md ghi rõ đường xử lý xlsx
Verification Evidence:
Related ADR/Review/Issue/PR: ADR-0019; DOC-CONFLICT-0016; quality/LF-Media-Structured-Extraction-Architecture-Review.md
Notes: Phát hiện khi đối chiếu trạng thái Phase 5, không phải khi review Database Docs — vocabulary cũ đã đúng cho đến khi đọc cell trực tiếp được thêm vào ở mục 4
```

---

## DOC-CONFLICT-0018

```text
Conflict ID: DOC-CONFLICT-0018
Title: Processing Contract § 4 chưa mở locator sang sheet/region
Classification: DOCUMENT_CONTRADICTION
Status: RESOLVED
Impact: MEDIUM
Detected At: 2026-08-27
Detected By: Đối chiếu tài liệu ↔ source, miền Media Document Processing
Owner: Architecture Owner
Affected Domain: Media
Affected Concern: Vocabulary của locator_type trong hợp đồng citation
Sources In Conflict:
Source A: docs/platform/LF-Media-Processing-Contract.md#4-citation-locator
Source B: docs/adr/ADR-0019-Media-Structured-Extraction-Boundary.md#d1-locator-giu-nguyen-hinh-dang-mo-rong-vocabulary
Additional Sources: docs/platform/LF-Media-Processing-Contract.md#structured-extraction-resource-controls; docs/platform/LF-Media-Read-Contract.md#2-hop-dong-doc
Contradictory Requirements:
- Source A requires: locator_type chỉ gồm page và timespan; thêm locator_type mới là amendment có review
- Source B requires: locator_type gồm page, timespan, sheet và region, đã Approved 2026-08-25
Why They Cannot Both Be True: Cùng một hợp đồng locator được hai tài liệu Approved mô tả bằng hai vocabulary khác nhau. Nặng hơn: chính Source A đã có mục resource control đếm region và cell, nên tài liệu tự mâu thuẫn với chính nó, không chỉ với ADR
Runtime/Business Impact: Chưa có ở runtime — không đường code nào ghi sheet hay region. Rủi ro là ngược lại: một implementer đọc § 4 sẽ kết luận locator mới chưa được duyệt và tiếp tục ép sheet vào page, đúng cách dùng sai mà ADR-0019 được viết để sửa
Affected Implementation: app/Services/LocalDocumentProcessingProvider.php::unit; app/Services/MediaReadService.php
Temporary Safety Rule: Không ghi locator_type ngoài page/timespan cho tới khi amendment được ký
Required Decision: Owner ký Amendment v2.1 của Processing Contract, hoặc bác và nêu vocabulary thay thế
Resolution Authority: Architecture Owner
Resolution Plan: Amendment Record Version 2.1 soạn trong chính Processing Contract ngày 2026-08-27; ký là đóng
Target Review Date: 2026-09-01
Resolved At: 2026-08-27
Resolution: Owner ký Amendment v2.1 ngày 2026-08-27. § 4 nhận sheet và region đúng theo ADR-0019 § D1; hình dạng locator không đổi. Runtime được phép ghi hai locator_type mới kể từ khi Gate R mở
Superseded/Updated Documents: docs/platform/LF-Media-Processing-Contract.md chuyển Version 2.1; docs/platform/LF-Media.md chuyển Version 1.4
Verification Evidence: Bốn kiểm tra docs:lint chạy lại bằng Python ngày 2026-08-27 — metadata header, README/INDEX mention, link resolution, manifest round-trip/count/sort/title — xanh trên mọi file đã sửa. php không có trên shell của máy đối chiếu
Related ADR/Review/Issue/PR: ADR-0019 v1.2; quality/LF-Media-Structured-Extraction-Architecture-Review.md
Notes: Amendment đề xuất chỉ mở vocabulary, không đổi hình dạng locator. Ghi nhận kèm theo, không sửa trong đợt này: extracted_text_too_large và page_limit_exceeded cùng bản chất lỗi vĩnh viễn nhưng chưa bao giờ được liệt kê ở § 2 Retry
```

---

## DOC-CONFLICT-0019

```text
Conflict ID: DOC-CONFLICT-0019
Title: job_type và output_type không có giá trị nào chứa được một revision structured
Classification: GAP
Status: RESOLVED
Impact: HIGH
Detected At: 2026-08-27
Detected By: Đối chiếu tài liệu ↔ source, miền Media Document Processing
Owner: Architecture Owner
Affected Domain: Media
Affected Concern: Job identity và output identity của structured extraction
Sources In Conflict:
Source A: docs/database/media/media_processing_jobs.md#constraints-and-indexes
Source B: docs/adr/ADR-0019-Media-Structured-Extraction-Boundary.md#d2-cau-truc-khong-song-trong-media_extracted_texts
Additional Sources: docs/database/media/media_extracted_regions.md#fields; docs/database/media/media_extracted_tables.md#fields; database/migrations/2026_08_26_000100_create_media_structured_extraction.php
Contradictory Requirements:
- Source A requires: job_type thuộc bảy giá trị đóng, output_type thuộc bốn giá trị đóng, và job ready khác virus_scan phải có output_id
- Source B requires: một lần chạy sinh row ở media_extracted_regions, media_extracted_tables và media_table_cells, và ba bảng đó đều có cột processing_job_id trỏ về media_processing_jobs
Why They Cannot Both Be True: Không áp dụng — đây là khoảng trống, không phải hai tài liệu chống nhau. Ba bảng mới khai báo khóa ngoại tới media_processing_jobs nhưng không job_type nào hợp lệ để tạo ra chúng, và không output_type nào trỏ về chúng
Runtime/Business Impact: Chặn Gate R hoàn toàn. Một provider muốn ghi structured output hôm nay phải hoặc đội lốt job_type ocr — sai với nhánh spreadsheet vốn không gọi OCR lần nào — hoặc vi phạm CHECK
Affected Implementation: app/Jobs/ProcessMediaProcessingJob::persistSuccess; app/Services/MediaProcessingOrchestrator
Temporary Safety Rule: Không viết service persist structured trước khi quyết định này đóng
Required Decision: Owner chọn giữa (a) job_type riêng structured_extraction với output_type extracted_region/extracted_table; hoặc (b) tái sử dụng ocr với output_profile layout/structure
Resolution Authority: Architecture Owner
Resolution Plan: Owner chọn phương án (a) ngày 2026-08-27 và ký ADR-0019 Amendment v1.2 cùng Processing Contract Amendment v2.1 trong ngày
Target Review Date: 2026-09-01
Resolved At: 2026-08-27
Resolution: job_type = 'structured_extraction', output_type = extracted_region (nguồn document) hoặc extracted_table (nguồn spreadsheet), output_id trỏ tới điểm vào của revision — region reading_order = 1, hoặc table sequence = 1. Hợp đồng ở Processing Contract § 2; ADR-0019 § D6
Superseded/Updated Documents: docs/adr/ADR-0019-Media-Structured-Extraction-Boundary.md chuyển Version 1.2; docs/platform/LF-Media-Processing-Contract.md chuyển Version 2.1; docs/database/media/media_processing_jobs.md chuyển Version 2.5 và Implementation Status Partial; docs/quality/LF-Media-Structured-Extraction-Architecture-Review.md chuyển Version 2.2 với § F.8
Verification Evidence: Bốn kiểm tra docs:lint chạy lại bằng Python ngày 2026-08-27, xanh trên mọi file đã sửa
Related ADR/Review/Issue/PR: ADR-0019 v1.2; DOC-CONFLICT-0018; DOC-CONFLICT-0020
Notes: Đóng ở tầng tài liệu, KHÔNG ở tầng vật lý. Vocabulary mới chưa migrate; migration thứ ba trên media_processing_jobs chịu Gate M và còn bị chặn bởi DOC-CONFLICT-0020. Chữ ký Owner cho amendment không phải chữ ký Architecture Review — § F.8 cần một lượt review độc lập trước khi migration đó được viết
```

---

## DOC-CONFLICT-0020

```text
Conflict ID: DOC-CONFLICT-0020
Title: Bốn CHECK trong doc media_processing_jobs không tồn tại trong schema vật lý
Classification: IMPLEMENTATION_DRIFT
Status: DECISION_REQUIRED
Impact: MEDIUM
Detected At: 2026-08-27
Detected By: Đối chiếu § Keys với LF-SCHEMA-CONTRACT.json khi chuẩn bị amendment job_type
Owner: Database Owner
Affected Domain: Media
Affected Concern: Hợp đồng vật lý của media_processing_jobs
Sources In Conflict:
Source A: docs/database/media/media_processing_jobs.md#constraints-and-indexes
Source B: database/migrations/2026_08_24_000000_create_media_processing_substrate.php; docs/database/LF-SCHEMA-CONTRACT.json
Contradictory Requirements:
- Source A requires: CHECK vocabulary cho output_type; CHECK job_type virus_scan phải có output NULL; CHECK completed_at >= started_at; CHECK cặp billable_units/billable_unit_type
- Source B requires: sáu CHECK, không có bốn cái trên
Why They Cannot Both Be True: Không áp dụng — drift giữa tài liệu và schema đã chạy
Runtime/Business Impact: output_type hiện không bị ràng buộc vocabulary ở tầng database, nên một giá trị sai chính tả ghi được mà không lỗi. Ba CHECK còn lại là bất biến dữ liệu đang không được thi hành
Affected Implementation: app/Jobs/ProcessMediaProcessingJob::persistSuccess
Temporary Safety Rule: Không mô tả bốn CHECK này như đang có hiệu lực trong bất kỳ review nào
Required Decision: Migration của DOC-CONFLICT-0019 tạo mới CHECK output_type với đủ vocabulary, hay § Keys được sửa cho khớp schema hiện tại. Hai đường dẫn tới hai schema khác nhau
Resolution Authority: Database Owner cùng Architecture Owner
Resolution Plan: Hướng chuẩn bị được review khuyến nghị là tạo mới CHECK output_type với đủ sáu giá trị (`transcript`, `caption`, `extracted_text`, `variant`, `extracted_region`, `extracted_table`) trong cùng migration mở job_type. Ba CHECK còn thiếu khác phải được Database Owner quyết định rõ là tạo vật lý hay sửa § Keys; audit dữ liệu cũ và preflight bắt buộc trước khi thêm constraint. Không viết migration thứ ba cho tới khi quyết định này được ký và Round 3 pass
Target Review Date: 2026-09-01
Resolved At: Not resolved
Resolution: Not resolved
Superseded/Updated Documents: None
Verification Evidence: docs/database/LF-SCHEMA-CONTRACT.json § tables.media_processing_jobs.checks liệt kê đúng sáu CHECK
Related ADR/Review/Issue/PR: DOC-CONFLICT-0019; ADR-0004
Notes: Phát hiện phụ khi soạn amendment, không phải mục tiêu của đợt đối chiếu
```
