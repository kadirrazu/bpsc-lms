<template id="copy-accession-picker-template">
    <div class="catalog-picker mt-2">
        <label class="form-label small mb-1" data-copy-search-label>Find an available accession</label>
        <div class="picker-input-wrap">
            <input class="form-control form-control-sm" type="search" placeholder="Select or search accession numbers"
                maxlength="100" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" required data-copy-search>
        </div>
        <div class="picker-options" role="listbox" aria-label="Available copy accessions" data-copy-options hidden></div>
        <input type="hidden" data-issued-copy>
        <small class="form-hint" data-copy-hint role="status"></small>
    </div>
</template>
