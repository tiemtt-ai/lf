# LearnForge SaaS Usage Foundation Review

Version: 0.1

Status: Ready for Owner Review

Last Updated: 2026-06

---

# Purpose

Review artifact cho SaaS Usage Foundation trước P1 Update, ADR-0009 và
Foundation Freeze.

Canonical Domain document:
[LF-SaaS-Usage](../saas/LF-SaaS-Usage.md).

---

# Tables

| Table | Responsibility | Review Status |
| --- | --- | --- |
| [saas_usage_events](../database/saas-usage/saas_usage_events.md) | Append-only Usage measurement Source Of Truth | Ready |
| [saas_usage_counters](../database/saas-usage/saas_usage_counters.md) | Rebuildable accumulated Usage projection | Ready |
| [saas_usage_summaries](../database/saas-usage/saas_usage_summaries.md) | Versioned reporting/Billing read model | Ready |

Table count: 3.

No migration or Laravel implementation has been created.

---

# Architecture Summary

```text
Approved Measurement Source

↓

Usage Event

↓

Counter

↓

Summary
```

Only Usage Event is Source Of Truth. Counter and Summary are derived and can
rebuild.

---

# Ownership Boundary Confirmation

```text
Commercial

↓

Can Use?

Usage

↓

Used.

Billing

↓

Pay.
```

Review result: no ownership conflict.

* Commercial owns Plan, Subscription and Entitlement.
* Usage owns measurement and Usage projections.
* Billing owns pricing, Invoice and Payment.
* No Domain updates another Domain's Source Of Truth directly.

---

# Relationship With Commercial

Usage reads Customer Subscription/Entitlement context when used-versus-allowed
comparison is required.

Usage does not:

* Activate/cancel Subscription.
* Create/update Entitlement.
* Store allowed limit as Usage Source Of Truth.
* Decide “Can Use?”.

---

# Relationship With Billing

Billing is a read-only consumer of approved Usage Summary/measurement
contracts.

Usage does not calculate price, create Invoice or record Payment. Billing does
not update Usage Event, Counter or Summary.

---

# Relationship With Track

```text
Track Event ≠ Usage Event
```

Track owns learning behavior observations. Usage owns resource-consumption
measurements. A source action may emit both under explicit independent
contracts; neither record replaces the other.

---

# Relationship With AI

```text
AI Model Run ≠ Usage Event
```

AI owns execution provenance. Usage may create request/token measurements with
a Model Run source reference but does not update or replace Model Run.

Estimated cost in AI provenance is not Usage or Billing Source Of Truth.

---

# Applied Principles

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

* Domain Responsibility Principle — applied.
* Source Of Truth Principle — applied.
* Evidence Principle — applied.
* Generic Reference Principle — applied to source provenance.
* Tenant Isolation Principle — applied to all three tables.
* Read Model Principle — applied to Counter and Summary.
* Append Only Principle — applied to Usage Event.
* Backward Compatibility Principle — applied.
* Simplicity Principle — applied with three Foundation tables.
* ADR Principle — ADR-0009 required after approval.

---

# Open Questions

1. What immutable idempotency contract prevents duplicate Usage ingestion?
2. How are correction/reversal measurements represented?
3. Who governs metric taxonomy, units and semantic compatibility?
4. Which timezone and format define `period_key`?
5. What late-arriving-event window triggers projection rebuild?
6. How are Counter concurrency and rebuild consistency enforced?
7. How is Summary JSON schema tied to `projection_version`?
8. What cutoff/finalization policy applies to Billing-period summaries?
9. What retention, privacy and partitioning policy applies at high volume?

These are owner/P1 decisions. They do not permit Usage to take ownership of
Commercial, Billing, Track or AI state.

---

# Final Conclusion

The three-table design preserves:

* Append-only Usage measurement.
* Rebuildable counters and summaries.
* Tenant isolation.
* Commercial/Usage/Billing ownership separation.
* Track Event and AI Model Run independence.

```text
SaaS Usage Foundation

Status

Foundation In Design

Ready for owner review

YES
```
