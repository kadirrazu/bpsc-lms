@extends('layouts.app')
@section('title',$book->exists ? 'Edit Book':'Add Book')
@push('scripts')<script src="{{ asset('js/book-format.js') }}?v={{ config('library.version') }}" defer></script>@endpush
@section('content')
<div class="catalog-heading"><div><span class="eyebrow">LIBRARY COLLECTION</span><h1>{{ $book->exists ? 'Edit Book':'Add Book' }}</h1><p class="text-secondary mb-0">Keep book details clear, organized and easy to find.</p></div><a class="btn btn-outline-secondary" href="{{ route('books.index') }}">Back to Books</a></div>
<form method="post" enctype="multipart/form-data" action="{{ $book->exists ? route('books.update',$book) : route('books.store') }}" data-book-form>@csrf @if($book->exists)@method('PUT')@endif
<noscript><div class="alert alert-info">Enable JavaScript to search and select authors, publishers and categories.</div></noscript>
<div class="row g-4"><div class="col-lg-8"><div class="card card-body catalog-editor"><h2 class="mb-4">Book Information</h2><div class="row g-4">
<div class="col-md-6">
    <label class="form-label required" for="accession_number">Accession Number</label>
    <input class="form-control" id="accession_number" name="accession_number" value="{{ old('accession_number',$book->accession_number) }}" maxlength="100" required>
    <small class="form-hint">For hard copies, enter the first copy's number here. Leading zeros are preserved.</small>
</div>
<div class="col-md-6" data-hard-copy>
    <label class="form-label required" for="call_number">Call Number</label>
    <input class="form-control" id="call_number" name="call_number" value="{{ old('call_number',$book->call_number) }}" maxlength="100" placeholder="e.g. 800.02.MK" data-format-required>
</div>
<div class="col-12"><label class="form-label required" for="title_bn">Title (Bengali)</label><input class="form-control bn-text bilingual-input" lang="bn" id="title_bn" name="title_bn" value="{{ old('title_bn',$book->title_bn) }}" maxlength="255" required><small class="form-hint">Primary title shown throughout the catalog.</small></div>
<div class="col-12"><label class="form-label" for="title_en">Title (English) <span class="text-secondary">— optional</span></label><input class="form-control bilingual-input" id="title_en" name="title_en" value="{{ old('title_en',$book->title_en) }}" maxlength="255"></div>
<div class="col-md-6">@include('books.multi-picker',['field'=>'author','label'=>'Authors','kind'=>'authors','selections'=>$authorSelections])</div>
<div class="col-md-6">@include('books.picker',['field'=>'publisher','label'=>'Publisher','kind'=>'publishers','selection'=>$publisherSelection])</div>
<div class="col-12">@include('books.multi-picker',['field'=>'category','label'=>'Categories','kind'=>'categories','selections'=>$categorySelections])</div>
<div class="col-md-4" data-hard-copy><label class="form-label" for="shelf_number">Shelf Number <small class="text-secondary">(optional)</small></label><input class="form-control" id="shelf_number" name="shelf_number" value="{{ old('shelf_number',$book->shelf_number) }}" maxlength="100"></div>
<div class="col-md-4"><label class="form-label" for="publication_year">Publication Year <small class="text-secondary">(optional)</small></label><input type="number" class="form-control" id="publication_year" name="publication_year" value="{{ old('publication_year',$book->publication_year) }}" min="1" max="9999" step="1"></div>
<div class="col-md-4" data-hard-copy>
    <label class="form-label required" for="number_of_copies">Number of Copies</label>
    <input type="number" class="form-control" id="number_of_copies" name="number_of_copies" value="{{ old('number_of_copies',$book->number_of_copies) }}" min="1" max="500" step="1" data-format-required>
</div>
<div class="col-12" data-hard-copy data-accessions>
    <label class="form-label" for="additional-accessions">Additional Copy Accession Numbers</label>
    <p class="form-hint mb-2">Separate numbers with semicolons, for example 00102; 00103. Start with copy 2.</p>
    <textarea class="form-control font-monospace" id="additional-accessions" name="additional_accessions" rows="3" maxlength="50500"
        aria-describedby="accession-progress">{{ old('additional_accessions', $book->exists ? $book->copies->reject(fn ($copy) => $copy->accession_number === $book->accession_number)->pluck('accession_number')->implode('; ') : '') }}</textarea>
    <div class="form-hint mt-2" id="accession-progress" role="status"></div>
</div>
<div class="col-md-6">
    <label class="form-label" for="printed_price">Printed Price <small class="text-secondary">(optional)</small></label>
    <div class="d-flex gap-2">
        <input type="number" class="form-control" id="printed_price" name="printed_price" value="{{ old('printed_price',$book->printed_price) }}" min="0" max="9999999999.99" step="0.01">
        <select class="form-select w-auto" name="printed_currency" aria-label="Printed Price Currency">
            @foreach (\App\Models\Book::PRINTED_CURRENCIES as $code => $label)
                <option value="{{ $code }}" @selected(old('printed_currency', $book->printed_currency) === $code)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>
<div class="col-md-6">
    <label class="form-label" for="price">Purchase Price <small class="text-secondary">(optional)</small></label>
    <div class="input-group"><span class="input-group-text">৳ (BDT)</span><input type="number" class="form-control" id="price" name="price" value="{{ old('price',$book->price) }}" min="0" max="9999999999.99" step="0.01"></div>
</div>
<div class="col-md-6"><label class="form-label" for="collection_method">Collection Method <small class="text-secondary">(optional)</small></label><select class="form-select" id="collection_method" name="collection_method"><option value="">Not specified</option>@foreach(['Purchase','Gift','Others'] as $method)<option value="{{ $method }}" @selected(old('collection_method',$book->collection_method)===$method)>{{ $method }}</option>@endforeach</select></div>
</div></div></div>
<div class="col-lg-4">
<div class="card card-body mb-4">
    <label class="form-label" for="book-format">Book format</label>
    <select class="form-select" id="book-format" name="format" @disabled($book->exists)>
        <option value="hard" @selected(old('format', $book->format) === 'hard')>Hard Copy</option>
        <option value="soft" @selected(old('format', $book->format) === 'soft')>Soft Copy (PDF/EPUB)</option>
    </select>
</div>
<div class="card card-body"><h2 class="mb-3">Cover Image</h2><div class="cover-preview mb-3" data-cover-preview>@if($book->cover_path)<img src="{{ route('books.cover',$book) }}" alt="Current book cover" data-current-cover>@else<div class="cover-placeholder"><i class="bi bi-book"></i><span>No cover image</span></div>@endif</div><label class="form-label" for="cover">Upload Cover <span class="text-secondary">(optional)</span></label><input type="file" id="cover" name="cover" class="form-control" accept="image/jpeg,image/png,image/webp" data-cover-file><small class="form-hint mt-2">JPG, PNG or WebP. Up to 5 MB, maximum 6000 × 6000 pixels.</small>@if($book->cover_path)<label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="remove_cover" value="1" @checked(old('remove_cover')) data-remove-cover><span class="form-check-label">Remove current cover</span></label>@endif<p class="small text-secondary mt-3 mb-0">Replacing or deleting a cover removes the old file.</p></div>
<div class="card card-body mt-4" data-soft-copy hidden>
    <h2 class="mb-3">Digital Book File</h2>
    <label class="form-label @if(!$book->pdf_path) required @endif" for="book-pdf">{{ $book->pdf_path ? 'Replace PDF/EPUB' : 'Upload PDF/EPUB' }}</label>
    <input type="file" id="book-pdf" name="pdf" class="form-control" accept=".pdf,.epub,application/pdf,application/epub+zip" @if(!$book->pdf_path) data-format-required @endif>
    @if($book->pdf_path)<p class="form-hint mt-2 mb-0">Current file: {{ strtoupper($book->digitalExtension()) }}</p>@endif
    <p class="form-hint mt-2 mb-0">PDF or EPUB up to 50 MB. Signed-in Library Members can download it.</p>
</div></div></div>
<div class="catalog-save-bar mt-4"><span class="text-secondary small">Fields marked * are required.</span><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="{{ route('books.index') }}">Cancel</a><button class="btn btn-primary"><i class="bi bi-check2"></i>{{ $book->exists ? 'Save Changes':'Add Book' }}</button></div></div>
</form>
@endsection
