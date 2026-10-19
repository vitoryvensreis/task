<?php
declare(strict_types=1);
// Pure filesystem test: no production database, session, upload, or user data.
$root=sys_get_temp_dir().'/taskesc-avatar-test-'.bin2hex(random_bytes(7));
define('ROOT',$root);
$dir=ROOT.'/storage/avatars';
if(!mkdir($dir,0700,true))throw new RuntimeException('Cannot create isolated test directory');
function base(string $path=''): string{return 'https://taskesc.example.test/'.ltrim($path,'/');}
function fail(string $message,int $status=422): never{throw new RuntimeException($message,$status);}
require dirname(__DIR__).'/app/UserAvatars.php';
function checkAvatar(bool $ok,string $reason): void{if(!$ok)throw new RuntimeException($reason);}
try {
 $reflect=new ReflectionClass(UserAvatars::class);
 checkAvatar($reflect->getReflectionConstant('MAX_BYTES')->getValue()===10*1024*1024,'10 MiB validation constant missing');
 $publish=$reflect->getMethod('publish');
 $stage=function(string $contents)use($dir):string{
  $file=$dir.'/upload-'.bin2hex(random_bytes(8)).'.tmp';
  if(file_put_contents($file,$contents)===false)throw new RuntimeException('Unable to stage test photo');
  return $file;
 };
 $publish->invoke(null,1,$stage('old-photo-jpeg'),'jpg');
 checkAvatar(is_file($dir.'/u1.jpg'),'Initial photo missing');
 $first=UserAvatars::url(1);
 $publish->invoke(null,1,$stage('new-photo-webp'),'webp');
 checkAvatar(!file_exists($dir.'/u1.jpg'),'Replaced JPEG photo not deleted');
 checkAvatar(file_get_contents($dir.'/u1.webp')==='new-photo-webp','New WebP photo missing');
 checkAvatar(UserAvatars::url(1)!==$first,'Avatar URL cache key must change after replacement');
 $second=UserAvatars::url(1);
 $publish->invoke(null,1,$stage('third-photo-webp'),'webp');
 checkAvatar(file_get_contents($dir.'/u1.webp')==='third-photo-webp','Same-extension avatar did not replace bytes');
 checkAvatar(UserAvatars::url(1)!==$second,'Cache key must change even for same extension and second');
 $publish->invoke(null,2,$stage('another-user'),'png');
 checkAvatar(is_file($dir.'/u1.webp')&&is_file($dir.'/u2.png'),'Another user photo was affected');
 $count=0;
 foreach(['jpg','png','webp'] as $ext)if(is_file($dir.'/u1.'.$ext))$count++;
 checkAvatar($count===1,'Old photo extension remained on disk');
 echo "Avatar upload replacement and revision cache: OK\n";
}finally{
 foreach(new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $file)if($file->isFile())@unlink($file->getPathname());
 @rmdir($dir);@rmdir(ROOT.'/storage');@rmdir(ROOT);
}
