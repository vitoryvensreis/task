<?php
final class Reports {
 public static function dashboard(array $p=[]): array {
  Auth::load();
  $tz=new DateTimeZone(config('timezone','America/Fortaleza'));
  $from=datev($p['from']??(new DateTimeImmutable('first day of this month',$tz))->format('Y-m-d'),false);
  $to=datev($p['to']??(new DateTimeImmutable('last day of this month',$tz))->format('Y-m-d'),false);
  if($from>$to)fail('Período inválido.');
  [$dw,$da]=Auth::demandFilter('d');$params=[...$da,$from.' 00:00:00',$to.' 23:59:59'];$where=$dw.' AND d.created_at BETWEEN ? AND ?';
  $counts=DB::all('SELECT s.id,s.name,s.color,COUNT(d.id) AS total FROM stages s LEFT JOIN demands d ON d.stage_id=s.id AND '.$where.' GROUP BY s.id ORDER BY s.position',$params);
  $summary=DB::one('SELECT COUNT(*) AS received,SUM(CASE WHEN s.kind IN (\'production\',\'approval\') THEN 1 ELSE 0 END) AS in_progress,SUM(CASE WHEN s.kind=\'completed\' THEN 1 ELSE 0 END) AS completed,SUM(CASE WHEN d.due_at<? AND s.kind NOT IN (\'completed\',\'cancelled\',\'archived\') THEN 1 ELSE 0 END) AS overdue FROM demands d JOIN stages s ON s.id=d.stage_id WHERE '.$dw.' AND d.created_at BETWEEN ? AND ?',[$to.' 23:59:59',...$da,$from.' 00:00:00',$to.' 23:59:59']);
  if(Auth::$user['role']!=='admin'){
   $financial=['approved_cents'=>0,'billed_cents'=>0,'received_cents'=>0,'open_cents'=>0,'overdue_cents'=>0];
  }else{
   Finance::syncCommercialInvoices($from,$to);[$iw,$ia]=Auth::clientFilter('i.client_id',true);$today=(new DateTimeImmutable('now',$tz))->format('Y-m-d');
   $financial=DB::one("SELECT
    COALESCE(SUM(CASE WHEN i.status<>'cancelled' THEN i.amount_cents ELSE 0 END),0) AS billed_cents,
    COALESCE(SUM(CASE WHEN i.status<>'cancelled' THEN (SELECT COALESCE(SUM(p.amount_cents),0) FROM payments p WHERE p.invoice_id=i.id) ELSE 0 END),0) AS received_cents,
    COALESCE(SUM(CASE WHEN i.status<>'cancelled' THEN GREATEST(0,i.amount_cents-(SELECT COALESCE(SUM(p.amount_cents),0) FROM payments p WHERE p.invoice_id=i.id)) ELSE 0 END),0) AS open_cents,
    COALESCE(SUM(CASE WHEN i.status<>'cancelled' AND i.due_on<? THEN GREATEST(0,i.amount_cents-(SELECT COALESCE(SUM(p.amount_cents),0) FROM payments p WHERE p.invoice_id=i.id)) ELSE 0 END),0) AS overdue_cents
    FROM invoices i WHERE $iw AND i.due_on BETWEEN ? AND ?",[$today,...$ia,$from,$to]);
   $financial['approved_cents']=(int)($financial['billed_cents']??0);
  }
  $upcomingSql='SELECT e.id,e.title,e.starts_at,e.ends_at,e.location,c.name AS calendar_name,c.color,d.id AS demand_id,d.title AS demand_title,cl.name AS client_name FROM events e JOIN calendars c ON c.id=e.calendar_id LEFT JOIN demands d ON d.id=e.demand_id AND d.deleted_at IS NULL LEFT JOIN clients cl ON cl.id=d.client_id WHERE c.selected=1 AND e.status<>\'cancelled\' AND e.starts_at BETWEEN ? AND ?';$upcomingArgs=[$from.' 00:00:00',$to.' 23:59:59'];if(Auth::$user['role']==='client'){if(Auth::isPreview()){$upcomingSql.=' AND e.private=0 AND d.client_id=?';$upcomingArgs[]=Auth::$previewClientId;}else{$upcomingSql.=' AND e.private=0 AND d.client_id IN (SELECT client_id FROM user_clients WHERE user_id=?)';$upcomingArgs[]=Auth::$user['id'];}}$upcoming=DB::all($upcomingSql.' ORDER BY e.starts_at LIMIT 20',$upcomingArgs);
  $pending=DB::all('SELECT d.id,d.title,d.due_at,c.name AS client_name,s.name AS stage_name FROM demands d JOIN clients c ON c.id=d.client_id JOIN stages s ON s.id=d.stage_id WHERE '.$dw." AND s.kind IN ('inbox','analysis','waiting','approval') ORDER BY COALESCE(d.due_at,'9999-12-31') LIMIT 20",$da);
  return ['period'=>['from'=>$from,'to'=>$to,'timezone'=>config('timezone'),'updated_at'=>now()],'summary'=>$summary+['received'=>(int)($summary['received']??0),'in_progress'=>(int)($summary['in_progress']??0),'completed'=>(int)($summary['completed']??0),'overdue'=>(int)($summary['overdue']??0)],'stages'=>$counts,'financial'=>$financial,'upcoming'=>$upcoming,'pending'=>$pending];
 }

 public static function report(array $p=[]): array {
  Auth::load();$from=datev($p['from']??gmdate('Y-m-01'),false);$to=datev($p['to']??gmdate('Y-m-t'),false);$type=enumv($p['type']??'production',['production','financial','clients','contracts'],'relatório');if($type==='clients'&&Auth::$user['role']!=='admin')fail('Relatório de clientes restrito ao administrador.',403);$d=self::dashboard(['from'=>$from,'to'=>$to]);
  if($type==='production'){
   $filter=Auth::demandFilter('d');$d['rows']=DB::all('SELECT d.id,d.title,c.name AS client_name,ca.name AS category_name,s.name AS stage_name,d.priority,d.due_at,d.created_at,d.completed_at,COALESCE((SELECT SUM(minutes) FROM time_entries t WHERE t.demand_id=d.id),0) AS worked_minutes,COALESCE((SELECT COUNT(*) FROM reviews r JOIN files f ON f.id=r.file_id WHERE f.demand_id=d.id AND r.decision=\'changes\'),0) AS changes FROM demands d JOIN clients c ON c.id=d.client_id JOIN categories ca ON ca.id=d.category_id JOIN stages s ON s.id=d.stage_id WHERE '.$filter[0].' AND d.created_at BETWEEN ? AND ? ORDER BY d.created_at DESC',[...$filter[1],$from.' 00:00:00',$to.' 23:59:59']);
  }elseif($type==='financial'){
   $d['rows']=Finance::invoices(['from'=>$from,'to'=>$to]);
  }elseif($type==='clients'){
   Finance::syncCommercialInvoices($from,$to);$d['rows']=DB::all("SELECT c.id,c.name,c.commercial_type,c.monthly_amount_cents,c.monthly_payment_day,
    (SELECT COUNT(*) FROM demands d WHERE d.client_id=c.id AND d.deleted_at IS NULL AND d.created_at BETWEEN ? AND ?) AS demands_count,
    (SELECT COUNT(*) FROM demands d JOIN stages s ON s.id=d.stage_id WHERE d.client_id=c.id AND d.deleted_at IS NULL AND s.kind='completed' AND d.created_at BETWEEN ? AND ?) AS completed,
    (SELECT COALESCE(SUM(i.amount_cents),0) FROM invoices i WHERE i.client_id=c.id AND i.status<>'cancelled' AND i.due_on BETWEEN ? AND ?) AS billed_cents,
    (SELECT COALESCE(SUM(p.amount_cents),0) FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.client_id=c.id AND i.status<>'cancelled' AND i.due_on BETWEEN ? AND ?) AS received_cents
    FROM clients c ORDER BY c.name",[$from.' 00:00:00',$to.' 23:59:59',$from.' 00:00:00',$to.' 23:59:59',$from,$to,$from,$to]);
  }else{
   $d['rows']=DB::all("SELECT id,name,brand,monthly_amount_cents,monthly_payment_day,active FROM clients WHERE commercial_type='monthly' ORDER BY active DESC,name");
  }
  return $d;
 }
 public static function csv(array $p): string {$r=self::report($p);$rows=$r['rows']??[];$out=fopen('php://temp','w+');if(!$rows){fputcsv($out,['Sem registros'],';');}else{fputcsv($out,array_keys($rows[0]),';');foreach($rows as $row)fputcsv($out,array_map(function($v){if(is_array($v))return json($v);return $v;},$row),';');}rewind($out);return stream_get_contents($out);}
}
