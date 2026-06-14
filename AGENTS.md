# AGENTS.md

Before modifying LearnForge code:

1. Read docs/LF-INDEX.md
2. Read docs/governance/LF-Architecture-Guardrails.md
3. Follow the Documentation Routing Guide in LF-INDEX.md
4. Load only the documents relevant to the current task
5. For major refactors or changes involving auth, tenant, navigation, UI, i18n,
   permissions, routes, or middleware, also read
   docs/governance/LF-Regression-Audit.md

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
