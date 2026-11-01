<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  foreach (['authors','publishers','categories'] as $name) Schema::create($name,function(Blueprint $t){
   $t->id();$t->string('title_bn');$t->string('title_en')->nullable();$t->timestamps();$t->unique('title_bn');$t->index('title_en');
  });
  Schema::create('books',function(Blueprint $t){
   $t->id();$t->string('accession_number',100)->unique();$t->string('title_bn');$t->string('title_en')->nullable();
   $t->foreignId('publisher_id')->constrained()->restrictOnDelete();
   $t->string('call_number',100);$t->string('shelf_number',100)->nullable();$t->unsignedSmallInteger('publication_year')->nullable();$t->unsignedInteger('number_of_copies')->default(1);$t->unsignedBigInteger('total_issue_count')->default(0);$t->string('cover_path')->nullable();$t->timestamps();$t->softDeletes();
   $t->index('title_bn');$t->index('title_en');$t->index('call_number');$t->index(['deleted_at','id']);
  });
  Schema::create('book_authors',function(Blueprint $t){$t->foreignId('book_id')->constrained()->cascadeOnDelete();$t->foreignId('author_id')->constrained()->restrictOnDelete();$t->primary(['book_id','author_id']);$t->index('author_id');});
  Schema::create('book_categories',function(Blueprint $t){$t->foreignId('book_id')->constrained()->cascadeOnDelete();$t->foreignId('category_id')->constrained()->restrictOnDelete();$t->primary(['book_id','category_id']);$t->index('category_id');});
  // Circulation foundation. No issue/return endpoints are exposed by this release.
  Schema::create('book_loans',function(Blueprint $t){
   $t->id();$t->foreignId('book_id')->constrained()->restrictOnDelete();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->unsignedInteger('quantity')->default(1);$t->timestamp('issued_at');$t->timestamp('returned_at')->nullable();$t->timestamps();$t->index(['book_id','returned_at']);
  });
  Schema::create('cover_file_deletions',function(Blueprint $t){$t->id();$t->string('path')->unique();$t->unsignedInteger('attempts')->default(0);$t->text('last_error')->nullable();$t->timestamps();});
 }
 public function down(): void {Schema::dropIfExists('cover_file_deletions');Schema::dropIfExists('book_loans');Schema::dropIfExists('book_categories');Schema::dropIfExists('book_authors');Schema::dropIfExists('books');foreach(['categories','publishers','authors'] as $name)Schema::dropIfExists($name);}
};
