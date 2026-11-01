<?php
namespace App\Services\Library;
use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Cache,DB};
class BookSearch {
 public const PAGE_SIZE=20;
 private function pattern(string $term,bool $prefix=false): string {
  $escaped=str_replace(['!','%','_'],['!!','!%','!_'],$term);
  return ($prefix?'':'%').$escaped.'%';
 }
 private function nameIds(string $table,string $pattern) {
  return DB::table($table)->select('id')->where(fn($q)=>$q->whereRaw("title_bn LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("title_en LIKE ? ESCAPE '!'",[$pattern]));
 }
 public function query(array $filters): Builder {
  $query=Book::query();
  if(!empty($filters['publisher_id']))$query->where('books.publisher_id',$filters['publisher_id']);
  if(!empty($filters['author_id']))$query->whereIn('books.id',DB::table('book_authors')->select('book_id')->where('author_id',$filters['author_id']));
  if(!empty($filters['category_id']))$query->whereIn('books.id',DB::table('book_categories')->select('book_id')->where('category_id',$filters['category_id']));
  foreach(['accession_number','call_number'] as $field){
   $term=trim($filters[$field]??'');if($term==='')continue;
   if(in_array($filters['number_match']??'exact',['prefix','partial'],true))$query->whereRaw("books.$field LIKE ? ESCAPE '!'",[$this->pattern($term,($filters['number_match']??'exact')==='prefix')]);
   else $query->where('books.'.$field,$term);
  }
  foreach(array_slice(preg_split('/\s+/u',trim($filters['q']??''),-1,PREG_SPLIT_NO_EMPTY),0,8) as $term){
   $pattern=$this->pattern($term);
   // Uncorrelated ID sets let the database search each metadata table once.
   // Flat title/identifier predicates can short-circuit broad matches without a UNION sort.
   $query->where(function($q)use($pattern){
    foreach(['title_bn','title_en','accession_number','call_number','shelf_number'] as $field)$q->orWhereRaw("books.$field LIKE ? ESCAPE '!'",[$pattern]);
    $q->orWhereIn('books.id',DB::table('book_authors')->select('book_id')->whereIn('author_id',$this->nameIds('authors',$pattern)));
    $q->orWhereIn('books.publisher_id',$this->nameIds('publishers',$pattern));
    $q->orWhereIn('books.id',DB::table('book_categories')->select('book_id')->whereIn('category_id',$this->nameIds('categories',$pattern)));
   });
  }
  return $query;
 }
 public function paginate(Request $request,array $filters): LengthAwarePaginator {
  $page=max(1,(int)$request->input('page',1));$normalized=$filters;unset($normalized['page'],$normalized['view']);
  foreach($normalized as $field=>$value){if($value===null || (is_string($value) && trim($value)===''))unset($normalized[$field]);elseif(is_string($value))$normalized[$field]=trim($value);}
  foreach(['author_id','publisher_id','category_id'] as $field)if(isset($normalized[$field]))$normalized[$field]=(int)$normalized[$field];
  $normalized['number_match']=$normalized['number_match']??'exact';ksort($normalized);
  $revision=app(CatalogRevision::class)->current();
  $fingerprint=hash('sha256',json_encode([$revision,$normalized,$page],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
  // 256 page slots + 256 count slots keep cache storage bounded across revisions.
  $key='library.catalog.page.'.substr($fingerprint,0,2);$cached=null;
  try {$cached=Cache::get($key);}catch(\Throwable $error){/* Search remains available if a separate cache backend is down. */}
  if(!is_array($cached) || ($cached['fingerprint']??null)!==$fingerprint){
   $query=$this->query($normalized);
   $countFingerprint=hash('sha256',json_encode([$revision,$normalized],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
   $countKey='library.catalog.count.'.substr($countFingerprint,0,2);$countCache=null;
   try {$countCache=Cache::get($countKey);}catch(\Throwable $error){}
   if(!is_array($countCache) || ($countCache['fingerprint']??null)!==$countFingerprint){
    $countCache=['fingerprint'=>$countFingerprint,'total'=>(clone $query)->count()];
    try {Cache::put($countKey,$countCache,now()->addSeconds(90));}catch(\Throwable $error){}
   }
   $cached=['fingerprint'=>$fingerprint,'total'=>(int)$countCache['total'],'ids'=>(clone $query)->orderByDesc('books.id')->forPage($page,self::PAGE_SIZE)->pluck('books.id')->all()];
   try {Cache::put($key,$cached,now()->addSeconds(90));}catch(\Throwable $error){/* Optional acceleration only. */}
  }
  $books=Book::with(['authors','publisher','categories'])->withSum(['loans as issued_copies'=>fn($q)=>$q->whereNull('returned_at')],'quantity')->whereIn('id',$cached['ids'])->orderByDesc('id')->get();
  return (new LengthAwarePaginator($books,(int)$cached['total'],self::PAGE_SIZE,$page,['path'=>$request->url()]))->withQueryString();
 }
}
