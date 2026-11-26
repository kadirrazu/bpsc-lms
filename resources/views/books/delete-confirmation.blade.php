<button type="button" class="btn btn-outline-danger" data-book-delete-open>
    <i class="bi bi-trash" aria-hidden="true"></i>Delete Book
</button>
<noscript><p class="text-danger mb-0">Enable JavaScript to confirm book deletion.</p></noscript>
<dialog class="book-delete-dialog" aria-labelledby="book-delete-title" aria-describedby="book-delete-message">
    <form method="post" action="{{ route('books.destroy', $book) }}" data-book-delete-form>
        @csrf
        @method('DELETE')
        <div class="book-delete-content">
            <h2 id="book-delete-title" class="text-danger mb-3">Delete this book?</h2>
            <p class="fw-semibold bn-text mb-2">{{ $book->title_bn }}</p>
            <p class="text-secondary small mb-3">Accession: {{ $book->accession_number }}</p>
            <p id="book-delete-message">This removes the book from the catalog and deletes its uploaded files. Previous issue and return records are retained. This action will be recorded in the audit log.</p>
            <label class="form-label" for="book-delete-confirmation">Type <strong>DELETE</strong> exactly to confirm.</label>
            <input type="text" class="form-control" id="book-delete-confirmation" name="confirmation"
                required pattern="DELETE" maxlength="6" autocomplete="off" autocapitalize="off" spellcheck="false"
                aria-describedby="book-delete-help">
            <p id="book-delete-help" class="form-hint mb-0">Use uppercase letters with no spaces.</p>
        </div>
        <div class="book-delete-actions">
            <button type="button" class="btn btn-outline-secondary" data-book-delete-cancel>Cancel</button>
            <button type="submit" class="btn btn-danger" data-book-delete-submit disabled>Delete Book</button>
        </div>
    </form>
</dialog>
