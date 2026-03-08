<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$csrf = $_SESSION['csrf'];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Blackjack</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    /* --- polish + animations --- */
    .tableHead{
      display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between;
      margin-top:12px;
    }
    .ctlGroup{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .ctlGroup .pill{ display:flex; gap:10px; align-items:center; }
    .ctlGroup select{ background: rgba(255,255,255,.06); color: inherit; border:1px solid rgba(255,255,255,.14); border-radius:10px; padding:6px 10px; }
    .actions .btn, .actions button{ white-space:nowrap; }

    .hand{ min-height:64px; }
    .cardchip{
      width:44px; height:60px; border-radius:10px;
      border:1px solid rgba(255,255,255,.14);
      background: linear-gradient(180deg, rgba(255,255,255,.10), rgba(0,0,0,.10));
      display:flex; align-items:center; justify-content:center;
      font-weight:900; font-size:18px;
      box-shadow: 0 12px 24px rgba(0,0,0,.25);
      transform: translateY(6px) scale(.98);
      opacity:0;
      animation: dealIn .28s ease-out forwards;
    }
    .cardchip.red{ color: #ff7a7a; }
    .cardchip.black{ color: #d6e0ff; }

    @keyframes dealIn{
      to{ transform: translateY(0) scale(1); opacity:1; }
    }

    .cardchip.flip{
      position:relative;
      transform-style:preserve-3d;
      animation: flipIn .38s ease-out forwards;
    }
    @keyframes flipIn{
      0%{ transform: rotateY(85deg) translateY(6px) scale(.98); opacity:.0; }
      100%{ transform: rotateY(0deg) translateY(0) scale(1); opacity:1; }
    }

    .phasePill{
      display:inline-flex; gap:8px; align-items:center;
      padding:6px 10px; border-radius:999px;
      border:1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.05);
      font-weight:800; font-size:12px; opacity:.95;
    }

    .row2{ display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
    @media (max-width: 960px){ .row2{ grid-template-columns:1fr; } }

    .subtleNote{ opacity:.85; font-size:12px; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="top">
      <div>
        <h1>🂡 Blackjack</h1>
        <div class="sub">Hit / Stand / Double / Split / Insurance. Credits are account-based.</div>
      </div>
      <div class="nav">
        <a class="btn" href="casino.php">🏠 Hub</a>
        <a class="btn" href="lottery.php">🎰 Slots</a>
        <a class="btn active" href="blackjack.php">🂡 Blackjack</a>
        <a class="btn" href="baccarat.php">🎴 Baccarat</a>
        <a class="btn" href="tutorial.php">🎓 Tutorial</a>
        <a class="btn" href="logout.php">Logout</a>
      </div>
    </div>

    <div class="pillrow" style="margin-top:12px;">
      <div class="pill">Credits: <strong id="m-credits">—</strong></div>
      <div class="pill">Session high: <strong id="m-high">—</strong></div>
      <div class="pill">Shoe: <strong id="m-shoe">—</strong></div>
      <div class="phasePill" id="phasePill">—</div>
      <button class="btn" style="height:34px; padding:0 12px;" onclick="resetSession()">Reset Session</button>
    </div>

    <div class="tableHead">
      <div class="ctlGroup">
        <div class="pill" style="gap:10px;">
          Bet
          <input type="number" id="bet" min="1" step="1" value="100" style="width:110px"/>
        </div>

        <label class="pill" style="cursor:pointer;">
          <input type="checkbox" id="h17" onchange="setH17(this.checked)" />
          Dealer hits soft 17
        </label>

        <label class="pill" style="gap:10px;">
          Decks
          <select id="decks" onchange="setDecks(this.value)">
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="4">4</option>
            <option value="6" selected>6</option>
            <option value="8">8</option>
          </select>
        </label>
      </div>

      <div class="ctlGroup">
        <button class="btn" onclick="start()">Start</button>
        <button class="secondary" onclick="hit()">Hit</button>
        <button class="secondary" onclick="stand()">Stand</button>
        <button class="secondary" onclick="dbl()">Double</button>
        <button class="secondary" onclick="split()">Split</button>
        <button class="secondary" onclick="insurance()">Insurance</button>
        <button class="secondary danger" onclick="roundReset()">Reset Round</button>
      </div>
    </div>

    <div id="msg" class="msg" style="margin-top:10px;"></div>

    <div class="row2" style="margin-top:14px;">
      <div class="card" style="box-shadow:none;">
        <div class="box">
          <div class="small"><b>Player</b> <span id="pt" class="small"></span></div>
          <div id="ph0" class="hand" style="margin-top:10px;"></div>
          <div id="ph1wrap" style="display:none; margin-top:10px;">
            <div class="small"><b>Split Hand</b></div>
            <div id="ph1" class="hand" style="margin-top:10px;"></div>
          </div>

          <div class="small" style="margin-top:16px;"><b>Dealer</b> <span id="dt" class="small"></span></div>
          <div id="dealer" class="hand" style="margin-top:10px;"></div>

          <div class="small" style="margin-top:16px;"><b>Status</b></div>
          <div id="status" class="small">—</div>
          <div class="subtleNote" style="margin-top:6px;">
            Tip: Deck changes reshuffle immediately.
          </div>
        </div>
      </div>

      <div class="card" style="box-shadow:none;">
        <h2 style="margin:0; font-size:18px;">🕒 Previous Hands</h2>
        <div class="sub">History persists while you switch pages.</div>
        <div class="hist" id="hist" style="margin-top:12px;"></div>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF = <?php echo json_encode($csrf); ?>;

async function api(op, payload={}){
  const res = await fetch('api.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({op, csrf: CSRF, ...payload})
  });
  const data = await res.json();
  if(!res.ok || !data.ok) throw new Error(data?.error || 'Request failed');
  return data;
}

function money(n){ return String(Math.floor(Number(n||0))).replace(/\B(?=(\d{3})+(?!\d))/g, ","); }
function setMsg(type, text){
  document.getElementById('msg').innerHTML = text ? `<div class="${type==='err'?'err':'ok'}">${text}</div>` : '';
}

function cleanCards(arr){
  // Supports either pure arrays or arrays that accidentally include meta objects.
  if(!Array.isArray(arr)) return [];
  return arr.filter(c => c && typeof c === 'object' && 'r' in c && 's' in c);
}

function renderMeta(m){
  document.getElementById('m-credits').textContent = money(m.credits);
  document.getElementById('m-high').textContent = money(m.sessionHighCredits ?? m.credits);
  const s=m.shoe || {};
  document.getElementById('m-shoe').textContent = `${s.remaining}/${s.totalCards} left • decks ${s.decks} • shuffles ${s.shuffles}`;
  document.getElementById('h17').checked = !!m.settings?.dealerHitsSoft17;

  const decksSel = document.getElementById('decks');
  if(decksSel && String(decksSel.value) !== String(s.decks)){
    decksSel.value = String(s.decks);
  }
}

function cardHTML(c, i){
  const red = (c.s==='♥' || c.s==='♦');
  // stagger a bit for deal feel
  const delay = Math.min(220, i*70);
  return `<div class="cardchip flip ${red?'red':'black'}" style="animation-delay:${delay}ms">${c.r+c.s}</div>`;
}

function renderCards(el, cards){
  const clean = cleanCards(cards);
  if(!clean.length){ el.innerHTML = `<span class="small">—</span>`; return; }
  el.innerHTML = clean.map((c,i)=>cardHTML(c,i)).join('');
}

function renderBJ(bj){
  const hands = Array.isArray(bj.hands) ? bj.hands : [];
  const dealer = cleanCards(bj.dealer);

  const h0 = hands[0] ? cleanCards(hands[0]) : [];
  const h1 = hands[1] ? cleanCards(hands[1]) : [];

  renderCards(document.getElementById('ph0'), h0);

  if(h1.length){
    document.getElementById('ph1wrap').style.display = 'block';
    renderCards(document.getElementById('ph1'), h1);
  } else {
    document.getElementById('ph1wrap').style.display = 'none';
    document.getElementById('ph1').innerHTML = '';
  }

  renderCards(document.getElementById('dealer'), dealer);

  const totals = Array.isArray(bj.handTotals) ? bj.handTotals : [];
  const active = Number(bj.activeHand||0);

  let pt = '—';
  if(totals.length===1){
    pt = `• Total ${totals[0].total}${totals[0].soft?' (soft)':''}`;
  } else if(totals.length>1){
    const cur = totals.find(x=>Number(x.idx)===active);
    pt = `• Active hand ${active+1} total ${cur?.total ?? '—'}${cur?.soft?' (soft)':''}`;
  }
  document.getElementById('pt').textContent = pt;
  document.getElementById('dt').textContent = dealer.length ? `• Total ${bj.dealerTotal}${bj.dealerSoft?' (soft)':''}` : '';

  const phase = String(bj.phase || 'idle');
  const phaseText = phase==='player' ? 'YOUR MOVE' : (phase==='dealer' ? 'DEALER' : (phase==='complete' ? 'COMPLETE' : 'IDLE'));
  document.getElementById('phasePill').textContent = phaseText;

  document.getElementById('status').textContent =
    bj.result?.label || (phase==='player' ? 'Your move.' : (phase==='idle' ? '—' : 'Resolving...'));

  const can = bj.flags || {};
  document.querySelector('button[onclick="dbl()"]').disabled = !can.canDouble;
  document.querySelector('button[onclick="split()"]').disabled = !can.canSplit;
  document.querySelector('button[onclick="insurance()"]').disabled = !can.canInsurance;

  renderHist(Array.isArray(bj.history)? bj.history : []);
}

function renderHist(items){
  const el=document.getElementById('hist');
  if(!items.length){ el.innerHTML=`<div class="histitem">No hands yet.</div>`; return; }

  el.innerHTML = items.map(it=>{
    const hands = Array.isArray(it.hands) ? it.hands : [];
    const player = hands.map((h,idx)=>{
      const cards = cleanCards(h).map(c=>c.r+c.s).join(' ');
      return `H${idx+1}: ${cards || '—'}`;
    }).join(' • ');

    const dealer = cleanCards(it.dealer || []).map(c=>c.r+c.s).join(' ');

    return `
      <div class="histitem">
        <div><b>${String(it.label||'Round')}</b> <span class="small">• ${String(it.time||'')}</span></div>
        <div class="small">Bet ${money(it.bet||0)} • Insurance ${money(it.insuranceBet||0)}</div>
        <div class="small">Player: ${player || '—'}</div>
        <div class="small">Dealer: ${dealer || '—'}</div>
      </div>
    `;
  }).join('');
}

function bet(){
  return Math.max(1, Math.floor(Number(document.getElementById('bet').value||1)));
}

async function load(){
  const d=await api('bj_get');
  renderMeta(d.meta);
  renderBJ(d.bj);
}

async function start(){ setMsg('', ''); try{ const d=await api('bj_start',{bet:bet()}); renderMeta(d.meta); renderBJ(d.bj); setMsg('ok','Hand started.'); }catch(e){ setMsg('err',e.message); } }
async function hit(){ setMsg('', ''); try{ const d=await api('bj_hit'); renderMeta(d.meta); renderBJ(d.bj); }catch(e){ setMsg('err',e.message); } }
async function stand(){ setMsg('', ''); try{ const d=await api('bj_stand'); renderMeta(d.meta); renderBJ(d.bj); }catch(e){ setMsg('err',e.message); } }
async function dbl(){ setMsg('', ''); try{ const d=await api('bj_double'); renderMeta(d.meta); renderBJ(d.bj); }catch(e){ setMsg('err',e.message); } }
async function split(){ setMsg('', ''); try{ const d=await api('bj_split'); renderMeta(d.meta); renderBJ(d.bj); setMsg('ok','Split created.'); }catch(e){ setMsg('err',e.message); } }
async function insurance(){
  const amtStr = prompt('Insurance amount? (max = half bet)');
  if(amtStr===null) return;
  const amt = Math.max(1, Math.floor(Number(amtStr)));
  setMsg('', '');
  try{ const d=await api('bj_insurance',{amt}); renderMeta(d.meta); renderBJ(d.bj); setMsg('ok','Insurance placed.'); }catch(e){ setMsg('err',e.message); }
}
async function roundReset(){ setMsg('', ''); try{ const d=await api('bj_reset'); renderMeta(d.meta); renderBJ(d.bj); setMsg('ok','Round reset.'); }catch(e){ setMsg('err',e.message); } }

async function setH17(on){
  try{
    const d=await api('settings_set',{dealerHitsSoft17:!!on});
    renderMeta(d.meta);
    renderBJ(d.bj);
    setMsg('ok','Settings updated.');
  }catch(e){ setMsg('err', e.message); }
}

async function setDecks(v){
  setMsg('', '');
  const decks = Math.max(1, Math.min(8, Math.floor(Number(v||6))));
  try{
    const d = await api('shoe_set', {decks});
    renderMeta(d.meta);
    setMsg('ok', `Shoe reshuffled with ${decks} deck(s).`);
  }catch(e){
    setMsg('err', e.message);
  }
}

async function resetSession(){
  if(!confirm('Reset session game state (shoe + games)?')) return;
  setMsg('', '');
  try{
    const d = await api('reset_all');
    renderMeta(d.meta);
    renderBJ(d.bj);
    setMsg('ok', 'Session reset complete.');
  }catch(e){
    setMsg('err', e.message);
  }
}

load().catch(e=>alert(e.message));
</script>

<script>
(function(){
  const IDLE_LIMIT_MS = 20 * 60 * 1000;   // 20 minutes total
  const WARN_AT_MS    = 18 * 60 * 1000;   // warn at 18 minutes
  let tLast = Date.now();
  let warned = false;

  const overlay = document.createElement('div');
  overlay.style.cssText = `
    position:fixed; inset:0; display:none; align-items:center; justify-content:center;
    background:rgba(0,0,0,.55); backdrop-filter: blur(4px); z-index:99999;
  `;
  overlay.innerHTML = `
    <div style="width:min(520px,92vw); border-radius:18px; border:1px solid rgba(255,255,255,.18);
      background:rgba(12,12,16,.88); padding:16px; box-shadow:0 18px 60px rgba(0,0,0,.55);">
      <div style="font-weight:900; font-size:18px;">You’re about to be signed out</div>
      <div style="opacity:.9; margin-top:8px; line-height:1.45;">
        For your security, we log you out after inactivity. Click below to stay signed in.
      </div>
      <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
        <button id="stayBtn" class="btn">Stay signed in</button>
        <a class="btn secondary" href="logout.php">Logout now</a>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);

  function touch(){ tLast = Date.now(); warned = false; overlay.style.display='none'; }
  ['click','keydown','mousemove','touchstart','scroll'].forEach(ev => window.addEventListener(ev, touch, {passive:true}));

  // This pings server to refresh session timer 
  async function ping(){
    try{
      if (typeof CSRF === 'undefined') return touch();
      await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({op:'ping', csrf: CSRF})
      });
    } catch(e) {}
    touch();
  }
  overlay.querySelector('#stayBtn').addEventListener('click', ping);

  setInterval(()=>{
    const idle = Date.now() - tLast;
    if (!warned && idle >= WARN_AT_MS && idle < IDLE_LIMIT_MS){
      warned = true;
      overlay.style.display='flex';
    }
    if (idle >= IDLE_LIMIT_MS){
      window.location.href = 'logout.php';
    }
  }, 1000);
})();
</script>

</body>


</html>
