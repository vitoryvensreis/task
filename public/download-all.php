<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

function zipEntryName(string $name,array &$used): string {
 $name=trim(basename(str_replace('\\','/',$name)));if($name==='')$name='arquivo';
 $name=preg_replace('/[^\pL\pN._ -]+/u','_',$name)?:'arquivo';$base=$name;$ext='';$dot=strrpos($name,'.');if($dot!==false){$base=substr($name,0,$dot);$ext=substr($name,$dot);}
 $candidate=$name;$n=2;while(isset($used[mb_strtolower($candidate)])){$candidate=$base.' ('.$n++.')'.$ext;}$used[mb_strtolower($candidate)]=true;return $candidate;
}

try{
 installed();Auth::load();$demandId=integer($_GET['demand_id']??0,1);$d=Auth::demand($demandId);
 if(!class_exists('ZipArchive'))fail('O servidor precisa da extensão ZIP do PHP para baixar tudo de uma vez.',500);
 $params=[$demandId];$sql="SELECT * FROM files WHERE demand_id=? AND kind<>'final'";if(Auth::$user['role']==='client')$sql.=' AND shared=1';$sql.=' ORDER BY id';
 $files=DB::all($sql,$params);if(!$files)fail('Não há arquivos do job para baixar.',404);
 $tmpBase=tempnam(sys_get_temp_dir(),'taskesc_zip_');if($tmpBase===false)fail('Não foi possível preparar o download.',500);@unlink($tmpBase);$zipPath=$tmpBase.'.zip';
 $zip=new ZipArchive();if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)fail('Não foi possível criar o arquivo ZIP.',500);
 $temps=[];$used=[];$links=[];
 try{
  foreach($files as $f){
   $entry=zipEntryName((string)($f['original_name']?:$f['label']?:('arquivo-'.$f['id'])),$used);
   $provider=(string)($f['storage_provider']??'local');
   if($provider==='drive'&&!empty($f['drive_file_id'])&&!empty($f['drive_account_id'])){
    $tmp=tempnam(sys_get_temp_dir(),'taskesc_file_');if($tmp===false)throw new RuntimeException('Não foi possível preparar um arquivo temporário.');
    Google::downloadDriveFileToPath((int)$f['drive_account_id'],(string)$f['drive_file_id'],$tmp);$temps[]=$tmp;$zip->addFile($tmp,$entry);
   }elseif($provider==='local'&&!empty($f['disk_name'])){
    $path=ROOT.'/storage/uploads/'.basename((string)$f['disk_name']);if(is_file($path))$zip->addFile($path,$entry);
   }elseif(!empty($f['url'])){
    $links[]=$entry.' → '.(string)$f['url'];
   }
  }
  if($links)$zip->addFromString('LINKS.txt',implode("\r\n",$links)."\r\n");
 }finally{$zip->close();}
 foreach($temps as $tmp)@unlink($tmp);
 if(!is_file($zipPath)||filesize($zipPath)===0)fail('Nenhum arquivo pôde ser incluído no ZIP.',404);
 $safe=preg_replace('/[^\pL\pN._ -]+/u','_',((string)($d['title']??'task')))?:(string)$demandId;
 $download='TaskEsc - '.$safe.' - arquivos.zip';
 header('Content-Type: application/zip');header('Content-Length: '.(string)filesize($zipPath));header('Content-Disposition: attachment; filename*=UTF-8\'\''.rawurlencode($download));header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');readfile($zipPath);@unlink($zipPath);exit;
}catch(AppError $e){http_response_code($e->status);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
catch(Throwable $e){error_log('Download all: '.(string)$e);http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'Não foi possível preparar o ZIP dos arquivos.';}
