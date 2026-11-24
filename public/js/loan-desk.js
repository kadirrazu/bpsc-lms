(() => {
    const form = document.querySelector('[data-loan-desk]');
    if (!form) return;

    const bookPicker = form.querySelector('[data-loan-picker="book"]');
    const bookInput = bookPicker.querySelector('[data-picker-input]');
    const bookValue = bookPicker.querySelector('[data-picker-value]');
    const memberValue = form.elements.namedItem('member_id');
    const list = form.querySelector('[data-loan-selection]');
    const count = form.querySelector('[data-loan-count]');
    const empty = form.querySelector('[data-loan-empty]');
    const feedback = form.querySelector('[data-loan-feedback]');
    const submit = form.querySelector('[data-loan-submit]');
    const limit = Number(form.dataset.bookLimit);
    const exceptionalToggle = form.querySelector('[data-exceptional-toggle]');
    const exceptionalReason = form.querySelector('[data-exceptional-reason]');
    const summary = form.querySelector('[data-member-borrowing-summary]');
    let onLoan = Number(form.dataset.memberOnLoan || 0);
    let pendingRequests = Number(form.dataset.memberPendingRequests || 0);
    let copySequence = 0;
    const exceptional = () => Boolean(exceptionalToggle?.checked);
    const endpoint = new URL(bookPicker.dataset.url, location.href);

    function sync() {
        const selected = [...list.querySelectorAll('input[name="book_ids[]"]')];
        list.querySelectorAll('[data-loan-serial]').forEach((serial, index) => {
            serial.textContent = index + 1;
            serial.setAttribute('aria-label', `Serial ${index + 1}`);
        });
        const copyCount = list.querySelectorAll('[data-issued-copy]').length;
        const url = new URL(endpoint);
        if (exceptional()) url.searchParams.set('exceptional_issue', '1');
        selected.forEach(input => url.searchParams.append('exclude[]', input.value));
        if (memberValue.value) url.searchParams.set('member_id', memberValue.value);
        bookPicker.dataset.url = url.toString();

        count.textContent = `${selected.length} titles · ${copyCount} copies`;
        form.dataset.onLoan = onLoan;
        form.dataset.pendingRequests = pendingRequests;
        summary.hidden = !memberValue.value;
        summary.querySelector('[data-borrowing-limit]').textContent = limit;
        summary.querySelector('[data-borrowing-loans]').textContent = onLoan;
        summary.querySelector('[data-borrowing-pending]').textContent = pendingRequests;
        summary.querySelector('[data-borrowing-total]').textContent = onLoan + copyCount;
        list.querySelectorAll('[data-loan-book]').forEach(book => {
            const copies = book.querySelectorAll('[data-issued-copy]').length;
            book.querySelector('[data-loan-copy-counts]').textContent = `Available: ${book.dataset.availableCopies} · Issuing: ${copies}`;
            const add = book.querySelector('[data-add-copy]');
            if (add) {
                add.hidden = !exceptional();
                add.disabled = copies >= Number(book.dataset.availableCopies) || copyCount >= 100;
            }
        });
        empty.hidden = selected.length !== 0;
        bookInput.disabled = exceptional() ? copyCount >= 100 : selected.length + onLoan >= limit;
        submit.disabled = !memberValue.value || !selected.length || (!exceptional() && copyCount + onLoan > limit) || copyCount > 100;
        feedback.textContent = bookInput.disabled ? 'Selection limit reached.' : '';
    }

    bookValue.addEventListener('change', event => {
        const id = bookValue.value;
        if (!id || !event.detail) return;
        const {title, authors, available_copies: availableCopies} = event.detail;
        bookValue.value = '';
        bookInput.value = '';

        const inputs = [...list.querySelectorAll('input[name="book_ids[]"]')];
        if ((!exceptional() && inputs.length + onLoan >= limit) || inputs.length >= 100 || inputs.some(input => input.value === id)) return;

        const item = document.createElement('li');
        item.className = 'loan-desk-book';
        item.dataset.loanBook = '';
        item.dataset.availableCopies = availableCopies;
        const serial = document.createElement('span');
        serial.className = 'loan-desk-serial';
        serial.dataset.loanSerial = '';
        const details = document.createElement('div');
        details.className = 'loan-desk-book-details';
        const text = document.createElement('div');
        text.className = 'bn-text fw-semibold';
        text.dataset.loanBookLabel = '';
        text.textContent = title;
        const author = document.createElement('div');
        author.className = 'bn-text small text-secondary';
        author.dataset.loanBookAuthors = '';
        author.textContent = authors || 'Author not recorded';
        const copies = document.createElement('div');
        copies.className = 'small text-secondary mt-1';
        copies.dataset.loanCopyCounts = '';
        copies.textContent = `Available: ${availableCopies} · Issuing: 1`;
        const picker = document.createElement('div');
        picker.id = `copy-picker-${id}`;
        picker.dataset.copyUrl = list.dataset.copyUrlTemplate.replace('BOOK_ID', id);
        picker.dataset.copyName = `copy_accessions[${id}]`;
        picker.dataset.copyRequired = '';
        picker.dataset.copyEager = '';
        const additional = document.createElement('div');
        additional.dataset.additionalCopies = '';
        details.append(text, author, copies, picker, additional);
        if (exceptionalToggle) {
            const addCopy = document.createElement('button');
            addCopy.type = 'button';
            addCopy.className = 'btn btn-sm btn-outline-primary mt-3';
            addCopy.dataset.addCopy = '';
            addCopy.textContent = 'Add another copy';
            details.append(addCopy);
        }
        const value = document.createElement('input');
        value.type = 'hidden';
        value.name = 'book_ids[]';
        value.value = id;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-outline-secondary';
        remove.dataset.removeLoanBook = '';
        remove.textContent = 'Remove';
        remove.setAttribute('aria-label', `Remove ${title}`);
        item.append(serial, details, value, remove);
        list.append(item);
        window.setupCopyAccessionPicker(picker);
        sync();
    });
    list.addEventListener('click', event => {
        const addCopy = event.target.closest('[data-add-copy]');
        if (addCopy && exceptional()) {
            const book = addCopy.closest('[data-loan-book]');
            const id = book.querySelector('input[name="book_ids[]"]').value;
            const copies = book.querySelectorAll('[data-issued-copy]').length;
            if (copies >= Number(book.dataset.availableCopies) || list.querySelectorAll('[data-issued-copy]').length >= 100) return;
            const row = document.createElement('div');
            row.className = 'mt-3';
            row.dataset.additionalCopy = '';
            const picker = document.createElement('div');
            picker.id = `additional-${id}-new-${++copySequence}`;
            picker.dataset.copyUrl = list.dataset.copyUrlTemplate.replace('BOOK_ID', id);
            picker.dataset.copyName = `additional_copy_accessions[${id}][]`;
            picker.dataset.copyRequired = '';
            picker.dataset.copyEager = '';
            const removeCopy = document.createElement('button');
            removeCopy.type = 'button';
            removeCopy.className = 'btn btn-sm btn-outline-secondary mt-2';
            removeCopy.dataset.removeCopy = '';
            removeCopy.textContent = 'Remove copy';
            row.append(picker, removeCopy);
            book.querySelector('[data-additional-copies]').append(row);
            window.setupCopyAccessionPicker(picker);
            sync();
            return;
        }
        const removeCopy = event.target.closest('[data-remove-copy]');
        if (removeCopy) {
            removeCopy.closest('[data-additional-copy]').remove();
            sync();
            return;
        }
        const remove = event.target.closest('[data-remove-loan-book]');
        if (!remove) return;
        remove.closest('[data-loan-book]').remove();
        sync();
        bookInput.focus();
    });
    memberValue.addEventListener('change', event => {
        onLoan = Number(event.detail?.on_loan || 0);
        pendingRequests = Number(event.detail?.pending_requests || 0);
        sync();
    });
    function syncExceptional() {
        if (exceptionalToggle) {
            const enabled = exceptional();
            form.querySelector('[data-exceptional-details]').hidden = !enabled;
            exceptionalReason.disabled = !enabled;
            exceptionalReason.required = enabled;
            if (!enabled) list.querySelectorAll('[data-additional-copy]').forEach(row => row.remove());
        }
        sync();
    }
    exceptionalToggle?.addEventListener('change', syncExceptional);
    syncExceptional();
})();
