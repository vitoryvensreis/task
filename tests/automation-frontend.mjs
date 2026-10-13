import fs from 'node:fs';

const app=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const css=fs.readFileSync(new URL('../public/assets/app.css',import.meta.url),'utf8');
const icons=fs.readFileSync(new URL('../public/assets/taskesc-icons.js',import.meta.url),'utf8');
const api=fs.readFileSync(new URL('../public/api.php',import.meta.url),'utf8');
const automation=fs.readFileSync(new URL('../app/Automation.php',import.meta.url),'utf8');
const demands=fs.readFileSync(new URL('../app/Demands.php',import.meta.url),'utf8');

function check(condition,message){if(!condition)throw new Error(message)}

check(app.includes("automations:'automacoes'"),'Automations must have a first-class route.');
check(app.includes("['automations','workflow','Automações'"),'Admin navigation must expose Automations.');
check(app.includes("automations:['AUTOMAÇÃO','Automações']"),'Automation page heading must be registered.');
check(app.includes('automations:renderAutomations'),'Router must render the automations page.');
check(app.includes("api('automation.catalog')")&&app.includes("api('automation.list')")&&app.includes("api('automation.get'"),'Automation frontend must use authenticated catalog/list/get endpoints.');
check(app.includes("api('automation.save'")&&app.includes("api('automation.toggle'"),'Automation frontend must save and activate/pause flows.');
check(app.includes('function automationRenderNode(')&&app.includes('automation-branches'),'Visual editor must recursively render the automation tree and condition branches.');
check(app.includes('data-auto-add="condition"')&&app.includes('data-auto-add="action.whatsapp"'),'Tree connectors must add condition and WhatsApp blocks.');
check(app.includes("branch:'yes'")&&app.includes("branch:'no'"),'Condition nodes must create explicit SIM/NÃO branches.');
check(app.includes('function automationRemoveNode(')&&app.includes('automationPruneBranch'),'Editor must safely remove graph nodes and prune discarded branches.');
check(app.includes('data-auto-config="trigger_event"')&&app.includes('data-auto-config="operator"')&&app.includes('data-auto-config="message"'),'Inspector must configure trigger, condition and WhatsApp action.');
check(app.includes('automationTriggerOptionsMarkup')&&app.includes("group||'Outros'"),'Trigger selector must group Task, Client and Finance triggers.');
check(app.includes('data-auto-config="hours_before"')&&app.includes('data-auto-config="days_before"'),'Scheduled due-soon triggers must expose configurable lead time.');
check(app.includes("value:'assigned_user',label:'Responsável pela task'")&&app.includes("value:'creator',label:'Quem criou a task'")&&app.includes("value:'client',label:'Cliente'")&&app.includes("value:'custom',label:'Número específico'"),'WhatsApp action must expose responsible, creator, client and custom-number recipients.');
check(app.includes('data-auto-config="recipient"')&&app.includes('data-auto-config="custom_phone"'),'WhatsApp inspector must let admins choose recipient and enter a custom phone.');
check(automation.includes("'{{invoice.due_on}}'")&&automation.includes("'{{team.name}}'")&&automation.includes("'{{client.whatsapp}}'"),'Backend catalog for the message variable palette must include billing, team and client variables.');
check(app.includes("{{task.title}}")&&app.includes('data-auto-variable'),'WhatsApp editor must expose template variables.');
check(app.includes("name:'Nova automação'")&&app.includes("routeUrl('automations')+'?automation=new'"),'New automation flow and deep-link state must exist.');
check(app.includes("window.addEventListener('beforeunload'")&&app.includes('automationEditorState?.dirty'),'Unsaved automation changes must be protected.');
check(app.includes('automationRunsMarkup()')&&app.includes('Últimas execuções'),'Editor must surface recent execution history.');
check(css.includes('.automation-canvas-scroll')&&css.includes('.automation-tree-node')&&css.includes('.automation-node-card'),'Visual canvas/tree CSS must exist.');
check(css.includes('.automation-branches')&&css.includes('.automation-branch.yes')&&css.includes('.automation-branch.no'),'SIM/NÃO branch styling must exist.');
check(css.includes('@media(max-width:700px)')&&css.includes('.automation-side-panel{grid-template-columns:1fr}'),'Automation editor must have responsive mobile layout.');
check(icons.includes("workflow:")&&icons.includes("'git-branch':")&&icons.includes("'circle-play':")&&icons.includes("'circle-stop':"),'Automation editor icons must be bundled locally.');
check(api.includes("'automation.catalog'=>Automation::catalog()")&&api.includes("'automation.save'=>Automation::save($p)"),'Visual editor APIs must remain server-side/admin backed.');

check(automation.includes("'{{link do drive}}'"),'Automation variable catalog must expose Drive delivery link.');
for(const action of ['action.task','action.email','action.push','action.create_task'])check(app.includes(action),'Action editor missing '+action);
check(app.includes('data-auto-add-select')&&app.includes('data-auto-action-field'),'Additional actions must be configurable in visual editor.');
check(automation.includes("'demand.final_delivered'=>'Envio de entrega final'"),'Final delivery trigger missing in backend.');
check(app.includes("value:'group',label:'Grupo do WhatsApp'")&&app.includes("api('wpp.groups')")&&app.includes('data-auto-config="group_id"'),'Editor must list/select WhatsApp groups securely.');
check(api.includes("'wpp.groups'=>WppConnect::groups()"),'Admin group listing route must be wired.');
check(app.includes('id="demand-whatsapp-custom-phone"')&&app.includes('syncWhatsappNumber'),'Task WhatsApp custom number input must be conditional.');
check(app.includes('${admin?`<div class="field"><label>Disparos de WhatsApp</label>')&&app.includes("storedWhatsappMode=d?.whatsapp_recipient_mode||'creator'"),'Only admins must see WhatsApp task fields, with creator as default.');
check(demands.includes("if($role==='admin')")&&demands.includes('Somente administradores podem configurar disparos de WhatsApp.'),'API must protect WhatsApp settings on non-admin demand writes.');
check(app.includes('function automationFlowMarkup(')&&app.includes('function automationFlowWire(')&&app.includes('automationFlowRedraw()'),'Free canvas must render positioned blocks and connectors.');
check(app.includes('function automationFlowAdd(')&&app.includes('function automationFlowLink(')&&app.includes('automationFlowCanConnect('),'Graph editor must support multiple outgoing paths and loop safety.');
check(app.includes("card.addEventListener('pointermove'")&&app.includes('automationMarkDirty()'),'Dragging cards must update and persist node positions.');
check(css.includes('.automation-flow-stage')&&css.includes('.automation-flow-node')&&css.includes('.automation-flow-lines'),'Free canvas position and SVG connector styles are required.');
console.log('Automation frontend: grouped triggers, deadlines, flexible WhatsApp recipients, visual tree and run history OK');
