<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\{User,Designation,Unit,Book,Author,Publisher,Category};
use App\Services\Library\{CatalogImport,ImportSample};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\{Spreadsheet,Writer\Xlsx};
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\TestCase;
class CatalogImportAndAdminTest extends TestCase {
 use RefreshDatabase;
 private function login(UserRole $role=UserRole::Admin): void {$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);}
 private function upload(array $headers,array $rows): UploadedFile {
  $sheet=new Spreadsheet;foreach(array_merge([$headers],$rows) as $r=>$row)foreach($row as $c=>$value)$sheet->getActiveSheet()->setCellValueExplicit([$c+1,$r+1],$value,is_int($value)||is_float($value)?DataType::TYPE_NUMERIC:DataType::TYPE_STRING);
  $path=tempnam(sys_get_temp_dir(),'lms-xlsx-');try{(new Xlsx($sheet))->save($path);$bytes=file_get_contents($path);}finally{$sheet->disconnectWorksheets();unlink($path);}
  return UploadedFile::fake()->createWithContent('import.xlsx',$bytes);
 }
 private function masterData(): array {
  $a=Author::create(['title_bn'=>'লেখক','title_en'=>'Author']);$b=Author::create(['title_bn'=>'আরেক লেখক']);$p=Publisher::create(['title_bn'=>'প্রকাশনী']);$c=Category::where('title_en','Uncategorized')->firstOrFail();return [$a,$b,$p,$c];
 }
 private function bookRow(string $accession='000001'): array {
  return [$accession,'বই','Book','লেখক | আরেক লেখক','প্রকাশনী','ক্যাটাগরী নির্দিষ্ট করা হয়নি','800.02.MK','A1','2026','2','350.50','Gift'];
 }
 public function test_production_and_staging_admin_command_stop_before_any_prompt(): void {
  foreach(['production','staging'] as $env){$this->app->detectEnvironment(fn()=>$env);$this->artisan('library:create-admin')->expectsOutput('This command is available only in development (APP_ENV=local or development).')->assertExitCode(1);}
  $this->assertDatabaseCount('users',0);
 }
 public function test_development_admin_command_uses_selected_active_designation(): void {
  $this->app->detectEnvironment(fn()=>'local');$designations=Designation::where('is_active',true)->orderBy('sort_order')->orderBy('name')->pluck('name')->all();
  $designation=Designation::where('name','Librarian')->firstOrFail();$labels=app(\App\Services\Library\Catalog::class)->unitLabels();$unit=array_key_first($labels);
  $this->artisan('library:create-admin')->expectsChoice('Designation',$designation->name,$designations)->expectsQuestion('Administrator name','Test Admin')->expectsQuestion('Email address','admin-test@example.com')->expectsQuestion('Password (8+ characters, mixed case, number and symbol)','Strong!Pass123')->expectsQuestion('Confirm password','Strong!Pass123')->expectsChoice('Department',$labels[$unit],array_values($labels))->expectsOutput('Administrator created. No default password was saved.')->assertExitCode(0);
  $this->assertDatabaseHas('users',['email'=>'admin-test@example.com','designation_id'=>$designation->id,'role'=>'admin','unit'=>$unit]);
 }
 public function test_master_imports_are_atomic_and_audited(): void {
  $this->login();$file=$this->upload(['title_bn','title_en'],[['প্রথম লেখক','First'],['দ্বিতীয় লেখক','Second']]);
  $this->post(route('catalog-import.store','authors'),['file'=>$file])->assertRedirect(route('authors.index'));$this->assertDatabaseCount('authors',2);$this->assertDatabaseHas('audit_logs',['action'=>'catalog.import_completed']);
  $file=$this->upload(['title_bn','title_en'],[['নতুন লেখক','New'],['প্রথম লেখক','Duplicate']]);
  $this->post(route('catalog-import.store','authors'),['file'=>$file])->assertSessionHasErrors('file');$this->assertDatabaseMissing('authors',['title_bn'=>'নতুন লেখক']);
 }
 public function test_book_import_preserves_identifiers_multiselect_price_and_collection(): void {
  $this->login(UserRole::Staff);$this->masterData();$file=$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow()]);
  $this->post(route('catalog-import.store','books'),['file'=>$file])->assertRedirect(route('books.index'));
  $book=Book::firstOrFail();$this->assertSame('000001',$book->accession_number);$this->assertSame(2,$book->authors()->count());$this->assertSame(1,$book->categories()->count());$this->assertSame('350.50',$book->price);$this->assertSame('Gift',$book->collection_method);$this->assertSame(0,$book->total_issue_count);
 }
 public function test_unknown_relation_and_duplicate_accession_leave_no_partial_books(): void {
  $this->login();$this->masterData();$row=$this->bookRow('000002');$row[3]='অজানা লেখক';
  $this->post(route('catalog-import.store','books'),['file'=>$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow(),$row])])->assertSessionHasErrors('file');$this->assertDatabaseCount('books',0);
  $this->post(route('catalog-import.store','books'),['file'=>$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow(),$this->bookRow()])])->assertSessionHasErrors('file');$this->assertDatabaseCount('books',0);
 }
 public function test_invalid_price_method_numeric_accession_and_formula_are_rejected(): void {
  $this->login();$this->masterData();
  foreach([['price',10,'-1'],['collection',11,'Donation'],['accession',0,123],['formula',2,'=1+1']] as [$label,$index,$value]){
   $row=$this->bookRow();$row[$index]=$value;$file=$this->upload(CatalogImport::BOOK_HEADERS,[$row]);
   if($label==='formula'){
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());$book->getActiveSheet()->setCellValue('C2','=1+1');(new Xlsx($book))->save($file->getRealPath());$book->disconnectWorksheets();
   }
   $this->post(route('catalog-import.store','books'),['file'=>$file])->assertSessionHasErrors('file');
  }$this->assertDatabaseCount('books',0);
 }
 public function test_member_cannot_access_imports_or_samples_and_guest_is_redirected(): void {
  $this->get(route('catalog-import.index','books'))->assertRedirect(route('login'));$this->login(UserRole::Member);
  foreach(['books','authors','publishers','categories'] as $kind){$this->get(route('catalog-import.index',$kind))->assertForbidden();$this->get(route('catalog-import.sample',$kind))->assertForbidden();$this->post(route('catalog-import.store',$kind))->assertForbidden();}
 }
 public function test_samples_have_required_headers_and_are_downloadable_for_managers(): void {
  $this->login(UserRole::Librarian);
  foreach(['books','authors','publishers','categories'] as $kind){$this->get(route('catalog-import.index',$kind))->assertOk()->assertSee('Download Sample XLSX');$this->get(route('catalog-import.sample',$kind))->assertOk()->assertDownload($kind.'-import-sample.xlsx');$book=app(ImportSample::class)->workbook($kind);$this->assertSame('Data',$book->getSheet(0)->getTitle());$this->assertSame('Instructions',$book->getSheet(1)->getTitle());$this->assertSame(app(CatalogImport::class)->headers($kind),$book->getSheet(0)->rangeToArray('A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count(app(CatalogImport::class)->headers($kind))).'1')[0]);$book->disconnectWorksheets();}
 }
 public function test_bad_headers_empty_and_oversized_workbooks_are_rejected(): void {
  $this->login();foreach([$this->upload(['wrong'],[['value']]),$this->upload(['title_bn','title_en'],[]),$this->upload(['title_bn','title_en'],array_fill(0,501,['লেখক','Author']))] as $file)$this->post(route('catalog-import.store','authors'),['file'=>$file])->assertSessionHasErrors('file');
  $this->assertDatabaseCount('authors',0);
 }
 public function test_book_form_acquisition_fields_persist_and_validate_on_edit(): void {
  $this->login();[$a,$b,$p,$c]=$this->masterData();$data=['accession_number'=>'FORM-1','title_bn'=>'বই','author_ids'=>[$a->id],'publisher_id'=>$p->id,'category_ids'=>[$c->id],'call_number'=>'800','number_of_copies'=>1,'price'=>'0.00','collection_method'=>'Gift'];
  $this->get('/books/create')->assertOk()->assertSee('Collection Method')->assertSee('Price');$this->post('/books',$data)->assertRedirect();$book=Book::firstOrFail();$this->assertSame('0.00',$book->price);
  $this->put('/books/'.$book->id,array_replace($data,['price'=>'12.345']))->assertSessionHasErrors('price');
  $this->put('/books/'.$book->id,array_replace($data,['price'=>null,'collection_method'=>null]))->assertRedirect();$this->assertNull($book->fresh()->price);$this->assertNull($book->fresh()->collection_method);
 }

 public function test_download_samples_round_trip_through_the_importer(): void {
  foreach(['authors','publishers','categories','books'] as $kind){
   $book=app(ImportSample::class)->workbook($kind);$path=tempnam(sys_get_temp_dir(),'lms-sample-');
   try{(new Xlsx($book))->save($path);$count=app(CatalogImport::class)->run($path,$kind);$this->assertGreaterThan(0,$count);}finally{$book->disconnectWorksheets();unlink($path);}
  }
  $this->assertDatabaseHas('books',['accession_number'=>'000123','collection_method'=>'Purchase']);
  $this->assertSame(2,Book::firstOrFail()->authors()->count());$this->assertSame(2,Book::firstOrFail()->categories()->count());
 }
 public function test_admin_command_requires_an_active_designation_in_development(): void {
  $this->app->detectEnvironment(fn()=>'development');Designation::query()->update(['is_active'=>false]);
  $this->artisan('library:create-admin')->expectsOutput('No active designations found. Run php artisan db:seed --class=DesignationSeeder first.')->assertExitCode(1);$this->assertDatabaseCount('users',0);
 }

 public function test_import_rejects_existing_and_archived_accession_without_partial_writes(): void {
  $this->login();$this->masterData();
  $this->post(route('catalog-import.store','books'),['file'=>$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow()])])->assertRedirect(route('books.index'));
  $existing=Book::firstOrFail();
  foreach([false,true] as $archived){
   if($archived)$existing->delete();
   $file=$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow('000002'),$this->bookRow()]);
   $this->post(route('catalog-import.store','books'),['file'=>$file])->assertSessionHasErrors('file');
   $this->assertSame(1,Book::withTrashed()->count());$this->assertDatabaseMissing('books',['accession_number'=>'000002']);
  }
 }
}
