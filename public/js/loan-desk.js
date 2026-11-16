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
    const endpoint = new URL(bookPicker.dataset.url, location.href);

    function sync() {
        const selected = [...list.querySelectorAll('input[name="book_ids[]"]')];
        list.querySelectorAll('[data-loan-serial]').forEach((serial, index) => {
            serial.textContent = index + 1;
            serial.setAttribute('aria-label', `Serial ${index + 1}`);
        });
        const url = new URL(endpoint);
        selected.forEach(input => url.searchParams.append('exclude[]', input.value));
        if (memberValue.value) url.searchParams.set('member_id', memberValue.value);
        bookPicker.dataset.url = url.toString();

        count.textContent = `${selected.length} of ${limit} selected`;
        empty.hidden = selected.length !== 0;
        bookInput.disabled = selected.length >= limit;
        submit.disabled = !memberValue.value || !selected.length || selected.length > limit;
        feedback.textContent = selected.length >= limit ? 'Selection limit reached.' : '';
    }

    bookValue.addEventListener('change', event => {
        const id = bookValue.value;
        if (!id || !event.detail) return;
        const {title, authors, available_copies: availableCopies, matched_accession: matchedAccession} = event.detail;
        bookValue.value = '';
        bookInput.value = '';

        const inputs = [...list.querySelectorAll('input[name="book_ids[]"]')];
        if (inputs.length >= limit || inputs.some(input => input.value === id)) return;

        const item = document.createElement('li');
        item.className = 'loan-desk-book';
        item.dataset.loanBook = '';
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
        picker.dataset.copySelected = matchedAccession ?? '';
        picker.dataset.copyEager = '';
        details.append(text, author, copies, picker);
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
        const remove = event.target.closest('[data-remove-loan-book]');
        if (!remove) return;
        remove.closest('[data-loan-book]').remove();
        sync();
        bookInput.focus();
    });
    memberValue.addEventListener('change', sync);
    sync();
})();
