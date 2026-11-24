<td>
    <div class="small text-secondary fw-normal">{{ $loan?->accession_snapshot ?? $book?->accession_number ?? 'Not recorded' }}</div>
    <div class="bn-text text-primary">
        @php($title = $loan?->title_snapshot ?? $book?->title_bn ?? 'Unavailable book')
        @if ($book && ! $book->trashed())
            <a class="fw-bold" href="{{ route('books.show', $book) }}">{{ $title }}</a>
        @else
            <strong>{{ $title }}</strong>
        @endif
    </div>
    <div class="bn-text text-teal fw-normal">{{ implode(', ', $loan?->authors_snapshot ?? $book?->authors->pluck('title_bn')->all() ?? []) ?: 'Not recorded' }}</div>
    @if ($loan?->exceptional_reason)
        <span class="badge bg-orange-lt mt-2">Exceptional Issue</span>
        <details class="small mt-2">
            <summary>Authorization details</summary>
            <div class="text-secondary mt-1">{{ $loan->exceptional_reason }}</div>
            <div class="text-secondary">Authorized by {{ $loan->issuer_name }} (ID {{ $loan->limit_override_by }})</div>
            <div class="text-secondary">{{ \App\Support\Ui::date($loan->limit_override_at, 'd M Y, h:i A') }}</div>
            <div class="text-secondary">Configured limit: {{ $loan->exceptional_limit }} · Total after issue: {{ $loan->exceptional_total_after }}</div>
        </details>
    @endif
</td>
