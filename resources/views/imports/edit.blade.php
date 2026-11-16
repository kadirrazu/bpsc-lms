@extends('layouts.app')
@section('title','Edit Import Row '.$row)
@section('content')
<div class="catalog-heading"><div><span class="eyebrow">IMPORT PREVIEW</span><h1>Edit Row {{ $row }}</h1><p class="text-secondary mb-0">Changes update this preview only. No catalog records are saved yet.</p></div><a class="btn btn-outline-secondary" href="{{ route('catalog-import.preview',[$kind,$token]) }}">Back to Preview</a></div>
@if($rowErrors)<div class="alert alert-warning"><ul class="mb-0 ps-3">@foreach($rowErrors as $error)<li>{{ $error }}</li>@endforeach
@if ($kind === 'books')
<div class="col-md-6">
    <label class="form-label" for="printed_currency">Printed Price Currency</label>
    <select class="form-select" id="printed_currency" name="printed_currency">
        @foreach (\App\Models\Book::PRINTED_CURRENCIES as $code => $label)
            <option value="{{ $code }}" @selected(old('printed_currency', $data['printed_currency'] ?? 'BDT') === $code)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="col-12">
    <label class="form-label" for="import-additional-accessions">Additional Copy Accession Numbers</label>
    <textarea class="form-control" id="import-additional-accessions" name="additional_accessions" rows="3" maxlength="50500">{{ old('additional_accessions', $data['additional_accessions'] ?? '') }}</textarea>
    <small class="form-hint">Separate numbers with semicolons, starting with copy 2.</small>
</div>
@endif
</ul></div>@endif
<form class="card" method="post" action="{{ route('catalog-import.update',[$kind,$token,$row]) }}">@csrf @method('PUT')<input type="hidden" name="revision" value="{{ $revision }}"><div class="card-body"><div class="row g-4">
@foreach($kind==='books'?['accession_number'=>'Accession Number','title_bn'=>'Title (Bengali)','title_en'=>'Title (English)','call_number'=>'Call Number','shelf_number'=>'Shelf Number','publication_year'=>'Publication Year','number_of_copies'=>'Number of Copies','price'=>'Purchase Price (BDT)','printed_price'=>'Printed Price']:['title_bn'=>'Name (Bengali)','title_en'=>'Name (English)'] as $field=>$label)
<div class="col-md-6"><label class="form-label {{ in_array($field,['accession_number','title_bn','call_number','number_of_copies'])?'required':'' }}" for="{{ $field }}">{{ $label }}</label><input class="form-control {{ $field==='title_bn'?'bn-text':'' }}" id="{{ $field }}" name="{{ $field }}" value="{{ old($field,$data[$field]??'') }}" maxlength="{{ $field==='accession_number'||$field==='call_number'?100:255 }}" @required(in_array($field,['accession_number','title_bn','call_number','number_of_copies']))></div>
@endforeach
@if($kind==='books')<noscript><div class="alert alert-info">Enable JavaScript to search and select related records.</div></noscript>
<div class="col-md-6">@include('books.multi-picker',['field'=>'author','label'=>'Authors','kind'=>'authors','selections'=>$authorSelections])</div>
<div class="col-md-6">@include('books.picker',['field'=>'publisher','label'=>'Publisher','kind'=>'publishers','selection'=>$publisherSelection])</div>
<div class="col-12">@include('books.multi-picker',['field'=>'category','label'=>'Categories','kind'=>'categories','selections'=>$categorySelections])</div>
<div class="col-md-6"><label class="form-label" for="collection_method">Collection Method</label><select class="form-select" id="collection_method" name="collection_method"><option value="">Not specified</option>@foreach(['Purchase','Gift','Others'] as $method)<option value="{{ $method }}" @selected(old('collection_method',$data['collection_method']??'')===$method)>{{ $method }}</option>@endforeach</select></div>@endif
</div></div><div class="card-footer d-flex gap-2"><button class="btn btn-primary">Save Preview Row</button><a class="btn btn-outline-secondary" href="{{ route('catalog-import.preview',[$kind,$token]) }}">Cancel</a></div></form>
@endsection
