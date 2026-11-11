@extends('layouts.app')
@section('title', 'Borrowing Settings')
@section('content')
<div class="mb-4">
    <h1>Borrowing Settings</h1>
    <p class="text-secondary">Set the borrowing limit, initial loan period and renewal policy.</p>
</div>
<form class="card" method="post" action="{{ route('borrowing-settings.update') }}">
    @csrf
    @method('PUT')
    <div class="card-body">
        <div class="row g-4">
            @foreach ([
                'loan_limit' => ['Maximum Books per Member', 1, 100, 'Maximum number of different books a member can have on loan. Pending requests also count when members submit a request.'],
                'loan_days' => ['Initial Loan Period (Days)', 1, 365, 'Number of days assigned when a book is issued.'],
                'renewal_limit' => ['Maximum Renewals per Loan', 0, 100, 'Default 2. Set to 0 to turn off renewals.'],
                'renewal_days' => ['Days per Renewal', 1, 365, 'Default 15. An overdue loan is extended by this many days from the time it is renewed.'],
            ] as $key => [$label, $min, $max, $hint])
                <div class="col-md-6">
                    <label class="form-label required" for="{{ $key }}">{{ $label }}</label>
                    <input class="form-control" type="number" id="{{ $key }}" name="{{ $key }}" min="{{ $min }}" max="{{ $max }}"
                        step="1" value="{{ old($key, $settings[$key]) }}" aria-describedby="{{ $key }}-hint" required>
                    <small class="form-hint" id="{{ $key }}-hint">{{ $hint }}</small>
                </div>
            @endforeach
        </div>
    </div>
    <div class="card-footer"><button class="btn btn-primary">Save Settings</button></div>
</form>
@endsection
