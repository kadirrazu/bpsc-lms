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
    public const BOOK_HEADERS=['accession_number','title_bn','title_en','author_ids','publisher_id','category_ids','call_number','shelf_number','publication_year','number_of_copies','price','collection_method'];
    public const TEMPLATE_HEADERS=['accession_number','title_bn','title_en','author_1','author_2','author_3','author_4','author_5','publisher_id','category_1','category_2','category_3','category_4','category_5','call_number','shelf_number','publication_year','number_of_copies','price','collection_method'];
    public function model(string $kind): string {return match($kind){'books'=>Book::class,'authors'=>Author::class,'publishers'=>Publisher::class,'categories'=>Category::class,default=>abort(404)};}
    public function headers(string $kind): array {$this->model($kind);return $kind==='books'?self::TEMPLATE_HEADERS:['title_bn','title_en'];}
    private function fail(string $message): never {throw ValidationException::withMessages(['file'=>$message]);}
    public function rows(string $path,string $kind): array
    {
        $allowed=$kind==='books'?array_values(array_unique(array_merge(self::BOOK_HEADERS,self::TEMPLATE_HEADERS))):$this->headers($kind);if(!class_exists(\ZipArchive::class))$this->fail('The PHP ZIP extension must be enabled to import XLSX files.');$zip=new \ZipArchive;
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
            $required=$kind==='books'?['accession_number','title_bn','publisher_id','call_number','number_of_copies']:['title_bn'];
            if($kind==='books')foreach(['author'=>'author_ids','category'=>'category_ids'] as $prefix=>$field){
                $slots=array_values(array_intersect($headers,array_map(fn($n)=>$prefix.'_'.$n,range(1,5))));
                if(in_array($field,$headers,true) && $slots)$this->fail('Use either numbered selection columns or ID lists for '.$prefix.', not both.');
                if(!in_array($field,$headers,true) && !in_array($prefix.'_1',$headers,true))$this->fail('Required header '.$prefix.'_1 is missing.');
            }
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
                    $limit=in_array($header,['author_ids','category_ids'],true)?15000:1000;
                    if($data[$header]!==null && mb_strlen($data[$header])>$limit)$this->fail("Row $row, $header: cell text is too long.");
                }
                if($hasValue)$rows[$row]=$data;
            }
            if(!$rows)$this->fail('The first worksheet contains no data rows.');
            return $rows;
        } catch(ValidationException $e){throw $e;} catch(\Throwable $e){report($e);$this->fail('The workbook could not be read. Save it as .xlsx and try again.');}
        finally {$workbook?->disconnectWorksheets();}
    }
    private function id(?string $value): ?int
    {
        return preg_match('/^([1-9][0-9]{0,17})(?:\s+[—–-]\s+[^\r\n]*)?$/u',trim($value??''),$m)?(int)$m[1]:null;
    }
    private function ids(mixed $value): array
    {
        $items=is_array($value)?$value:explode('|',(string)$value,52);
        return array_map(fn($v)=>is_scalar($v)?($this->id((string)$v)??trim((string)$v)):null,$items);
    }
    public function inspect(array $rows,string $kind,bool $lock=false): array
    {
        $model=$this->model($kind);$prepared=[];$errors=[];$seen=[];$refs=[];
        if($kind==='books')foreach($rows as $number=>&$data)foreach(['author'=>'author_ids','category'=>'category_ids'] as $prefix=>$field){
            if(array_key_exists($prefix.'_1',$data)){
                if(trim((string)($data[$prefix.'_1']??''))==='')$errors[$number][]=ucfirst($prefix).' 1 is required. Columns 2–5 are optional.';
                $values=[];for($n=1;$n<=5;$n++){if(trim((string)($data[$prefix.'_'.$n]??''))!=='')$values[]=$data[$prefix.'_'.$n];unset($data[$prefix.'_'.$n]);}
                $data[$field]=$values;
            }
        }
        unset($data);

        if($kind==='books')foreach(['author_ids'=>Author::class,'publisher_id'=>Publisher::class,'category_ids'=>Category::class] as $field=>$class){
            $ids=[];foreach($rows as $data){$values=$field==='publisher_id'?[$this->id((string)($data[$field]??''))]:$this->ids($data[$field]??'');foreach($values as $id)if(is_int($id))$ids[]=$id;}
            $query=$class::whereIn('id',array_unique($ids))->orderBy('id');if($lock)$query->lockForUpdate();$refs[$field]=$query->get(['id','title_bn','title_en'])->keyBy('id');
        }
        foreach($rows as $number=>$data){
            $data=array_intersect_key($data,array_flip($kind==='books'?self::BOOK_HEADERS:$this->headers($kind)));$key=$kind==='books'?'accession_number':'title_bn';$identity=(string)($data[$key]??'');
            if(isset($seen[$identity]))$errors[$number][]="Duplicate $key in this workbook (also row ".$seen[$identity].").";$seen[$identity]=$number;
            if($kind==='books'){
                foreach(['author_ids','category_ids'] as $field){$data[$field]=array_values(array_unique($this->ids($data[$field]??''),SORT_REGULAR));foreach($data[$field] as $id)if(!is_int($id) || !$refs[$field]->has($id))$errors[$number][]="Unknown $field value. Select an existing record using Edit Row.";}
                $data['publisher_id']=$this->id((string)($data['publisher_id']??''));if(!$data['publisher_id'] || !$refs['publisher_id']->has($data['publisher_id']))$errors[$number][]='Unknown publisher. Select an existing publisher using Edit Row.';
                $data['number_of_copies']=$data['number_of_copies']??'1';$rules=(new BookRequest)->rules();unset($rules['cover'],$rules['remove_cover']);
                // IDs were checked in three bulk queries above; avoid per-ID existence queries.
                foreach(['author_ids.*','publisher_id','category_ids.*'] as $field)$rules[$field]=preg_replace('/\\|exists:[a-z_]+,id/','',$rules[$field]);
            }else $rules=['title_bn'=>['required','string','max:255',\Illuminate\Validation\Rule::unique((new $model)->getTable(),'title_bn')],'title_en'=>'nullable|string|max:255'];
            $validator=Validator::make($data,$rules);
            if($validator->fails())$errors[$number]=array_merge($errors[$number]??[],$validator->errors()->all());
            $prepared[$number]=$data;
        }
        $labels=[];foreach($refs as $field=>$entries)$labels[$field]=$entries->map(fn($entry)=>['id'=>$entry->id,'title_bn'=>$entry->title_bn,'title_en'=>$entry->title_en])->all();
        $fingerprint=hash('sha256',json_encode([$kind,$prepared,$errors,$labels],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return ['rows'=>$prepared,'errors'=>$errors,'references'=>$refs,'fingerprint'=>$fingerprint];
    }
    public function commitRows(array $rows,string $kind,?string $expectedFingerprint=null): int
    {
        try {return DB::transaction(function()use($rows,$kind,$expectedFingerprint){
            $result=$this->inspect($rows,$kind,true);
            if($result['errors']){ $messages=[];foreach($result['errors'] as $number=>$errors)foreach($errors as $error)$messages[]="Row $number: $error";throw ValidationException::withMessages(['file'=>array_slice($messages,0,50)]);}
            if($expectedFingerprint!==null && !hash_equals($result['fingerprint'],$expectedFingerprint))throw ValidationException::withMessages(['file'=>'The data or selected master records changed. Review the refreshed preview and confirm again.']);
            $model=$this->model($kind);
            foreach($result['rows'] as $data){
                if($kind==='books'){
                    $authors=$data['author_ids'];$categories=$data['category_ids'];unset($data['author_ids'],$data['category_ids']);
                    $book=Book::create($data);$book->authors()->sync($authors);$book->categories()->sync($categories);
                    app(Audit::class)->record('book.imported',['author_ids'=>$authors,'category_ids'=>$categories],$book);
                }else {$entry=$model::create($data);app(Audit::class)->record(strtolower(class_basename($model)).'.imported',[],$entry);}
            }
            app(CatalogRevision::class)->touch();app(Audit::class)->record('catalog.import_completed',['kind'=>$kind,'count'=>count($result['rows'])]);
            return count($result['rows']);
        });}catch(QueryException $e){report($e);$this->fail('Import was rolled back because a database record conflicted or changed. Check duplicates and try again.');}
    }
    public function run(string $path,string $kind): int {return $this->commitRows($this->rows($path,$kind),$kind);}
}
