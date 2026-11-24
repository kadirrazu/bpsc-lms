<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{Book,BookLoan,BookIssueRequest,Designation,Unit,User,Publisher};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class BorrowingPolicyTest extends TestCase {
 use RefreshDatabase;
 private function postIssue(array $data){
  $bookIds = $data['book_ids'] ?? [$data['book_id'] ?? null];
  $data['copy_accessions'] ??= Book::whereKey($bookIds)->pluck('accession_number','id')->all();
  return $this->post(route('loans.store'),array_merge(['confirmed'=>true],$data));}
 private function login(User $u): void {$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);}
 private function book(): Book {$p=Publisher::firstOrCreate(['title_bn'=>'প্রকাশক']);$book=Book::create(['accession_number'=>'POL-'.(Book::withTrashed()->count()+1),'title_bn'=>'বই','publisher_id'=>$p->id,'call_number'=>'800','number_of_copies'=>5]);$book->copies()->create(['accession_number'=>$book->accession_number,'is_active'=>true]);return $book;}
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
 public function test_batch_issue_records_one_copy_per_title_and_can_issue_again_after_return(): void {
  $member=$this->member();
  $this->login($this->operator());
  $first=$this->book();$second=$this->book();
  $this->postIssue(['member_id'=>$member->id,'book_ids'=>[$first->id,$second->id]])
   ->assertRedirect(route('issue-requests.index'))->assertSessionHasNoErrors();
  $this->assertDatabaseCount('book_loans',2);
  $this->assertSame([1,1],BookLoan::orderBy('id')->pluck('quantity')->all());
  $this->assertDatabaseHas('audit_logs',['action'=>'book.batch_issued']);
  $this->postIssue(['member_id'=>$member->id,'book_id'=>$first->id])->assertSessionHasErrors('loan');
  BookLoan::where('book_id',$first->id)->firstOrFail()->update(['returned_at'=>now()]);
  $this->postIssue(['member_id'=>$member->id,'book_id'=>$first->id])->assertRedirect(route('issue-requests.index'));
  $this->assertDatabaseCount('book_loans',3);
 }
 public function test_batch_rejects_duplicate_titles_multiple_copies_and_selection_over_limit(): void {
  $this->login($this->operator());
  $member=$this->member();$first=$this->book();$second=$this->book();$third=$this->book();
  $base=['member_id'=>$member->id];
  $this->postIssue($base+['book_ids'=>[$first->id,$first->id]])->assertSessionHasErrors('book_ids.1');
  $this->postIssue($base+['book_id'=>$first->id,'quantity'=>2])->assertSessionHasErrors('quantity');
  $this->postIssue($base+['book_ids'=>[$first->id,$second->id,$third->id]])->assertSessionHasErrors('book_ids');
  $this->assertDatabaseCount('book_loans',0);
  $this->assertDatabaseCount('book_issue_requests',0);
 }
 public function test_all_operators_and_request_approval_obey_the_current_cap(): void {
  $member=$this->member();$this->loan($member);$this->loan($member);$book=$this->book();
  $entry=BookIssueRequest::create(['book_id'=>$book->id,'user_id'=>$member->id]);
  $data=['member_id'=>$member->id,'book_id'=>$book->id,'confirmation_token'=>str_repeat('a',64),'override'=>true];
  foreach([UserRole::Admin,UserRole::Librarian,UserRole::Staff] as $role){
   $this->login($this->operator($role));
   $this->postIssue($data)->assertSessionHasErrors('loan');
   $this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertSessionHasErrors('loan');
  }
  $this->assertSame('pending',$entry->fresh()->status);
  $this->login($member);$this->postIssue($data)->assertForbidden();$this->get(route('loans.create'))->assertForbidden();
  $this->assertDatabaseCount('book_loans',2);
 }
 public function test_unavailable_book_aborts_the_batch_and_is_excluded_from_search(): void {
  $this->login($this->operator());
  $member=$this->member();$first=$this->book();$second=$this->book();$second->update(['number_of_copies'=>1]);
  BookLoan::create(['book_id'=>$second->id,'user_id'=>$this->member()->id,'issued_at'=>now()]);
  BookLoan::create(['book_id'=>$first->id,'user_id'=>$this->member()->id,'issued_at'=>now()]);
  $this->getJson(route('loans.options','books'))->assertExactJson(['results'=>[
   ['id'=>$first->id,'label'=>$first->title_bn.' - Author not recorded','title'=>$first->title_bn,'authors'=>'','available_copies'=>4],
  ]]);
  $this->get(route('loans.create',['book_id'=>$first->id]))->assertOk()->assertSee('Available: 4 · Issuing: 1');
  $this->postIssue(['member_id'=>$member->id,'book_ids'=>[$first->id,$second->id]])->assertSessionHasErrors('quantity');
  $this->assertDatabaseCount('book_loans',2);
  $this->assertDatabaseCount('book_issue_requests',0);
 }
 public function test_user_totals_include_repeated_borrowing_and_returns_release_the_active_count(): void {
  $member=$this->member();$first=$this->loan($member);$this->loan($member);$first->update(['returned_at'=>now()]);$this->loan($member);$this->login($this->operator(UserRole::Admin));
  $this->get('/users')->assertOk()->assertSee('Total Borrowed')->assertSee('On Loan')->assertViewHas('users',fn($rows)=>(int)$rows->firstWhere('id',$member->id)->total_borrowed===3 && (int)$rows->firstWhere('id',$member->id)->on_loan===2);
 }
 public function test_model_rejects_multiple_copies_and_a_second_active_loan_for_the_same_title(): void {
  $member=$this->member();$book=$this->book();
  $data=['book_id'=>$book->id,'user_id'=>$member->id,'issued_at'=>now()];
  try{BookLoan::create($data+['quantity'=>2]);$this->fail('Expected quantity validation.');}
  catch(ValidationException $error){$this->assertArrayHasKey('quantity',$error->errors());}
  BookLoan::create($data);
  try{BookLoan::create($data);$this->fail('Expected duplicate title validation.');}
  catch(ValidationException $error){$this->assertArrayHasKey('loan',$error->errors());}
  $this->assertDatabaseCount('book_loans',1);
 }
 public function test_lookup_and_direct_issue_are_allowed_only_for_library_operators(): void {
  $member=$this->member();$book=$this->book();$this->login($member);$this->getJson(route('loans.options','members'))->assertForbidden();
  $this->login($this->operator(UserRole::Staff));$this->get(route('loans.create'))->assertOk()->assertSee('Issue for 15 Days');
  $designation=Designation::where('name','Assistant Director')->firstOrFail();
  $unit=Unit::where('name','Unit 01')->firstOrFail();
  $member->update(['designation_id'=>$designation->id,'unit'=>$unit->code]);
  $this->getJson(route('loans.options',['kind'=>'members','q'=>$member->email]))->assertOk()
   ->assertExactJson(['results'=>[['id'=>$member->id,'label'=>$member->name.' - Assistant Director - Unit 01 - #'.$member->membership_number,'on_loan'=>0,'pending_requests'=>0]]]);
  $this->postIssue(['member_id'=>$member->id,'book_id'=>$book->id,'due_at'=>now()->addYear()->toDateTimeString()])->assertRedirect();
  $loan=BookLoan::firstOrFail();$this->assertTrue($loan->due_at->equalTo($loan->issued_at->copy()->addDays(15)));$this->assertDatabaseHas('book_issue_requests',['loan_id'=>$loan->id,'source'=>'staff_issue','status'=>'approved']);
 }
}
