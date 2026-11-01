<?php
namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\{Author,Publisher,Category,Book,BookLoan,User};
use App\Services\Library\CoverStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function loginRole(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        return $user;
    }

    private function data(string $accession = '000123'): array
    {
        $first = Author::firstOrCreate(['title_bn' => 'প্রথম লেখক'], ['title_en' => 'First Author']);
        $second = Author::firstOrCreate(['title_bn' => 'দ্বিতীয় লেখক'], ['title_en' => 'Second Author']);
        $publisher = Publisher::firstOrCreate(['title_bn' => 'প্রকাশনা'], ['title_en' => 'Knowledge Press']);
        $category = Category::firstOrCreate(['title_bn' => 'সাহিত্য'], ['title_en' => 'Literature']);
        $other = Category::firstOrCreate(['title_bn' => 'ইতিহাস'], ['title_en' => 'History']);
        return [
            'accession_number' => $accession, 'title_bn' => 'বাংলা বই', 'title_en' => 'English Book Title',
            'author_ids' => [$first->id, $second->id], 'publisher_id' => $publisher->id,
            'category_ids' => [$category->id, $other->id], 'call_number' => '800.02.MK',
            'shelf_number' => null, 'publication_year' => null, 'number_of_copies' => 3,
        ];
    }

    private function book(array $override = []): Book
    {
        $data = array_replace($this->data(), $override);
        $this->post('/books', $data)->assertRedirect();
        return Book::where('accession_number', $data['accession_number'])->firstOrFail();
    }

    private function loan(Book $book, int $quantity = 1): BookLoan
    {
        return BookLoan::create(['book_id' => $book->id, 'user_id' => User::factory()->create()->id, 'quantity' => $quantity, 'issued_at' => now()]);
    }

    public function test_catalog_masters_support_bilingual_crud_and_audit(): void
    {
        $this->loginRole();
        foreach (['authors' => Author::class, 'publishers' => Publisher::class, 'categories' => Category::class] as $kind => $model) {
            $this->post('/'.$kind, ['title_bn' => 'বাংলা নাম', 'title_en' => null])->assertRedirect('/'.$kind);
            $entry = $model::where('title_bn', 'বাংলা নাম')->firstOrFail();
            $this->get('/'.$kind.'/'.$entry->id.'/edit')->assertOk();
            $this->put('/'.$kind.'/'.$entry->id, ['title_bn' => 'পরিবর্তিত নাম', 'title_en' => 'Searchable Name'])->assertRedirect('/'.$kind);
            $this->get('/'.$kind.'?q=Searchable')->assertOk()->assertSee('পরিবর্তিত নাম');
            $this->delete('/'.$kind.'/'.$entry->id, ['confirmation' => 'DELETE'])->assertRedirect('/'.$kind);
            $this->assertDatabaseMissing($kind, ['id' => $entry->id]);
            $this->assertDatabaseHas('audit_logs', ['action' => strtolower(class_basename($model)).'.deleted', 'subject_id' => $entry->id]);
        }
    }

    public function test_bn_names_are_required_for_masters_and_en_names_are_optional(): void
    {
        $this->loginRole();
        foreach (['authors','publishers','categories'] as $kind) {
            $this->post('/'.$kind, ['title_bn' => '', 'title_en' => 'English only'])->assertSessionHasErrors('title_bn');
            $this->post('/'.$kind, ['title_bn' => 'বাংলা নাম', 'title_en' => null])->assertRedirect('/'.$kind);
        }
    }

    public function test_book_create_preserves_accession_and_multiple_authors_and_categories(): void
    {
        $this->loginRole();
        $book = $this->book(['number_of_copies' => 1, 'title_en' => null]);
        $this->assertSame('000123', $book->accession_number);
        $this->assertSame(1, $book->number_of_copies);
        $this->assertCount(2, $book->authors);
        $this->assertCount(2, $book->categories);
        $this->get('/books/'.$book->id)->assertOk()->assertSee('বাংলা বই')->assertSee('প্রথম লেখক')->assertSee('দ্বিতীয় লেখক')->assertSee('সাহিত্য')->assertSee('ইতিহাস')->assertSee('Total Times Issued');
        $this->assertDatabaseHas('audit_logs', ['action' => 'book.created', 'subject_id' => $book->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'book.relationships_changed', 'subject_id' => $book->id]);
    }

    public function test_edit_synchronizes_multi_selections_without_duplicate_pivots(): void
    {
        $this->loginRole();$book = $this->book();$data = $this->data();
        $data['author_ids'] = [$data['author_ids'][1]];$data['category_ids'] = [$data['category_ids'][1]];
        $data['title_bn'] = 'সম্পাদিত বই';$data['call_number'] = 'Custom / A-7';
        $this->put('/books/'.$book->id, $data)->assertRedirect('/books/'.$book->id);
        $this->assertSame($data['author_ids'], $book->fresh()->authors->modelKeys());
        $this->assertSame($data['category_ids'], $book->fresh()->categories->modelKeys());
        $this->assertSame('Custom / A-7', $book->fresh()->call_number);
        $this->assertDatabaseHas('audit_logs', ['action' => 'book.updated', 'subject_id' => $book->id]);
    }

    public function test_book_validation_requires_at_least_one_valid_author_and_category(): void
    {
        $this->loginRole();$data = $this->data();
        $bad = array_replace($data, ['accession_number' => '', 'title_bn' => '', 'author_ids' => [], 'category_ids' => [], 'number_of_copies' => 0]);
        $this->post('/books', $bad)->assertSessionHasErrors(['accession_number','title_bn','author_ids','category_ids','number_of_copies']);
        $bad = array_replace($data, ['author_ids' => [999999], 'category_ids' => [$data['category_ids'][0], $data['category_ids'][0]]]);
        $this->post('/books', $bad)->assertSessionHasErrors(['author_ids.0','category_ids.0']);
        $this->assertDatabaseCount('books', 0);
    }

    public function test_accession_number_is_unique_including_deleted_book_history(): void
    {
        $this->loginRole();$book = $this->book();
        $this->post('/books', $this->data())->assertSessionHasErrors('accession_number');
        $this->delete('/books/'.$book->id, ['confirmation' => 'DELETE'])->assertRedirect('/books');
        $this->post('/books', $this->data())->assertSessionHasErrors('accession_number');
    }

    public function test_live_search_matches_bn_en_and_any_related_author_or_category(): void
    {
        $this->loginRole();$book = $this->book();
        foreach (['বাংলা','English Book','Second Author','History','Knowledge Press','000123','800.02.MK'] as $term) {
            $response=$this->getJson('/books?'.http_build_query(['q' => $term]))->assertOk()->assertJsonStructure(['html']);
            $this->assertStringContainsString('বাংলা বই',$response->json('html'));
        }
        $this->get('/books?'.http_build_query(['author_id' => $book->authors->last()->id, 'category_id' => $book->categories->last()->id]))->assertOk()->assertSee('বাংলা বই');
        $this->get('/books?q=DoesNotExist')->assertOk()->assertDontSee('বাংলা বই');
    }

    public function test_remote_picklists_search_both_names_exclude_selected_and_limit_results(): void
    {
        $this->loginRole();$data = $this->data();
        for ($i=0; $i<25; $i++) Author::create(['title_bn' => 'লেখক '.$i, 'title_en' => 'Writer '.$i]);
        $response = $this->getJson('/catalog-options/authors')->assertOk();
        $this->assertCount(20, $response->json('results'));
        $this->getJson('/catalog-options/authors?q=Second')->assertJsonPath('results.0.label', 'দ্বিতীয় লেখক');
        $response = $this->getJson('/catalog-options/authors?'.http_build_query(['q' => 'Second', 'exclude' => [$data['author_ids'][1]]]))->assertOk();
        $this->assertSame([], $response->json('results'));
    }

    public function test_members_can_read_catalog_and_covers_but_cannot_manage_catalog(): void
    {
        Storage::fake('local');$this->loginRole();$book=$this->book(['cover'=>UploadedFile::fake()->image('cover.png')]);
        $this->loginRole(UserRole::Member);
        $this->get('/member/catalog?q=Second')->assertOk()->assertSee('বাংলা বই')->assertSee('MEMBER PORTAL');
        $this->get('/member/catalog/'.$book->id)->assertOk()->assertDontSee('Book Activity');
        $this->get('/books/'.$book->id.'/cover')->assertOk();
        $this->getJson('/catalog-options/categories?q=History')->assertOk()->assertJsonPath('results.0.label','ইতিহাস');
        foreach(['/books','/books/create','/books/'.$book->id,'/authors','/publishers','/categories'] as $path)$this->get($path)->assertForbidden();
        $this->post('/books',$this->data('OTHER'))->assertForbidden();
        $this->put('/books/'.$book->id,$this->data())->assertForbidden();
        $this->delete('/books/'.$book->id,['confirmation'=>'DELETE'])->assertForbidden();
    }

    public function test_staff_can_create_and_edit_books_but_cannot_delete_books_or_masters(): void
    {
        $this->loginRole(UserRole::Staff);$book=$this->book();
        $this->get('/books/create')->assertOk()->assertSee('author_ids[]',false)->assertSee('category_ids[]',false);
        $this->get('/books/'.$book->id.'/edit')->assertOk();
        $this->put('/books/'.$book->id,array_replace($this->data(),['title_bn'=>'কর্মীর সম্পাদনা']))->assertRedirect();
        $this->post('/authors',['title_bn'=>'নতুন লেখক'])->assertRedirect('/authors');
        $this->delete('/books/'.$book->id,['confirmation'=>'DELETE'])->assertForbidden();
        $this->delete('/authors/'.$book->authors->first()->id,['confirmation'=>'DELETE'])->assertForbidden();
        $this->get('/books/'.$book->id)->assertDontSee('Book Activity');
    }

    public function test_linked_masters_cannot_be_deleted_even_after_book_is_archived(): void
    {
        $this->loginRole();$book=$this->book();$author=$book->authors->first();$category=$book->categories->first();
        $this->delete('/authors/'.$author->id,['confirmation'=>'DELETE'])->assertSessionHasErrors('entry');
        $this->delete('/publishers/'.$book->publisher_id,['confirmation'=>'DELETE'])->assertSessionHasErrors('entry');
        $this->delete('/books/'.$book->id,['confirmation'=>'DELETE'])->assertRedirect();
        $this->delete('/categories/'.$category->id,['confirmation'=>'DELETE'])->assertSessionHasErrors('entry');
    }

    public function test_cover_replacement_removal_and_book_deletion_leave_no_unused_files(): void
    {
        Storage::fake('local');$this->loginRole();$book=$this->book(['cover'=>UploadedFile::fake()->image('first.png')]);
        $first=$book->cover_path;Storage::disk('local')->assertExists($first);
        $this->put('/books/'.$book->id,array_replace($this->data(),['cover'=>UploadedFile::fake()->image('second.png')]))->assertRedirect();
        $second=$book->fresh()->cover_path;Storage::disk('local')->assertMissing($first);Storage::disk('local')->assertExists($second);
        $this->put('/books/'.$book->id,array_replace($this->data(),['remove_cover'=>1]))->assertRedirect();
        Storage::disk('local')->assertMissing($second);$this->assertNull($book->fresh()->cover_path);
        $this->put('/books/'.$book->id,array_replace($this->data(),['cover'=>UploadedFile::fake()->image('third.png')]))->assertRedirect();
        $last=$book->fresh()->cover_path;
        $this->delete('/books/'.$book->id,['confirmation'=>'DELETE'])->assertRedirect('/books');
        Storage::disk('local')->assertMissing($last);$this->assertSame([],Storage::disk('local')->allFiles('book-covers'));
        $this->assertSoftDeleted($book);$this->assertDatabaseCount('cover_file_deletions',0);
    }

    public function test_invalid_cover_files_are_rejected_before_storage(): void
    {
        Storage::fake('local');$this->loginRole();
        $this->post('/books',array_replace($this->data(),['cover'=>UploadedFile::fake()->create('unsafe.svg',1,'image/svg+xml')]))->assertSessionHasErrors('cover');
        $this->post('/books',array_replace($this->data(),['cover'=>UploadedFile::fake()->image('large.png')->size(6000)]))->assertSessionHasErrors('cover');
        $this->assertSame([],Storage::disk('local')->allFiles('book-covers'));$this->assertDatabaseCount('books',0);
    }

    public function test_issued_book_cannot_be_deleted_or_reduced_below_issued_stock(): void
    {
        Storage::fake('local');$this->loginRole();$book=$this->book(['cover'=>UploadedFile::fake()->image('cover.png')]);$cover=$book->cover_path;$this->loan($book,2);
        $this->delete('/books/'.$book->id,['confirmation'=>'DELETE'])->assertSessionHasErrors('book');
        $this->put('/books/'.$book->id,array_replace($this->data(),['number_of_copies'=>1,'cover'=>UploadedFile::fake()->image('rejected.png')]))->assertSessionHasErrors('number_of_copies');
        $this->assertNotSoftDeleted($book);$this->assertSame(3,$book->fresh()->number_of_copies);
        Storage::disk('local')->assertExists($cover);$this->assertCount(1,Storage::disk('local')->allFiles('book-covers'));
    }

    public function test_return_allows_delete_and_retains_issue_history_and_logs(): void
    {
        Storage::fake('local');$this->loginRole();$book=$this->book(['cover'=>UploadedFile::fake()->image('cover.png')]);$loan=$this->loan($book);
        $loan->update(['returned_at'=>now()]);
        $this->assertDatabaseHas('audit_logs',['action'=>'book.issued','subject_type'=>'Book','subject_id'=>$book->id]);
        $this->assertDatabaseHas('audit_logs',['action'=>'book.returned','subject_type'=>'Book','subject_id'=>$book->id]);
        $this->get('/books/'.$book->id)->assertOk()->assertSee('Book issued')->assertSee('Book returned');
        $this->delete('/books/'.$book->id,['confirmation'=>'DELETE'])->assertRedirect('/books');
        $this->assertSame($book->id,$loan->fresh()->book->id);$this->assertSame(1,$loan->fresh()->book->total_issue_count);
        $this->get('/audit-logs?subject_type=Book&subject_id='.$book->id)->assertOk()->assertSee('book.issued')->assertSee('book.returned');
    }

    public function test_total_times_issued_counts_events_not_copies_or_return_edits(): void
    {
        $this->loginRole();$book=$this->book();$first=$this->loan($book,2);$second=$this->loan($book,1);
        $this->assertSame(2,$book->fresh()->total_issue_count);$this->assertSame(3,$book->activeLoanQuantity());
        $first->update(['returned_at'=>now()]);$second->update(['quantity'=>2]);
        $this->assertSame(2,$book->fresh()->total_issue_count);$this->loan($book,1);$this->assertSame(3,$book->fresh()->total_issue_count);
        $this->assertDatabaseCount('book_loans',3);
    }

    public function test_issue_writes_cannot_exceed_available_copies(): void
    {
        $this->loginRole();$book=$this->book(['number_of_copies'=>1]);$this->loan($book,1);
        try {$this->loan($book,1);$this->fail('Expected stock validation.');}catch(ValidationException $error){$this->assertArrayHasKey('quantity',$error->errors());}
        $this->assertSame(1,$book->fresh()->total_issue_count);$this->assertDatabaseCount('book_loans',1);
    }

    public function test_cleanup_queue_does_not_remove_a_currently_referenced_cover(): void
    {
        Storage::fake('local');$this->loginRole();$book=$this->book(['cover'=>UploadedFile::fake()->image('cover.png')]);
        $storage=app(CoverStorage::class);$storage->defer($book->cover_path);$storage->purge();
        Storage::disk('local')->assertExists($book->cover_path);
        $this->delete('/books/'.$book->id,['confirmation'=>'DELETE'])->assertRedirect();
        Storage::disk('local')->assertMissing($book->cover_path);$this->assertDatabaseCount('cover_file_deletions',0);
    }

    public function test_returned_issues_cannot_be_reopened_and_deleted_books_cannot_be_issued(): void
    {
        $this->loginRole();$book=$this->book();$loan=$this->loan($book);$loan->update(['returned_at'=>now()]);
        try {$loan->update(['returned_at'=>null]);$this->fail('Expected reissue validation.');}catch(ValidationException $error){$this->assertArrayHasKey('returned_at',$error->errors());}
        $this->assertNotNull($loan->fresh()->returned_at);$this->assertSame(1,$book->fresh()->total_issue_count);
        $this->delete('/books/'.$book->id,['confirmation'=>'DELETE'])->assertRedirect('/books');
        try {$this->loan($book);$this->fail('Expected deleted book validation.');}catch(ValidationException $error){$this->assertArrayHasKey('book_id',$error->errors());}
        $this->assertSame(1,Book::withTrashed()->findOrFail($book->id)->total_issue_count);
    }
}
