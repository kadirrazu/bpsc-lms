@extends('layouts.member')
@section('title','Member Home')
@section('content')
<section class="member-welcome mb-4"><span class="eyebrow">YOUR LIBRARY, YOUR SPACE</span><h1>Welcome, {{ auth()->user()->name }}.</h1><span class="badge bg-teal-lt mb-2">Membership No. {{ auth()->user()->membership_number }}</span><p>Explore the library catalog and access the services available to you.</p><div class="d-flex flex-wrap gap-2 mt-4"><a class="btn btn-primary" href="{{ route('member.catalog') }}"><i class="bi bi-search"></i>Explore Catalog</a><a class="btn btn-outline-primary" href="{{ route('member.requests') }}">My Requests</a><a class="btn btn-outline-primary" href="{{ route('member.library-card') }}"><i class="bi bi-person-vcard" aria-hidden="true"></i>Download Library Card</a></div></section>
@if($reminders->isNotEmpty())<section class="card card-body mb-4 border-warning"><h2><i class="bi bi-bell me-2" aria-hidden="true"></i>Book Return Reminders</h2>@foreach($reminders as $reminder)<div class="alert alert-warning mb-2"><strong class="bn-text">{{ $reminder->loan->title_snapshot??$reminder->loan->book?->title_bn }}</strong> ({{ $reminder->loan->accession_snapshot??$reminder->loan->book?->accession_number }})<div class="mt-1">Please return this book to the library. Due: {{ \App\Support\Ui::date($reminder->loan->due_at,'d M Y, h:i A') }}.</div><div class="small mt-1">Reminder sent: {{ \App\Support\Ui::date($reminder->sent_at,'d M Y, h:i A') }}</div></div>@endforeach<a class="small" href="{{ route('member.requests',['loan_status'=>'on_loan']) }}">View my issued books</a></section>@endif
<section class="card mb-4" aria-labelledby="member-loans-title">
    <div class="card-header">
        <h2 class="card-title" id="member-loans-title"><i class="bi bi-journal-bookmark me-2" aria-hidden="true"></i>My Books on Loan</h2>
    </div>
    @if ($loans->isEmpty())
        <p class="card-body text-secondary mb-0">You have no books on loan.</p>
    @else
        <div class="table-responsive">
            <table class="table table-vcenter mb-0" aria-labelledby="member-loans-title">
                <thead>
                    <tr>
                        <th scope="col">Book</th>
                        <th scope="col" class="text-center">Copies</th>
                        <th scope="col">Due date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($loans as $loan)
                        @php($title = $loan->book?->title_bn ?? $loan->title_snapshot ?? 'Unavailable book')
                        <tr>
                            <td>
                                @if ($loan->book && ! $loan->book->trashed())
                                    <a class="bn-text fw-semibold" href="{{ route('member.books.show', $loan->book) }}">{{ $title }}</a>
                                @else
                                    <span class="bn-text fw-semibold">{{ $title }}</span>
                                @endif
                                <div class="text-secondary small">{{ $loan->accession_snapshot ?? $loan->book?->accession_number }}</div>
                            </td>
                            <td class="text-center">{{ $loan->quantity }}</td>
                            <td>{{ \App\Support\Ui::date($loan->due_at) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
<div class="row g-4"><div class="col-md-6"><a class="card card-body library-link" href="{{ route('member.catalog') }}"><i class="bi bi-journals"></i><h2>Find your next book</h2><p>Search Bengali or English titles and discover books by author, publisher or category.</p><strong>Visit catalog <i class="bi bi-arrow-right"></i></strong></a></div><div class="col-md-6"><a class="card card-body library-link" href="{{ route('member.requests') }}"><i class="bi bi-bookmark-check"></i><h2>Keep track of requests</h2><p>Track your issue requests, issued books and returns.</p><strong>View requests <i class="bi bi-arrow-right"></i></strong></a></div></div>
@endsection
