// Presentation layer for the demo recording: synthetic cursor, click ripples,
// spotlight dimming, chapter cards, large captions. Every overlay is
// pointer-events:none so it can never intercept a real click.
export function makeStage(p){
  const S = { step:0, total:0, caption:'' };

  async function inject(){
    // Navigation destroys the overlay. Rebuild it AND restore the step badge and
    // caption, otherwise every page load leaves seconds of unguided dead air.
    await p.evaluate(([st, tot, cap]) => {
      if (!document.getElementById('__stage')) {
        const root = document.createElement('div');
        root.id = '__stage';
        root.innerHTML=`
        <div id="__dim"></div>
        <div id="__ring"></div>
        <div id="__cur"></div>
        <div id="__ripple"></div>
        <div id="__cap"><span id="__captxt"></span></div>
        <div id="__step"></div>
        <div id="__card"><div id="__cardin"><div id="__cardn"></div><div id="__cardt"></div><div id="__cards"></div></div></div>`;
        document.documentElement.appendChild(root);
        const css = document.createElement('style');
        css.textContent=`
        #__stage,#__stage *{pointer-events:none!important;box-sizing:border-box}
        #__stage{position:fixed;inset:0;z-index:2147483647}
        #__dim{position:fixed;inset:0;background:rgba(8,11,20,.62);opacity:0;transition:opacity .45s}
        #__ring{position:fixed;border:3px solid #f43f5e;border-radius:8px;opacity:0;
          transition:all .45s cubic-bezier(.4,0,.2,1);box-shadow:0 0 0 4000px rgba(8,11,20,.62),0 0 22px rgba(244,63,94,.9)}
        #__cur{position:fixed;width:30px;height:30px;left:700px;top:520px;opacity:1;
          transition:left .75s cubic-bezier(.33,1,.68,1),top .75s cubic-bezier(.33,1,.68,1),opacity .3s;
          background:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='30' height='30'><path d='M3 2 L3 20 L8 15.5 L11.5 23 L14.8 21.4 L11.4 14.2 L18 14 Z' fill='%23fff' stroke='%23111' stroke-width='1.6' stroke-linejoin='round'/></svg>") no-repeat;
          filter:drop-shadow(0 2px 4px rgba(0,0,0,.55))}
        #__ripple{position:fixed;width:14px;height:14px;border-radius:50%;border:3px solid #f43f5e;opacity:0}
        #__ripple.go{animation:rp .65s ease-out}
        @keyframes rp{0%{transform:scale(.4);opacity:1}100%{transform:scale(5);opacity:0}}
        #__cap{position:fixed;left:0;right:0;bottom:0;background:linear-gradient(180deg,rgba(9,12,20,.0),rgba(9,12,20,1) 30%);
          padding:52px 60px 30px;opacity:0;transition:opacity .18s}
        #__captxt{display:inline-block;color:#fff;font:700 30px/1.35 system-ui,-apple-system,sans-serif;
          letter-spacing:-.2px;text-shadow:0 2px 12px rgba(0,0,0,.9);max-width:1100px}
        #__step{position:fixed;bottom:118px;left:26px;background:rgba(9,12,20,.9);color:#cbd5e1;
          font:700 15px system-ui;padding:7px 14px;border-radius:999px;border:1px solid rgba(148,163,184,.35);opacity:0;transition:opacity .3s}
        #__card{position:fixed;inset:0;background:#0b1020;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .5s}
        #__cardin{text-align:center;padding:0 60px}
        #__cardn{color:#f43f5e;font:800 19px system-ui;letter-spacing:3.5px;margin-bottom:18px}
        #__cardt{color:#fff;font:800 52px/1.15 system-ui;letter-spacing:-1.2px}
        #__cards{color:#94a3b8;font:500 23px/1.5 system-ui;margin-top:20px;max-width:820px}`;
        document.head.appendChild(css);
      }
      if (st > 0) {
        const b = document.getElementById('__step');
        if (b) { b.textContent = 'STEP ' + st + ' OF ' + tot; b.style.opacity = '1'; }
      }
      if (cap) {
        const c = document.getElementById('__cap'), x = document.getElementById('__captxt');
        if (x) x.textContent = cap;
        if (c) c.style.opacity = '1';
      }
    }, [S.step, S.total, S.caption]).catch(() => {});
  }

  const wait = ms => p.waitForTimeout(ms);

  async function box(sel){
    // Bring it into view FIRST. This only ever measured, so focusing an element
    // below the fold drew the ring and its badge at the viewport edge - the
    // "filed, with the ARN" callout pointed at a table that was off-screen and
    // collided with the caption bar, captioning something nobody could see.
    await p.evaluate((s)=>{
      const el=document.querySelector(s); if(!el) return;
      const r=el.getBoundingClientRect();
      // Align the TOP just under the nav rather than centring. block:'center' on a
      // tall table puts its middle at mid-viewport, which left only two rows above
      // the caption bar - the rest of the evidence was still cut off.
      if(r.top<90||r.bottom>window.innerHeight-150){
        const abs=window.scrollY+r.top;
        window.scrollTo({top:Math.max(0,abs-110),behavior:'instant'});
      }
    }, sel).catch(()=>{});
    await p.waitForTimeout(500);   // let the scroll settle before measuring
    return p.evaluate((s)=>{
      let el=document.querySelector(s); if(!el) return null;
      let r=el.getBoundingClientRect();
      // select2 parks the real <select> at left:-9999px - use the visible proxy
      if(r.width===0||r.height===0||r.top<-100){
        const id=el.id?document.querySelector(`#select2-${el.id}-container`):null;
        const alt=id||(el.closest('tr,div')||document).querySelector('.select2-container,.select2-selection');
        if(alt){const b=alt.getBoundingClientRect(); if(b.width>0&&b.top>-100) r=b; else return null;}
        else return null;
      }
      return {x:r.left+r.width/2,y:r.top+r.height/2,l:r.left,t:r.top,w:r.width,h:r.height};
    }, sel).catch(()=>null);
  }

  return {
    inject,
    async ready(){ await inject(); },

    async cursorTo(sel){
      await inject();
      const b=await box(sel); if(!b) return null;
      await p.evaluate(([x,y])=>{const c=document.getElementById('__cur');
        if(c){c.style.opacity='1';c.style.left=(x-4)+'px';c.style.top=(y-4)+'px';}},[b.x,b.y]);
      await wait(850);
      return b;
    },

    async ripple(sel){
      const b=await box(sel); if(!b) return;
      await p.evaluate(([x,y])=>{const r=document.getElementById('__ripple');
        if(!r) return; r.style.left=(x-7)+'px'; r.style.top=(y-7)+'px';
        r.classList.remove('go'); void r.offsetWidth; r.classList.add('go');},[b.x,b.y]);
      await wait(450);
    },

    async spotlight(sel,label){
      await inject();
      const b=await box(sel); if(!b) return false;
      await p.evaluate(([l,t,w,h,txt])=>{
        const ring=document.getElementById('__ring');
        ring.style.left=(l-7)+'px'; ring.style.top=(t-7)+'px';
        ring.style.width=(w+14)+'px'; ring.style.height=(h+14)+'px'; ring.style.opacity='1';
        document.querySelectorAll('.__cal').forEach(n=>n.remove());
        if(txt){ const c=document.createElement('div'); c.className='__cal'; c.textContent=txt;
          Object.assign(c.style,{position:'fixed',zIndex:'2147483647',pointerEvents:'none',
            background:'#f43f5e',color:'#fff',font:'700 17px system-ui',padding:'9px 15px',
            borderRadius:'8px',whiteSpace:'nowrap',boxShadow:'0 6px 20px rgba(0,0,0,.5)'});
          c.style.top=(t-48)+'px'; c.style.left=Math.max(14,l-7)+'px';
          document.getElementById('__stage').appendChild(c); }
      },[b.l,b.t,b.w,b.h,label||'']);
      await wait(700);
      return true;
    },

    async unspot(){
      await p.evaluate(()=>{const r=document.getElementById('__ring');
        if(r) r.style.opacity='0'; document.querySelectorAll('.__cal').forEach(n=>n.remove());}).catch(()=>{});
      await wait(350);
    },

    async say(text,ms=4200){
      S.caption=text; await inject();
      await p.evaluate(t=>{const c=document.getElementById('__cap'),s=document.getElementById('__captxt');
        if(s){s.textContent=t;} if(c){c.style.opacity='1';}},text);
      await wait(ms);
    },
    async hush(){ S.caption=''; await p.evaluate(()=>{const c=document.getElementById('__cap'); if(c) c.style.opacity='0';}).catch(()=>{}); await wait(300); },

    async step(n,total){
      S.step=n; S.total=total; await inject();
      await p.evaluate(([a,b])=>{const s=document.getElementById('__step');
        if(s){s.textContent=`STEP ${a} OF ${b}`; s.style.opacity='1';}},[n,total]).catch(()=>{});
    },

    // caption and marker ALWAYS travel together - the previous version let
    // narration run with nothing highlighted, so there was nothing to follow.
    async focus(sel,badge,text,ms=5200){
      await inject();
      const b=await box(sel);
      if(b){
        await p.evaluate(([x,y])=>{const c=document.getElementById('__cur');
          if(c){c.style.opacity='1';c.style.left=(x-4)+'px';c.style.top=(y-4)+'px';}},[b.x,b.y]);
        await wait(780);
        await p.evaluate(([l,t,w,h,lab])=>{
          const ring=document.getElementById('__ring');
          ring.style.left=(l-8)+'px'; ring.style.top=(t-8)+'px';
          ring.style.width=(w+16)+'px'; ring.style.height=(h+16)+'px'; ring.style.opacity='1';
          document.querySelectorAll('.__cal').forEach(n=>n.remove());
          if(lab){const c=document.createElement('div'); c.className='__cal'; c.textContent=lab;
            Object.assign(c.style,{position:'fixed',zIndex:'2147483647',pointerEvents:'none',
              background:'#f43f5e',color:'#fff',font:'800 18px system-ui',padding:'10px 16px',
              borderRadius:'9px',whiteSpace:'nowrap',boxShadow:'0 8px 26px rgba(0,0,0,.6)'});
            const above=t>110; c.style.top=(above?t-54:t+h+14)+'px'; c.style.left=Math.max(16,l-8)+'px';
            document.getElementById('__stage').appendChild(c);}
        },[b.l,b.t,b.w,b.h,badge||'']);
        await wait(420);
      }
      if(text){ S.caption=text;
        await p.evaluate(t=>{const c=document.getElementById('__cap'),x=document.getElementById('__captxt');
          if(x)x.textContent=t; if(c)c.style.opacity='1';},text); }
      await wait(ms);
      return !!b;
    },

    async chapter(num,title,sub,ms=3800){
      await inject();
      await p.evaluate(([n,t,s])=>{
        document.getElementById('__cardn').textContent=n;
        document.getElementById('__cardt').textContent=t;
        document.getElementById('__cards').textContent=s||'';
        document.getElementById('__card').style.opacity='1';
        const c=document.getElementById('__cap'); if(c) c.style.opacity='0';
      },[num,title,sub]);
      await wait(ms);
      await p.evaluate(()=>{document.getElementById('__card').style.opacity='0';});
      await wait(650);
    }
  };
}
