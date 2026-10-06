<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$violations=[];
$patterns=[
 '/\bDROP\s+TABLE\b/i'=>'DROP TABLE',
 '/\bDROP\s+COLUMN\b/i'=>'DROP COLUMN',
 '/\bTRUNCATE\s+TABLE\b/i'=>'TRUNCATE TABLE',
 '/\bRENAME\s+TABLE\b/i'=>'RENAME TABLE',
 '/\bALTER\s+TABLE\b[^;\n]*(?:\bDROP\b|\bCHANGE\s+COLUMN\b|\bMODIFY\s+COLUMN\b)/i'=>'ALTER destrutivo',
 '/\bDELETE\s+FROM\b/i'=>'DELETE FROM',
];

foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){
 $name=basename($file);
 if(!preg_match('/^(\d+)_/',$name,$m)||(int)$m[1]<20)continue;
 $src=(string)file_get_contents($file);
 foreach($patterns as $rx=>$label)if(preg_match($rx,$src))$violations[]=$name.': '.$label;
}

$migrations=(string)file_get_contents($root.'/app/Migrations.php');
$marker='// SAAS_ADDITIVE_MIGRATIONS_START';
$start=strpos($migrations,$marker);
$end=$start===false?false:strpos($migrations,'private static function hasColumn',$start);
if($start===false||$end===false)$violations[]='app/Migrations.php: bloco SaaS aditivo não delimitado';
else{
 $src=substr($migrations,$start,$end-$start);
 foreach($patterns as $rx=>$label)if(preg_match($rx,$src))$violations[]='app/Migrations.php (SaaS): '.$label;
}

if($violations){
 fwrite(STDERR,"ERRO: migration destrutiva detectada na fase SaaS:\n - ".implode("\n - ",$violations)."\n");
 exit(1);
}
echo "OK: migrations SaaS >=20 são somente aditivas.\n";
