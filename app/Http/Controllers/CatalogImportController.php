<?php
namespace App\Http\Controllers;
use App\Services\Library\{CatalogImport,ImportSample};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
class CatalogImportController extends Controller {
 public function index(string $kind,CatalogImport $imports){Gate::authorize('manage-library');$headers=$imports->headers($kind);return view('imports.index',compact('kind','headers'));}
 public function sample(string $kind,ImportSample $samples){
  Gate::authorize('manage-library');$book=$samples->workbook($kind);
  return response()->streamDownload(function()use($book){try{(new Xlsx($book))->save('php://output');}finally{$book->disconnectWorksheets();}},$kind.'-import-sample.xlsx',['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Cache-Control'=>'no-store']);
 }
 public function store(Request $r,string $kind,CatalogImport $imports){
  Gate::authorize('manage-library');$imports->headers($kind);
  $r->validate(['file'=>['required','file','extensions:xlsx','max:10240']]);
  $count=$imports->run($r->file('file')->getRealPath(),$kind);
  return redirect()->route($kind.'.index')->with('success',number_format($count).' '.($kind==='books'?'books':'records').' imported successfully.');
 }
}
