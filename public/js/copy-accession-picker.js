(() => {
    const template = document.querySelector('#copy-accession-picker-template');
    if (!template) return;

    function setup(root) {
        const fragment = template.content.cloneNode(true);
        const search = fragment.querySelector('[data-copy-search]');
        const options = fragment.querySelector('[data-copy-options]');
        const value = fragment.querySelector('[data-issued-copy]');
        const hint = fragment.querySelector('[data-copy-hint]');
        search.id = `${root.id}-search`;
        options.id = `${root.id}-options`;
        if (root.hasAttribute('data-copy-inline')) options.classList.add('position-static');
        hint.id = `${root.id}-hint`;
        search.setAttribute('aria-controls', options.id);
        if (root.hasAttribute('data-copy-required')) fragment.querySelector('[data-copy-search-label]').classList.add('required');
        search.setAttribute('aria-describedby', hint.id);
        fragment.querySelector('[data-copy-search-label]').htmlFor = search.id;
        value.name = root.dataset.copyName;
        root.append(fragment);

        let accessions = null;
        let loading = false;
        let registrationRequired = false;
        let matches = [];
        let highlighted = -1;

        function close() {
            options.hidden = true;
            search.setAttribute('aria-expanded', 'false');
            search.removeAttribute('aria-activedescendant');
            highlighted = -1;
        }

        function select(number) {
            search.value = number;
            value.value = number;
            search.setCustomValidity('');
            hint.textContent = '';
            close();
        }

        function display() {
            if (accessions === null || search.disabled || registrationRequired) return;
            const term = value.value ? '' : search.value.trim().toLowerCase();
            const otherSelections = [...root.closest('form').querySelectorAll('[data-issued-copy]')]
                .filter(input => input !== value).map(input => input.value);
            matches = accessions.filter(number => number.toLowerCase().includes(term) && !otherSelections.includes(number));
            close();
            options.replaceChildren(...matches.map((number, index) => {
                const option = document.createElement('div');
                option.className = 'picker-option';
                option.id = `${options.id}-${index}`;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.textContent = number;
                option.addEventListener('mousedown', event => event.preventDefault());
                option.addEventListener('click', () => select(number));
                return option;
            }));
            options.hidden = matches.length === 0;
            search.setAttribute('aria-expanded', String(!options.hidden));
            hint.textContent = matches.length ? '' : (accessions.length ? 'No available accessions match this search.' : 'No registered copies are available to issue.');
        }

        async function load() {
            if (accessions !== null || loading) return;
            loading = true;
            hint.textContent = 'Loading available copies…';
            try {
                const response = await fetch(root.dataset.copyUrl, {headers: {Accept: 'application/json'}, cache: 'no-store'});
                if (!response.ok) throw new Error('Copy lookup failed');
                const result = await response.json();
                accessions = result.accessions;
                if (result.legacyStock && root.hasAttribute('data-copy-required')) {
                    registrationRequired = true;
                    search.placeholder = 'No registered copies available';
                    hint.textContent = 'Register the physical copy on Edit Book before issuing it.';
                    return;
                }
                if (result.legacyStock) {
                    search.required = false;
                    search.disabled = true;
                    search.setCustomValidity('');
                    search.placeholder = 'Unregistered legacy copy';
                    hint.textContent = 'This title has available stock without individual accession numbers.';
                    return;
                }
                const initial = root.dataset.copySelected;
                if (!search.value && initial && accessions.includes(initial)) select(initial);
                hint.textContent = accessions.length ? '' : 'No registered copies are available to issue.';
                if (document.activeElement === search) display();
            } catch {
                hint.textContent = 'Could not load copies. Focus this field again to retry.';
            } finally {
                loading = false;
            }
        }

        function open() {
            if (accessions === null) load();
            else display();
        }

        search.addEventListener('focus', open);
        search.addEventListener('click', () => {
            if (options.hidden) open();
        });
        search.addEventListener('blur', close);
        search.addEventListener('input', () => {
            value.value = '';
            search.setCustomValidity(search.value ? 'Choose an available accession from the list.' : '');
            open();
        });
        search.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                close();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (!options.hidden && highlighted >= 0) select(matches[highlighted]);
                else if (!options.hidden && matches.length === 1) select(matches[0]);
                else if (options.hidden) open();
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (options.hidden) open();
                if (options.hidden) return;
                const forward = event.key === 'ArrowDown';
                highlighted = highlighted < 0 ? (forward ? 0 : matches.length - 1)
                    : (highlighted + (forward ? 1 : -1) + matches.length) % matches.length;
                [...options.children].forEach((option, index) => {
                    option.classList.toggle('active', index === highlighted);
                    option.setAttribute('aria-selected', String(index === highlighted));
                });
                const active = options.children[highlighted];
                search.setAttribute('aria-activedescendant', active.id);
                active.scrollIntoView({block: 'nearest'});
            }
        });
        if (root.hasAttribute('data-copy-eager')) load();
    }

    window.setupCopyAccessionPicker = setup;
    document.querySelectorAll('[data-copy-picker]').forEach(setup);
})();
