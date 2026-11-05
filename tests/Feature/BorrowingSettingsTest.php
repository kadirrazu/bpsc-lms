<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{Book,BookLoan,Publisher,User};
use App\Services\Library\BorrowingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class BorrowingSettingsTest extends TestCase {
 use RefreshDatabase;
 private function login(UserRole $role): User {$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);return $u;}
 private function book(): Book {$p=Publisher::firstOrCreate(['title_bn'=>'প্রকাশক']);return Book::create(['accession_number'=>'SET-'.(Book::withTrashed()->count()+1),'title_bn'=>'বই','publisher_id'=>$p->id,'call_number'=>'800','number_of_copies'=>5]);}
 private function settings(int $limit,int $days): void {$this->login(UserRole::Admin);$this->put(route('borrowing-settings.update'),['loan_limit'=>$limit,'loan_days'=>$days])->assertRedirect()->assertSessionHasNoErrors();}
 private function loan(User $member): BookLoan {return BookLoan::create(['book_id'=>$this->book()->id,'user_id'=>$member->id,'quantity'=>1,'issued_at'=>now()]);}
 public function test_only_administrator_can_read_or_update_settings(): void {
  foreach([UserRole::Librarian,UserRole::Staff,UserRole::Member] as $role){$this->login($role);$this->get(route('borrowing-settings.edit'))->assertForbidden();$this->put(route('borrowing-settings.update'),['loan_limit'=>3,'loan_days'=>21])->assertForbidden();}
  $this->login(UserRole::Admin);$this->get(route('borrowing-settings.edit'))->assertOk()->assertViewHas('settings',fn($s)=>$s['loan_limit']===2 && $s['loan_days']===15);
 }
 public function test_settings_validate_persist_and_record_an_audit_event(): void {
  $this->login(UserRole::Admin);
  foreach([[0,15],[101,15],[2,0],[2,366],['bad',15]] as [$limit,$days])$this->put(route('borrowing-settings.update'),['loan_limit'=>$limit,'loan_days'=>$days])->assertSessionHasErrors();
  $this->settings(3,21);$this->assertDatabaseHas('borrowing_settings',['id'=>1,'loan_limit'=>3,'loan_days'=>21,'revision'=>2]);$this->assertDatabaseHas('audit_logs',['action'=>'library.borrowing_policy_updated']);$this->assertSame(21,app(BorrowingPolicy::class)->days());
 }
 public function test_dynamic_limit_and_period_apply_to_direct_issue_and_model_guard(): void {
  $this->freezeTime();$this->settings(1,21);$member=User::factory()->create(['role'=>UserRole::Member]);$this->login(UserRole::Staff);$book=$this->book();
  $this->get(route('loans.create'))->assertOk()->assertSee('Issue for 21 Days');
  $this->post(route('loans.store'),['confirmed'=>true,'member_id'=>$member->id,'book_id'=>$book->id])->assertRedirect()->assertSessionHasNoErrors();$loan=BookLoan::firstOrFail();$this->assertTrue($loan->due_at->equalTo($loan->issued_at->copy()->addDays(21)));
  $this->post(route('loans.store'),['confirmed'=>true,'member_id'=>$member->id,'book_id'=>$this->book()->id])->assertSessionHasErrors('loan');
  try{$this->loan($member);$this->fail('Expected dynamic borrowing limit.');}catch(ValidationException $e){$this->assertArrayHasKey('loan',$e->errors());}$this->assertDatabaseCount('book_loans',1);
 }
 public function test_member_pending_requests_use_the_configured_limit(): void {
  $this->settings(1,21);$this->login(UserRole::Member);$this->post(route('member.books.request',$this->book()))->assertRedirect()->assertSessionHasNoErrors();$this->post(route('member.books.request',$this->book()))->assertSessionHasErrors('request');$this->assertDatabaseCount('book_issue_requests',1);
 }
 public function test_policy_changes_preserve_existing_due_dates_and_change_renewal_default(): void {
  $this->freezeTime();$member=User::factory()->create(['role'=>UserRole::Member]);$loan=$this->loan($member);$due=$loan->due_at->toDateTimeString();$this->settings(3,21);$this->assertSame($due,$loan->fresh()->due_at->toDateTimeString());$this->assertTrue($this->loan($member)->due_at->equalTo(now()->addDays(21)->startOfSecond()));
  $this->travel(16)->days();$expected=now()->addDays(21)->startOfSecond();$this->login(UserRole::Librarian);$this->post(route('issue-register.action',['loan'=>$loan->id,'action'=>'renew']),['confirmed'=>true,'expected_renewal_count'=>0])->assertRedirect()->assertSessionHasNoErrors();$this->assertTrue($loan->fresh()->due_at->equalTo($expected));
 }
 public function test_renew_and_fine_are_hidden_and_rejected_until_due_time_passes(): void {
  $this->freezeTime();$member=User::factory()->create(['role'=>UserRole::Member]);$loan=$this->loan($member);$this->login(UserRole::Librarian);
  $this->get(route('issue-register.index'))->assertOk()->assertDontSee('data-circulation-confirm="renew"',false)->assertDontSee('data-circulation-confirm="fine"',false);
  foreach(['renew','fine'] as $action)$this->post(route('issue-register.action',['loan'=>$loan->id,'action'=>$action]),['confirmed'=>true,'days'=>15,'expected_renewal_count'=>0])->assertSessionHasErrors('loan');
  $this->assertSame(0,$loan->fresh()->renewal_count);$this->assertFalse($loan->fresh()->is_fined);$this->travel(16)->days();
  $this->get(route('issue-register.index'))->assertOk()->assertSee('data-circulation-confirm="renew"',false)->assertSee('data-circulation-confirm="fine"',false);
  $this->post(route('issue-register.action',['loan'=>$loan->id,'action'=>'fine']),['confirmed'=>true])->assertRedirect()->assertSessionHasNoErrors();$this->assertTrue($loan->fresh()->is_fined);
 }
 public function test_configuration_change_invalidates_a_pending_librarian_override(): void {
  $member=User::factory()->create(['role'=>UserRole::Member]);$this->loan($member);$this->loan($member);$operator=$this->login(UserRole::Librarian);$book=$this->book();$data=['confirmed'=>true,'member_id'=>$member->id,'book_id'=>$book->id];$response=$this->post(route('loans.store'),$data)->assertOk();$token=$response->viewData('token');
  $this->settings(2,21);$this->actingAs($operator)->withSession(['auth_version'=>$operator->auth_version]);$this->post(route('loans.store'),$data+['confirmation_token'=>$token])->assertOk()->assertViewIs('loans.confirm-limit');$this->assertDatabaseCount('book_loans',2);
 }
}
