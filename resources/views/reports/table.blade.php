@php
    $alignment = fn ($key) => \App\Services\Library\LibraryReports::centered($report, $key)
        ? 'text-center align-middle'
        : (\App\Services\Library\LibraryReports::numeric($key) ? 'numeric text-end' : 'text-start');
@endphp
<table class="table table-vcenter mb-0">
    <thead>
        <tr>
            @foreach ($columns as $key => $label)
                <th scope="col" @if(isset($widths[$key])) style="width: {{ $widths[$key] }}" @endif class="{{ $alignment($key) }}">{{ $label }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                @foreach ($columns as $key => $label)
                    @php($value = $row->{$key} ?? null)
                    <td @if($report === 'annual' && !($pdf ?? false)) data-column-label="{{ $label }}" @endif class="{{ $alignment($key) }}{{ is_string($value) && preg_match('/\p{Bengali}/u', $value) ? ' bengali-value' : '' }}">
                        @if ($value === null || $value === '')
                            —
                        @elseif (\App\Services\Library\LibraryReports::numeric($key))
                            {{ number_format((float) $value, \App\Services\Library\LibraryReports::money($key) ? 2 : 0) }}
                        @elseif ($report === 'members' && $key === 'member' && !($pdf ?? false))
                            <a href="{{ route('reports.index', ['report' => 'member-loans', 'member' => $row->member_id]) }}">{{ $value }}</a>
                        @elseif ($key === 'status' && in_array($report, ['members', 'member-loans'], true))
                            <span class="badge {{ match ($value) {
                                'Active', 'Returned' => 'bg-green-lt',
                                'Inactive', 'Overdue' => 'bg-red-lt',
                                default => 'bg-blue-lt',
                            } }}">{{ $value }}</span>
                        @elseif ($report === 'issue-register' && $key === 'book')
                            {!! nl2br(e($value)) !!}
                        @else
                            {{ $value }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($columns) }}" class="text-secondary{{ $report === 'circulation' ? ' text-center align-middle' : '' }}">No records match this report.</td></tr>
        @endforelse
    </tbody>
    @isset($totals)
        <tfoot>
            <tr>
                @foreach ($columns as $key => $label)
                    @php($value = $totals[$key] ?? null)
                    <td @if($report === 'annual' && !($pdf ?? false)) data-column-label="{{ $label }}" @endif
                        class="fw-bold {{ $alignment($key) }}">
                        {{ is_numeric($value) ? number_format($value, \App\Services\Library\LibraryReports::money($key) ? 2 : 0) : $value }}
                    </td>
                @endforeach
            </tr>
        </tfoot>
    @endisset
</table>
