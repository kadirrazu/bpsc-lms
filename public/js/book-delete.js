(() => {
    const open = document.querySelector('[data-book-delete-open]');
    const dialog = document.querySelector('.book-delete-dialog');
    if (!open || !dialog) return;

    document.body.append(dialog);

    const form = dialog.querySelector('[data-book-delete-form]');
    const input = form.elements.namedItem('confirmation');
    const submit = form.querySelector('[data-book-delete-submit]');
    const reset = () => {
        input.value = '';
        submit.disabled = true;
    };

    open.addEventListener('click', () => {
        reset();
        dialog.showModal();
        input.focus({preventScroll: true});
    });
    input.addEventListener('input', () => {
        submit.disabled = input.value !== 'DELETE';
    });
    form.querySelector('[data-book-delete-cancel]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', reset);
    form.addEventListener('submit', event => {
        if (input.value !== 'DELETE') {
            event.preventDefault();
            input.focus({preventScroll: true});
        }
    });
})();
