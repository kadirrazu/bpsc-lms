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
</td>
