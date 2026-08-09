# Table: saas_customer_settings

Document Path: database/saas/saas_customer_settings.md

## Purpose

Grouped key-value Tenant configuration.

## Relationships

`Customer 1 → N Customer Settings`.

## Business Rules

* Every setting belongs to one Customer.
* `setting_key` is stable lowercase `snake_case` within group.
* Settings are configuration, not learning/commercial business state.
* Allowed `value_type`: `string`, `integer`, `decimal`, `boolean`, `json`,
  `date`, `datetime`.
* `is_public` controls safe client exposure, not authorization.
* Sensitive values require approved encryption/access/rotation policy.
* Raw API key, password or BYOK secret must not be stored as plain setting.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| setting_group | VARCHAR(100) NOT NULL | Configuration namespace. |
| setting_key | VARCHAR(100) NOT NULL | Stable setting key. |
| setting_value | LONGTEXT NULL | Serialized value by `value_type`. |
| value_type | VARCHAR(30) NOT NULL DEFAULT 'string' | Value interpretation. |
| is_public | BOOLEAN NOT NULL DEFAULT false | Safe public exposure flag. |
| metadata | JSON NULL | Validation/source metadata. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Last configuration update. |

## Indexes

```sql
INDEX (customer_id);
UNIQUE (customer_id, setting_group, setting_key);
```

## Sample Data

`id=10, customer_id=1, setting_group=localization, setting_key=timezone, setting_value=Asia/Ho_Chi_Minh, value_type=string, is_public=true`

## Design Notes

Typed validation and defaults belong to an approved setting registry/contract,
not arbitrary metadata. Secret vault design remains outside this table.
