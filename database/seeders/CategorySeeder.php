<?php
namespace Database\Seeders;
use App\Models\Category;
use Illuminate\Database\Seeder;
class CategorySeeder extends Seeder {
 public function run(): void {
  foreach(['অন্যান্য'=>'Others','ক্যাটাগরী নির্দিষ্ট করা হয়নি'=>'Uncategorized'] as $bn=>$en) Category::firstOrCreate(['title_bn'=>$bn],['title_en'=>$en]);
 }
}
