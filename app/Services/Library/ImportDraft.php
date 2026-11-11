<?php
namespace App\Services\Library;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
class ImportDraft {
 private function directory(): string {return storage_path('app/private/catalog-imports');}
 public function prune(): int {
  $count=0;foreach(glob($this->directory().'/*.json')?:[] as $path){
   if(!preg_match('/^[a-f0-9]{64}\.json$/D',basename($path)) || filemtime($path)>=time()-7200)continue;
   $file=@fopen($path,'r+');if(!$file)continue;$remove=false;try{if(flock($file,LOCK_EX|LOCK_NB)){$remove=true;flock($file,LOCK_UN);}}finally{fclose($file);}if($remove && @unlink($path))$count++;
  }return $count;
 }
 public function create(Request $r,string $kind,array $rows): string {
  File::ensureDirectoryExists($this->directory(),0700,true);
  $this->prune();
  $token=bin2hex(random_bytes(32));$draft=['actor'=>(int)$r->user()->id,'session'=>hash('sha256',$r->session()->getId()),'kind'=>$kind,'expires'=>time()+3600,'revision'=>1,'status'=>'pending','rows'=>$rows];
  $path=$this->directory().'/'.$token.'.json';if(file_put_contents($path,json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new \RuntimeException('Cannot save import preview. Check private storage permissions.');chmod($path,0600);return $token;
 }
 // One lock serializes edits, cancellation and confirmation of a draft, including double clicks.
 public function access(Request $r,string $kind,string $token,callable $callback): mixed {
  abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token),404);$path=$this->directory().'/'.$token.'.json';abort_unless(is_file($path),404);
  $file=fopen($path,'r+');abort_unless($file!==false,404);
  try{flock($file,LOCK_EX);$draft=json_decode(stream_get_contents($file),true,512,JSON_THROW_ON_ERROR);
   abort_unless($draft['actor']===(int)$r->user()->id && hash_equals($draft['session'],hash('sha256',$r->session()->getId())) && $draft['kind']===$kind,404);
   abort_if($draft['expires']<time(),410,'This import preview has expired. Upload the workbook again.');
   $result=$callback($draft);rewind($file);ftruncate($file,0);fwrite($file,json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($file);return $result;
  }finally{flock($file,LOCK_UN);fclose($file);}
 }
}
