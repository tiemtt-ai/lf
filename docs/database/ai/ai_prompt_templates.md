# Table Name

`ai_prompt_templates`

## Purpose

Versioned, governed Prompt Templates for approved AI roles and purposes.

## Relationships

Prompt Template may be global (`customer_id NULL`) or tenant-owned; referenced
by Assistant Sessions and Model Runs; optional self replacement link.

## Business Rules

* `code` lowercase `snake_case` and immutable after first publish.
* `code + version` unique within normalized global/tenant scope.
* Published Prompt Template immutable; change creates new version.
* Allowed `status`: `draft`, `published`, `deprecated`, `archived`.
* Deprecated prompt may point to `replaced_by_prompt_template_id`.
* Allowed role/purpose and input/output schemas must be explicit.
* Prompt cannot contain API key, BYOK secret, tenant credential or forbidden
  business-action instruction.
* Global prompts are system-managed; tenant override policy requires approval.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NULL | Tenant owner; NULL for global prompt. |
| code | VARCHAR(100) NOT NULL | Stable prompt family code. |
| version | INT UNSIGNED NOT NULL | Immutable published version. |
| name | VARCHAR(255) NOT NULL | Display name. |
| assistant_role | VARCHAR(50) NOT NULL | Allowed AI role. |
| purpose | VARCHAR(100) NOT NULL | Prompt purpose. |
| system_prompt | LONGTEXT NOT NULL | Governed prompt body. |
| input_schema | JSON NULL | Expected input contract. |
| output_schema | JSON NULL | Expected output contract. |
| default_model | VARCHAR(100) NULL | Optional preferred model. |
| temperature | DECIMAL(4,3) NULL | Optional default generation setting. |
| status | VARCHAR(50) NOT NULL DEFAULT 'draft' | Prompt lifecycle. |
| is_system | BOOLEAN NOT NULL DEFAULT false | Global/system-managed flag. |
| published_at | TIMESTAMP NULL | Publish time. |
| deprecated_at | TIMESTAMP NULL | Deprecation time. |
| replaced_by_prompt_template_id | BIGINT UNSIGNED NULL | Replacement prompt version. |
| created_by | BIGINT UNSIGNED NULL | Author User/system actor. |
| updated_by | BIGINT UNSIGNED NULL | Last draft editor. |
| metadata | JSON NULL | Approval/model constraints without secrets. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Draft/lifecycle update time. |

## Indexes

```sql
UNIQUE (COALESCE(customer_id, 0), code, version);
INDEX (customer_id, code, status);
INDEX (customer_id, assistant_role, purpose);
INDEX (customer_id, replaced_by_prompt_template_id);
INDEX (status, published_at);
```

## Sample Data

`id=900, customer_id=NULL, code=tutor_explain_concept, version=1, name=Tutor Explain Concept, assistant_role=tutor, purpose=explain_concept, system_prompt=Explain using authorized context and cite sources..., status=published, is_system=true, published_at=2026-06-28T00:00:00Z`

## Design Notes

Approval roles, tenant overrides, rollback and prompt evaluation gates remain
open. Reserved scope `0` is an index normalization concept, not a tenant.
