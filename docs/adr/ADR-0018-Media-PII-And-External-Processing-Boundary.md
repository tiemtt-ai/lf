# ADR-0018 — Media PII And External Processing Boundary

Version: 1.0

Status: Approved

Document Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-08-25

Proposal Date: 2026-08-25

Approval Date: 2026-08-25

Approved By: LearnForge Architecture Owner

Document Path: adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md

Related ADRs:

* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](ADR-0006-AI-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](ADR-0017-AI-Assisted-Learning-Authoring.md)

---

# Context

Tài liệu thật trong LMS có thể chứa tên, thông tin liên hệ, hình ảnh nhận diện
hoặc dữ liệu cá nhân khác. Coi sự hiện diện của PII là lỗi xử lý tuyệt đối làm
OCR deterministic không dùng được cho chính học liệu mà tenant được phép quản
lý. Ngược lại, cho phép OCR local không thể bị suy diễn thành quyền gửi cùng dữ
liệu ra provider bên ngoài tenant boundary.

Foundation hiện có tenant isolation, owner-context authorization, processing
provenance và access audit, nhưng chưa có một quyết định thống nhất tách ba khái
niệm sau:

1. source có PII;
2. derivative đã redact;
3. eligibility cho external processing.

ADR này đề xuất boundary đó. Nó không cho phép provider mới, không chọn retention
duration cụ thể và không thay đổi schema hay runtime.

# Decision

## PII classification không phải processing failure

`PII_PRESENT` là classification, không phải processing outcome. Sự hiện diện của
PII tự nó không được chuyển Media File hoặc job sang `failed`/`cancelled`, không
từ chối enqueue và không làm OCR deterministic/local mất eligibility.

OCR deterministic/local được phép xử lý source có PII khi:

* actor đã được authorize trên owner context;
* source và output giữ nguyên `customer_id`/tenant ownership;
* provider chạy trong boundary local/self-hosted đã được phê duyệt;
* access audit, retention và deletion policy áp dụng cho source cùng output.

PII classification không nới MIME, locale, page, character, timeout hoặc resource
limit. Source vượt một limit vẫn nhận error code tương ứng, độc lập với PII.

## Source và redacted derivative

Không pipeline nào được tự redact hoặc sửa source gốc. Redaction chỉ được tạo
một derivative riêng với:

* fingerprint riêng cho bytes của derivative;
* processing version và provenance riêng;
* reference tới source/procedure đã tạo derivative;
* authorization, retention, deletion và audit riêng.

Source gốc giữ nguyên. Derived OCR text từ source gốc không được gắn nhãn
`redacted` chỉ vì consumer không hiển thị một phần nội dung.

## External-processing eligibility

Cho phép OCR local không cấp quyền external processing. Mọi call đưa source,
crop, OCR text, transcript hoặc context dẫn xuất ra ngoài tenant boundary phải
có policy và approval tường minh cho provider, purpose, data classes, tenant,
region/storage, retention/deletion, audit/provenance và credential boundary.

Boundary này áp dụng cho Docling cloud nếu có, Bedrock, Textract, OpenAI,
Claude, Gemini, OpenRouter, vision service và mọi provider bên ngoài. ADR này
không approve bất kỳ provider nào trong số đó; cũng không approve Docker,
Docling runtime hay vision analysis.

Nếu external-processing approval còn thiếu, external workflow phải fail-closed
hoặc trả `DECISION_REQUIRED`. Local deterministic processing đã eligible không
bị chặn theo đó.

## Read authorization và AI consumer

Media Read tiếp tục authorize bằng tenant và owner context. AI consumer không có
quyền mới vì output chứa PII, vì output đã được OCR thành công, hay vì actor có
quyền đọc một output khác của cùng Media File. Mọi consumer phải đi qua Media
Read boundary và để lại decision audit theo contract.

## Retention, deletion và audit scope

Policy retention/deletion và audit/access phải bao phủ theo provenance chain:

* source Media File;
* OCR/extracted text và transcript;
* redacted derivative;
* AI-derived output/chunk/embedding;
* crop/page/region asset;
* external provider request/output khi được approve.

Retention duration, legal hold, purge orchestration và physical audit sink là
decision triển khai riêng. Cho tới khi chúng được chốt, việc mở production/real
tenant vẫn gated; gate này không biến `PII_PRESENT` thành processing failure.

## Benchmark corpus

Corpus offline có PII được phép làm candidate khi manifest/evidence ghi đủ:

* Owner approval và nguồn/quyền sử dụng;
* local storage scope và access restriction;
* cấm upload, remote service và external provider call;
* retention/deletion date;
* tài liệu/source hash và approval evidence.

Thiếu approval hoặc workflow cần external processing chưa được phép mới là
`DECISION_REQUIRED`. `contains_pii: true` tự nó không phải verdict. Nếu không đáp
ứng các điều kiện trên, corpus phải dùng redacted derivative hoặc nguồn không PII.

# Consequences

* LF có thể xử lý học liệu thật bằng OCR local mà không hạ tenant/owner security.
* PII presence, redaction state và external eligibility không còn bị trộn thành
  một boolean policy.
* Production activation vẫn bị chặn cho tới khi retention/deletion và audit
  coverage có evidence triển khai.
* Mọi provider ngoài boundary cần quyết định riêng; ADR này không phải blanket
  consent.
* Không có schema, migration, provider binding, queue hoặc deployment change từ
  ADR proposal này.

# Alternatives Rejected

1. **Fail mọi source có PII:** không phù hợp với học liệu thật và không cần thiết
   cho OCR local đã tenant-isolated.
2. **Tự redact source trước OCR:** phá source integrity và citation provenance.
3. **Cho external AI dùng vì local OCR đã được phép:** nhập nhằng trust boundary
   và bypass approval theo provider/purpose.
4. **Một boolean `contains_pii` quyết định mọi thứ:** không biểu đạt được
   authorization, derivative state, provider eligibility hay retention.

# Owner Approval

Architecture Owner đã approve policy này ngày 2026-08-25:

```text
Role: LearnForge Architecture Owner
Date: 2026-08-25
Decision: Approved
```

Owner approval của ADR không tự đóng các gate triển khai retention/deletion,
external-provider approval, audit sink hoặc production deployment.

---

## Owner

Architecture Team

## Primary Consumers

* Domain Owner (Media)
* Security/Privacy Owner
* Developer
* Reviewer
* AI Agent
