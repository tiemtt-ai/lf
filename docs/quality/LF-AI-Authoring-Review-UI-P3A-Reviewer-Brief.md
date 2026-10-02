# AI Authoring Review UI — P3-A Implementation Reviewer Brief

Version: 1.6

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-29

Document Path: quality/LF-AI-Authoring-Review-UI-P3A-Reviewer-Brief.md

---

# Vì sao có brief này

P3-A là giai đoạn đầu của giao diện duyệt đề xuất AI (Phần 3): mục "Đề xuất AI" trên trang
chi tiết Hoạt động của Course Template, cho phép giáo viên có phân công và admin xem danh
sách, đọc chi tiết, sửa, chấp nhận/từ chối, tạo yêu cầu sinh và duyệt hàng loạt các đề xuất
dạng chữ. Thiết kế đã qua ba lượt review độc lập và Owner đã duyệt
([thiết kế v0.6](../platform/LF-AI-Authoring-Review-UI-Design.md), §10). Bốn lát cắt đã code
xong và đã kiểm bằng test cùng trình duyệt trên database tạm (§13 của thiết kế). Việc kiểm
đó do **chính implementer** làm nên **không phải review độc lập**. Theo AGENTS.md và mức
audit HIGH (LF-Regression-Audit), P3-A cần review implementation độc lập trước khi coi là
đóng và trước khi mở P3-B.

Verdict yêu cầu: `APPROVE`, `APPROVE WITH CHANGES` hoặc `REJECT`, cho từng câu hỏi và cho
toàn P3-A.

## Phạm vi

Trong phạm vi: bốn lát P3-A của [thiết kế §8.1](../platform/LF-AI-Authoring-Review-UI-Design.md)
và §13.

| Lát | Nội dung |
| --- | --- |
| 1 | Mục "Đề xuất AI" trên trang Hoạt động, hiện theo quyền AI |
| 2 | Danh sách, bộ lọc, chi tiết chỉ đọc; lệnh dữ liệu mẫu `ai:authoring-demo-seed` |
| 3 | Sửa, chấp nhận/từ chối một đề xuất qua hộp xác nhận chung |
| 4 | Tạo yêu cầu sinh (`generation-requests`), duyệt hàng loạt |

Ngoài phạm vi (không đánh giá là thiếu): `reuse_existing` accept và bộ chọn Node,
application/target/retry/cancel, successor, rebase, hành động admin duyệt Node (P3-B/P3-C);
`allowed_actions` mở rộng và DTO tên/ứng viên Node (cần amendment Owner duyệt riêng); mockup;
hiệu năng danh sách lớn; chất lượng nội dung AI thật. Không có thay đổi service, route, API
hay schema AI trong P3-A; có một hàm đọc mới `CourseAuthoringContextService::authoringRole()`
và thay đổi controller web của trang Hoạt động.

---

# Điều reviewer phải tự kiểm chứng, không dựa vào implementer

| Khẳng định | Nơi kiểm |
| --- | --- |
| Mục AI chỉ hiện khi `authoringRole()` khác null; quyền vào trang là quyền rộng hơn và **không** được dùng thay | `CourseTemplateActivityController::aiAuthoringEntry`, `CourseAuthoringContextService::authoringRole` / `actorRole`, so với `AiAuthoringProposalService` / `proposalContext` (cùng một `actorRole`) |
| Không có nội dung AI, Media hay lý do nào trong HTML ban đầu của trang; chỉ có URL, cờ, `csrfToken`, khung và bộ thông điệp i18n | `partials/ai-authoring.blade.php`, `AiAuthoringHostSectionTest` |
| JS không dùng `innerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, `Function`, `localStorage`, `sessionStorage`, `indexedDB`, `caches`, `serviceWorker`, `console`, `history` hoặc `location` để mang nội dung | `resources/js/ai-authoring.js`, `AiAuthoringScriptProtocolTest` (kiểm test có thật sự bắt, không chỉ tin) |
| Mọi phản hồi bất đồng bộ được kiểm mã thế hệ ngay sau `await`; 401/403/404/503 hoặc `content_denied` gỡ nội dung khỏi DOM và bộ nhớ script | `ai-authoring.js` (`openDetail`, `loadList`, `runCommand`, `runBulk`, `runCreate`), thiết kế §4.1 |
| Lệnh ghi đóng băng body và guard rồi mới gửi; retry sau kết quả chưa biết dùng cùng UUID + cùng body; đổi nội dung/guard hoặc sau khi đọc lại thì UUID mới | `frozenId`, `rememberUnknown`, `runCommand`, `runCreate`, `runBulk`; thiết kế §4.5 |
| Bulk: tối đa 100, mỗi dòng UUID riêng, chỉ dòng đã có chi tiết hợp lệ của đúng revision, kết quả đọc từng dòng, thử lại chỉ dòng chưa rõ với body gốc | `selectBox`, `bulkDecide`, `runBulk`, `showBulkResult` |
| Bảng lỗi §4.6 được ánh xạ đủ 10 mã `AI_*`, không hiện `details`/mã thô, `AI_QUOTA_EXCEEDED` không nói "đã hết hạn mức" | `GATE_MESSAGES`, `failureMessage`, khoá `LF_ai_authoring_*` VI/EN |
| `ai:authoring-demo-seed` từ chối ngoài `local`/`testing` **trước mọi truy cập DB**, kể cả cờ ép; không mạng, không allow-list bền vững, không secret thật; không ghi vào thư mục media của tenant đã tồn tại | `AiAuthoringDemoSeed.php`, `app/Support/AiDemo/*`, `AiAuthoringDemoSeedMariaDbTest` |
| VI/EN có đúng cùng bộ khoá `LF_ai_authoring_*` | `resources/lang/{vi,en}/lf.php` và test tương ứng |

---

# Câu hỏi

1. **Quyền tại host.** Điều kiện hiện mục AI có đúng với quyền mà mọi lệnh ghi kiểm lại
   (admin hoạt động; teacher hoạt động có phân công `primary`/`assistant`/`reviewer`
   `active`)? Có ca nào mục hiện nhưng POST bị từ chối, hoặc ngược lại, không được giải
   thích? Giáo viên chỉ có quyền vào trang, người tạo Template nhưng phân công đã kết thúc,
   người dùng tenant khác, người dùng bị khoá: kiểm bằng code và test, không tin mô tả.
2. **Lộ nội dung.** Có đường nào để nội dung đề xuất, trích dẫn hoặc lý do xuất hiện ngoài
   DOM đã được ủy quyền: HTML ban đầu, thuộc tính `data-*`, thông báo lỗi, URL, log,
   bộ nhớ trình duyệt, BFCache (`pageshow` với `persisted`), tab khác, phản hồi đến muộn?
   Mất quyền giữa chừng, audit lỗi lúc xem chi tiết (503 `data = null`) có gỡ sạch không?
3. **XSS và nội dung tự do.** Văn bản từ AI, Media hoặc do người dùng nhập (tiêu đề, nội
   dung, tiêu chí JSON của `propose_new`, lý do, thông báo lỗi máy chủ) có luôn đi qua
   `textContent`? Có đường nào tạo URL, thuộc tính hay HTML từ nội dung? Test tĩnh có đủ
   chặt hay có cách né (đặt tên khác, truy cập qua chuỗi)?
4. **Giao thức lệnh và idempotency.** Đóng băng, UUID, retry, 409, replay, nhấp đúp, hai
   tab, `expected_lock_version` / `expected_revision_no`: có tình huống nào gửi hai lần với
   nội dung khác dưới cùng UUID, hoặc mất bản sửa mà không báo, hoặc tin một replay cũ thay
   cho lần đọc hiện tại? So với thiết kế §4.2 và §4.5.
5. **Hàng loạt.** Đúng "không accept mù từ danh sách", không chọn xuyên trang, đổi bộ lọc/
   con trỏ/Activity xoá lựa chọn, kết quả từng dòng và thử lại chỉ dòng chưa rõ. Có ca nào
   một dòng trả 200 ngoài nhưng thất bại trong, hoặc dòng bị đánh dấu thành công sai?
6. **Tạo yêu cầu.** Điều kiện `competency`/`node_mapping` cần Framework đúng lựa chọn của
   Template; liên kết Learning mapping chỉ cho admin; 202 không hứa có worker; `failed`
   không nói "đã tạo thành công"; các mã cổng `AI_*` nhóm đúng hành vi (§4.6), không lộ
   `details`, `blocked_at` hay suy trạng thái triển khai.
7. **Đúng với hợp đồng và không nói quá.** Nhãn và thông báo có bao giờ nói "đã áp dụng"
   cho đề xuất mới accept (bất biến §3.1)? Confidence tách khỏi `weight`? `reuse_existing`
   chỉ có Từ chối (D9)? `competency` và bốn loại chữ dừng sau accept? Không hứa dữ liệu
   mà DTO chưa có (thời điểm, phiên bản trích dẫn, so sánh ngữ cảnh)?
8. **Lệnh dữ liệu mẫu.** Chứng minh độc lập rằng nó không thể chạy ở production/staging
   (kể cả cờ ép, cấu hình lạ), không gọi mạng, không cần secret, không để lại allow-list hay
   cấu hình bền vững, đi qua cổng và ledger thật, và không ghi ngoài thư mục tenant mẫu.
   Có ca nào tenant/ID trùng tenant thật gây ghi nhầm (đã từng xảy ra một lần khi thử trên
   database tạm, đã thêm guard)?
9. **i18n, truy cập, chuẩn giao diện.** VI/EN đủ khoá và không chuỗi cứng; `role`/`aria-*`
   cho trạng thái động và kết quả từng dòng; focus khi mở chi tiết, sau lỗi, khi đóng hộp
   xác nhận; bàn phím; không dùng màu làm tín hiệu duy nhất; bám LF-Admin-Form-Design-
   Standard; thanh bên mở/thu và màn hình hẹp. Nêu rõ phần nào reviewer kiểm bằng trình
   duyệt và phần nào chỉ đọc code.
10. **Kiểm chứng.** Ma trận acceptance ở [review thiết kế §6](LF-AI-Authoring-Review-UI-Design-Review.md)
    có dòng nào chưa có test hoặc ca trình duyệt truy vết được? Test hiện có có bắt được
    lỗi thật không (đề nghị đột biến vài chỗ trên **bản sao**, xem ràng buộc)? Test dùng
    SQLite có bỏ qua điều cần MariaDB không?
11. **Hồi quy.** Trang Hoạt động (cấu trúc, media), Learning mapping thủ công và luồng xuất
    bản Version hiện có có bị ảnh hưởng? CSS `.ai-authoring*` có lan sang màn hình khác?
    Thêm `resources/js/app.js` import có đổi hành vi trang khác? Hiệu năng tải trang khi
    không có quyền AI.
12. **Điều chưa kiểm.** Liệt kê điều implementer tuyên bố đã kiểm mà bạn không tái lập được,
    và điều hoàn toàn chưa ai kiểm.

---

# Ràng buộc độc lập

* **Không đủ tư cách:** session implementer của P3-A (bốn lát, JS, Blade, CSS, i18n, lệnh
  dữ liệu mẫu, test), tác giả thiết kế Phần 3, implementer backend Bước 7 và Phần 2.
* **Đủ tư cách:** reviewer chưa sửa code hay tài liệu canonical của những phần trên, kể cả
  reviewer đã review thiết kế Phần 3, K3 hoặc closure Phần 2 nếu chưa từng sửa chúng. Nếu
  bạn đã sửa bất kỳ file nào trong snapshot, nêu ngay và dừng.
* Reviewer **không vá**; finding giao implementer.

# Ràng buộc an toàn

* Chỉ đọc trên repo. Báo cáo duy nhất được tạo: `docs/quality/LF-AI-Authoring-Review-UI-P3A-Review.md`.
* **Không kết nối `learnforge_db`** (`127.0.0.1:3307`), XAMPP `:3306`, và **không chạy test
  suite hay `ai:authoring-demo-seed` vào chúng**.
* Cần database: MariaDB 11.4 dùng một lần trong `/tmp`, `--no-defaults --skip-networking`,
  tên database bắt đầu `lf_`, tắt và xoá khi xong. `migrate:fresh` mất khoảng 5 phút. SQLite
  bỏ qua mọi CHECK nên không chứng minh được schema. Một số test MariaDB của AI Authoring
  chậm (khoảng 5 phút mỗi test).
* Cần server web để thử trình duyệt: chỉ trên database tạm, host dạng
  `<slug>.localhost:<port>` (`APP_BASE_DOMAIN=localhost`), tắt khi xong. Dọn mọi file media
  bạn tạo (lệnh seed ghi một file dưới thư mục media của tenant mẫu).
* Đột biến (mutation) chỉ trên **bản sao riêng** của repo (rsync loại `.git`,
  `node_modules`, `runtime`, `storage` con), khôi phục bằng SHA-256, **không** dùng symlink
  `vendor` (khiến bản sao nạp code của repo chính và kiểm sai code).
* Không gọi provider thật, không thêm secret, không gửi dữ liệu ra mạng, không ghi mật khẩu
  hay khoá vào báo cáo.

---

# Cách chạy được đề nghị

```bash
php artisan test --compact tests/Feature/AiAuthoringHostSectionTest.php \
  tests/Unit/AiAuthoringScriptProtocolTest.php
php artisan test --compact                       # SQLite mặc định; ghi số passed/skipped
php artisan route:list --path=ai-authoring       # 41 route
vendor/bin/pint --test <file PHP đã đổi>
php artisan docs:lint && php artisan schema:drift --docs-only
npm run build
```

`tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` và các test AI Authoring MariaDB chỉ
chạy trên MariaDB tạm (thiết lập `DB_CONNECTION=mysql` bằng socket của instance tạm).
Baseline của implementer: full suite mặc định 1393 passed, 23 skipped, 11904 assertions.

---

# Snapshot

Ghi SHA-256 đầy đủ lúc bắt đầu và cuối lượt. Implementer ghi 16 ký tự đầu lúc bàn giao
(2026-09-29); nếu khác, dừng và báo trước khi review.

| File | SHA-256 (16 đầu) |
| --- | --- |
| `resources/js/ai-authoring.js` | `9643cb4dc8ab409c` |
| `resources/js/app.js` | `d5da869391295db6` |
| `resources/views/course-template-activities/partials/ai-authoring.blade.php` | `2d4ec40400a7a58f` |
| `resources/views/course-template-activities/show.blade.php` | `cd506b3ec05df4ad` |
| `app/Http/Controllers/CourseTemplateActivityController.php` | `d56349503f58945d` |
| `app/Services/CourseAuthoringContextService.php` | `5f36b85a2ddc6770` |
| `app/Console/Commands/AiAuthoringDemoSeed.php` | `971a504838a99c7d` |
| `app/Support/AiDemo/DemoAuthoringProposalProvider.php` | `ebaba37948c2f022` |
| `app/Support/AiDemo/InMemoryTenantSettings.php` | `fcf385f10e4cee00` |
| `resources/css/admin/admin-pages.css` | `7ead1b346f61fa5a` |
| `resources/lang/vi/lf.php` | `e66096296e981b00` |
| `resources/lang/en/lf.php` | `8bccd352562f96cb` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c04463` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `fb7418e91f51686a` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `d6ce6c62289d5f05` |
| `routes/modules/ai-authoring.php` (không đổi, tham chiếu) | `61ef30963f09a993` |
| `app/Http/Controllers/AiAuthoringController.php` (không đổi, tham chiếu) | `4d2f380d4a807916` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` (Frozen, tham chiếu) | `fd23a83289e49901` |

Hash của `docs/platform/LF-AI-Authoring-Review-UI-Design.md` được Owner ghi lúc bàn giao
vì tài liệu này còn được cập nhật khi review kết thúc. Ngoài ra `.github/workflows/application-tests.yml`
(thêm một test MariaDB) và các dòng INDEX/README/manifest thuộc phạm vi đọc, không có hash
riêng. Working tree có thay đổi chưa commit; snapshot là trạng thái working tree, không phải
một commit.

---

# Định dạng báo cáo

* Header chuẩn, tên reviewer, xác nhận độc lập, snapshot đầu/cuối, ngày.
* Verdict cho từng câu hỏi 1–12 và cho toàn P3-A, kèm bằng chứng **của chính reviewer**
  (file:dòng, lệnh đã chạy, kết quả trình duyệt).
* Findings `BLOCKER | HIGH | MEDIUM | LOW`: vị trí, tình huống, bằng chứng, đề xuất (không
  vá).
* Bảng dòng acceptance của review thiết kế §6 ↔ test/ca trình duyệt, đánh dấu dòng chưa phủ.
* Bảng lệnh đã chạy, mục chưa kiểm, và xác nhận đã dọn (server, MariaDB tạm, file media,
  bản sao đột biến).
* Điều kiện để đóng P3-A và để bắt đầu P3-B, nếu `APPROVE WITH CHANGES`.

# Cách xử lý sau review

Finding HIGH trở lên phải sửa và reviewer xác nhận lại trước khi P3-A được ghi là đóng.
MEDIUM/LOW được đăng ký vào tài liệu tiến độ với chủ sở hữu và hạn. Miễn trừ không thay cho
PASS (waiver ≠ PASS). P3-B chỉ bắt đầu sau khi Owner duyệt hai amendment (`allowed_actions`,
DTO tên/ứng viên Node) và P3-A được đóng.


---

# Lượt 2 — sau review implementation lượt 1 (2026-09-29)

[Báo cáo lượt 1](LF-AI-Authoring-Review-UI-P3A-Review.md): **REJECT**, 5 HIGH + 6 MEDIUM. Đã
sửa; bảng đối chiếu từng finding ở [thiết kế §13.1](../platform/LF-AI-Authoring-Review-UI-Design.md).
Cổng P3-A vẫn đóng cho đến khi reviewer độc lập xác nhận lại. Reviewer lượt 2 có thể là
người của lượt 1 (chưa sửa file nào) hoặc người khác đủ tư cách.

## Việc cần làm

1. **Tái lập lại từng probe và ca của lượt 1** (P1–P13, B3–B8, ba mutation của §3.3) trên
   snapshot mới. Với mỗi finding R1–R11, nêu `ĐÓNG`, `ĐÓNG MỘT PHẦN` hoặc `MỞ`, kèm bằng
   chứng của chính reviewer. Không lấy kết quả implementer làm bằng chứng.
2. **Chạy bộ test hành vi mới** (`node --test tests/js`, 14 ca, hoặc qua
   `tests/Unit/AiAuthoringScriptBehaviorTest.php`) và tự đột biến `resources/js/ai-authoring.js`
   trên bản sao: bỏ teardown editor/noteField, bỏ khoá biểu mẫu khi kết quả chưa biết, bỏ
   xoá `pendings` khi đọc lại, bỏ kiểm `epoch`, coi 403 không phải JSON là lỗi thường, bỏ
   xoá lựa chọn khi chuyển trang. Implementer đã chạy 13 đột biến và mọi đột biến đều bị
   bắt; hãy kiểm độc lập và tìm đột biến sống sót. DOM giả (`tests/js/fake-dom.mjs`) không
   phải trình duyệt: những gì nó không chứng minh (bố cục, BFCache thật, focus) phải kiểm
   bằng trình duyệt thật.
3. **Lệnh dữ liệu mẫu:** kiểm lại R4/R5 độc lập, gồm tenant root có sẵn nhưng thư mục
   Activity mới, disk `s3`/driver lạ (không được tạo adapter), symlink, và cạnh tranh
   (`mkdir` độc quyền). Lưu ý test dùng `Storage::fake` có hậu tố `_test_<token>` ở root;
   `Storage::fake` không đổi `root` trong config, test phải đặt lại (đã có ghi chú trong test).
4. **Bằng chứng trình duyệt cho R1 thật sự:** đổi trạng thái người dùng sang `inactive`
   trên DB tạm rồi thao tác (Lưu bản sửa, Xem) như B8 lượt 1; kiểm cả nội dung lẫn giá trị
   trong `input`/`textarea` không còn trong DOM, và các nút ghi tắt. Kiểm thẻ trên màn hình
   hẹp 390px (`data-label`).
5. **D8:** kiểm liên kết ngược ở trang Learning mapping với Activity trực tiếp, trong
   section, Lesson, Activity đã mất (test `CourseTemplateLearningMappingHttpMariaDbTest`,
   ba ca `backlink`).
6. **D10 (thiết kế §10), Owner đã duyệt 2026-09-29:** giữ nút Xem hiển thị thay cho menu
   `⋯` của chuẩn List §25.4 vì mỗi dòng chỉ có một hành động. Đây là lệch chuẩn có duyệt,
   không tính là finding R9 còn mở; nếu reviewer thấy lý do kỹ thuật để phản đối, nêu ý kiến.

## Ràng buộc

Như lượt 1 (chỉ đọc repo, báo cáo là file duy nhất được tạo; không `learnforge_db`; MariaDB
tạm `--skip-networking`; đột biến trên bản sao, không symlink vendor; dọn sạch, gồm file
media tenant mẫu). Lưu ý bổ sung: `ai:authoring-demo-seed` ghi thư mục
`storage/app/media/tenants/<id>` của disk media cấu hình; trên DB tạm hãy đặt
`AUTO_INCREMENT` của `saas_customers` lên 900 để không trùng tenant thật, và xoá đúng thư
mục đó khi xong.

## Snapshot lượt 2

Ghi SHA-256 đầy đủ lúc bắt đầu và cuối lượt (16 ký tự đầu do implementer ghi lúc bàn giao).
File mới hoặc mới vào phạm vi so với lượt 1: partial Learning mapping, các file `tests/js`,
`AiAuthoringScriptBehaviorTest`.

| File | SHA-256 (16 đầu) |
| --- | --- |
| `resources/js/ai-authoring.js` | `eb37246ba1ba7936` |
| `resources/js/app.js` | `d5da869391295db6` |
| `resources/views/course-template-activities/partials/ai-authoring.blade.php` | `9301801b3d82436c` |
| `resources/views/course-template-activities/show.blade.php` | `cd506b3ec05df4ad` |
| `resources/views/course-templates/partials/learning-mappings.blade.php` | `fbe914ccfd43aee9` |
| `app/Http/Controllers/CourseTemplateActivityController.php` | `d56349503f58945d` |
| `app/Services/CourseAuthoringContextService.php` | `5f36b85a2ddc6770` |
| `app/Console/Commands/AiAuthoringDemoSeed.php` | `5748a8f05496d82d` |
| `app/Support/AiDemo/DemoAuthoringProposalProvider.php` | `ebaba37948c2f022` |
| `app/Support/AiDemo/InMemoryTenantSettings.php` | `fcf385f10e4cee00` |
| `resources/css/admin/admin-pages.css` | `b59c67ae5ddf87a1` |
| `resources/lang/vi/lf.php` | `31040bcd2c8897fe` |
| `resources/lang/en/lf.php` | `afc4d5f0b0d9c932` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c04463` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `b5ea210217079335` |
| `tests/Integration/CourseTemplateLearningMappingHttpMariaDbTest.php` | `cd8e1127095c90ad` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `bd4f6641773e1e9c` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7` |
| `tests/js/fake-dom.mjs` | `2509647dbbb0b7eb` |
| `tests/js/ai-authoring.behavior.test.mjs` | `5df3205920cea822` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` (v0.7) | `6a967dda974f7648` |
| `routes/modules/ai-authoring.php` (không đổi) | `61ef30963f09a993` |
| `app/Http/Controllers/AiAuthoringController.php` (không đổi) | `4d2f380d4a807916` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` (Frozen, không đổi) | `fd23a83289e49901` |

Kết quả của implementer, để reviewer so: full suite mặc định 1395 passed, 23 skipped, 11912
assertions; MariaDB tạm (seed + Learning mapping) 32 passed, 161 assertions; `node --test
tests/js` 14 passed; Pint, `docs:lint`, `schema:drift --docs-only`, `git diff --check`, `npm run
build` đều qua. Reviewer lượt 1 không tái lập được baseline 1393/23 do thiếu runtime media
(7 lỗi ngoài P3-A); hãy ghi phân loại tương tự thay vì coi là PASS.


---

# Lượt 3 — sau review implementation lượt 2 (2026-09-29)

[Báo cáo lượt 2](LF-AI-Authoring-Review-UI-P3A-Review-Round2.md): **REJECT**; 7 finding cũ
đóng, 4 đóng một phần, thêm 2 HIGH (N1, N2) và 2 MEDIUM (N3, N4). Đã sửa; bảng đối chiếu ở
[thiết kế §13.2](../platform/LF-AI-Authoring-Review-UI-Design.md). Cổng P3-A vẫn đóng.

## Việc cần làm

1. Với N1–N4 và các finding "đóng một phần" R1, R2, R3, R10: nêu `ĐÓNG` / `ĐÓNG MỘT PHẦN` /
   `MỞ` kèm bằng chứng của chính reviewer; xác nhận lại những finding đã đóng ở lượt 2 không
   bị hồi quy.
2. **Tái lập B9a, B9b, B10 và P14–P16 của lượt 2** trên snapshot mới, cùng harness trình duyệt
   nếu cần. Kiểm bằng cách đi từ tham chiếu đang giữ lên cha (không chỉ subtree) và bằng
   giá trị thật của `input`/`textarea`.
3. **Đột biến:** chạy lại M1–M15 của lượt 2 và tự thêm. Implementer chạy 14 đột biến mới
   (bỏ epoch riêng của runBulk/runCreate, bỏ `releaseBulk` ở thu hồi và `pagehide`, bỏ xoá giá
   trị ô nhập, bỏ ba chỗ kiểm `epoch` trước thông báo, về `loadList(true)` ở phản hồi muộn,
   bỏ `scrub` và hai nhánh của nó) và đều bị bắt; M15 nay bị test tĩnh chặn. Hãy tìm đột
   biến sống sót.
4. **Zero-egress:** `resources/views/layouts/app.blade.php` và `auth.blade.php` (dòng 6–7) có
   URL favicon ngoài. Đó là hạ tầng cũ, không thuộc P3-A và implementer không sửa. Trước khi
   mở trình duyệt, chặn egress ở mức môi trường (hoặc loại URL này **chỉ trong bản sao**), và
   ghi rõ cách đã chặn hay chưa trong báo cáo.
5. Không tái mở R9 vì D10 (Owner đã duyệt). BFCache `persisted=true` thật, hai tab, zoom 200%
   và trình đọc màn hình vẫn là mục chưa chứng nhận; ghi đúng trạng thái.

## Ràng buộc

Như lượt 2 (chỉ đọc repo, một file báo cáo duy nhất, không `learnforge_db`, MariaDB tạm
`--skip-networking`, `AUTO_INCREMENT` của `saas_customers` lên 900 trước khi seed, đột biến trên
bản sao không symlink vendor, dọn sạch gồm file media tenant mẫu).

## Snapshot lượt 3

Ghi SHA-256 đầy đủ lúc bắt đầu và cuối lượt (16 ký tự đầu do implementer ghi lúc bàn giao).
Khác lượt 2: `ai-authoring.js`, `AiAuthoringScriptProtocolTest`, `tests/js/*`, thiết kế.

| File | SHA-256 (16 đầu) |
| --- | --- |
| `resources/js/ai-authoring.js` | `1625ff4851466400` |
| `resources/js/app.js` | `d5da869391295db6` |
| `resources/views/course-template-activities/partials/ai-authoring.blade.php` | `9301801b3d82436c` |
| `resources/views/course-template-activities/show.blade.php` | `cd506b3ec05df4ad` |
| `resources/views/course-templates/partials/learning-mappings.blade.php` | `fbe914ccfd43aee9` |
| `app/Http/Controllers/CourseTemplateActivityController.php` | `d56349503f58945d` |
| `app/Services/CourseAuthoringContextService.php` | `5f36b85a2ddc6770` |
| `app/Console/Commands/AiAuthoringDemoSeed.php` | `5748a8f05496d82d` |
| `app/Support/AiDemo/DemoAuthoringProposalProvider.php` | `ebaba37948c2f022` |
| `app/Support/AiDemo/InMemoryTenantSettings.php` | `fcf385f10e4cee00` |
| `resources/css/admin/admin-pages.css` | `b59c67ae5ddf87a1` |
| `resources/lang/vi/lf.php` | `31040bcd2c8897fe` |
| `resources/lang/en/lf.php` | `afc4d5f0b0d9c932` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c04463` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `b5ea210217079335` |
| `tests/Integration/CourseTemplateLearningMappingHttpMariaDbTest.php` | `cd8e1127095c90ad` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `90a591128943cff7` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7` |
| `tests/js/fake-dom.mjs` | `8038ec74f43865ee` |
| `tests/js/ai-authoring.behavior.test.mjs` | `1667282d4d057e18` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `fb705e7f16e12ab9` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a993` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e49901` |

Kết quả của implementer, để so: full suite mặc định 1396 passed, 23 skipped, 11913 assertions;
`node --test tests/js` 20 passed; Pint, `docs:lint`, `schema:drift --docs-only`, `git diff
--check`, `npm run build` đều qua. Test MariaDB của lượt trước (seed + Learning mapping, 32
passed) không bị ảnh hưởng bởi thay đổi lượt này (chỉ JS, test JS và tài liệu); lượt 2 của
reviewer đã chạy 104 test MariaDB trên snapshot trước. Lỗi media 7 ca của lượt 1/2 là ngoài
P3-A (thiếu runtime), hãy phân loại tương tự.


---

# Lượt 4 — sau review implementation lượt 3 (2026-09-30)

[Báo cáo lượt 3](LF-AI-Authoring-Review-UI-P3A-Review-Round3.md): **REJECT**; N1–N3 đóng, 1 HIGH
mới (O1: phản hồi bulk đến muộn đọc lại đề xuất đang mở và xoá bản sửa), N4 còn một phần
(M16, M27, M28, M29). Đã sửa; bảng đối chiếu ở [thiết kế §13.3](../platform/LF-AI-Authoring-Review-UI-Design.md).

## Việc cần làm

1. **O1:** tái lập P17 và B11 trên snapshot mới; nêu `ĐÓNG` / `MỘT PHẦN` / `MỞ` cho O1, N4, R2,
   R3, R10 kèm bằng chứng của chính reviewer. Cách sửa: mọi lần script **tự** đọc lại đề xuất
   đang mở đi qua `refreshOpen()` (không đọc khi có bản sửa chưa gửi hoặc khi trang đã rời).
   Hãy tự tìm **mọi** đường khác có thể xoá bản sửa không hỏi (các điểm gọi `openDetail`,
   `dropDetail`, `loadList(true)`, `renderDetail`); implementer đã rà và không thấy đường
   còn lại, xem thiết kế §13.3.
2. **P18:** ghi nhận đã xử lý bằng cờ `pageHidden` (không đọc lại khi trang đã rời). Kiểm
   bằng sự kiện `pagehide`/`pageshow` thật nếu có thể; BFCache `persisted=true` thật vẫn là
   mục chưa chứng nhận.
3. **N4:** chạy lại M1–M29 của lượt 3 và tự thêm. Implementer thêm test cho M16 (khoảng trắng
   trong `node[ member ]`), M27 (`scrub` văn bản cạnh phần tử khác, test đơn vị), M28 (epoch
   sau hộp xác nhận bulk), M29 (epoch sau đọc trạng thái yêu cầu) và bốn đột biến quanh O1
   (bỏ kiểm bản nháp, bỏ cờ `pageHidden`, không đặt cờ khi `pagehide`); tất cả bị bắt. Hãy
   tìm đột biến sống sót và phân biệt đột biến có tác động với đột biến tương đương.
4. Giữ nguyên các regression N1–N3, M1–M15 đã đạt.

## Ràng buộc

Như lượt 3, kể cả zero-egress (loại URL favicon ngoài **chỉ trong bản sao** và/hoặc CSP,
ghi rõ đã làm gì). Báo cáo là file duy nhất được tạo.

## Snapshot lượt 4

Ghi SHA-256 đầy đủ lúc bắt đầu và cuối lượt (16 ký tự đầu do implementer ghi lúc bàn giao).
Khác lượt 3: `ai-authoring.js`, `resources/lang/{vi,en}/lf.php` (hai khoá mới), test giao thức,
test hành vi, thiết kế.

| File | SHA-256 (16 đầu) |
| --- | --- |
| `resources/js/ai-authoring.js` | `6547266d62419955` |
| `resources/js/app.js` | `d5da869391295db6` |
| `resources/views/course-template-activities/partials/ai-authoring.blade.php` | `9301801b3d82436c` |
| `resources/views/course-template-activities/show.blade.php` | `cd506b3ec05df4ad` |
| `resources/views/course-templates/partials/learning-mappings.blade.php` | `fbe914ccfd43aee9` |
| `app/Http/Controllers/CourseTemplateActivityController.php` | `d56349503f58945d` |
| `app/Services/CourseAuthoringContextService.php` | `5f36b85a2ddc6770` |
| `app/Console/Commands/AiAuthoringDemoSeed.php` | `5748a8f05496d82d` |
| `app/Support/AiDemo/DemoAuthoringProposalProvider.php` | `ebaba37948c2f022` |
| `app/Support/AiDemo/InMemoryTenantSettings.php` | `fcf385f10e4cee00` |
| `resources/css/admin/admin-pages.css` | `b59c67ae5ddf87a1` |
| `resources/lang/vi/lf.php` | `90bbbbf2e5b13aae` |
| `resources/lang/en/lf.php` | `b72c4a2be4832584` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c04463` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `b5ea210217079335` |
| `tests/Integration/CourseTemplateLearningMappingHttpMariaDbTest.php` | `cd8e1127095c90ad` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `a90fa1e0cb832f73` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7` |
| `tests/js/fake-dom.mjs` | `8038ec74f43865ee` |
| `tests/js/ai-authoring.behavior.test.mjs` | `3308b025b7be6cb5` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `c812f06ae9ffee89` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a993` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e49901` |

Kết quả của implementer, để so: full suite mặc định 1397 passed, 23 skipped, 11917 assertions;
`node --test tests/js` 26 passed; Pint, `docs:lint`, `schema:drift --docs-only`, `git diff
--check`, `npm run build` đều qua. Test MariaDB không đổi so lượt trước. Bảy ca media của lượt
1–3 là ngoài P3-A (thiếu runtime).


---

# Lượt 5 — sau review implementation lượt 4 (2026-09-30)

[Báo cáo lượt 4](LF-AI-Authoring-Review-UI-P3A-Review-Round4.md): **REJECT**; O1 đóng, N1–N3
không hồi quy, 1 HIGH mới (Q1: Thử lại danh sách sau lỗi tải trang kế xoá bản sửa không hỏi),
N4 một phần (M34, M35, M36). Đã sửa; bảng đối chiếu ở [thiết kế §13.4](../platform/LF-AI-Authoring-Review-UI-Design.md).

## Điều cần biết về cách sửa

Ba lượt liên tiếp (N2, O1, Q1) cùng họ lỗi: một luồng tự làm mới trạng thái và xoá bản sửa
chưa gửi. Lần này không vá thêm một nơi gọi mà đổi hợp đồng của hàm: `loadList()` **không bao giờ
đóng đề xuất đang mở** trừ khi người gọi truyền `{ closeDetail: true }` (chỉ đổi bộ lọc, sau khi
đã hỏi). Cùng với `refreshOpen()` của lượt 4, hai hàm này là các đường duy nhất tự đọc lại. Có
thêm test **quét**: với bản sửa đang mở và mọi câu hỏi trả lời "huỷ", bấm mọi nút hiện có trừ
Lưu (kể cả sau lỗi tải trang kế) và kiểm bản sửa còn nguyên.

## Việc cần làm

1. Tái lập P21 và B12; nêu `ĐÓNG` / `MỘT PHẦN` / `MỞ` cho Q1, N4 (và R2, R3, R10) kèm bằng chứng
   của chính bạn.
2. **Tìm chủ động họ lỗi này, không chỉ bản đã báo:** mọi cách để một bản sửa chưa gửi (ô
   `input`/`textarea` của biểu mẫu sửa, ghi chú quyết định, ghi chú bulk, lựa chọn tạo yêu
   cầu) biến mất mà không có câu hỏi. Kể cả: đổi bộ lọc khi hộp xác nhận đang mở, hai lệnh chồng
   nhau, thao tác bàn phím, tải trang kế thành công, tạo yêu cầu hoàn tất, thu hồi rồi có lại
   quyền. Nếu tìm được, nêu tình huống và bằng chứng.
3. Đột biến: chạy lại M1–M37 của lượt 4 và tự thêm. Implementer thêm test cho M34 (nút "Đọc
   lại và bỏ bản sửa": huỷ và đồng ý), M35 (cờ `pageHidden` về `false` sau `pageshow`), M36
   (`+=`, `||=`, `??=` trong quy tắc tĩnh; cấm `Object.assign(`, `Object.defineProperty(`, `srcdoc`).
   M37 (`refreshOpen` bỏ kiểm `uuid !== openUuid`) được giữ là tương đương trong đồ thị gọi
   hiện tại theo phân loại của lượt 4.
4. Giữ nguyên regression O1, N1–N3, M1–M29.

## Ràng buộc

Như lượt 4 (zero-egress: loại URL favicon ngoài chỉ trong bản sao và/hoặc CSP, ghi rõ; báo cáo
là file duy nhất được tạo).

## Snapshot lượt 5

Ghi SHA-256 đầy đủ lúc bắt đầu và cuối lượt (16 ký tự đầu do implementer ghi lúc bàn giao).
Khác lượt 4: `ai-authoring.js`, test giao thức, test hành vi, thiết kế.

| File | SHA-256 (16 đầu) |
| --- | --- |
| `resources/js/ai-authoring.js` | `2ccf67a14d343221` |
| `resources/js/app.js` | `d5da869391295db6` |
| `resources/views/course-template-activities/partials/ai-authoring.blade.php` | `9301801b3d82436c` |
| `resources/views/course-template-activities/show.blade.php` | `cd506b3ec05df4ad` |
| `resources/views/course-templates/partials/learning-mappings.blade.php` | `fbe914ccfd43aee9` |
| `app/Http/Controllers/CourseTemplateActivityController.php` | `d56349503f58945d` |
| `app/Services/CourseAuthoringContextService.php` | `5f36b85a2ddc6770` |
| `app/Console/Commands/AiAuthoringDemoSeed.php` | `5748a8f05496d82d` |
| `app/Support/AiDemo/DemoAuthoringProposalProvider.php` | `ebaba37948c2f022` |
| `app/Support/AiDemo/InMemoryTenantSettings.php` | `fcf385f10e4cee00` |
| `resources/css/admin/admin-pages.css` | `b59c67ae5ddf87a1` |
| `resources/lang/vi/lf.php` | `90bbbbf2e5b13aae` |
| `resources/lang/en/lf.php` | `b72c4a2be4832584` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c04463` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `b5ea210217079335` |
| `tests/Integration/CourseTemplateLearningMappingHttpMariaDbTest.php` | `cd8e1127095c90ad` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `ffb60cd2f23ea14f` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7` |
| `tests/js/fake-dom.mjs` | `8038ec74f43865ee` |
| `tests/js/ai-authoring.behavior.test.mjs` | `4903c47a70b00cfc` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `ee8453abbc14285a` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a993` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e49901` |

Kết quả của implementer, để so: full suite mặc định 1397 passed, 23 skipped, 11926 assertions;
`node --test tests/js` 30 passed; Pint, `docs:lint`, `schema:drift --docs-only`, `git diff
--check`, `npm run build` đều qua. Test MariaDB không đổi. Bảy ca media của các lượt trước là
ngoài P3-A (thiếu runtime).


---

# Lượt 6 — sau review implementation lượt 5 và D11 (2026-09-30)

[Báo cáo lượt 5](LF-AI-Authoring-Review-UI-P3A-Review-Round5.md): **REJECT**; Q1 đóng, 1 HIGH mới (S1: ghi
chú chưa gửi nằm ngoài cơ chế bảo vệ bản nháp), S2 và N4 MEDIUM. Đã sửa; bảng đối chiếu ở
[thiết kế §13.6](../platform/LF-AI-Authoring-Review-UI-Design.md). Snapshot này **gộp thêm D11** (lối vào từ
danh sách Hoạt động, thiết kế §2 và §13.5) mà chưa có review độc lập nào.

## Điều cần biết về cách sửa

Thay vì vá thêm từng nơi, lần này dữ liệu người dùng đang nhập có **một định nghĩa duy nhất**: `hasDraft()`
(của đề xuất đang mở: biểu mẫu sửa đã đổi, lệnh chưa rõ kết quả, hoặc ghi chú quyết định không rỗng) và
`hasUnsent()` (thêm ghi chú bulk, dùng cho cảnh báo rời trang). Mọi cách rời hoặc làm mới dựa vào chúng.
Form tạo đề xuất được dựng một lần và giữ. DOM giả trong test từ chối mọi truy cập `innerHTML`, `outerHTML`,
`insertAdjacentHTML`, nên đường tới chúng thất bại khi chạy dù viết thế nào.

## Việc cần làm

1. **S1, S2, N4:** tái lập P23–P36 và B13–B17 của lượt 5; nêu `ĐÓNG` / `MỘT PHẦN` / `MỞ` cho từng finding, kèm
   bằng chứng của chính bạn.
2. **Tìm chủ động họ lỗi "dữ liệu nhập bị mất" cho MỌI loại dữ liệu nhập**, không chỉ ghi chú: bản sửa, ghi chú
   quyết định, ghi chú bulk, lựa chọn form tạo đề xuất, cùng các bộ lọc. Với mỗi loại, thử: đổi đề xuất, đóng,
   đổi bộ lọc, tải trang kế, thử lại sau lỗi, phản hồi muộn của bulk, của lưu, của trạng thái tạo yêu cầu, bàn
   phím, rời trang. Nếu tìm được, nêu tình huống và bằng chứng.
3. **D11:** kiểm nút "Đề xuất AI" ở dòng Hoạt động và ghi chú "không có nội dung Media" trên trang chi tiết.
   - Chỉ hiện với `video`, `audio`, `document` và người có quyền AI (admin; giáo viên phân công `primary`,
     `assistant`, `reviewer` đang hoạt động). Không hiện cho người chỉ mở được trang, người tạo Template
     không có phân công, phân công đã kết thúc.
   - Liên kết đi qua section khi Bài học nằm trong section; nút Xem không đổi; neo `#ai-authoring` cuộn tới tiêu
     đề (không bị header cố định che).
   - Hai test cũ `CourseTemplateActivityManagementTest` được cập nhật có chủ đích (admin thêm nút, người tạo
     không có quyền AI thì không): đánh giá việc cập nhật đó có đúng không.
   - Nêu rõ nếu D11 làm lộ thông tin gì cho người không có quyền AI, hoặc hiện nút ở đâu không nên.
4. **Đột biến:** chạy lại M1–M43 của lượt 5 và tự thêm. Implementer chạy 15 đột biến mới (bỏ từng nhánh của S1,
   S2, M39, M40, M43 và ba dạng sink) và tất cả bị bắt; M37 giữ là tương đương.
5. Giữ nguyên regression Q1, O1, N1–N3, M1–M38.

## Ràng buộc

Như lượt 5 (zero-egress: loại URL favicon ngoài **chỉ trong bản sao** và/hoặc CSP, ghi rõ; báo cáo là file duy nhất
được tạo: `docs/quality/LF-AI-Authoring-Review-UI-P3A-Review-Round6.md`). Có DB tạm thì nhớ `AUTO_INCREMENT` của
`saas_customers` lên 900 trước khi seed, và xoá đúng thư mục media tenant đó khi xong.

## Snapshot lượt 6

Ghi SHA-256 đầy đủ lúc bắt đầu và cuối lượt (16 ký tự đầu do implementer ghi lúc bàn giao). Khác lượt 5:
`ai-authoring.js`, partial Blade AI, danh sách Hoạt động (`course-template-lessons/partials/list.blade.php`), hai
controller Template/Activity, `CourseAuthoringContextService`, CSS, i18n, các test, thiết kế.

| File | SHA-256 (16 đầu) |
| --- | --- |
| `resources/js/ai-authoring.js` | `db5769c370280f87` |
| `resources/js/app.js` | `d5da869391295db6` |
| `resources/views/course-template-activities/partials/ai-authoring.blade.php` | `09d481ff98f4e577` |
| `resources/views/course-template-activities/show.blade.php` | `cd506b3ec05df4ad` |
| `resources/views/course-template-lessons/partials/list.blade.php` | `42d7e6513d5a4d15` |
| `resources/views/course-templates/partials/learning-mappings.blade.php` | `fbe914ccfd43aee9` |
| `app/Http/Controllers/CourseTemplateActivityController.php` | `a6c073e3a28bbbff` |
| `app/Http/Controllers/CourseTemplateController.php` | `f6e7a7189857c283` |
| `app/Services/CourseAuthoringContextService.php` | `d1146bb21a5e64cc` |
| `app/Console/Commands/AiAuthoringDemoSeed.php` | `5748a8f05496d82d` |
| `app/Support/AiDemo/DemoAuthoringProposalProvider.php` | `ebaba37948c2f022` |
| `app/Support/AiDemo/InMemoryTenantSettings.php` | `fcf385f10e4cee00` |
| `resources/css/admin/admin-pages.css` | `f5d0ab10404ce748` |
| `resources/lang/vi/lf.php` | `0c2ba59101cbbd6b` |
| `resources/lang/en/lf.php` | `71cb96ab22ca4c4f` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c04463` |
| `tests/Feature/AiAuthoringActivityEntryTest.php` | `dbaeb0dfcf1d1c7c` |
| `tests/Feature/CourseTemplateActivityManagementTest.php` | `d7d8132639af2783` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `b5ea210217079335` |
| `tests/Integration/CourseTemplateLearningMappingHttpMariaDbTest.php` | `cd8e1127095c90ad` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `78f21c4c217e1b0f` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7` |
| `tests/js/fake-dom.mjs` | `78bdd8ac1d7929ba` |
| `tests/js/ai-authoring.behavior.test.mjs` | `2518e62e5573824e` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `fa8125dbc9796d87` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a993` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e49901` |

Kết quả của implementer, để so: full suite mặc định 1411 passed, 23 skipped, 11973 assertions; `node --test tests/js`
41 passed; Pint, `docs:lint`, `schema:drift --docs-only`, `git diff --check`, `npm run build` đều qua. Test MariaDB
không đổi ở lượt này (lần chạy gần nhất: seed và Learning mapping 32 passed). Bảy ca media của các lượt trước là
ngoài P3-A (thiếu runtime).


---

# Lượt 7 — xác nhận sau lượt 6 (2026-09-30) — KHÔNG CHẠY

> Owner đóng P3-A ngày 2026-09-30 mà không chạy lượt này ([thiết kế §13.8](../platform/LF-AI-Authoring-Review-UI-Design.md)). Nội dung dưới đây giữ lại làm hồ sơ; F1/F2 sẽ được xác nhận trong lượt review đầu tiên của P3-B.

[Báo cáo lượt 6](LF-AI-Authoring-Review-UI-P3A-Review-Round6.md): **APPROVE WITH CHANGES**, 0 BLOCKER, 0 HIGH;
S1, S2, N4 đóng; D11 đúng theo source và test. Còn F1 (MEDIUM) và F2 (LOW). Lượt này **chỉ xác nhận hai điều kiện
đóng**, không mở rộng phạm vi.

## Đã làm ([thiết kế §13.7](../platform/LF-AI-Authoring-Review-UI-Design.md))

- **F1:** `hasUnsent()` nay tính cả loại đề xuất đã tích trong form tạo (kể cả khi form đang gập), nên `beforeunload`
  cảnh báo; Huỷ chủ động thì không còn gì để cảnh báo. Không lưu lựa chọn vào bộ nhớ trình duyệt. Có test Node mới;
  bỏ mệnh đề đó thì test đỏ.
- **F2:** đã đo neo `#ai-authoring` trên trang Laravel thật (Template 3, Hoạt động 97): header cố định cao 157px,
  tiêu đề mục AI ở 265px với viewport 1440, 640 (tương đương zoom 200% của 1280) và 375, không tràn ngang.
  Reviewer hãy tự đo lại; đây là số của implementer.

## Việc cần làm

1. Xác nhận F1: tái lập probe choice-only `beforeunload` (Node và trình duyệt), kể cả form gập và sau Huỷ. Thử thêm
   các cách khác làm mất lựa chọn tạo yêu cầu (đổi bộ lọc, tải trang kế, thu hồi rồi có lại quyền).
2. Xác nhận F2: đo neo trên trang Laravel thật hoặc mô phỏng đủ sát (header cố định, sidebar mở/thu, mobile,
   zoom); ghi cách đo.
3. Xác nhận không hồi quy: chạy lại Node, các test trọng tâm và một vòng đột biến chọn lọc quanh `hasUnsent()`,
   `hasDraft()`, `afterGeneration`, `resetCreate`.
4. Kết luận P3-A: `APPROVE`, hoặc nêu điều kiện còn lại. Nếu APPROVE, ghi rõ giới hạn đã biết (BFCache
   `persisted=true` thật, hai tab, screen reader, MariaDB không chạy lại) để Owner đóng P3-A với đầy đủ thông tin.

## Ràng buộc

Như lượt 6; báo cáo là file duy nhất được tạo: `docs/quality/LF-AI-Authoring-Review-UI-P3A-Review-Round7.md`.

## Snapshot lượt 7

Chỉ ba file đổi so lượt 6 (28 file còn lại giữ nguyên hash của lượt 6; ghi lại SHA-256 đầy đủ của cả 28 file lúc
bắt đầu và cuối lượt):

| File | SHA-256 (16 đầu) |
| --- | --- |
| `resources/js/ai-authoring.js` | `8cc9f8f359827733` |
| `tests/js/ai-authoring.behavior.test.mjs` | `8f089e1782e3c6a2` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `ab6f6d2abbcc84f7` |

Kết quả của implementer, để so: full suite mặc định 1411 passed, 23 skipped, 11973 assertions; `node --test tests/js`
42 passed; Pint, `docs:lint`, `schema:drift --docs-only`, `git diff --check`, `npm run build` đều qua.
