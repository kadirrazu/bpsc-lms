import '@tabler/core/dist/js/tabler.min.js';
document.addEventListener('submit', event => {
    const form = event.target.closest('form[data-confirm-delete]');
    if (form && !window.confirm(form.dataset.confirmMessage)) event.preventDefault();
});

// Values remain only in the existing field. Submission and page hiding restore masking.
const passwordControls = [];
document.querySelectorAll('[data-password-toggle]').forEach(button => {
    const input = button.parentElement.querySelector('input');
    if (!input) return;
    const setVisible = visible => {
        input.type = visible ? 'text' : 'password';
        const label = visible ? button.dataset.hideLabel : button.dataset.showLabel;
        button.setAttribute('aria-label', label);
        button.title = label;
        button.setAttribute('aria-pressed', String(visible));
        button.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
    };
    button.hidden = false;
    button.addEventListener('click', () => {
        setVisible(input.type === 'password');
        input.focus({preventScroll: true});
    });
    input.form?.addEventListener('submit', () => setVisible(false));
    passwordControls.push(() => setVisible(false));
});
window.addEventListener('pagehide', () => passwordControls.forEach(hide => hide()));
document.addEventListener('visibilitychange', () => {
    if(document.hidden) passwordControls.forEach(hide => hide());
});


// Small remote combobox: labels remain Bengali; the server searches both languages.
document.querySelectorAll('[data-catalog-picker]').forEach((picker, index) => {
    const input = picker.querySelector('[data-picker-input]');
    const value = picker.querySelector('[data-picker-value]');
    const list = picker.querySelector('[role="listbox"]');
    const status = picker.querySelector('.picker-status');
    let timer, controller, items = [], active = -1, generation = 0;
    const close = () => {list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1;};
    const highlight = next => {
        active = next;
        [...list.children].forEach((option, n) => {option.classList.toggle('active', n === active); option.setAttribute('aria-selected', String(n === active));});
        if (active >= 0) {input.setAttribute('aria-activedescendant', list.children[active].id); list.children[active].scrollIntoView({block: 'nearest'});}
    };
    const select = item => {generation++; controller?.abort(); clearTimeout(timer); input.value = item.label; value.value = String(item.id); status.textContent = ''; input.setCustomValidity(''); close(); value.dispatchEvent(new Event('change', {bubbles: true}));};
    const search = async () => {
        const current = ++generation;
        controller?.abort(); controller = new AbortController(); status.textContent = 'Searching…';
        const url = new URL(picker.dataset.url, location.href); url.searchParams.set('q', input.value.trim());
        try {
            const response = await fetch(url, {signal: controller.signal, headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (!response.ok) throw new Error('Lookup failed');
            const data = await response.json(); if (current !== generation || document.activeElement !== input) return;
            items = data.results; active = -1; list.replaceChildren();
            items.forEach((item, n) => {
                const option = document.createElement('div'); option.className = 'picker-option bn-text'; option.id = `picker-${index}-option-${n}`; option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false'); option.textContent = item.label;
                option.addEventListener('pointerdown', event => {event.preventDefault(); select(item);}); list.append(option);
            });
            list.hidden = !items.length; input.setAttribute('aria-expanded', String(items.length > 0)); status.textContent = items.length ? `${items.length} results. Use arrow keys and Enter to select.` : 'No matches. Try another name.';
        } catch (error) {if (error.name !== 'AbortError' && current === generation) {close(); status.textContent = 'Unable to load options. Please try again.';}}
    };
    input.addEventListener('input', () => {generation++; controller?.abort(); value.value = ''; close(); clearTimeout(timer); timer = setTimeout(search, 250); value.dispatchEvent(new Event('change', {bubbles: true}));});
    input.addEventListener('focus', () => {clearTimeout(timer); search();});
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') {close(); return;}
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !list.hidden && items.length) {event.preventDefault(); highlight(active === -1 ? (event.key === 'ArrowDown' ? 0 : items.length - 1) : (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length);}
        if (event.key === 'Enter' && !list.hidden) {event.preventDefault(); if (active >= 0) select(items[active]);}
    });
    input.addEventListener('blur', () => {setTimeout(close, 100); if (!value.value && input.value) {input.value = ''; status.textContent = 'Select a result to apply this field.';}});
    picker.querySelector('[data-picker-clear]').addEventListener('click', () => {generation++; controller?.abort(); value.value = ''; input.value = ''; input.setCustomValidity(''); value.dispatchEvent(new Event('change', {bubbles: true})); input.focus(); search();});
    input.form?.addEventListener('submit', event => {if (input.required && !value.value) {event.preventDefault(); input.setCustomValidity('Select a result from the list.'); input.reportValidity();}});
});

// Live result refresh with request cancellation and normal links as a fallback.
document.querySelectorAll('[data-live-search]').forEach(form => {
    const results = document.querySelector(form.dataset.results);
    const status = form.querySelector('.live-status');
    let timer, controller, generation = 0;
    const load = async (url, push = false) => {
        const current = ++generation; controller?.abort(); controller = new AbortController(); results.setAttribute('aria-busy', 'true'); status.textContent = 'Updating results…';
        try {
            const response = await fetch(url, {signal: controller.signal, headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (!response.ok) throw new Error('Results failed'); const data = await response.json(); if (current !== generation) return;
            results.innerHTML = data.html; history[push ? 'pushState' : 'replaceState']({}, '', url); status.textContent = 'Results updated.';
        } catch (error) {if (error.name !== 'AbortError' && current === generation) status.textContent = 'Unable to update results. Press Search to retry.';}
        finally {if (current === generation) results.removeAttribute('aria-busy');}
    };
    const urlForForm = () => {const url = new URL(form.action || location.href); url.search = new URLSearchParams(new FormData(form)).toString(); url.searchParams.delete('page'); return url;};
    const queue = () => {
        clearTimeout(timer); controller?.abort(); generation++;
        const query=form.querySelector('input[name="q"]')?.value.trim() || '';
        const narrowed=[...form.querySelectorAll('input[name="accession_number"],input[name="call_number"],input[name$="_id"]')].some(input=>input.value.trim());
        if(query && !narrowed && [...query].length < Number(form.dataset.minQuery || 1)){status.textContent='Type at least 2 characters, or press Search.';results.removeAttribute('aria-busy');return;}
        timer=setTimeout(() => load(urlForForm()), 180);
    };
    form.addEventListener('input', event => {if (event.target.matches('[data-live-input]')) queue();});
    form.addEventListener('change', event => {if (event.target.matches('[data-picker-value], [data-live-select]')) queue();});
    form.addEventListener('submit', event => {event.preventDefault(); clearTimeout(timer); load(urlForForm(), true);});
    results.addEventListener('click', event => {const link = event.target.closest('.pagination a'); if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {event.preventDefault(); clearTimeout(timer); load(new URL(link.href), true);}});
});
if (document.querySelector('[data-live-search]')) window.addEventListener('popstate', () => location.reload());

const coverInput = document.querySelector('[data-cover-file]');
if (coverInput) {
    const preview = document.querySelector('[data-cover-preview]'); const remove = document.querySelector('[data-remove-cover]'); const original = preview.innerHTML;
    const empty = () => {preview.innerHTML = '<div class="cover-placeholder"><i class="bi bi-book"></i><span>No cover image</span></div>';};
    coverInput.addEventListener('change', () => {
        coverInput.setCustomValidity(''); const file = coverInput.files[0];
        if (!file) {preview.innerHTML = original; if (remove?.checked) empty(); return;}
        if (file.size > 5 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {coverInput.setCustomValidity('Choose a JPG, PNG or WebP image up to 5 MB.'); coverInput.reportValidity(); return;}
        if (remove) remove.checked = false;
        const reader = new FileReader(); reader.onload = () => {const img = document.createElement('img'); img.src = reader.result; img.alt = 'Selected cover preview'; preview.replaceChildren(img);}; reader.readAsDataURL(file);
    });
    remove?.addEventListener('change', () => {if (!coverInput.files.length) {if (remove.checked) empty(); else preview.innerHTML = original;}});
}

// Multiple authors/categories: chips carry IDs while the visible query stays unnamed.
document.querySelectorAll('[data-multi-catalog-picker]').forEach((picker, index) => {
    const input = picker.querySelector('[data-picker-input]'), chips = picker.querySelector('[data-picker-chips]'), list = picker.querySelector('[role="listbox"]'), status = picker.querySelector('.picker-status');
    const selected = new Set([...chips.querySelectorAll('[data-picker-chip]')].map(chip => chip.dataset.id));
    let controller, timer, generation = 0, items = [], active = -1;
    const close = () => {list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1;};
    const sync = () => {input.required = selected.size === 0; input.setCustomValidity(''); status.textContent = selected.size ? `${selected.size} selected. Search to add more.` : 'Select at least one.';};
    chips.addEventListener('click', event => {const button = event.target.closest('[data-chip-remove]'); if (!button) return; const chip = button.closest('[data-picker-chip]'); selected.delete(chip.dataset.id); chip.remove(); sync(); input.focus(); search();});
    const select = item => {
        if (selected.has(String(item.id))) return;
        if (selected.size >= 50) {status.textContent = 'You can select up to 50 entries.'; return;}
        selected.add(String(item.id)); generation++; controller?.abort(); clearTimeout(timer);
        const chip = document.createElement('span'); chip.className = 'catalog-chip bn-text'; chip.dataset.pickerChip = ''; chip.dataset.id = String(item.id);
        const label = document.createElement('span'); label.textContent = item.label; chip.append(label);
        const value = document.createElement('input'); value.type = 'hidden'; value.name = picker.dataset.name; value.value = String(item.id); chip.append(value);
        const button = document.createElement('button'); button.type = 'button'; button.dataset.chipRemove = ''; button.setAttribute('aria-label', `Remove ${item.label}`); button.textContent = '×'; chip.append(button); chips.append(chip);
        input.value = ''; close(); sync(); input.focus();
    };
    const search = async () => {
        const current = ++generation; controller?.abort(); controller = new AbortController();
        const url = new URL(picker.dataset.url, location.href); url.searchParams.set('q', input.value.trim()); selected.forEach(id => url.searchParams.append('exclude[]', id)); status.textContent = 'Searching…';
        try {
            const response = await fetch(url, {signal: controller.signal, headers: {'Accept': 'application/json'}, credentials: 'same-origin'}); if (!response.ok) throw new Error('Lookup failed');
            const data = await response.json(); if (current !== generation || document.activeElement !== input) return;
            items = data.results.filter(item => !selected.has(String(item.id))); list.replaceChildren(); active = -1;
            items.forEach((item, n) => {const option = document.createElement('div'); option.className = 'picker-option bn-text'; option.id = `multi-${index}-option-${n}`; option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false'); option.textContent = item.label; option.addEventListener('pointerdown', event => {event.preventDefault(); select(item);}); list.append(option);});
            list.hidden = !items.length; input.setAttribute('aria-expanded', String(items.length > 0)); status.textContent = items.length ? `${items.length} results. Use arrow keys and Enter to add.` : 'No more matches. Try another name.';
        } catch (error) {if (error.name !== 'AbortError' && current === generation) {close(); status.textContent = 'Unable to load options. Please try again.';}}
    };
    input.addEventListener('input', () => {generation++; controller?.abort(); close(); clearTimeout(timer); input.setCustomValidity(''); timer = setTimeout(search, 250);});
    input.addEventListener('focus', () => {clearTimeout(timer); search();});
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') {close(); return;}
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !list.hidden && items.length) {event.preventDefault(); active = active === -1 ? (event.key === 'ArrowDown' ? 0 : items.length - 1) : (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; [...list.children].forEach((option, n) => {option.classList.toggle('active', n === active); option.setAttribute('aria-selected', String(n === active));}); input.setAttribute('aria-activedescendant', list.children[active].id); list.children[active].scrollIntoView({block: 'nearest'});}
        if (event.key === 'Enter' && !list.hidden) {event.preventDefault(); if (active >= 0) select(items[active]);}
    });
    input.addEventListener('blur', () => {setTimeout(close, 100); input.value = ''; sync();});
    document.addEventListener('pointerdown', event => {if (!picker.contains(event.target)) close();});
    input.form?.addEventListener('submit', event => {if (!selected.size) {event.preventDefault(); input.setCustomValidity('Select at least one entry from the list.'); input.reportValidity();}});
    sync();
});

// Debounced, local-only guidance. Laravel validation remains authoritative.
document.querySelectorAll('form[data-password-feedback]').forEach(form => {
    const password = form.querySelector('[name="password"]');
    const confirmation = form.querySelector('[name="password_confirmation"]');
    if (!password || !confirmation) return;
    let timer;
    const paint = (input, message, valid) => {
        const feedback = document.getElementById(`${input.id}-feedback`);
        if (!feedback) return;
        feedback.textContent = message;
        feedback.classList.toggle('text-danger', Boolean(message) && !valid);
        feedback.classList.toggle('text-success', Boolean(message) && valid);
        if (message) input.setAttribute('aria-invalid', String(!valid));
        else input.removeAttribute('aria-invalid');
    };
    const validate = () => {
        const value = password.value, missing = [];
        if ([...value].length < 8) missing.push('at least 8 characters');
        if ([...value].length > 72) missing.push('no more than 72 characters');
        if (!/\p{Lu}/u.test(value)) missing.push('an uppercase letter');
        if (!/\p{Ll}/u.test(value)) missing.push('a lowercase letter');
        if (!/\p{N}/u.test(value)) missing.push('a number');
        if (!/[\p{Z}\p{S}\p{P}]/u.test(value)) missing.push('a symbol');
        paint(password, !value ? '' : missing.length ? `Password needs ${missing.join(', ')}.` : 'Password meets all requirements.', !missing.length);
        const matches = Boolean(value) && confirmation.value === value;
        paint(confirmation, !confirmation.value ? '' : matches ? 'Passwords match.' : 'Passwords do not match.', matches);
    };
    [password, confirmation].forEach(input => {
        input.addEventListener('input', () => {clearTimeout(timer); timer = setTimeout(validate, 300);});
        input.addEventListener('blur', () => {clearTimeout(timer); validate();});
        input.addEventListener('change', () => {clearTimeout(timer); validate();});
    });
    form.addEventListener('submit', () => {clearTimeout(timer); validate();});
});
