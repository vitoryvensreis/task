import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL(p,import.meta.url),'utf8');
const js=read('../public/assets/app.js'),php=read('../app/Files.php'),demands=read('../app/Demands.php'),google=read('../app/Google.php');
const auth=read('../app/Auth.php'),roles=read('../app/WorkspaceRoles.php'),download=read('../public/download.php');
const board=js.slice(js.indexOf('async function renderKanban(){'),js.indexOf('function stageModal(',js.indexOf('async function renderKanban(){')));
assert(board.includes('const filtersBeforeRender=captureKanbanFilters()'),'Kanban must capture current search and selectors prior to replacing DOM');
assert(board.includes('restoreKanbanFilters(filtersBeforeRender)'),'Kanban must restore filters after DOM is rebuilt');
assert(board.indexOf('const filtersBeforeRender=captureKanbanFilters()')<board.indexOf("$('#content').innerHTML="),'Filters must be captured before DOM is overwritten');
assert(board.indexOf('restoreKanbanFilters(filtersBeforeRender)')>board.indexOf("$('#refresh-kanban').onclick=refresh"),'Filters must be restored after paint/event handlers are wired');
for(const statement of [
  "UPDATE comments SET version_id=NULL WHERE version_id=?",
  "DELETE FROM reviews WHERE file_id=?",
  "DELETE FROM files WHERE id=?"
]){
  assert(php.includes(statement+"'."+ "self::where(),self::args([$id])"),'Missing workspace-safe mutation: '+statement);
}
assert(php.includes('google_status')&&php.includes('permissões da conta original'),'Google permission errors must identify the original file account');
const details=js.slice(js.indexOf('function detailHtml(d){'),js.indexOf('function fileCardHtml(f){'));
assert(details.includes('id="copy-final-link"')&&details.includes('id="open-final-folder"'),'Copy/open actions must target the final-delivery folder');
assert(details.indexOf('id="open-final-folder"')<details.indexOf('const jobActions='),'Final folder buttons must belong to Entrega final');
assert(!details.includes('id="copy-job-folder"')&&!details.includes('id="open-job-folder"'),'Do not link to the parent job folder');
assert(demands.includes("Google::finalDeliveryShareLink($d,$fileRow)"),'Final folder link must be retrieved from the actual final file');
assert(google.includes("if((int)($s['account_id']??0)>0")&&google.includes("(!$old||(int)$old['id']!==(int)$s['account_id'])"),'Targeted OAuth must reject a different Google identity');
assert(js.includes("account_id:Number(x.details.drive_account_id)")&&php.includes('drive_account_email'),'Recovery must target the account originally associated with the file');
assert(php.includes('Google::refreshErrorCategory')&&php.includes('requires_google_reconnect'),'Revoked Drive token must produce a safe reconnect path');
assert(php.includes('drive_preserved')&&js.includes('keep_drive:1')&&js.includes('O arquivo continuará armazenado'),'TaskEsc-only removal must ask for explicit confirmation');
console.log('Kanban search, Entrega final folder actions and original Drive account reconnection contract OK');

assert(google.includes('CURLOPT_FOLLOWLOCATION=>true'),'Drive file previews/downloads must follow Google redirects');
assert(google.includes('if(!$sent)$sendHeaders()'),'Drive streaming must send only verified media response headers');
assert(download.includes("Auth::can('tasks.read')")&&download.includes('Auth::canEditDemand($d)'),'Task readers or owner/assignee must be able to preview files without upload role');
assert(auth.includes('function canEditDemand(array $d)')&&auth.includes("($d['created_by']??0)")&&auth.includes("($d['assigned_to']??0)"),'Creator and assignee must have task-specific edit policy');
assert(demands.includes("Auth::canEditDemand($old)")&&demands.includes("$d['can_edit']=Auth::canEditDemand($d)"),'Task save and API responses must use the same authorization policy');
assert(roles.includes('Auth::canEditDemand(Auth::demand($id))'),'Custom staff roles must permit task-specific owner/assignee edits');
assert(js.includes("canEdit=!preview&&!!d.can_edit"),'Edit action must use task-specific server permission');
assert(js.includes("media?.addEventListener('error'"),'Broken media should show actionable feedback instead of a blank black preview');
const api=read('../public/api.php');
assert(js.includes('data-disconnect-google')&&js.includes('data-reconnect-google'),'Each Google account must show disconnect or reconnect controls');
assert(js.includes("api('google.disconnect',{id},'POST')"),'Disconnect must call POST endpoint');
assert(js.includes('Os arquivos existentes no Google Drive não serão apagados.'),'Confirmation must explain that the Drive files remain intact');
assert(api.includes("'google.disconnect'=>($_SERVER['REQUEST_METHOD']??'GET')==='POST'?Google::disconnect($p):fail('Método não permitido.',405)"),'Google disconnect must reject GET requests');
assert(google.includes("public static function disconnect(array $p)")&&google.includes("'status'=>'disconnected','access_token'=>'','refresh_token'=>''"),'Disconnect must clear tokens without destroying persisted file references');
assert(!google.slice(google.indexOf('public static function disconnect('),google.indexOf('private static function',google.indexOf('public static function disconnect('))).includes('deleteDriveFile'),'Disconnect must not delete remote files');
console.log('Drive previews and creator/assignee task-edit contracts OK');
