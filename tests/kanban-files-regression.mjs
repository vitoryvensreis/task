import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL(p,import.meta.url),'utf8');
const js=read('../public/assets/app.js'),php=read('../app/Files.php'),demands=read('../app/Demands.php');
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
assert(php.includes("google_status")&&php.includes("permissões de edição"),'Explicit Google permission error should be returned to user');
assert(demands.includes("$d['drive_folder_url']")&&js.includes('id="copy-job-folder"')&&js.includes('id="open-job-folder"'),'Task Drive folder access and copy actions must work independently of final delivery');
assert(php.includes('Google::refreshErrorCategory')&&php.includes('requires_google_reconnect'),'Revoked Drive token must produce a safe reconnect path');
assert(php.includes('drive_preserved')&&js.includes('keep_drive:1')&&js.includes('O arquivo continuará armazenado'),'TaskEsc-only removal must ask for explicit confirmation');
console.log('Kanban search, folder shortcuts and safe Drive deletion recovery contract OK');
