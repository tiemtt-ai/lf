# LF LiveClass Whiteboard Media Evaluation

Version: 1.11

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-08

Document Path: quality/LF-LiveClass-Whiteboard-Media-Evaluation.md

Target: [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) § LiveClass Whiteboard Amendment,
[LF-Core-LiveClass](../core/LF-Core-LiveClass.md) § Whiteboard,
[LF-Media](../platform/LF-Media.md) § Whiteboard Rendering Exception.

Owner duyệt hướng đi và trả lời 5 câu hỏi ngày 2026-11-08 (xem § Quyết định của Owner).
Đây là tài liệu đánh giá, không phải đặc tả: mỗi đợt phải sửa tài liệu đích ở trên trước
khi viết code, theo luồng Governance → ADR → Domain → Review → Implementation.

---

# 1. Bối cảnh

Khi thử lớp demo ngày 2026-11-08, Owner thấy tệp âm thanh không mở trong bảng. Đây không
phải lỗi: Rendering Exception chỉ cho **PDF và video** hiện trong bảng, mọi loại khác mở
tab mới. Âm thanh không được nhắc tới nên rơi vào nhóm "mở tab mới"; đây là thiếu sót về
phạm vi, không có lý do kỹ thuật. Tab mới phát được (đã thử: `audio/mpeg`, trình phát
chạy), nhưng chỉ trên máy giáo viên, học viên không nghe.

Hiện trạng LF theo loại tài liệu (trước đợt 1; nay xem § 6):

| Loại | Giáo viên | Học viên |
|---|---|---|
| PDF của bài | Trong bảng, vẽ lên được | Thấy cùng trang và nét vẽ |
| PDF từ máy giáo viên | Trong bảng | Trang trắng cùng kích thước kèm nét vẽ (tệp không rời máy giáo viên) |
| Video tải lên | Trong bảng | Phát theo giáo viên: phát, dừng, tua; vào muộn theo kịp |
| Video YouTube/Vimeo | Trong bảng | Chỉ nhận lệnh bắt đầu phát, chưa theo dừng và tua |
| Âm thanh | Tab mới | Không nghe |
| Word, PowerPoint, Excel | Tab mới | Không thấy |

---

# 2. AllVia đang làm thế nào

Đọc chỉ để tham khảo (`allviacl-frontend-dev`, `allviacl-backend-dev`), không sửa.

AllVia có hai công cụ tách riêng:

| | e-board (lớp offline) | VCR (lớp online, Amazon Chime) |
|---|---|---|
| Mở khi | Giáo viên bấm "Lớp offline", mở cửa sổ bật lên | Giáo viên bấm "Lớp online" |
| Trên bảng | PDF (pdf.js) và video (`<video>`) | Chỉ nét vẽ và chữ (Konva) |
| Âm thanh | Không có trình phát | Không có |
| Đồng bộ video | Không. Học viên chỉ nhận "đổi tài liệu", video đứng ở giây 0 | Không. Cả lớp xem bằng chia sẻ màn hình, bảng đè lên |
| Ai vẽ | Ai cũng vẽ trên máy mình; nét học viên là ghi chú riêng, không gửi | Giáo viên và tối đa 1 học viên được cấp quyền |
| Lưu | localStorage | Ảnh chụp trạng thái lên máy chủ |
| YouTube, ảnh, Office trên bảng | Không | Không |
| Hybrid | Không. Offline và online là hai sản phẩm | |

Điểm đáng chú ý:

* **Học viên e-board đang không đồng bộ.** Ticket NACL-443: học viên vào e-board bị báo
  "bị đuổi khỏi lớp" của VCR, nên phía học viên bỏ kết nối đồng bộ
  (`apps/student-app/src/app/(e-board)/e-board/EBoardApp.tsx:149-155`). Bảng học viên chạy
  độc lập.
* **Office sang PDF trên máy chủ** bằng Polaris Converter (`PolarisConversionPolicy`,
  `PdfConversionService`): doc/docx, ppt/pptx, xls/xlsx, hwp; chạy nền, hỏi trạng thái mỗi
  giây, quá 5 phút là lỗi; trạng thái PENDING, CONVERTING, COMPLETED, FAILED. Bảng không
  kiểm tra trạng thái, nên tệp chưa chuyển xong báo "không tải được PDF".
* **Tệp nằm trên CDN công khai, không ký.** pdf.js tải từ unpkg. Tin nhắn Chime giới hạn
  2 KB; e-board cắt theo byte nên có thể làm hỏng chữ Hàn trong ghi chú.
* **Mở khoá âm thanh iPhone** (VCR `utils/unlockAudio.ts`): phát một tệp WAV im lặng và
  resume AudioContext ngay trong thao tác bấm.
* Không có bản video nén hay HLS.

---

# 3. Giải pháp

**Mục tiêu.** Mọi Media của bài mở ngay trên bảng, giáo viên điều khiển, cả lớp cùng thấy
và cùng nghe đúng cách theo hình thức buổi học (offline, online, hybrid).

**Nguyên tắc.** Giáo viên điều khiển; mỗi máy tự xin link có chữ ký và tự phát bản của
mình, chạy theo giáo viên. Giọng giáo viên vẫn đi qua Zoom/Meet theo ADR-0002 § Hybrid
delivery; bảng không truyền giọng hay hình giáo viên.

Cột **Nguồn**: *Giữ* = LF đã có · *AllVia* = học từ AllVia · *Vượt AllVia* = khắc phục
chỗ AllVia làm chưa tốt · *Mới* = AllVia không có. **Công sức**: S nhỏ · M vừa · L lớn.

## A. Giữ nguyên

| # | Hạng mục | Vì sao giữ |
|---|---|---|
| A1 | Học viên đồng bộ qua Reverb, tách khỏi Zoom/Meet | AllVia phải tắt đồng bộ học viên (NACL-443) vì dính vào phòng họp |
| A2 | Link có chữ ký, ngắn hạn, mỗi máy tự xin | AllVia dùng CDN công khai |
| A3 | Một người trình chiếu, có giành quyền | AllVia không có |
| A4 | Vào muộn lấy ảnh chụp và nhật ký sự kiện trên máy chủ | AllVia nhờ máy giáo viên gửi lại, thiếu nét cũ |
| A5 | Gói sự kiện ≤ 8 KB, không cắt giữa một sự kiện | AllVia có thể hỏng chữ Hàn |
| A6 | pdf.js đóng gói trong LF | AllVia tải từ unpkg |
| A7 | PDF từ máy giáo viên không gửi đi | Riêng tư |

## B. Đợt 1: âm thanh và chế độ nghe

Không cần migration. Sửa `LF-Media` § Whiteboard Rendering Exception, `LF-Core-LiveClass`
§ Whiteboard và thêm ghi chú amendment vào ADR-0002.

| # | Hạng mục | Nguồn | Công sức | Rủi ro / lưu ý |
|---|---|---|---|---|
| B1 | Âm thanh phát trong bảng, đồng bộ phát, dừng, tua như video | Mới | S | Thấp, dùng lại cơ chế video |
| B2 | Chống lệch: giáo viên gửi vị trí mỗi 5 giây, lệch quá 1 giây thì chỉnh; vào muộn tính vị trí theo giờ máy chủ | Mới | S | Lệch còn khoảng 0,5–1 giây |
| B3 | Chế độ nghe trên máy học viên: "Ở lớp" (tắt tiếng) / "Từ xa" (có tiếng). Mặc định: offline → Ở lớp, online → Từ xa, hybrid → hỏi khi vào bảng, nhớ cho buổi đó. Đổi được bất cứ lúc nào | Mới | M | Xem § Quyết định 2, 3 |
| B4 | Nút "Bật tiếng" cho học viên, kèm mẹo mở khoá âm thanh iPhone | AllVia (VCR) | S | iPhone/Safari chặn tự phát có tiếng chặt nhất |
| B5 | "Xem trước riêng" / "Chiếu cho lớp" cho giáo viên | Mới | S | Thấp |
| B6 | Nhắc giáo viên không chia sẻ âm thanh máy qua Zoom khi đang phát trên bảng | Mới | S | Chỉ là dòng nhắc |

## C. Đợt 2: hoàn thiện đồng bộ và ghi chú riêng

| # | Hạng mục | Nguồn | Công sức | Rủi ro / lưu ý |
|---|---|---|---|---|
| C1 | YouTube/Vimeo đồng bộ đầy đủ: phát, dừng, tua, vị trí | Mới | M | **Đổi cách làm khi triển khai:** điều khiển iframe bằng `postMessage`, không nạp script của YouTube/Vimeo vào trang (tránh chạy mã bên thứ ba trong origin của tenant) |
| C2 | Ghi chú riêng của học viên: vẽ trên máy mình, lưu trên trình duyệt theo tài liệu, không gửi | AllVia (e-board) | M | **Đã có từ giai đoạn 2** (lớp riêng, khoá `:viewer`); không phải nộp bài |

## D. Đợt 3: Office sang PDF

Cần sửa ADR-0002 trước, vì § Not authorized here đang ghi "Office-to-PDF conversion".
**Xong 2026-11-08** (ADR-0002 § Whiteboard Office Preview Amendment); xem § 6.

| # | Hạng mục | Nguồn | Công sức | Rủi ro / lưu ý |
|---|---|---|---|---|
| D1 | Chuyển Word, PowerPoint, Excel sang PDF một lần khi tải lên, lưu thành variant `preview` trong `media_variants`, có trạng thái | AllVia (Polaris) | L | Dùng LibreOffice sẵn có trong LF. **Thêm khi triển khai (Owner đồng ý 2026-11-08):** hàng đợi riêng `media-preview`; admin thấy trạng thái và có nút Chuyển lại PDF |
| D2 | Bảng kiểm tra trạng thái trước khi mở; chưa xong hoặc lỗi thì mở tab mới | Vượt AllVia | S | AllVia không kiểm tra. Ngăn tài liệu tải lại danh sách mỗi lần mở và ghi rõ đang chuyển / lỗi |
| D3 | Bản PDF dùng chung vẽ, đồng bộ và ghi chú riêng | Kết hợp | S | PowerPoint mất hiệu ứng chuyển động |

## E. Đợt 4: hạ tầng, bắt buộc trước khi chạy lớp thật

**Hoãn (Owner chốt 2026-11-08):** làm sau khi triển khai production.

| # | Hạng mục | Nguồn | Công sức | Rủi ro / lưu ý |
|---|---|---|---|---|
| E1 | Giao tệp thẳng từ S3 (link có chữ ký, CORS chỉ cho origin của tenant), không qua PHP | Vượt AllVia | M | Máy dev hiện kẹt worker khi phát video dài |
| E2 | Bản video nén 720p, sau này HLS (`media_variants`) | Mới | L | Cần chỗ chạy ffmpeg |
| E3 | Giới hạn dung lượng và cảnh báo khi lớp đông | Mới | S | |

### Đề xuất video phát nhanh (faststart) — Owner duyệt 2026-11-08

Owner chọn làm ngay (2026-11-08), tách khỏi đợt 4. **Nguyên nhân đã đo:** video mẫu
(Media 86, 88 MB) có `mdat` ở byte 40 và `moov` ở byte 86.144.208, tức chỉ mục nằm cuối
tệp; Safari phải xin phần cuối trước khi phát, trên máy dev đi qua PHP nên mất 30–40
giây. Chạy thử `ffmpeg -map 0 -c copy -movflags +faststart` trên bản sao: 1,5 giây, cùng
dung lượng, `moov` lên ngay sau `ftyp`.

**Tài liệu (Owner duyệt 2026-11-08):**

* `LF-Media-Processing-Contract` 2.50 § Video board playback copy, và dòng `video` của
  § Fan-out theo file type.
* `LF-Media` 1.18: § Variants và § Whiteboard Rendering Exception (Video và âm thanh).
* Không đổi ADR: `transcode` đã là job tuỳ chọn của `video` trong Processing Contract và
  variant thuộc ADR-0004. Không đổi database docs: `transcode`, `variant`, `preview`
  đã có trong CHECK. **Không migration.**

**Rà soát theo Guardrails:**

| Điểm | Kết quả |
|---|---|
| Tenant isolation, `customer_id` | Variant mang `customer_id` của Media File, key bắt đầu `tenants/{customer_id}`, route chỉ phục vụ variant của Media File `ready` cùng tenant (như bản PDF) |
| Original immutable | Tệp gốc không bị sửa; bản chép là Derived Asset, xoá cùng Media File |
| Media sở hữu xử lý | Job chạy trong Media substrate; LiveClass chỉ đọc qua hàm cấp URL đã kiểm quyền |
| Không chặn | Job tuỳ chọn; thất bại thì bảng phát tệp gốc như hiện nay |
| Chi phí | Thêm chỗ lưu chỉ cho tệp cần sửa (khoảng bằng tệp gốc); CPU rất nhẹ vì không mã hoá lại |
| Phạm vi | Chỉ bảng điện tử dùng; trình phát bài học giữ tệp gốc (mở rộng là amendment riêng của Media Read Contract) |

**Đã làm (2026-11-08):** `VideoBoardPreview` (đọc thứ tự hộp MP4, tìm bản chép `ready`),
provider `local_video` (`LocalVideoPreviewProvider`: ffmpeg, kiểm thứ tự hộp, dung lượng,
thời lượng rồi mới lưu), job tạo khi gắn video vào Activity, `persistBoardPreview` dùng chung
với bản PDF, route `media.variants.signed` trả MIME của row và hỗ trợ Range, hàm cấp URL của
bảng tự chọn bản chép; lệnh `media:video-faststart`. Media 86 (88 MB) chép trong 2 giây; trên
iPhone giả lập video hiện ở giây 1,7 (trước 30–40 giây). Máy dev phải chạy `php artisan serve`
với `--no-reload` (thiếu cờ này thì chỉ có 1 worker, luồng video chặn sự kiện của giáo viên);
production chạy php-fpm nên không bị.

## F. Không làm, có chủ đích

| Hạng mục | Lý do |
|---|---|
| Cho cả lớp xem tài liệu bằng chia sẻ màn hình (cách VCR) | Mờ, tốn băng thông, không vẽ đè chính xác |
| Tách offline và online thành hai công cụ | Không làm được hybrid |
| Tệp trên CDN công khai | Không bảo đảm tách biệt tenant |
| Xem Office bằng Office Online | Phải gửi tệp ra Microsoft |
| Truyền giọng/hình giáo viên trong bảng (WebRTC) | ADR-0002 chưa cho phép |

---

# 4. Theo hình thức buổi học

| Hình thức | Máy giáo viên | Máy học viên (mặc định) |
|---|---|---|
| Offline | Phát tiếng ra loa phòng hoặc máy chiếu | "Ở lớp": thấy bảng và Media, tắt tiếng, xem gần trên máy mình |
| Online | Phát tiếng (nên đeo tai nghe) | "Từ xa": có tiếng, đồng bộ |
| Hybrid | Phát tiếng cho phòng | Hỏi khi vào bảng, nhớ cho buổi đó |

Ở mọi hình thức, học viên đều được mở bảng, dù ngồi trong lớp hay ở xa; chế độ chỉ quyết
định máy đó có phát tiếng hay không, và học viên tự đổi được.

---

# 5. Quyết định của Owner (2026-11-08)

1. **Phạm vi đợt 1 (B1–B6):** đồng ý.
2. **Buổi hybrid:** đồng ý hỏi học viên khi vào bảng và nhớ cho buổi đó. Lấy theo điểm
   danh để sau.
3. **Học viên ở buổi offline có mở bảng không:** không ai cấm. Học viên ở xa hay gần đều
   mở được nếu muốn; không bắt buộc. Máy trong lớp mặc định "Ở lớp" (tắt tiếng) để tránh
   vọng âm, học viên tự chuyển sang có tiếng nếu dùng tai nghe.
4. **Office sang PDF bằng LibreOffice chạy trên máy chủ:** đồng ý. Vẫn cần sửa ADR-0002
   trước đợt 3.
5. **Lưu trữ khi chạy thật:** S3. Đợt 4 thiết kế theo S3 (presigned URL, CORS theo origin
   tenant). Owner chốt 2026-11-08: làm sau khi triển khai production.

---

# 6. Kết quả

| Đợt | Trạng thái | Tài liệu đích | Test |
|---|---|---|---|
| 1 (B1–B6) | Xong 2026-11-08 | `LF-Media` 1.15, `LF-Core-LiveClass` 2.12, ADR-0002 § Whiteboard Media Amendment | `liveclass-whiteboard-media.test.mjs`, `LiveClassWhiteboardTest` |
| 2 (C1; C2 có từ giai đoạn 2) | Xong 2026-11-08 | `LF-Media` 1.16, `LF-Core-LiveClass` 2.13 | `liveclass-whiteboard-embed.test.mjs` |
| 3 (D1–D3) | Xong 2026-11-08 | ADR-0002 § Whiteboard Office Preview Amendment, Processing Contract 2.49, `LF-Media` 1.17, `LF-Core-LiveClass` 2.14, `LF-Tech-Runtime-Requirements` 1.9 | `DocumentProcessingLocalReviewTest` (LibreOffice thật), `MediaProcessingSubstrateTest` |
| Faststart | Xong 2026-11-08 | Processing Contract 2.50, `LF-Media` 1.18 | `VideoBoardPreviewTest` |
| 4 (E1–E3, S3) | **Hoãn đến sau khi triển khai production** (Owner chốt 2026-11-08) | Sửa tài liệu và rà soát trước khi code | |

Cả bộ test sau đợt cuối: 1846 đạt, 23 bỏ qua, 0 lỗi; JS 372 đạt.

**Thử trên thiết bị (2026-11-08, giáo viên ở Chrome, buổi 30):**

| Môi trường | Kết quả |
|---|---|
| Chrome máy tính | Đạt: PDF, âm thanh, video, YouTube/Vimeo (phát, dừng, tua, vào muộn), Office→PDF (PPTX), Ở lớp/Từ xa. Học viên chưa bấm gì vẫn có tiếng ngay (Chrome tính lần bấm trước đó trên cùng trang web) |
| iPhone 16 Pro giả lập (Safari iOS) | Đạt: PDF lật trang theo; âm thanh, video MP4 khớp vị trí; YouTube; xoay ngang; khoá rồi mở màn hình thì theo kịp; bấm "Bật tiếng" một lần giữ tiếng cho cả buổi, kể cả mở lại và đổi giữa video và MP3 |
| Safari Mac, Firefox, Edge, máy thật | Chưa thử. Safari Mac cần Owner bật Allow Remote Automation |

**Lỗi tìm thấy khi thử, đã sửa:** trang PDF ngang tràn trên điện thoại dựng đứng (100% nay là
cả trang, `board-view.js`, `LF-Core-LiveClass` 2.15); khung video che nút chế độ nghe; iOS tự
phóng to ô nhập (chữ tối thiểu 16px, chỉ iOS); "Bật tiếng" mất khi mở lại Media (Safari iOS cho
tiếng theo từng phần tử: bảng dùng lại một `<audio>` và một `<video>` qua `createMediaPool`, cú
bấm mở khoá cả hai loại qua `primeElement`); video mở chậm (faststart, § E).

**Giới hạn đã biết:** YouTube/Vimeo trên iPhone phải bấm "Bật tiếng" lại cho mỗi video mới
(khung nhúng mới mỗi lần). iPhone không cho trang web toàn màn hình; iPad thì có.

**Cần khi chạy thật:** worker nghe hàng đợi `media-preview`, có LibreOffice, ffmpeg, ffprobe;
tệp Office tải lên trước đợt 3 chỉ có bản PDF khi admin bấm Chuyển sang PDF; video gắn trước
faststart chạy `media:video-faststart --customer=<id>`. Trước đợt 4 tệp vẫn đi qua PHP, nên lớp
đông phát video dài có thể chậm.
