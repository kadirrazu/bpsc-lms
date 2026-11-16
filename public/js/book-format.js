(() => {
    const form = document.querySelector('[data-book-form]');
    if (!form) return;

    const format = form.querySelector('#book-format');
    const count = form.querySelector('#number_of_copies');
    const panel = form.querySelector('[data-accessions]');
    const first = form.querySelector('#accession_number');
    const additional = form.querySelector('#additional-accessions');
    const progress = form.querySelector('#accession-progress');

    const expectedCount = () => Math.max(0, (Number(count.value) || 1) - 1);
    const entriesFrom = value => value.split(/[;\r\n]+/).map(entry => entry.trim()).filter(Boolean);
    let acceptedValue = additional.value;

    function updateAccessions() {
        const expected = expectedCount();
        const entries = entriesFrom(additional.value);
        const numbers = [first.value.trim(), ...entries].filter(Boolean).map(value => value.toLowerCase());
        const duplicate = new Set(numbers).size !== numbers.length;
        panel.hidden = expected === 0 && entries.length === 0;

        progress.textContent = `${entries.length} of ${expected} additional numbers entered.`;
        if (duplicate) progress.textContent += ' Accession numbers must be unique.';
        const error = duplicate ? 'Accession numbers must be unique.'
            : entries.length !== expected ? `Enter exactly ${expected} additional accession number(s).` : '';
        additional.setCustomValidity(format.value === 'hard' ? error : '');
    }

    function updateFormat() {
        const hard = format.value === 'hard';
        for (const [selector, visible] of [['[data-hard-copy]', hard], ['[data-soft-copy]', !hard]]) {
            form.querySelectorAll(selector).forEach(group => {
                group.hidden = !visible;
                group.querySelectorAll('input, textarea').forEach(input => {
                    input.disabled = !visible;
                    input.required = visible && input.hasAttribute('data-format-required');
                });
            });
        }
        updateAccessions();
        if (!hard) panel.hidden = true;
    }

    format.addEventListener('change', updateFormat);
    for (const input of [first, count]) {
        input.addEventListener('input', updateAccessions);
    }
    additional.addEventListener('input', () => {
        const countEntered = entriesFrom(additional.value).length;
        if (countEntered > expectedCount() && countEntered > entriesFrom(acceptedValue).length) {
            additional.value = acceptedValue;
            updateAccessions();
            progress.textContent += ' Increase Number of Copies before adding another accession.';
            return;
        }
        acceptedValue = additional.value;
        updateAccessions();
    });
    additional.addEventListener('blur', () => {
        additional.value = acceptedValue = entriesFrom(additional.value).join('; ');
        updateAccessions();
    });
    updateFormat();
})();
