# Issue Management

The menu after Dashboard groups Issue Book, Issue Requests, Issue Register and Fine Reports. Existing circulation operators (Administrator, Librarian, Library Staff) can use these operational records/actions. System Audit Log access remains governed by the existing Administrator/Librarian gate.

## Confirming issues

Issue Book asks for a member and book through searchable selectors. Clicking Issue opens a modal with the selected book/accession and member/email. Cancel sends nothing; Confirm submits the issue. The server requires confirmation acknowledgement. Book Details also links to this form with that book selected. The separate Librarian-only over-limit warning remains in force after the normal confirmation; stock, role and borrowing limits still apply.

## Register and snapshots

Issue Register stacks accession, title and author on separate lines in one Book column, followed by member, issue/due/return timestamps, issued by, renewal count, fine flag and last reminder. Search, loan/fine filters and 20-row pagination are supported. Fine Reports opens the flagged view, and Export CSV uses the same filters. CSV rows are streamed in batches with UTF-8 BOM; text with spreadsheet formula prefixes is escaped. Import accession columns as Text when opening CSV in spreadsheet software to retain leading zeros.

New issues capture book names/accession/authors and issuer identity at issue time, so later edits do not rewrite the register history. Migration backfills existing book snapshots from the current catalog and issuer from a linked request reviewer when recorded. These are migration-time reconstructions, not recovered historical names. Missing issuer provenance is shown as Not recorded.

## Reminder, Renew and Fine

Each action opens a contextual confirmation modal naming the book and member. Server authorization and confirmation validation apply even to a manually constructed POST. JavaScript must be enabled for the modal; no action is submitted on Cancel.

- Reminder updates one current reminder per loan. The member's dashboard shows the latest ten reminders for their own unreturned loans, with book/accession, due date and sent time. Other members cannot see them. Returning the loan removes it from the dashboard reminder query. Re-sending updates the existing reminder instead of creating duplicate notices. This is an in-system dashboard message, with no external email/SMS delivery.
- Renew and Fine appear only after the due time has passed; the server rejects premature submissions too. Actions remain side by side in a horizontally scrollable table on narrow screens. Renew defaults to the configured loan period (initially 15 days); the modal accepts 1–365 days. Extension starts now because renewal is available only for overdue loans. It updates due date/renewal count and records an audit event, preserving original issue date, issuer and lifetime issue count. Returned loans cannot be renewed. The submitted renewal revision must match the locked record; repeated/stale confirmation cannot silently add the extension twice.
- Fine marks the issue with a fine flag for filtered reporting, including returned/archived history. It does not create a monetary amount/payment. Clear Fine remains available for an existing flag and reverses it after confirmation, preserving both actions in audit history.

Actions are logged as `book.return_reminder_sent`, `book.renewed`, `book.fine_flagged`, `book.fine_cleared`; normal issue and metadata logs remain in place.
