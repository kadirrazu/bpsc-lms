<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Unit,AuditLog};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class LibraryUnitsAndMemberTest extends TestCase {
 use RefreshDatabase;
 private function loginRole(UserRole $role): User {
  $user=User::factory()->create(['role'=>$role]);
  $this->actingAs($user)->withSession(['auth_version'=>$user->auth_version]);return $user;
 }
 public function test_only_administrator_can_manage_units(): void {
  foreach(UserRole::cases() as $role) {
   $this->loginRole($role);
   $this->get('/units')->assertStatus($role===UserRole::Admin?200:403);
   $response=$this->post('/units',['name'=>'Restricted '.$role->value,'sort_order'=>1,'is_active'=>1]);
   if($role===UserRole::Admin)$response->assertRedirect('/units');else $response->assertForbidden();
  }
 }
 public function test_renaming_keeps_user_assignment_and_unit_changes_are_audited(): void {
  $this->loginRole(UserRole::Admin);$unit=Unit::where('code','Unit 01')->firstOrFail();
  $member=User::factory()->create(['role'=>UserRole::Member,'unit'=>$unit->code]);
  $this->put('/units/'.$unit->id,['name'=>'Renamed Unit','sort_order'=>5,'is_active'=>1])->assertRedirect('/units');
  $this->assertSame('Unit 01',$member->fresh()->unit);
  $this->assertSame('Renamed Unit',$unit->fresh()->name);
  $this->assertDatabaseHas('audit_logs',['action'=>'unit.updated','subject_id'=>$unit->id]);
  $this->delete('/units/'.$unit->id,['confirmation'=>'DELETE'])->assertSessionHasErrors('unit');
  $this->put('/units/'.$unit->id,['name'=>'Renamed Unit','sort_order'=>5,'is_active'=>0])->assertSessionHasErrors('is_active');
 }
 public function test_administrator_can_create_and_delete_unassigned_unit(): void {
  $this->loginRole(UserRole::Admin);$this->post('/units',['name'=>'Research','sort_order'=>3,'is_active'=>1])->assertRedirect('/units');
  $unit=Unit::where('name','Research')->firstOrFail();
  $this->assertDatabaseHas('audit_logs',['action'=>'unit.created','subject_id'=>$unit->id]);
  $this->delete('/units/'.$unit->id,['confirmation'=>'DELETE'])->assertRedirect('/units');
  $this->assertDatabaseMissing('units',['id'=>$unit->id]);
  $this->assertDatabaseHas('audit_logs',['action'=>'unit.deleted','subject_id'=>$unit->id]);
 }
 public function test_member_uses_separate_portal_including_profile_and_password(): void {
  $this->loginRole(UserRole::Member);$this->get('/dashboard')->assertRedirect('/member');
  foreach(['/member','/member/catalog','/member/requests','/profile','/profile/password'] as $path) $this->get($path)->assertOk()->assertSee('MEMBER PORTAL')->assertDontSee('Administration</button>',false);
  foreach(['/users','/units','/designations','/roles','/audit-logs'] as $path) $this->get($path)->assertForbidden();
 }
 public function test_staff_cannot_use_member_portal(): void { $this->loginRole(UserRole::Staff);$this->get('/member')->assertForbidden(); }
 public function test_original_scheduler_units_and_all_designations_are_seeded(): void {
  $this->seed(\Database\Seeders\DatabaseSeeder::class);
  foreach(config('library.seed_units') as $name)$this->assertDatabaseHas('units',['code'=>$name]);
  foreach(['System Manager','Senior System Analyst','Office Assistant cum Computer Operator','Librarian','Assistant Librarian','Law Adviser','Law Officer','Member','Chairman','Personal Secretary'] as $name)$this->assertDatabaseHas('designations',['name'=>$name]);
 }
 public function test_upgrade_keeps_legacy_members_and_custom_units(): void {
  $member=User::factory()->create(['role'=>UserRole::Member,'unit'=>'Legacy Custom Unit']);$password=$member->getRawOriginal('password');
  DB::table('users')->where('id',$member->id)->update(['role'=>'borrower']);Schema::drop('units');
  $migration=require database_path('migrations/2026_10_31_000002_add_library_units_and_member_role.php');$migration->up();
  $this->assertDatabaseHas('users',['id'=>$member->id,'role'=>'library_member','password'=>$password,'unit'=>'Legacy Custom Unit']);
  $this->assertDatabaseHas('units',['code'=>'Legacy Custom Unit','name'=>'Legacy Custom Unit']);
  $this->assertSame(UserRole::Member,$member->fresh()->role);
 }
}
