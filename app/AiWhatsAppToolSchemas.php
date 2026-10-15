<?php
declare(strict_types=1);

/**
 * Tool definitions are authored as PHP arrays. JSON schema's "properties"
 * MUST be a JSON object even when empty: PHP json_encode([]) yields [].
 *
 * Normalize a REQUEST COPY only; keep the original catalog immutable so
 * authorization continues to use server-side tool names and permissions.
 */
final class AiWhatsAppToolSchemas
{
 public static function forGroq(array $tools): array {
  $copy=[];
  foreach($tools as $tool){
   $fn=$tool['function']??null;
   if(($tool['type']??null)!=='function'||!is_array($fn)||!is_string($fn['name']??null))
    throw new RuntimeException('ai_tool_catalog_invalid');
   $params=$fn['parameters']??['type'=>'object','properties'=>[]];
   if(!is_array($params))throw new RuntimeException('ai_tool_catalog_invalid');
   $fn['parameters']=self::schema($params);
   $tool['function']=$fn;
   $copy[]=$tool;
  }
  return $copy;
 }
 private static function schema(array $schema): array {
  if(strtolower((string)($schema['type']??''))==='object'){
   $properties=$schema['properties']??[];
   if(!is_array($properties))throw new RuntimeException('ai_tool_catalog_invalid');
   if($properties===[]){
    $schema['properties']=(object)[];
   }else{
    foreach($properties as $key=>$spec){
     if(!is_array($spec))throw new RuntimeException('ai_tool_catalog_invalid');
     $properties[$key]=self::schema($spec);
    }
    $schema['properties']=$properties;
   }
  }
  if(isset($schema['items'])&&is_array($schema['items']))$schema['items']=self::schema($schema['items']);
  return $schema;
 }
}
