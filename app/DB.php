<?php
final class DB {
 private static ?PDO $pdo=null;
 public static function conn(): PDO {if(!self::$pdo){$c=config('db');self::$pdo=new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);self::$pdo->exec("SET time_zone = '+00:00'");}return self::$pdo;}
 public static function run(string $sql,array $args=[]): PDOStatement {WorkspaceTenantMode::assertSql($sql);$q=self::conn()->prepare($sql);$q->execute($args);return $q;}
 public static function all(string $sql,array $args=[]): array {return self::run($sql,$args)->fetchAll();}
 public static function one(string $sql,array $args=[]): ?array {$v=self::run($sql,$args)->fetch();return $v===false?null:$v;}
 public static function insert(string $table,array $data): int {$data=WorkspaceTenantMode::insertData($table,$data);$cols=array_keys($data);self::run('INSERT INTO `'.$table.'` (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')',array_values($data));return (int)self::conn()->lastInsertId();}
 public static function update(string $table,int $id,array $data): void {self::run('UPDATE `'.$table.'` SET '.implode(',',array_map(fn($k)=>'`'.$k.'`=?',array_keys($data))).' WHERE id=?',[...array_values($data),$id]);}
 public static function tx(callable $fn): mixed {$db=self::conn();if($db->inTransaction())return $fn();$db->beginTransaction();try{$v=$fn();$db->commit();return $v;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
}
