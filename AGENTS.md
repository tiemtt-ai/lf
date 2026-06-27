# AGENTS.md

Before modifying LearnForge code:

1. Read docs/README.md
2. Read docs/LF-INDEX.md
3. Read docs/governance/LF-Architecture-Guardrails.md
4. Follow the Documentation Routing Guide in LF-INDEX.md
5. Load only the documents relevant to the current task
6. For major refactors or changes involving auth, tenant, navigation, UI, i18n,
   permissions, routes, or middleware, also read
   docs/quality/LF-Regression-Audit.md

Governance rules:

- LF-Architecture-Guardrails.md has higher priority than module documentation
  when they conflict.
- If a proposed change risks violating the Guardrails, stop and report the
  risk before writing code.

LearnForge is a multi-tenant AI-native LMS SaaS.

Preserve:

- tenant isolation
- customer_id ownership
- LF-OS principles
- LF architecture standards

## Architecture Workflow

```text
Governance

↓

ADR

↓

Domain

↓

Database

↓

Review

↓

Freeze

↓

Migration

↓

Implementation

↓

Testing
```

## Database Rule

Không tạo migration trước khi:

- Database Docs approved
- ADR approved nếu thay đổi là Foundation
- Architecture Review passed

## Documentation Rule

Không tạo:

- report
- review
- temporary analysis

trong:

- `docs/governance`
- `docs/database`
- `docs/core`
- `docs/platform`
- `docs/saas`

Review artifacts phải nằm trong:

```text
docs/quality
```

hoặc working directory.
