const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const fn = fs.readFileSync('public/assets/js/word-preview.js','utf8');

test('floating logo uses the page origin rather than the centered title origin',async()=>{
 const box={style:{position:'relative',width:'0px',left:'57.6pt'}};
 const page={offsetWidth:800,getBoundingClientRect:()=>({left:100,width:800})};
 const image={parentElement:box,closest:()=>page,decode:async()=>{},getBoundingClientRect:()=>({left:600})};
 const horizontal={getAttribute:()=> 'page',getElementsByTagNameNS:()=>[{textContent:'731521'}]};
 const drawing={getElementsByTagNameNS:()=>[{getElementsByTagNameNS:(_ns,name)=>name==='positionH'?[horizontal]:[]}]};
 const stage={isConnected:true,querySelectorAll:()=>[image]};
 const ctx=vm.createContext({window:{JSZip:{loadAsync:async()=>({file:()=>({async:async()=>'<xml/>'})})}},DOMParser:class{parseFromString(){return{getElementsByTagNameNS:()=>[drawing]};}}});
 vm.runInContext(fn,ctx);await ctx.window.VKWordPreview.alignPageImages({},stage);
 const delta=Number(box.style.left.match(/\+ ([-\d.]+)px/)[1]);
 assert.ok(Math.abs(600+delta-(100+731521/914400*96))<0.001);
 stage.isConnected=false;box.style.left='57.6pt';await ctx.window.VKWordPreview.alignPageImages({},stage);
 assert.equal(box.style.left,'57.6pt');
});
