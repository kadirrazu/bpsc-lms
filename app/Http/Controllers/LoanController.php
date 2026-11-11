<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\BookLoan;
use App\Models\User;
use App\Services\Library\LoanIssuance;
use App\Support\Ui;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LoanController extends Controller
{
    public function create(Request $request)
    {
        Gate::authorize('manage-library');

        $ids = old('book_ids', [old('book_id', $request->input('book_id'))]);
        $ids = array_filter((array) $ids, fn ($id) => is_scalar($id) && ctype_digit((string) $id));
        $books = Book::addSelect(['active_copies' => $this->activeCopies()])->whereKey(array_values($ids))->orderBy('id')->get();
        $memberId = old('member_id');
        $member = is_scalar($memberId) ? User::with('designation')->where('role', UserRole::Member->value)->where('is_active', true)->find($memberId) : null;

        return view('loans.create', [
            'books' => $books,
            'book' => $books->first(),
            'member' => $member,
            'memberLabel' => $member ? $this->memberLabel($member) : '',
        ]);
    }

    public function store(Request $request, LoanIssuance $issuer)
    {
        Gate::authorize('manage-library');
        $data = $request->validate([
            'book_id' => 'required_without:book_ids|prohibits:book_ids|integer|exists:books,id',
            'book_ids' => 'required_without:book_id|array|min:1|max:100',
            'book_ids.*' => 'required|integer|distinct|exists:books,id',
            'member_id' => 'required|integer|exists:users,id',
            'quantity' => 'sometimes|required|integer|in:1',
            'confirmed' => 'required|accepted',
        ]);

        $ids = array_values(array_map('intval', $data['book_ids'] ?? [$data['book_id']]));
        $loans = $issuer->issue($request->user(), $ids, (int) $data['member_id']);
        $message = $loans->count() === 1 ? 'Book issued.' : $loans->count().' books issued.';

        return redirect()->route('issue-requests.index')->with('success', $message.' Due: '.Ui::date($loans->first()->due_at));
    }

    public function options(Request $request, string $kind)
    {
        Gate::authorize('manage-library');
        $data = $request->validate([
            'q' => 'nullable|string|max:150',
            'member_id' => 'nullable|integer',
            'exclude' => 'nullable|array|max:100',
            'exclude.*' => 'integer',
        ]);
        $term = trim($data['q'] ?? '');
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        if ($kind === 'members') {
            $rows = User::with('designation')->where('role', UserRole::Member->value)->where('is_active', true)
                ->when($term !== '', fn ($query) => $query->where(fn ($user) => $user
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("mobile_number LIKE ? ESCAPE '!'", [$pattern])))
                ->orderBy('name')->orderBy('id')->limit(20)->get()
                ->map(fn ($user) => ['id' => $user->id, 'label' => $this->memberLabel($user)]);
        } else {
            $onLoan = $this->activeCopies();
            $rows = Book::addSelect(['active_copies' => $onLoan])->where('number_of_copies', '>', $onLoan)
                ->whereNotIn('id', $data['exclude'] ?? [])
                ->when(isset($data['member_id']), fn ($query) => $query->whereDoesntHave('loans', fn ($loan) => $loan
                    ->where('user_id', $data['member_id'])->whereNull('returned_at')))
                ->when($term !== '', fn ($query) => $query->where(function ($book) use ($pattern) {
                    foreach (['title_bn', 'title_en', 'accession_number', 'call_number'] as $field) {
                        $book->orWhereRaw("$field LIKE ? ESCAPE '!'", [$pattern]);
                    }
                    $book->orWhereHas('authors', fn ($author) => $author->where(fn ($names) => $names
                        ->whereRaw("title_bn LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("title_en LIKE ? ESCAPE '!'", [$pattern])));
                }))
                ->latest('id')->limit(20)->get()
                ->map(fn ($book) => [
                    'id' => $book->id,
                    'label' => $book->title_bn.' — '.$book->accession_number,
                    'available_copies' => $book->number_of_copies - (int) $book->active_copies,
                ]);
        }

        return response()->json(['results' => $rows]);
    }

    private function activeCopies(): Builder
    {
        return BookLoan::selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('book_id', 'books.id')
            ->whereNull('returned_at');
    }

    private function memberLabel(User $user): string
    {
        return implode(' - ', [$user->name, $user->designation?->name ?? 'Not assigned', Ui::unit($user->unit)]);
    }
}
