# Module 3 Contribution: Resolution, Feedback & Analytics

## Contributors

- **Member 3 — Raiyanuzzaman Khan:** Staff progress updates, resolution details, and resolving complaints.
- **Member 2 — MD Zahid Hasan:** Complainant resolution verification, confirmation, reopening, rating, and feedback.
- **Member 1 — Anika Islam Tonne:** Resolved complaint knowledge base and historical recommendation support.

## Implemented behavior

- Assigned staff can upload supporting evidence, post public progress updates, and record resolution details when resolving an in-progress complaint. Updates are added to the complaint timeline and resolution actions are audited.
- Complainants can read resolution details, confirm a resolution, reopen resolved or closed complaints that remain unresolved, and submit or revise a 1–5 rating with optional feedback.
- The `complaint_resolution_knowledge` database view combines category, priority, complaint description, resolution, elapsed resolution time, rating, and feedback for resolved cases. Rule based recommendations use this case history when suggesting resolution steps.
- Staff access remains limited to complaints assigned to them. Complaint attachments continue to use the existing validated upload storage and access control.

## Files

- `database/migrations/007_module3_resolution_feedback.sql` — schema fields, feedback table, historical knowledge view.
- `includes/complaint_admin.php`, `includes/complaints.php`, `includes/constants.php`, `includes/helpers.php` — lifecycle actions, ownership/access rules, and timeline labels.
- `services/complaint_intelligence.php` — feed resolved cases from the knowledge view into recommendations.
- `public/api/complaints/staff-update.php`, `public/api/complaints/evidence.php`, `public/api/complaints/resolution-action.php` — protected staff and complainant actions.
- `public/admin/complaint.php`, `public/complaints/view.php`, `components/complaint_detail.php`, `components/resolution_actions.php` — staff and complainant interface.
- `public/api/complaints/attachment.php` — permits the assigned staff member to view case evidence.
- `public/assets/js/pages/resolution-actions.js` — asynchronous form handling.

## Database setup

Apply migration `007_module3_resolution_feedback.sql` after migrations `003` through `006` (or update `database/schema.sql` for fresh installs before importing). Existing complaint history is retained. Only complaints resolved with recorded resolution details enter the knowledge view.
