<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Book,Publisher,BookLoan,BookIssueRequest,Designation,Unit};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class IssueRequestSearchTest extends TestCase {
 use RefreshDatabase;
 private function login(UserRole $role=UserRole::Admin): void {$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);}
 private function entry(string $status='pending',bool $returned=false,?User $user=null): BookIssueRequest {
  $user??=User::factory()->create(['role'=>UserRole::Member]);$publisher=Publisher::firstOrCreate(['title_bn'=>'প্রকাশক']);$serial=Book::withTrashed()->count()+1;
  $book=Book::create(['accession_number'=>'ACC-'.$serial,'title_bn'=>'বাংলা বই '.$serial,'title_en'=>'English Title '.$serial,'publisher_id'=>$publisher->id,'call_number'=>'800.MK.'.$serial,'number_of_copies'=>2]);$loan=null;
  if($status==='approved')$loan=BookLoan::create(['book_id'=>$book->id,'user_id'=>$user->id,'quantity'=>1,'issued_at'=>now(),'returned_at'=>$returned?now():null]);
  return BookIssueRequest::create(['book_id'=>$book->id,'user_id'=>$user->id,'status'=>$status,'loan_id'=>$loan?->id,'withdrawn_at'=>$status==='withdrawn'?now():null]);
 }
 public function test_request_and_loan_status_filters_have_distinct_meanings(): void {
  $this->login();foreach(['pending','rejected','withdrawn','approved'] as $status)$this->entry($status);$this->entry('approved',true);
  foreach(['pending'=>1,'rejected'=>1,'withdrawn'=>1,'approved'=>2,'on_loan'=>1,'returned'=>1] as $status=>$count)$this->get('/issue-requests?status='.$status)->assertOk()->assertViewHas('requests',fn($rows)=>$rows->total()===$count);
  $this->get('/issue-requests')->assertViewHas('requests',fn($rows)=>$rows->total()===5);
 }
 public function test_common_search_covers_books_and_member_fields_and_combines_with_status(): void {
  $designation=Designation::create(['name'=>'Unique Reader Designation','slug'=>'unique-reader-designation','is_active'=>true,'sort_order'=>0]);$unit=Unit::create(['code'=>'unit-filter','name'=>'Unique Filter Department','sort_order'=>0,'is_active'=>true]);
  $member=User::factory()->create(['role'=>UserRole::Member,'name'=>'Lookup Reader','email'=>'lookup@example.test','mobile_number'=>'01912234567','designation_id'=>$designation->id,'unit'=>$unit->code]);
  $target=$this->entry('pending',false,$member);$this->entry('rejected');$this->login(UserRole::Librarian);
  foreach(['বাংলা বই 1','English Title 1','ACC-1','800.MK.1','Lookup Reader','lookup@example.test','01912234567','Unique Reader Designation','Unique Filter Department','unit-filter'] as $term)$this->get('/issue-requests?'.http_build_query(['q'=>$term,'status'=>'pending']))->assertOk()->assertViewHas('requests',fn($rows)=>$rows->total()===1 && $rows->first()->id===$target->id);
  $this->get('/issue-requests?'.http_build_query(['q'=>'Lookup Reader','status'=>'rejected']))->assertViewHas('requests',fn($rows)=>$rows->total()===0);
 }
 public function test_search_preserves_archived_book_history_and_treats_wildcards_literally(): void {
  $target=$this->entry();$target->book->update(['title_en'=>'Literal %_ marker']);$target->book->delete();$this->entry();$this->login(UserRole::Staff);
  $this->get('/issue-requests?'.http_build_query(['q'=>'%_']))->assertOk()->assertViewHas('requests',fn($rows)=>$rows->total()===1 && $rows->first()->id===$target->id);
 }
 public function test_filters_validate_and_survive_pagination_and_member_access_is_denied(): void {
  $member=User::factory()->create(['role'=>UserRole::Member,'name'=>'Paged Reader']);for($i=0;$i<21;$i++)$this->entry('pending',false,$member);$this->login();
  $this->get('/issue-requests?q=Paged&status=pending')->assertOk()->assertViewHas('requests',fn($rows)=>$rows->count()===20 && str_contains($rows->nextPageUrl(),'status=pending') && str_contains($rows->nextPageUrl(),'q=Paged'));
  $this->get('/issue-requests?q=Paged&status=pending&page=2')->assertViewHas('requests',fn($rows)=>$rows->count()===1);
  $this->getJson('/issue-requests?status=invalid')->assertUnprocessable();$this->getJson('/issue-requests?q[]=bad')->assertUnprocessable();$this->login(UserRole::Member);$this->get('/issue-requests?status=pending')->assertForbidden();
 }
}
