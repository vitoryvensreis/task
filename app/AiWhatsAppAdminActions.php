<?php
declare(strict_types=1);

/** Admin-only, private-chat, allowlisted operations. No arbitrary API or SQL. */
final class AiWhatsAppAdminActions {
 public static function enabled(): bool {
  $cfg=config('ai_whatsapp',[]);return is_array($cfg)&&!empty($cfg['admin_control_enabled']);
 }
 /** Finance is deliberately independent from broader admin control:
  * private ADM, finance.manage, and one-time confirmation are mandatory.
  * Explicit config financial_write_enabled=false disables this feature. */
 public static function financialWriteEnabled(): bool {
  $cfg=config('ai_whatsapp',[]);
  return is_array($cfg)&&($cfg['financial_write_enabled']??true)!==false;
 }
 private const FINANCIAL_ACTIONS=['create_invoice','record_payment','create_expense',
  'mark_expense_paid','cancel_invoice','register_receipt'];
 private static function allowedWrite(string $name): bool {
  return in_array($name,self::FINANCIAL_ACTIONS,true)
   ?self::financialWriteEnabled()&&Auth::can('finance.manage')
   :self::enabled();
 }
 private static function tool(string $name,string $description,array $properties,array $required=[]): array {
  return ['type'=>'function','function'=>['name'=>$name,'description'=>$description,'parameters'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false]]];
 }
 public static function sensitive(string $name): bool {
  return in_array($name,['create_invoice','record_payment','create_expense','mark_expense_paid','cancel_invoice','register_receipt','create_project','agenda_create','agenda_update','agenda_cancel'],true);
 }
 public static function catalog(bool $private): array {
  if(!$private||(Auth::$user['role']??'')!=='admin')return [];
  $full=self::enabled();
  $finance=self::financialWriteEnabled()&&Auth::can('finance.manage');
  if(!$full&&!$finance)return [];
  $id=['type'=>'integer'];$date=['type'=>'string','description'=>'YYYY-MM-DD'];
  $money=['type'=>'integer','description'=>'Valor em centavos: R$ 150,00 = 15000.'];
  $all=[
   self::tool('list_clients','Listar clientes ativos do TaskEsc',['query'=>['type'=>'string']]),
   self::tool('list_projects','Listar projetos por cliente',['client_id'=>$id]),
   self::tool('list_team','Listar usuários, função e atividade; sem segredos',[]),
   self::tool('list_events','Listar próximos eventos da agenda, sem atualizar Google',[]),
   self::tool('create_invoice','Preparar cobrança avulsa. Só será executada após confirmação separada do administrador.',['client_id'=>$id,'description'=>['type'=>'string'],'amount_cents'=>$money,'due_on'=>$date],['client_id','description','amount_cents','due_on']),
   self::tool('record_payment','Preparar registro CONTÁBIL de recebimento já ocorrido; não faz transferência. Confirmação separada.',['invoice_id'=>$id,'amount_cents'=>$money,'paid_on'=>$date,'method'=>['type'=>'string']],['invoice_id','amount_cents','paid_on','method']),
   self::tool('create_expense','Registrar DESPESA/custo novo no financeiro; preparação e confirmação em mensagens diferentes. Na ausência de categoria, usar Outros; se não houver data, usar hoje. Despesa nasce PENDENTE, sem presumir pagamento.',['description'=>['type'=>'string'],'category'=>['type'=>'string','description'=>'Categoria existente, opcional: Outros quando não informada'],'vendor'=>['type'=>'string'],'amount_cents'=>$money,'spent_on'=>$date],['description','amount_cents']),
   self::tool('register_receipt','Registrar uma ENTRADA/RECEITA JÁ RECEBIDA, vinculada a CLIENTE existente: cria cobrança e baixa contábil no mesmo ato, mediante confirmação separada. Exige cliente, descrição, valor, data e meio de pagamento; não faz transferência.',['client_id'=>$id,'description'=>['type'=>'string'],'amount_cents'=>$money,'paid_on'=>$date,'method'=>['type'=>'string']],['client_id','description','amount_cents','paid_on','method']),
   self::tool('mark_expense_paid','Preparar marcação contábil de despesa como PAGA, sem transferir dinheiro, com confirmação separada.',['expense_id'=>$id,'paid_on'=>$date],['expense_id','paid_on']),
   self::tool('cancel_invoice','Preparar cancelamento de cobrança sem pagamentos, com confirmação.',['invoice_id'=>$id],['invoice_id']),
   self::tool('create_project','Preparar criação de projeto no cliente, mediante confirmação.',['client_id'=>$id,'name'=>['type'=>'string'],'description'=>['type'=>'string']],['client_id','name']),
  ];
  if(!MasterSupport::isSupport()&&!(WorkspaceContext::legacyCiFixture()&&defined('TASKESC_LEGACY_AI_CALENDAR_FIXTURE')))$all=array_values(array_filter($all,static fn($t)=>($t['function']['name']??'')!=='list_events'));
  return $full?$all:array_values(array_filter($all,static fn($t)=>in_array($t['function']['name']??'',self::FINANCIAL_ACTIONS,true)));
 }
 private static function keys(array $data,array $allowed): void {
  foreach(array_keys($data) as $key)if(!in_array($key,$allowed,true))fail('Campo administrativo não permitido.',422);
 }
 private static function brl(int $cents): string {return 'R$ '.number_format($cents/100,2,',','.');}
 public static function prepare(string $name,array $p): array {
  Auth::admin();
  if(AiWhatsAppAgendaTools::sensitive($name))return AiWhatsAppAgendaTools::prepare($name,$p);
  if(!self::allowedWrite($name))fail('Controle de escrita financeira ou administrativa não autorizado.',403);
  if(!self::sensitive($name))fail('Ação administrativa não autorizada.',403);
  switch($name){
   case 'create_invoice':
    self::keys($p,['client_id','description','amount_cents','due_on']);
    $id=integer($p['client_id']??null,1);$c=Auth::client($id);
    if(!(int)$c['active'])fail('Cliente inativo.',422);
    $desc=required($p,'description',255);$amount=integer($p['amount_cents']??null,1,100000000);$due=datev($p['due_on']??null,false);
    return [['client_id'=>$id,'description'=>$desc,'amount_cents'=>$amount,'due_on'=>$due],
     'Criar cobrança para '.$c['name'].': '.self::brl($amount).', vencimento '.$due.', descrição '.$desc];
   case 'record_payment':
    self::keys($p,['invoice_id','amount_cents','paid_on','method']);
    $id=integer($p['invoice_id']??null,1);$invoice=DB::one("SELECT * FROM invoices WHERE id=? AND status<>'cancelled' AND deleted_at IS NULL",[$id]);
    if(!$invoice)fail('Cobrança não encontrada.',404);
    $amount=integer($p['amount_cents']??null,1,100000000);$paid=datev($p['paid_on']??null,false);$method=required($p,'method',50);
    $settled=(int)(DB::one('SELECT COALESCE(SUM(amount_cents+COALESCE(discount_cents,0)),0) n FROM payments WHERE invoice_id=?',[$id])['n']??0);
    $remaining=max(0,(int)$invoice['amount_cents']-$settled);
    if($amount>$remaining)fail('Recebimento acima do saldo.',422);
    return [['invoice_id'=>$id,'amount_cents'=>$amount,'paid_on'=>$paid,'method'=>$method,'expected_balance_cents'=>$remaining],
     'Registrar recebimento já ocorrido: '.self::brl($amount).' na fatura #'.$id.', data '.$paid.', meio '.$method.'. Saldo anterior '.self::brl($remaining)];
   case 'create_expense':
    self::keys($p,['description','category','vendor','amount_cents','spent_on']);
    $desc=required($p,'description',190);$cat=trim((string)($p['category']??'Outros'));
    if($cat==='')$cat='Outros';
    if(mb_strlen($cat)>80)fail('Categoria de despesa muito extensa.',422);
    if(!DB::one('SELECT id FROM expense_categories WHERE name=? AND active=1',[$cat]))fail('Categoria de despesa inválida.',422);
    $amount=integer($p['amount_cents']??null,1,100000000);$date=datev($p['spent_on']??(new DateTimeImmutable('today',new DateTimeZone((string)config('timezone','America/Fortaleza'))))->format('Y-m-d'),false);
    $vendor=mb_substr(trim((string)($p['vendor']??'')),0,190);
    return [['description'=>$desc,'category'=>$cat,'vendor'=>$vendor,'amount_cents'=>$amount,'spent_on'=>$date,'payment_status'=>'pending'],
     'Criar despesa PENDENTE: '.$desc.', '.self::brl($amount).', '.$cat.', '.$date];
   case 'register_receipt':
    self::keys($p,['client_id','description','amount_cents','paid_on','method']);
    $clientId=integer($p['client_id']??null,1);$client=Auth::client($clientId);
    if(!(int)$client['active'])fail('Cliente inativo.',422);
    $desc=required($p,'description',190);
    $amount=integer($p['amount_cents']??null,1,100000000);
    $paid=datev($p['paid_on']??null,false);
    $method=required($p,'method',50);
    return [['client_id'=>$clientId,'description'=>$desc,'amount_cents'=>$amount,
      'paid_on'=>$paid,'method'=>$method],
     'Registrar ENTRADA JÁ RECEBIDA de '.$client['name'].': '.self::brl($amount).
     ', data '.$paid.', meio '.$method.', descrição '.$desc.
     '. Será criada cobrança quitada para manter a conciliação do TaskEsc.'];
   case 'mark_expense_paid':
    self::keys($p,['expense_id','paid_on']);$id=integer($p['expense_id']??null,1);
    $expense=DB::one('SELECT id,description,amount_cents,payment_status,deleted_at FROM expenses WHERE id=?',[$id]);
    if(!$expense||$expense['deleted_at'])fail('Despesa inexistente.',404);
    if($expense['payment_status']==='paid')fail('Despesa já está paga.',409);
    $date=datev($p['paid_on']??null,false);
    return [['expense_id'=>$id,'paid_on'=>$date,'expected_amount_cents'=>(int)$expense['amount_cents']],
     'Marcar despesa #'.$id.' como PAGA em '.$date.': '.$expense['description'].' de '.self::brl((int)$expense['amount_cents'])];
   case 'cancel_invoice':
    self::keys($p,['invoice_id']);$id=integer($p['invoice_id']??null,1);
    $i=DB::one('SELECT * FROM invoices WHERE id=? AND deleted_at IS NULL AND status<>\'cancelled\'',[$id]);
    if(!$i)fail('Cobrança não encontrada.',404);
    $settled=(int)(DB::one('SELECT COALESCE(SUM(amount_cents+COALESCE(discount_cents,0)),0) n FROM payments WHERE invoice_id=?',[$id])['n']??0);
    if($settled!==0)fail('Cobrança possui pagamentos; não pode cancelar pelo WhatsApp.',409);
    return [['invoice_id'=>$id,'expected_amount_cents'=>(int)$i['amount_cents']],
     'CANCELAR cobrança #'.$id.' ('.$i['description'].') de '.self::brl((int)$i['amount_cents'])];
   case 'create_project':
    self::keys($p,['client_id','name','description']);$id=integer($p['client_id']??null,1);$c=Auth::client($id);
    if(!(int)$c['active'])fail('Cliente inativo.',422);
    $name=required($p,'name',190);$desc=mb_substr((string)($p['description']??''),0,2000);
    return [['client_id'=>$id,'name'=>$name,'description'=>$desc],'Criar projeto '.$name.' no cliente '.$c['name']];
  }
  fail('Ação não autorizada.',403);
 }
 public static function run(string $name,array $p): array {
  Auth::admin();
  if(AiWhatsAppAgendaTools::sensitive($name))return AiWhatsAppAgendaTools::runConfirmed($name,$p);
  if(self::sensitive($name)&&!self::allowedWrite($name))fail('Controle de escrita financeira ou administrativa não autorizado.',403);
  switch($name){
   case 'list_clients':
    self::keys($p,['query']);$q=mb_substr(trim((string)($p['query']??'')),0,70);
    return ['kind'=>'admin_list','heading'=>'Clientes','items'=>DB::all('SELECT id,name FROM clients WHERE active=1 AND name LIKE ? ORDER BY name LIMIT 10',['%'.$q.'%'])];
   case 'list_projects':
    self::keys($p,['client_id']);$args=[];$where='';
    if(isset($p['client_id'])){$where=' WHERE p.client_id=?';$args[]=integer($p['client_id'],1);}
    return ['kind'=>'admin_list','heading'=>'Projetos','items'=>DB::all('SELECT p.id,CONCAT(p.name,\' — \',c.name) name FROM projects p JOIN clients c ON c.id=p.client_id'.$where.' ORDER BY p.id DESC LIMIT 10',$args)];
   case 'list_team':
    self::keys($p,[]);return ['kind'=>'admin_list','heading'=>'Equipe','items'=>DB::all("SELECT id,CONCAT(name,' (',role,IF(active=1,'',', inativo'),')') name FROM users ORDER BY id DESC LIMIT 10")];
   case 'list_events':
    MasterSupport::requireCalendar();
    self::keys($p,[]);return ['kind'=>'admin_list','heading'=>'Agenda','items'=>DB::all("SELECT id,CONCAT(title,' (',starts_at,')') name FROM events WHERE status<>'cancelled' AND starts_at>=UTC_TIMESTAMP() ORDER BY starts_at LIMIT 10")];
  }
  if(!self::sensitive($name))fail('Operação não autorizada.',403);
  if(AiWhatsAppAgendaTools::sensitive($name))return AiWhatsAppAgendaTools::runConfirmed($name,$p);
  switch($name){
   case 'create_invoice':return ['kind'=>'admin_done','action'=>'Cobrança criada','id'=>(int)Finance::invoiceCreate($p)['id']];
   case 'record_payment':
    $i=DB::one('SELECT * FROM invoices WHERE id=? FOR UPDATE',[integer($p['invoice_id'],1)]);
    if(!$i||$i['status']==='cancelled'||$i['deleted_at'])fail('Cobrança indisponível.',409);
    $settled=(int)(DB::one('SELECT COALESCE(SUM(amount_cents+COALESCE(discount_cents,0)),0) n FROM payments WHERE invoice_id=?',[$i['id']])['n']??0);
    if((int)$i['amount_cents']-$settled!==(int)$p['expected_balance_cents'])fail('Saldo alterado. Solicite novamente.',409);
    unset($p['expected_balance_cents']);return ['kind'=>'admin_done','action'=>'Recebimento registrado','id'=>(int)Finance::payment($p)['id']];
   case 'create_expense':return ['kind'=>'admin_done','action'=>'Despesa criada (pendente)','id'=>(int)Finance::expenseSave($p)['id']];
   case 'register_receipt':
    // This is called inside the SAME DB transaction as approval consumption.
    // The pair either commits together or is fully rolled back.
    $invoice=Finance::invoiceCreate([
     'client_id'=>$p['client_id'],'description'=>$p['description'],
     'amount_cents'=>$p['amount_cents'],'due_on'=>$p['paid_on']]);
    $payment=Finance::payment([
     'invoice_id'=>$invoice['id'],'amount_cents'=>$p['amount_cents'],
     'paid_on'=>$p['paid_on'],'method'=>$p['method']]);
    return ['kind'=>'admin_done','action'=>'Entrada recebida registrada',
     'id'=>(int)$payment['id']];
   case 'mark_expense_paid':
    $id=integer($p['expense_id'],1);
    $e=DB::one('SELECT amount_cents,payment_status,deleted_at FROM expenses WHERE id=? FOR UPDATE',[$id]);
    if(!$e||$e['deleted_at']||$e['payment_status']==='paid'||(int)$e['amount_cents']!==(int)$p['expected_amount_cents'])fail('Despesa alterada ou já paga.',409);
    Finance::expensePayment(['id'=>$id,'payment_status'=>'paid','paid_on'=>$p['paid_on']]);
    return ['kind'=>'admin_done','action'=>'Despesa marcada como paga','id'=>$id];
   case 'cancel_invoice':
    $i=DB::one('SELECT amount_cents FROM invoices WHERE id=? FOR UPDATE',[integer($p['invoice_id'],1)]);
    if(!$i||(int)$i['amount_cents']!==(int)$p['expected_amount_cents'])fail('Cobrança alterada.',409);
    Finance::cancel(['id'=>$p['invoice_id']]);return ['kind'=>'admin_done','action'=>'Cobrança cancelada','id'=>(int)$p['invoice_id']];
   case 'create_project':return ['kind'=>'admin_done','action'=>'Projeto criado','id'=>(int)Catalog::projectSave($p)['id']];
  }
  fail('Ação indisponível.',403);
 }
}