<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\{Book, BookLoan, IssueReceipt, Publisher, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExceptionalIssueTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        return $user;
    }

    private function book(string $prefix = 'EX', int $copies = 3): Book
    {
        $publisher = Publisher::firstOrCreate(['title_bn' => 'Publisher']);
        $book = Book::create([
            'accession_number' => $prefix.'-1', 'title_bn' => 'Book '.$prefix,
            'publisher_id' => $publisher->id, 'call_number' => '800', 'number_of_copies' => $copies,
        ]);
        foreach (range(1, $copies) as $number) {
            $book->copies()->create(['accession_number' => $prefix.'-'.$number, 'is_active' => true]);
        }
        return $book;
    }

    private function payload(User $member, Book $book): array
    {
        return [
            'member_id' => $member->id, 'book_ids' => [$book->id], 'confirmed' => true,
            'copy_accessions' => [$book->id => $book->accession_number],
            'exceptional_issue' => true, 'exceptional_reason' => 'Required for preparing an official report.',
        ];
    }

    public function test_librarian_can_issue_multiple_copies_above_limit_and_return_each_independently(): void
    {
        $operator = $this->signIn(UserRole::Librarian);
        $member = User::factory()->create();
        $book = $this->book();
        $data = $this->payload($member, $book);
        $data['additional_copy_accessions'] = [$book->id => ['EX-2', 'EX-3']];
        $this->post(route('loans.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $loans = BookLoan::orderBy('id')->get();
        $this->assertSame(['EX-1', 'EX-2', 'EX-3'], $loans->pluck('accession_snapshot')->all());
        $this->assertSame([1, 1, 1], $loans->pluck('quantity')->all());
        $this->assertSame(1, $loans->pluck('issue_receipt_id')->unique()->count());
        foreach ($loans as $loan) {
            $this->assertSame($operator->id, $loan->limit_override_by);
            $this->assertSame(2, $loan->exceptional_limit);
            $this->assertSame(3, $loan->exceptional_total_after);
            $this->assertTrue($loan->due_at->equalTo($loan->issued_at->copy()->addDays(15)));
        }
        $this->assertSame(3, $book->fresh()->total_issue_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'book.exceptionally_issued']);
        $this->get('/issue-register?issue_type=exceptional')->assertOk()
            ->assertViewHas('loans', fn ($rows) => $rows->total() === 3)->assertSee('Exceptional Issue');
        $this->get('/issue-register?issue_type=normal')->assertOk()
            ->assertViewHas('loans', fn ($rows) => $rows->total() === 0);
        $csv = $this->get('/issue-register/export?issue_type=exceptional')->assertOk()->streamedContent();
        $this->assertStringContainsString($data['exceptional_reason'], $csv);
        $this->get('/issue-register/export?format=pdf&issue_type=exceptional')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get('/issue-register/export?format=xlsx&issue_type=exceptional')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get(route('loans.receipt', $loans->first()))->assertOk();
        $this->post(route('loans.return', $loans->first()))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('loans.return-receipt', $loans->first()))->assertOk();
        $this->assertSame(2, BookLoan::whereNull('returned_at')->count());
        $this->getJson(route('loans.copy-options', $book))->assertJsonPath('accessions.0', 'EX-1');

        $this->actingAs($member)->withSession(['auth_version' => $member->auth_version]);
        $this->post(route('member.books.request', $this->book('NEW')))->assertSessionHasErrors('request');
    }

    public function test_only_librarian_can_authorize_even_when_the_payload_is_forged(): void
    {
        $member = User::factory()->create();
        $book = $this->book();
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $this->signIn($role);
            $this->post(route('loans.store'), $this->payload($member, $book))->assertForbidden();
            if ($role !== UserRole::Member) {
                $this->get(route('loans.create'))->assertOk()->assertDontSee('Allow Exceptional Issue');
            }
        }
        $this->assertDatabaseCount('book_loans', 0);
        $this->assertDatabaseCount('issue_receipts', 0);
    }

    public function test_missing_reason_duplicate_or_unavailable_accessions_roll_back_the_entire_batch(): void
    {
        $this->signIn(UserRole::Librarian);
        $member = User::factory()->create();
        $book = $this->book();
        $payload = $this->payload($member, $book);
        $this->post(route('loans.store'), array_replace($payload, ['exceptional_reason' => '  ']))
            ->assertSessionHasErrors('exceptional_reason');
        $this->post(route('loans.store'), $payload + ['additional_copy_accessions' => [$book->id => ['EX-1']]])
            ->assertSessionHasErrors('copy_accessions');
        $this->post(route('loans.store'), $payload + ['additional_copy_accessions' => [$book->id => ['UNAVAILABLE']]])
            ->assertSessionHasErrors('loan');
        $this->assertDatabaseCount('book_loans', 0);
        $this->assertDatabaseCount('book_issue_requests', 0);
        $this->assertDatabaseCount('issue_receipts', 0);
        $this->assertSame(0, $book->fresh()->total_issue_count);
    }

    public function test_existing_same_title_and_more_distinct_titles_require_exceptional_authorization(): void
    {
        $this->signIn(UserRole::Librarian);
        $member = User::factory()->create();
        $first = $this->book();
        $base = $this->payload($member, $first);
        $normal = array_replace($base, ['exceptional_issue' => false]);
        $this->post(route('loans.store'), $normal)->assertSessionHasNoErrors();
        $next = array_replace($base, ['copy_accessions' => [$first->id => 'EX-2']]);
        $this->post(route('loans.store'), array_replace($next, ['exceptional_issue' => false]))->assertSessionHasErrors('loan');
        $this->post(route('loans.store'), $next)->assertSessionHasNoErrors();
        $second = $this->book('SECOND');
        $third = $this->book('THIRD');
        $fourth = $this->book('FOURTH');
        $data = array_replace($base, [
            'book_ids' => [$second->id, $third->id, $fourth->id],
            'copy_accessions' => [$second->id => 'SECOND-1', $third->id => 'THIRD-1', $fourth->id => 'FOURTH-1'],
        ]);
        $this->post(route('loans.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('book_loans', 5);
        $this->signIn(UserRole::Staff);
        $this->post(route('loans.store'), array_replace($this->payload($member, $this->book('STAFF')), ['exceptional_issue' => false]))
            ->assertSessionHasErrors('loan');
    }

    public function test_additional_copies_require_opt_in_and_lookup_respects_role_and_mode(): void
    {
        $this->signIn(UserRole::Librarian);
        $member = User::factory()->create();
        $book = $this->book();
        $payload = $this->payload($member, $book);
        $normal = array_replace($payload, ['exceptional_issue' => false]);
        $this->post(route('loans.store'), $normal + ['additional_copy_accessions' => [$book->id => ['EX-2']]])
            ->assertSessionHasErrors('additional_copy_accessions');
        $this->post(route('loans.store'), $normal)->assertSessionHasNoErrors();
        $this->getJson('/loan-options/books?member_id='.$member->id)->assertJsonCount(0, 'results');
        $this->getJson('/loan-options/books?member_id='.$member->id.'&exceptional_issue=1')
            ->assertJsonPath('results.0.id', $book->id);
        $this->getJson('/loan-options/members?q='.$member->membership_number)->assertJsonPath('results.0.on_loan', 1);
        $this->signIn(UserRole::Staff);
        $this->getJson('/loan-options/books?exceptional_issue=1')->assertForbidden();
    }
}
