@inject('borrowingPolicy', 'App\Services\Library\BorrowingPolicy')
@extends('layouts.app')
@section('title', 'Issue Books')
@push('styles')<link rel="stylesheet" href="{{ asset('css/loan-desk.css') }}?v={{ config('library.version') }}">@endpush
@push('scripts')
    <script src="{{ asset('js/copy-accession-picker.js') }}?v={{ config('library.version') }}" defer></script>
    <script src="{{ asset('js/loan-desk.js') }}?v={{ config('library.version') }}" defer></script>
    <script src="{{ asset('js/circulation.js') }}?v={{ config('library.version') }}" defer></script>
@endpush
@section('content')
@include('loans.copy-picker-template')
<div class="catalog-heading">
    <div><h1>Issue Books</h1><p class="text-secondary mb-0">Select a member and the books to issue.</p></div>
    <a class="btn btn-outline-secondary" href="{{ route('issue-requests.index') }}">Back to Issue Requests</a>
</div>
<noscript><div class="alert alert-info">Enable JavaScript to select books and review the issue.</div></noscript>
<form class="card loan-desk" data-loan-desk data-circulation-confirm="issue"
      data-loan-days="{{ $borrowingPolicy->days() }}" data-book-limit="{{ $borrowingPolicy->limit() }}"
      method="post" action="{{ route('loans.store') }}">
    @csrf
    <div class="loan-desk-grid">
        <div class="loan-desk-search">
            @foreach (['member' => ['Member', 'members', $member?->id, $memberLabel, 'Name, email or mobile number'], 'book' => ['Find a book', 'books', null, '', 'Title, author, accession or call number']] as $field => $picker)
                <div class="catalog-picker" data-catalog-picker data-url="{{ route('loans.options', $picker[1]) }}" data-loan-picker="{{ $field }}">
                    <label class="form-label" for="{{ $field }}-search">{{ $picker[0] }}</label>
                    <input type="hidden" @if($field === 'member') name="member_id" @endif value="{{ $picker[2] }}" data-picker-value>
                    <div class="picker-input-wrap">
                        <input class="form-control form-control-lg" id="{{ $field }}-search" type="search" value="{{ $picker[3] }}"
                            placeholder="{{ $picker[4] }}" maxlength="150" role="combobox" aria-autocomplete="list" aria-expanded="false"
                            aria-controls="{{ $field }}-options" autocomplete="off" data-picker-input @required($field === 'member')>
                        <button type="button" class="picker-clear" aria-label="Clear {{ $field }} search" data-picker-clear><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                    <div class="picker-options" id="{{ $field }}-options" role="listbox" aria-label="{{ $picker[0] }} results" hidden></div>
                    <small class="form-hint picker-status" role="status" aria-live="polite"></small>
                </div>
            @endforeach
            <p class="text-secondary small mb-0">Each selected book adds one copy. A member can have up to {{ $borrowingPolicy->limit() }} different books on loan.</p>
        </div>
        <section class="loan-desk-selection" aria-labelledby="loan-selection-title">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <h2 id="loan-selection-title" class="h3 mb-0">Selected books</h2>
                <span class="badge bg-blue-lt" data-loan-count aria-live="polite">{{ $books->count() }} selected</span>
            </div>
            <p class="text-secondary small mt-2 mb-0" data-loan-empty @if($books->isNotEmpty()) hidden @endif>Choose a search result to add a book.</p>
            <ol class="list-unstyled mb-0" data-loan-selection data-copy-url-template="{{ route('loans.copy-options', ['book' => 'BOOK_ID']) }}">
                @foreach ($books as $selected)
                    <li class="loan-desk-book" data-loan-book>
                        <span class="loan-desk-serial" data-loan-serial aria-label="Serial {{ $loop->iteration }}">{{ $loop->iteration }}</span>
                        <div class="loan-desk-book-details">
                            <div class="bn-text fw-semibold" data-loan-book-label>{{ $selected->title_bn }}</div>
                            <div class="bn-text small text-secondary" data-loan-book-authors>{{ $selected->authors->pluck('title_bn')->implode(', ') ?: 'Author not recorded' }}</div>
                            <div class="small text-secondary mt-1" data-loan-copy-counts>
                                Available: {{ max(0, $selected->number_of_copies - (int) $selected->active_copies) }} · Issuing: 1
                            </div>
                            <div id="copy-picker-{{ $selected->id }}" data-copy-picker data-copy-eager
                                data-copy-url="{{ route('loans.copy-options', $selected) }}"
                                data-copy-name="copy_accessions[{{ $selected->id }}]"
                                data-copy-selected="{{ old('copy_accessions.'.$selected->id) }}"></div>
                        </div>
                        <input type="hidden" name="book_ids[]" value="{{ $selected->id }}">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-remove-loan-book aria-label="Remove {{ $selected->title_bn }}">Remove</button>
                    </li>
                @endforeach
            </ol>
            <p class="text-secondary small mb-0" data-loan-feedback role="status"></p>
        </section>
    </div>
    <div class="loan-desk-footer">
        <span class="text-secondary small">{{ $borrowingPolicy->days() }}-day loan period</span>
        <button class="btn btn-primary" type="submit" data-loan-submit disabled><i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Review &amp; Issue for {{ $borrowingPolicy->days() }} Days</button>
    </div>
</form>
@endsection
