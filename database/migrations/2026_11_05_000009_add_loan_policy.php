<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
use Illuminate\Support\Carbon;
return new class extends Migration {
 public function up(): void {
  Schema::table('book_loans',function(Blueprint $t){$t->timestamp('due_at')->nullable();$t->unsignedBigInteger('limit_override_by')->nullable();$t->timestamp('limit_override_at')->nullable();$t->unsignedInteger('active_count_at_issue')->nullable();$t->index(['user_id','returned_at'],'loans_user_active');});
  Schema::table('book_issue_requests',fn(Blueprint $t)=>$t->string('source',20)->default('member_request'));
  DB::table('book_loans')->select('id','issued_at')->orderBy('id')->chunkById(500,function($rows){foreach($rows as $row)DB::table('book_loans')->where('id',$row->id)->update(['due_at'=>Carbon::parse($row->issued_at)->addDays(15)->format('Y-m-d H:i:s')]);});
 }
 public function down(): void {
  Schema::table('book_issue_requests',fn(Blueprint $t)=>$t->dropColumn('source'));
  Schema::table('book_loans',function(Blueprint $t){$t->dropIndex('loans_user_active');$t->dropColumn(['due_at','limit_override_by','limit_override_at','active_count_at_issue']);});
 }
};
