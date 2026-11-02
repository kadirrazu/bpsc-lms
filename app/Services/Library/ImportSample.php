<?php
namespace App\Services\Library;
use PhpOffice\PhpSpreadsheet\{Spreadsheet,Writer\Xlsx};
use PhpOffice\PhpSpreadsheet\Cell\{DataType,Coordinate};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
class ImportSample {
 public function workbook(string $kind): Spreadsheet {
  $headers=app(CatalogImport::class)->headers($kind);$book=new Spreadsheet;$sheet=$book->getActiveSheet();$sheet->setTitle('Data');
  $examples=match($kind){
   'authors'=>[['নমুনা লেখক','Sample Author'],['দ্বিতীয় লেখক','Second Author']],
   'publishers'=>[['নমুনা প্রকাশনী','Sample Publisher']],
   'categories'=>[['সাহিত্য','Literature'],['ইতিহাস','History']],
   'books'=>[['000123','নমুনা বই','Sample Book','নমুনা লেখক | দ্বিতীয় লেখক','নমুনা প্রকাশনী','সাহিত্য | ইতিহাস','800.02.MK','A-01',2026,1,350.50,'Purchase']],
  };
  foreach(array_merge([$headers],$examples) as $r=>$values)foreach($values as $c=>$value)$sheet->setCellValueExplicit([$c+1,$r+1],$value,is_int($value)||is_float($value)?DataType::TYPE_NUMERIC:DataType::TYPE_STRING);
  $last=Coordinate::stringFromColumnIndex(count($headers));
  $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
  $sheet->getStyle('A1:'.$last.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF3454D1');
  $sheet->getRowDimension(1)->setRowHeight(28);$sheet->freezePane('A2');$sheet->setAutoFilter('A1:'.$last.(count($examples)+1));
  foreach($headers as $i=>$header){$col=Coordinate::stringFromColumnIndex($i+1);$sheet->getColumnDimension($col)->setWidth(in_array($header,['authors_bn','categories_bn'],true)?42:($header==='title_bn'?35:24));if(!in_array($header,['publication_year','number_of_copies','price'],true))$sheet->getStyle($col.'2:'.$col.'501')->getNumberFormat()->setFormatCode('@');}
  if($kind==='books')$sheet->getStyle('K2:K501')->getNumberFormat()->setFormatCode('0.00');
  $notes=new Worksheet($book,'Instructions');$book->addSheet($notes);$notes->getColumnDimension('A')->setWidth(110);
  $lines=['Import instructions','Edit the Data sheet. Keep its header names. The first worksheet is imported.','Replace or delete example rows; do not import examples into a live catalog.','Maximum 500 data rows per file, 10 MB upload. Import adds new records only.','Any invalid row cancels the whole import. Duplicates are errors.','title_bn is mandatory; title_en is optional. Display uses the Bengali title.','Use plain values. Formulas, macros and external links are not supported.'];
  if($kind==='books')$lines=array_merge($lines,['Import authors, publishers and categories first. Match their Bengali names exactly.','Separate multiple authors_bn/categories_bn values with |. A name containing | cannot be used in these lists.','Enter accession_number as Text before typing; leading zeros must remain intact.','Required: accession_number, title_bn, authors_bn, publisher_bn, categories_bn, call_number.','number_of_copies defaults to 1 when blank; otherwise use a positive whole number.','price is optional, non-negative, with at most two decimal places.','collection_method is optional: Purchase, Gift or Others (exact spelling).','Cover images are added later from Edit Book; they are not imported.']);
  foreach($lines as $i=>$line){$notes->setCellValueExplicit('A'.($i+1),$line,DataType::TYPE_STRING);$notes->getRowDimension($i+1)->setRowHeight(30);}
  $notes->getStyle('A1:A'.count($lines))->getAlignment()->setWrapText(true);$notes->getStyle('A1')->getFont()->setBold(true);$book->setActiveSheetIndex(0);return $book;
 }
}
