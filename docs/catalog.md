# Catalog and Circulation Foundation

## Book records

- `accession_number` is unique text; leading zeros are preserved. It remains reserved after deleting a book.
- `title_bn` is mandatory and is the primary display title. `title_en` is optional. Both are searchable.
- Each book has one or more authors (`book_authors`), one publisher, and one or more categories (`book_categories`). At most 50 authors/categories may be selected per book. Related titles follow the same BN-required/EN-optional rule.
- Call Number is required free text. Shelf Number, publication year and cover are optional. Copies are a required positive integer, default 1 in the form and database.
- Author/category pivots have unique composite keys and indexed foreign keys. Linked master records, including those linked to archived books, cannot be deleted.
- Catalog, author, publisher and category book-list pages use 20 books per page. Entity names and dashboard clouds link to a scoped contributor book list.
- Dedicated Accession Number and Call Number fields support Exact (default) and Starts with modes. Identifier patterns escape `%`, `_` and `!` literally. Composite indexes cover live-book identifier and publisher filtering.
- General search retains substring matching across both languages and related names. Terms are ANDed. Metadata matching uses uncorrelated ID sets and indexed pivots, instead of per-book nested relation/name scans. This is not a full-text search engine.
- Page ID/count caches have 256 page slots and 256 count slots with 90-second TTLs and fingerprints. Catalog revision changes invalidate cached answers after books, metadata or pivots change. Only the current page's 20 models and relations are hydrated; stock/cumulative issue values remain fresh even on an ID-cache hit.
- Issue/user writes bump a separate dashboard revision, keeping unchanged title-search caches and clouds reusable. Dashboard statistics, recent five books/issues and top-24 author/category clouds are cached with the relevant revision. Counts exclude archived books; latest-issue history can still show an archived title.
- Search debounce is 180 ms. Broad one-character general search waits for a second character unless another filter narrows the search; pressing Search still supports a one-character query. Identifier fields and normal pagination remain available without live-search JavaScript.
- Bulk/query-builder catalog imports must call `CatalogRevision::touch()` after their writes, since they bypass model observers. Ordinary CRUD and pivot/loan model paths already invalidate the appropriate revisions.

## Permissions

Administrator, Librarian and Library Staff can add/edit books and catalog setup entries. Only Administrator and Librarian can delete. Library Members can search/filter the catalog and read details/covers. They cannot use administrative CRUD endpoints or see audit history.

## Covers

Covers accept JPEG, PNG or WebP up to 5 MB and 6000 × 6000 pixels. SVG is not accepted. Generated filenames are stored on the private `local` disk and served through an authenticated route; `storage:link` is not needed.

Replacement, removal and book deletion queue old paths in the database transaction, then remove files immediately after the operation succeeds. Failed removals remain in `cover_file_deletions` for retry. A currently referenced file is never removed. New files from failed book writes are discarded. Run `php artisan library:purge-cover-files` to retry cleanup, or run the Laravel scheduler for automatic retries.

Book deletion is a soft deletion to preserve identity, issue history and audit records; cover paths/files are removed. A book with an outstanding issue cannot be deleted, and copies cannot be reduced below issued quantity.

## Issue/return foundation

This release does not expose issue/return or request-approval screens. `book_loans` and `BookLoan` prepare those workflows. `returned_at = null` means currently issued. `quantity` counts copies within one issue record.

Use `BookLoan::create()` / model saves for circulation writers; do not bypass them with bulk/query-builder inserts. The model serializes writes with book edit/deletion using a book-row lock, checks stock and rejects new loans on deleted books. Returned records cannot be reopened; reissuing requires a new record. Existing issue records cannot be moved to a different book.

`books.total_issue_count` is the lifetime number of successful issue records, not the sum of copies. It increments atomically once on creation. Returning or editing an existing record does not increment it. The count is retained if an issue record is removed, and is displayed in the catalog and book details.

## Audit

Book CRUD and master CRUD use the existing audit trail. Pivot changes have a separate `book.relationships_changed` event. Issue/return events are `book.issued` and `book.returned`, with issue number, member, operator, quantity and timestamps. Administrators and Librarians can view the latest book events on its details page and open the full subject-filtered audit history.

## Bengali font

Noto Sans Bengali is bundled locally in WOFF2 format under the SIL Open Font License; the license is in `public/fonts/Noto-Sans-Bengali-LICENSE.txt`. The old Nikosh file remains untouched in the baseline but is not used by the new catalog: Chromium rejected its font metadata during QA.

## Default categories

`CategorySeeder` idempotently adds the Bengali labels `অন্যান্য` / `Others` and `ক্যাটাগরী নির্দিষ্ট করা হয়নি` / `Uncategorized`. Existing entries and customized English names are not overwritten. The upgrade migration seeds these defaults; it does not reassign existing books.

## Views and member requests (v0.6.0)

Catalog views support `view=grid` (default) and `view=list`; filters and numbered pagination preserve the selected view. View mode does not duplicate the cached search IDs/counts. Number Match also supports `partial`, which uses escaped `%term%` matching. Unlike exact lookup, substring matching can scan the catalog; use Exact Match for known identifiers.

Approved Library Members can request one available copy from book details. Only their own requests and loans appear in My Requests. One pending request per member/book and an existing active loan prevent duplicates. Administrator, Librarian and Library Staff can approve/reject issue requests and record returns. Approval rechecks member status and stock inside a transaction and writes through BookLoan; it does not reserve stock at request time. Issue/return audit and lifetime issue counts use the existing observers. Due dates, fines, renewals and notifications are outside this release.
