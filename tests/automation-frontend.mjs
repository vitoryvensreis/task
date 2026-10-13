import fs from 'node:fs';

const app=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const css=fs.readFileSync(new URL('../public/assets/app.css',import.meta.url),'utf8');
const icons=fs.readFileSync(new URL('../public/assets/taskesc-icons.js',import.meta.url),'utf8');
const api=fs.readFileSync(new URL('../public/api.php',import.meta.url),'utf8');

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
check(app.includes("{{task.title}}")&&app.includes('data-auto-variable'),'WhatsApp editor must expose template variables.');
check(app.includes("name:'Nova automação'")&&app.includes("routeUrl('automations')+'?automation=new'"),'New automation flow and deep-link state must exist.');
check(app.includes("window.addEventListener('beforeunload'")&&app.includes('automationEditorState?.dirty'),'Unsaved automation changes must be protected.');
check(app.includes('automationRunsMarkup()')&&app.includes('Últimas execuções'),'Editor must surface recent execution history.');
check(css.includes('.automation-canvas-scroll')&&css.includes('.automation-tree-node')&&css.includes('.automation-node-card'),'Visual canvas/tree CSS must exist.');
check(css.includes('.automation-branches')&&css.includes('.automation-branch.yes')&&css.includes('.automation-branch.no'),'SIM/NÃO branch styling must exist.');
check(css.includes('@media(max-width:700px)')&&css.includes('.automation-side-panel{grid-template-columns:1fr}'),'Automation editor must have responsive mobile layout.');
check(icons.includes("workflow:")&&icons.includes("'git-branch':")&&icons.includes("'circle-play':")&&icons.includes("'circle-stop':"),'Automation editor icons must be bundled locally.');
check(api.includes("'automation.catalog'=>Automation::catalog()")&&api.includes("'automation.save'=>Automation::save($p)"),'Visual editor APIs must remain server-side/admin backed.');

console.log('Automation frontend: route, visual tree, SIM/NAO branches, inspector, save/toggle, run history and responsive canvas OK');
