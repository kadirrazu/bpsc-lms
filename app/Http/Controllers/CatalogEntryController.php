<?php
namespace App\Http\Controllers;
use App\Models\{Author,Publisher,Category};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\{Rule,ValidationException};
class CatalogEntryController extends Controller {
 private function context(Request $r): array {
  return match($r->route('kind')){'authors'=>[Author::class,'Authors','Author'],'publishers'=>[Publisher::class,'Publishers','Publisher'],'categories'=>[Category::class,'Categories','Category'],default=>abort(404)};
 }
 public function index(Request $r) {
  Gate::authorize('manage-library');[$model,$plural,$singular]=$this->context($r);$kind=$r->route('kind');
  $data=$r->validate(['q'=>'nullable|string|max:150']);
  $entries=$model::withCount(['books'=>fn($q)=>$q->withTrashed()])->search($data['q']??null)->orderBy('title_bn')->paginate(20)->withQueryString();
  if($r->expectsJson())return response()->json(['html'=>view('catalog-entries.table',compact('entries','kind'))->render()]);
  return view('catalog-entries.index',compact('entries','kind','plural','singular'));
 }
 public function create(Request $r){Gate::authorize('manage-library');[$model,$plural,$singular]=$this->context($r);return view('catalog-entries.form',['entry'=>new $model,'kind'=>$r->route('kind'),'plural'=>$plural,'singular'=>$singular]);}
 public function store(Request $r){Gate::authorize('manage-library');[$model]=$this->context($r);$data=$this->data($r,new $model);DB::transaction(fn()=>$model::create($data));return redirect()->route($r->route('kind').'.index')->with('success','Entry created.');}
 public function edit(Request $r){Gate::authorize('manage-library');[$model,$plural,$singular]=$this->context($r);return view('catalog-entries.form',['entry'=>$model::findOrFail($r->route('entry')),'kind'=>$r->route('kind'),'plural'=>$plural,'singular'=>$singular]);}
 public function update(Request $r){
  Gate::authorize('manage-library');[$model]=$this->context($r);$entry=$model::findOrFail($r->route('entry'));$data=$this->data($r,$entry);
  DB::transaction(function()use($model,$entry,$data){$model::whereKey($entry->id)->lockForUpdate()->firstOrFail()->update($data);});
  return redirect()->route($r->route('kind').'.index')->with('success','Entry updated.');
 }
 public function destroy(Request $r){
  Gate::authorize('delete-library');[$model]=$this->context($r);$r->validate(['confirmation'=>'required|in:DELETE']);
  DB::transaction(function()use($model,$r){$entry=$model::whereKey($r->route('entry'))->lockForUpdate()->firstOrFail();if($entry->books()->withTrashed()->exists())throw ValidationException::withMessages(['entry'=>'This entry belongs to book records and cannot be deleted.']);$entry->delete();});
  return redirect()->route($r->route('kind').'.index')->with('success','Entry deleted.');
 }
 private function data(Request $r,$entry): array {return $r->validate(['title_bn'=>['required','string','max:255',Rule::unique($entry->getTable(),'title_bn')->ignore($entry->id)],'title_en'=>'nullable|string|max:255']);}
}
