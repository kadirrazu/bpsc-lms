@inject('borrowingPolicy', 'App\Services\Library\BorrowingPolicy')
@extends('layouts.app')
@section('title',$book->title_bn)
@push('styles')<link rel="stylesheet" href="{{ asset('css/book-delete.css') }}?v={{ config('library.version') }}">@endpush
@push('scripts')<script src="{{ asset('js/book-delete.js') }}?v={{ config('library.version') }}" defer></script>@endpush
@section('content')
<div class="catalog-heading"><div><span class="eyebrow">BOOK DETAILS · {{ $book->accession_number }}</span><h1 class="bn-text">{{ $book->title_bn }}</h1></div><div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary" href="{{ route($member ? 'member.catalog':'books.index') }}">Back to {{ $member ? 'Catalog':'Books' }}</a>@unless($member)@unless($book->isSoftCopy())<a class="btn btn-outline-primary" href="{{ route('loans.create',['book_id'=>$book->id]) }}"><i class="bi bi-box-arrow-up-right"></i>Issue Book</a>@endunless<a class="btn btn-primary" href="{{ route('books.edit',$book) }}"><i class="bi bi-pencil-square"></i>Edit Book</a>@endunless</div></div>
@if($book->isSoftCopy())
<div class="card card-body mb-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div><span class="badge bg-purple-lt">Soft Copy · {{ strtoupper($book->digitalExtension()) }}</span> <span class="text-secondary ms-2">{{ number_format($book->download_count) }} downloads</span></div>
        @if($member && $book->pdf_path)
            <a class="btn btn-primary" href="{{ route('member.books.download', $book) }}"><i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Download {{ strtoupper($book->digitalExtension()) }}</a>
        @endif
    </div>
</div>
@elseif($member) @if($memberLoan)<div class="alert alert-success d-flex flex-wrap gap-3 align-items-center justify-content-between mb-3" role="status"><div><strong><i class="bi bi-journal-check me-2" aria-hidden="true"></i>This book is currently issued to you.</strong><div class="small mt-1">Issued: {{ \App\Support\Ui::date($memberLoan->issued_at,'d M Y, h:i A') }}. Due: {{ \App\Support\Ui::date($memberLoan->due_at) }}. You can request it again after it is marked as returned.</div></div><a class="btn btn-sm btn-outline-success" href="{{ route('member.requests',['loan_status'=>'on_loan']) }}">View My Issued Books</a></div>@else<div class="card card-body mb-3"><div class="d-flex flex-wrap gap-3 align-items-center justify-content-between"><span>Request one copy for {{ $borrowingPolicy->days() }} days. The limit is {{ $borrowingPolicy->limit() }} books, including pending requests. Library staff will review your request.</span><form method="post" action="{{ route('member.books.request',$book) }}">@csrf<button class="btn btn-primary" @disabled($issued >= $book->number_of_copies)>Request This Book</button></form></div></div>@endif @endif
<div class="row g-4"><div class="col-md-4 col-lg-3"><div class="card card-body"><div class="book-detail-cover">@if($book->cover_path)<img src="{{ route('books.cover',$book) }}" alt="{{ $book->title_bn }}">@else<div class="cover-placeholder"><i class="bi bi-book"></i><span>No cover image</span></div>@endif</div></div></div><div class="col-md-8 col-lg-9"><div class="card card-body">@unless($book->isSoftCopy())<div class="d-flex gap-2 mb-4"><span class="badge bg-blue-lt">{{ $book->number_of_copies }} total copies</span><span class="badge bg-green-lt">{{ max(0,$book->number_of_copies-$issued) }} available</span><span class="badge bg-orange-lt">{{ $issued }} issued</span></div>@endunless<dl class="book-facts">
<div><dt>Printed Price</dt><dd>{{ $book->printed_price !== null ? $book->printed_price.' '.\App\Models\Book::PRINTED_CURRENCIES[$book->printed_currency] : 'Not specified' }}</dd></div>
<div><dt>Purchase Price</dt><dd>{{ $book->price !== null ? '৳ '.$book->price.' (BDT)' : 'Not specified' }}</dd></div><div><dt>Collection Method</dt><dd>{{ $book->collection_method ?? 'Not specified' }}</dd></div>
@unless($book->isSoftCopy())<div><dt>Total Times Issued</dt><dd>{{ number_format($book->total_issue_count) }}</dd></div>@endunless
<div><dt>Authors</dt><dd class="d-flex flex-wrap gap-2">@foreach($book->authors as $entry)<a class="bn-text" href="{{ route('catalog.entity',['kind'=>'authors','entry'=>$entry->id]) }}">{{ $entry->title_bn }}</a>@endforeach</dd></div>
<div><dt>Publisher</dt><dd><a class="bn-text" href="{{ route('catalog.entity',['kind'=>'publishers','entry'=>$book->publisher_id]) }}">{{ $book->publisher->title_bn }}</a></dd></div>
<div><dt>Categories</dt><dd class="d-flex flex-wrap gap-2">@foreach($book->categories as $entry)<a class="bn-text" href="{{ route('catalog.entity',['kind'=>'categories','entry'=>$entry->id]) }}">{{ $entry->title_bn }}</a>@endforeach</dd></div>
<div><dt>Accession Number</dt><dd>{{ $book->accession_number }}</dd></div>@unless($book->isSoftCopy())<div><dt>Call Number</dt><dd>{{ $book->call_number }}</dd></div><div><dt>Shelf Number</dt><dd>{{ $book->shelf_number ?? '—' }}</dd></div>@endunless<div><dt>Publication Year</dt><dd>{{ $book->publication_year ?? '—' }}</dd></div>
</dl>
@if (! $book->isSoftCopy() && $book->copies->isNotEmpty())
    <section class="mt-4 pt-3 border-top" aria-labelledby="copy-accessions-title">
        <h2 class="h3 mb-2" id="copy-accessions-title">Copy Accession Numbers</h2>
        <div class="d-flex flex-wrap gap-2">
            @foreach ($book->copies as $copy)
                <span class="badge {{ $copy->on_loan ? 'bg-orange-lt' : 'bg-blue-lt' }}">{{ $copy->accession_number }}@if($copy->on_loan) · On loan @endif</span>
            @endforeach
        </div>
        @if ($book->copies->count() < $book->number_of_copies && ! $member)
            <p class="small text-secondary mt-2 mb-0">Some existing copies do not have an individual accession number. Add their numbers in Edit Book.</p>
        @endif
    </section>
@endif
@if ($borrowers?->isNotEmpty())
<section class="mt-4 pt-3 border-top" aria-labelledby="on-loan-title">
    <h2 class="h3 mb-2" id="on-loan-title">Copies on Loan</h2>
    <div class="table-responsive">
        <table class="table table-vcenter mb-0" aria-labelledby="on-loan-title">
            <thead><tr><th scope="col">#</th><th scope="col">Member</th><th scope="col">Issued On</th><th scope="col">Copy Accession</th><th scope="col" class="text-center">Copies on Loan</th></tr></thead>
            <tbody>
            @foreach ($borrowers as $borrower)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        <strong>{{ $borrower->user?->name ?? 'Unavailable member' }}</strong>
                        @if ($borrower->user)
                            <div class="text-secondary small">{{ $borrower->user->designation?->name ?? 'Not assigned' }} · {{ \App\Support\Ui::unit($borrower->user->unit) }}</div>
                        @endif
                    </td>
                    <td>{{ \App\Support\Ui::date($borrower->first_issued_at, 'd M Y, h:i A') }}</td>
                    <td>{{ $loanAccessions->get($borrower->user_id)?->pluck('accession_snapshot')->implode(', ') ?: '—' }}</td>
                    <td class="text-center">{{ number_format((int) $borrower->copies) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif
</div>@unless($member)@can('delete-library')<div class="card card-body mt-4 delete-panel"><div><h2>Delete Book</h2><p class="text-secondary mb-0">Issued books cannot be deleted. The cover file is removed when a book is deleted.</p></div>@if($issued>0)<span class="badge bg-orange-lt">Return all issued copies first</span>@else
    @include('books.delete-confirmation')
@endif
</div>@endcan @endunless</div></div>
@can('view-book-activity')
<section class="card mt-4"><div class="card-body"><div class="d-flex justify-content-between align-items-center gap-3 mb-4"><div><h2 class="mb-1">Book Activity</h2><span class="text-secondary small">Latest 20 recorded events. Times use Bangladesh time.</span></div>@can('view-logs')<a class="btn btn-sm btn-outline-secondary" href="{{ route('audit-logs.index',['subject_type'=>'Book','subject_id'=>$book->id]) }}">All Book Logs</a>@endcan</div>
<div class="book-activity">@forelse($activity as $event)<div class="activity-item"><span class="activity-dot"></span><div><strong>{{ ['book.pdf_downloaded'=>'PDF downloaded','book.epub_downloaded'=>'EPUB downloaded','book.copy_accessions_updated'=>'Copy accession numbers updated','book.created'=>'Book added','book.updated'=>'Book edited','book.relationships_changed'=>'Authors or categories updated','book.issued'=>'Book issued','book.returned'=>'Book returned','book.issue_updated'=>'Issue record updated','book.issue_deleted'=>'Issue record removed'][$event->action] ?? $event->action }}</strong><p class="text-secondary small mb-1">{{ $event->actor_name ?? 'System' }} · {{ \App\Support\Ui::date($event->created_at,'d M Y, h:i A') }}</p><p class="small mb-1">@if($event->details['member_name'] ?? null)Member: {{ $event->details['member_name'] }} · @endif @if(isset($event->details['after']['quantity']))Copies: {{ $event->details['after']['quantity'] }}@endif</p><details><summary class="small">View changes</summary><pre class="audit-details mt-2">{{ json_encode($event->details,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></details></div></div>@empty<p class="text-secondary mb-0">No recorded activity.</p>@endforelse</div>
</div></section>
@endcan
@endsection
