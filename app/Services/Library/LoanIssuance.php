<?php

namespace App\Services\Library;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\BookIssueRequest;
use App\Models\BookLoan;
use App\Models\IssueReceipt;
use App\Models\User;
use App\Support\Ui;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanIssuance
{
    /** @param list<int> $bookIds @param array<int,string|null> $copyAccessions */
    public function issue(User $operator, array $bookIds, int $memberId, ?int $requestId = null, array $copyAccessions = []): Collection
    {
        $limit = app(BorrowingPolicy::class)->limit();
        if (count($bookIds) < 1 || count($bookIds) > $limit || count(array_unique($bookIds)) !== count($bookIds)) {
            throw ValidationException::withMessages(['book_ids' => "Select between 1 and $limit different books."]);
        }

        return DB::transaction(function () use ($operator, $bookIds, $memberId, $requestId, $limit, $copyAccessions) {
            // Lock books in a consistent order before locking requests and the member.
            $books = Book::whereKey($bookIds)->orderBy('id')->lockForUpdate()->get();
            if ($books->count() !== count($bookIds)) {
                throw ValidationException::withMessages(['loan' => 'A selected book is no longer available.']);
            }

            $request = $requestId ? BookIssueRequest::lockForUpdate()->findOrFail($requestId) : null;
            if ($request && (count($bookIds) !== 1 || $request->status !== 'pending' || $request->loan_id
                || (int) $request->book_id !== $bookIds[0] || (int) $request->user_id !== $memberId)) {
                throw ValidationException::withMessages(['request' => 'This request has already been reviewed or changed.']);
            }

            $member = User::lockForUpdate()->find($memberId);
            if (! $member || ! $member->is_active || $member->role !== UserRole::Member) {
                throw ValidationException::withMessages(['loan' => 'Select an active Library Member.']);
            }

            $activeTitles = BookLoan::where('user_id', $memberId)->whereNull('returned_at')
                ->lockForUpdate()->pluck('book_id')->unique();
            if ($activeTitles->intersect($bookIds)->isNotEmpty()) {
                throw ValidationException::withMessages(['loan' => 'A selected book is already on loan to this member.']);
            }
            if ($activeTitles->count() + $books->count() > $limit) {
                $remaining = max(0, $limit - $activeTitles->count());
                throw ValidationException::withMessages(['loan' => "This member can borrow $remaining more book(s). The configured limit is $limit."]);
            }

            foreach ($books as $book) {
                if ($book->isSoftCopy()) {
                    throw ValidationException::withMessages(['loan' => 'Soft copies are available for download.']);
                }
                if ($book->activeLoanQuantity() >= $book->number_of_copies) {
                    throw ValidationException::withMessages(['quantity' => "No copies are available for accession {$book->accession_number}."]);
                }
                if (! $request && blank($copyAccessions[$book->id] ?? null)) {
                    throw ValidationException::withMessages(['copy_accessions' => "Select an available accession number for {$book->title_bn}."]);
                }
                if (! $request && BookIssueRequest::where('book_id', $book->id)->where('user_id', $memberId)->where('status', 'pending')->exists()) {
                    throw ValidationException::withMessages(['loan' => "Accession {$book->accession_number} already has a pending request. Approve it from Issue Requests."]);
                }
            }

            $issuedAt = now();
            $receipt = IssueReceipt::create([
                'member_name' => $member->name,
                'membership_number' => $member->membership_number,
                'issued_at' => $issuedAt,
                'member_designation' => $member->designation?->name ?? 'Not assigned',
                'member_unit' => Ui::unit($member->unit),
                'issuer_user_id' => $operator->id,
                'issuer_name' => $operator->name,
                'issuer_designation' => $operator->designation?->name ?? 'Not assigned',
            ]);
            $loans = collect();
            foreach ($books as $book) {
                $loan = new BookLoan(['book_id' => $book->id, 'user_id' => $memberId, 'issued_at' => $issuedAt]);
                $loan->issue_receipt_id = $receipt->id;
                $loan->issued_by = $operator->id;
                $loan->requestedAccession = $copyAccessions[$book->id] ?? null;
                $loan->save();
                $loans->push($loan);

                $entry = $request ?? new BookIssueRequest(['book_id' => $book->id, 'user_id' => $memberId, 'source' => 'staff_issue']);
                $entry->fill(['status' => 'approved', 'loan_id' => $loan->id, 'reviewed_by' => $operator->id, 'reviewed_at' => $issuedAt])->save();
                app(Audit::class)->record('book.issue_request_approved', ['loan_id' => $loan->id, 'source' => $entry->source], $entry);
            }

            if ($loans->count() > 1) {
                app(Audit::class)->record('book.batch_issued', ['member_id' => $memberId, 'loan_ids' => $loans->pluck('id')->all()], $member);
            }

            return $loans;
        }, 3);
    }
}
