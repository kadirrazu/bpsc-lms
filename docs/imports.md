# XLSX catalog imports

Books, Authors, Publishers and Categories each have **Import XLSX** beside Add. Download the sample from that page, edit its Data worksheet and retain the header names. The second Instructions worksheet explains the fields.

1. Import Authors, Publishers and Categories first: `title_bn` is required; `title_en` is optional.
2. Books match their exact Bengali names. Separate multiple names in `authors_bn` / `categories_bn` with ` | `; `publisher_bn` is a single name. Names containing `|` must use the regular book form instead.
3. Format `accession_number` as **Text before typing**. Numeric accession cells are rejected to protect leading zeros. Keep call numbers as text as well.
4. Replace or remove sample rows before importing real records. Samples contain illustrative records; importing the unchanged sample would create them.

Required Books headers: `accession_number`, `title_bn`, `authors_bn`, `publisher_bn`, `categories_bn`, `call_number`, `number_of_copies`. Blank copies default to 1. Optional: `title_en`, `shelf_number`, `publication_year`, `price`, `collection_method`.

Price is non-negative, maximum 9,999,999,999.99, with up to two decimal places. Blank means unknown; zero stays zero. Collection Method is blank or exactly `Purchase`, `Gift`, `Others`. Covers are added later through Edit Book.

## Validation and access

- Administrator, Librarian and Library Staff can import/download samples; Members cannot.
- Maximum 500 data rows plus header, 10 MB upload, 30 MB expanded archive, 5,000 archive entries. Split larger batches. Imports are synchronous and limited to ten attempts/minute per authenticated user.
- XLSX only; the first worksheet is imported. Unknown/duplicate/missing headers, formulas, errors, macros, embedded binaries and external links are rejected.
- Imports **add new records**, without updating/deleting existing data. Duplicate master BN names or accession numbers (including archived books) are errors. There are no silent skips.
- The entire file is validated before saving. Any invalid row cancels the import; up to 50 row errors are shown. Save conflicts roll back all rows, relationships and audit entries.
- New books start with zero issues. Creation, imported relationships and batch summaries are audited. Catalog/dashboard caches invalidate through the existing revision mechanism. No image files are created.
