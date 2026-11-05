<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{Author,Publisher,Category,Book,BookLoan,User};
use App\Services\Library\{BookSearch,DashboardOverview};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,DB};
use Tests\TestCase;

class DashboardAndSearchTest extends TestCase
{
    use RefreshDatabase;
    private function login(UserRole $role=UserRole::Admin): User
    {
        $user=User::factory()->create(['role'=>$role]);
        $this->actingAs($user)->withSession(['auth_version'=>$user->auth_version]);return $user;
    }
    private function book(int $serial,array $overrides=[]): Book
    {
        $author=Author::firstOrCreate(['title_bn'=>'প্রধান লেখক'],['title_en'=>'Main Writer']);
        $publisher=Publisher::firstOrCreate(['title_bn'=>'প্রকাশনা'],['title_en'=>'Press']);
        $category=Category::where('title_bn','অন্যান্য')->firstOrFail();
        $book=Book::create(array_replace(['accession_number'=>sprintf('%06d',$serial),'title_bn'=>'বই '.$serial,'title_en'=>'Book '.$serial,'publisher_id'=>$publisher->id,'call_number'=>'800.02.MK','number_of_copies'=>3],$overrides));
        $book->authors()->attach($author);$book->categories()->attach($category);return $book;
    }
    private function payload(Book $book): array
    {
        return ['accession_number'=>$book->accession_number,'title_bn'=>$book->title_bn,'title_en'=>$book->title_en,'publisher_id'=>$book->publisher_id,'author_ids'=>$book->authors->modelKeys(),'category_ids'=>$book->categories->modelKeys(),'call_number'=>$book->call_number,'number_of_copies'=>$book->number_of_copies];
    }
    public function test_default_categories_are_seeded_once_without_overwriting_edits(): void
    {
        $this->seed(\Database\Seeders\CategorySeeder::class);$this->seed(\Database\Seeders\CategorySeeder::class);
        $this->assertDatabaseCount('categories',2);
        $this->assertDatabaseHas('categories',['title_bn'=>'অন্যান্য','title_en'=>'Others']);
        $this->assertDatabaseHas('categories',['title_bn'=>'ক্যাটাগরী নির্দিষ্ট করা হয়নি','title_en'=>'Uncategorized']);
        Category::where('title_bn','অন্যান্য')->firstOrFail()->update(['title_en'=>'Custom English']);
        $this->seed(\Database\Seeders\CategorySeeder::class);
        $this->assertDatabaseHas('categories',['title_bn'=>'অন্যান্য','title_en'=>'Custom English']);
    }
    public function test_dashboard_has_real_stats_latest_five_books_and_latest_five_issues(): void
    {
        $this->login();$members=User::factory()->count(3)->create(['role'=>UserRole::Member]);$books=[];$loans=[];
        for($i=1;$i<=7;$i++)$books[]=$this->book($i);
        for($i=0;$i<6;$i++)$loans[]=BookLoan::create(['book_id'=>$books[$i]->id,'user_id'=>$members[$i%3]->id,'quantity'=>1,'issued_at'=>now()->subMinutes(10-$i)]);
        $loans[0]->update(['returned_at'=>now()]);
        $this->get('/dashboard')->assertOk()->assertSee('Latest Books')->assertSee('Latest Issues')
            ->assertViewHas('stats',fn($stats)=>$stats['books']===7 && $stats['copies']===21 && $stats['issued']===5 && $stats['users']===4 && $stats['members']===3)
            ->assertViewHas('recentBooks',fn($rows)=>$rows->count()===5 && $rows->first()->id===$books[6]->id)
            ->assertViewHas('recentIssues',fn($rows)=>$rows->count()===5 && $rows->first()->id===$loans[5]->id);
    }
    public function test_dashboard_cloud_links_drill_down_to_book_lists(): void
    {
        $this->login();$book=$this->book(1);$author=$book->authors->first();$category=$book->categories->first();
        $this->get('/dashboard')->assertOk()->assertSee('href="'.route('catalog.entity',['kind'=>'authors','entry'=>$author->id]).'"',false)->assertSee('href="'.route('catalog.entity',['kind'=>'categories','entry'=>$category->id]).'"',false)
            ->assertViewHas('authors',fn($cloud)=>collect($cloud)->firstWhere('id',$author->id)['count']===1);
        foreach(['authors'=>$author,'categories'=>$category,'publishers'=>$book->publisher] as $kind=>$entry)$this->get('/catalog/'.$kind.'/'.$entry->id)->assertOk()->assertSee($book->title_bn);
    }
    public function test_catalog_and_all_contributor_lists_paginate_twenty_books(): void
    {
        $this->login();$first=null;for($i=1;$i<=45;$i++){$book=$this->book($i);$first??=$book;}
        foreach(['/books','/catalog/authors/'.$first->authors->first()->id,'/catalog/publishers/'.$first->publisher_id,'/catalog/categories/'.$first->categories->first()->id] as $url){
            $this->get($url)->assertOk()->assertViewHas('books',fn($p)=>$p->perPage()===20 && $p->count()===20 && $p->total()===45);
            $this->get($url.'?page=3')->assertOk()->assertViewHas('books',fn($p)=>$p->count()===5 && $p->currentPage()===3 && $p->total()===45);
        }
        $this->login(UserRole::Member);$this->get('/member/catalog?page=2')->assertOk()->assertSee('MEMBER PORTAL')->assertViewHas('books',fn($p)=>$p->count()===20);
    }
    public function test_entity_scope_cannot_be_overridden_by_query_parameters(): void
    {
        $this->login();$first=$this->book(1);$second=$this->book(2);$other=Author::create(['title_bn'=>'ভিন্ন লেখক']);$second->authors()->sync([$other->id]);
        $author=$first->authors->first();
        $this->get('/catalog/authors/'.$author->id.'?author_id='.$other->id)->assertOk()->assertViewHas('books',fn($p)=>$p->total()===1 && $p->first()->id===$first->id);
    }
    public function test_dedicated_accession_and_call_filters_support_exact_and_prefix(): void
    {
        $this->login();$one=$this->book(1,['accession_number'=>'00123','call_number'=>'800.02.MK']);$two=$this->book(2,['accession_number'=>'001234','call_number'=>'800.02.AB']);
        $this->get('/books?accession_number=00123')->assertOk()->assertViewHas('books',fn($p)=>$p->total()===1 && $p->first()->id===$one->id);
        $this->get('/books?accession_number=00123&number_match=prefix')->assertOk()->assertViewHas('books',fn($p)=>$p->total()===2);
        $this->get('/books?call_number=800.02.MK')->assertOk()->assertViewHas('books',fn($p)=>$p->total()===1 && $p->first()->id===$one->id);
        $this->get('/books?call_number=800.02&number_match=prefix')->assertOk()->assertViewHas('books',fn($p)=>$p->total()===2);
    }
    public function test_identifier_prefix_treats_percent_and_underscore_literally(): void
    {
        $this->login();$literal=$this->book(1,['accession_number'=>'ACC%_2']);$this->book(2,['accession_number'=>'ACCZZ']);
        $this->get('/books?'.http_build_query(['accession_number'=>'ACC%','number_match'=>'prefix']))->assertOk()->assertViewHas('books',fn($p)=>$p->total()===1 && $p->first()->id===$literal->id);
    }
    public function test_search_cache_invalidates_after_new_book_and_metadata_rename(): void
    {
        $this->login();$book=$this->book(1);$this->get('/books?q=NewNeedle')->assertViewHas('books',fn($p)=>$p->total()===0);
        $author=$book->authors->first();$this->put('/authors/'.$author->id,['title_bn'=>$author->title_bn,'title_en'=>'NewNeedle'])->assertRedirect();
        $this->get('/books?q=NewNeedle')->assertViewHas('books',fn($p)=>$p->total()===1);
        $this->book(2);$this->get('/books?q=NewNeedle')->assertViewHas('books',fn($p)=>$p->total()===2);
    }
    public function test_relation_only_edits_invalidate_cached_search_results(): void
    {
        $this->login();$book=$this->book(1);$other=Author::create(['title_bn'=>'বিশেষ লেখক','title_en'=>'SpecialNeedle']);
        $this->get('/books?q=SpecialNeedle')->assertViewHas('books',fn($p)=>$p->total()===0);
        $data=$this->payload($book);$data['author_ids']=[$other->id];$this->put('/books/'.$book->id,$data)->assertRedirect();
        $this->get('/books?q=SpecialNeedle')->assertViewHas('books',fn($p)=>$p->total()===1);
    }
    public function test_dashboard_cache_tracks_issue_return_user_creation_and_book_deletion(): void
    {
        $this->login();$book=$this->book(1);$before=app(DashboardOverview::class)->snapshot();$member=User::factory()->create();
        $loan=BookLoan::create(['book_id'=>$book->id,'user_id'=>$member->id,'quantity'=>2,'issued_at'=>now()]);
        $during=app(DashboardOverview::class)->snapshot();$this->assertSame(2,$during['stats']['issued']);$this->assertSame($before['stats']['users']+1,$during['stats']['users']);
        $loan->update(['returned_at'=>now()]);$this->delete('/books/'.$book->id,['confirmation'=>'DELETE'])->assertRedirect();
        $after=app(DashboardOverview::class)->snapshot();$this->assertSame(0,$after['stats']['issued']);$this->assertSame(0,$after['stats']['books']);
    }
    public function test_hydration_query_budget_is_bounded_and_count_is_reused_on_next_page(): void
    {
        $this->login();for($i=1;$i<=25;$i++)$this->book($i);config(['cache.default'=>'array']);Cache::flush();$search=app(BookSearch::class);
        DB::enableQueryLog();DB::flushQueryLog();
        $first=$search->paginate(Request::create('/books','GET',['q'=>'Main']),['q'=>'Main']);$queries=DB::getQueryLog();
        $this->assertSame(20,$first->count());$this->assertLessThanOrEqual(8,count($queries));
        DB::flushQueryLog();$next=$search->paginate(Request::create('/books','GET',['q'=>'Main','page'=>2]),['q'=>'Main']);$queries=DB::getQueryLog();
        $this->assertSame(5,$next->count());$this->assertSame(25,$next->total());
        $this->assertFalse(collect($queries)->contains(fn($query)=>(bool)preg_match('/count\(\*\)/i',$query['query'])));
        DB::disableQueryLog();
    }

    public function test_cached_catalog_ids_still_hydrate_fresh_issue_counts_and_availability(): void
    {
        $this->login();$book=$this->book(1);$search=app(BookSearch::class);$request=Request::create('/books','GET');
        $first=$search->paginate($request,[]);$this->assertSame(0,$first->first()->total_issue_count);
        $loan=BookLoan::create(['book_id'=>$book->id,'user_id'=>User::factory()->create()->id,'quantity'=>2,'issued_at'=>now()]);
        $during=$search->paginate($request,[]);$this->assertSame(1,$during->first()->total_issue_count);$this->assertSame(2,(int)$during->first()->issued_copies);
        $loan->update(['returned_at'=>now()]);$after=$search->paginate($request,[]);$this->assertSame(0,(int)$after->first()->issued_copies);
    }
    public function test_local_benchmark_command_does_not_create_or_modify_catalog_records(): void
    {
        $this->login();$book=$this->book(1);$before=$book->fresh()->getAttributes();
        $this->artisan('library:benchmark-catalog',['--query'=>'Main','--runs'=>1,'--json'=>true])->assertSuccessful();
        $this->assertDatabaseCount('books',1);$this->assertSame($before,$book->fresh()->getAttributes());
    }
}
