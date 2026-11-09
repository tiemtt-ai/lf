# ADR-0023 — Cohort Content Order

Version: 1.5

Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-09

Proposal Date: 2026-11-05

Approval Date: 2026-11-05 (bản 1.3 ghi quyết định cả box đi theo bài và cách 1 của Owner; bản 1.4 ghi việc lưu Lịch học tự tạo Session; bản 1.5, Owner duyệt 2026-11-09, ghi việc hủy buổi thì dời các bài sau)

Document Path: adr/ADR-0023-Cohort-Content-Order.md

Related ADRs:

* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md) — Schedule, Occurrence, Origin và Session.
* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md) — Version bất biến; ADR này không sửa Version.
* [ADR-0015 — Course Lesson Multiple Prerequisites](ADR-0015-Course-Lesson-Multiple-Prerequisites.md) — quy tắc mở khóa được đóng băng khi xuất bản.

---

# Context

Tab Buổi học của lớp hiển thị cây nội dung của Version mà lớp đã khóa, và ghép
ngày dự kiến từ Schedule theo thứ tự bài (đợt 1, không có bảng mới). Owner muốn
mỗi lớp được kéo thả thứ tự như tab Nội dung của Template, thay đổi chỉ có hiệu
lực trong lớp đó.

Version đã xuất bản là bất biến nên thứ tự của lớp không thể nằm trong bảng
Version. Owner đã chốt ngày 2026-11-05:

* Kéo thả chỉ đổi vị trí nội dung. Ngày gắn với vị trí: bài ở vị trí nào nhận
  ngày ở vị trí đó.
* Lịch đã qua bị khóa, không được sửa và không được kéo thả.
* Học viên thấy thứ tự của lớp mình.

Hiện trạng đã kiểm tra trong code:

* Chưa có trình phát bài học cho học viên, điều hướng bài trước/sau, ghi tiến độ
  hay chứng chỉ. Quyết định về học viên ở đây là hợp đồng cho phần sẽ xây sau.
* Khi chạy, quy tắc mở khóa không đọc thứ tự nội dung; chúng dùng dữ liệu đã
  đóng băng. `all_previous_lessons_completed` được đóng băng theo vị trí lúc
  xuất bản.
* `sort_order` của Activity chỉ để hiển thị.
* Bảng tiến độ có cột `sort_order` nhưng chưa có code ghi hay đọc; bảng tiến độ
  không có `cohort_id`.
* Mỗi Enrollment có tối đa một dòng thành viên lớp. Chuyển lớp chỉ được sang lớp
  cùng Product và Version.
* Tab Nội dung của Template chỉ sắp xếp Lesson trong cùng Section (hoặc cùng
  nhóm bài trực tiếp) và Activity trong cùng Lesson.

---

# Decision

## 1. Thứ tự của lớp là lớp phủ riêng

Thêm hai bảng do Cohort sở hữu:

* [`core_course_cohort_lesson_orders`](../database/course/core_course_cohort_lesson_orders.md)
* [`core_course_cohort_activity_orders`](../database/course/core_course_cohort_activity_orders.md)

Không có dòng nào nghĩa là lớp đang dùng thứ tự của Version. Bảng Version và
Template không thay đổi, và thứ tự của lớp không được sao chép khi xuất bản hay
nhân bản Version.

## 2. Phạm vi kéo thả giống tab Nội dung

Chỉ sắp xếp trong cùng cấp:

* Lesson trong cùng Section, hoặc trong nhóm bài trực tiếp.
* Activity trong cùng Lesson.

Không chuyển Lesson sang Section khác, không chuyển Activity sang Lesson khác,
không sắp xếp lại Section. Session gắn với cặp Lesson và Activity của Version và
tiến độ bài tính từ Activity của bài, nên chuyển khác cấp cần ADR riêng.

## 3. Ngày thuộc về vị trí, cả box đi theo bài

Owner chốt 2026-11-05: kéo là kéo toàn bộ box nội dung; chỉ ngày giờ đứng yên và hiện lần lượt theo vị trí. Sửa ngày là sửa ngày của vị trí đó. Bản 1.3 thay quy tắc giáo viên và phòng ở lại vị trí của bản 1.2.

Vị trí là danh sách phẳng các Activity `live_class` theo thứ tự của lớp. Một lần kéo làm như sau, trong một transaction:

1. Ghép danh sách cũ và mới theo chỉ số.
2. Dòng Session sửa được ở vị trí i giữ giờ, Origin và số buổi, nên không ghi `core_liveclass_session_schedule_changes`.
3. Dòng đó nhận box của Activity mới ở vị trí i: Lesson, Activity, `title`, hình thức, link, phòng, địa chỉ và giáo viên.
4. Activity mới chưa có Session thì mang box mặc định: tên Lesson, online, link của Activity, chưa có giáo viên riêng. Trên giao diện, buổi chưa có giáo viên hiện giáo viên của lớp.
5. Activity đã có Session chuyển sang vị trí chưa lưu thì được lưu tại ngày gợi ý của vị trí đó, kèm Origin, để không mất thông tin đã nhập.

Backend từ chối cả lần kéo khi:

* Activity đã có Session chuyển sang vị trí không có ngày.
* Giáo viên trong box không dạy được ở ngày mới; thông báo nêu tên giáo viên và ngày.
* Một Activity trong đoạn đổi có hơn một Session sửa được, vì khi đó một vị trí có nhiều ngày.

Giới hạn và biên:

* Đoạn bị đổi là các chỉ số có Activity khác trước.
* Session `cancelled` và Session ngoài chương trình không được gắn lại. Session `cancelled` ở lại Activity của nó và vẫn hiện ngày cũ tại vị trí mới của Activity đó.
* Session thủ công không có Origin chiếm một vị trí mà không tiêu thụ occurrence, nên các ngày chưa lưu phía sau lùi một vị trí. Chấp nhận ở giai đoạn này.
* Đây không phải đồng bộ theo Schedule mà ADR-0002 còn hoãn: không có Session nào đổi giờ, và lần kéo chỉ tạo Session trong trường hợp 5.
* Bản 1.4: lưu Lịch học đã tự tạo Session (ADR-0002, Automatic Session Generation Amendment), nên ngày chưa lưu chỉ còn ở trường hợp ít gặp, ví dụ sau khi hủy buổi. Các quy tắc trên vẫn áp dụng cho những ngày đó.

## 4. Khóa những gì đã qua

Owner chốt 2026-11-05: buổi đã qua thì khóa, không phụ thuộc học viên có mặt hay vắng mặt,
kể cả buổi đã hủy.

Một Session gây khóa khi:

* Đã đến giờ bắt đầu, ở mọi trạng thái.
* Trạng thái `live`, `completed` hoặc `no_show`.
* Có `actual_start_at`.
* Có Attendance hoặc Recording, qua một hàm kiểm tra evidence dùng chung.

Session `cancelled` chưa đến giờ và không có evidence không gây khóa. Lesson bị khóa khi
có Activity bị khóa.

Backend từ chối lần kéo nếu:

* Activity bị khóa đổi vị trí trong danh sách phẳng, hoặc đổi chỉ số trong Lesson chứa nó.
* Lesson bị khóa đổi chỉ số trong phạm vi cùng cấp.

Giao diện ẩn tay kéo, nhưng backend mới là nơi quyết định.

## 5. Không vượt qua quy tắc mở khóa

Thứ tự của lớp không thay đổi quy tắc đã đóng băng trong Version, và ADR-0015 không bị
sửa (Owner chốt 2026-11-05). Với thứ tự Lesson mới trong cùng cấp, backend đọc:

* `core_course_template_version_lesson_prerequisites` theo `customer_id` và
  `template_version_id`.
* `core_course_template_version_lessons.unlock_after_version_lesson_id`.

Nếu cả hai đầu cùng cấp mà bài điều kiện không đứng trước bài phụ thuộc thì từ chối, bất
kể `prerequisite_match_snapshot`. `all_previous_lessons_completed` không cần xử lý riêng vì đã
được mở thành các dòng điều kiện lúc xuất bản. Hệ quả đã được chấp nhận: lớp của khóa học
tuần tự gần như không kéo được bài. `CourseTemplateLessonUnlockOrder` đọc bảng working nên
không dùng lại được.

Lesson mở theo ngày (`date_based`) không có dòng điều kiện, nên một lần kéo có thể đặt buổi
học trước ngày mở. Owner chốt 2026-11-05: cho phép, giống dời lịch hiện tại.

Với Activity, thứ tự vốn chỉ để hiển thị và điều kiện không bắt buộc phải đứng trước, nên
chỉ kiểm tra khi có ngày: nếu cả Activity và Activity điều kiện của nó
(`unlock_after_version_activity_id`) đều là `live_class`, backend từ chối lần kéo làm bài
điều kiện từ chỗ đứng trước thành đứng sau. Thứ tự sẵn có của Version không bị từ chối.

## 6. Một điểm đọc duy nhất

Thêm một dịch vụ đọc thứ tự theo Cohort. Mọi màn hình trong ngữ cảnh lớp đọc qua
dịch vụ đó:

* Cây tab Buổi học và ngày dự kiến.
* Danh sách chọn bài trong form và tạo hàng loạt.
* Trình phát, điều hướng và danh sách bài của học viên sau này.

Các màn hình quản trị Template và Version, luồng xuất bản, nhân bản và AI vẫn đọc
thứ tự của Version.

## 7. Học viên

* Học viên có thành viên `active` trong lớp hoạt động hoặc đã hoàn thành thấy thứ tự của
  lớp đó, cùng điều kiện với kiểm tra quyền Activity.
* Enrollment không có lớp, hoặc thành viên đã kết thúc, thấy thứ tự của Version.
* Chuyển lớp: học viên thấy thứ tự của lớp mới; tiến độ và hoàn thành không đổi
  vì cùng Version và cùng ID.
* `sort_order` trong bảng tiến độ giữ nghĩa thứ tự của Version. Thứ tự của lớp
  luôn tính khi đọc, không chụp vào tiến độ.

## 8. Ràng buộc tenant và Version

Mọi đọc và ghi theo `customer_id`. Lớp phải có `version_id`, giá trị này không đổi sau khi
gán. Thao tác phải qua `CourseCohortMutationPolicy::canMutate` và cùng kiểm tra khóa học
chạy theo lớp như các thao tác buổi học hiện có.

Backend kiểm tra Lesson và Activity thuộc đúng Version của lớp, và danh sách gửi lên phải
đủ toàn bộ mục cùng cấp, không thừa, không trùng.

Transaction khóa dòng Cohort rồi các dòng Session bị ảnh hưởng, và đánh giá mục 4 bên trong
transaction với cùng một mốc thời gian.

Form buổi học mở trước một lần kéo không được ghi đè kết quả của lần kéo đó. Form gửi kèm
Lesson và Activity lúc mở; `updateSession` khóa dòng Cohort và từ chối nếu Session đã được
gắn sang Activity khác. `storeSession` từ một ngày trên chương trình cũng từ chối nếu
occurrence đó không còn là ngày của Activity đã chọn. Cách này không cần cột mới.

## 9. Hủy buổi thì dời các bài sau (bản 1.5)

Owner duyệt 2026-11-09. Trước bản này, hủy buổi của bài k chỉ để trống vị trí đó; ngày gợi ý
cho buổi bù là ngày trống đầu tiên, thường nằm sau các bài k+1, k+2, nên học viên học bài sau
trước bài trước. Từ bản 1.5:

* Hủy một Session theo chương trình chưa gây khóa (mục 4) làm ngày của nó bị bỏ qua. Bài k và
  mọi Activity `live_class` phía sau lùi một vị trí: box của Activity i (tên, hình thức, link,
  phòng, địa chỉ, giáo viên) chuyển sang ngày của vị trí i+1, như một lần kéo ở mục 3. Không
  Session nào đổi giờ, nên không có dòng audit đổi lịch.
* Box của bài k lấy từ Session vừa hủy. Session vừa hủy ở lại Activity của nó làm lịch sử,
  với `superseded_by_session_id` trỏ tới Session đang mang bài k sau khi dời.
* Activity cuối nhận ngày trống tiếp theo của Lịch học: nếu nó đã có Session thì được lưu tại
  ngày đó kèm Origin (mục 3, trường hợp 5); không còn ngày trống thì bài đó hiện "Chưa có ngày".
* Không dời, chỉ hủy, khi: Session đã gây khóa (đã đến giờ, `live`, có evidence); Activity của
  nó vẫn còn một Session khác đang giao; hoặc một vị trí phía sau bị khóa, có hơn một Session
  sửa được, hoặc phải lưu vào vị trí không có ngày. Thông báo sau khi hủy nói rõ có dời hay không.
* Giáo viên trong box phải dạy được ở ngày mới; nếu không, cả lần hủy bị từ chối và thông báo
  nêu tên giáo viên và ngày.
* Cùng transaction với việc hủy, cùng thứ tự khóa của mục 8. Không đổi schema.

---

# Deferred

Thông báo cho học viên khi thứ tự đổi lúc lớp đang chạy: Owner chốt làm sau. Khi
làm cần xét riêng, vì hiện chưa có màn hình học cho học viên.

---

# Consequences

* Mỗi lớp có thứ tự riêng mà không ảnh hưởng Template, Version hay lớp khác.
* Mọi màn hình của học viên sau này phải đọc qua dịch vụ ở mục 6, nếu không sẽ
  hiện sai thứ tự.
* Mục 7 chưa có code vì chưa có màn hình học cho học viên. Phần còn lại đã triển khai.

---

# Implementation Gate

Owner duyệt bản 1.2 ngày 2026-11-05; Architecture Review đạt; hai tài liệu bảng đã Freeze.
Schema đã được kiểm chứng trên MariaDB 11.4 bằng `schema:drift --fresh` ngày 2026-11-05.
