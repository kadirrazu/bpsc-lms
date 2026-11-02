<?php
namespace App\Services\Library;

use App\Http\Requests\BookRequest;
use App\Models\{Author,Publisher,Category,Book};
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use PhpOffice\PhpSpreadsheet\Reader\{Xlsx,IReadFilter};
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class CatalogImport
{
    public const MAX_ROWS=500;
    public const BOOK_HEADERS=['accession_number','title_bn','title_en','authors_bn','publisher_bn','categories_bn','call_number','shelf_number','publication_year','number_of_copies','price','collection_method'];
    public function model(string $kind): string {return match($kind){'books'=>Book::class,'authors'=>Author::class,'publishers'=>Publisher::class,'categories'=>Category::class,default=>abort(404)};}
    public function headers(string $kind): array {$this->model($kind);return $kind==='books'?self::BOOK_HEADERS:['title_bn','title_en'];}
    private function fail(string $message): never {throw ValidationException::withMessages(['file'=>$message]);}
    public function rows(string $path,string $kind): array
    {
        $allowed=$this->headers($kind);if(!class_exists(\ZipArchive::class))$this->fail('The PHP ZIP extension must be enabled to import XLSX files.');$zip=new \ZipArchive;
        if($zip->open($path)!==true)$this->fail('Upload a valid .xlsx workbook.');
        try {
            $size=0;if($zip->numFiles>5000)$this->fail('This workbook contains too many archive entries.');
            for($i=0;$i<$zip->numFiles;$i++){
                $entry=$zip->statIndex($i);if(!is_array($entry))$this->fail('The workbook archive is damaged.');$size+=(int)$entry['size'];
                if($size>30*1024*1024)$this->fail('Workbook is too large when expanded (maximum 30 MB).');
                if(preg_match('~(?:externalLinks/|\.bin$)~i',$entry['name']))$this->fail('Workbooks with macros, embedded binaries or external links are not supported.');
            }
        } finally {$zip->close();}
        $reader=new Xlsx;$workbook=null;
        try {
            if(!$reader->canRead($path))$this->fail('Upload a valid .xlsx workbook.');
            $info=$reader->listWorksheetInfo($path);$first=$info[0]??null;
            if(!$first)$this->fail('The workbook has no worksheets.');
            if($first['totalRows']>self::MAX_ROWS+1 || $first['totalColumns']>count($allowed))$this->fail('Use the sample columns and no more than 500 data rows per file.');
            $reader->setLoadSheetsOnly($first['worksheetName']);$reader->setReadDataOnly(false);$reader->setReadEmptyCells(false);$reader->setIncludeCharts(false);
            $reader->setReadFilter(new class implements IReadFilter {public function readCell(string $columnAddress,int $row,string $worksheetName=''): bool {return $row<=501;}});
            $workbook=$reader->load($path);$sheet=$workbook->getSheet(0);$headers=[];
            for($col=1;$col<=$first['totalColumns'];$col++){
                $cell=$sheet->getCell([$col,1]);if($cell->getDataType()===DataType::TYPE_FORMULA)$this->fail('Header formulas are not supported.');
                $header=trim((string)$cell->getValue());
                if(!in_array($header,$allowed,true) || in_array($header,$headers,true))$this->fail('Invalid or duplicate header. Keep the sample column names unchanged.');
                $headers[]=$header;
            }
            $required=$kind==='books'?['accession_number','title_bn','authors_bn','publisher_bn','categories_bn','call_number','number_of_copies']:['title_bn'];
            if(array_diff($required,$headers))$this->fail('Required headers are missing. Download the sample workbook.');
            $rows=[];
            for($row=2;$row<=$first['totalRows'];$row++){
                $data=[];$hasValue=false;
                foreach($headers as $index=>$header){
                    $cell=$sheet->getCell([$index+1,$row]);$value=$cell->getValue();
                    if($value!==null && $value!=='')$hasValue=true;
                    if(in_array($cell->getDataType(),[DataType::TYPE_FORMULA,DataType::TYPE_ERROR,DataType::TYPE_BOOL],true))$this->fail("Row $row, $header: use plain text or numbers, without formulas or errors.");
                    if($header==='accession_number' && $value!==null && $cell->getDataType()===DataType::TYPE_NUMERIC)$this->fail("Row $row: format accession_number as Text before entering it, so leading zeros are preserved.");
                    if($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText)$value=$value->getPlainText();
                    $data[$header]=$value===null?null:trim((string)$value);
                    if($data[$header]==='')$data[$header]=null;
                    $limit=in_array($header,['authors_bn','categories_bn'],true)?15000:1000;
                    if($data[$header]!==null && mb_strlen($data[$header])>$limit)$this->fail("Row $row, $header: cell text is too long.");
                }
                if($hasValue)$rows[$row]=$data;
            }
            if(!$rows)$this->fail('The first worksheet contains no data rows.');
            return $rows;
        } catch(ValidationException $e){throw $e;} catch(\Throwable $e){report($e);$this->fail('The workbook could not be read. Save it as .xlsx and try again.');}
        finally {$workbook?->disconnectWorksheets();}
    }
    private function names(?string $value): array {return array_values(array_unique(array_filter(array_map('trim',explode('|',$value??'')),fn($name)=>$name!=='')));}
    public function run(string $path,string $kind): int
    {
        $rows=$this->rows($path,$kind);$model=$this->model($kind);
        if($kind==='books')foreach($rows as $number=>$data)foreach(['authors_bn','categories_bn'] as $field)if(count($this->names($data[$field]??null))>50)$this->fail("Row $number: select no more than 50 entries in $field.");
        try {return DB::transaction(function()use($rows,$kind,$model){
            $errors=[];$prepared=[];$seen=[];$maps=[];
            if($kind==='books'){
                foreach(['authors_bn'=>Author::class,'publisher_bn'=>Publisher::class,'categories_bn'=>Category::class] as $field=>$class){
                    $names=[];foreach($rows as $data)$names=array_merge($names,$field==='publisher_bn'?array_filter([$data[$field]??null]):$this->names($data[$field]??null));
                    $maps[$field]=$class::whereIn('title_bn',array_unique($names))->orderBy('id')->lockForUpdate()->get()->keyBy('title_bn');
                }
            }
            foreach($rows as $number=>$data){
                $key=$kind==='books'?'accession_number':'title_bn';$identity=$data[$key]??'';
                if(isset($seen[$identity]))$errors[]="Row $number: duplicate $key in this workbook.";$seen[$identity]=true;
                if($kind==='books'){
                    foreach(['authors_bn'=>'author_ids','categories_bn'=>'category_ids'] as $field=>$target){
                        $names=$this->names($data[$field]??null);$ids=[];
                        foreach($names as $name){$entry=$maps[$field]->get($name);if(!$entry)$errors[]="Row $number: unknown $field '".mb_substr($name,0,100)."'. Import this master record first.";else $ids[]=$entry->id;}
                        $data[$target]=$ids;
                    }
                    $publisher=$maps['publisher_bn']->get($data['publisher_bn']??'');
                    if(!$publisher)$errors[]="Row $number: unknown publisher_bn. Import this publisher first.";
                    $data['publisher_id']=$publisher?->id;$data['number_of_copies']=$data['number_of_copies']??'1';
                    unset($data['authors_bn'],$data['publisher_bn'],$data['categories_bn']);
                    $rules=(new BookRequest)->rules();unset($rules['cover'],$rules['remove_cover']);
                }else {$rules=['title_bn'=>['required','string','max:255',\Illuminate\Validation\Rule::unique((new $model)->getTable(),'title_bn')],'title_en'=>'nullable|string|max:255'];}
                $validator=Validator::make($data,$rules);
                if($validator->fails())foreach($validator->errors()->all() as $message)$errors[]="Row $number: $message";
                $prepared[]=$validator->fails()?[]:$validator->validated();
                if(count($errors)>=50)break;
            }
            if($errors)throw ValidationException::withMessages(['file'=>array_slice($errors,0,50)]);
            foreach($prepared as $data){
                if($kind==='books'){
                    $authors=$data['author_ids'];$categories=$data['category_ids'];unset($data['author_ids'],$data['category_ids']);
                    $book=Book::create($data);$book->authors()->sync($authors);$book->categories()->sync($categories);
                    app(Audit::class)->record('book.imported',['author_ids'=>$authors,'category_ids'=>$categories],$book);
                }else {$entry=$model::create($data);app(Audit::class)->record(strtolower(class_basename($model)).'.imported',[],$entry);}
            }
            app(CatalogRevision::class)->touch();app(Audit::class)->record('catalog.import_completed',['kind'=>$kind,'count'=>count($prepared)]);
            return count($prepared);
        });}catch(QueryException $e){report($e);$this->fail('Import was rolled back because a database record conflicted or changed. Check duplicates and try again.');}
    }
}
