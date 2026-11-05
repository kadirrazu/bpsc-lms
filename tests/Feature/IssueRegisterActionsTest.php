<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Book,BookLoan,Publisher,Author,LoanReminder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class IssueRegisterActionsTest extends TestCase {
 use RefreshDatabase;
 private function login(User $u): void {$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);}
 private function member(): User {return User::factory()->create(['role'=>UserRole::Member]);}
 private function operator(UserRole $role=UserRole::Librarian): User {return User::factory()->create(['role'=>$role]);}
 private function book(): Book {
  $publisher=Publisher::firstOrCreate(['title_bn'=>'প্রকাশক']);$book=Book::create(['accession_number'=>'REG-'.(Book::withTrashed()->count()+1),'title_bn'=>'পুরনো শিরোনাম','publisher_id'=>$publisher->id,'call_number'=>'800','number_of_copies'=>2]);$author=Author::firstOrCreate(['title_bn'=>'প্রথম লেখক']);$book->authors()->attach($author);return $book;
 }
 private function issue(User $member): BookLoan {$book=$this->book();$this->post(route('loans.store'),['member_id'=>$member->id,'book_id'=>$book->id,'confirmed'=>true])->assertRedirect();return BookLoan::latest('id')->firstOrFail();}
 private function action(BookLoan $loan,string $action,array $data=[]){return $this->post(route('issue-register.action',['loan'=>$loan->id,'action'=>$action]),array_merge(['confirmed'=>true],$data));}
 public function test_direct_issue_requires_confirmation_and_book_details_offer_prefilled_issue_link(): void {
  $operator=$this->operator(UserRole::Staff);$this->login($operator);$member=$this->member();$book=$this->book();
  $this->post(route('loans.store'),['book_id'=>$book->id,'member_id'=>$member->id])->assertSessionHasErrors('confirmed');$this->assertDatabaseCount('book_loans',0);
  $this->get(route('books.show',$book))->assertOk()->assertSee(route('loans.create',['book_id'=>$book->id]),false);
  $this->get(route('loans.create',['book_id'=>$book->id]))->assertOk()->assertViewHas('book',fn($value)=>$value->id===$book->id)->assertSee('data-circulation-confirm="issue"',false);
 }
 public function test_register_preserves_book_and_issuer_snapshots_after_edits(): void {
  $operator=$this->operator();$this->login($operator);$loan=$this->issue($this->member());$this->assertSame($operator->id,(int)$loan->issued_by);$this->assertSame(['প্রথম লেখক'],$loan->authors_snapshot);
  $loan->book->update(['title_bn'=>'নতুন শিরোনাম','accession_number'=>'CHANGED']);$loan->book->authors->first()->update(['title_bn'=>'নতুন লেখক']);$operator->update(['name'=>'Changed Operator']);$operator=$operator->fresh();$this->login($operator);
  $this->get(route('issue-register.index'))->assertOk()->assertSee('পুরনো শিরোনাম')->assertSee('প্রথম লেখক')->assertSee($loan->accession_snapshot)->assertSee($loan->issuer_name);
 }
 public function test_reminder_is_visible_only_to_its_member_and_hidden_after_return(): void {
  $this->login($this->operator());$member=$this->member();$loan=$this->issue($member);$this->action($loan,'reminder')->assertRedirect();$this->action($loan,'reminder')->assertRedirect();$this->assertDatabaseCount('loan_reminders',1);
  $this->login($member);$this->get('/member')->assertOk()->assertSee('Book Return Reminders')->assertSee($loan->accession_snapshot);
  $this->login($this->member());$this->get('/member')->assertOk()->assertDontSee('Book Return Reminders')->assertDontSee($loan->accession_snapshot);
  $loan->update(['returned_at'=>now()]);$this->login($member);$this->get('/member')->assertOk()->assertDontSee('Book Return Reminders');
 }
 public function test_renewal_extends_due_date_without_new_issue_and_blocks_duplicate_confirmation(): void {
  $this->freezeTime();$this->login($this->operator());$loan=$this->issue($this->member());$this->travel(16)->days();$due=now()->startOfSecond();$count=$loan->book->total_issue_count;
  $this->action($loan,'renew',['days'=>15,'expected_renewal_count'=>0])->assertRedirect();$this->assertTrue($loan->fresh()->due_at->equalTo($due->addDays(15)));$this->assertSame(1,$loan->fresh()->renewal_count);$this->assertDatabaseCount('book_loans',1);$this->assertSame($count,$loan->book->fresh()->total_issue_count);
  $this->action($loan,'renew',['days'=>15,'expected_renewal_count'=>0])->assertSessionHasErrors('loan');$this->assertSame(1,$loan->fresh()->renewal_count);$this->assertDatabaseHas('audit_logs',['action'=>'book.renewed']);
 }
 public function test_overdue_renewal_uses_today_and_returned_loans_reject_renewal_and_reminders(): void {
  $this->freezeTime();$this->login($this->operator());$loan=$this->issue($this->member());$this->travel(20)->days();$expected=now()->addDays(7)->startOfSecond();
  $this->action($loan,'renew',['days'=>7,'expected_renewal_count'=>0])->assertRedirect();$this->assertTrue($loan->fresh()->due_at->equalTo($expected));
  $loan->refresh()->update(['returned_at'=>now()]);$this->action($loan,'renew',['days'=>15,'expected_renewal_count'=>1])->assertSessionHasErrors('loan');$this->action($loan,'reminder')->assertSessionHasErrors('loan');$this->travelBack();
 }
 public function test_fine_flag_is_filterable_exportable_and_can_be_cleared_on_archived_history(): void {
  $this->login($this->operator());$loan=$this->issue($this->member());$loan->forceFill(['due_at'=>now()->subDay(),'returned_at'=>now()])->save();$loan->book->delete();
  $this->action($loan,'fine')->assertRedirect();$this->assertTrue($loan->fresh()->is_fined);
  $this->get('/issue-register?fine=flagged')->assertOk()->assertViewHas('loans',fn($rows)=>$rows->total()===1);
  $this->get('/issue-register?fine=clear')->assertViewHas('loans',fn($rows)=>$rows->total()===0);
  $csv=$this->get('/issue-register/export?fine=flagged')->assertOk()->streamedContent();$this->assertStringContainsString('Fine Flag',$csv);$this->assertStringContainsString($loan->accession_snapshot,$csv);
  $this->action($loan,'clear-fine')->assertRedirect();$this->assertFalse($loan->fresh()->is_fined);$this->assertDatabaseHas('audit_logs',['action'=>'book.fine_flagged']);$this->assertDatabaseHas('audit_logs',['action'=>'book.fine_cleared']);
 }
 public function test_actions_require_confirmation_valid_days_and_authorized_role(): void {
  $this->login($this->operator());$member=$this->member();$loan=$this->issue($member);
  $this->post(route('issue-register.action',['loan'=>$loan->id,'action'=>'fine']))->assertSessionHasErrors('confirmed');
  foreach([0,366,'bad'] as $days)$this->action($loan,'renew',['days'=>$days,'expected_renewal_count'=>0])->assertSessionHasErrors('days');
  $this->login($member);foreach(['fine','reminder','renew'] as $action)$this->action($loan,$action,['days'=>15,'expected_renewal_count'=>0])->assertForbidden();$this->get('/issue-register')->assertForbidden();$this->get('/issue-register/export')->assertForbidden();
 }
 public function test_issue_management_menu_groups_the_operations_after_dashboard(): void {
  $this->login($this->operator(UserRole::Staff));$this->get('/dashboard')->assertOk()->assertSeeInOrder(['Dashboard','Issue Management','Issue Book','Issue Requests','Issue Register','Fine Reports','Books']);
 }
}
