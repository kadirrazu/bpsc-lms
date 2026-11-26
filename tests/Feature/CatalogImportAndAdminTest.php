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
 private function importUpload(string $kind,array $payload){
  $response=$this->post(route('catalog-import.store',$kind),$payload);
  $location=$response->headers->get('Location','');if(!str_contains($location,'/preview/'))return $response;
  $this->withCookie(config('session.cookie'),session()->getId());$preview=$this->get($location)->assertOk();$token=basename(parse_url($location,PHP_URL_PATH));
  return $this->post(route('catalog-import.confirm',[$kind,$token]),['confirmed'=>true,'revision'=>$preview->viewData('revision'),'fingerprint'=>$preview->viewData('result')['fingerprint']]);
 }
 private function masterData(): array {
  $a=Author::create(['title_bn'=>'লেখক','title_en'=>'Author']);$b=Author::create(['title_bn'=>'আরেক লেখক']);$p=Publisher::create(['title_bn'=>'প্রকাশনী']);$c=Category::where('title_en','Uncategorized')->firstOrFail();return [$a,$b,$p,$c];
 }
 private function bookRow(string $accession='000001'): array {
  return [$accession,'বই','Book',Author::orderBy('id')->pluck('id')->implode(' | '),(string)Publisher::firstOrFail()->id,(string)Category::where('title_en','Uncategorized')->firstOrFail()->id,'800.02.MK','A1','2026','2','350.50','Gift',$accession.'-02','19.50','USD'];
 }
 public function test_production_and_staging_admin_command_stop_before_any_prompt(): void {
  foreach(['production','staging'] as $env){$this->app->detectEnvironment(fn()=>$env);$this->artisan('library:create-admin')->expectsOutput('This command is available only in development (APP_ENV=local or development).')->assertExitCode(1);}
  $this->assertDatabaseCount('users',0);
 }
 public function test_development_admin_command_uses_selected_active_designation(): void {
  $this->app->detectEnvironment(fn()=>'local');
  $designation=Designation::where('name','Librarian')->firstOrFail();$labels=app(\App\Services\Library\Catalog::class)->unitLabels();$unit=array_key_first($labels);
  $this->artisan('library:create-admin')->expectsQuestion('Designation',$designation->name)->expectsQuestion('Administrator name','Test Admin')->expectsQuestion('Email address','admin-test@example.com')->expectsQuestion('Password (8+ characters, mixed case, number and symbol)','Strong!Pass123')->expectsQuestion('Confirm password','Strong!Pass123')->expectsQuestion('Department',$labels[$unit])->expectsOutput('Administrator created. No default password was saved.')->assertExitCode(0);
  $this->assertDatabaseHas('users',['email'=>'admin-test@example.com','designation_id'=>$designation->id,'role'=>'admin','unit'=>$unit]);
 }
 public function test_master_imports_are_atomic_and_audited(): void {
  $this->login();$file=$this->upload(['title_bn','title_en'],[['প্রথম লেখক','First'],['দ্বিতীয় লেখক','Second']]);
  $this->importUpload('authors',['file'=>$file])->assertRedirect(route('authors.index'));$this->assertDatabaseCount('authors',2);$this->assertDatabaseHas('audit_logs',['action'=>'catalog.import_completed']);
  $file=$this->upload(['title_bn','title_en'],[['নতুন লেখক','New'],['প্রথম লেখক','Duplicate']]);
  $this->importUpload('authors',['file'=>$file])->assertSessionHasErrors('file');$this->assertDatabaseMissing('authors',['title_bn'=>'নতুন লেখক']);
 }
 public function test_book_import_preserves_identifiers_multiselect_price_and_collection(): void {
  $this->login(UserRole::Staff);$this->masterData();$file=$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow()]);
  $this->importUpload('books',['file'=>$file])->assertRedirect(route('books.index'));
  $book=Book::firstOrFail();$this->assertSame('000001',$book->accession_number);$this->assertSame(2,$book->authors()->count());$this->assertSame(1,$book->categories()->count());$this->assertSame('350.50',$book->price);$this->assertSame('19.50',$book->printed_price);$this->assertSame('USD',$book->printed_currency);$this->assertSame(['000001','000001-02'],$book->copies()->orderBy('id')->pluck('accession_number')->all());$this->assertSame('Gift',$book->collection_method);$this->assertSame(0,$book->total_issue_count);
 }
 public function test_unknown_relation_and_duplicate_accession_leave_no_partial_books(): void {
  $this->login();$this->masterData();$row=$this->bookRow('000002');$row[3]='অজানা লেখক';
  $this->importUpload('books',['file'=>$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow(),$row])])->assertSessionHasErrors('file');$this->assertDatabaseCount('books',0);
  $this->importUpload('books',['file'=>$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow(),$this->bookRow()])])->assertSessionHasErrors('file');$this->assertDatabaseCount('books',0);
 }
 public function test_invalid_price_method_numeric_accession_and_formula_are_rejected(): void {
  $this->login();$this->masterData();
  foreach([['price',10,'-1'],['collection',11,'Donation'],['accession',0,123],['formula',2,'=1+1']] as [$label,$index,$value]){
   $row=$this->bookRow();$row[$index]=$value;$file=$this->upload(CatalogImport::BOOK_HEADERS,[$row]);
   if($label==='formula'){
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());$book->getActiveSheet()->setCellValue('C2','=1+1');(new Xlsx($book))->save($file->getRealPath());$book->disconnectWorksheets();
   }
   $this->importUpload('books',['file'=>$file])->assertSessionHasErrors('file');
  }$this->assertDatabaseCount('books',0);
 }
 public function test_member_cannot_access_imports_or_samples_and_guest_is_redirected(): void {
  $this->get(route('catalog-import.index','books'))->assertRedirect(route('login'));$this->login(UserRole::Member);
  foreach(['books','authors','publishers','categories'] as $kind){$this->get(route('catalog-import.index',$kind))->assertForbidden();$this->get(route('catalog-import.sample',$kind))->assertForbidden();$this->post(route('catalog-import.store',$kind))->assertForbidden();}
 }
 public function test_samples_have_required_headers_and_are_downloadable_for_managers(): void {
  $this->login(UserRole::Librarian);
  foreach(['books','authors','publishers','categories'] as $kind){$this->get(route('catalog-import.index',$kind))->assertOk()->assertSee('Download Import Template');$this->get(route('catalog-import.sample',$kind))->assertOk()->assertDownload($kind.'-import-sample.xlsx');$book=app(ImportSample::class)->workbook($kind);$this->assertSame($kind==='books'?'Books':'Data',$book->getSheet(0)->getTitle());$this->assertNotNull($book->getSheetByName('Instructions'));$this->assertSame(app(CatalogImport::class)->headers($kind),$book->getSheet(0)->rangeToArray('A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count(app(CatalogImport::class)->headers($kind))).'1')[0]);$book->disconnectWorksheets();}
 }
 public function test_bad_headers_empty_and_oversized_workbooks_are_rejected(): void {
  $this->login();foreach([$this->upload(['wrong'],[['value']]),$this->upload(['title_bn','title_en'],[]),$this->upload(['title_bn','title_en'],array_fill(0,501,['লেখক','Author']))] as $file)$this->importUpload('authors',['file'=>$file])->assertSessionHasErrors('file');
  $this->assertDatabaseCount('authors',0);
 }
 public function test_book_form_acquisition_fields_persist_and_validate_on_edit(): void {
  $this->login();[$a,$b,$p,$c]=$this->masterData();$data=['accession_number'=>'FORM-1','title_bn'=>'বই','author_ids'=>[$a->id],'publisher_id'=>$p->id,'category_ids'=>[$c->id],'call_number'=>'800','number_of_copies'=>1,'price'=>'0.00','collection_method'=>'Gift'];
  $this->get('/books/create')->assertOk()->assertSee('Collection Method')->assertSee('Price');$this->post('/books',$data)->assertRedirect();$book=Book::firstOrFail();$this->assertSame('0.00',$book->price);
  $this->put('/books/'.$book->id,array_replace($data,['price'=>'12.345']))->assertSessionHasErrors('price');
  $this->put('/books/'.$book->id,array_replace($data,['price'=>null,'collection_method'=>null]))->assertRedirect();$this->assertNull($book->fresh()->price);$this->assertNull($book->fresh()->collection_method);
 }

 public function test_database_book_template_round_trips_with_ids(): void {
  $this->masterData();$workbook=app(ImportSample::class)->workbook('books');$sheet=$workbook->getSheet(0);$row=$this->bookRow('000123');$row[4]=Publisher::firstOrFail()->id.' — '.Publisher::firstOrFail()->title_bn;
  foreach(array_combine(CatalogImport::BOOK_HEADERS,$row) as $header=>$value){if($header==='author_ids'){foreach(explode(' | ',$value) as $n=>$id)$sheet->setCellValueExplicit([array_search('author_'.($n+1),CatalogImport::TEMPLATE_HEADERS,true)+1,2],$id,DataType::TYPE_STRING);}elseif($header==='category_ids')$sheet->setCellValueExplicit([10,2],$value,DataType::TYPE_STRING);else $sheet->setCellValueExplicit([array_search($header,CatalogImport::TEMPLATE_HEADERS,true)+1,2],$value,DataType::TYPE_STRING);}$path=tempnam(sys_get_temp_dir(),'lms-template-');
  try{(new Xlsx($workbook))->save($path);$this->assertSame(1,app(CatalogImport::class)->run($path,'books'));}finally{$workbook->disconnectWorksheets();unlink($path);}
  $this->assertDatabaseHas('books',['accession_number'=>'000123','collection_method'=>'Gift']);$this->assertSame(2,Book::firstOrFail()->authors()->count());
 }
 public function test_admin_command_requires_an_active_designation_in_development(): void {
  $this->app->detectEnvironment(fn()=>'development');Designation::query()->update(['is_active'=>false]);
  $this->artisan('library:create-admin')->expectsOutput('No active designations found. Run php artisan db:seed --class=DesignationSeeder first.')->assertExitCode(1);$this->assertDatabaseCount('users',0);
 }

 public function test_import_rejects_existing_accession_and_accepts_reuse_after_deletion(): void {
  $this->login();$this->masterData();
  $this->importUpload('books',['file'=>$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow()])])->assertRedirect(route('books.index'));
  $existing=Book::firstOrFail();
  $file=$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow('000002'),$this->bookRow()]);
  $this->importUpload('books',['file'=>$file])->assertSessionHasErrors('file');
  $this->assertSame(1,Book::withTrashed()->count());
  $this->assertDatabaseMissing('books',['accession_number'=>'000002']);
  $existing->delete();
  $file=$this->upload(CatalogImport::BOOK_HEADERS,[$this->bookRow('000002'),$this->bookRow()]);
  $this->importUpload('books',['file'=>$file])->assertRedirect(route('books.index'))->assertSessionHasNoErrors();
  $this->assertSame(3,Book::withTrashed()->count());
  $this->assertSame(2,Book::count());
 }

}
