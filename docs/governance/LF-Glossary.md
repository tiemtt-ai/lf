# LearnForge Glossary

Version: 1.0

Status: Official Governance

Last Updated: 2026-07

---

# Purpose

Tài liệu này là Single Source of Truth cho thuật ngữ của LearnForge.

Một thuật ngữ chỉ có một định nghĩa canonical. Tài liệu Domain, ADR và
implementation phải sử dụng thuật ngữ theo định nghĩa tại đây. Glossary không
tạo thêm Domain ownership; ownership luôn phải phù hợp với ADR và
[LF-Domain-Map](LF-Domain-Map.md).

---

# General Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Domain | Ranh giới nghiệp vụ hoặc năng lực có dữ liệu, business rules và trách nhiệm được xác định rõ. | Governance | Domain Map và ADR của Domain | ADR của từng Domain |
| Module | Nhóm implementation và tài liệu phục vụ một năng lực; Module không tự có business ownership nếu chưa được xác lập là Domain. | Engineering | Documentation Index và module documentation | N/A |
| Platform | Toàn bộ môi trường LearnForge cung cấp các Business, Platform, SaaS, Intelligence và Infrastructure Domain. | Governance | Architecture Principles và Domain Map | N/A |
| Business Domain | Domain sở hữu business state và quyết định nghiệp vụ cốt lõi của một lĩnh vực. | Governance | Domain Map | ADR của từng Business Domain |
| Platform Domain | Domain cung cấp năng lực dùng chung nhưng không sở hữu business state của Domain tiêu thụ. | Governance | Domain Map | ADR của từng Platform Domain |
| Infrastructure Domain | Năng lực kỹ thuật dùng chung hỗ trợ vận hành hệ thống và không quyết định business state. | Platform Engineering | Domain Map | N/A |
| Source Of Truth | Domain hoặc dữ liệu canonical có thẩm quyền cuối cùng đối với một business state. | Governance | Architecture Principles và Domain Map | ADR của Domain sở hữu |
| Evidence | Dữ liệu do một Domain phát sinh để Domain khác tham khảo; Evidence không trực tiếp thay đổi business state của Domain nhận. | Domain phát sinh | Dữ liệu canonical của Domain phát sinh | ADR của Domain phát sinh |
| Snapshot | Bản sao bất biến của dữ liệu cần giữ nguyên ngữ cảnh lịch sử tại thời điểm chốt. | Domain tạo Snapshot | Snapshot record của Domain đó | ADR của Domain đó |
| Version | Một revision có danh tính riêng; khi đã Published thì bất biến và thay đổi tiếp theo phải tạo Version mới. | Domain tạo Version | Version record của Domain đó | ADR của Domain đó |
| Immutable | Trạng thái không được sửa sau mốc chốt; thay đổi được biểu diễn bằng record, Version hoặc Asset mới. | Governance | Architecture Principles | ADR liên quan |
| Runtime | Ngữ cảnh thực thi đang hoạt động dựa trên cấu hình hoặc Version đã được chấp thuận; Runtime không thay đổi nguồn Published. | Domain vận hành Runtime | Runtime record của Domain đó | ADR của Domain đó |
| Read Model | Dữ liệu dẫn xuất được tối ưu cho truy vấn, báo cáo hoặc hiển thị; có thể rebuild và không phải Source Of Truth. | Domain tạo Read Model | Source records dùng để dựng Read Model | ADR của Domain đó |

---

# Course Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Template | Bản authoring có thể chỉnh sửa, mô tả cấu trúc Course trước khi publish. | Course | Course Template | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) |
| Template Version | Revision bất biến của Template sau khi publish, dùng làm learning structure lịch sử. | Course | Course Template Version | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) |
| Version Activity | Đơn vị hoạt động học tập thuộc một Template Version và là điểm liên kết canonical cho trải nghiệm học. | Course | Course Version Activity | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) |
| Product | Offering thương mại chỉ định Course Version được bán hoặc cấp quyền truy cập. | Course | Course Product | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) |
| Enrollment | Quan hệ tenant-scoped cấp cho learner quyền và ngữ cảnh tham gia một Product hoặc Course Version. | Course | Course Enrollment | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) |
| Cohort | Nhóm Enrollment dùng chung lịch trình hoặc ngữ cảnh triển khai học tập. | Course | Course Cohort và Cohort Membership | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) |
| Completion | Quyết định canonical của Course rằng learner đã đáp ứng điều kiện hoàn thành. | Course | Course Completion state | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) |
| Progress | Trạng thái canonical phản ánh mức tiến triển của learner đối với Course hoặc Version Activity. | Course | Course Activity Progress | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) |

---

# LiveClass Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Room | Cấu hình không gian live có thể tái sử dụng và liên kết với Version Activity. | LiveClass | LiveClass Room | [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) |
| Session | Một lần diễn ra cụ thể, có lịch và lifecycle riêng, bên trong Room. | LiveClass | LiveClass Session | [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) |
| Attendance | Bằng chứng tham dự của một learner trong một Session; không tự quyết định Course Completion. | LiveClass | LiveClass Attendance | [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) |
| Replay | Bằng chứng learner truy cập hoặc xem lại nội dung của Session; không tự quyết định Course Completion. | LiveClass | LiveClass Replay | [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) |
| Recording | Reference vận hành từ Session đến Media File chứa bản ghi; binary và delivery thuộc Media. | LiveClass | LiveClass Recording reference; Media File cho asset | [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md), [ADR-0004](../adr/ADR-0004-Media-Foundation.md) |

---

# Assessment Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Question Bank | Không gian authoring và quản trị tập Question có thể tái sử dụng. | Assessment | Assessment Question Bank | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Question | Nội dung đánh giá có thể author trong Bank và được snapshot khi đưa vào Assessment đã publish. | Assessment | Assessment Question; Question Snapshot cho lịch sử | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Quiz | Cấu hình Assessment có version hoặc snapshot xác định, được learner thực hiện trong một Attempt. | Assessment | Assessment Quiz và published snapshot | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Attempt | Một lần learner thực hiện Quiz trong ngữ cảnh evaluation đã được cố định. | Assessment | Assessment Attempt | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Answer | Câu trả lời của learner cho Question trong một Attempt. | Assessment | Assessment Answer | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Grading | Quá trình áp dụng rules hoặc đánh giá thủ công để tạo Score và Evaluation Evidence. | Assessment | Assessment grading records | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Rubric | Bộ tiêu chí chấm điểm; bản dùng để grading phải được snapshot để bảo toàn lịch sử. | Assessment | Assessment Rubric; Rubric Snapshot cho lịch sử | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Evaluation Evidence | Kết quả đánh giá như Score hoặc Pass/Fail do Assessment phát sinh để Domain khác tự ra quyết định. | Assessment | Assessment Result | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Topic | Nhãn phân loại Question theo chủ đề nội dung, hỗ trợ tổ chức Question Bank. | Assessment | Assessment Question Topic | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Exam | Quiz dùng policy nghiêm ngặt hơn (thời lượng, số lần làm) cho đánh giá chính thức; vẫn là một dạng Quiz, không phải entity riêng. | Assessment | Assessment Quiz | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |
| Assignment | Nhiệm vụ đánh giá được giao và nộp bài, được xử lý qua Grading Assignment. | Assessment | Assessment Grading Assignment | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) |

---

# Media Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Media File | Digital Asset canonical có Content Identity, metadata và storage reference; binary đã lưu là bất biến. | Media | Media File | [ADR-0004](../adr/ADR-0004-Media-Foundation.md) |
| Variant | Derived Asset được tạo từ Media File gốc, có thể regenerate và không thay thế Original Asset. | Media | Media Variant | [ADR-0004](../adr/ADR-0004-Media-Foundation.md) |
| Transcript | Nội dung văn bản theo thời gian được dẫn xuất từ audio hoặc video. | Media | Media Transcript | [ADR-0004](../adr/ADR-0004-Media-Foundation.md) |
| Caption | Nội dung chữ có timing dùng để hiển thị đồng bộ trong quá trình phát Media. | Media | Media Caption | [ADR-0004](../adr/ADR-0004-Media-Foundation.md) |
| Processing Job | Đơn vị công việc theo dõi lifecycle xử lý Media như transcode, thumbnail hoặc transcript. | Media | Media Processing Job | [ADR-0004](../adr/ADR-0004-Media-Foundation.md) |
| Usage Mapping | Quan hệ generic ghi nhận một Media File đang được Domain consumer tham chiếu ở đâu; không chuyển ownership của asset. | Media | Media Usage | [ADR-0004](../adr/ADR-0004-Media-Foundation.md) |

---

# AI Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| AI-ready Feature | Derived behavior feature do Track chuẩn hóa để AI tiêu thụ; không phải AI output hoặc consumer business state. | Track | `track_ai_features` | [ADR-0005](../adr/ADR-0005-Track-Foundation.md) |
| Knowledge Source | Registration của một approved source mà AI được phép chunk/retrieve; nội dung gốc vẫn thuộc Owner Domain. | AI | AI Knowledge Source registration | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) |
| Knowledge Chunk | Derived text segment phục vụ retrieval/RAG và có thể rebuild từ Knowledge Source. | AI | AI Knowledge Chunk | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) |
| Embedding | Derived vector representation/reference của Knowledge Chunk cho semantic retrieval. | AI | AI Embedding metadata/reference | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) |
| Conversation | AI-owned interaction container giữa User và một assistant role; không phải business decision. | AI | AI Conversation and Messages | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) |
| Assistant Session | Runtime orchestration context cho một AI role; không phải login hoặc Track Learning Session. | AI | AI Assistant Session | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) |
| Model Run | Audit/provenance record của một provider/model execution. | AI | AI Model Run | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) |
| Prompt Template | Versioned, governed prompt contract cho một AI role/purpose. | AI | AI Prompt Template | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) |
| Recommendation | Đề xuất do AI tạo để hỗ trợ User hoặc Owner Domain quyết định; không tự thực thi business action. | AI | AI Recommendation | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) |
| Insight | Explainable AI observation/analysis; không thay Source Of Truth của Domain đầu vào. | AI | AI Insight | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) |

---

# SaaS Tenant Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Customer | Tenant identity gốc; tổ chức mua và vận hành LearnForge dưới một ranh giới cô lập riêng. Customer không phải Student/learner. | Tenant | `saas_customers` | [ADR-0007](../adr/ADR-0007-SaaS-Tenant-Foundation.md) |
| Membership | Quan hệ tenant-scoped giữa User và Customer, xác nhận User thuộc Customer với một official role. | Tenant | `saas_customer_members` | [ADR-0007](../adr/ADR-0007-SaaS-Tenant-Foundation.md) |
| Invitation | Lời mời tham gia Customer, lưu token hash và thời hạn; chỉ tạo Membership sau khi được accept. | Tenant | `saas_customer_invitations` | [ADR-0007](../adr/ADR-0007-SaaS-Tenant-Foundation.md) |
| Domain Mapping | Ánh xạ subdomain hoặc custom domain của request tới một Customer; là canonical routing registry. | Tenant | `saas_customer_domains` | [ADR-0007](../adr/ADR-0007-SaaS-Tenant-Foundation.md) |

---

# SaaS Commercial Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Plan | Global commercial package describing an offering classification; a Plan does not identify a Customer, Usage, price calculation, Invoice or Payment. | Commercial | `saas_plans` | [ADR-0008](../adr/ADR-0008-SaaS-Commercial-Foundation.md) |
| Plan Feature | Default feature allowance configured for a Plan; it is not current Usage or the final effective Customer right. | Commercial | `saas_plan_features` | [ADR-0008](../adr/ADR-0008-SaaS-Commercial-Foundation.md) |
| Subscription | Tenant-scoped lifecycle record connecting a Customer to a Plan. | Commercial | `saas_subscriptions` | [ADR-0008](../adr/ADR-0008-SaaS-Commercial-Foundation.md) |
| Subscription Item | Add-on or package component attached to a Subscription and used as an input to Entitlement resolution. | Commercial | `saas_subscription_items` | [ADR-0008](../adr/ADR-0008-SaaS-Commercial-Foundation.md) |
| Entitlement | Effective tenant-scoped right answering whether and to what limit a Customer can use a feature; it does not record current Usage. | Commercial | `saas_entitlements` | [ADR-0008](../adr/ADR-0008-SaaS-Commercial-Foundation.md) |

---

# SaaS Usage Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Usage Event | Append-only tenant-scoped measurement of consumed platform resources; it does not replace Track Event, AI Model Run or source business state. | Usage | `saas_usage_events` | [ADR-0009](../adr/ADR-0009-SaaS-Usage-Foundation.md) |
| Usage Counter | Rebuildable projection of accumulated Usage by Customer, feature, period and unit. | Usage | Usage Events | [ADR-0009](../adr/ADR-0009-SaaS-Usage-Foundation.md) |
| Usage Summary | Versioned reporting/Billing read model projected from Usage Events; it is not Invoice or Payment state. | Usage | Usage Events | [ADR-0009](../adr/ADR-0009-SaaS-Usage-Foundation.md) |
| Quota Consumption | Measured quantity already consumed; allowed quota/limit remains a Commercial Entitlement. | Usage | Usage Events and derived Counters | [ADR-0009](../adr/ADR-0009-SaaS-Usage-Foundation.md) |

---

# SaaS Billing Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Invoice | Official tenant-scoped document representing a Customer payment obligation; it does not decide Entitlement. | Billing | `saas_invoices` | [ADR-0010](../adr/ADR-0010-SaaS-Billing-Foundation.md) |
| Invoice Item | Immutable issued-Invoice line snapshot of description, quantity and price; source references do not make it Usage or Commercial state. | Billing | `saas_invoice_items` | [ADR-0010](../adr/ADR-0010-SaaS-Billing-Foundation.md) |
| Payment | Tenant-scoped transaction and provider-reconciliation state applied toward an Invoice. | Billing | `saas_payments` | [ADR-0010](../adr/ADR-0010-SaaS-Billing-Foundation.md) |
| Payment Method | Safe provider reference for a Customer payment instrument; it never stores full payment credentials. | Billing | `saas_payment_methods` | [ADR-0010](../adr/ADR-0010-SaaS-Billing-Foundation.md) |
| Credit Note | Independent official document adjusting or refunding an Invoice without rewriting the original Invoice. | Billing | `saas_credit_notes` | [ADR-0010](../adr/ADR-0010-SaaS-Billing-Foundation.md) |
| Refund | Billing outcome represented through a Credit Note and optional Payment reference; it is not Usage correction or Entitlement state. | Billing | Credit Note and Payment reconciliation | [ADR-0010](../adr/ADR-0010-SaaS-Billing-Foundation.md) |

---

# Certificate Terms

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Certificate Template | Tenant-scoped layout, branding, content and rendering configuration used to issue a Certificate. | Certificate | `core_certificate_templates` | [ADR-0011](../adr/ADR-0011-Certificate-Foundation.md) |
| Certificate Template Product Mapping | Product and published Template Version binding containing Certificate eligibility, issuance and validity rules. | Certificate | `core_certificate_template_products` | [ADR-0011](../adr/ADR-0011-Certificate-Foundation.md) |
| Issued Certificate | Historical Business Evidence containing credential identity, source lineage, lifecycle and issuance-time snapshots. | Certificate | `core_certificate_issued_certificates` | [ADR-0011](../adr/ADR-0011-Certificate-Foundation.md) |
| Certificate Verification | Tenant-scoped evaluation of an Issued Certificate verification code, status and validity. | Certificate | Issued Certificate; Verification Log records audit result | [ADR-0011](../adr/ADR-0011-Certificate-Foundation.md) |
| Certificate Audit Evidence | Append-only verification and download/activity history that does not change Issued Certificate state. | Certificate | Verification and Download Logs | [ADR-0011](../adr/ADR-0011-Certificate-Foundation.md) |

---

# Future Terms

Các thuật ngữ trong phần này là định hướng. Ownership hoặc Source of Truth ghi
`TBD` phải được xác nhận bằng ADR trước khi implementation.

| Term | Definition | Owner Domain | Source of Truth | Related ADR |
| --- | --- | --- | --- | --- |
| Track Event | Sự kiện append-only mô tả hành vi hoặc tín hiệu học tập thô từ các Domain nguồn; không phải business state của Domain nguồn. | Track | Track Event observation history | [ADR-0005](../adr/ADR-0005-Track-Foundation.md) |
| Competency | Năng lực hoặc kỹ năng có thể được định nghĩa, liên kết và đánh giá trong learning architecture tương lai. | TBD | TBD | Planned |
| Learning Path | Lộ trình có thứ tự hoặc điều kiện kết hợp nhiều learning experiences để đạt mục tiêu. | TBD | TBD | Planned |

---

# Governance

Khi một thuật ngữ mới có ảnh hưởng tới Domain boundary, ownership hoặc Source
of Truth:

```text
Propose Definition

↓

Confirm Owner Domain

↓

Create or Update ADR

↓

Update Glossary
```

Không dùng nhiều tên cho cùng một khái niệm, và không dùng cùng một tên cho
nhiều khái niệm khác nhau.
