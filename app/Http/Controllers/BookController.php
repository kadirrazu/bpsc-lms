<?php
namespace App\Http\Controllers;
use App\Http\Requests\BookRequest;
use App\Models\{Book,Author,Publisher,Category,AuditLog};
use App\Services\Library\CoverStorage;
use App\Services\Library\Audit;
use App\Services\Library\{BookSearch,CatalogRevision,CopyAccessions};
use Illuminate\Database\UniqueConstraintViolationException;
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
 public function edit(Request $r,Book $book){Gate::authorize('manage-library');$book->load(['authors','publisher','categories','copies'=>fn ($query) => $query->where('is_active', true)->orderBy('id')]);return view('books.form',array_merge(compact('book'),$this->selectedEntries($r,$book)));}
 public function show(Book $book){Gate::authorize('manage-library');return $this->details($book,false);}
 public function memberShow(Book $book){Gate::authorize('member-portal');return $this->details($book,true);}
 private function details(Book $book, bool $member)
 {
  $book->load(['authors', 'publisher', 'categories']);
  $borrowers = Gate::allows('view-book-borrowers') ? $book->loans()->whereNull('returned_at')
   ->select('user_id')->selectRaw('SUM(quantity) AS copies, MIN(issued_at) AS first_issued_at')->groupBy('user_id')
   ->orderBy('first_issued_at')->orderBy('user_id')->with('user.designation')->get() : null;
  $loanAccessions = $borrowers?->isNotEmpty() ? $book->loans()->whereNull('returned_at')->whereNotNull('book_copy_id')
   ->orderBy('issued_at')->orderBy('id')->get(['user_id', 'accession_snapshot'])->groupBy('user_id') : collect();
  $issued = $borrowers === null ? $book->activeLoanQuantity() : (int) $borrowers->sum('copies');
  if (! $book->isSoftCopy()) {
   $book->load(['copies' => fn ($query) => $query->where('is_active', true)
    ->withExists(['loans as on_loan' => fn ($loans) => $loans->whereNull('returned_at')])->orderBy('id')]);
  }
  $memberLoan = $member ? $book->loans()->where('user_id', auth()->id())
   ->whereNull('returned_at')->latest('issued_at')->first() : null;
  $activity = Gate::allows('view-book-activity') ? AuditLog::where('subject_type', 'Book')
   ->where('subject_id', $book->id)->latest('id')->limit(20)->get() : null;

  return view('books.show', compact('book', 'member', 'issued', 'activity', 'memberLoan', 'borrowers', 'loanAccessions'));
 }
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
 private function bookFields(array $data): array
 {
  unset($data['author_ids'], $data['category_ids'], $data['additional_accessions']);
  $data['printed_currency'] = $data['printed_currency'] ?? 'BDT';
  if (($data['format'] ?? 'hard') === 'soft') {
   $data['number_of_copies'] = 0;
   $data['call_number'] = '';
   $data['shelf_number'] = null;
  }

  return $data;
 }

 public function store(BookRequest $request, CoverStorage $files, CopyAccessions $accessions)
 {
  $paths = [];
  try {
   $data = $request->safe()->except(['cover', 'remove_cover', 'pdf']);
   $numbers = $accessions->validate($data);
   $paths = $this->storeFiles($request, $files);
   $book = DB::transaction(function () use ($data, $paths, $numbers, $accessions) {
    $this->lockEntries($data);
    $book = Book::create($paths + $this->bookFields($data));
    $accessions->sync($book, $numbers);
    $this->relationships($book, $data);
    return $book;
   });
  } catch (\Throwable $error) {
   $this->discardFiles($paths, $files);
   if ($error instanceof UniqueConstraintViolationException) {
    throw ValidationException::withMessages(['accession_number' => 'This accession is already in use.']);
   }
   throw $error;
  }

  return redirect()->route('books.show', $book)->with('success', 'Book added.');
 }

 public function update(BookRequest $request, Book $book, CoverStorage $files, CopyAccessions $accessions)
 {
  $paths = [];
  $retired = [];
  try {
   $data = $request->safe()->except(['cover', 'remove_cover', 'pdf']);
   $paths = $this->storeFiles($request, $files);
   DB::transaction(function () use ($request, $book, $files, $paths, $data, $accessions, &$retired) {
    $this->lockEntries($data);
    $current = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
    $data['format'] = $current->format;
    $issued = $current->activeLoanQuantity();
    if (! $current->isSoftCopy() && (int) $data['number_of_copies'] < $issued) {
     throw ValidationException::withMessages(['number_of_copies' => 'Copies cannot be fewer than the copies currently issued.']);
    }
    $numbers = $accessions->validate($data, $current->id);
    if ($request->boolean('remove_cover')) {
     $data['cover_path'] = null;
    }
    foreach (array_intersect_key($paths + $data, array_flip(['cover_path', 'pdf_path'])) as $field => $path) {
     $retired[] = $current->{$field};
     $files->defer($current->{$field});
    }
    $current->update($paths + $this->bookFields($data));
    $accessions->sync($current, $numbers);
    $this->relationships($current, $data);
   });
  } catch (\Throwable $error) {
   $this->discardFiles($paths, $files);
   if ($error instanceof UniqueConstraintViolationException) {
    throw ValidationException::withMessages(['accession_number' => 'This accession is already in use.']);
   }
   throw $error;
  }
  $this->discardRetired($retired, $files);

  return redirect()->route('books.show', $book)->with('success', 'Book updated.');
 }

 public function destroy(Request $request, Book $book, CoverStorage $files)
 {
  Gate::authorize('delete-library');
  $request->validate(['confirmation' => 'required|in:DELETE']);
  $retired = DB::transaction(function () use ($book, $files) {
   $current = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
   if ($current->loans()->whereNull('returned_at')->exists()) {
    throw ValidationException::withMessages(['book' => 'This book is currently issued and cannot be deleted. Return all issued copies first.']);
   }
   $paths = [$current->cover_path, $current->pdf_path];
   foreach ($paths as $path) {
    $files->defer($path);
   }
   $current->update(['cover_path' => null, 'pdf_path' => null]);
   $current->delete();
   return $paths;
  });
  $this->discardRetired($retired, $files);

  return redirect()->route('books.index')->with('success', 'Book deleted.');
 }

 private function storeFiles(BookRequest $request, CoverStorage $files): array
 {
  $paths = [];
  try {
   foreach (['cover' => 'book-covers', 'pdf' => 'book-pdfs'] as $field => $directory) {
    if ($request->hasFile($field)) {
     $file = $request->file($field);
     $filename = $field === 'pdf'
      ? pathinfo($file->hashName(), PATHINFO_FILENAME).'.'.strtolower($file->getClientOriginalExtension())
      : $file->hashName();
     $paths[$field.'_path'] = $directory.'/'.$filename;
     if (! $file->storeAs($directory, $filename, 'local')) {
      throw new \RuntimeException("The $field file could not be stored.");
     }
    }
   }
  } catch (\Throwable $error) {
   $this->discardFiles($paths, $files);
   throw $error;
  }

  return $paths;
 }

 private function discardFiles(array $paths, CoverStorage $files): void
 {
  foreach ($paths as $path) {
   $files->discard($path);
  }
 }

 private function discardRetired(array $paths, CoverStorage $files): void
 {
  foreach (array_filter($paths) as $path) {
   $files->purge($path);
  }
 }

 public function download(Request $request, Book $book)
 {
  Gate::authorize('member-portal');
  abort_if($request->isMethod('HEAD'), 405);

  return DB::transaction(function () use ($book) {
   $current = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
   $disk = Storage::disk('local');
   abort_unless($current->isSoftCopy() && $current->pdf_path && $disk->exists($current->pdf_path), 404);
   $stream = $disk->readStream($current->pdf_path);
   abort_unless(is_resource($stream), 404);
   $current->increment('download_count');
   $extension = $current->digitalExtension();
   app(Audit::class)->record('book.'.$extension.'_downloaded', [], $current);
   return response()->streamDownload(function () use ($stream) {
    try {
     fpassthru($stream);
    } finally {
     fclose($stream);
    }
   }, 'library-book-'.$current->id.'.'.$extension, [
    'Content-Type' => $extension === 'epub' ? 'application/epub+zip' : 'application/pdf',
    'Cache-Control' => 'private, no-store',
    'X-Content-Type-Options' => 'nosniff',
   ]);
  });
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
