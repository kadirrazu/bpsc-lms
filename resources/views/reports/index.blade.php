@extends('layouts.app')
@section('title', $title.' | Reports')
@if ($report === 'annual')
    @push('styles')<link rel="stylesheet" href="{{ asset('css/annual-report.css') }}?v={{ config('library.version') }}">@endpush
@endif
@section('content')
<div class="page-header mb-3">
    <div>
        <h1 class="page-title">Reports</h1>
        <p class="text-secondary mb-0">Review circulation, acquisitions, membership and catalog activity.</p>
    </div>
</div>

<nav class="nav nav-pills gap-1 mb-4" aria-label="Library reports">
    @foreach (\App\Services\Library\LibraryReports::TITLES as $key => $name)
        <a class="nav-link {{ ($report === $key || ($report === 'member-loans' && $key === 'members')) ? 'active' : '' }}"
           @if($report === $key) aria-current="page" @endif
           href="{{ route('reports.index', $key) }}">{{ $name }}</a>
    @endforeach
    <a class="nav-link" href="{{ route('issue-register.index') }}">
        <i class="bi bi-journal-text me-1" aria-hidden="true"></i>Issue Register
    </a>
</nav>

<section class="card mb-3" aria-labelledby="report-title">
    <div class="card-header flex-wrap gap-2">
        <h2 class="card-title mb-0 me-auto" id="report-title">{{ $title }}</h2>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary" href="{{ route('reports.export', ['report' => $report, 'format' => 'pdf'] + $filters) }}">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i> Download PDF
            </a>
            <a class="btn btn-outline-success" href="{{ route('reports.export', ['report' => $report, 'format' => 'xlsx'] + $filters) }}">
                <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i> Download Excel
            </a>
        </div>
    </div>

    @if ($report === 'member-loans')
        <div class="card-body">
            <a href="{{ route('reports.index', 'members') }}">Back to Member Directory</a>
        </div>
    @else
    <div class="card-body">
        @if ($report === 'annual')
            <div class="annual-filters">
                <form action="{{ route('reports.index', 'annual') }}" method="get" class="annual-filter-form">
                    <div class="annual-year-field">
                        <label class="form-label" for="year">Calendar year</label>
                        <input class="form-control" type="number" id="year" name="year" min="1900" max="9998" value="{{ $filters['year'] ?? now()->year }}" required>
                    </div>
                    <div class="annual-filter-actions">
                        <button class="btn btn-primary" type="submit">Run Calendar Year</button>
                        <a class="btn btn-outline-secondary" href="{{ route('reports.index', 'annual') }}">Reset</a>
                    </div>
                </form>
                <form action="{{ route('reports.index', 'annual') }}" method="get" class="annual-filter-form annual-range-filter">
                    <div class="annual-date-field">
                        <label class="form-label" for="annual-from">From date</label>
                        <input class="form-control" type="date" id="annual-from" name="from" value="{{ $filters['from'] ?? '' }}" required>
                    </div>
                    <div class="annual-date-field">
                        <label class="form-label" for="annual-to">Through date</label>
                        <input class="form-control" type="date" id="annual-to" name="to" value="{{ $filters['to'] ?? '' }}" required>
                    </div>
                    <div class="annual-filter-actions"><button class="btn btn-primary" type="submit">Run Date Range</button></div>
                </form>
            </div>
            <p class="text-secondary small mt-3 mb-0">
                Reporting period
                @if (isset($filters['year']))
                    {{ $filters['year'] }}
                @else
                    {{ $filters['from'] }} through {{ $filters['to'] }}
                @endif
            </p>
        @else
            <form action="{{ route('reports.index', $report) }}" method="get" class="row g-2 align-items-end">
                @if (in_array($report, ['circulation', 'acquisitions', 'members', 'popular'], true))
                    <div class="col-sm-6 col-lg-auto">
                        <label class="form-label" for="from">From date</label>
                        <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}">
                    </div>
                    <div class="col-sm-6 col-lg-auto">
                        <label class="form-label" for="to">Through date</label>
                        <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}">
                    </div>
                @endif

                @if ($report === 'categories')
                    <div class="col-sm-6 col-lg-4">
                        <label class="form-label" for="category">Category</label>
                        <select class="form-select" id="category" name="category">
                            <option value="">All categories</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((string)($filters['category'] ?? '') === (string)$category->id)>{{ $category->title_bn }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    @php($selection = match($report) {
                        'acquisitions' => ['method', 'Collection method', ['' => 'All methods', 'Purchase' => 'Purchase', 'Gift' => 'Gift', 'Others' => 'Others']],
                        'members' => ['status', 'Member status', ['' => 'All statuses', 'active' => 'Active', 'inactive' => 'Inactive']],
                        'open-loans' => ['scope', 'Loan scope', ['all' => 'All open loans', 'overdue' => 'Overdue only', 'flagged' => 'Fine flagged']],
                        default => null,
                    })
                    @if ($selection)
                        <div class="col-sm-6 col-lg-auto">
                            <label class="form-label" for="{{ $selection[0] }}">{{ $selection[1] }}</label>
                            <select class="form-select" id="{{ $selection[0] }}" name="{{ $selection[0] }}">
                                @foreach ($selection[2] as $value => $label)
                                    <option value="{{ $value }}" @selected((string)($filters[$selection[0]] ?? '') === (string)$value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                @endif

                <div class="col-auto d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Run Report</button>
                    <a class="btn btn-outline-secondary" href="{{ route('reports.index', $report) }}">{{ $report === 'circulation' ? 'Today' : 'Reset' }}</a>
                </div>
            </form>
        @endif
    </div>
    @endif
</section>

@isset($inventoryTotals)
    <div class="card mb-3">
        <div class="card-header"><h2 class="card-title">Catalog Totals</h2></div>
        <div class="table-responsive">
            <table class="table table-vcenter text-center mb-0">
                <thead><tr><th scope="col" class="text-center align-middle">Total Book Titles</th><th scope="col" class="text-center align-middle">Total Book Copies</th></tr></thead>
                <tbody><tr><td>{{ number_format($inventoryTotals->titles) }}</td><td>{{ number_format($inventoryTotals->copies) }}</td></tr></tbody>
            </table>
        </div>
    </div>
@elseif ($report === 'annual')
    <dl class="annual-stat-grid mb-3">
        @foreach ($summary as $label => $value)
            <div class="annual-stat card">
                <dt>{{ $label }}</dt>
                <dd>{{ is_int($value) ? number_format($value) : $value }}</dd>
            </div>
        @endforeach
    </dl>
@else
<div class="row row-cards mb-3">
    @foreach ($summary as $label => $value)
        <div class="col-6 col-lg">
            <div class="card h-100"><div class="card-body">
                <div class="text-secondary small">{{ $label }}</div>
                <div class="h2 mb-0 mt-1">{{ is_int($value) ? number_format($value) : $value }}</div>
            </div></div>
        </div>
    @endforeach
</div>
@endisset

<p class="text-secondary small">{{ $note }}</p>
<div class="card">
    <div class="{{ $report === 'annual' ? 'annual-table' : 'table-responsive' }}">
        @include('reports.table')
    </div>
    @if ($rows instanceof \Illuminate\Contracts\Pagination\Paginator && $rows->hasPages())
        <div class="card-footer">{{ $rows->links() }}</div>
    @endif
</div>
@endsection
