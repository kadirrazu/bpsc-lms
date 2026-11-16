<?php
namespace App\Services\Library;
use App\Models\{Author,Publisher,Category};
use PhpOffice\PhpSpreadsheet\{Spreadsheet,NamedRange};
use PhpOffice\PhpSpreadsheet\Cell\{DataType,Coordinate,DataValidation};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
class ImportSample {
 private function table(Worksheet $sheet,array $headers,array $rows): void {
  foreach(array_merge([$headers],$rows) as $r=>$values)foreach($values as $c=>$value)$sheet->setCellValueExplicit([$c+1,$r+1],$value,is_int($value)||is_float($value)?DataType::TYPE_NUMERIC:DataType::TYPE_STRING);
  $last=Coordinate::stringFromColumnIndex(count($headers));$sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');$sheet->getStyle('A1:'.$last.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF3454D1');$sheet->getRowDimension(1)->setRowHeight(28);$sheet->freezePane('A2');$sheet->setAutoFilter('A1:'.$last.max(2,count($rows)+1));
  foreach($headers as $i=>$header)$sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i+1))->setWidth((preg_match('/^(author|category)_[1-5]$/',$header)||in_array($header,['title_bn','title_en','selection'],true))?38:24);
 }
 public function workbook(string $kind): Spreadsheet {
  $headers=app(CatalogImport::class)->headers($kind);$book=new Spreadsheet;$sheet=$book->getActiveSheet();$sheet->setTitle($kind==='books'?'Books':'Data');
  // Templates are blank: sample records must never accidentally enter the live catalog.
  $this->table($sheet,$headers,[]);
  foreach($headers as $i=>$header){$column=Coordinate::stringFromColumnIndex($i+1);if(!in_array($header,['publication_year','number_of_copies','price','printed_price'],true))$sheet->getStyle($column.'2:'.$column.'501')->getNumberFormat()->setFormatCode('@');}
  $required=$kind==='books'?['accession_number','title_bn','author_1','publisher_id','category_1','call_number','number_of_copies']:['title_bn'];
  foreach($headers as $index=>$header){$col=Coordinate::stringFromColumnIndex($index+1);$mandatory=in_array($header,$required,true);$sheet->getStyle($col.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB($mandatory?'FF3454D1':'FF64748B');$sheet->getStyle($col.'2:'.$col.'501')->getFill()->setFillType('solid')->getStartColor()->setARGB($mandatory?'FFF0F4FF':'FFF8FAFC');$sheet->getComment($col.'1')->getText()->createTextRun($mandatory?'Required.':'Optional. Leave blank unless needed.');}
  $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'501')->getFont()->setName('Nirmala UI')->setSize(11);$sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->getAlignment()->setWrapText(true);$sheet->getRowDimension(1)->setRowHeight(34);$sheet->getDefaultRowDimension()->setRowHeight(25);
  if($kind==='books'){
   $sheet->freezePane('C2');$sheet->getStyle('V2:V501')->getNumberFormat()->setFormatCode('0.00');$sheet->getStyle('S2:S501')->getNumberFormat()->setFormatCode('0.00');
   foreach(['Authors'=>Author::class,'Publishers'=>Publisher::class,'Categories'=>Category::class] as $title=>$model){
    $reference=new Worksheet($book,$title);$book->addSheet($reference);$rows=$model::orderBy('title_bn')->get(['id','title_bn','title_en'])->map(fn($entry)=>[$entry->id,$entry->title_bn,$entry->title_en,$entry->id.' — '.$entry->title_bn])->all();
    $this->table($reference,['id','title_bn','title_en','selection'],$rows);
    if(count($rows)){
     $rangeName=match($title){'Authors'=>'AuthorSelections','Publishers'=>'PublisherSelections','Categories'=>'CategorySelections'};
     $book->addNamedRange(new NamedRange($rangeName,$reference,'$D$2:$D$'.(count($rows)+1)));
     $columns=match($title){'Authors'=>['D','E','F','G','H'],'Publishers'=>['I'],'Categories'=>['J','K','L','M','N']};
     foreach($columns as $n=>$col){$optional=$title!=='Publishers' && $n>0;
      $validation=$sheet->getCell($col.'2')->getDataValidation();$validation->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)->setAllowBlank($optional)->setShowDropDown(true)->setShowErrorMessage(false)->setShowInputMessage(true)->setPromptTitle($optional?'Optional Selection':'Required Selection')->setPrompt($optional?'Leave blank unless another selection is needed. Choose ID — Name or enter one ID.':'Choose an existing ID — Name, or enter one ID. The server validates every selection.')->setFormula1('='.$rangeName)->setSqref($col.'2:'.$col.'501');
     }
    }
   }
   $validation=$sheet->getCell('T2')->getDataValidation();$validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)->setFormula1('"Purchase,Gift,Others"')->setSqref('T2:T501');
  }
  $notes=new Worksheet($book,'Instructions');$book->addSheet($notes);$notes->getColumnDimension('A')->setWidth(110);
  $lines=['Import instructions','Fill the first worksheet. Keep its column names unchanged. Reference sheets are lookup lists, not import data.','Maximum 500 data rows and 10 MB per file. Only new records are added; existing records are not overwritten.','Upload → Preview → Edit Row if necessary → Confirm Import. Nothing is saved before confirmation.','Every row must be valid. Duplicate names/accessions and unknown IDs prevent the entire import.','title_bn is required; title_en is optional. Use plain values, without formulas, macros or external links.'];
  if($kind==='books')$lines=array_merge($lines,['Import new authors, publishers and categories first, then download a fresh template from this installation.','author_1 and category_1 are required. Select one ID — Name in each dropdown.', 'author_2 to author_5 and category_2 to category_5 are strictly optional: leave them blank unless needed. They are never prefilled.', 'Each numbered column accepts one selection. Repeated IDs are linked once. Use Edit Row for more than five authors/categories.','publisher_id: select ID — Name from the dropdown, or enter one ID from Publishers.','Reference names are for guidance. Only IDs are used to link records; the server validates every ID.','IDs belong to this database. Do not reuse this template with a different installation.','Enter accession_number as Text before typing to preserve leading zeros. Accession must be unique.','Required: accession_number, title_bn, author_1, publisher_id, category_1, call_number. Blue headers are required; slate headers are optional.','number_of_copies defaults to 1 when blank. For multiple copies, enter the first accession in accession_number and the remaining numbers separated by semicolons in additional_accessions.', 'price is the Purchase Price in BDT. Printed price is optional; printed_currency accepts BDT, USD, EUR, INR or CNY. It defaults to BDT. Add soft copies from the Add Book screen.','collection_method: Purchase, Gift or Others; optional. Cover images can be added later from Edit Book.','Freeze panes keep accession and title visible. Use reference-sheet filters to find records. Dropdown AutoComplete depends on your Excel version.']);
  foreach($lines as $i=>$line){$notes->setCellValueExplicit('A'.($i+1),$line,DataType::TYPE_STRING);$notes->getRowDimension($i+1)->setRowHeight(42);}$notes->getStyle('A1:A'.count($lines))->getAlignment()->setWrapText(true);$notes->getStyle('A1')->getFont()->setBold(true);$book->setActiveSheetIndex(0);return $book;
 }
}
