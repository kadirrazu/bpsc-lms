<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
use App\Models\Book;
return new class extends Migration {
 public function up(): void {
  Schema::table('book_loans',function(Blueprint $t){
   $t->unsignedBigInteger('issued_by')->nullable();$t->string('issuer_name')->nullable();
   $t->string('accession_snapshot',100)->nullable();$t->string('title_snapshot')->nullable();$t->string('title_en_snapshot')->nullable();$t->json('authors_snapshot')->nullable();
   $t->unsignedInteger('renewal_count')->default(0);$t->timestamp('renewed_at')->nullable();
   $t->boolean('is_fined')->default(false);$t->timestamp('fined_at')->nullable();$t->unsignedBigInteger('fined_by')->nullable();$t->index(['is_fined','id'],'loans_fine_report');
  });
  Schema::create('loan_reminders',function(Blueprint $t){$t->id();$t->foreignId('loan_id')->unique()->constrained('book_loans')->cascadeOnDelete();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('sent_by');$t->timestamp('sent_at');$t->timestamps();$t->index(['user_id','sent_at']);});
  DB::table('book_loans')->select('id','book_id')->orderBy('id')->chunkById(500,function($rows){
   $books=Book::withTrashed()->with('authors')->whereIn('id',$rows->pluck('book_id'))->get()->keyBy('id');
   $reviewers=DB::table('book_issue_requests')->whereIn('loan_id',$rows->pluck('id'))->pluck('reviewed_by','loan_id');
   $names=DB::table('users')->whereIn('id',$reviewers->filter()->unique())->pluck('name','id');
   foreach($rows as $row){$book=$books->get($row->book_id);$issuer=$reviewers->get($row->id);DB::table('book_loans')->where('id',$row->id)->update(['issued_by'=>$issuer,'issuer_name'=>$issuer?$names->get($issuer):null,'accession_snapshot'=>$book?->accession_number,'title_snapshot'=>$book?->title_bn,'title_en_snapshot'=>$book?->title_en,'authors_snapshot'=>$book?json_encode($book->authors->pluck('title_bn')->all(),JSON_UNESCAPED_UNICODE):null]);}
  });
 }
 public function down(): void {Schema::dropIfExists('loan_reminders');Schema::table('book_loans',function(Blueprint $t){$t->dropIndex('loans_fine_report');$t->dropColumn(['issued_by','issuer_name','accession_snapshot','title_snapshot','title_en_snapshot','authors_snapshot','renewal_count','renewed_at','is_fined','fined_at','fined_by']);});}
};
