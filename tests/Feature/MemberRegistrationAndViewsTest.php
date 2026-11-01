<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Designation,Unit,MemberRegistration,Book,Publisher};
use App\Services\Library\BookSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
class MemberRegistrationAndViewsTest extends TestCase {
 use RefreshDatabase;
 private function payload(): array {
  $designation=Designation::create(['name'=>'Reader','slug'=>'reader','is_active'=>true,'sort_order'=>0]);
  $unit=Unit::create(['code'=>'test-unit','name'=>'Test Unit','is_active'=>true,'sort_order'=>0]);
  return ['name'=>'New Member','email'=>'new@example.com','designation_id'=>$designation->id,'unit'=>$unit->code,'mobile_number'=>'01912234567','password'=>'Strong!Pass123','password_confirmation'=>'Strong!Pass123'];
 }
 private function login(UserRole $role): User {$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);return $u;}
 public function test_login_links_to_public_registration_and_form_has_no_role_selector(): void {
  $this->get('/login')->assertOk()->assertSee(route('register'),false)->assertSee('Join as a Library Member');
  $this->get('/register')->assertOk()->assertSee('Mobile Number')->assertSee('Register only if you do not already have an account.')->assertSee('data-password-feedback',false)->assertSee('data-password-toggle',false)->assertDontSee('name="role"',false);
 }
 public function test_submission_is_pending_hashed_and_cannot_sign_in(): void {
  $data=$this->payload();$this->post('/register',$data+['role'=>'admin','is_active'=>true])->assertRedirect('/login');
  $entry=MemberRegistration::firstOrFail();$this->assertSame('pending',$entry->status);$this->assertSame('01912234567',$entry->mobile_number);$this->assertTrue(Hash::check($data['password'],$entry->password));
  $this->assertDatabaseMissing('users',['email'=>$data['email']]);$this->assertArrayNotHasKey('password',$entry->toArray());
  $this->post('/login',['email'=>$data['email'],'password'=>$data['password']])->assertSessionHasErrors();$this->assertGuest();
 }
 public function test_registration_requires_eleven_ascii_digits_and_matching_strong_password(): void {
  $data=$this->payload();$this->post('/register',array_replace($data,['mobile_number'=>'','password_confirmation'=>'wrong']))->assertSessionHasErrors(['mobile_number','password']);
  foreach(['1912234567','019122345678','01912abc567'] as $mobile)$this->post('/register',array_replace($data,['mobile_number'=>$mobile]))->assertSessionHasErrors('mobile_number');
 }
 public function test_duplicate_pending_and_existing_emails_are_rejected(): void {
  $data=$this->payload();$this->post('/register',$data)->assertRedirect();$this->post('/register',$data)->assertSessionHasErrors('email');
  User::factory()->create(['email'=>'existing@example.com']);$this->post('/register',array_replace($data,['email'=>'existing@example.com']))->assertSessionHasErrors('email');
 }
 public function test_admin_can_approve_once_and_member_can_sign_in(): void {
  $data=$this->payload();$this->post('/register',$data);$admin=$this->login(UserRole::Admin);$entry=MemberRegistration::firstOrFail();
  $this->get('/dashboard')->assertOk()->assertSee('Pending Member Registrations: 1');$this->get('/users/registration-requests')->assertOk()->assertSee($data['mobile_number']);
  $this->post(route('users.registrations.approve',$entry))->assertRedirect();
  $user=User::where('email',$data['email'])->firstOrFail();$this->assertSame(UserRole::Member,$user->role);$this->assertTrue($user->is_active);$this->assertTrue(Hash::check($data['password'],$user->password));$this->assertSame($data['mobile_number'],$user->mobile_number);
  $this->assertSame($admin->id,$entry->fresh()->reviewed_by);$this->assertNull($entry->fresh()->password);
  $this->post(route('users.registrations.approve',$entry))->assertSessionHasErrors('registration');
  $this->post('/logout');$this->post('/login',['email'=>$data['email'],'password'=>$data['password']])->assertSessionHasNoErrors();$this->assertAuthenticatedAs($user);$this->get('/member/catalog')->assertOk();
  $this->assertDatabaseHas('audit_logs',['action'=>'member.registration_approved']);
 }
 public function test_librarian_can_approve_but_staff_and_members_cannot(): void {
  $data=$this->payload();$this->post('/register',$data);$entry=MemberRegistration::firstOrFail();
  foreach([UserRole::Staff,UserRole::Member] as $role){$this->login($role);$this->get('/users/registration-requests')->assertForbidden();$this->post(route('users.registrations.approve',$entry))->assertForbidden();}
  $this->login(UserRole::Librarian);$this->post(route('users.registrations.approve',$entry))->assertRedirect();$this->assertSame('approved',$entry->fresh()->status);
 }
 public function test_approval_rejects_email_collision_without_consuming_request(): void {
  $data=$this->payload();$this->post('/register',$data);User::factory()->create(['email'=>$data['email']]);$this->login(UserRole::Admin);$entry=MemberRegistration::firstOrFail();
  $this->post(route('users.registrations.approve',$entry))->assertSessionHasErrors('registration');$this->assertSame('pending',$entry->fresh()->status);
 }
 public function test_backend_mobile_is_optional_string_and_survives_edit(): void {
  $data=$this->payload();$this->login(UserRole::Admin);$data['role']='library_member';
  $this->post('/users',$data)->assertRedirect('/users');$user=User::where('email',$data['email'])->firstOrFail();$this->assertSame('01912234567',$user->mobile_number);
  $this->put('/users/'.$user->id,array_replace($data,['mobile_number'=>null,'is_active'=>true]))->assertRedirect('/users');$this->assertNull($user->fresh()->mobile_number);
  $this->put('/users/'.$user->id,array_replace($data,['mobile_number'=>'123']))->assertSessionHasErrors('mobile_number');
 }
 public function test_partial_identifiers_match_any_position_and_escape_wildcards(): void {
  $publisher=Publisher::create(['title_bn'=>'প্রকাশক']);
  foreach([['AA-123-Z','800.02.MK'],['AA-999-Z','900.XX'],['AA-%_literal','100']] as [$accession,$call])Book::create(['accession_number'=>$accession,'call_number'=>$call,'publisher_id'=>$publisher->id,'title_bn'=>'বই','number_of_copies'=>1]);
  $search=app(BookSearch::class);
  $this->assertSame(1,$search->query(['accession_number'=>'123','number_match'=>'partial'])->count());
  $this->assertSame(1,$search->query(['call_number'=>'.02.','number_match'=>'partial'])->count());
  $this->assertSame(1,$search->query(['accession_number'=>'%_','number_match'=>'partial'])->count());
  $this->assertSame(0,$search->query(['accession_number'=>'123','number_match'=>'exact'])->count());
  $this->login(UserRole::Admin);$this->get('/books?view=list&number_match=partial&accession_number=123')->assertOk()->assertSee('AA-123-Z')->assertSee('Title / Authors')->assertDontSee('AA-999-Z');
  $this->get('/books?view=grid')->assertOk()->assertSee('book-grid',false);$this->getJson('/books?view=invalid')->assertUnprocessable();
 }

 public function test_member_request_is_owned_and_staff_approval_issues_once_then_returns(): void {
  $publisher=Publisher::create(['title_bn'=>'প্রকাশক']);$book=Book::create(['accession_number'=>'REQ-1','call_number'=>'800','publisher_id'=>$publisher->id,'title_bn'=>'বই','number_of_copies'=>1]);
  $member=$this->login(UserRole::Member);$this->post(route('member.books.request',$book),['user_id'=>999,'quantity'=>99])->assertRedirect(route('member.requests'));
  $entry=\App\Models\BookIssueRequest::firstOrFail();$this->assertSame($member->id,$entry->user_id);
  $this->post(route('member.books.request',$book))->assertSessionHasErrors('request');$this->assertDatabaseCount('book_issue_requests',1);
  $this->login(UserRole::Member);$this->get('/member/requests')->assertOk()->assertViewHas('requests',fn($rows)=>$rows->isEmpty());$this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertForbidden();
  $this->login(UserRole::Staff);$this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertRedirect();
  $loan=$entry->fresh()->loan;$this->assertNotNull($loan);$this->assertSame($member->id,$loan->user_id);$this->assertSame(1,$loan->quantity);$this->assertSame(1,(int)$book->fresh()->total_issue_count);
  $this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertSessionHasErrors('request');$this->assertDatabaseCount('book_loans',1);
  $this->post(route('loans.return',$loan))->assertRedirect();$this->assertNotNull($loan->fresh()->returned_at);$this->assertSame(1,(int)$book->fresh()->total_issue_count);
  $this->actingAs($member)->withSession(['auth_version'=>$member->auth_version]);$this->get('/member/requests')->assertOk()->assertSee('Returned');
 }
 public function test_issue_approval_rechecks_stock_and_rejection_never_creates_loan(): void {
  $publisher=Publisher::create(['title_bn'=>'প্রকাশক']);$book=Book::create(['accession_number'=>'REQ-2','call_number'=>'800','publisher_id'=>$publisher->id,'title_bn'=>'বই','number_of_copies'=>1]);
  $this->login(UserRole::Member);$this->post(route('member.books.request',$book));$entry=\App\Models\BookIssueRequest::firstOrFail();$other=User::factory()->create(['role'=>UserRole::Member]);
  \App\Models\BookLoan::create(['book_id'=>$book->id,'user_id'=>$other->id,'quantity'=>1,'issued_at'=>now()]);
  $this->login(UserRole::Librarian);$this->post(route('issue-requests.review',$entry),['decision'=>'approve'])->assertSessionHasErrors('quantity');$this->assertSame('pending',$entry->fresh()->status);
  $this->post(route('issue-requests.review',$entry),['decision'=>'reject'])->assertRedirect();$this->assertSame('rejected',$entry->fresh()->status);$this->assertDatabaseCount('book_loans',1);
 }
}
