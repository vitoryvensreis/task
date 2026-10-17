<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$origin=$_SERVER['HTTP_ORIGIN']??'';$allowed=config('trusted_origins',['https://chatgpt.com','https://chat.openai.com']);if(in_array($origin,$allowed,true)){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');}header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id');header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
if(($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS'){http_response_code(204);exit;}
function mcpResult(mixed $id,mixed $result): never{header('Content-Type: application/json; charset=utf-8');echo json(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);exit;}
function mcpError(mixed $id,int $code,string $message,array $data=[]): never{header('Content-Type: application/json; charset=utf-8');echo json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message,'data'=>$data]]);exit;}
function mcpTools(): array{return[
 ['name'=>'search_demands','description'=>'Busca demandas do TaskEsc por texto, cliente, etapa e prazo.','inputSchema'=>['type'=>'object','properties'=>['q'=>['type'=>'string'],'overdue'=>['type'=>'boolean']]]],
 ['name'=>'get_demand','description'=>'Obtém uma demanda, seu briefing, arquivos, comentários e histórico.','inputSchema'=>['type'=>'object','required'=>['id'],'properties'=>['id'=>['type'=>'integer']]]],
 ['name'=>'create_demand','description'=>'Cadastra uma task no TaskEsc. Toda task precisa de um responsável ativo; usuários da Equipe precisam estar vinculados ao cliente. A task nova entra sempre na primeira coluna do Kanban. Para clientes mensais use billing_mode included ou one_off; clientes avulsos usam one_off.','inputSchema'=>['type'=>'object','required'=>['client_id','category_id','title','brief','due_at','assigned_to'],'properties'=>['client_id'=>['type'=>'integer'],'category_id'=>['type'=>'integer'],'title'=>['type'=>'string'],'brief'=>['type'=>'string'],'due_at'=>['type'=>'string'],'priority'=>['type'=>'string'],'assigned_to'=>['type'=>'integer'],'billing_mode'=>['type'=>'string'],'amount_cents'=>['type'=>'integer']]]],
 ['name'=>'move_demand','description'=>'Move uma demanda no Kanban.','inputSchema'=>['type'=>'object','required'=>['id','stage_id','version'],'properties'=>['id'=>['type'=>'integer'],'stage_id'=>['type'=>'integer'],'version'=>['type'=>'integer']]]],
 ['name'=>'get_dashboard','description'=>'Consulta indicadores de produção, agenda e financeiro.','inputSchema'=>['type'=>'object','properties'=>['from'=>['type'=>'string'],'to'=>['type'=>'string']]]],
 ['name'=>'list_invoices','description'=>'Lista cobranças, pagamentos e valores a receber.','inputSchema'=>['type'=>'object','properties'=>['from'=>['type'=>'string'],'to'=>['type'=>'string'],'client_id'=>['type'=>'integer']]]],
 ['name'=>'list_calendars','description'=>'Lista agendas disponíveis e permissões.','inputSchema'=>['type'=>'object','properties'=>[]]],
 ['name'=>'list_events','description'=>'Lista compromissos nas agendas selecionadas.','inputSchema'=>['type'=>'object','required'=>['from','to'],'properties'=>['from'=>['type'=>'string'],'to'=>['type'=>'string']]]],
 ['name'=>'create_event','description'=>'Cria um compromisso em uma agenda autorizada.','inputSchema'=>['type'=>'object','required'=>['calendar_id','title','starts_at','ends_at'],'properties'=>['calendar_id'=>['type'=>'integer'],'demand_id'=>['type'=>'integer'],'title'=>['type'=>'string'],'starts_at'=>['type'=>'string'],'ends_at'=>['type'=>'string'],'location'=>['type'=>'string'],'description'=>['type'=>'string']]]],
 ['name'=>'register_payment','description'=>'Registra um pagamento já recebido em uma cobrança.','inputSchema'=>['type'=>'object','required'=>['invoice_id','amount_cents','paid_on','method'],'properties'=>['invoice_id'=>['type'=>'integer'],'amount_cents'=>['type'=>'integer'],'paid_on'=>['type'=>'string'],'method'=>['type'=>'string'],'note'=>['type'=>'string']]]],
 ];}
try{
 if(!is_file(ROOT.'/storage/installed.lock'))mcpError(null,-32000,'Sistema ainda não instalado.');
 Auth::load();SaasLegacyBoundary::enforce();$raw=file_get_contents('php://input');$req=json_decode($raw?:'{}',true,512,JSON_THROW_ON_ERROR);if(!is_array($req))mcpError(null,-32600,'JSON-RPC inválido.');$id=$req['id']??null;$method=$req['method']??'';$params=$req['params']??[];
 if($method==='initialize')mcpResult($id,['protocolVersion'=>'2025-03-26','capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'taskesc','version'=>'1.0.0'],'instructions'=>'Você opera o TaskEsc do produtor audiovisual. Antes de ações, confirme o cliente, demanda, agenda e versão quando necessário. Informe códigos, valores, datas e a situação efetiva. Nunca invente dados.']);
 if($method==='notifications/initialized'){http_response_code(202);exit;}
 if($method==='ping')mcpResult($id,[]);
 if($method==='tools/list')mcpResult($id,['tools'=>mcpTools()]);
 if($method!=='tools/call')mcpError($id,-32601,'Método MCP não suportado.');
 $name=(string)($params['name']??'');$args=is_array($params['arguments']??null)?$params['arguments']:[];$write=['create_demand','move_demand','create_event','register_payment'];if(in_array($name,$write,true)&&!str_contains(' '.Auth::$scopes.' ',' tools.write '))mcpError($id,-32001,'O token não possui tools.write.');
 $r=match($name){'search_demands'=>Demands::list($args),'get_demand'=>Demands::get($args),'create_demand'=>Demands::save($args),'move_demand'=>Demands::stage($args),'get_dashboard'=>Reports::dashboard($args),'list_invoices'=>Finance::invoices($args),'list_calendars'=>Agenda::calendars(),'list_events'=>Agenda::list($args),'create_event'=>Agenda::save($args),'register_payment'=>Finance::payment($args),default=>fail('Ferramenta MCP não encontrada.',-32601)};
 mcpResult($id,['content'=>[['type'=>'text','text'=>json($r)]],'structuredContent'=>$r]);
}catch(AppError $e){http_response_code($e->status);mcpError($id??null,-32000,$e->getMessage(),$e->details);}catch(Throwable $e){error_log('MCP: '.(string)$e);http_response_code(500);mcpError($id??null,-32603,'Erro interno ao executar a ferramenta.');}
