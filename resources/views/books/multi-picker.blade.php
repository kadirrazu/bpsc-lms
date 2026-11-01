<div class="catalog-picker" data-multi-catalog-picker data-url="{{ route('catalog.options',$kind) }}" data-name="{{ $field }}_ids[]">
<label class="form-label required" for="{{ $field }}-search">{{ $label }}</label>
<div class="selected-catalog-items" data-picker-chips>@foreach($selections as $entry)<span class="catalog-chip bn-text" data-picker-chip data-id="{{ $entry->id }}"><span data-chip-label>{{ $entry->title_bn }}</span><input type="hidden" name="{{ $field }}_ids[]" value="{{ $entry->id }}"><button type="button" data-chip-remove aria-label="Remove {{ $entry->title_bn }}"><i class="bi bi-x"></i></button></span>@endforeach</div>
<input class="form-control bn-text" id="{{ $field }}-search" type="search" placeholder="Search Bengali or English names" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $field }}-options" autocomplete="off" @required($selections->isEmpty()) data-picker-input>
<div class="picker-options" id="{{ $field }}-options" role="listbox" aria-label="{{ $label }} results" hidden></div><small class="form-hint picker-status" role="status" aria-live="polite">Select at least one. You can add more than one.</small>
<a class="small" href="{{ route($kind.'.create') }}" target="_blank" rel="noopener">Add new {{ $field }} <i class="bi bi-box-arrow-up-right"></i></a>
</div>
