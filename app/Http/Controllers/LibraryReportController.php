<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Library\Audit;
use App\Services\Library\LibraryReportExport;
use App\Services\Library\LibraryReports;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LibraryReportController extends Controller
{
    public function index(Request $request, LibraryReports $reports, string $report = 'circulation')
    {
        $data = $reports->make($report, $this->filters($request, $report));
        if ($data['rows'] instanceof Builder) {
            $data['rows'] = $data['rows']->paginate(25)->appends($data['filters']);
        }
        $data['categories'] = $report === 'categories' ? Category::orderBy('title_bn')->get(['id', 'title_bn']) : collect();

        app(Audit::class)->record('reports.viewed', ['report' => $report, 'filters' => $data['filters']]);

        return view('reports.index', $data);
    }

    public function export(Request $request, LibraryReports $reports, LibraryReportExport $export, string $report, string $format)
    {
        $data = $reports->make($report, $this->filters($request, $report));
        $download = $export->download($data, $format);
        app(Audit::class)->record('reports.exported', ['report' => $report, 'format' => $format, 'filters' => $data['filters']]);

        return $download;
    }

    private function filters(Request $request, string $report): array
    {
        abort_unless(isset(LibraryReports::TITLES[$report]) || $report === 'member-loans', 404);

        $dates = ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'];
        $annualRange = $report === 'annual' && ($request->has('from') || $request->has('to'));
        $rules = match ($report) {
            'member-loans' => ['member' => 'required|integer|min:1'],
            'annual' => $annualRange
                ? ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']
                : ['year' => 'required|integer|between:1900,9998'],
            'categories' => ['category' => 'nullable|integer|exists:categories,id'],
            'open-loans' => ['scope' => 'nullable|in:all,overdue,flagged'],
            'members' => $dates + ['status' => 'nullable|in:active,inactive'],
            'acquisitions' => $dates + ['method' => 'nullable|in:Purchase,Gift,Others'],
            default => $dates,
        };
        $values = $request->query();
        if (isset($rules['from']) && ! in_array($report, ['members', 'annual'], true)) {
            $values['to'] ??= now()->toDateString();
            $values['from'] ??= $report === 'circulation' ? $values['to'] : now()->startOfMonth()->toDateString();
        }
        if (! $annualRange && isset($rules['to']) && ! empty($values['from'])) {
            $rules['to'] .= '|after_or_equal:from';
        }
        if ($report === 'annual' && ! $annualRange) {
            $values['year'] ??= now()->year;
        }

        $validator = Validator::make($values, $rules);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo(route('reports.index', $report === 'member-loans' ? 'members' : $report));
        }

        return array_filter($validator->validated(), fn ($value) => $value !== null && $value !== '');
    }
}
