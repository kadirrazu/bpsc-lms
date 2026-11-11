<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Book,Author,Publisher,Category};
use App\Services\Library\{CatalogImport,ImportDraft,ImportSample};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\{Spreadsheet,Writer\Xlsx};
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\TestCase;
class CatalogImportPreviewTest extends TestCase {
 use RefreshDatabase;
 private array $tokens=[];
 protected function tearDown(): void {foreach($this->tokens as $token)@unlink(storage_path('app/private/catalog-imports/'.$token.'.json'));parent::tearDown();}
 private function login(UserRole $role=UserRole::Admin): User {$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);return $u;}
 private function data(): array {$a=Author::create(['title_bn'=>'লেখক']);$p=Publisher::create(['title_bn'=>'প্রকাশক']);$c=Category::firstOrFail();return ['accession_number'=>'000123','title_bn'=>'বই','title_en'=>'Book','author_ids'=>(string)$a->id,'publisher_id'=>$p->id.' — '.$p->title_bn,'category_ids'=>(string)$c->id,'call_number'=>'800.02.MK','number_of_copies'=>'1'];}
 private function upload(string $kind,array $rows): string {
  $headers=$kind==='books'?CatalogImport::BOOK_HEADERS:app(CatalogImport::class)->headers($kind);$book=new Spreadsheet;$sheet=$book->getActiveSheet();foreach($headers as $i=>$h)$sheet->setCellValueExplicit([$i+1,1],$h,DataType::TYPE_STRING);
  foreach($rows as $n=>$row)foreach($headers as $i=>$h)$sheet->setCellValueExplicit([$i+1,$n+2],$row[$h]??'',DataType::TYPE_STRING);
  $path=tempnam(sys_get_temp_dir(),'lms-preview-');try{(new Xlsx($book))->save($path);$file=UploadedFile::fake()->createWithContent('upload.xlsx',file_get_contents($path));}finally{$book->disconnectWorksheets();unlink($path);}
  $response=$this->post(route('catalog-import.store',$kind),['file'=>$file])->assertRedirect();$token=basename(parse_url($response->headers->get('Location'),PHP_URL_PATH));$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$token);$this->tokens[]=$token;$this->withCookie(config('session.cookie'),session()->getId());return $token;
 }
 private function confirm(string $kind,string $token){$preview=$this->get(route('catalog-import.preview',[$kind,$token]))->assertOk();return $this->post(route('catalog-import.confirm',[$kind,$token]),['confirmed'=>true,'revision'=>$preview->viewData('revision'),'fingerprint'=>$preview->viewData('result')['fingerprint']]);}
 public function test_upload_is_read_only_and_confirm_commits_once_with_resolved_names(): void {
  $this->login(UserRole::Staff);$data=$this->data();$token=$this->upload('books',[$data]);$this->assertDatabaseCount('books',0);$preview=$this->get(route('catalog-import.preview',['books',$token]))->assertOk()->assertSee('লেখক')->assertSee('প্রকাশক')->assertSee('000123');$payload=['confirmed'=>1,'revision'=>$preview->viewData('revision'),'fingerprint'=>$preview->viewData('result')['fingerprint']];
  $this->post(route('catalog-import.confirm',['books',$token]),$payload)->assertRedirect(route('books.index'));$this->post(route('catalog-import.confirm',['books',$token]),$payload)->assertRedirect(route('books.index'));$this->assertDatabaseCount('books',1);$this->assertSame(1,Book::firstOrFail()->authors()->count());$this->assertDatabaseHas('audit_logs',['action'=>'catalog.import_completed']);
 }
 public function test_unknown_ids_can_be_repaired_through_searchable_row_editor(): void {
  $this->login();$data=$this->data();$bad=array_replace($data,['author_ids'=>'999999','publisher_id'=>'Wrong Name','category_ids'=>'999999']);$token=$this->upload('books',[$bad]);$this->get(route('catalog-import.preview',['books',$token]))->assertOk()->assertSee('Needs Attention');$this->confirm('books',$token)->assertSessionHasErrors('file');$this->assertDatabaseCount('books',0);
  $this->get(route('catalog-import.edit',['books',$token,2]))->assertOk()->assertSee('data-multi-catalog-picker',false);
  $data['author_ids']=[Author::firstOrFail()->id];$data['category_ids']=[Category::firstOrFail()->id];$data['publisher_id']=Publisher::firstOrFail()->id;
  $this->put(route('catalog-import.update',['books',$token,2]),$data+['revision'=>1])->assertRedirect();$this->confirm('books',$token)->assertRedirect(route('books.index'));$this->assertDatabaseCount('books',1);
 }
 public function test_invalid_row_blocks_the_whole_batch_and_no_unknown_masters_are_created(): void {
  $this->login();$data=$this->data();$token=$this->upload('books',[$data,array_replace($data,['accession_number'=>'000124','author_ids'=>'Typo'])]);$this->confirm('books',$token)->assertSessionHasErrors('file');$this->assertDatabaseCount('books',0);$this->assertDatabaseCount('authors',1);
 }
 public function test_master_imports_require_preview_and_can_be_edited_before_confirming(): void {
  $this->login();foreach(['authors','publishers','categories'] as $kind){$model=app(CatalogImport::class)->model($kind);$before=$model::count();$token=$this->upload($kind,[['title_bn'=>'নতুন নাম','title_en'=>'New']]);$this->assertSame($before,$model::count());$this->get(route('catalog-import.edit',[$kind,$token,2]))->assertOk();$this->put(route('catalog-import.update',[$kind,$token,2]),['title_bn'=>'সংশোধিত নাম','title_en'=>'Corrected','revision'=>1])->assertRedirect();$this->confirm($kind,$token)->assertRedirect(route($kind.'.index'));$this->assertDatabaseHas($kind,['title_bn'=>'সংশোধিত নাম']);}
 }
 public function test_preview_is_bound_to_actor_kind_and_session_and_member_cannot_access_it(): void {
  $actor=$this->login();$token=$this->upload('authors',[['title_bn'=>'গোপন খসড়া']]);$this->get(route('catalog-import.preview',['publishers',$token]))->assertNotFound();$this->login(UserRole::Librarian);$this->get(route('catalog-import.preview',['authors',$token]))->assertNotFound();$this->login(UserRole::Member);
  $this->get(route('catalog-import.preview',['authors',$token]))->assertForbidden();$this->post(route('catalog-import.confirm',['authors',$token]),['confirmed'=>true,'revision'=>1,'fingerprint'=>str_repeat('a',64)])->assertForbidden();
  $this->actingAs($actor)->withSession(['auth_version'=>$actor->auth_version]);$this->app['session']->driver()->migrate();$this->withCookie(config('session.cookie'),session()->getId());$this->get(route('catalog-import.preview',['authors',$token]))->assertNotFound();
 }
 public function test_changed_master_name_requires_a_refreshed_preview_before_confirmation(): void {
  $this->login();$token=$this->upload('books',[$this->data()]);$preview=$this->get(route('catalog-import.preview',['books',$token]))->assertOk();Author::firstOrFail()->update(['title_bn'=>'পরিবর্তিত নাম']);
  $this->post(route('catalog-import.confirm',['books',$token]),['confirmed'=>true,'revision'=>1,'fingerprint'=>$preview->viewData('result')['fingerprint']])->assertSessionHasErrors('file');$this->assertDatabaseCount('books',0);$this->confirm('books',$token)->assertRedirect(route('books.index'));
 }
 public function test_cancel_and_expiry_prevent_import_and_stale_edit_is_rejected(): void {
  $this->login();$token=$this->upload('authors',[['title_bn'=>'খসড়া']]);$this->put(route('catalog-import.update',['authors',$token,2]),['title_bn'=>'নতুন খসড়া','revision'=>1])->assertRedirect();$this->put(route('catalog-import.update',['authors',$token,2]),['title_bn'=>'পুরনো সম্পাদনা','revision'=>1])->assertStatus(409);$this->delete(route('catalog-import.cancel',['authors',$token]))->assertRedirect();
  $this->post(route('catalog-import.confirm',['authors',$token]),['confirmed'=>true,'revision'=>2,'fingerprint'=>str_repeat('a',64)])->assertStatus(410);$this->assertDatabaseCount('authors',0);
  $token=$this->upload('authors',[['title_bn'=>'মেয়াদ শেষ']]);$path=storage_path('app/private/catalog-imports/'.$token.'.json');$draft=json_decode(file_get_contents($path),true);$draft['expires']=time()-1;file_put_contents($path,json_encode($draft));$this->get(route('catalog-import.preview',['authors',$token]))->assertStatus(410);
 }
 public function test_templates_contain_database_references_publisher_validation_and_no_example_records(): void {
  $this->data();$book=app(ImportSample::class)->workbook('books');try{$this->assertSame(['Books','Authors','Publishers','Categories','Instructions'],$book->getSheetNames());$this->assertNull($book->getSheet(0)->getCell('A2')->getValue());$this->assertSame('=PublisherSelections',$book->getSheet(0)->getCell('I2')->getDataValidation()->getFormula1());$this->assertSame('I2:I501',$book->getSheet(0)->getCell('I2')->getDataValidation()->getSqref());$this->assertSame(Author::firstOrFail()->id,(int)$book->getSheetByName('Authors')->getCell('A2')->getValue());}finally{$book->disconnectWorksheets();}
 }
 public function test_preview_paginates_twenty_rows_and_does_not_post_all_rows_at_confirmation(): void {
  $this->login();$rows=[];for($n=0;$n<21;$n++)$rows[]=['title_bn'=>'লেখক '.$n];$token=$this->upload('authors',$rows);$this->get(route('catalog-import.preview',['authors',$token]))->assertOk()->assertViewHas('rows',fn($p)=>$p->total()===21 && $p->count()===20);$this->get(route('catalog-import.preview',['authors',$token,'page'=>2]))->assertViewHas('rows',fn($p)=>$p->count()===1);$this->confirm('authors',$token)->assertRedirect();$this->assertDatabaseCount('authors',21);
 }
}
