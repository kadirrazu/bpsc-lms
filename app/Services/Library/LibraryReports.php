<?php

namespace App\Services\Library;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LibraryReports
{
    public const TITLES = [
        'circulation' => 'Issue & Return',
        'acquisitions' => 'Book Acquisitions',
        'members' => 'Member Directory',
        'categories' => 'Category Inventory',
        'open-loans' => 'Open & Overdue Loans',
        'popular' => 'Most Issued Books',
        'annual' => 'Annual Summary',
    ];

    private const NUMERIC = ['issued', 'returned', 'titles', 'copies', 'unit_price', 'value', 'members', 'quantity', 'open_copies', 'borrowed_copies', 'returned_copies', 'loan_id', 'renewals'];

    private const COPY_COUNTS = 'COALESCE(SUM(quantity), 0) AS borrowed_copies, COALESCE(SUM(CASE WHEN returned_at IS NOT NULL THEN quantity ELSE 0 END), 0) AS returned_copies, COALESCE(SUM(CASE WHEN returned_at IS NULL THEN quantity ELSE 0 END), 0) AS open_copies';

    public static function centered(string $report, string $key): bool
    {
        return match ($report) {
            'circulation' => true,
            'acquisitions' => in_array($key, ['accession', 'copies', 'unit_price', 'value', 'status'], true),
            'members' => ! in_array($key, ['member', 'designation', 'email'], true),
            'categories' => in_array($key, ['titles', 'copies'], true),
            'popular' => in_array($key, ['issued', 'copies', 'status'], true),
            'annual' => $key !== 'period',
            'member-loans' => ! in_array($key, ['book', 'english'], true),
            default => false,
        };
    }

    public static function numeric(string $key): bool
    {
        return in_array($key, self::NUMERIC, true);
    }

    public static function money(string $key): bool
    {
        return in_array($key, ['unit_price', 'value'], true);
    }

    public function make(string $report, array $filters): array
    {
        $data = match ($report) {
            'circulation' => $this->circulation($filters),
            'acquisitions' => $this->acquisitions($filters),
            'members' => $this->members($filters),
            'member-loans' => $this->memberLoans($filters['member']),
            'categories' => $this->categories($filters),
            'open-loans' => $this->openLoans($filters),
            'popular' => $this->popular($filters),
            'annual' => $this->annual($filters),
        };

        return $data + ['title' => self::TITLES[$report] ?? 'Member Loan History', 'report' => $report, 'filters' => $filters];
    }

    private function inPeriod(Builder $query, string $column, array $filters): Builder
    {
        if (isset($filters['from'])) {
            $query->where($column, '>=', $filters['from'].' 00:00:00');
        }

        if (isset($filters['to'])) {
            $query->where($column, '<', CarbonImmutable::parse($filters['to'])->addDay()->startOfDay());
        }

        return $query;
    }

    private function movement(array $filters, int $periodLength): Builder
    {
        $issues = $this->inPeriod(DB::table('book_loans'), 'issued_at', $filters)
            ->selectRaw("SUBSTR(issued_at, 1, $periodLength) AS period, SUM(quantity) AS issued, 0 AS returned")
            ->groupBy('period');
        $returns = $this->inPeriod(DB::table('book_loans')->whereNotNull('returned_at'), 'returned_at', $filters)
            ->selectRaw("SUBSTR(returned_at, 1, $periodLength) AS period, 0 AS issued, SUM(quantity) AS returned")
            ->groupBy('period');

        return DB::query()->fromSub($issues->unionAll($returns), 'movement')
            ->selectRaw('period, SUM(issued) AS issued, SUM(returned) AS returned')
            ->groupBy('period')->orderBy('period');
    }

    private function circulation(array $filters): array
    {
        $query = $this->movement($filters, 10);
        $total = DB::query()->fromSub((clone $query)->reorder(), 'daily')->selectRaw('SUM(issued) AS issued, SUM(returned) AS returned')->first();

        return [
            'rows' => $query,
            'columns' => ['period' => 'Date', 'issued' => 'Books Issued', 'returned' => 'Books Returned'],
            'summary' => ['Books Issued' => (int) $total->issued, 'Books Returned' => (int) $total->returned],
            'note' => 'Issues and returns use their own transaction dates. Only dates with activity appear.',
        ];
    }

    private function acquisitions(array $filters): array
    {
        $books = $this->inPeriod(DB::table('books'), 'created_at', $filters)
            ->when(isset($filters['method']), fn ($q) => $q->where('collection_method', $filters['method']));
        $totals = (clone $books)->selectRaw("COUNT(*) AS titles, SUM(number_of_copies) AS copies, SUM(price * CASE WHEN format = 'soft' THEN 1 ELSE number_of_copies END) AS value, COUNT(*) - COUNT(price) AS unpriced")->first();

        return [
            'rows' => $books->selectRaw("created_at AS added, accession_number AS accession, title_bn AS book, collection_method AS method, number_of_copies AS copies, price AS unit_price, price * CASE WHEN format = 'soft' THEN 1 ELSE number_of_copies END AS value, CASE WHEN deleted_at IS NULL THEN 'In catalog' ELSE 'Archived' END AS status")
                ->orderByDesc('created_at')->orderByDesc('id'),
            'columns' => ['added' => 'Added At', 'accession' => 'Accession', 'book' => 'Book Title', 'method' => 'Collection Method', 'copies' => 'Copies', 'unit_price' => 'Unit Price (BDT)', 'value' => 'Value (BDT)', 'status' => 'Catalog Status'],
            'summary' => ['Titles added' => (int) $totals->titles, 'Recorded copies' => (int) $totals->copies, 'Known value (BDT)' => number_format((float) $totals->value, 2), 'Titles without price' => (int) $totals->unpriced],
            'note' => 'Includes archived books. Value uses Purchase Price × copies for hard copies and the recorded Purchase Price for each PDF. Titles without a recorded Purchase Price are excluded from value totals.',
        ];
    }

    private function members(array $filters): array
    {
        $members = $this->inPeriod(DB::table('users')->whereNull('users.deleted_at')->where('users.role', UserRole::Member->value), 'users.created_at', $filters)
            ->when(isset($filters['status']), fn ($q) => $q->where('users.is_active', $filters['status'] === 'active'));
        $total = (clone $members)->selectRaw('COUNT(*) AS total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active')->first();
        $loans = DB::table('book_loans')->groupBy('user_id')->selectRaw('user_id, '.self::COPY_COUNTS);

        return [
            'rows' => $members->leftJoin('designations', 'designations.id', '=', 'users.designation_id')
                ->leftJoin('units', 'units.code', '=', 'users.unit')
                ->leftJoinSub($loans, 'loans', 'loans.user_id', '=', 'users.id')
                ->selectRaw("users.id AS member_id, users.name AS member, users.email, users.mobile_number AS mobile, designations.name AS designation, COALESCE(units.name, users.unit) AS unit, users.created_at AS joined, CASE WHEN users.is_active = 1 THEN 'Active' ELSE 'Inactive' END AS status, COALESCE(loans.borrowed_copies, 0) AS borrowed_copies, COALESCE(loans.returned_copies, 0) AS returned_copies, COALESCE(loans.open_copies, 0) AS open_copies")
                ->orderBy('users.name')->orderBy('users.id'),
            'columns' => ['member' => 'Member', 'designation' => 'Designation', 'unit' => 'Unit', 'email' => 'Email', 'mobile' => 'Mobile', 'joined' => 'Account Created', 'status' => 'Status', 'borrowed_copies' => 'Copies Borrowed', 'returned_copies' => 'Copies Returned', 'open_copies' => 'Copies on Loan'],
            'summary' => ['Members' => (int) $total->total, 'Active' => (int) $total->active, 'Inactive' => (int) $total->total - (int) $total->active],
            'note' => 'Date filters use account creation dates. Borrowing counts include the full loan history of each listed member.',
        ];
    }

    private function memberLoans(int $memberId): array
    {
        $member = User::where('role', UserRole::Member->value)->findOrFail($memberId);
        $loans = DB::table('book_loans')->where('user_id', $member->id);
        $total = (clone $loans)->selectRaw(self::COPY_COUNTS)->first();

        return [
            'title' => 'Loan History — '.$member->name,
            'rows' => $loans->leftJoin('books', 'books.id', '=', 'book_loans.book_id')
                ->selectRaw("COALESCE(accession_snapshot, books.accession_number) AS accession, COALESCE(title_snapshot, books.title_bn) AS book, quantity, issued_at AS issued_on, due_at AS due, returned_at AS returned_on, CASE WHEN returned_at IS NOT NULL THEN 'Returned' WHEN due_at < ? THEN 'Overdue' ELSE 'On Loan' END AS status", [now()->toDateTimeString()])
                ->orderByDesc('issued_at')->orderByDesc('book_loans.id'),
            'columns' => ['accession' => 'Accession', 'book' => 'Book Title', 'quantity' => 'Copies', 'issued_on' => 'Issued At', 'due' => 'Due At', 'returned_on' => 'Returned At', 'status' => 'Loan Status'],
            'summary' => ['Copies Borrowed' => (int) $total->borrowed_copies, 'Copies Returned' => (int) $total->returned_copies, 'Copies on Loan' => (int) $total->open_copies],
            'note' => 'All recorded loans for this member. An overdue copy remains on loan until its return is recorded.',
        ];
    }

    private function categories(array $filters): array
    {
        $books = DB::table('books')->whereNull('deleted_at');
        if (isset($filters['category'])) {
            $books->whereIn('id', DB::table('book_categories')->where('category_id', $filters['category'])->select('book_id'));

            return [
                'rows' => $books->selectRaw('accession_number AS accession, title_bn AS book, title_en AS english, call_number, number_of_copies AS copies')
                    ->orderBy('title_bn')->orderBy('id'),
                'columns' => ['accession' => 'Accession', 'book' => 'Book Title', 'english' => 'English Title', 'call_number' => 'Call Number', 'copies' => 'Copies'],
                'summary' => ['Catalog titles' => (clone $books)->count(), 'Catalog copies' => (int) $books->sum('number_of_copies')],
                'note' => 'Titles and copies currently in the catalog for the selected category.',
            ];
        }

        $inventory = DB::table('book_categories')->join('books', 'books.id', '=', 'book_categories.book_id')
            ->whereNull('books.deleted_at')->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) AS titles, SUM(number_of_copies) AS copies');
        $categories = DB::table('categories');
        $categoryTotals = DB::query()->fromSub(clone $inventory, 'category_totals')
            ->selectRaw('COALESCE(SUM(titles), 0) AS titles, COALESCE(SUM(copies), 0) AS copies')->first();
        $catalogTotals = (clone $books)->selectRaw('COUNT(*) AS titles, COALESCE(SUM(number_of_copies), 0) AS copies')->first();

        return [
            'rows' => $categories->leftJoinSub($inventory, 'inventory', 'inventory.category_id', '=', 'categories.id')
                ->selectRaw('title_bn AS category, title_en AS english, COALESCE(inventory.titles, 0) AS titles, COALESCE(inventory.copies, 0) AS copies')
                ->orderBy('title_bn')->orderBy('categories.id'),
            'columns' => ['category' => 'Category', 'english' => 'English Name', 'titles' => 'Titles', 'copies' => 'Copies'],
            'summary' => ['Categories' => (clone $categories)->count(), 'Distinct catalog titles' => (int) $catalogTotals->titles, 'Catalog copies' => (int) $catalogTotals->copies],
            'inventoryTotals' => $catalogTotals,
            'totals' => ['category' => 'Total across categories', 'titles' => $categoryTotals->titles, 'copies' => $categoryTotals->copies],
            'note' => 'A title can belong to several categories, so category rows can overlap. The catalog totals count each matching title and its copies once.',
        ];
    }

    private function openLoans(array $filters): array
    {
        $now = now();
        $loans = DB::table('book_loans')->whereNull('returned_at');
        if (($filters['scope'] ?? '') === 'overdue') {
            $loans->where('due_at', '<', $now);
        }
        if (($filters['scope'] ?? '') === 'flagged') {
            $loans->where('is_fined', true);
        }
        $total = (clone $loans)->selectRaw('COUNT(*) AS total, SUM(quantity) AS copies, SUM(CASE WHEN due_at < ? THEN 1 ELSE 0 END) AS overdue', [$now->toDateTimeString()])->first();

        return [
            'rows' => $loans->join('books', 'books.id', '=', 'book_loans.book_id')->leftJoin('users', 'users.id', '=', 'book_loans.user_id')
                ->selectRaw("COALESCE(accession_snapshot, books.accession_number) AS accession, COALESCE(title_snapshot, books.title_bn) AS book, users.name AS member, quantity, issued_at AS issued_on, due_at AS due, CASE WHEN due_at IS NULL THEN 'No due date' WHEN due_at < ? THEN 'Overdue' ELSE 'On loan' END AS status, CASE WHEN is_fined = 1 THEN 'Yes' ELSE 'No' END AS flagged", [$now->toDateTimeString()])
                ->orderBy('due_at')->orderBy('book_loans.id'),
            'columns' => ['accession' => 'Accession', 'book' => 'Book Title', 'member' => 'Member', 'quantity' => 'Copies', 'issued_on' => 'Issued At', 'due' => 'Due At', 'status' => 'Loan Status', 'flagged' => 'Fine Flag'],
            'summary' => ['Open loans' => (int) $total->total, 'Copies on loan' => (int) $total->copies, 'Overdue loans' => (int) $total->overdue],
            'note' => 'A loan becomes overdue after its recorded due time. Fine flags reflect the current Issue Register.',
        ];
    }

    private function popular(array $filters): array
    {
        $issues = $this->inPeriod(DB::table('book_loans'), 'issued_at', $filters);
        $totals = (clone $issues)->selectRaw('COUNT(DISTINCT book_id) AS titles, SUM(quantity) AS issued')->first();
        $ranked = $issues->groupBy('book_id')->selectRaw('book_id, SUM(quantity) AS issued');

        return [
            'rows' => DB::query()->fromSub($ranked, 'ranking')->join('books', 'books.id', '=', 'ranking.book_id')
                ->selectRaw("books.accession_number AS accession, books.title_bn AS book, ranking.issued, CASE WHEN books.deleted_at IS NULL THEN 'In catalog' ELSE 'Archived' END AS status")
                ->orderByDesc('ranking.issued')->orderBy('books.id'),
            'columns' => ['accession' => 'Accession', 'book' => 'Book Title', 'issued' => 'Books Issued', 'status' => 'Catalog Status'],
            'summary' => ['Titles issued' => (int) $totals->titles, 'Books Issued' => (int) $totals->issued],
            'note' => 'Ranks titles by the number of books issued in the selected period, including archived titles.',
        ];
    }

    private function annual(array $filters): array
    {
        $period = isset($filters['year'])
            ? ['from' => sprintf('%04d-01-01', $filters['year']), 'to' => sprintf('%04d-12-31', $filters['year'])]
            : $filters;
        $movement = $this->movement($period, 7)->get()->keyBy('period');
        $books = $this->inPeriod(DB::table('books'), 'created_at', $period)
            ->selectRaw("SUBSTR(created_at, 1, 7) AS period, COUNT(*) AS titles, SUM(number_of_copies) AS copies, SUM(price * CASE WHEN format = 'soft' THEN 1 ELSE number_of_copies END) AS value")
            ->groupBy('period')->get()->keyBy('period');
        $members = $this->inPeriod(DB::table('users')->where('role', UserRole::Member->value), 'created_at', $period)
            ->selectRaw('SUBSTR(created_at, 1, 7) AS period, COUNT(*) AS members')->groupBy('period')->pluck('members', 'period');

        $rows = new Collection;
        $firstMonth = CarbonImmutable::parse($period['from'])->startOfMonth();
        $lastMonth = CarbonImmutable::parse($period['to'])->startOfMonth();
        for ($month = $firstMonth; $month <= $lastMonth; $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $rows->push((object) [
                'period' => $month->format('M Y'),
                'issued' => $movement[$key]->issued ?? 0,
                'returned' => $movement[$key]->returned ?? 0,
                'titles' => $books[$key]->titles ?? 0,
                'copies' => $books[$key]->copies ?? 0,
                'value' => $books[$key]->value ?? 0,
                'members' => $members[$key] ?? 0,
            ]);
        }

        $totals = ['period' => 'Total'];
        foreach (['issued', 'returned', 'titles', 'copies', 'value', 'members'] as $key) {
            $totals[$key] = $rows->sum($key);
        }

        return [
            'rows' => $rows,
            'columns' => ['period' => 'Month', 'issued' => 'Books Issued', 'returned' => 'Books Returned', 'titles' => 'Titles Added', 'copies' => 'Acquired Copies', 'value' => 'Known Value (BDT)', 'members' => 'Member Accounts Added'],
            'totals' => $totals,
            'summary' => [
                'Books Issued' => (int) $totals['issued'],
                'Books Returned' => (int) $totals['returned'],
                'Titles added' => (int) $totals['titles'],
                'Member accounts added' => (int) $totals['members'],
                'Known value (BDT)' => number_format($totals['value'], 2),
            ],
            'note' => 'Monthly totals include only dates in the selected period, including archived records. Acquisition value uses the recorded Purchase Price and physical copy count, plus the Purchase Price of each PDF. Member counts use account creation dates and the recorded member role.',
        ];
    }
}
