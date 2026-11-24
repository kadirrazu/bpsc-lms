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
      data-member-on-loan="{{ $memberOnLoan }}" data-member-pending-requests="{{ $memberPendingRequests }}"
      data-loan-days="{{ $borrowingPolicy->days() }}" data-book-limit="{{ $borrowingPolicy->limit() }}"
      method="post" action="{{ route('loans.store') }}">
    @csrf
    @if (auth()->user()->role === \App\Enums\UserRole::Librarian)
        <div class="exceptional-issue-panel">
            <div class="exceptional-issue-heading">
                <span class="exceptional-issue-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
                <div>
                    <label class="form-check form-switch exceptional-issue-switch">
                        <input class="form-check-input" type="checkbox" name="exceptional_issue" value="1" data-exceptional-toggle
                            aria-describedby="exceptional-issue-help" @checked(old('exceptional_issue'))>
                        <span class="form-check-label">Allow Exceptional Issue</span>
                    </label>
                    <p id="exceptional-issue-help" class="exceptional-issue-help">Authorize additional books or copies for official work. A reason and confirmation are required.</p>
                </div>
            </div>
            <div class="mt-3" data-exceptional-details @unless(old('exceptional_issue')) hidden @endunless>
                <label class="form-label required" for="exceptional-reason">Reason for exceptional issue</label>
                <textarea id="exceptional-reason" class="form-control" name="exceptional_reason" data-exceptional-reason
                    rows="2" maxlength="1000" @disabled(!old('exceptional_issue')) @required(old('exceptional_issue'))>{{ old('exceptional_reason') }}</textarea>
            </div>
        </div>
    @endif
    <div class="loan-desk-grid">
        <div class="loan-desk-search">
            @foreach (['member' => ['Member', 'members', $member?->id, $memberLabel, 'Name, email, mobile or membership no.'], 'book' => ['Find a book', 'books', null, '', 'Title, author, accession or call number']] as $field => $picker)
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
            <div class="borrowing-summary" data-member-borrowing-summary role="status" aria-live="polite" hidden>
                <span class="borrowing-badge borrowing-badge-limit">Configured limit: <strong data-borrowing-limit>{{ $borrowingPolicy->limit() }}</strong></span>
                <span class="borrowing-badge borrowing-badge-loan">Currently on loan: <strong data-borrowing-loans>0</strong></span>
                <span class="borrowing-badge borrowing-badge-pending">Pending requests: <strong data-borrowing-pending>0</strong></span>
                <span class="borrowing-badge borrowing-badge-total">Total after issue: <strong data-borrowing-total>0</strong></span>
            </div>
            <div class="loan-desk-info">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <p class="mb-0">Select an <strong>accession number</strong> for each book you issue. A member can have up to <strong>{{ $borrowingPolicy->limit() }} different books</strong> on loan.</p>
            </div>
        </div>
        <section class="loan-desk-selection" aria-labelledby="loan-selection-title">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <h2 id="loan-selection-title" class="h3 mb-0">Selected books</h2>
                <span class="badge bg-blue-lt" data-loan-count aria-live="polite">{{ $books->count() }} selected</span>
            </div>
            <p class="text-secondary small mt-2 mb-0" data-loan-empty @if($books->isNotEmpty()) hidden @endif>Choose a search result to add a book.</p>
            <ol class="list-unstyled mb-0" data-loan-selection data-copy-url-template="{{ route('loans.copy-options', ['book' => 'BOOK_ID']) }}">
                @foreach ($books as $selected)
                    <li class="loan-desk-book" data-loan-book data-available-copies="{{ max(0, $selected->number_of_copies - (int) $selected->active_copies) }}">
                        <span class="loan-desk-serial" data-loan-serial aria-label="Serial {{ $loop->iteration }}">{{ $loop->iteration }}</span>
                        <div class="loan-desk-book-details">
                            <div class="bn-text fw-semibold" data-loan-book-label>{{ $selected->title_bn }}</div>
                            <div class="bn-text small text-secondary" data-loan-book-authors>{{ $selected->authors->pluck('title_bn')->implode(', ') ?: 'Author not recorded' }}</div>
                            <div class="small text-secondary mt-1" data-loan-copy-counts>
                                Available: {{ max(0, $selected->number_of_copies - (int) $selected->active_copies) }} · Issuing: 1
                            </div>
                            <div id="copy-picker-{{ $selected->id }}" data-copy-picker data-copy-eager data-copy-required
                                data-copy-url="{{ route('loans.copy-options', $selected) }}"
                                data-copy-name="copy_accessions[{{ $selected->id }}]"
                                data-copy-selected="{{ old('copy_accessions.'.$selected->id) }}"></div>
                            <div data-additional-copies>
                                @foreach ((array) old('additional_copy_accessions.'.$selected->id, []) as $copyIndex => $number)
                                    <div class="mt-3" data-additional-copy>
                                        <div id="additional-{{ $selected->id }}-{{ $copyIndex }}" data-copy-picker data-copy-eager data-copy-required
                                            data-copy-url="{{ route('loans.copy-options', $selected) }}"
                                            data-copy-name="additional_copy_accessions[{{ $selected->id }}][]"
                                            data-copy-selected="{{ $number }}"></div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-remove-copy>Remove copy</button>
                                    </div>
                                @endforeach
                            </div>
                            @if (auth()->user()->role === \App\Enums\UserRole::Librarian)
                                <button type="button" class="btn btn-sm btn-outline-primary mt-3" data-add-copy hidden>Add another copy</button>
                            @endif
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
