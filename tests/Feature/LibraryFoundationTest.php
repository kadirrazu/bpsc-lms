<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Designation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class LibraryFoundationTest extends TestCase {
 use RefreshDatabase;
 private function actor(UserRole $role): User { return User::factory()->create(['role'=>$role]); }
 private function loginAs(User $u): static { $this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]); return $this; }
 public function test_all_four_roles_can_render_dashboard_and_profile_in_english(): void {
  foreach(UserRole::cases() as $role) { $u=$this->actor($role); $this->loginAs($u); if($role===UserRole::Member) $this->get('/dashboard')->assertRedirect('/member'); else $this->get('/dashboard')->assertOk()->assertSee('lang="en"',false); $this->get('/profile')->assertOk(); }
 }
 public function test_only_managers_can_view_audit_logs(): void {
  foreach(UserRole::cases() as $role) { $u=$this->actor($role); $this->loginAs($u)->get('/audit-logs')->assertStatus(in_array($role,[UserRole::Admin,UserRole::Librarian],true)?200:403); }
 }
 public function test_staff_cannot_delete_users_or_designations_or_view_roles(): void {
  $u=$this->actor(UserRole::Staff);$target=$this->actor(UserRole::Member);$d=Designation::create(['name'=>'পাঠক','slug'=>'reader','sort_order'=>1,'is_active'=>true]);
  $this->loginAs($u)->delete('/users/'.$target->id,['confirmation'=>'DELETE'])->assertForbidden();
  $this->delete('/designations/'.$d->id,['confirmation'=>'DELETE'])->assertForbidden();
  $this->get('/roles')->assertForbidden();$this->assertNotSoftDeleted($target);
 }
 public function test_member_cannot_access_management(): void {
  $u=$this->actor(UserRole::Member);$this->loginAs($u)->get('/users')->assertForbidden();$this->get('/designations')->assertForbidden();
 }
 public function test_librarian_cannot_edit_or_delete_administrator(): void {
  $u=$this->actor(UserRole::Librarian);$admin=$this->actor(UserRole::Admin);$this->loginAs($u)->get('/users/'.$admin->id.'/edit')->assertForbidden();$this->delete('/users/'.$admin->id,['confirmation'=>'DELETE'])->assertForbidden();
 }
 public function test_librarian_can_create_member_but_cannot_escalate_roles(): void {
  $u=$this->actor(UserRole::Librarian);$d=Designation::create(['name'=>'কর্মকর্তা','slug'=>'test-officer','sort_order'=>1,'is_active'=>true]);
  $data=['name'=>'নতুন পাঠক','email'=>'reader@example.test','designation_id'=>$d->id,'unit'=>'Unit 01','role'=>'admin','password'=>'Strong@Pass123','password_confirmation'=>'Strong@Pass123','is_active'=>1];
  $this->loginAs($u)->post('/users',$data)->assertSessionHasErrors('role');$this->assertDatabaseMissing('users',['email'=>$data['email']]);
  $data['role']='library_member';$this->post('/users',$data)->assertRedirect('/users');$this->assertDatabaseHas('users',['email'=>$data['email'],'role'=>'library_member']);
 }
 public function test_active_account_login_and_inactive_account_rejection(): void {
  $u=User::factory()->create(['password'=>'Strong@Pass123']);$this->post('/login',['email'=>$u->email,'password'=>'Strong@Pass123'])->assertRedirect('/dashboard');$this->assertAuthenticatedAs($u);
  $this->post('/logout');$u->update(['is_active'=>false]);$this->post('/login',['email'=>$u->email,'password'=>'Strong@Pass123'])->assertSessionHasErrors();$this->assertGuest();
 }
 public function test_old_exam_routes_and_language_switch_are_removed(): void {
  $u=$this->actor(UserRole::Admin);$this->loginAs($u);foreach(['/schedules','/calendar','/statistics','/exam-types'] as $path)$this->get($path)->assertNotFound();$this->post('/language',['locale'=>'en'])->assertNotFound();
 }
 public function test_account_changes_invalidate_existing_session(): void {
  $u=$this->actor(UserRole::Member);$this->loginAs($u);$u->update(['is_active'=>false]);$this->get('/dashboard')->assertRedirect('/login');$this->assertGuest();
 }
 public function test_admin_cannot_delete_self(): void {
  $u=$this->actor(UserRole::Admin);$this->loginAs($u)->delete('/users/'.$u->id,['confirmation'=>'DELETE'])->assertSessionHasErrors('confirmation');$this->assertNotSoftDeleted($u);
 }
 public function test_all_blade_templates_compile_to_valid_php(): void {
  $views = \Illuminate\Support\Facades\File::allFiles(resource_path('views'));
  foreach ($views as $view) {
   if (!str_ends_with($view->getFilename(), '.blade.php')) continue;
   $compiled = \Illuminate\Support\Facades\Blade::compileString($view->getContents());
   try {
    $tokens = token_get_all($compiled, TOKEN_PARSE);
   } catch (\ParseError $error) {
    $this->fail($view->getRelativePathname().': '.$error->getMessage());
   }
   $this->assertNotEmpty($tokens, $view->getRelativePathname());
  }
 }
}
