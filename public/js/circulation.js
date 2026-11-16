(() => {
    const dialog = document.createElement('dialog');
    dialog.className = 'circulation-dialog';
    dialog.setAttribute('aria-labelledby', 'circulation-confirm-title');
    dialog.setAttribute('aria-describedby', 'circulation-confirm-text');
    Object.assign(dialog.style, {
        border: '1px solid #dce5f2', borderRadius: '16px', padding: '0',
        maxWidth: 'min(560px, 92vw)', maxHeight: '85dvh', width: '100%',
        boxShadow: '0 20px 65px rgba(27, 49, 88, .24)',
        color: 'var(--tblr-body-color, #182433)', background: 'var(--tblr-bg-surface, #fff)',
    });
    dialog.innerHTML = `
        <form method="dialog">
            <div class="p-4">
                <h2 class="mb-2" id="circulation-confirm-title">Confirm action</h2>
                <p class="text-secondary mb-0" id="circulation-confirm-text" style="white-space:pre-wrap;overflow-wrap:anywhere"></p>
                <ul class="list-group list-group-flush mt-3 mb-0" data-confirm-books hidden></ul>
            </div>
            <div class="border-top px-4 py-3 d-flex flex-wrap justify-content-end gap-2">
                <button class="btn btn-outline-secondary" value="cancel" formnovalidate>Cancel</button>
                <button class="btn btn-primary" value="confirm">Confirm</button>
            </div>
        </form>`;
    document.body.append(dialog);
    const approved = new WeakSet();
    let pending = null;
    dialog.addEventListener('cancel', () => { pending = null; });
    dialog.querySelector('form').addEventListener('submit', event => {
        event.preventDefault();
        const submission = pending;
        pending = null;
        dialog.close();
        if (!submission || event.submitter?.value !== 'confirm') return;

        const {form, submitter} = submission;
        let confirmed = form.elements.namedItem('confirmed');
        if (!confirmed) {
            confirmed = document.createElement('input');
            confirmed.type = 'hidden';
            confirmed.name = 'confirmed';
            form.append(confirmed);
        }
        confirmed.value = '1';
        approved.add(form);
        try {
            form.requestSubmit(submitter || undefined);
        } finally {
            approved.delete(form);
        }
    });
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-circulation-confirm]');
        if (!form || event.defaultPrevented || approved.has(form)) return;
        event.preventDefault();
        if (pending) return;
        const action = form.dataset.circulationConfirm;
        const loanDays = Number(form.dataset.loanDays);
        const issue = action === 'issue';
        const books = issue ? [...form.querySelectorAll('[data-loan-book]')] : [];
        if (issue && (!books.length || !form.elements.namedItem('member_id')?.value)) return;
        pending = {form, submitter: event.submitter};
        const book = `${form.dataset.book} (${form.dataset.accession})`;
        const member = action === 'issue' ? form.querySelector('#member-search').value : form.dataset.member;
        const title = {issue: 'Review book issue', reminder: 'Send return reminder?', renew: 'Renew this loan?', fine: 'Flag this issue for fine?', 'clear-fine': 'Clear the fine flag?'}[action];
        const text = {issue: `${member}\n${books.length} book${books.length === 1 ? '' : 's'} · ${loanDays}-day loan`, reminder: `Send a book return reminder to ${member}’s dashboard?\n\nBook: ${book}`, renew: `Extend the loan for ${member} by ${loanDays} days from today?\n\nBook: ${book}\nCurrent due date: ${form.dataset.due ? new Date(form.dataset.due).toLocaleString() : 'Not recorded'}`, fine: `Flag this issue for separate fine reporting?\n\nBook: ${book}\nMember: ${member}`, 'clear-fine': `Clear the fine flag on this issue?\n\nBook: ${book}\nMember: ${member}`}[action];
        dialog.querySelector('#circulation-confirm-title').textContent = title || 'Confirm action';
        dialog.querySelector('#circulation-confirm-text').textContent = text || 'Continue?';
        const list = dialog.querySelector('[data-confirm-books]');
        list.hidden = !issue;
        list.replaceChildren(...books.map(book => {
            const item = document.createElement('li');
            item.className = 'list-group-item px-0';
            const label = book.querySelector('[data-loan-book-label]');
            const copies = book.querySelector('[data-loan-copy-counts]');
            item.append(label.cloneNode(true), copies.cloneNode(true));
            const accession = book.querySelector('[data-issued-copy]')?.value.trim();
            if (accession) {
                const selectedCopy = document.createElement('div');
                selectedCopy.className = 'small text-secondary mt-1';
                selectedCopy.textContent = `Copy accession: ${accession}`;
                item.append(selectedCopy);
            }
            return item;
        }));
        dialog.querySelector('button[value="confirm"]').textContent = issue ? 'Confirm Issue' : 'Confirm';
        dialog.showModal();
        dialog.querySelector('button[value="cancel"]').focus();
    });
})();
