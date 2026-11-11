<?php
namespace Tests\Feature;
use App\Models\{Author,Publisher,Category,Book};
use App\Services\Library\{CatalogImport,ImportSample};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\{Writer\Xlsx,IOFactory};
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\TestCase;
class CatalogImportSelectionsTest extends TestCase {
 use RefreshDatabase;
 private function data(): array {$a=Author::create(['title_bn'=>'প্রথম লেখক']);$p=Publisher::create(['title_bn'=>'প্রকাশক']);$c=Category::firstOrFail();return ['accession_number'=>'000121','title_bn'=>'বই','author_1'=>$a->id.' — '.$a->title_bn,'publisher_id'=>(string)$p->id,'category_1'=>$c->id.' — '.$c->title_bn,'call_number'=>'800','number_of_copies'=>'1'];}
 private function import(array $data,bool $omitOptional=false): int {
  $book=app(ImportSample::class)->workbook('books');$sheet=$book->getSheet(0);$headers=CatalogImport::TEMPLATE_HEADERS;
  if($omitOptional){$headers=array_values(array_filter($headers,fn($h)=>!preg_match('/^(author|category)_[2-5]$/',$h)));$book->disconnectWorksheets();$book=new \PhpOffice\PhpSpreadsheet\Spreadsheet;$sheet=$book->getActiveSheet();foreach($headers as $i=>$header)$sheet->setCellValueExplicit([$i+1,1],$header,DataType::TYPE_STRING);}
  foreach($headers as $i=>$header)if(array_key_exists($header,$data))$sheet->setCellValueExplicit([$i+1,2],$data[$header],DataType::TYPE_STRING);
  $path=tempnam(sys_get_temp_dir(),'lms-slots-');try{(new Xlsx($book))->save($path);return app(CatalogImport::class)->run($path,'books');}finally{$book->disconnectWorksheets();unlink($path);}
 }
 public function test_only_first_author_and_category_are_required_and_empty_optional_slots_create_no_links(): void {
  $this->assertSame(1,$this->import($this->data()));$book=Book::firstOrFail();$this->assertSame(1,$book->authors()->count());$this->assertSame(1,$book->categories()->count());$this->assertSame('000121',$book->accession_number);
 }
 public function test_optional_slot_headers_can_be_omitted_entirely(): void {$this->assertSame(1,$this->import($this->data(),true));$this->assertSame(1,Book::firstOrFail()->authors()->count());}
 public function test_extra_selections_and_gaps_are_supported_and_duplicates_are_linked_once(): void {
  $data=$this->data();$a=Author::create(['title_bn'=>'দ্বিতীয় লেখক']);$c=Category::create(['title_bn'=>'নতুন বিভাগ']);$data['author_3']=$a->id.' — '.$a->title_bn;$data['author_5']=$data['author_1'];$data['category_4']=(string)$c->id;$data['category_5']=$data['category_1'];
  $this->assertSame(1,$this->import($data));$book=Book::firstOrFail();$this->assertSame(2,$book->authors()->count());$this->assertSame(2,$book->categories()->count());
 }
 public function test_first_slot_cannot_be_replaced_by_a_later_selection(): void {
  $data=$this->data();foreach(['author','category'] as $prefix){$row=$data;$row[$prefix.'_2']=$row[$prefix.'_1'];$row[$prefix.'_1']='';$result=app(CatalogImport::class)->inspect([2=>$row],'books');$this->assertNotEmpty($result['errors'][2]);$this->assertStringContainsString(ucfirst($prefix).' 1 is required',implode(' ',$result['errors'][2]));}
  $this->assertDatabaseCount('books',0);
 }
 public function test_nonempty_invalid_optional_slot_blocks_the_whole_import(): void {
  $data=$this->data();$data['author_4']='999999';try{$this->import($data);$this->fail('Expected invalid optional selection.');}catch(ValidationException $e){$this->assertArrayHasKey('file',$e->errors());}$this->assertDatabaseCount('books',0);
 }
 public function test_template_optional_dropdowns_allow_blanks_and_are_never_prefilled_after_xlsx_round_trip(): void {
  $this->data();$book=app(ImportSample::class)->workbook('books');$path=tempnam(sys_get_temp_dir(),'lms-empty-slots-');try{
   (new Xlsx($book))->save($path);$loaded=IOFactory::load($path);$sheet=$loaded->getSheet(0);$this->assertSame('C2',$sheet->getFreezePane());
   foreach(['E','F','G','H','K','L','M','N'] as $col){$validation=$sheet->getCell($col.'2')->getDataValidation();$this->assertTrue($validation->getAllowBlank());$this->assertSame($col.'2:'.$col.'501',$validation->getSqref());for($row=2;$row<=501;$row++)$this->assertNull($sheet->getCell($col.$row)->getValue());}
   foreach(['D','I','J'] as $col)$this->assertFalse($sheet->getCell($col.'2')->getDataValidation()->getAllowBlank());
   $this->assertSame('=AuthorSelections',$sheet->getCell('D2')->getDataValidation()->getFormula1());$this->assertSame('=CategorySelections',$sheet->getCell('J2')->getDataValidation()->getFormula1());$loaded->disconnectWorksheets();
  }finally{$book->disconnectWorksheets();unlink($path);}
 }
}
