@php($selected = $selection ?? null)
<div class="catalog-picker" data-catalog-picker data-url="{{ route('catalog.options',$kind) }}">
<label class="form-label {{ ($required ?? true) ? 'required':'' }}" for="{{ $field }}-search">{{ $label }}</label>
<input type="hidden" name="{{ $field }}_id" value="{{ $selected?->id }}" data-picker-value>
<div class="picker-input-wrap"><input class="form-control bn-text" id="{{ $field }}-search" type="search" value="{{ $selected?->title_bn }}" placeholder="Search Bengali or English names" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $field }}-options" autocomplete="off" @required($required ?? true) data-picker-input><button type="button" class="picker-clear" aria-label="Clear {{ strtolower($label) }}" data-picker-clear><i class="bi bi-x-lg"></i></button></div>
<div class="picker-options" id="{{ $field }}-options" role="listbox" aria-label="{{ $label }} results" hidden></div><small class="form-hint picker-status" role="status" aria-live="polite"></small>
@if($allowCreate ?? true) @can('manage-library')<a class="small" href="{{ route($kind.'.create') }}" target="_blank" rel="noopener">Add new {{ strtolower($label) }} <i class="bi bi-box-arrow-up-right"></i></a>@endcan @endif
</div>
