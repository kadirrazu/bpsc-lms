# Catalog imports

All four import types follow **Download Template → Upload & Preview → Edit Row if needed → Confirm Import**. Only Administrator, Librarian and Library Staff can use them. Nothing is written to the catalog on upload or preview editing. Import adds new records; it never overwrites existing records.

## Templates

Download a fresh template from the target installation. Templates are blank to avoid importing example records accidentally.

- Books template: `Books`, `Authors`, `Publishers`, `Categories`, `Instructions` sheets. The first sheet alone is imported. Reference sheets contain current database IDs, Bengali/English names and an ID–name selection label.
- Master templates: `Data` and `Instructions` sheets; `title_bn` is required, `title_en` optional.
- New Book templates use `author_1`–`author_5`, `publisher_id`, `category_1`–`category_5`. Author 1 and Category 1 are required. Columns 2–5 are strictly optional and are never prefilled; leave them blank unless additional selections are needed. Optional 2–5 headers may also be omitted.
- Every numbered author/category field has an ID–name dropdown and accepts one selection. Gaps in optional positions are allowed; repeated IDs are linked only once. First selection remains required even if a later position is filled. Nonempty invalid optional selections block import. Use the web row editor for more than five selections, up to the existing limit of 50.
- Author, Category and Publisher dropdowns display `ID — Bengali name`; plain IDs are accepted too. The server checks each selected ID and preview displays the actual database names. Required columns have blue headers; optional columns have slate headers with lighter cells and input hints. Accession and primary title remain visible when scrolling. Reference sheets offer filters; dropdown AutoComplete depends on the installed Excel version.
- Existing v0.12.0 ID-list templates remain supported (`author_ids` / `category_ids`, pipe-separated). Do not mix numbered columns and ID-list columns for the same relation. Old name-based headers (`authors_bn`, `publisher_bn`, `categories_bn`) remain unsupported.
- IDs are installation-specific. Do not transfer templates between different databases. Missing records must be created/imported first; book imports never create masters from unknown text.

Required new Books headers: `accession_number`, `title_bn`, `author_1`, `publisher_id`, `category_1`, `call_number`, `number_of_copies`. Blank copies default to 1. Optional: `title_en`, `shelf_number`, `publication_year`, `price`, `collection_method`.

Accession numbers must be Text cells to preserve leading zeros and must be unique, including archived books. Price is optional, non-negative, with up to two decimal places. Collection Method is optional: Purchase, Gift or Others; the template provides a dropdown. Add cover images later through Edit Book.

## Preview, correction and confirmation

Preview lists 20 rows per page, retaining original Excel row numbers. It displays selected names and IDs, book fields, validation errors and an Edit Row link. All rows are validated, including those on other pages. Any invalid row disables confirmation and blocks the whole import server-side.

Edit Row corrects the preview only. Book rows use the existing searchable single/multiple selectors for publisher/authors/categories. The final Confirm Import submits only the draft token, revision and preview fingerprint, avoiding PHP's input-variable limit for large files.

Confirmation revalidates all fields, duplicates and selected master records in a database transaction. Changed reference names or preview content require a refreshed preview. Deleted reference IDs and duplicate accession/name conflicts block the whole import. No partial catalog writes are retained. Double confirmation is serialized and does not import the same draft twice. Every successful import and imported record is audited.

## Limits and private drafts

XLSX only, maximum 10 MB uploaded, 30 MB expanded, 500 data rows. Formulas, errors, booleans, macros, embedded binaries and external links are rejected. Reference worksheets are not imported.

Drafts are stored under private local storage, bound to the uploading operator and signed-in session, and expire after one hour. Cancellation clears draft rows. Preview/edit/confirmation operations use one exclusive file lock per draft. Completed drafts retain only completion metadata until cleanup.

Stale preview files older than two hours are pruned on upload and by the daily scheduled `library:prune-import-drafts` command. Run Laravel's scheduler through the existing server/Windows Task Scheduler setup (`php artisan schedule:run` each minute), or run the prune command manually. Private storage must be writable and shared by application workers; multi-server deployments need a shared filesystem with working file locks and sessions.

Runtime requirements remain the existing PhpSpreadsheet dependency and PHP ZIP/XML/mbstring/GD extensions. No new Composer package, migration or frontend build is required for this release.
