<?php
namespace App\Http\Controllers;
use App\Http\Requests\BookRequest;
use App\Models\{Book,Author,Publisher,Category,AuditLog};
use App\Services\Library\CoverStorage;
use App\Services\Library\Audit;
use App\Services\Library\{BookSearch,CatalogRevision};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate,Storage};
use Illuminate\Validation\ValidationException;
class BookController extends Controller {
 public function index(Request $r){Gate::authorize('manage-library');return $this->listing($r,false);}
 public function catalog(Request $r){Gate::authorize('member-portal');return $this->listing($r,true);}
 public function entity(Request $r){
  Gate::authorize('view-catalog');
  [$model,$field,$label]=match($r->route('kind')){'authors'=>[Author::class,'author_id','Author'],'publishers'=>[Publisher::class,'publisher_id','Publisher'],'categories'=>[Category::class,'category_id','Category'],default=>abort(404)};
  $entry=$model::findOrFail($r->route('entry'));$r->merge([$field=>$entry->id]);
  return $this->listing($r,$r->user()->role===\App\Enums\UserRole::Member,['entry'=>$entry,'kind'=>$r->route('kind'),'filter'=>$field,'label'=>$label]);
 }
 private function listing(Request $r,bool $member,?array $context=null){
  $filters=$r->validate(['view'=>'nullable|in:grid,list','q'=>'nullable|string|max:150','accession_number'=>'nullable|string|max:100','call_number'=>'nullable|string|max:100','number_match'=>'nullable|in:exact,prefix,partial','author_id'=>'nullable|integer|exists:authors,id','publisher_id'=>'nullable|integer|exists:publishers,id','category_id'=>'nullable|integer|exists:categories,id','page'=>'nullable|integer|min:1']);
  $books=app(BookSearch::class)->paginate($r,$filters);
  if($r->expectsJson())return response()->json(['html'=>view('books.results',compact('books','member'))->render()]);
  return view('books.index',array_merge(compact('books','member','context'),$this->selectedEntries($r)));
 }
 public function create(Request $r){Gate::authorize('manage-library');return view('books.form',array_merge(['book'=>new Book(['number_of_copies'=>1])],$this->selectedEntries($r)));}
 public function edit(Request $r,Book $book){Gate::authorize('manage-library');$book->load(['authors','publisher','categories']);return view('books.form',array_merge(compact('book'),$this->selectedEntries($r,$book)));}
 public function show(Book $book){Gate::authorize('manage-library');return $this->details($book,false);}
 public function memberShow(Book $book){Gate::authorize('member-portal');return $this->details($book,true);}
 private function details(Book $book,bool $member){$book->load(['authors','publisher','categories']);$issued=$book->activeLoanQuantity();$activity=Gate::allows('view-logs') ? AuditLog::where('subject_type','Book')->where('subject_id',$book->id)->latest('id')->limit(20)->get() : null;return view('books.show',compact('book','member','issued','activity'));}
 private function selectedEntries(Request $r,?Book $book=null): array {
  $selected=[];
  foreach(['author'=>Author::class,'publisher'=>Publisher::class,'category'=>Category::class] as $key=>$model){$id=$r->routeIs('books.create','books.edit') ? old($key.'_id',$r->input($key.'_id',$key==='publisher' ? $book?->publisher_id : null)) : $r->input($key.'_id');$selected[$key.'Selection']=is_scalar($id)?$model::find($id):null;}
  if($r->routeIs('books.create','books.edit')) foreach(['author'=>Author::class,'category'=>Category::class] as $key=>$model){
   $relation=$key==='author'?'authors':'categories';$ids=old($key.'_ids',$book?->{$relation}->modelKeys()??[]);
   $selected[$key.'Selections']=$model::whereIn('id',is_array($ids)?array_filter($ids,'is_scalar'):[])->orderBy('title_bn')->get();
  }
  return $selected;
 }
 private function lockEntries(array $data): void {
  $authors=Author::whereIn('id',$data['author_ids'])->orderBy('id')->lockForUpdate()->get();
  if($authors->count()!==count($data['author_ids']))throw ValidationException::withMessages(['author_ids'=>'An author was removed. Select again.']);
  if(!Publisher::whereKey($data['publisher_id'])->lockForUpdate()->first())throw ValidationException::withMessages(['publisher_id'=>'This publisher was removed. Select another entry.']);
  $categories=Category::whereIn('id',$data['category_ids'])->orderBy('id')->lockForUpdate()->get();
  if($categories->count()!==count($data['category_ids']))throw ValidationException::withMessages(['category_ids'=>'A category was removed. Select again.']);
 }
 private function relationships(Book $book,array $data): void {
  $before=['author_ids'=>$book->authors()->allRelatedIds()->map(fn($id)=>(int)$id)->sort()->values()->all(),'category_ids'=>$book->categories()->allRelatedIds()->map(fn($id)=>(int)$id)->sort()->values()->all()];
  $book->authors()->sync($data['author_ids']);$book->categories()->sync($data['category_ids']);
  $after=['author_ids'=>array_map('intval',$data['author_ids']),'category_ids'=>array_map('intval',$data['category_ids'])];sort($after['author_ids']);sort($after['category_ids']);
  if($before!==$after){app(CatalogRevision::class)->touch();app(Audit::class)->record('book.relationships_changed',compact('before','after'),$book);}
 }
 private function bookFields(array $data): array {unset($data['author_ids'],$data['category_ids']);return $data;}
 public function store(BookRequest $r,CoverStorage $covers){
  $path=null;
  try {
   if($r->hasFile('cover')){$path=$r->file('cover')->hashName('book-covers');if(!$r->file('cover')->store('book-covers','local'))throw new \RuntimeException('Cover could not be stored.');}
   $data=$r->safe()->except(['cover','remove_cover']);$data['cover_path']=$path;
   $book=DB::transaction(function()use($data){$this->lockEntries($data);$book=Book::create($this->bookFields($data));$this->relationships($book,$data);return $book;});
  }catch(\Throwable $e){$covers->discard($path);throw $e;}
  return redirect()->route('books.show',$book)->with('success','Book added.');
 }
 public function update(BookRequest $r,Book $book,CoverStorage $covers){
  $path=null;$retired=null;
  try {
   if($r->hasFile('cover')){$path=$r->file('cover')->hashName('book-covers');if(!$r->file('cover')->store('book-covers','local'))throw new \RuntimeException('Cover could not be stored.');}
   $data=$r->safe()->except(['cover','remove_cover']);
   DB::transaction(function()use($r,$book,$covers,$path,$data,&$retired){
    $this->lockEntries($data);$current=Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
    if((int)$data['number_of_copies']<$current->activeLoanQuantity())throw ValidationException::withMessages(['number_of_copies'=>'Copies cannot be fewer than the copies currently issued.']);
    if($path || $r->boolean('remove_cover')){$retired=$current->cover_path;$covers->defer($retired);$data['cover_path']=$path;}
    $current->update($this->bookFields($data));$this->relationships($current,$data);
   });
  }catch(\Throwable $e){$covers->discard($path);throw $e;}
  if($retired)$covers->purge($retired);return redirect()->route('books.show',$book)->with('success','Book updated.');
 }
 public function destroy(Request $r,Book $book,CoverStorage $covers){
  Gate::authorize('delete-library');$r->validate(['confirmation'=>'required|in:DELETE']);
  $retired=null;
  DB::transaction(function()use($book,$covers,&$retired){
   $current=Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
   if($current->loans()->whereNull('returned_at')->exists())throw ValidationException::withMessages(['book'=>'This book is currently issued and cannot be deleted. Return all issued copies first.']);
   $retired=$current->cover_path;$covers->defer($retired);$current->cover_path=null;$current->save();$current->delete();
  });
  if($retired)$covers->purge($retired);return redirect()->route('books.index')->with('success','Book deleted.');
 }
 public function cover(Request $r,Book $book){
  Gate::authorize('view-catalog');
  abort_unless($book->cover_path && Storage::disk('local')->exists($book->cover_path),404);
  return Storage::disk('local')->response($book->cover_path,null,['X-Content-Type-Options'=>'nosniff'],'inline');
 }
 public function options(Request $r,string $kind){
  Gate::authorize('view-catalog');$model=match($kind){'authors'=>Author::class,'publishers'=>Publisher::class,'categories'=>Category::class,default=>abort(404)};
  $data=$r->validate(['view'=>'nullable|in:grid,list','q'=>'nullable|string|max:150','exclude'=>'sometimes|array|max:50','exclude.*'=>'integer']);
  return response()->json(['results'=>$model::search($data['q']??null)->when($data['exclude']??null,fn($q,$ids)=>$q->whereNotIn('id',$ids))->orderBy('title_bn')->limit(20)->get(['id','title_bn'])->map(fn($e)=>['id'=>$e->id,'label'=>$e->title_bn])]);
 }
}
