# Mudnaan ERP — Postman Collection

Complete QA collection: **23 folders, 99 requests, 234 assertions**,
covering every API endpoint including error and permission cases.
Verified green end-to-end with Newman against the demo tenant.

## Files

| File | What it is |
|---|---|
| `MudnaanERP.postman_collection.json` | The collection (Postman v2.1 format) |
| `MudnaanERP-Local.postman_environment.json` | Local environment (base URL + demo credentials) |
| `generate_collection.py` | The generator — **edit this, never the JSON**, then re-run it |

## Import & run (Postman app)

1. Postman → **Import** → drop both `.json` files.
2. Select the **“Mudnaan ERP — Local”** environment (top-right picker).
3. Open **Authentication → Login (Owner)** and hit Send once.
   The test script stores the JWT in `{{token}}`; every other request
   sends `Authorization: Bearer {{token}}` automatically.
4. Explore any folder, or run everything:
   **Collection → Run** — folders are ordered so created resources feed
   later requests (category → product → customer/supplier →
   purchase/sale → AR/AP documents → accounting).

## Run headless (CI / terminal)

```bash
npx newman run MudnaanERP.postman_collection.json \
    -e MudnaanERP-Local.postman_environment.json
```

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `base_url` | `http://localhost/erp-backend/api/v1` | API root |
| `root_url` | `http://localhost/erp-backend` | App root (health check) |
| `identity` | `yasir@demo.com` | Owner login (all permissions, premium tenant) |
| `password` | `Secret123!` | Demo password |

Collection variables (`token`, `product_id`, `invoice_id`, …) are filled
automatically by test scripts as the run progresses.

## Notes & gotchas

- The demo tenant is **premium**, so premium routes return 200. On a
  basic-plan tenant the same routes return `403 PLAN_UPGRADE_REQUIRED`.
- **Financial periods** have unique, non-overlapping date ranges. The
  second full run gets `PERIOD_OVERLAP` on *Create period (April 2026)* —
  that's the API working correctly. Delete that period row (or change
  the dates in the request) to re-run clean.
- Each full run creates real documents (sales, invoices, bills …) in the
  demo database — they are history and are intentionally not deleted.
  The throwaway *product* is cleaned up at the end.
- `data` is a reserved global inside Postman test scripts (collection-
  runner data files). Never declare `const data` in a test script.
- Staff user management endpoints (create user, assign role) are not in
  the API yet; roles/permissions are seeded per tenant by
  `app/Database/TenantSchema/03_permissions_seed.sql`.
