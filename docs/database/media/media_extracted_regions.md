# Table: media_extracted_regions

Version: 1.16

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-31

Document Path: database/media/media_extracted_regions.md

Related ADR:

* [ADR-0004 — Media Foundation](../../adr/ADR-0004-Media-Foundation.md)
* [ADR-0018 — Media PII And External Processing Boundary](../../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved
* [ADR-0019 — Media Structured Extraction Boundary](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) — Approved

Related Specification:
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md),
[LF-Media-Read-Contract](../../platform/LF-Media-Read-Contract.md)

## Purpose

Lưu **vùng** quan sát được trên một trang document: role của vùng, hình học,
thứ tự đọc và text thuộc về nó.

Bảng này tồn tại riêng thay vì thêm cột vào `media_extracted_texts` vì hai output
trả lời hai câu hỏi khác nhau. `media_extracted_texts` trả lời "trang 12 viết gì";
bảng này trả lời "trang 12 gồm những khối nào, khối nào là bảng, đọc theo thứ tự
nào". Nhồi chung sẽ khiến phần lớn cột NULL với mọi extractor không sinh layout.

Media **chỉ quan sát**. Role là hình dạng nhìn thấy được (`table`, `figure`,
`heading`), không phải ý nghĩa (`bảng doanh thu`, `sơ đồ quy trình`). Diễn giải
thuộc AI theo ADR-0019 § D4.

## Relationships

`Media File 1 → N Extracted Regions`, scope theo `(locale, processing_version)`.

`Region 1 → 0..1 Extracted Table` khi `role = 'table'`.

Region **không** tham chiếu tới `media_extracted_texts`: hai bảng là hai mức chi
tiết của cùng một lần chạy, nối với nhau bằng
`(media_file_id, locale, processing_version, page)`.

## Business Rules

* Mọi row tenant-scoped; extractor không sinh layout thì đơn giản là không sinh
  row nào — thiếu region không phải lỗi.
* `locator_type = 'region'`, `locator_value = '<page>#<ordinal>'`, cả hai ≥ 1,
  theo hợp đồng locator tại
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)
  § 4 mở rộng bởi ADR-0019 § D1.
* Hình học **không** nằm trong locator. `bbox_*` lưu riêng, chuẩn hoá 0..1 theo
  kích thước trang, nên đổi DPI không làm đổi giá trị và không làm hỏng citation
  cũ.
* `ordinal` là thứ tự vùng trong **một trang**; `reading_order` là thứ tự trong
  **cả tài liệu**. Hai giá trị khác nhau và đều cần: một citation neo theo trang,
  một consumer đọc tuần tự cần trình tự toàn cục.
* Output không bao giờ bị ghi đè. Đổi `source_fingerprint` hoặc
  `processing_version` sinh bộ row mới; bộ cũ chuyển `archived` theo quy tắc
  stale trong Processing Contract.
* Text của region là text của **chính vùng đó**. Nó trùng lặp có chủ ý với text
  cấp trang; xem Design Notes.
* Region có PII vẫn là output hợp lệ theo ADR-0018; PII presence không đổi
  `status` và không cấp thêm quyền đọc.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File nguồn. |
| processing_job_id | BIGINT UNSIGNED NULL | Lần chạy đã tạo ra row này. |
| locale | VARCHAR(20) NOT NULL | Locale của lần trích xuất. |
| locator_type | VARCHAR(20) NOT NULL | Luôn là `region`. |
| locator_value | VARCHAR(50) NOT NULL | `<page>#<ordinal>`, dạng text. |
| page | INT UNSIGNED NOT NULL | Số trang, ≥ 1. Denormalize để index và sắp xếp. |
| ordinal | INT UNSIGNED NOT NULL | Thứ tự vùng trong trang, ≥ 1. |
| reading_order | INT UNSIGNED NOT NULL | Thứ tự đọc trong toàn tài liệu, ≥ 1. |
| role | VARCHAR(30) NOT NULL | Hình dạng quan sát được của vùng. Vocabulary đóng, xem ghi chú dưới § Constraints. |
| bbox_x | DECIMAL(9,6) NULL | Cạnh trái, chuẩn hoá 0..1 theo chiều rộng trang. |
| bbox_y | DECIMAL(9,6) NULL | Cạnh trên, chuẩn hoá 0..1 theo chiều cao trang. |
| bbox_width | DECIMAL(9,6) NULL | Chiều rộng, chuẩn hoá 0..1. |
| bbox_height | DECIMAL(9,6) NULL | Chiều cao, chuẩn hoá 0..1. |
| text | LONGTEXT NULL | Text quan sát được **bên trong bbox của vùng này**, kể cả với `figure`. |
| char_count | INT UNSIGNED NULL | Độ dài text, phục vụ chunking và đo lường. |
| confidence_score | DECIMAL(5,2) NULL | Confidence 0–100 khi extractor báo cáo. |
| extraction_method | VARCHAR(50) NOT NULL | `ocr` hoặc `embedded_text`. |
| provider | VARCHAR(100) NULL | Extractor; NULL khi dùng text layer sẵn có. |
| crop_storage_key | VARCHAR(512) NULL | Object key của ảnh crop vùng này. Xem § Đường lưu. |
| crop_mime_type | VARCHAR(100) NULL | MIME của crop; Phase 1 đóng ở `image/png`. |
| crop_width | INT UNSIGNED NULL | Chiều rộng crop theo pixel. |
| crop_height | INT UNSIGNED NULL | Chiều cao crop theo pixel. |
| crop_bytes | INT UNSIGNED NULL | Kích thước crop theo byte, phục vụ trần tài nguyên. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản extractor/model/cấu hình. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn khi trích xuất. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Trạng thái output. |
| metadata | JSON NULL | Provenance bổ sung; **không** chứa text. |
| created_at | TIMESTAMP(6) NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP(6) NULL | Thời điểm cập nhật. |

## Constraints And Indexes

```sql
UNIQUE (customer_id, media_file_id, locale, locator_type, locator_value,
        processing_version);
UNIQUE (id, customer_id);
UNIQUE (customer_id, media_file_id, locale, processing_version, reading_order);
UNIQUE (id, customer_id, media_file_id, locale, processing_version);
INDEX  (customer_id, media_file_id, locale, page, ordinal);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);
INDEX  (customer_id, processing_job_id);
INDEX  (customer_id, media_file_id, role);
UNIQUE (customer_id, crop_storage_key);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;

CHECK (status IN ('pending','processing','ready','failed','archived'));
CHECK (locator_type IN ('region'));
CHECK (role IN ('paragraph','heading','list','table','figure','caption',
                'header','footer','other'));
CHECK (extraction_method IN ('ocr','embedded_text'));
CHECK (page >= 1);
CHECK (ordinal >= 1);
CHECK (reading_order >= 1);
CHECK (confidence_score IS NULL
       OR (confidence_score >= 0 AND confidence_score <= 100));
CHECK (extraction_method <> 'ocr' OR provider IS NOT NULL);
CHECK (
  (bbox_x IS NULL AND bbox_y IS NULL
   AND bbox_width IS NULL AND bbox_height IS NULL)
  OR
  (bbox_x IS NOT NULL AND bbox_y IS NOT NULL
   AND bbox_width IS NOT NULL AND bbox_height IS NOT NULL
   AND bbox_x >= 0 AND bbox_y >= 0
   AND bbox_width > 0 AND bbox_height > 0
   AND bbox_x + bbox_width <= 1 AND bbox_y + bbox_height <= 1)
);
CHECK (
  (crop_storage_key IS NULL AND crop_mime_type IS NULL AND crop_width IS NULL
   AND crop_height IS NULL AND crop_bytes IS NULL)
  OR
  (crop_storage_key IS NOT NULL AND crop_mime_type IS NOT NULL
   AND crop_width IS NOT NULL AND crop_height IS NOT NULL AND crop_bytes IS NOT NULL
   AND crop_width > 0 AND crop_height > 0 AND crop_bytes > 0)
);
CHECK (crop_storage_key IS NULL OR bbox_x IS NOT NULL);
CHECK (crop_mime_type IS NULL OR crop_mime_type IN ('image/png'));
```

`CHECK (role <> 'figure' OR text IS NULL)` của các bản trước đã **bị loại bỏ** —
xem § Ghi chú `role` bên dưới.

### Ảnh crop của vùng — v1.9, đã migrate và có runtime

ADR-0019 § D7 điểm 4 buộc Media cung cấp **crop và citation** cho mọi khối đồ
hoạ. Citation đã có (`page#ordinal`, ổn định theo `source_fingerprint`); crop thì
chưa có chỗ chứa. Đây là phần còn lại của
[DOC-CONFLICT-0022](../../quality/LF-Documentation-Conflicts.md).

#### Vì sao đặt trên chính region, không dùng `media_variants`

`media_variants` là asset thay thế **của cả file** — `720p`, `thumbnail`,
`webp`. Crop thì thuộc về **một vùng trong một revision**: nó sinh ra cùng
revision, phải `archived` cùng revision, và mất nghĩa nếu tách khỏi `bbox` đã
tạo ra nó. Đặt ở `media_variants` sẽ cần thêm `region_id` và một quy tắc archive
song song — tức mô phỏng lại quan hệ vốn đã có sẵn ở đây.

#### Quy tắc

* Năm cột crop là **tất-cả-hoặc-không-có**. Một row không được có
  `crop_storage_key` mà thiếu kích thước, và ngược lại.
* Crop được sinh cho `role = 'figure'` trong Phase 1, và chỉ khi vùng có `bbox`.
  Hai điều kiện đó hợp thành **tập vùng đủ điều kiện**; ngoài tập đó thì năm cột
  crop luôn NULL và đó **không** phải dữ liệu thiếu. Consumer đọc theo bảng ở
  [Spec B § 5.3](../../platform/LF-Media-Read-Contract.md). Schema không cấm
  role khác — tập này là cấu hình runtime (`crop_roles`), không phải CHECK.
* All-or-nothing áp dụng **trên tập đủ điều kiện**, không phải trên mọi row: nếu
  một vùng đủ điều kiện có crop thì mọi vùng đủ điều kiện trong cùng revision
  đều có. Luật fail-whole-revision là thứ bảo đảm điều này.
* Crop là **private**, phục vụ qua signed delivery như mọi asset Media khác.
  Không có URL công khai.
* Crop **không bị xoá khi revision chuyển `archived`**. Một AI Proposal đã trích
  dẫn crop của bản cũ phải xem lại được đúng ảnh đó. Crop chỉ mất khi Media File
  bị xoá.
* `crop_mime_type` đóng ở `image/png` bằng CHECK, cùng kiểu với `role`,
  `status` và `extraction_method`. Mở JPEG là một migration có chủ ý, không
  phải một giá trị lọt vào.
* `crop_bytes` là INT UNSIGNED (trần ~4 GB), không phải BIGINT: crop lớn nhất
  đo được là 1 MB, và trần cả tài liệu là 64 MB.
* Crop **không** thay thế `bbox`. Toạ độ vẫn là nguồn sự thật về vị trí; crop là
  tiện ích để consumer nhìn thấy vùng mà không phải tự render lại trang.

#### Ràng buộc

```sql
CHECK (
  (crop_storage_key IS NULL AND crop_mime_type IS NULL AND crop_width IS NULL
    AND crop_height IS NULL AND crop_bytes IS NULL)
  OR
  (crop_storage_key IS NOT NULL AND crop_mime_type IS NOT NULL
    AND crop_width IS NOT NULL AND crop_height IS NOT NULL AND crop_bytes IS NOT NULL
    AND crop_width > 0 AND crop_height > 0 AND crop_bytes > 0)
);
CHECK (crop_storage_key IS NULL OR bbox_x IS NOT NULL);
```

Ràng buộc thứ hai là điều kiện ngữ nghĩa: không có `bbox` thì không có gì để
cắt, nên một crop không kèm toạ độ là dữ liệu không giải thích được.

#### Đường lưu

```text
tenants/{customer}/media/{media_file_id}/regions/
  {source_fingerprint}/{processing_version}/{locale}/{page}-{ordinal}.png
```

**Sửa ở v1.8 (Database review).** Bản v1.5 chỉ đặt `processing_version` trong
đường dẫn, với lý do "để hai revision không ghi đè nhau". Lý do đúng, đường dẫn
sai: định danh một revision là **bộ ba** `(source_fingerprint,
processing_version, locale)` — chính bộ ba của UNIQUE key ở trên — chứ không
phải một mình `processing_version`. Hai trường hợp ghi đè thật mà bản cũ không
chặn:

* Nội dung file được thay trong khi extractor version giữ nguyên → cùng
  `processing_version`, khác `source_fingerprint`, **cùng đường dẫn**. Crop của
  tài liệu cũ bị crop của tài liệu mới đè lên, trong khi row cũ vẫn `archived`
  và vẫn trỏ tới key đó. Consumer đọc revision cũ nhận về ảnh của nội dung khác.
* Cùng file trích xuất ở hai locale → cùng đường dẫn.

Cả hai đều phá đúng lời hứa lớn nhất của bảng này: bản cũ đọc được mãi mãi.

`UNIQUE (customer_id, crop_storage_key)` được thêm ở cùng bản. Nó biến "đường
dẫn duy nhất vì chúng ta suy ra cẩn thận" thành thứ database tự bảo đảm — đúng
loại lỗi vừa xảy ra ở trên. Đó cũng là lý do `crop_storage_key` rút từ
VARCHAR(1024) xuống **VARCHAR(512)**: utf8mb4 × 1024 = 4096 byte, vượt trần
3072 byte của index InnoDB nên không thể UNIQUE; 512 vẫn dư cho đường dẫn dài
nhất (~200 ký tự).

#### Xoá crop — v1.11

Object storage và DB **không có transaction chung**, nên thứ tự quyết định
hướng hỏng. `MediaService::deleteMedia()` đánh dấu `deleted` trong DB **trước**
rồi mới chạm storage:

* Không bao giờ tồn tại một Media còn `ready` mà thiếu source hoặc thiếu crop.
  Delivery từ chối mọi trạng thái khác `ready`, nên row `deleted` không phục vụ
  ai kể cả khi storage chưa dọn xong.
* Thứ tự ngược lại — xoá storage trước — để lại Media `ready` mất crop hoặc mất
  source khi bước sau hỏng. Đó là mất dữ liệu thật, không phải rác thừa.

Hai hàm xoá đều **kiểm chứng lại** thay vì tin giá trị trả về: `deleteDirectory()`
có thể trả `true` mà object vẫn còn, và coi `false` là thành công nghĩa là báo
"PII đã biến mất" trong khi nó vẫn nằm đó.

Nếu dọn storage thất bại, row vẫn `deleted` và object trở thành rác mồ côi. Đó
là chi phí bắt buộc của việc không có transaction chung, và nó **có đường quay
lại**:

```bash
php artisan media:purge-deleted-storage
```

#### Hai nguồn rác, không cùng một điều kiện — v1.12

Bản v1.11 nói sweeper lấy danh sách row `deleted` làm danh sách việc. **Câu đó
chỉ đúng một nửa**, và review độc lập đã chỉ ra. Có hai loại rác:

1. **Media đã `deleted`** — cả source lẫn cây crop phải biến mất.
2. **Revision structured thất bại trên một Media vẫn `ready`** — crop đã lên
   storage trước khi persistence hỏng. Media **không** đổi trạng thái, đúng theo
   thiết kế: một lần trích xuất hỏng không được làm tài liệu mất `ready`.

Quét theo `status = 'deleted'` **không bao giờ thấy loại 2**. Đó là loại rác
thường gặp nhất, vì nó sinh ra từ chính đường hỏng mà crop cleanup phục vụ.

Với loại 2, thứ được xoá là crop **không được row `media_extracted_regions` nào
tham chiếu**. Đó là định nghĩa chính xác của "mồ côi", và nó đúng cả khi
revision được chạy lại thành công sau đó — crop của bản thành công có row trỏ
tới nên được giữ.

`--limit` đếm số Media **đã xử lý**, không phải số row đã quét. Đếm theo row quét
thì hàng trăm tombstone sạch đứng trước sẽ làm residue phía sau đói vĩnh viễn.

Mỗi Media được cô lập bằng try/catch riêng: một disk hỏng không được làm cả lượt
quét dừng lại, nếu không thì lần chạy sau vẫn mắc ở đúng row đó. Row lỗi **không
tính vào `--limit`** — nếu tính thì 500 Media trên một disk đang hỏng sẽ chặn
vĩnh viễn mọi residue hợp lệ phía sau, đúng kiểu đói mà `--limit` sinh ra để
tránh. Command trả exit code thất bại ở cuối lượt.

#### Không được dọn khi có job đang chạy

Provider đẩy crop lên storage **trước** khi job ghi `media_extracted_regions`.
Giữa hai bước đó, crop tồn tại mà chưa row nào trỏ tới — nhìn từ ngoài vào
**không phân biệt được với rác mồ côi**. Bấm Dọn ngay đúng lúc đó sẽ xoá crop
của một job sắp thành công, và revision `ready` sẽ trỏ tới file không còn tồn
tại. Nút "dọn rác" khi đó trở thành lệnh xoá asset hợp lệ.

Ba lớp chặn, mỗi lớp có test riêng và mutation riêng:

1. **Lúc quét**: bỏ qua mọi Media có job `structured_extraction` ở trạng thái
   `pending` hoặc `processing`. `pending` cũng tính, vì từ lúc claim tới lúc
   crop đầu tiên lên storage chỉ là một lệnh render.
2. **Lúc xoá, trong khoá row**: kiểm tra lại điều kiện trên. Job có thể được
   claim ngay sau khi quét xong. `ProcessMediaProcessingJob` claim job trong một
   transaction có `lockForUpdate()` trên đúng row `media_files` này, nên hai bên
   xếp hàng — không thể vừa claim job vừa xoá file của nó.
3. **Danh sách key được tính lại trong khoá**, không dùng lại danh sách lúc
   quét. Một revision chạy lại có thể vừa ghi đè đúng những key đó và đã commit
   row; xoá theo danh sách cũ là xoá asset hợp lệ.

#### Owner purge policy — 2026-08-28, bản thay thế

**Thay thế policy "sweeper chạy mỗi giờ" ghi trước đó cùng ngày.** Bản cũ đặt
lịch tự động; bản này bỏ hoàn toàn lịch tự động.

Quyết định: **không có tác vụ nào tự động xoá file.** Sweeper không được đăng ký
vào bất kỳ scheduler nào. Đây là quyết định, không phải hạng mục còn thiếu.

Lý do: xoá file là thao tác không hoàn tác được, và thứ bị xoá được nhận diện
bằng **suy luận** — "không còn row nào trỏ tới". Một sai sót trong suy luận đó,
khi chạy tự động lúc 3 giờ sáng, sẽ xoá dữ liệu thật mà không ai kịp thấy. Trong
khi đó rác này **không ai với tới được**: không có row thì không có
`crop_storage_key` để ký URL, và `generateDerivedSignedUrl()` từ chối mọi Media
khác `ready`. Dọn sớm hơn vì thế không mua thêm an toàn, chỉ mua thêm rủi ro.

Hai lối chạy, cả hai đều do người khởi động:

* Nút **Dọn ngay** trong Quản lý Media, kèm ghi chú giải thích và một bước xác
  nhận. Chỉ quét tenant hiện tại — `TenantContext` là ranh giới, không phải bộ lọc.
* `php artisan media:purge-deleted-storage` cho operator, có `--dry-run`.

Cả hai dùng chung `MediaStorageResidueSweeper`, nên không tồn tại luật thứ hai.

Hệ quả được chấp nhận: một lần dọn thất bại chỉ lộ ra qua log mức error cho tới
khi có người bấm lại. Không có alerting vận hành, vì không có job vận hành.

Legal hold và purge orchestration đầy đủ vẫn thuộc ADR-0018. Khi mở chúng, quyết
định "không tự động xoá" ở đây phải được xem lại **tường minh**, không mặc nhiên
bị ghi đè.

#### Trần tài nguyên — cần benchmark trước khi freeze

Đo thật bằng `pdftoppm` trên hai tài liệu đã có trong hệ thống, cắt đúng bbox
của từng figure region:

| Tài liệu | Figure | DPI | Tổng | Trung bình | Lớn nhất |
| --- | ---: | ---: | ---: | ---: | ---: |
| ALLIVA, 16 trang, 960×540 pts | 62 | 100 | 2,59 MB | 42,8 KB | 386,5 KB |
| ALLIVA | 62 | 150 | 4,71 MB | 77,8 KB | 695,7 KB |
| ALLIVA | 62 | **200** | **7,31 MB** | 120,7 KB | 1.032,4 KB |
| Tiếng Hàn sơ cấp, 100 trang, 544×748 pts | **300** | **200** | **14,00 MB** | 47,8 KB | 451,9 KB |

Chi phí thật thấp hơn ước lượng ban đầu một bậc: tài liệu dày nhất đang có —
300 figure trên 100 trang — chỉ tốn **14 MB** mỗi revision ở 200 DPI.

**Đề xuất `max_crop_bytes_per_document = 64 MB`.** Nó gấp hơn bốn lần trường hợp
dày nhất đo được, nên không chặn nhầm tài liệu bình thường, nhưng vẫn chặn được
trường hợp bệnh lý — 5.000 region ở trần `max_regions_per_document` mà mỗi crop
cỡ ảnh toàn trang.

DPI đề xuất **200**: ở mức này crop của ALLIVA giữ đọc được nhãn trục và chữ
trong sơ đồ, còn 100 DPI thì chữ nhỏ bắt đầu nhoè. Chênh lệch dung lượng giữa
hai mức chỉ 2,59 MB so với 7,31 MB — không đáng đánh đổi lấy chất lượng.

Vượt trần thì fail cả revision với `structured_extraction_too_large`, đúng luật
hiện hành — **không cắt bớt crop**, vì một bộ crop thiếu vài vùng thì consumer
không phân biệt được "vùng này không có crop" với "vùng này chưa được cắt".

Định dạng Phase 1 là **PNG**: crop chủ yếu là sơ đồ và biểu đồ có chữ, nơi PNG
không làm nhoè nét. Với ảnh chụp thì JPEG nhỏ hơn nhiều, nhưng phân biệt
"biểu đồ" với "ảnh chụp" lại là phân loại — thuộc ADR-0020, không phải Phase 1.
Chọn một định dạng an toàn cho mọi trường hợp là đúng ranh giới.

#### Chưa quyết

* ~~Read Contract trả crop thế nào.~~ Đã chốt trong
  [LF-Media-Read-Contract](../../platform/LF-Media-Read-Contract.md) v1.9 § 5.3:
  crop đi trên chính unit `region` ở `structure.crop`, **không** tạo
  `content_type` mới, và có cờ request `include_crop` mặc định `false` để không
  ký signed URL cho consumer chỉ đọc text.
* ~~Database review cho năm cột crop, rồi migration.~~ Xong 2026-08-28:
  migration `2026_08_28_000100_add_region_crop_columns`, và runtime sinh crop
  200 DPI PNG trong `DoclingStructuredExtractionProvider::renderCrops()`.
* Crop hiện chỉ sinh cho `role = 'figure'`. Mở cho `table` không cần đổi
  schema, nhưng cần đo lại trần vì số vùng sẽ tăng.
* ~~Xoá crop khi Media File bị xoá chưa có runtime.~~ Xong 2026-08-28, sửa lại
  ở v1.11 sau review độc lập lần hai. Chi tiết ở § Xoá crop bên dưới.
* Retention theo lịch, legal hold và purge orchestration vẫn thuộc gate đang mở
  của ADR-0018.

### Ghi chú `role` — v1.1, Approved 2026-08-27

Vocabulary `role` **giữ nguyên chín giá trị**, không tách `figure` thành
`chart` / `diagram` / `image`.

Tách ra không phải mở rộng vocabulary mà là yêu cầu Media **phân loại** nội dung
đồ hoạ. Phân biệt "biểu đồ cột" với "sơ đồ luồng" là phán đoán về ý nghĩa, thuộc
AI theo [ADR-0019 § D7](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)
và [ADR-0020](../../adr/ADR-0020-AI-Vision-Interpretation-Boundary.md). Một
vocabulary mà chính producer không điền đúng được thì thêm vào chỉ tạo dữ liệu sai
có vẻ chính xác.

### `figure` mang text trong bbox — v1.4, Owner quyết định 2026-08-28

`CHECK (role <> 'figure' OR text IS NULL)` của các bản trước **đã bị loại bỏ**,
đóng [DOC-CONFLICT-0022](../../quality/LF-Documentation-Conflicts.md).

Bản trước giải thích rằng chữ bên trong khối đồ hoạ *"thuộc vùng text riêng,
không phải thuộc row `figure`"*. Đo trên PDF ALLIVA 16 trang cho thấy cách hoà
giải đó không đúng với dữ liệu thật:

```text
62 region `figure`, cả 62 có text = NULL
text region nằm trong bbox của figure : 0      (chồng lấn một phần: 2/62)
text theo trang 47.852 ký tự · text trong region 13.373 → 72% không truy được ở tầng region
```

Không có "vùng text riêng" nào ở đó cả. Giữ CHECK nghĩa là phần lớn chữ của tài
liệu không trích dẫn được ở mức vùng — trái với chính lý do các region tồn tại.

Lý do quyết định, ngoài số đo:

* **Cắt text theo bbox là quan sát, không phải diễn giải.** Nó khẳng định "những
  ký tự này nằm trong hình chữ nhật này", không khẳng định biểu đồ nói gì. Ranh
  giới với [ADR-0020](../../adr/ADR-0020-AI-Vision-Interpretation-Boundary.md)
  không đổi.
* **`figure` là role duy nhất không theo luật của chính pipeline.** `paragraph`,
  `heading`, `list`, `caption` đều đã mang text trùng với text theo trang —
  region vốn là một lát cắt có toạ độ của cùng nội dung, không phải kho riêng.
  CHECK cũ không bảo vệ ranh giới nào; nó tạo một ngoại lệ.
* **ADR-0019 § D7 vốn đã yêu cầu điều này** ở điểm 3. Tài liệu bảng mới là chỗ
  lệch, không phải ADR.

`extraction_method` của region **phải** ghi đúng nguồn, vì độ tin cậy khác nhau:

| Nguồn | `extraction_method` | Cách lấy |
| --- | --- | --- |
| PDF có text layer | `embedded_text` | Cắt theo bbox bằng `pdftotext -x -y -W -H` |
| PDF scan | `ocr` | Tesseract chạy trên crop của vùng |

Quyết định này **không** mở vocabulary `role`: `figure` vẫn dùng chung cho biểu
đồ, sơ đồ và ảnh chụp. Phân loại chúng vẫn thuộc ADR-0020.

`UNIQUE (…, reading_order)` chỉ bảo đảm **không trùng** trong mỗi revision. Nó
**không** bảo đảm dãy liên tục `1..N`: bộ giá trị `1, 2, 9` vẫn hợp lệ với
database. Tính liên tục là **readiness invariant**, xem § Readiness invariants.

`UNIQUE (id, customer_id, media_file_id, locale, processing_version)` không phục
vụ truy vấn. Nó tồn tại để `media_extracted_tables` tham chiếu ngược bằng khóa
ngoại phủ đủ scope, chứ không chỉ phủ tenant.

`CHECK` của `bbox_*` viết dạng all-or-none tường minh. Dạng `bbox_x IS NULL OR
(…)` **không** đủ: với `bbox_x` có giá trị và `bbox_y` NULL, vế sau cho `UNKNOWN`,
và SQL CHECK chỉ fail khi kết quả là FALSE — bộ toạ độ khuyết một nửa sẽ lọt qua.

Chuẩn hoá 0..1 là điều làm hình học độc lập với DPI. Đây chính là lý do ADR-0019
§ D1 giữ toạ độ **ngoài** locator.

## Readiness invariants

Các điều kiện dưới đây **không biểu diễn được bằng CHECK** vì chúng xuyên nhiều
row. Chúng phải được kiểm trong cùng transaction chuyển revision sang `ready`,
và phải có test database chứng minh, trước khi bảng này được coi là Foundation
Ready:

1. `reading_order` của một revision là dãy liên tục `1..N`, không khuyết, không
   nhảy. Khuyết một giá trị nghĩa là extractor đã bỏ sót một vùng mà không ai
   biết là bỏ sót.
2. `ordinal` trong mỗi `page` là dãy liên tục `1..M`.
3. Mỗi region có `role = 'table'` có đúng 0 hoặc 1 row trong
   `media_extracted_tables`.

Revision không thoả một trong ba điều kiện phải fail toàn phần, không được ghi
một phần rồi để `ready`.

`CHECK (locator_type IN ('region'))` cố ý chỉ mở một giá trị, giống cách
`media_extracted_texts` chỉ mở `page`. Bảng này không phải nơi chứa mọi loại
locator tương lai.

## Sample Data

```text
id=900, customer_id=1, media_file_id=700, processing_job_id=310,
locale=vi, locator_type=region, locator_value=12#2, page=12, ordinal=2,
reading_order=41, role=table, bbox_x=0.081000, bbox_y=0.412000,
bbox_width=0.838000, bbox_height=0.221000, text=NULL, char_count=NULL,
extraction_method=embedded_text, provider=NULL,
processing_version=local-document-v2, status=ready
```

## Design Notes

**Text trùng lặp là có chủ ý.** Cùng một đoạn văn xuất hiện ở text cấp trang và
ở region chứa nó. Bỏ trùng lặp bằng cách bỏ text cấp trang sẽ phá mọi citation
`page` đang tồn tại; bỏ text ở region sẽ khiến region chỉ còn là trang trí — AI
biết "có một khối ở đây" mà không đọc được khối đó. Cái giá là dung lượng, và nó
phải được tính vào giới hạn tài nguyên: cap ký tự của một revision áp cho **tổng**
text cấp trang và text region, không phải riêng từng bảng.

Giới hạn ban đầu được Owner freeze ngày 2026-08-25 và được amendment ngày
2026-08-28 tại
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)
§ Structured extraction resource controls: `max_regions_per_page = 100` và
`max_regions_per_document = 5000`, **cả hai cùng lúc**. Chỉ có trần tài liệu thì
một trang vẫn sinh được 5.000 vùng; chỉ có trần trang thì một tài liệu 100 trang
có thể sinh 10.000 vùng. Amendment dựa trên evidence tài liệu thật: một PDF 100
trang sinh 1.924 vùng tổng cộng nhưng trang 15 có 61 vùng hợp lệ, vượt trần cũ
`50`. Revision đã fail dưới processing version cũ không được sửa hoặc tái sử
dụng; runtime phải tạo revision mới sau khi version/config tương ứng được mở.

Text của region tính vào cùng ngân sách `max_extracted_characters = 500000` với
text cấp trang và text cell — trùng lặp có chủ ý vẫn tính theo dung lượng thực
persist. Vượt bất kỳ trần nào: `structured_extraction_too_large`, fail toàn
revision, không truncate.

Region cho spreadsheet: **không có**. Sheet không có hình học trang, nên
worksheet đi thẳng vào `media_extracted_tables` với `locator_type = 'sheet'`.
---

## D1–D6 amendment — Approved 2026-08-31

Owner approval trong task Document Processing. D1 crop present phải non-null đủ dimensions/bytes, positive; giữ all-null branch. D3 canonical input transition archive output cũ, giữ citation/crop.

Migration forward mới sau review; preflight báo count và IDs vi phạm rồi abort, không tự fill/delete. Approval thiết kế không phải evidence schema đã deployed.
