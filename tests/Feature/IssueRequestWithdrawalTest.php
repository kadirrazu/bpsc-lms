<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Book,BookLoan,BookIssueRequest,Publisher,Designation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class IssueRequestWithdrawalTest extends TestCase {
 use RefreshDatabase;
 private function login(User $user): void {$this->actingAs($user)->withSession(['auth_version'=>$user->auth_version]);}
 private function requestEntry(?User $member=null): BookIssueRequest {
  $member??=User::factory()->create(['role'=>UserRole::Member]);$publisher=Publisher::firstOrCreate(['title_bn'=>'প্রকাশক']);
  $book=Book::create(['accession_number'=>'REQ-'.(Book::withTrashed()->count()+1),'title_bn'=>'অনুরোধের বই','publisher_id'=>$publisher->id,'call_number'=>'800','number_of_copies'=>2]);
  return BookIssueRequest::create(['book_id'=>$book->id,'user_id'=>$member->id]);
 }
 public function test_member_can_withdraw_own_pending_request_without_creating_a_loan(): void {
  $entry=$this->requestEntry();$this->login($entry->user);$url=route('member.requests.withdraw',$entry);
  $this->get('/member/requests')->assertOk()->assertSee($url,false)->assertSee('Withdraw this pending book request? It will no longer be available for approval.')->assertSee('data-confirm-delete',false);
  $this->post($url)->assertRedirect(route('member.requests'));$this->assertSame('withdrawn',$entry->fresh()->status);$this->assertNotNull($entry->fresh()->withdrawn_at);$this->assertNull($entry->fresh()->reviewed_at);$this->assertDatabaseCount('book_loans',0);$this->assertSame(0,$entry->book->fresh()->total_issue_count);
  $this->assertDatabaseHas('audit_logs',['action'=>'book.issue_request_withdrawn','subject_id'=>$entry->id]);
  $this->get('/member/requests')->assertOk()->assertSee('Withdrawn')->assertDontSee($url,false);
  $this->post($url)->assertSessionHasErrors('request');
  $this->post(route('member.books.request',$entry->book))->assertRedirect(route('member.requests'));$this->assertDatabaseCount('book_issue_requests',2);
 }
 public function test_member_cannot_withdraw_someone_elses_request_or_use_management_review(): void {
  $entry=$this->requestEntry();$this->login(User::factory()->create(['role'=>UserRole::Member]));
  $this->post(route('member.requests.withdraw',$entry))->assertForbidden();$this->assertSame('pending',$entry->fresh()->status);
  $this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertForbidden();
  $this->login(User::factory()->create(['role'=>UserRole::Librarian]));$this->post(route('member.requests.withdraw',$entry))->assertForbidden();
 }
 public function test_approval_prevents_withdrawal_and_shows_actual_issue_date(): void {
  $entry=$this->requestEntry();$this->login(User::factory()->create(['role'=>UserRole::Admin]));$this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertRedirect();$loan=$entry->fresh()->loan;
  $this->login($entry->user);$this->post(route('member.requests.withdraw',$entry))->assertSessionHasErrors('request');$this->assertSame('approved',$entry->fresh()->status);$this->assertNull($entry->fresh()->withdrawn_at);$this->assertDatabaseCount('book_loans',1);
  $this->get('/member/requests')->assertOk()->assertSee(\App\Support\Ui::date($loan->issued_at,'d M Y, h:i A'))->assertDontSee(route('member.requests.withdraw',$entry),false);
 }
 public function test_withdrawal_prevents_later_approval_and_rejected_requests_cannot_be_withdrawn(): void {
  $entry=$this->requestEntry();$this->login($entry->user);$this->post(route('member.requests.withdraw',$entry))->assertRedirect();
  $this->login(User::factory()->create(['role'=>UserRole::Librarian]));$this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertSessionHasErrors('request');$this->assertDatabaseCount('book_loans',0);
  $rejected=$this->requestEntry();$this->post(route('issue-requests.review',$rejected),['decision'=>'reject'])->assertRedirect();$this->login($rejected->user);$this->post(route('member.requests.withdraw',$rejected))->assertSessionHasErrors('request');$this->assertSame('rejected',$rejected->fresh()->status);
 }
 public function test_managers_see_member_details_and_withdrawal_but_mobile_is_optional(): void {
  $designation=Designation::where('name','Librarian')->firstOrFail();$member=User::factory()->create(['role'=>UserRole::Member,'name'=>'Test Reader','designation_id'=>$designation->id,'unit'=>'Unit 01','mobile_number'=>'01912234567']);$entry=$this->requestEntry($member);$this->login($member);$this->post(route('member.requests.withdraw',$entry));
  foreach([UserRole::Admin,UserRole::Librarian] as $role){$this->login(User::factory()->create(['role'=>$role]));$this->get('/issue-requests')->assertOk()->assertSee('Test Reader')->assertSee('Librarian, Unit 01')->assertSee($member->email)->assertSee('01912234567')->assertSee('Withdrawn by member')->assertViewHas('requests',fn($rows)=>$rows->first()->user->relationLoaded('designation'));}
  $member->update(['mobile_number'=>null]);$this->get('/issue-requests')->assertOk()->assertDontSee('01912234567');
 }

 public function test_missing_withdrawal_migration_is_a_validation_error_without_mutating_request(): void {
  $entry=$this->requestEntry();$this->login($entry->user);
  \Illuminate\Support\Facades\Schema::partialMock()->shouldReceive('hasColumn')->once()->with('book_issue_requests','withdrawn_at')->andReturn(false);
  $this->post(route('member.requests.withdraw',$entry))->assertSessionHasErrors('request');
  $this->assertSame('pending',$entry->fresh()->status);$this->assertNull($entry->fresh()->withdrawn_at);$this->assertDatabaseCount('book_loans',0);
 }
}
