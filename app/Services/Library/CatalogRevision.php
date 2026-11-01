<?php
namespace App\Services\Library;
use Illuminate\Support\Facades\DB;
class CatalogRevision {
 public function current(): int {return (int)DB::table('catalog_revisions')->where('id',1)->value('version');}
 public function dashboardCurrent(): int {return (int)DB::table('catalog_revisions')->where('id',1)->value('dashboard_version');}
 public function touch(bool $catalog=true): void {
  $changes=['dashboard_version'=>DB::raw('dashboard_version + 1')];if($catalog)$changes['version']=DB::raw('version + 1');
  DB::table('catalog_revisions')->where('id',1)->update($changes);
 }
}
