# Subscription & Billing Architecture (future master database)

> **Status: DESIGN ONLY.** Nothing in this document is implemented yet.
> It exists so today's code doesn't block tomorrow's subscription
> system, and so the eventual implementation is a fill-in, not a rewrite.

---

## 1. Where subscriptions live

Subscriptions belong to the **master database** (`erp_master`), not to
tenant databases. Tenants must never see billing internals, and billing
must survive a tenant database being restored from backup.

```
                 ┌──────────────────────────┐
                 │        erp_master         │   ← plans, subscriptions,
                 │ (one DB for the platform) │     billing history, tenants
                 └────────────┬──────────────┘
                              │  "which DB? which plan? which features?"
      ┌───────────────────────┼───────────────────────┐
      ▼                       ▼                       ▼
 erp_tenant_acme        erp_tenant_demo         erp_tenant_xyz
 (operational data)     (operational data)      (operational data)
```

## 2. The single swap point (already in place)

Today the tenant registry is a config map:

- `app/Config/Tenants.php` — code → `{name, database, plan}`
- `app/Libraries/TenantManager.php::find()` — the ONLY lookup
- `app/Filters/AuthFilter.php` — calls `find()` + `connect()` per request
- `app/Filters/PlanFilter.php` — gates routes on the resolved plan

**Contract:** `TenantManager::find(code)` returns
`{code, name, database, plan}`. When the master DB exists, `find()`
switches from the config array to a master-DB query (with a short cache).
Nothing else in the codebase changes — controllers, services and filters
already treat the returned `plan` as the authority (the JWT's plan claim
is informational only and re-checked every request, so a mid-session
upgrade/downgrade takes effect on the next request without re-login).

## 3. Master database schema (planned)

```sql
tenants
  id, code (unique), name, database_name, db_host,
  status ENUM('active','suspended','cancelled'),  -- suspended = login blocked
  business_type, created_at, ...

plans
  id, code ('basic','premium', later 'enterprise'), name,
  monthly_price, yearly_price, sort_order, is_active

plan_features                      -- feature access control
  plan_id, feature_code (e.g. 'accounting', 'multi_branch',
  'advanced_reports', 'api_access'), limit_value NULL  -- NULL = unlimited

subscriptions
  id, tenant_id, plan_id,
  status ENUM('trialing','active','past_due','cancelled','expired'),
  billing_cycle ENUM('monthly','yearly'),
  current_period_start, current_period_end,
  cancel_at_period_end TINYINT,
  trial_ends_at, created_at, updated_at

subscription_invoices               -- billing history
  id, subscription_id, invoice_no, amount, currency,
  period_start, period_end,
  status ENUM('draft','open','paid','void','uncollectible'),
  due_at, paid_at, created_at

subscription_payments
  id, subscription_invoice_id,
  provider ENUM('manual','stripe','sifalo','mobile_money', ...),
  provider_reference,               -- gateway transaction id
  amount, currency, status ENUM('pending','succeeded','failed','refunded'),
  raw_payload JSON,                 -- webhook body for audit
  created_at

subscription_events                 -- upgrade/downgrade audit trail
  id, subscription_id,
  event ENUM('created','upgraded','downgraded','renewed','cancelled',
             'suspended','reactivated','payment_failed'),
  from_plan_id NULL, to_plan_id NULL, actor, details JSON, created_at
```

## 4. How each future requirement maps

| Requirement | Design answer |
|---|---|
| Tenant subscription status tracking | `subscriptions.status` + `tenants.status`; AuthFilter denies login for `suspended` tenants (one added check in `find()`'s result) |
| Plan management | `plans` + `plan_features` rows — adding a plan is data, not code |
| Feature access control | `PlanFilter` today gates by plan name; step 2 is gating by **feature code** (`plan:accounting`) resolved through `plan_features`. Route groups stay identical |
| Billing history | `subscription_invoices` + `subscription_payments`, append-only |
| Upgrade / downgrade | Update `subscriptions.plan_id` + one `subscription_events` row. Takes effect next request because plan is re-resolved per request (already true today) |
| Payment providers | `subscription_payments.provider` + a `PaymentProviderInterface` (charge, webhook verify) per gateway; providers are plugins, the schema doesn't care |

## 5. Rules that keep today compatible with tomorrow

1. **Never** store plan/billing facts in tenant databases. The tenant DB
   is operational data only. (Nothing today violates this.)
2. **Never** trust the JWT's `plan` claim for authorization — always the
   registry's answer. (Already true: AuthFilter overwrites the context
   with the registry's plan on every request.)
3. Every new premium route goes inside the existing
   `['filter' => 'plan:premium']` group so a future feature-code filter
   can replace the plan filter in one place.
4. Tenant provisioning stays scripted (`app/Database/TenantSchema/*.sql`
   in order) so a future "sign-up → provision tenant" workflow just runs
   the same scripts.

## 6. Implementation order (when the time comes)

1. Create `erp_master` with the schema above; seed `plans` from the
   current hard-coded basic/premium pair.
2. Point `TenantManager::find()` at the master DB (keep the config map
   as a dev fallback).
3. Suspension check in AuthFilter (tenant status ≠ active → 403 TENANT_SUSPENDED).
4. Feature-code gating in PlanFilter.
5. Billing workflows (invoicing cron, payment webhooks, dunning) — pure
   additions in a new `Billing\` module of the master context.
