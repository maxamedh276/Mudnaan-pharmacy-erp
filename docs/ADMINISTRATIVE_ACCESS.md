# Superadmin and Admin boundaries

Superadmin has full access, including Operational Settings. Admin retains full ordinary
business access, appearance/printer settings, staff and custom-role management, but cannot
access Operational Settings or manage a Superadmin account.

The protection is based on the **acting user's type**, not account ownership. A Superadmin
may edit another Superadmin's profile, reset their password, activate/deactivate them, or
change their assigned role. Admins and all lower types cannot perform those actions on any
Superadmin, even if their permission rows contain every permission. Only a Superadmin can
create or promote another account to Superadmin.

Existing safeguards keep at least one active Superadmin and prevent self-deactivation.
Superadmin/Admin are fixed roles: their universal access cannot be removed through role
or menu editors. Custom-role editing remains available to Admin. The legacy Owner role is
recognized as Superadmin until the existing administrative-tier migration upgrades it.

## Enforcement

- `AuthContext::can('settings.operational')` checks the role, ignoring delegated permission
  rows. The web Operational Settings page and its link use this check.
- The legacy settings API controller independently requires settings.operational for both
  reads and writes. The authentication filter also guards that endpoint. Appearance and
  ordinary printer forms continue using their existing settings.manage web endpoints.
- `AdministrativeAccess::assertUserManageable()` protects Superadmin targets in UserService
  profile/password/activation changes and in user menu mutation/reset paths.
- `assertRoleAssignable()` prevents lower types assigning the Superadmin role. Fixed role
  names and permissions cannot be edited to bypass these restrictions.
- The staff directory disables protected Superadmin controls for other types and filters
  Superadmin out of their role selector. Server checks still reject forged requests.
- Authentication reloads the current database role on each request; a stale JWT role claim
  cannot elevate the actor.

There is currently no staff-account deletion endpoint. Role deletion cannot remove the
fixed Superadmin role. Any future account deletion/reset endpoint must call the same
target guard before writing.

## Verification and deployment

```powershell
C:/xampp/php/php.exe tests/administrative_roles_smoke.php
C:/xampp/php/php.exe tests/administrative_roles_smoke.php --users-view
C:/xampp/php/php.exe tests/administrative_roles_smoke.php --superadmin-view
```

These retained tests use connection-local temporary tables and verify that real user,
permission, settings and audit records remain unchanged. They exercise direct controller
denials, forged permission/token claims, blocked profile/password/status/role changes,
Superadmin-to-Superadmin management, the last-Superadmin safeguard and rendered controls.

This hardening adds no tables or columns and requires no new database migration. Deploy
the controller, view text and tests normally. The existing administrative-tier migration
and its tenant bootstrap remain responsible for provisioning the fixed role types.
