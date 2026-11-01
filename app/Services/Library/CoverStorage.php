<?php
namespace App\Services\Library;
use App\Models\Book;
use Illuminate\Support\Facades\{DB,Storage,Log};
class CoverStorage {
 public function defer(?string $path): void {
  if(!$path)return;
  DB::table('cover_file_deletions')->insertOrIgnore(['path'=>$path,'attempts'=>0,'created_at'=>now(),'updated_at'=>now()]);
 }
 public function purge(?string $only=null): int {
  $removed=0;
  $rows=DB::table('cover_file_deletions')->when($only,fn($q)=>$q->where('path',$only))->orderBy('id')->limit(200)->get();
  foreach($rows as $row){
   if(!str_starts_with($row->path,'book-covers/'))continue;
   if(Book::withTrashed()->where('cover_path',$row->path)->exists()){DB::table('cover_file_deletions')->where('id',$row->id)->delete();continue;}
   try {
    $disk=Storage::disk('local');
    if($disk->exists($row->path) && !$disk->delete($row->path))throw new \RuntimeException('Cover deletion failed.');
    DB::table('cover_file_deletions')->where('id',$row->id)->delete();$removed++;
   } catch(\Throwable $e){
    DB::table('cover_file_deletions')->where('id',$row->id)->update(['attempts'=>DB::raw('attempts + 1'),'last_error'=>mb_substr($e->getMessage(),0,500),'updated_at'=>now()]);
    Log::warning('Book cover cleanup pending.', ['path'=>$row->path]);
   }
  }
  return $removed;
 }
 public function discard(?string $path): void {
  if(!$path)return;
  try {$disk=Storage::disk('local');if(!$disk->exists($path) || $disk->delete($path))return;}catch(\Throwable $e){Log::warning('Immediate unused cover cleanup failed.',['path'=>$path]);}
  try {$this->defer($path);}catch(\Throwable $e){Log::error('Unable to queue unused cover cleanup.',['path'=>$path]);}
 }
}
