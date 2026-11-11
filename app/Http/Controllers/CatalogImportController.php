<?php
namespace App\Http\Controllers;
use App\Services\Library\{CatalogImport,ImportSample,ImportDraft};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Pagination\LengthAwarePaginator;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
class CatalogImportController extends Controller {
 public function index(string $kind,CatalogImport $imports){Gate::authorize('manage-library');$headers=$imports->headers($kind);return view('imports.index',compact('kind','headers'));}
 public function sample(string $kind,ImportSample $samples){
  Gate::authorize('manage-library');$book=$samples->workbook($kind);
  return response()->streamDownload(function()use($book){try{(new Xlsx($book))->save('php://output');}finally{$book->disconnectWorksheets();}},$kind.'-import-sample.xlsx',['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Cache-Control'=>'no-store']);
 }
 public function store(Request $r,string $kind,CatalogImport $imports,ImportDraft $drafts){
  Gate::authorize('manage-library');$imports->headers($kind);$r->validate(['file'=>['required','file','extensions:xlsx','max:10240']]);
  $token=$drafts->create($r,$kind,$imports->rows($r->file('file')->getRealPath(),$kind));return redirect()->route('catalog-import.preview',[$kind,$token]);
 }
 public function preview(Request $r,string $kind,string $token,CatalogImport $imports,ImportDraft $drafts){
  Gate::authorize('manage-library');$r->validate(['page'=>'nullable|integer|min:1|max:25']);
  return $drafts->access($r,$kind,$token,function(&$draft)use($r,$kind,$token,$imports){
   if($draft['status']!=='pending')return redirect()->route($kind.'.index')->with('success','This import is already completed or cancelled.');
   $result=$imports->inspect($draft['rows'],$kind);$revision=$draft['revision'];$page=(int)$r->input('page',1);
   $rows=new LengthAwarePaginator(array_slice($result['rows'],($page-1)*20,20,true),count($result['rows']),20,$page,['path'=>$r->url()]);
   return view('imports.preview',compact('kind','token','result','revision','rows'));
  });
 }
 public function edit(Request $r,string $kind,string $token,int $row,CatalogImport $imports,ImportDraft $drafts){
  Gate::authorize('manage-library');return $drafts->access($r,$kind,$token,function(&$draft)use($r,$kind,$token,$row,$imports){
   abort_unless($draft['status']==='pending' && isset($draft['rows'][$row]),404);$result=$imports->inspect($draft['rows'],$kind);$data=$result['rows'][$row];$rowErrors=$result['errors'][$row]??[];$revision=$draft['revision'];
   $authorSelections=$kind==='books'?\App\Models\Author::whereIn('id',array_filter((array)$r->old('author_ids',$data['author_ids']??[]),'is_numeric'))->get():collect();$categorySelections=$kind==='books'?\App\Models\Category::whereIn('id',array_filter((array)$r->old('category_ids',$data['category_ids']??[]),'is_numeric'))->get():collect();$publisherSelection=$kind==='books'?\App\Models\Publisher::find($r->old('publisher_id',$data['publisher_id']??null)):null;
   return view('imports.edit',compact('kind','token','row','data','rowErrors','revision','authorSelections','categorySelections','publisherSelection'));
  });
 }
 public function update(Request $r,string $kind,string $token,int $row,CatalogImport $imports,ImportDraft $drafts){
  Gate::authorize('manage-library');$r->validate(['revision'=>'required|integer']);
  return $drafts->access($r,$kind,$token,function(&$draft)use($r,$kind,$token,$row,$imports){
   abort_unless($draft['status']==='pending' && isset($draft['rows'][$row]),404);abort_unless((int)$r->input('revision')===$draft['revision'],409,'This preview changed. Reload before editing.');
   $rules=$kind==='books'?(new \App\Http\Requests\BookRequest)->rules():['title_bn'=>'required|string|max:255','title_en'=>'nullable|string|max:255'];unset($rules['cover'],$rules['remove_cover']);$data=$r->validate($rules);
   foreach(['author_ids','category_ids'] as $field)if(isset($data[$field]))$data[$field]=array_map('intval',$data[$field]);
   $draft['rows'][$row]=$data;$draft['revision']++;return redirect()->route('catalog-import.preview',[$kind,$token])->with('success',"Row $row updated. Review all rows before confirming.");
  });
 }
 public function confirm(Request $r,string $kind,string $token,CatalogImport $imports,ImportDraft $drafts){
  Gate::authorize('manage-library');$r->validate(['confirmed'=>'required|accepted','revision'=>'required|integer','fingerprint'=>'required|string|size:64']);
  return $drafts->access($r,$kind,$token,function(&$draft)use($r,$kind,$token,$imports){
   if($draft['status']==='confirmed')return redirect()->route($kind.'.index')->with('success','This import was already completed.');
   abort_unless($draft['status']==='pending',410);abort_unless((int)$r->input('revision')===$draft['revision'],409,'This preview changed. Reload before confirming.');
   try{$count=$imports->commitRows($draft['rows'],$kind,$r->input('fingerprint'));}catch(\Illuminate\Validation\ValidationException $e){return redirect()->route('catalog-import.preview',[$kind,$token])->withErrors($e->errors());}
   $draft['status']='confirmed';$draft['rows']=[];$draft['revision']++;return redirect()->route($kind.'.index')->with('success',number_format($count).' records imported successfully.');
  });
 }
 public function cancel(Request $r,string $kind,string $token,ImportDraft $drafts){
  Gate::authorize('manage-library');return $drafts->access($r,$kind,$token,function(&$draft)use($kind){if($draft['status']==='pending'){$draft['status']='cancelled';$draft['rows']=[];$draft['revision']++;}return redirect()->route('catalog-import.index',$kind)->with('success','Import preview closed.');});
 }
}
