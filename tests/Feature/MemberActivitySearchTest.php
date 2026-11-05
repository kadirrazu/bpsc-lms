<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Book,Publisher,Author,BookLoan,BookIssueRequest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class MemberActivitySearchTest extends TestCase {
 use RefreshDatabase;
 private function login(User $u): void {$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);}
 private function entry(User $user,string $status='pending',bool $returned=false): BookIssueRequest {
  $serial=Book::withTrashed()->count()+1;$publisher=Publisher::firstOrCreate(['title_bn'=>'প্রকাশক','title_en'=>'Lookup Press']);
  $book=Book::create(['accession_number'=>'MEM-'.$serial,'title_bn'=>'বই '.$serial,'title_en'=>'Shared Lookup '.$serial,'call_number'=>'800.MK.'.$serial,'publisher_id'=>$publisher->id,'number_of_copies'=>2]);
  $author=Author::firstOrCreate(['title_bn'=>'লেখক','title_en'=>'Lookup Author']);$book->authors()->attach($author);
  $loan=$status==='approved'?BookLoan::create(['book_id'=>$book->id,'user_id'=>$user->id,'issued_at'=>now(),'quantity'=>1,'returned_at'=>$returned?now():null]):null;
  return BookIssueRequest::create(['book_id'=>$book->id,'user_id'=>$user->id,'status'=>$status,'loan_id'=>$loan?->id,'withdrawn_at'=>$status==='withdrawn'?now():null]);
 }
 public function test_member_search_remains_scoped_to_own_requests_and_loans(): void {
  $member=User::factory()->create(['role'=>UserRole::Member]);$other=User::factory()->create(['role'=>UserRole::Member]);$mine=$this->entry($member,'approved');$this->entry($other,'approved');$this->login($member);
  foreach(['Shared Lookup','Lookup Author','Lookup Press','MEM-1','800.MK.1','বই 1'] as $term)$this->get('/member/requests?'.http_build_query(['q'=>$term]))->assertOk()->assertViewHas('requests',fn($rows)=>$rows->total()===1 && $rows->first()->id===$mine->id)->assertViewHas('loans',fn($rows)=>$rows->total()===1 && $rows->first()->user_id===$member->id);
  $this->get('/member/requests?q=MEM-2')->assertViewHas('requests',fn($rows)=>$rows->total()===0)->assertViewHas('loans',fn($rows)=>$rows->total()===0);
 }
 public function test_request_and_loan_filters_are_independent_and_combine_with_search(): void {
  $member=User::factory()->create(['role'=>UserRole::Member]);foreach(['pending','rejected','withdrawn','approved'] as $status)$this->entry($member,$status);$this->entry($member,'approved',true);$this->login($member);
  foreach(['pending'=>1,'rejected'=>1,'withdrawn'=>1,'approved'=>2] as $status=>$count)$this->get('/member/requests?'.http_build_query(['q'=>'Shared Lookup','request_status'=>$status,'loan_status'=>'returned']))->assertOk()->assertViewHas('requests',fn($rows)=>$rows->total()===$count)->assertViewHas('loans',fn($rows)=>$rows->total()===1 && $rows->first()->returned_at!==null);
  $this->get('/member/requests?loan_status=on_loan')->assertViewHas('loans',fn($rows)=>$rows->total()===1 && $rows->first()->returned_at===null)->assertViewHas('requests',fn($rows)=>$rows->total()===5);
 }
 public function test_literal_search_and_archived_book_history_remain_available(): void {
  $member=User::factory()->create(['role'=>UserRole::Member]);$target=$this->entry($member);$this->entry($member);$target->book->update(['title_en'=>'Literal %_ text']);$target->book->delete();$this->login($member);
  $this->get('/member/requests?'.http_build_query(['q'=>'%_']))->assertOk()->assertViewHas('requests',fn($rows)=>$rows->total()===1 && $rows->first()->id===$target->id);
 }
 public function test_member_filters_validate_and_pagination_keeps_the_filters(): void {
  $member=User::factory()->create(['role'=>UserRole::Member]);for($i=0;$i<21;$i++)$this->entry($member,'approved',true);$this->login($member);
  $this->get('/member/requests?q=Shared&request_status=approved&loan_status=returned')->assertOk()->assertViewHas('requests',fn($rows)=>$rows->count()===20 && str_contains($rows->nextPageUrl(),'request_status=approved'))->assertViewHas('loans',fn($rows)=>$rows->count()===20 && str_contains($rows->nextPageUrl(),'loans_page=2') && str_contains($rows->nextPageUrl(),'loan_status=returned'));
  $this->get('/member/requests?q=Shared&request_status=approved&loan_status=returned&page=2&loans_page=2')->assertViewHas('requests',fn($rows)=>$rows->count()===1)->assertViewHas('loans',fn($rows)=>$rows->count()===1);
  $this->getJson('/member/requests?request_status=invalid')->assertUnprocessable();$this->getJson('/member/requests?loan_status=invalid')->assertUnprocessable();$this->getJson('/member/requests?q[]=bad')->assertUnprocessable();
 }

 public function test_book_details_hide_request_for_own_active_loan_and_restore_it_after_return(): void {
  $member=User::factory()->create(['role'=>UserRole::Member]);$entry=$this->entry($member,'approved');$this->login($member);$url=route('member.books.show',$entry->book);
  $this->get($url)->assertOk()->assertSee('This book is currently issued to you.')->assertDontSee('Request This Book');
  $this->post(route('member.books.request',$entry->book))->assertSessionHasErrors('request');
  $entry->loan->update(['returned_at'=>now()]);
  $this->get($url)->assertOk()->assertDontSee('This book is currently issued to you.')->assertSee('Request This Book');
  $this->post(route('member.books.request',$entry->book))->assertRedirect(route('member.requests'));
 }
 public function test_another_members_loan_does_not_hide_the_request_option(): void {
  $other=User::factory()->create(['role'=>UserRole::Member]);$entry=$this->entry($other,'approved');$this->login(User::factory()->create(['role'=>UserRole::Member]));
  $this->get(route('member.books.show',$entry->book))->assertOk()->assertDontSee('This book is currently issued to you.')->assertSee('Request This Book')->assertViewHas('memberLoan',null);
 }
}
