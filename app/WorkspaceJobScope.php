<?php
declare(strict_types=1);

/** Worker-originated scope; fail closed rather than guessing the default tenant. */
final class WorkspaceJobScope {
 private static int $id=0;
 public static function currentId(): int {return self::$id;}
 public static function forWorkspace(int $id,callable $work): mixed {
  if($id<1)throw new InvalidArgumentException('Invalid worker workspace.');
  $w=DB::one('SELECT id,status FROM workspaces WHERE id=?',[$id]);
  if(!$w||!in_array((string)$w['status'],[WorkspaceFoundation::STATUS,'active'],true))
   throw new RuntimeException('Worker workspace is unavailable.');
  if(self::$id!==0)throw new LogicException('Nested workspace worker contexts not supported.');
  self::$id=$id;
  try{return $work();}finally{self::$id=0;}
 }
}
