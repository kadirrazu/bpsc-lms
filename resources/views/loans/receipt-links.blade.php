<a class="btn btn-sm btn-outline-secondary text-nowrap" href="{{ route('loans.receipt', $loan) }}">Issue Receipt</a>
@if ($loan->returned_at)
    <a class="btn btn-sm btn-outline-teal text-nowrap" href="{{ route('loans.return-receipt', $loan) }}">Return Receipt</a>
@endif
