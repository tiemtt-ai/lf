# LF-Media-Read-Contract.md

Version: 1.17

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-09-04

Document Path: platform/LF-Media-Read-Contract.md

Related ADR:

* [ADR-0004 — Media Foundation](../adr/ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0018 — Media PII And External Processing Boundary](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved
* [ADR-0019 — Media Structured Extraction Boundary](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) — Approved

Related Specification:
[LF-Media-Processing-Contract](LF-Media-Processing-Contract.md)

---

## Region language evidence and formula threshold — Approved 2026-09-04

Region trả thêm `languages`: danh sách mọi chữ viết quan sát được trong vùng đó,
xếp theo số ký tự giảm dần.

```text
languages: [ { script, locale, char_count }, ... ]
```

`script` là ISO 15924 quan sát được. `locale` là locale trong profile ứng với
script đó, hoặc `null` khi profile không có locale nào tương ứng — `null` ở đây
là `undetermined`, không phải thiếu dữ liệu. `char_count` là phép đếm ký tự,
**không** phải confidence; consumer không được quy đổi nó thành điểm tin cậy.

`detected_locale` và `script` ở cấp region giữ nguyên ngữ nghĩa cũ. Với revision
sinh ra từ v1.8 trở đi, chúng bằng đúng phần tử đầu của `languages`. Consumer
đang đọc hai trường đó không phải đổi. Vùng song ngữ có nhiều hơn một phần tử;
vùng không nhận được chữ viết nào trả `languages` rỗng cùng
`detected_locale = undetermined`.

**Revision có trước v1.8 trả `languages` rỗng** dù `detected_locale` có giá trị.
Đây không phải mâu thuẫn và cũng không phải thiếu dữ liệu: bằng chứng đa trị
không tồn tại cho những revision đó và không được backfill bằng phỏng đoán.
Consumer cần bằng chứng đầy đủ phải đọc một revision từ v1.8 trở đi; consumer
đọc revision cũ dùng hai cột dominant như trước. Phân biệt bằng
`processing_version` đi kèm mọi unit.

`content_type = formula` chỉ trả row khi evidence chứa ít nhất một toán tử quan
sát được trong `raw_text`. Vùng mà provider gán `role = formula` nhưng không đạt
ngưỡng đó **vẫn đọc được** qua `content_type = region` với đầy đủ page, locator,
bbox và crop — nó không biến mất, chỉ không được trả như formula evidence.

---

## Document language-profile reads and formula evidence — Approved 2026-09-03

Document consumer có thể chọn canonical `language_profile` cùng
`processing_version`/`source_fingerprint`. Nếu không nêu, service chỉ chọn đúng
một current profile; nhiều current profile là `ambiguous_profile`, không tự gộp.
Page text, region, table và formula trả về phải thuộc cùng processing job,
profile hash, source fingerprint và revision.

`content_type = formula` trả raw evidence, region locator/page/bbox/crop,
normalization status, format, value và confidence. `unavailable`/`failed` là dữ
liệu có tên. Page text và region vẫn đọc được khi normalization thất bại. Media
Read không diễn giải hoặc xác nhận công thức đúng.

Region trả `detected_locale` và `script` khi có signal đủ tin cậy; NULL được
biểu diễn là `undetermined`. Mã lỗi bổ sung: `ambiguous_profile`,
`language_profile_unavailable`.

---

## D2–D4 — Approved 2026-08-31

Owner duyệt: canonical spreadsheet mới dùng sheet/spreadsheet_cells, archived page/embedded_text citation cũ vẫn đọc nguyên bản. PDF mixed blank trả page thật với text rỗng và char_count=0; pages_with_text chỉ tính char_count>0. OCR revision mới archive cấu trúc thuộc canonical cũ, không đổi terminal job hoặc xoá citation/crop. Không đổi authorization/owner/tenant/locale boundary.

---

# Scope

Hợp đồng đọc output dẫn xuất của Media cho consumer, trước hết là AI.

Đây là Spec B. Substrate sản xuất output nằm ở
[LF-Media-Processing-Contract](LF-Media-Processing-Contract.md); tài liệu này chỉ
quy định cách đọc chúng ra.

Version 1.5 áp dụng [ADR-0019](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)
đã Approved: thêm hai content type `region` và `table`, và mở `locator_type`
sang `sheet`/`region`. Không có đường đọc mới, không có API riêng cho structured
data; mọi thứ khác của hợp đồng giữ nguyên.

Version 1.9 mở đường trả **ảnh crop của vùng** trên chính unit `region`, không
qua `content_type` mới. Version 1.10 ghi nhận crop đã **implemented** cả hai
đầu: runtime sinh crop khi trích xuất, và Read Service trả `structure.crop`
cùng cờ `include_crop`. Xem § 5.3 và
[media_extracted_regions](../database/media/media_extracted_regions.md).

Version 1.7 đóng DOC-CONFLICT-0021 bằng selector canonical `usage_type`. Caller
phải chỉ đúng media slot trong owner context; service không được dùng
`first()`/`latest()` để đoán source và không nhận bare `media_file_id`.

Version 1.8 đặt tên cho structured coverage gap bằng
`structure_unavailable`, bắt buộc page-text fallback, và thêm phép đo coverage
live. Phép đo phải chọn đúng current ready structured revision rồi đối chiếu
canonical page-text revision có cùng `source_fingerprint`; không được gộp row
của nhiều source/revision. Lời gọi coverage đi qua cùng active-usage,
tenant/owner authorization, ambiguity và audit boundary như derived-content.

Version 1.4 áp dụng ADR-0018 đã được Architecture Owner approve ngày 2026-08-25.
Approval này không authorize external processing và không tự đóng các gate triển
khai retention/deletion.

Không thuộc phạm vi: AI Proposal persistence, review workflow, ghi Learning
Node/Mapping. Tất cả vẫn gated theo ADR-0017 §268.

---

# 1. Nguyên tắc

AI là **consumer**. Nó không sở hữu processing state, và ba điều sau là tuyệt
đối:

* AI **không** đọc trực tiếp object storage.
* AI **không** query bảng `media_*`.
* AI **không** ghi bất kỳ bảng `media_*` nào.

Mọi truy cập đi qua Media Read Service. Lý do không phải hình thức: quyền truy
cập, trạng thái readiness, chọn locale và citation locator đều là luật của Media,
và một consumer đọc thẳng bảng sẽ tự diễn giải lại chúng — mỗi consumer một
kiểu.

---

# 2. Đơn vị đọc

Service trả **derived content unit**, không trả file:

```text
unit := {
  media_file_id, source_fingerprint, processing_version,
  content_type,           // extracted_text | transcript | caption_asset
                          // | variant | region | table
  locale,
  locator: { type, value },   // page | timespan | sheet | region | null
  text,                   // null với caption_asset và variant
  delivery_url,           // signed; chỉ với caption_asset và variant
                          // crop của region đi ở structure.crop — xem § 5.3
  confidence,             // null khi provider không báo cáo
  status
}
```

`locator` là `null` chỉ với `caption_asset` và `variant` — hai thứ là file, không
phải đoạn trích dẫn được. Xem § 5.

`region` và `table` trả thêm trường `structure`: với `region` là
`{ role, reading_order, bbox, crop }`, với `table` là
`{ row_count, column_count, has_header, cells: [{ row, column, row_span,
column_span, is_header, text }] }`. Đây là **quan sát**, không phải diễn giải:
Media nói bảng có mấy hàng mấy cột và ô nào chứa chuỗi gì, không nói bảng đó nói
về cái gì (ADR-0019 § D4).

---

# 3. Định danh nguồn

Consumer gọi theo **owner context**, không theo `media_file_id`:

```text
GET derived-content
  actor_id              bắt buộc; explicit cho HTTP | queue | console
  owner_type            course_activity | course_version_activity
  owner_id
  usage_type            bắt buộc; media slot trong owner context
  content_type
  locale                (tuỳ chọn; xem § 4)
  processing_version    (tuỳ chọn; xem § 4.1)
  source_fingerprint    (tuỳ chọn; xem § 4.1)
  page                  (tuỳ chọn; chỉ với `region` và `table` — xem § 5.1)
  include_crop          (tuỳ chọn; mặc định false; chỉ với `region` — xem § 5.3)
```

Media tự phân giải owner → active usage → Media File. Consumer không được cầm
`media_file_id` trực tiếp, vì quyền truy cập gắn với owner chứ không gắn với
file: cùng một file có thể phục vụ hai Activity với hai mức quyền khác nhau.

`usage_type` là một phần của định danh nguồn, không phải bộ lọc tiện dụng. Mapping
Phase 1 đóng như sau:

| `content_type` | `usage_type` hợp lệ |
| --- | --- |
| `extracted_text`, `region`, `table` | `document` |
| `transcript` | `audio` hoặc `video` |
| `caption_asset` | `video` |
| `variant` | `video` |

Service chỉ xét active usage khớp chính xác `(customer_id, owner_type, owner_id,
usage_type)`. Không có row thì trả `detached` hoặc `missing`. Nếu có nhiều hơn
một active row trong cùng slot, service trả `ambiguous_source`; không chọn row
đầu, row mới nhất hay media id lớn nhất. Schema hiện cho phép trạng thái này nên
fail-closed ở service là bắt buộc. Mở thêm slot/content mapping là amendment của
contract này, không phải quyết định cục bộ của consumer.

Authorization: tenant từ context hiện hành, và actor phải được authorize trên
owner đó theo luật của Course Domain. Media không tự diễn giải business state
của owner; nó hỏi Course adapter.

Service không đọc actor ngầm từ HTTP request. Caller truyền `actor_id` tường
minh; authorizer nạp actor trong đúng `customer_id` và kiểm trạng thái/role.
Do đó cùng contract dùng được từ AI queue/console mà không nới quyền.

PII không cấp thêm quyền. Actor/AI consumer không được đọc output chỉ vì OCR đã
thành công, vì output có hoặc không có PII, hay vì caller là một AI job. Tenant
và owner-context authorization ở trên áp dụng giống nhau cho source có PII,
redacted derivative và mọi derived output. External processing eligibility là
policy riêng và không được Media Read suy ra từ một lần đọc `allowed`.

---

# 4. Chọn locale

1. Nếu request nêu `locale`, trả đúng locale đó hoặc lỗi `locale_unavailable`.
2. Nếu không nêu, trả locale canonical — `media_files.processing_locale`.
3. **Không** fallback sang locale khác, không dùng locale UI, browser hay user
   preference.

Fallback im lặng nguy hiểm hơn lỗi: một Proposal AI trích dẫn transcript tiếng
Hàn trong khi tác giả tưởng đang đọc tiếng Việt là sai lầm không ai phát hiện
được từ output.

## 4.1. Chọn revision

Mặc định service trả **bản hiện hành** — row `ready` mới nhất cho
`(owner, content_type, locale)`.

Revision hiện hành được chọn theo processing job identity lớn nhất
(`processing_job_id`, rồi `id` làm tie-break), không theo `created_at` của một
output row; nhiều page/segment của hai revision không thể làm lệch lựa chọn.

Consumer đọc lại một bản cũ bằng cách nêu đích danh:

| Tham số | Tác dụng |
| --- | --- |
| `processing_version` | Chọn đúng revision đó, kể cả khi status là `archived` |
| `source_fingerprint` | Ràng buộc thêm rằng revision đó dựng từ đúng nội dung nguồn này |

Quy tắc:

* Nêu `processing_version` thì service trả revision đó **bất kể** `ready` hay
  `archived`. Đây là ngoại lệ duy nhất của luật "chỉ trả `ready`" ở § 5, và nó
  tồn tại vì một Proposal đã trích dẫn trang 12 của bản cũ cần đọc lại đúng bản
  đó — không phải trang 12 sau khi OCR lại.
* Nêu cả hai thì cả hai phải khớp cùng một row; lệch nhau là lỗi, không phải
  ưu tiên cái này bỏ cái kia.
* Revision phải thuộc **đúng owner context** đang gọi. Một `processing_version`
  hợp lệ của Media File khác không cấp quyền đọc: authorization vẫn gắn với
  owner, không gắn với version.
* Không nêu gì thì không bao giờ trả `archived`.

| Tình huống | Mã lỗi |
| --- | --- |
| `processing_version` không tồn tại cho owner/content_type/locale này | `revision_unavailable` |
| `source_fingerprint` không khớp revision đã nêu | `revision_mismatch` |
| Revision tồn tại nhưng thuộc owner context khác | `unauthorized` |

---

# 5. Readiness và citation

Chỉ row `ready` được trả ra. Mọi trạng thái khác là mã lỗi có tên, không phải
mảng rỗng.

| content_type | Nguồn | Locator |
| --- | --- | --- |
| `extracted_text` | `media_extracted_texts` | `page` (document), `sheet` (spreadsheet) |
| `transcript` | `media_transcripts` | `timespan` |
| `region` | `media_extracted_regions` | `region` |
| `table` | `media_extracted_tables` + `media_table_cells` | `region` hoặc `sheet` |
| `formula` | `media_extracted_formulas` + `media_extracted_regions` | `region` — locator/page/bbox/crop kế thừa từ formula region cha |
| `caption_asset` | `media_captions` | `null` — file VTT/SRT/ASS, trả `delivery_url` |
| `variant` | `media_variants` | `null` — asset thay thế, trả `delivery_url` |

## 5.1. Trang có text nhưng không có cấu trúc

Structured extraction **không** đạt coverage 1.00. Đo trên tài liệu thật: PDF
text-layer phủ đủ, còn trang scan thì không đảm bảo — một mẫu cho 20/67 trang
scan có text mà không sinh region nào, với 278–2.017 ký tự mỗi trang. Chi tiết
và số đo nằm trong [ADR-0019](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md).

Nêu `page` với `content_type` là `region` hoặc `table`:

| Tình huống | Kết quả |
| --- | --- |
| Trang có cấu trúc | Trả unit như bình thường |
| Trang có canonical text nhưng **không** có region trong revision hiện hành | `structure_unavailable` |
| Trang không có gì | `missing` |

`structure_unavailable` **không phải lỗi hệ thống**. Nó là câu trả lời: *"trang
này có nội dung, nhưng chưa được cấu trúc hoá."* Consumer gặp nó phải **fallback
sang `extracted_text` của chính trang đó** — nội dung vẫn đầy đủ, chỉ mất độ
chính xác của trích dẫn từ mức vùng xuống mức trang.

Lý do mã này tồn tại: nếu trả mảng rỗng, consumer không phân biệt được ba tình
huống khác nhau — trang trắng thật, trang có nội dung mà layout model trượt, và
lỗi hệ thống. Khi đó AI **không thiếu dữ liệu; nó không biết là mình đang
thiếu**, và sẽ trả lời tự tin trên phần đã có. Đặt tên cho sự vắng mặt là cách
duy nhất để consumer hành xử đúng.

`page` chỉ hợp lệ với `region` và `table`; nêu nó với `content_type` khác trả
`unsupported_source`.

## 5.2. Đo độ phủ trước khi đọc

Consumer muốn biết toàn cảnh thay vì dò từng trang thì gọi:

```text
structure-coverage
  owner_type, owner_id, usage_type, locale?
→ { pages_with_text, pages_with_regions, pages_text_without_structure[] }
```

Giá trị được tính **trực tiếp từ bảng output** tại thời điểm đọc, không đọc
`media_processing_jobs.metadata`. Hai lý do: hợp đồng này cấm consumer chạm bảng
`media_*` của Media, và metadata coverage chỉ tồn tại cho job chạy sau khi tính
năng đó được thêm — job cũ chưa backfill. Tính live thì luôn đúng cho mọi
revision.

**Caption không trích dẫn được ở mức cue.** Một row là một file chứa nhiều cue.
Consumer cần trích dẫn theo thời gian phải dùng `transcript`, nơi một row đã là
một đoạn có `timespan` thật. Nếu về sau cần citation ở mức cue của chính caption
asset, đó là một derived cue contract riêng — mỗi cue một row với
`{timespan, text}` — và phải được review trước khi API mở nó.

Mọi unit trả ra **bắt buộc** kèm `source_fingerprint` và `processing_version`.
Consumer lưu hai giá trị này cùng mọi thứ nó tạo ra: đó là cách một Proposal
biết được nó đã đọc bản nào, và là điều kiện để phát hiện stale mà không phải
đoán.

## 5.3. Ảnh crop của vùng

Crop là ảnh cắt đúng `bbox` của một region. Nó **không** phải một
`content_type` riêng, và đây là quyết định có chủ ý:

* Một `content_type` riêng buộc consumer gọi hai lần rồi tự ghép theo
  `locator_value`. Chính § 1 cấm consumer diễn giải lại quan hệ của Media —
  cái ghép đó là việc của Media.
* Crop vô nghĩa nếu tách khỏi `role`, `reading_order` và `bbox` của chính
  region. Database đã quyết định như vậy rồi: crop nằm trên
  `media_extracted_regions`, không nằm ở `media_variants`. Read shape phải kể
  cùng một câu chuyện với schema.

Hình dạng:

```text
region.structure.crop := null | { width, height, bytes, delivery_url }
```

| `include_crop` | `crop` | `crop.delivery_url` |
| --- | --- | --- |
| `false` (mặc định) | object nếu revision có crop | `null` |
| `true` | object nếu revision có crop | signed URL, ngắn hạn |

Mặc định `false` là bắt buộc chứ không phải tối ưu hoá: một trang có thể tới
100 region, và ký 100 signed URL cho một consumer chỉ muốn đọc text là lãng phí
và mở rộng bề mặt rò rỉ vô cớ. Không nêu cờ thì consumer vẫn biết crop **tồn
tại** và nặng bao nhiêu, đủ để quyết định có xin URL hay không.

Nêu `include_crop` với `content_type` khác `region` trả `unsupported_source`,
cùng luật với `page` ở § 5.1.

### `crop = null` và tập vùng đủ điều kiện

**Sửa ở v1.11.** Bản v1.9–v1.10 viết rằng crop là all-or-nothing trong một
revision, nên `null` chỉ có một nghĩa là "revision sinh trước khi crop được
bật". **Câu đó sai so với runtime**, và review độc lập đã chỉ ra: runtime chỉ
cắt crop cho `role = 'figure'`, và bỏ qua vùng không có `bbox`. Một revision
hiện hành vì thế có cả vùng mang crop lẫn vùng `crop = null` cùng lúc — consumer
đọc theo câu cũ sẽ kết luận sai.

Luật đúng là **all-or-nothing trên tập vùng đủ điều kiện**, không phải trên mọi
vùng. Một vùng đủ điều kiện khi:

```text
crop_eligible(region) := role ∈ crop_roles  ∧  bbox ≠ null
```

Phase 1 `crop_roles = { figure }`. Consumer **tự tính được** vị từ này: `role`
và `bbox` đã nằm sẵn trong `structure` của cùng unit, không cần trường mới và
không cần gọi thêm lần nào.

Từ đó `crop = null` có đúng hai nghĩa, và consumer phân biệt được bằng chính dữ
liệu nó đang cầm:

| `crop_eligible` | `crop` | Nghĩa |
| --- | --- | --- |
| `false` | luôn `null` | Vùng này không thuộc tập được cắt. Không thiếu gì cả |
| `true` | object | Bình thường |
| `true` | `null` | **Cả revision này không có crop** — sinh trước khi crop được bật, hoặc chạy khi crop bị tắt bằng config |

Dòng thứ ba vẫn giữ được tính all-or-nothing: nếu một vùng đủ điều kiện mà
`crop = null`, thì **mọi** vùng đủ điều kiện trong revision đó đều `null`. Luật
*"vượt trần thì fail cả revision, không cắt bớt crop"* là thứ bảo đảm điều này —
không có revision nào cắt được nửa chừng rồi dừng.

Hai nguyên nhân của dòng thứ ba — revision cũ, và crop bị tắt — cố tình **không**
được phân biệt, vì với consumer chúng dẫn tới cùng một hành động: revision này
không có ảnh để xem, dùng `bbox` mà tự render nếu cần.

Mở `crop_roles` sang `table` hay role khác là amendment của tài liệu này, và
phải đo lại trần dung lượng vì số vùng đủ điều kiện sẽ tăng.

### Ràng buộc

* Crop đi qua signed delivery private, ngắn hạn, tenant-aware — cùng đường với
  `caption_asset` và `variant` ở § 5, không phải một cơ chế thứ hai.
* Crop chịu đúng owner-context authorization, revision selection và audit như
  mọi read khác. Một `delivery_url` đã ký không mở rộng quyền ra ngoài owner
  context đã authorize.
* Crop **không** bị xoá khi region chuyển `archived`. Revision cũ vẫn đọc được
  đầy đủ khi consumer nêu đích danh `processing_version`, đúng § 4.1 — nếu ảnh
  biến mất thì "đọc được mãi mãi" là câu nói sai.
* Crop là **quan sát**, không phải diễn giải: Media trả ảnh của vùng, không nói
  ảnh đó vẽ cái gì (ADR-0019 § D4). Mô tả nội dung ảnh thuộc consumer.

---

# 6. Mã lỗi

Đóng, có tên, không dùng mảng rỗng để biểu đạt lỗi:

| Mã | Nghĩa |
| --- | --- |
| `pending` | Output chưa bắt đầu xử lý |
| `processing` | Đang xử lý; thử lại sau |
| `failed` | Xử lý thất bại; sẽ không tự có kết quả |
| `unauthorized` | Actor không được authorize trên owner |
| `detached` | Usage đã detach; output còn nhưng không phục vụ qua owner này |
| `archived` | Bản này đã bị thay bởi revision mới |
| `missing` | Owner không có Media nào ở `content_type` yêu cầu |
| `ambiguous_source` | Owner có nhiều active Media trong đúng `usage_type`; service từ chối đoán source |
| `locale_unavailable` | Không có output ở locale yêu cầu |
| `language_profile_unavailable` | Không có revision nào ở `language_profile` được nêu |
| `ambiguous_profile` | Owner có nhiều current language profile; service từ chối gộp hoặc đoán profile |
| `unsupported_source` | MIME không nằm trong tập được hỗ trợ |
| `revision_unavailable` | `processing_version` được nêu không tồn tại trong owner context này |
| `revision_mismatch` | `source_fingerprint` không khớp revision đã nêu |
| `structure_unavailable` | Trang có canonical text nhưng không có cấu trúc trong revision này |

`archived` **vẫn đọc được** khi consumer nêu đích danh `processing_version`; quy
tắc và mã lỗi đầy đủ ở § 4.1. Không nêu thì mặc định là bản hiện hành và
`archived` không bao giờ được trả ra.

---

# 7. AI Knowledge Source

`ai_knowledge_sources` đăng ký theo derived content unit, không theo Media File,
và lưu `source_fingerprint` cùng `processing_version` của unit đã đọc.

Khi Media sinh revision mới, chunk và embedding dựng từ bản cũ trở thành
**stale** và phải rebuild. Media **không** tự động rebuild và không xoá gì của
AI: Media báo trạng thái, AI quyết định.

AI vẫn là consumer. Không có đường nào từ AI ghi ngược vào processing state.

---

# 8. Audit

Mỗi lần đọc thành công hoặc bị từ chối ghi một dòng `media_access_logs` với
`action = 'read_derived'`, `source_type` là consumer đã gọi, và metadata chứa
`decision = allowed|denied` cùng error code ổn định. Khi owner không resolve
được tới Media File trong tenant thì không thể ghi row vì audit schema bắt buộc
`media_file_id`; trường hợp đó vẫn fail-closed nhưng không invent một FK giả.
OCR và transcript có thể chứa dữ liệu cá nhân trong học liệu; ai đọc hoặc cố đọc
cái gì, lúc nào, phải trả lời được khi target tồn tại.

Audit và retention/deletion phải theo được toàn provenance chain: source, OCR
text/transcript, redacted derivative, AI-derived output/chunk/embedding và
crop/page/region asset. Redaction không sửa source gốc; derivative đã redact có
identity/version/provenance riêng. Duration, legal hold, purge orchestration và
audit sink cho owner không resolve vẫn là implementation gate, không phải lý do
để trả `failed` cho local OCR chỉ vì `PII_PRESENT`.

---

# Rủi ro đã ghi nhận

| # | Rủi ro |
| --- | --- |
| B1 | ADR-0018 đã approve boundary PII/redaction/external processing; retention duration, deletion synchronization và full provenance audit chưa có implementation evidence. Chặn production/real-tenant rollout, không biến `PII_PRESENT` thành OCR failure |
| B2 | Cue-level caption citation chưa có contract; nếu AI cần, phải review trước |
| B3 | `region`/`table` đã có runtime và đã persist trên tài liệu thật; độ phủ region trên trang scan vẫn không đảm bảo. Consumer phải tôn trọng `structure_unavailable` và fallback về canonical page text (§ 5.1) |
| B5 | Crop (§ 5.3) đã implemented cho `role = figure` trên PDF. Vùng ngoài tập đủ điều kiện luôn `crop = null`; consumer phải đọc theo bảng ở § 5.3 chứ không suy ra "revision không có crop" |
| B6 | Xoá crop khi Media/revision thất bại đã có runtime. Dọn rác mồ côi do **người** khởi động — nút trong Quản lý Media hoặc `media:purge-deleted-storage`. Owner quyết định 2026-08-28 (thay thế policy "mỗi giờ" cùng ngày): **không có tác vụ nào tự động xoá file**. Legal hold và purge orchestration vẫn thuộc gate ADR-0018 |
| B4 | Đã đóng ở contract v1.7: `usage_type` bắt buộc và nhiều active row trong cùng slot fail-closed bằng `ambiguous_source`. Runtime/test phải có evidence trước khi mở HTTP/API |

---

# Owner

Domain Owner (Media)

# Primary Consumers

* Developer
* Reviewer
* AI Agent
