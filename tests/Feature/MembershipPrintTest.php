<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\BookLoan;
use App\Models\BookIssueRequest;
use App\Models\Designation;
use App\Models\IssueReceipt;
use App\Models\Publisher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Tests\TestCase;

class MembershipPrintTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
    }

    public function test_membership_is_sequential_unique_and_retained_for_inactive_or_deleted_accounts(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 17));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->signIn($admin);
        $data = [
            'name' => 'First member', 'email' => 'First@EXAMPLE.com',
            'role' => UserRole::Member->value, 'unit' => 'Unit 01', 'is_active' => true,
            'designation_id' => Designation::where('is_active', true)->value('id'),
            'password' => 'Strong!Pass123', 'password_confirmation' => 'Strong!Pass123',
        ];
        $this->post(route('users.store'), $data)->assertRedirect(route('users.index'));
        $first = User::where('email', 'first@example.com')->firstOrFail();
        $this->assertSame('260001', $first->membership_number);
        $this->post(route('users.store'), array_replace($data, ['email' => 'FIRST@Example.COM']))
            ->assertSessionHasErrors('email');
        $this->get('/users?search=260001')->assertOk()->assertSee('Membership No. 260001');
        $this->getJson('/loan-options/members?q=260001')->assertOk()
            ->assertJsonPath('results.0.id', $first->id)->assertJsonCount(1, 'results');
        $first->update(['is_active' => false]);
        $this->getJson('/loan-options/members?q=260001')->assertExactJson(['results' => []]);
        $this->delete(route('users.destroy', $first), ['confirmation' => 'DELETE'])->assertRedirect();
        $this->assertSame('260001', User::withTrashed()->findOrFail($first->id)->membership_number);
        $this->post(route('users.store'), $data)->assertSessionHasErrors('email');
        $second = User::factory()->create();
        $this->assertSame('260002', $second->membership_number);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame('270001', User::factory()->create()->membership_number);
        $second->update(['is_active' => false]);
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => $second->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $first->email, 'password' => $data['password']])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_member_and_managing_staff_can_download_the_card_from_authorized_routes(): void
    {
        $member = User::factory()->create();
        $this->signIn($member);
        $this->get(route('member.home'))->assertOk()->assertSee('Download Library Card')->assertSee($member->membership_number)
            ->assertSee('avatar avatar-sm flex-shrink-0', false)
            ->assertSeeInOrder(['My Profile', 'Change Password', 'Sign out']);
        $card = $this->get(route('member.library-card'))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertDownload('library-card-'.$member->membership_number.'.pdf');
        $this->assertStringStartsWith('%PDF-', $card->getContent());
        $this->get(route('users.library-card', $member))->assertForbidden();
        $this->signIn(User::factory()->create(['role' => UserRole::Librarian]));
        $this->get(route('users.edit', $member))->assertOk()->assertSee('Download Library Card');
        $this->get(route('users.library-card', $member))->assertOk();
        $this->get(route('member.library-card'))->assertForbidden();
        $this->signIn(User::factory()->create(['role' => UserRole::Staff]));
        $this->get(route('users.library-card', $member))->assertForbidden();
    }

    public function test_multi_book_issue_has_one_optional_receipt_with_saved_member_and_book_details(): void
    {
        $designation = Designation::firstOrFail();
        $staff = User::factory()->create(['role' => UserRole::Staff, 'designation_id' => $designation->id]);
        $member = User::factory()->create(['name' => 'Original member', 'designation_id' => $designation->id, 'unit' => 'Unit 01']);
        $publisher = Publisher::create(['title_bn' => 'Publisher']);
        $books = collect(['00123' => 'First book', '00124' => 'Second book'])->map(
            fn ($name, $accession) => Book::create([
                'accession_number' => $accession, 'title_bn' => $name,
                'publisher_id' => $publisher->id, 'call_number' => '800',
            ])
        );
        foreach ($books as $book) {
            $book->copies()->create(['accession_number' => $book->accession_number, 'is_active' => true]);
        }
        $this->signIn($staff);
        $payload = ['book_ids' => $books->pluck('id')->all(), 'member_id' => $member->id, 'confirmed' => true];
        $accessions = $books->pluck('accession_number', 'id')->all();
        $this->post(route('loans.store'), $payload + ['copy_accessions' => array_slice($accessions, 0, 1, true)])
            ->assertSessionHasErrors('copy_accessions');
        $this->assertDatabaseCount('book_loans', 0);
        $this->assertDatabaseCount('issue_receipts', 0);
        $this->post(route('loans.store'), $payload + ['copy_accessions' => $accessions])
            ->assertRedirect(route('issue-requests.index'))->assertSessionHasNoErrors()->assertSessionHas('issue_receipt_loan');
        $loans = BookLoan::orderBy('id')->get();
        $this->assertCount(2, $loans);
        $this->assertSame($loans[0]->issue_receipt_id, $loans[1]->issue_receipt_id);
        $receipt = IssueReceipt::sole();
        $this->assertSame('Original member', $receipt->member_name);
        $this->assertSame($member->membership_number, $receipt->membership_number);
        $this->assertSame($designation->name, $receipt->member_designation);
        $this->assertSame(\App\Support\Ui::unit($member->unit), $receipt->member_unit);
        $this->assertSame($staff->id, $receipt->issuer_user_id);
        $this->assertSame($staff->name, $receipt->issuer_name);
        $this->assertSame($designation->name, $receipt->issuer_designation);
        $member->update(['name' => 'New name']);
        $this->get('/issue-requests')->assertOk()->assertSee('Download Issue Receipt');
        foreach ($loans as $loan) {
            $pdf = $this->get(route('loans.receipt', $loan))->assertOk()
                ->assertDownload('library-receipt-ISS-'.$receipt->id.'.pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        }
        $returned = $loans->first();
        $other = $loans->last();
        $this->get(route('loans.return-receipt', $returned))->assertNotFound();
        $receiver = User::factory()->create(['role' => UserRole::Staff, 'designation_id' => $designation->id]);
        $this->signIn($receiver);
        $this->from(route('issue-requests.index'))->post(route('loans.return', $returned))
            ->assertRedirect(route('issue-requests.index'))->assertSessionHasNoErrors()
            ->assertSessionHas('return_receipt_loan', $returned->id);
        $this->get(route('issue-requests.index'))->assertOk()->assertSee('Download Return Receipt');
        $this->assertNull($other->fresh()->returned_at);
        $this->post(route('loans.return', $returned))->assertSessionHasErrors('loan');
        $receiverName = $receiver->name;
        $receiver->update(['name' => 'Renamed operator', 'designation_id' => null]);
        $this->assertDatabaseHas('book_loans', [
            'id' => $returned->id, 'returned_by' => $receiver->id,
            'return_receiver_name' => $receiverName,
            'return_receiver_designation' => $designation->name,
        ]);
        $pdf = $this->get(route('loans.return-receipt', $returned))->assertOk()
            ->assertDownload('library-receipt-RET-'.$returned->id.'.pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get(route('loans.return-receipt', $other))->assertNotFound();
        $this->get(route('issue-register.index'))->assertOk()
            ->assertSee(route('loans.return-receipt', $returned))
            ->assertDontSee(route('loans.return-receipt', $other));

        $this->signIn($member->fresh());
        $this->get(route('loans.receipt', $returned))->assertForbidden();
        $this->get(route('loans.return-receipt', $returned))->assertForbidden();
    }

    public function test_circulation_pages_open_when_the_route_cache_predates_return_receipts(): void
    {
        $this->signIn(User::factory()->create(['role' => UserRole::Staff]));
        $member = User::factory()->create();
        $publisher = Publisher::create(['title_bn' => 'Publisher']);
        $book = Book::create([
            'accession_number' => 'RETURN-CACHE', 'title_bn' => 'Previously returned book',
            'publisher_id' => $publisher->id, 'call_number' => '800',
        ]);
        $loan = BookLoan::create([
            'book_id' => $book->id, 'user_id' => $member->id,
            'issued_at' => now()->subDay(), 'returned_at' => now(),
        ]);
        BookIssueRequest::create([
            'book_id' => $book->id, 'user_id' => $member->id, 'loan_id' => $loan->id,
            'status' => 'approved', 'source' => 'staff_issue',
        ]);

        $router = $this->app['router'];
        $previousRoutes = new RouteCollection;
        foreach ($router->getRoutes() as $route) {
            if ($route->getName() !== 'loans.return-receipt') {
                $previousRoutes->add($route);
            }
        }
        $router->setCompiledRoutes($previousRoutes->compile());
        $this->app['url']->setRoutes($router->getRoutes());
        $this->assertFalse($router->has('loans.return-receipt'));

        $issueReceiptUrl = route('loans.receipt', $loan);
        $this->withSession(['return_receipt_loan' => $loan->id])->get('/issue-requests')
            ->assertOk()->assertSee('Previously returned book')->assertSee($issueReceiptUrl)
            ->assertDontSee('Download Return Receipt');
        $this->get('/issue-register')
            ->assertOk()->assertSee('Previously returned book')->assertSee($issueReceiptUrl);
    }

}
