@extends('layouts.app')
@section('title',($entry->exists ? 'Edit ':'Add ').$singular)
@section('content')
<div class="catalog-heading"><div><span class="eyebrow">CATALOG MANAGEMENT</span><h1>{{ $entry->exists ? 'Edit':'Add' }} {{ $singular }}</h1><p class="text-secondary mb-0">Bengali is the display name. Both languages are searchable.</p></div><a class="btn btn-outline-secondary" href="{{ route($kind.'.index') }}">Back to {{ $plural }}</a></div>
<form class="card card-body catalog-editor" method="post" action="{{ $entry->exists ? route($kind.'.update',$entry->id) : route($kind.'.store') }}">@csrf @if($entry->exists)@method('PUT')@endif
<div class="row g-4"><div class="col-md-6"><label class="form-label required" for="title_bn">Name (Bengali)</label><input class="form-control bn-text bilingual-input" lang="bn" id="title_bn" name="title_bn" maxlength="255" value="{{ old('title_bn',$entry->title_bn) }}" required autofocus><small class="form-hint">Shown in the catalog and book forms.</small></div><div class="col-md-6"><label class="form-label" for="title_en">Name (English) <span class="text-secondary">— optional</span></label><input class="form-control bilingual-input" id="title_en" name="title_en" maxlength="255" value="{{ old('title_en',$entry->title_en) }}"></div></div><div class="d-flex gap-2 mt-4"><button class="btn btn-primary"><i class="bi bi-check2"></i>Save {{ $singular }}</button><a class="btn btn-outline-secondary" href="{{ route($kind.'.index') }}">Cancel</a></div>
</form>
@endsection
