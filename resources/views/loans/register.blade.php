@inject('borrowingPolicy', 'App\Services\Library\BorrowingPolicy')
@extends('layouts.app')
@section('title','Issue Register')
@push('styles')<link rel="stylesheet" href="{{ asset('css/loan-desk.css') }}?v={{ config('library.version') }}">@endpush
@push('scripts')<script src="{{ asset('js/circulation.js') }}?v={{ config('library.version') }}" defer></script>@endpush
@section('content')
<noscript><div class="alert alert-info">Enable JavaScript to confirm and submit issue actions.</div></noscript>
<div class="catalog-heading">
    <div>
        <span class="eyebrow">CIRCULATION RECORDS</span>
        <h1>Issue Register</h1>
        <p class="text-secondary mb-0">Review issues, returns, reminders, renewals and fine flags.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="{{ route('loans.create') }}">Issue Book</a>
        <a class="btn btn-outline-primary" href="{{ route('issue-register.export', ['format' => 'pdf'] + $filters) }}">
            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download PDF
        </a>
        <a class="btn btn-outline-success" href="{{ route('issue-register.export', ['format' => 'xlsx'] + $filters) }}">
            <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Download Excel
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('issue-register.export', $filters) }}">Export CSV</a>
    </div>
</div>

<form class="card card-body mb-4" method="get" action="{{ route('issue-register.index') }}">
    <div class="row g-3 align-items-end">
        <div class="col-12 col-lg-4">
            <label class="form-label" for="register-q">Search register</label>
            <input type="search" class="form-control" name="q" id="register-q" value="{{ $filters['q'] ?? '' }}"
                   maxlength="150" placeholder="Accession, title, author, member or issued by">
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label" for="register-status">Loan status</label>
            <select class="form-select" name="status" id="register-status">
                <option value="">All loans</option>
                <option value="on_loan" @selected(($filters['status'] ?? '') === 'on_loan')>On loan</option>
                <option value="returned" @selected(($filters['status'] ?? '') === 'returned')>Returned</option>
            </select>
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label" for="register-fine">Fine status</label>
            <select class="form-select" name="fine" id="register-fine">
                <option value="">All records</option>
                <option value="flagged" @selected(($filters['fine'] ?? '') === 'flagged')>Fine flagged</option>
                <option value="clear" @selected(($filters['fine'] ?? '') === 'clear')>Not flagged</option>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label" for="register-from">Issue date from</label>
            <input type="date" class="form-control" name="from" id="register-from" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label" for="register-to">Issue date through</label>
            <input type="date" class="form-control" name="to" id="register-to" value="{{ $filters['to'] ?? '' }}">
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <label class="form-label" for="register-sort">Sort by</label>
            <select class="form-select" name="sort" id="register-sort">
                @foreach ($sortOptions as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['sort'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-sm-auto">
            <div class="filter-actions">
                <button class="btn btn-primary">Search</button>
                <a class="btn btn-outline-secondary" href="{{ route('issue-register.index') }}">Clear</a>
            </div>
        </div>
    </div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-vcenter mb-0"><thead><tr>@include('loans.book-column-heading')<th>Member</th><th class="text-center">Issue / Due</th><th class="text-center">Return Date & Time</th><th>Issued By</th><th class="text-center">Flags</th><th>Actions</th></tr></thead><tbody>@forelse($loans as $loan)<tr>@include('loans.book-column-cell', ['book' => $loan->book, 'loan' => $loan])<td><strong>{{ $loan->user?->name??'Unavailable member' }}</strong><div class="small text-secondary">{{ $loan->user?->designation?->name??'Not assigned' }}, {{ \App\Support\Ui::unit($loan->user?->unit) }}</div><div class="small">{{ $loan->user?->email }}</div>@if($loan->user?->mobile_number)<div class="small">{{ $loan->user->mobile_number }}</div>@endif</td><td class="text-center align-middle small">{{ \App\Support\Ui::date($loan->issued_at,'d M Y, h:i A') }}<div class="text-secondary mt-1">Due: {{ \App\Support\Ui::date($loan->due_at,'d M Y, h:i A') }}</div><div class="text-secondary">Renewals used: {{ (int)$loan->renewal_count }} of {{ $borrowingPolicy->renewalLimit() }}</div></td><td class="text-center align-middle small">{{ \App\Support\Ui::date($loan->returned_at,'d M Y, h:i A') }}@if(!$loan->returned_at)<span class="badge bg-blue-lt d-block mt-1">On loan</span>@endif</td><td>{{ $loan->issuer_name??$loan->issuer?->name??'Not recorded' }}</td><td class="text-center align-middle">@if($loan->is_fined)<span class="badge bg-red-lt">Fine flagged</span>@else<span class="text-secondary">—</span>@endif @if($loan->reminder)<div class="small text-secondary mt-1">Reminder: {{ \App\Support\Ui::date($loan->reminder->sent_at,'d M Y, h:i A') }}</div>@endif</td><td><div class="d-flex flex-nowrap gap-2">@foreach(['reminder'=>'Reminder','renew'=>'Renew',($loan->is_fined?'clear-fine':'fine')=>($loan->is_fined?'Clear Fine':'Fine')] as $action=>$label) @if(($action==='reminder' && !$loan->returned_at) || ($action==='renew' && !$loan->returned_at && $loan->due_at?->isPast() && $loan->renewal_count < $borrowingPolicy->renewalLimit()) || ($action==='fine' && $loan->due_at?->isPast()) || $action==='clear-fine')<form method="post" action="{{ route('issue-register.action',['loan'=>$loan->id,'action'=>$action]) }}" data-circulation-confirm="{{ $action }}" data-loan-days="{{ $borrowingPolicy->renewalDays() }}" data-book="{{ $loan->title_snapshot??$loan->book?->title_bn }}" data-accession="{{ $loan->accession_snapshot??$loan->book?->accession_number }}" data-member="{{ $loan->user?->name }}" data-due="{{ $loan->due_at?->toIso8601String() }}">@csrf @if($action==='renew')<input type="hidden" name="expected_policy_revision" value="{{ $borrowingPolicy->revision() }}"><input type="hidden" name="expected_renewal_count" value="{{ (int)$loan->renewal_count }}">@endif<button class="btn btn-sm text-nowrap {{ in_array($action,['fine','clear-fine'])?'btn-outline-danger':'btn-outline-primary' }}">{{ $label }}</button></form>@endif @endforeach</div></td></tr>@empty<tr><td colspan="7" class="text-center text-secondary py-5">No matching issue records.</td></tr>@endforelse</tbody></table></div></div><div class="mt-4">@include('shared.pagination',['paginator'=>$loans])</div>
@endsection
