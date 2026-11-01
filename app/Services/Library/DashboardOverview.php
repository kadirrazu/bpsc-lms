<?php
namespace App\Services\Library;
use App\Models\{Book,BookLoan,Author,Category,User};
use Illuminate\Support\Facades\Cache;
class DashboardOverview {
 public function snapshot(): array {
  $revision=app(CatalogRevision::class)->dashboardCurrent();$cached=null;
  try {$cached=Cache::get('library.dashboard.overview');}catch(\Throwable $error){}
  if(is_array($cached) && ($cached['revision']??null)===$revision)return $cached;
  $aggregate=Book::selectRaw('COUNT(*) AS total_books, COALESCE(SUM(number_of_copies),0) AS total_copies')->first();
  $stats=['books'=>(int)$aggregate->total_books,'copies'=>(int)$aggregate->total_copies,'issued'=>(int)BookLoan::whereNull('returned_at')->sum('quantity'),'users'=>User::count(),'members'=>User::where('role','library_member')->count()];
  $catalogRevision=app(CatalogRevision::class)->current();$clouds=null;
  try {$clouds=Cache::get('library.dashboard.clouds');}catch(\Throwable $error){}
  if(!is_array($clouds) || ($clouds['revision']??null)!==$catalogRevision){
  $cloud=fn(string $model)=>$model::select(['id','title_bn'])->withCount('books')->orderByDesc('books_count')->orderBy('title_bn')->limit(24)->get()->map(fn($entry)=>['id'=>$entry->id,'title_bn'=>$entry->title_bn,'count'=>(int)$entry->books_count])->all();
   $clouds=['revision'=>$catalogRevision,'authors'=>$cloud(Author::class),'categories'=>$cloud(Category::class)];
   try {Cache::put('library.dashboard.clouds',$clouds,now()->addSeconds(90));}catch(\Throwable $error){}
  }
  $cached=['revision'=>$revision,'stats'=>$stats,'recent_book_ids'=>Book::latest('id')->limit(5)->pluck('id')->all(),'recent_loan_ids'=>BookLoan::orderByDesc('issued_at')->orderByDesc('id')->limit(5)->pluck('id')->all(),'authors'=>$clouds['authors'],'categories'=>$clouds['categories']];
  try {Cache::put('library.dashboard.overview',$cached,now()->addSeconds(90));}catch(\Throwable $error){}
  return $cached;
 }
}
