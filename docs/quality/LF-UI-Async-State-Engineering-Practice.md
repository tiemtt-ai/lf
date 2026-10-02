# LF UI Async-State Engineering Practice

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-30

Document Path: quality/LF-UI-Async-State-Engineering-Practice.md

---

# Vì sao có tài liệu này

Giao diện duyệt đề xuất AI (P3-A, [thiết kế](../platform/LF-AI-Authoring-Review-UI-Design.md)) cần sáu lượt review
độc lập trước khi hết lỗi mức HIGH. Số lỗi HIGH theo lượt implementation là 5, 2, 1, 1, 1, 0 (báo cáo
[lượt 1](LF-AI-Authoring-Review-UI-P3A-Review.md), [2](LF-AI-Authoring-Review-UI-P3A-Review-Round2.md),
[3](LF-AI-Authoring-Review-UI-P3A-Review-Round3.md), [4](LF-AI-Authoring-Review-UI-P3A-Review-Round4.md),
[5](LF-AI-Authoring-Review-UI-P3A-Review-Round5.md), [6](LF-AI-Authoring-Review-UI-P3A-Review-Round6.md)).
Bốn lượt liên tiếp cùng một họ lỗi: một luồng tự làm mới trạng thái rồi xoá dữ liệu người dùng đang nhập. Phần lớn
số lượt đó do cách làm của bên implement, không do yêu cầu quá khó. Tài liệu này ghi lại điều cần làm khác đi để
không lặp lại ở P3-B/P3-C và ở bất kỳ giao diện nào có nhiều luồng bất đồng bộ dùng chung trạng thái.

# Phạm vi

Áp dụng cho mọi mã giao diện phía trình duyệt (JavaScript trong Blade/Alpine hoặc module riêng) đáp ứng đủ một
trong các điều kiện: gọi API bất đồng bộ song song, giữ nội dung nhạy cảm đã được ủy quyền, hoặc giữ dữ liệu người
dùng đã nhập mà chưa gửi. Không thay thế [LF-Regression-Audit](LF-Regression-Audit.md); nó bổ sung cách chứng minh
cho mức `HIGH` trong các trường hợp đó.

# Các nguyên tắc

## 1. Viết bất biến trước khi viết logic

Trước dòng mã đầu tiên, liệt kê những điều **luôn phải đúng**, bằng câu ngắn kiểm chứng được. Ví dụ của P3-A:

* Dữ liệu người dùng đã nhập mà chưa gửi không bao giờ mất mà không có câu hỏi, ngoại trừ khi mất quyền.
* Mất quyền gỡ mọi nội dung khỏi trang **và** khỏi bộ nhớ của script, kể cả tham chiếu tới phần tử đã tháo.
* Một phản hồi đến muộn không hiển thị gì và không kích hoạt việc đọc lại.
* Thử lại một lệnh chưa rõ kết quả gửi đúng mã yêu cầu và đúng nội dung đã gửi.

Mỗi bất biến trở thành ít nhất một test (nguyên tắc 5) trước khi có cách sửa.

## 2. Một khái niệm, một định nghĩa, một nơi

Mỗi khái niệm mà nhiều luồng cùng dựa vào chỉ có **một** định nghĩa. Ở P3-A: `hasDraft()` và `hasUnsent()` (thế nào là
"chưa gửi"), `revoke()` (thế nào là mất quyền), `refreshOpen()` (đường duy nhất tự đọc lại đề xuất đang mở).
Loại dữ liệu nhập mới phải được thêm vào định nghĩa chung, không được tự xử lý riêng ở luồng của nó. Khi thấy hai
luồng cùng quyết định một điều bằng hai đoạn mã khác nhau, đó là lỗi thiết kế, không phải chuyện chi tiết.

## 3. Hàm nền phải an toàn theo mặc định

Hàm làm mới, tải lại, đóng, hoặc thử lại không được phá hủy trạng thái trừ khi người gọi **nói rõ** (tuỳ chọn có tên,
mặc định tắt). Ví dụ: `loadList()` không bao giờ đóng đề xuất đang mở; chỉ `{ closeDetail: true }`, dùng duy nhất khi đổi
bộ lọc sau khi đã hỏi. Cách này biến "nhớ đừng xoá" thành "phải chủ ý mới xoá", và loại cả một họ lỗi thay vì từng nơi gọi.

## 4. Mọi `await` có kiểm tra thế hệ

Sau mỗi `await` (mạng, hộp xác nhận, đọc lại), trước khi chạm vào trạng thái, DOM hoặc thông báo, kiểm tra rằng ngữ
cảnh vẫn là của lần gọi này: bộ đếm thế hệ theo đối tượng (`detailSeq`, `listSeq`) và bộ đếm mất quyền (`epoch`). Điều này
gồm cả lời thông báo cho trình đọc màn hình và việc đọc lại kế tiếp: phản hồi cũ không được ghi đè kết quả mới hơn.

## 5. Dựng bộ test đối kháng trước, không sau

Bộ test hành vi (Node với DOM giả tối thiểu, `tests/js`) có mặt **trước hoặc cùng lúc** với logic. Nó phải có:

* **Giữ phản hồi rồi trả muộn** (promise trì hoãn) cho từng luồng: lưu, quyết định, bulk, tạo yêu cầu, đọc trạng thái.
* **Quét mọi nút:** nhập mọi loại dữ liệu chưa gửi, trả lời mọi câu hỏi bằng "huỷ", bấm từng nút, và **nhìn ngay sau mỗi
  lần bấm** (không chỉ nhập giá trị mới rồi kiểm lệnh gửi).
* **Mất quyền giữa chừng:** kiểm cả đường đi lên từ tham chiếu đang giữ tới phần tử cha, và giá trị thật của ô nhập.
* **Bẫy sink:** DOM giả từ chối `innerHTML`, `outerHTML`, `insertAdjacentHTML`, nên mọi đường tới chúng, viết theo
  cách nào, thất bại khi chạy. Quét mã nguồn chỉ là lớp phụ, vì regex không chứng minh được mọi cách viết.

## 5a. Kiểm tra bằng đột biến trước khi gửi review

Tự đột biến mã của mình trên **bản sao** (không phải trên repo): bỏ từng kiểm tra `epoch`, bỏ từng nhánh làm rỗng, đổi
mặc định của hàm nền, bỏ một loại dữ liệu khỏi định nghĩa chung. Mỗi đột biến phải làm ít nhất một test đỏ. Đột biến
sống sót là test thiếu; đột biến tương đương (không đổi hành vi) thì ghi lại là tương đương, không ép test đỏ.

## 6. Đi thử từ menu như người dùng

"Đã kiểm trên trình duyệt" chỉ có nghĩa khi đã đi **đường bấm chuột thật** từ menu tới chức năng, với loại dữ liệu thật
(loại Hoạt động có Media, không chỉ loại dễ). Gõ thẳng địa chỉ không chứng minh người dùng tới được. Ở P3-A, mục AI
đã hoàn thành mà không có đường vào từ danh sách Hoạt động cho chính các Hoạt động cần nó (D11); chỉ phát hiện khi người
dùng hỏi. Mỗi chức năng phải có một dòng "đường vào" trong thiết kế và một test cho nó.

## 7. Phát biểu phạm vi kiểm chứng đúng bằng phạm vi đã kiểm

Không viết "đã rà mọi đường" nếu danh sách đó không do công cụ hay test sinh ra. Viết cái gì đã được kiểm, bằng cách
nào, và cái gì chưa (BFCache thật, hai tab, trình đọc màn hình...). Ở P3-A, hai lần khẳng định "không còn đường nào xoá
bản nháp" đều sai.

## 8. Sửa lớp lỗi, không chỉ ví dụ

Khi review chỉ ra một lỗi: (a) xác định bất biến bị vi phạm, (b) sửa ở hàm nền hoặc định nghĩa chung, (c) thêm test
quét hoặc đối kháng cho cả lớp, (d) tìm chủ động các "anh em" của lỗi (cùng nguyên nhân, luồng khác, loại dữ liệu khác)
**trước** khi gửi lại, (e) mới sửa chỗ cụ thể nếu còn cần. Vá đúng chỗ reviewer chỉ ra mà không làm (a)–(d) là nguyên nhân
chính của chuỗi lượt review kéo dài.

## 9. Lát nhỏ, review từng lát

Chia thành lát nhỏ có ranh giới rõ, mỗi lát có test đối kháng và bảng đối chiếu bất biến riêng, review từng lát. Không
gom nhiều luồng bất đồng bộ tương tác lẫn nhau (sửa, quyết định, bulk, tạo, thử lại) vào một lượt review lớn nếu chưa
có định nghĩa chung của nguyên tắc 2.

# Danh sách kiểm trước khi gửi review

Chỉ gửi reviewer khi tất cả đã làm và có bằng chứng:

- [ ] Bất biến đã liệt kê; mỗi bất biến có ít nhất một test đối kháng.
- [ ] Mỗi khái niệm dùng chung có một định nghĩa; loại dữ liệu nhập mới đã nằm trong định nghĩa chung.
- [ ] Mọi hàm nền an toàn theo mặc định; các tuỳ chọn phá hủy có tên và chỉ một nơi gọi có lý do.
- [ ] Mọi `await` có kiểm tra thế hệ; đã thử "giữ phản hồi rồi trả muộn" cho từng luồng.
- [ ] Test quét mọi nút chạy với mọi loại dữ liệu chưa gửi, nhìn ngay sau mỗi lần bấm.
- [ ] Đã tự đột biến ít nhất các mục ở 5a; không còn đột biến có tác động sống sót.
- [ ] Đã đi từ menu tới chức năng bằng loại dữ liệu thật, ghi lại đường bấm.
- [ ] Bảng "đã kiểm / chưa kiểm" viết đúng phạm vi, không dùng "mọi".
- [ ] Toàn bộ test mặc định, `pint --test`, `docs:lint`, `git diff --check`, `npm run build` đạt.

# Owner

Architecture Team

# Primary Consumers

* Frontend Developers
* Backend Developers (khi bàn giao API cho giao diện bất đồng bộ)
* Reviewers độc lập

---

End of LF-UI-Async-State-Engineering-Practice
