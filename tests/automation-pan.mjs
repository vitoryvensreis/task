import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const start=source.indexOf('  function automationPanCanvas(){');
const end=source.indexOf('\n  function automationFlowWire(){',start);
assert.ok(start>0&&end>start,'Canvas pan handler must exist before flow wiring');
const script=source.slice(start,end);
const events=new Map();
const classes=new Set();
let captured=null;
const viewport={
  scrollLeft:0,scrollTop:0,clientWidth:900,clientHeight:600,
  getBoundingClientRect:()=>({left:0,top:0}),
  classList:{add:name=>classes.add(name),remove:name=>classes.delete(name)},
  addEventListener:(name,callback)=>events.set(name,callback),
  setPointerCapture:id=>{captured=id},
  hasPointerCapture:id=>captured===id,
  releasePointerCapture:id=>{if(captured===id)captured=null}
};
const state={dirty:false};
vm.runInNewContext(script+'\nautomationPanCanvas();',{
  $:selector=>selector==='.automation-canvas-scroll'?viewport:null,
  automationEditorState:state
});
assert.equal(viewport.scrollLeft,320,'Initial canvas pan offset should leave room on the left');
assert.equal(viewport.scrollTop,240,'Initial canvas pan offset should leave room above');
const target=selector=>({closest:()=>selector?{}:null});
const pointer=(type,id,x,y,element=null)=>({
  pointerId:id,pointerType:type,button:0,isPrimary:true,clientX:x,clientY:y,
  target:target(element),preventDefault(){this.prevented=true}
});
const down=pointer('mouse',1,360,290);
events.get('pointerdown')(down);
assert.equal(captured,1);
assert.equal(classes.has('is-panning'),true);
assert.equal(down.prevented,true);
const move=pointer('mouse',1,300,240);
events.get('pointermove')(move);
assert.equal(viewport.scrollLeft,380,'Dragging left should pan the viewport right');
assert.equal(viewport.scrollTop,290,'Dragging up should pan the viewport down');
assert.equal(move.prevented,true);
assert.equal(state.dirty,false,'Panning must not mark automation dirty');
events.get('scroll')();
assert.equal(state.viewport.left,380);
assert.equal(state.viewport.top,290);
events.get('pointerup')(pointer('mouse',1,300,240));
assert.equal(captured,null);
assert.equal(classes.has('is-panning'),false);
const before={left:viewport.scrollLeft,top:viewport.scrollTop};
events.get('pointerdown')(pointer('mouse',2,350,280,'.automation-flow-node'));
events.get('pointermove')(pointer('mouse',2,200,150));
assert.deepEqual({left:viewport.scrollLeft,top:viewport.scrollTop},before,'Card dragging must not pan the viewport');
assert.equal(captured,null);
events.get('pointerdown')(pointer('mouse',3,350,280,'button'));
events.get('pointermove')(pointer('mouse',3,200,150));
assert.deepEqual({left:viewport.scrollLeft,top:viewport.scrollTop},before,'Buttons must remain interactive');
assert.equal(captured,null);
events.get('pointerdown')(pointer('mouse',4,899,280));
assert.equal(captured,null,'Scrollbar interaction must not start panning');
events.get('pointerdown')(pointer('touch',5,400,280));
events.get('pointermove')(pointer('touch',5,420,300));
assert.equal(viewport.scrollLeft,360,'Touch dragging should pan the viewport');
events.get('pointercancel')(pointer('touch',5,420,300));
assert.equal(captured,null,'Cancelled touch must release pointer capture');
console.log('Automation canvas pan: mouse/touch, cards, controls, scrollbars, viewport state OK');
