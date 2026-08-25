# Table: ai_model_runs

Document Path: database/ai/ai_model_runs.md

## Amendment — Proposed 2026-08-25

Nguồn: [LF-AI-Foundation-Media-Consumer-Database-Architecture-Review](../../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md)
finding F-2 và F-5. **Chưa được Owner approve.** Thay đổi: thêm tenant composite
identity và ghi rõ hai khóa ngoại được hoãn. Amendment này **không** đụng tới
ADR-0006 Amendment v1.1 vẫn đang Proposed ở phần Business Rules bên dưới.

## Purpose

Audit/provenance record for every AI provider/model execution.

## Relationships

Model Run belongs to optional User/Assistant Session/Prompt Template and may
generate Messages, Recommendations or Insights.

## Business Rules

* Every provider call must create one tenant-scoped Model Run.
* Allowed `status`: `queued`, `running`, `completed`, `failed`, `blocked`,
  `cancelled`.
* Prompt template version/hash and provider/model are immutable run provenance.
* Token/cost values are measurements/estimates, not Billing Source of Truth.
* No API key, BYOK secret or raw credential.
* Sensitive payload storage is optional and governed by retention/privacy.
* Failed/blocked runs retained for audit according to policy.
* **Proposed, chưa có hiệu lực** (xem ADR-0006 Amendment Version 1.1 — cần
  Owner Approval và Learning Phase 4): khi Model Run dùng Learning Mastery
  Profile làm input, `metadata` phải ghi nhận Profile identity
  (`customer_id`, `user_id`, `node_definition_id`,
  `basis_framework_version_id`, `current_calculation_id`) và `projected_at`
  tại thời điểm đọc. Đây là điểm audit quan trọng nhất để chứng minh Model
  Run đã dùng đúng Profile projection nào.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| run_uuid | CHAR(36) NOT NULL | Stable run identity. |
| user_id | BIGINT UNSIGNED NULL | Initiating User. |
| assistant_session_id | BIGINT UNSIGNED NULL | Optional Assistant Session. |
| prompt_template_id | BIGINT UNSIGNED NULL | Prompt Template reference. |
| prompt_version | INT UNSIGNED NULL | Published prompt version snapshot. |
| prompt_hash | VARCHAR(128) NOT NULL | Effective prompt fingerprint. |
| purpose | VARCHAR(100) NOT NULL | Run purpose. |
| provider | VARCHAR(50) NOT NULL | Provider name. |
| model | VARCHAR(100) NOT NULL | Provider model. |
| correlation_id | CHAR(36) NULL | Cross-run/business-flow correlation. |
| status | VARCHAR(50) NOT NULL DEFAULT 'queued' | Run lifecycle. |
| input_tokens | INT UNSIGNED NOT NULL DEFAULT 0 | Input token usage. |
| output_tokens | INT UNSIGNED NOT NULL DEFAULT 0 | Output token usage. |
| total_tokens | INT UNSIGNED NOT NULL DEFAULT 0 | Total token usage. |
| estimated_cost | DECIMAL(14,6) NULL | Estimated provider cost. |
| currency | CHAR(3) NULL | ISO currency for estimate. |
| latency_ms | BIGINT UNSIGNED NULL | Provider latency. |
| started_at | TIMESTAMP NULL | Start time. |
| completed_at | TIMESTAMP NULL | Completion/failure time. |
| error_code | VARCHAR(100) NULL | Stable failure code. |
| safety_metadata | JSON NULL | Safety/moderation provenance. |
| metadata | JSON NULL | Request provenance without secrets. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, run_uuid);
UNIQUE (id, customer_id);
INDEX  (customer_id, user_id, created_at);
INDEX  (customer_id, assistant_session_id);
INDEX  (customer_id, prompt_template_id, prompt_version);
INDEX  (customer_id, provider, model);
INDEX  (customer_id, status, created_at);
INDEX  (customer_id, correlation_id);

FOREIGN KEY (user_id, customer_id)
    REFERENCES users (id, customer_id) RESTRICT;

CHECK (status IN ('queued','running','completed','failed','blocked',
                  'cancelled'));
CHECK (total_tokens >= input_tokens);
CHECK (status <> 'completed' OR completed_at IS NOT NULL);
CHECK (status <> 'failed' OR error_code IS NOT NULL);
```

`assistant_session_id` và `prompt_template_id` **chưa có khóa ngoại**:
`ai_assistant_sessions` và `ai_prompt_templates` nằm ngoài subset Media→AI và
chưa được implement. Hai khóa đó được thêm trong chính migration tạo ra bảng đích,
không phải bỏ quên. Cột vẫn nullable và vẫn được index để không phải sửa shape
sau này.

## Sample Data

`id=500, customer_id=1, run_uuid=0191-run-0500, user_id=100, assistant_session_id=200, prompt_template_id=900, prompt_version=1, prompt_hash=sha256:prompt1, purpose=tutor_answer, provider=openai, model=gpt-5, status=completed, input_tokens=950, output_tokens=180, total_tokens=1130, estimated_cost=0.012500, currency=USD, latency_ms=1800`

## Design Notes

Usage Domain may consume approved measurements. Provider pricing snapshot,
payload retention and retry-attempt modeling remain open.
