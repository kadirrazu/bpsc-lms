<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up(): void {
  Schema::create('catalog_revisions',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->unsignedBigInteger('version')->default(1);$t->unsignedBigInteger('dashboard_version')->default(1);});
  DB::table('catalog_revisions')->insert(['id'=>1,'version'=>1]);
  Schema::table('books',function(Blueprint $t){$t->index(['deleted_at','accession_number'],'books_live_accession');$t->index(['deleted_at','call_number'],'books_live_call');$t->index(['deleted_at','publisher_id','id'],'books_live_publisher');});
  Schema::table('book_loans',function(Blueprint $t){$t->index(['issued_at','id'],'loans_recent_issues');$t->index(['returned_at','id'],'loans_open_issues');});
  (new \Database\Seeders\CategorySeeder)->run();
 }
 public function down(): void {
  Schema::table('book_loans',function(Blueprint $t){$t->dropIndex('loans_recent_issues');$t->dropIndex('loans_open_issues');});
  Schema::table('books',function(Blueprint $t){$t->dropIndex('books_live_accession');$t->dropIndex('books_live_call');$t->dropIndex('books_live_publisher');});
  Schema::dropIfExists('catalog_revisions');
  // Seeded categories are retained to protect existing book assignments.
 }
};
