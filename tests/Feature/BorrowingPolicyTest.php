<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{Book,BookLoan,BookIssueRequest,User,Publisher};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class BorrowingPolicyTest extends TestCase {
 use RefreshDatabase;
 private function postIssue(array $data){return $this->post(route('loans.store'),array_merge(['confirmed'=>true],$data));}
 private function login(User $u): void {$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);}
 private function book(): Book {$p=Publisher::firstOrCreate(['title_bn'=>'প্রকাশক']);return Book::create(['accession_number'=>'POL-'.(Book::withTrashed()->count()+1),'title_bn'=>'বই','publisher_id'=>$p->id,'call_number'=>'800','number_of_copies'=>5]);}
 private function loan(User $member): BookLoan {return BookLoan::create(['book_id'=>$this->book()->id,'user_id'=>$member->id,'quantity'=>1,'issued_at'=>now()]);}
 private function member(): User {return User::factory()->create(['role'=>UserRole::Member]);}
 private function operator(UserRole $role=UserRole::Librarian): User {return User::factory()->create(['role'=>$role]);}
 public function test_member_request_limit_counts_active_and_pending_slots_and_releases_them(): void {
  $u=$this->member();$loan=$this->loan($u);$this->login($u);$book=$this->book();$this->post(route('member.books.request',$book))->assertRedirect();
  $third=$this->book();$this->post(route('member.books.request',$third))->assertSessionHasErrors('request');
  $entry=BookIssueRequest::firstOrFail();$this->post(route('member.requests.withdraw',$entry))->assertRedirect();$this->post(route('member.books.request',$third))->assertRedirect();
  $loan->update(['returned_at'=>now()]);$this->post(route('member.books.request',$this->book()))->assertRedirect();
 }
 public function test_model_blocks_a_third_active_copy_when_validation_is_bypassed(): void {
  $u=$this->member();$this->loan($u);$this->loan($u);try{$this->loan($u);$this->fail('Expected borrowing limit.');}catch(ValidationException $e){$this->assertArrayHasKey('loan',$e->errors());}
  $this->assertSame(2,BookLoan::where('user_id',$u->id)->whereNull('returned_at')->sum('quantity'));
 }
 public function test_new_loans_are_due_after_exactly_fifteen_days_and_return_does_not_extend_them(): void {
  $this->freezeTime();$u=$this->member();$loan=$this->loan($u);$this->assertTrue($loan->due_at->equalTo($loan->issued_at->copy()->addDays(15)));$due=$loan->due_at->toDateTimeString();
  $this->travel(2)->days();$loan->update(['returned_at'=>now()]);$this->assertSame($due,$loan->fresh()->due_at->toDateTimeString());$this->travelBack();
 }
 public function test_librarian_over_limit_issue_requires_a_server_confirmation_before_creating_anything(): void {
  $member=$this->member();$this->loan($member);$this->loan($member);$operator=$this->operator();$this->login($operator);$book=$this->book();$data=['member_id'=>$member->id,'book_id'=>$book->id];
  $response=$this->postIssue($data)->assertOk()->assertViewIs('loans.confirm-limit')->assertSee('2 books on loan');$this->assertDatabaseCount('book_loans',2);$token=$response->viewData('token');
  $this->postIssue($data+['confirmation_token'=>$token])->assertRedirect(route('issue-requests.index'));
  $loan=BookLoan::latest('id')->firstOrFail();$this->assertSame(3,(int)BookLoan::where('user_id',$member->id)->whereNull('returned_at')->sum('quantity'));$this->assertSame($operator->id,(int)$loan->limit_override_by);$this->assertTrue($loan->due_at->equalTo($loan->issued_at->copy()->addDays(15)));$this->assertDatabaseHas('audit_logs',['action'=>'book.loan_limit_overridden']);
  $this->postIssue($data+['confirmation_token'=>$token])->assertSessionHasErrors('loan');$this->assertDatabaseCount('book_loans',3);
 }
 public function test_admin_staff_and_member_cannot_forge_a_limit_override(): void {
  $member=$this->member();$this->loan($member);$this->loan($member);$book=$this->book();$data=['member_id'=>$member->id,'book_id'=>$book->id,'confirmation_token'=>str_repeat('a',64),'override'=>true];
  foreach([UserRole::Admin,UserRole::Staff] as $role){$this->login($this->operator($role));$this->postIssue($data)->assertSessionHasErrors('loan');}
  $this->login($member);$this->postIssue($data)->assertForbidden();$this->get(route('loans.create'))->assertForbidden();$this->assertDatabaseCount('book_loans',2);
 }
 public function test_stale_confirmation_reprompts_when_the_active_count_changes(): void {
  $member=$this->member();$first=$this->loan($member);$this->loan($member);$this->login($this->operator());$book=$this->book();$data=['member_id'=>$member->id,'book_id'=>$book->id];
  $response=$this->postIssue($data);$token=$response->viewData('token');
  $extra=$this->postIssue(['member_id'=>$member->id,'book_id'=>$this->book()->id]);$newToken=$extra->viewData('token');$this->postIssue(['member_id'=>$member->id,'book_id'=>$extra->viewData('book')->id,'confirmation_token'=>$newToken])->assertRedirect();
  $this->postIssue($data+['confirmation_token'=>$token])->assertOk()->assertViewHas('active',3);$this->assertDatabaseCount('book_loans',3);
 }
 public function test_approval_rechecks_limit_and_confirmed_librarian_override_is_recorded(): void {
  $member=$this->member();$book=$this->book();$entry=BookIssueRequest::create(['book_id'=>$book->id,'user_id'=>$member->id]);$this->loan($member);$this->loan($member);
  $this->login($this->operator(UserRole::Staff));$this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertSessionHasErrors('loan');$this->assertSame('pending',$entry->fresh()->status);
  $this->login($this->operator());$response=$this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertOk()->assertViewIs('loans.confirm-limit');$this->assertSame('pending',$entry->fresh()->status);
  $this->post(route('issue-requests.review',$entry),['decision'=>'approve','confirmation_token'=>$response->viewData('token')])->assertRedirect();$this->assertSame('approved',$entry->fresh()->status);$this->assertNotNull($entry->fresh()->loan->due_at);
 }
 public function test_user_totals_include_repeated_borrowing_and_returns_release_the_active_count(): void {
  $member=$this->member();$first=$this->loan($member);$this->loan($member);$first->update(['returned_at'=>now()]);$this->loan($member);$this->login($this->operator(UserRole::Admin));
  $this->get('/users')->assertOk()->assertSee('Total Borrowed')->assertSee('On Loan')->assertViewHas('users',fn($rows)=>(int)$rows->firstWhere('id',$member->id)->total_borrowed===3 && (int)$rows->firstWhere('id',$member->id)->on_loan===2);
 }
 public function test_confirmation_is_bound_to_the_member_and_book_and_cannot_bypass_stock(): void {
  $member=$this->member();$this->loan($member);$this->loan($member);$this->login($this->operator());$book=$this->book();$data=['member_id'=>$member->id,'book_id'=>$book->id];$token=$this->postIssue($data)->viewData('token');
  $other=$this->book();$this->postIssue(['member_id'=>$member->id,'book_id'=>$other->id,'confirmation_token'=>$token])->assertOk()->assertViewIs('loans.confirm-limit');$this->assertDatabaseCount('book_loans',2);
  $book->update(['number_of_copies'=>0]);$this->postIssue($data+['confirmation_token'=>$token])->assertSessionHasErrors('quantity');$this->assertDatabaseCount('book_loans',2);
 }

 public function test_copy_quantity_counts_toward_the_limit_and_client_fields_cannot_override_it(): void {
  $member=$this->member();$book=$this->book();BookLoan::create(['book_id'=>$book->id,'user_id'=>$member->id,'quantity'=>2,'issued_at'=>now()]);$this->login($member);
  $this->post(route('member.books.request',$this->book()),['override'=>true,'limit_override_by'=>999,'due_at'=>now()->addYear()->toDateTimeString()])->assertSessionHasErrors('request');
  $this->assertDatabaseCount('book_loans',1);$this->assertDatabaseCount('book_issue_requests',0);
 }
 public function test_lookup_and_direct_issue_are_allowed_only_for_library_operators(): void {
  $member=$this->member();$book=$this->book();$this->login($member);$this->getJson(route('loans.options','members'))->assertForbidden();
  $this->login($this->operator(UserRole::Staff));$this->get(route('loans.create'))->assertOk()->assertSee('Issue for 15 Days');
  $this->getJson(route('loans.options','members'))->assertOk()->assertJsonFragment(['id'=>$member->id]);
  $this->postIssue(['member_id'=>$member->id,'book_id'=>$book->id,'due_at'=>now()->addYear()->toDateTimeString()])->assertRedirect();
  $loan=BookLoan::firstOrFail();$this->assertTrue($loan->due_at->equalTo($loan->issued_at->copy()->addDays(15)));$this->assertDatabaseHas('book_issue_requests',['loan_id'=>$loan->id,'source'=>'staff_issue','status'=>'approved']);
 }
}
