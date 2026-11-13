# Project conventions

- Keep code clean, organized, and commented where behavior or business rules need explanation. Use descriptive names and follow the existing PHP and JavaScript naming conventions.
- Use SweetAlert2 for confirmation dialogs. Use the shared toaster for success and error messages; do not introduce browser `alert()` or `confirm()` dialogs.
- Keep mobile/API controllers and `/api/v1` route definitions unchanged during web UI tasks unless the user explicitly requests otherwise.
- Prefer AJAX updates for in-page navigation and data changes. Preserve accessible keyboard interactions, loading states, validation, and failure recovery.
- Verify financial changes against isolated test data; do not create test financial records in the user's real tables.
