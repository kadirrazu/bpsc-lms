<?php
namespace App\Http\Controllers;
use App\Models\{Book,BookLoan,LoanReminder};
use App\Enums\UserRole;
use App\Services\Library\Audit;
use App\Services\Library\BorrowingPolicy;
use App\Services\Library\LibraryReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class IssueRegisterController extends Controller {
 private const SORT_OPTIONS = [
  '' => 'Latest records first (default)',
  'issued_asc' => 'Issue date and time: oldest first',
  'issued_desc' => 'Issue date and time: newest first',
 ];

 private const EXPORT_COLUMNS = [
  'loan_id' => 'Loan ID', 'accession' => 'Accession Number', 'title' => 'Title',
  'authors' => 'Authors', 'member' => 'Member', 'email' => 'Email',
  'issued_on' => 'Issue Date and Time', 'due' => 'Due Date and Time',
  'returned_on' => 'Return Date and Time', 'issuer' => 'Issued By',
  'renewals' => 'Renewal Count', 'fine' => 'Fine Flag', 'reminder' => 'Last Reminder',
 ];

 private function query(Request $request): array
 {
  $filters = $request->validate([
   'q' => 'nullable|string|max:150',
   'status' => 'nullable|in:on_loan,returned',
   'fine' => 'nullable|in:flagged,clear',
   'from' => 'nullable|date_format:Y-m-d',
   'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
   'sort' => ['nullable', Rule::in(array_keys(self::SORT_OPTIONS))],
   'page' => 'nullable|integer|min:1',
  ]);
  unset($filters['page']);
  $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');

  $query = BookLoan::with(['book.authors', 'user', 'issuer', 'reminder'])
   ->when(isset($filters['from']), fn ($query) => $query->where('issued_at', '>=', $filters['from'].' 00:00:00'))
   ->when(isset($filters['to']), fn ($query) => $query->where('issued_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->startOfDay()));
  if (($filters['status'] ?? null) === 'on_loan') {
   $query->whereNull('returned_at');
  } elseif (($filters['status'] ?? null) === 'returned') {
   $query->whereNotNull('returned_at');
  }
  if (isset($filters['fine'])) {
   $query->where('is_fined', $filters['fine'] === 'flagged');
  }
  if (($term = trim($filters['q'] ?? '')) !== '') {
   $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
   $query->where(function ($query) use ($pattern) {
    foreach (['accession_snapshot', 'title_snapshot', 'title_en_snapshot', 'issuer_name', 'authors_snapshot'] as $field) {
     $query->orWhereRaw("$field LIKE ? ESCAPE '!'", [$pattern]);
    }
    $query->orWhereHas('book', fn ($book) => $book->where(fn ($book) => $book
     ->whereRaw("title_bn LIKE ? ESCAPE '!'", [$pattern])
     ->orWhereRaw("title_en LIKE ? ESCAPE '!'", [$pattern])
     ->orWhereRaw("accession_number LIKE ? ESCAPE '!'", [$pattern])));
    $query->orWhereHas('book.authors', fn ($author) => $author->where(fn ($author) => $author
     ->whereRaw("title_bn LIKE ? ESCAPE '!'", [$pattern])
     ->orWhereRaw("title_en LIKE ? ESCAPE '!'", [$pattern])));
    $query->orWhereHas('user', fn ($user) => $user->where(fn ($user) => $user
     ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
     ->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern])
     ->orWhereRaw("mobile_number LIKE ? ESCAPE '!'", [$pattern])));
   });
  }

  $sort = $filters['sort'] ?? '';
  $direction = $sort === 'issued_asc' ? 'asc' : 'desc';
  if ($sort !== '') {
   $query->orderBy('issued_at', $direction);
  }

  return [$query->orderBy('id', $direction), $filters];
 }

 public function index(Request $request)
 {
  Gate::authorize('manage-library');
  [$query, $filters] = $this->query($request);
  $loans = $query->with('user.designation')->paginate(20)->appends($filters);

  return view('loans.register', compact('loans', 'filters') + ['sortOptions' => self::SORT_OPTIONS]);
 }

 public function export(Request $request, LibraryReportExport $export)
 {
  Gate::authorize('manage-library');
  $format = $request->validate(['format' => 'sometimes|required|in:pdf,xlsx,csv'])['format'] ?? 'csv';
  [$query, $filters] = $this->query($request);
  $loans = $this->exportLoans($query);
  $download = $format === 'csv'
   ? $this->csv($loans)
   : $export->download([
    'report' => 'issue-register',
    'title' => 'Issue Register',
    'filters' => $filters,
    'columns' => $format === 'pdf'
     ? [
      'book' => 'Title / Accession / Authors', 'member' => 'Member',
      'issued_on' => 'Issued at', 'due' => 'Due at', 'returned_on' => 'Returned at',
      'issuer' => 'Issued By', 'renewals' => 'Renewals', 'fine' => 'Fine Flag', 'reminder' => 'Last Reminder',
     ]
     : self::EXPORT_COLUMNS,
    'rows' => $loans->map(fn (BookLoan $loan) => (object) $this->exportRow($loan)),
    'summary' => [],
    'note' => 'The date range applies to the issue date. All timestamps are shown in Dhaka time.',
   ], $format);
  app(Audit::class)->record('loan.register_exported', ['format' => $format] + Arr::except($filters, ['q']));

  return $download;
 }

 private function exportLoans(Builder $query): LazyCollection
 {
  return LazyCollection::make(function () use ($query) {
   // Start at the first record regardless of any cursor in the request URL.
   $cursor = '';
   do {
    $page = (clone $query)->cursorPaginate(250, ['*'], 'cursor', $cursor);
    yield from $page->items();
    $cursor = $page->nextCursor();
   } while ($cursor !== null);
  });
 }

 private function exportRow(BookLoan $loan): array
 {
  $accession = $loan->accession_snapshot ?? $loan->book?->accession_number;
  $title = $loan->title_snapshot ?? $loan->book?->title_bn;
  $authors = implode(' | ', $loan->authors_snapshot ?? $loan->book?->authors->pluck('title_bn')->all() ?? []);

  return [
   'loan_id' => $loan->id,
   'accession' => $accession,
   'title' => $title,
   'authors' => $authors,
   'member' => $loan->user?->name,
   'email' => $loan->user?->email,
   'issued_on' => $loan->issued_at?->format('Y-m-d H:i:s'),
   'due' => $loan->due_at?->format('Y-m-d H:i:s'),
   'returned_on' => $loan->returned_at?->format('Y-m-d H:i:s'),
   'issuer' => $loan->issuer_name ?? $loan->issuer?->name ?? 'Not recorded',
   'renewals' => (int) $loan->renewal_count,
   'fine' => $loan->is_fined ? 'Yes' : 'No',
   'reminder' => $loan->reminder?->sent_at?->format('Y-m-d H:i:s'),
   'book' => implode("\n", array_filter([$title, $accession, $authors], fn ($value) => filled($value))),
  ];
 }

 private function csv(LazyCollection $loans)
 {
  return response()->streamDownload(function () use ($loans) {
   $file = fopen('php://output', 'w');
   try {
    fwrite($file, "\xEF\xBB\xBF");
    fputcsv($file, array_values(self::EXPORT_COLUMNS), ',', '"', '');
    $safe = fn ($value) => preg_match('/^(?:\s*[=+@\-]|[\x00-\x1F])/u', (string) $value) ? "'".$value : (string) $value;
    foreach ($loans as $loan) {
     $row = Arr::only($this->exportRow($loan), array_keys(self::EXPORT_COLUMNS));
     fputcsv($file, array_map($safe, $row), ',', '"', '');
    }
   } finally {
    fclose($file);
   }
  }, 'issue-register-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
 }

 public function action(Request $r,BookLoan $loan,string $action){
  Gate::authorize('manage-library');
  $r->validate(['confirmed'=>'required|accepted']);
  if($action==='renew'){
   $r->validate(['days'=>'prohibited','expected_renewal_count'=>'required|integer|min:0','expected_policy_revision'=>'nullable|integer|min:0']);
  }
  DB::transaction(function()use($r,$loan,$action){
   Book::withTrashed()->whereKey($loan->book_id)->lockForUpdate()->first();$current=BookLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
   if(in_array($action,['reminder','renew'],true) && $current->returned_at)throw ValidationException::withMessages(['loan'=>'This book has already been returned.']);
   if(in_array($action,['renew','fine'],true) && (!$current->due_at || !$current->due_at->isPast()))throw ValidationException::withMessages(['loan'=>'Renew and Fine are available only after the due date has passed.']);
   if($action==='reminder'){
    $member=$current->user;if(!$member || !$member->is_active || $member->role!==UserRole::Member)throw ValidationException::withMessages(['loan'=>'This member cannot receive a dashboard reminder.']);
    LoanReminder::updateOrCreate(['loan_id'=>$current->id],['user_id'=>$member->id,'sent_by'=>$r->user()->id,'sent_at'=>now()]);
    app(Audit::class)->record('book.return_reminder_sent',['loan_id'=>$current->id,'member_id'=>$member->id],$current->book);
   }elseif($action==='renew'){
    if((int)$r->input('expected_renewal_count')!==(int)$current->renewal_count)throw ValidationException::withMessages(['loan'=>'This loan was already renewed. Reload the register before renewing again.']);
    $policy=app(BorrowingPolicy::class);
    if($r->filled('expected_policy_revision') && (int)$r->input('expected_policy_revision')!==$policy->revision()){
     throw ValidationException::withMessages(['loan'=>'Renewal settings changed. Reload the register and review the new settings.']);
    }
    $limit=$policy->renewalLimit();
    if($current->renewal_count >= $limit){
     throw ValidationException::withMessages(['loan'=>$limit===0?'Renewals are disabled in Borrowing Settings.':"This loan has reached the maximum of $limit renewals."]);
    }
    $days=$policy->renewalDays();$before=$current->due_at?->toIso8601String();$renewedAt=now();
    $current->due_at=$renewedAt->copy()->addDays($days);
    $current->renewal_count=(int)$current->renewal_count+1;
    $current->renewed_at=$renewedAt;$current->save();
    app(Audit::class)->record('book.renewed',['loan_id'=>$current->id,'days'=>$days,'previous_due_at'=>$before,'new_due_at'=>$current->due_at->toIso8601String()],$current->book);
   }elseif(in_array($action,['fine','clear-fine'],true)){
    $flag=$action==='fine';if((bool)$current->is_fined!==$flag){$current->is_fined=$flag;$current->fined_at=$flag?now():null;$current->fined_by=$flag?$r->user()->id:null;$current->save();app(Audit::class)->record($flag?'book.fine_flagged':'book.fine_cleared',['loan_id'=>$current->id,'member_id'=>$current->user_id],$current->book);}
   }else abort(404);
  });
  return back()->with('success',match($action){'reminder'=>'Return reminder sent to the member dashboard.','renew'=>'Loan extended successfully.','fine'=>'Issue flagged for fine reporting.','clear-fine'=>'Fine flag cleared.'});
 }
}
