# Borrowing policy

The default standard limit is **2 active book copies per borrower for 15 days**. An Administrator can change both values under **Administration → Borrowing Settings** (1–100 books; 1–365 days). One shared policy supplies request validation, issue/model guards, due-date calculations, messages and renewal defaults. Changes are audited and apply to new issues and future renewal defaults; existing due dates are preserved. Quantities count as physical copies; a quantity-2 loan uses two slots. Returned loans release slots immediately, while historical borrowing totals retain them. Due dates do not automatically return a book.

## Member requests

Member request submission counts active copies plus pending one-copy requests against the configured limit. This avoids accepting requests beyond the standard capacity; pending requests do not reserve physical stock. Withdrawing/rejecting a pending request releases its request slot. Submission cannot override the policy through extra fields. Approval checks active loan count and stock again under locks.

## Issuing and exceptions

Use **Issue Management → Issue Book** to select an active Library Member and a book through searchable pickers. Administrator, Librarian and Library Staff can issue within the standard limit, consistent with their existing circulation permissions. A pending request for the same book/member must be approved rather than duplicated through direct issue.

Only the **Librarian** role can exceed the standard limit. When an additional issue would exceed the configured limit, the server returns a warning page showing the member, book and actual current count. Nothing is issued until the Librarian clicks **Yes, Issue This Book**. Cancel performs no issue. The 10-minute confirmation is bound to the operator, member, book, source request, active count and policy revision. Wrong/stale confirmations are re-prompted; a successful issue consumes the token. An exception cannot bypass stock, active-member, duplicate-book or request-status checks, and every new loan uses the configured loan period.

Direct issues create a clearly labelled approved management record (`source=staff_issue`) so they appear in the existing return workflow. They are distinguishable from member-submitted requests. Mark Returned uses the same existing loan return action.

## Enforcement and history

New loans use the model's central configurable copy-limit guard and computed due date. Standard writers must use BookLoan model saves, not raw database writes. The book lock protects stock; a borrower row lock serializes issuing different books to the same person. Borrower active copies are rechecked before committing. The trusted override method is not mass assignable, requires a current Librarian and a matching count, and is called by the confirmed issuance service.

Override operator/time, previous active count and due date are recorded on the loan and in audit events. Existing loans receive a due date of original issue time plus 15 days during migration. Existing loans above the standard limit are preserved; further member requests are blocked until sufficient books are returned. Historical return/issue times and counts are unchanged.

Users shows **Total Borrowed** (sum of issued quantities, including returned/reissued records) and **On Loan** (sum of unreturned quantities), with centered values/headings. Both are computed through aggregate subqueries rather than per-user queries.
