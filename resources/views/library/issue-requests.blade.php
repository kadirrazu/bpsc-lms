@extends('layouts.app')
@section('title','Book Issue Requests')
@push('scripts')<script src="{{ asset('js/copy-accession-picker.js') }}?v={{ config('library.version') }}" defer></script>@endpush
@section('content')
@include('loans.copy-picker-template')
@php
    $issueReceiptAvailable = Route::has('loans.receipt');
    $returnReceiptAvailable = Route::has('loans.return-receipt');
@endphp
@if ($issueReceiptAvailable && session('issue_receipt_loan'))
    <div class="mb-3">
        <a class="btn btn-outline-primary" href="{{ route('loans.receipt', session('issue_receipt_loan')) }}">
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Download Issue Receipt
        </a>
    </div>
@endif
@if ($returnReceiptAvailable && session('return_receipt_loan'))
    <div class="mb-3">
        <a class="btn btn-outline-primary" href="{{ route('loans.return-receipt', session('return_receipt_loan')) }}">
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Download Return Receipt
        </a>
    </div>
@endif
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="mb-1">Book Issue Requests</h1>
        <p class="text-secondary mb-0">Review member requests, withdrawals and issued books.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-teal" href="{{ route('issue-register.index') }}">
            <i class="bi bi-journal-text" aria-hidden="true"></i>Issue Register
        </a>
        <a class="btn btn-primary" href="{{ route('loans.create') }}">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Issue Book
        </a>
    </div>
</div>
<form class="card card-body mb-4" method="get" action="{{ route('issue-requests.index') }}"><div class="row g-3 align-items-start"><div class="col-12 col-lg-6"><label class="form-label" for="request-search">Search requests</label><input class="form-control" type="search" id="request-search" name="q" value="{{ $search }}" maxlength="150" placeholder="Book, accession number, member or contact details"><small class="form-hint">Search book titles, accession/call numbers, member name, designation, unit, email or mobile.</small></div><div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="request-status">Issue Status</label><select class="form-select" id="request-status" name="status"><option value="">All statuses</option>@foreach(['pending'=>'Pending','approved'=>'Approved (all)','on_loan'=>'On Loan','returned'=>'Returned','rejected'=>'Rejected','withdrawn'=>'Withdrawn'] as $value=>$label)<option value="{{ $value }}" @selected($status===$value)>{{ $label }}</option>@endforeach</select></div><div class="col-12 col-sm-6 col-lg-3"><div class="form-label d-none d-sm-block" aria-hidden="true">&nbsp;</div><div class="filter-actions"><button class="btn btn-primary"><i class="bi bi-search" aria-hidden="true"></i>Search</button><a class="btn btn-outline-secondary" href="{{ route('issue-requests.index') }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Clear</a></div></div></div></form>
<div class="card"><div class="table-responsive"><table class="table table-vcenter"><thead><tr>@include('loans.book-column-heading')<th>Member</th><th class="text-center align-middle">Requested</th><th class="text-center align-middle">Status / Activity</th><th>Actions</th></tr></thead><tbody>@forelse($requests as $entry)<tr>@include('loans.book-column-cell', ['book' => $entry->book, 'loan' => $entry->loan])<td><strong>{{ $entry->user?->name ?? 'Unavailable member' }}</strong><div class="small text-secondary">{{ $entry->user?->designation?->name ?? 'Not assigned' }}, {{ \App\Support\Ui::unit($entry->user?->unit) }}</div><div class="small">{{ $entry->user?->email }}</div>@if($entry->user?->mobile_number)<div class="small">{{ $entry->user->mobile_number }}</div>@endif</td><td class="text-center align-middle small">{{ \App\Support\Ui::date($entry->created_at,'d M Y, h:i A') }}<div class="mt-2"><span class="badge {{ $entry->source==='staff_issue' ? 'bg-purple-lt':'bg-cyan-lt' }}">{{ $entry->source==='staff_issue' ? 'Direct Issue':'Member Request' }}</span></div></td><td class="text-center align-middle"><span class="badge {{ $entry->status==='approved' ? 'bg-green-lt':($entry->status==='withdrawn' ? 'bg-secondary-lt':($entry->status==='rejected' ? 'bg-red-lt':'bg-blue-lt')) }}">{{ ucfirst($entry->status) }}</span>@if($entry->withdrawn_at)<div class="small text-secondary mt-1">Withdrawn by member<br>{{ \App\Support\Ui::date($entry->withdrawn_at,'d M Y, h:i A') }}</div>@endif @if($entry->loan)<div class="small text-secondary mt-1">Issued: {{ \App\Support\Ui::date($entry->loan->issued_at,'d M Y, h:i A') }}</div><div class="small text-secondary">Due: {{ \App\Support\Ui::date($entry->loan->due_at) }}</div><div class="mt-2"><span class="badge {{ $entry->loan->returned_at ? 'bg-secondary-lt':'bg-blue-lt' }}">{{ $entry->loan->returned_at ? 'Returned':'On Loan' }}</span></div>@endif</td><td>
    @if ($entry->status === 'pending')
        <form method="post" action="{{ route('issue-requests.review', $entry) }}">
            @csrf
            @if ($entry->book && ! $entry->book->trashed() && ! $entry->book->isSoftCopy())
                <div class="mb-2" id="request-copy-{{ $entry->id }}" data-copy-picker data-copy-inline
                     data-copy-url="{{ route('loans.copy-options', $entry->book) }}" data-copy-name="copy_accession"
                     data-copy-selected="{{ old('copy_accession') }}"></div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-primary" name="decision" value="approve">Approve &amp; Issue</button>
                    <button class="btn btn-sm btn-outline-danger" name="decision" value="reject" formnovalidate>Reject</button>
                </div>
            @else
                <button class="btn btn-sm btn-outline-danger" name="decision" value="reject">Reject</button>
            @endif
        </form>
    @elseif ($entry->loan && ! $entry->loan->returned_at)
        <form method="post" action="{{ route('loans.return', $entry->loan) }}">
            @csrf
            <button class="btn btn-sm btn-outline-primary">Mark Returned</button>
        </form>
    @endif
    @if ($entry->loan)
        <div class="d-flex flex-wrap gap-2 mt-2">
            @if ($issueReceiptAvailable)
                <a class="btn btn-sm btn-outline-secondary text-nowrap" href="{{ route('loans.receipt', $entry->loan) }}">Issue Receipt</a>
            @endif
            @if ($returnReceiptAvailable && $entry->loan->returned_at)
                <a class="btn btn-sm btn-outline-teal text-nowrap" href="{{ route('loans.return-receipt', $entry->loan) }}">Return Receipt</a>
            @endif
        </div>
    @endif
</td></tr>@empty<tr><td colspan="5" class="py-4 text-center text-secondary">No matching requests. Try another search or clear the filters.</td></tr>@endforelse</tbody></table></div></div><div class="mt-4">@include('shared.pagination',['paginator'=>$requests])</div>
@endsection
