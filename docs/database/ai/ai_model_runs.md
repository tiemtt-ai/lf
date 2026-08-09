# Table: ai_model_runs

Document Path: database/ai/ai_model_runs.md

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
INDEX (customer_id, user_id, created_at);
INDEX (customer_id, assistant_session_id);
INDEX (customer_id, prompt_template_id, prompt_version);
INDEX (customer_id, provider, model);
INDEX (customer_id, status, created_at);
INDEX (customer_id, correlation_id);
```

## Sample Data

`id=500, customer_id=1, run_uuid=0191-run-0500, user_id=100, assistant_session_id=200, prompt_template_id=900, prompt_version=1, prompt_hash=sha256:prompt1, purpose=tutor_answer, provider=openai, model=gpt-5, status=completed, input_tokens=950, output_tokens=180, total_tokens=1130, estimated_cost=0.012500, currency=USD, latency_ms=1800`

## Design Notes

Usage Domain may consume approved measurements. Provider pricing snapshot,
payload retention and retry-attempt modeling remain open.
