<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::table('books',function(Blueprint $t){$t->decimal('price',12,2)->nullable();$t->string('collection_method',20)->nullable();});}
 public function down(): void {Schema::table('books',fn(Blueprint $t)=>$t->dropColumn(['price','collection_method']));}
};
