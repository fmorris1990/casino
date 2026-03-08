<?php
declare(strict_types=1);
require __DIR__ . '/security_admin_guard.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Admin • Security</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .grid2{ display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
    @media (max-width: 980px){ .grid2{ grid-template-columns:1fr; } }

    .tiles{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:12px; margin-top:12px; }
    @media (max-width: 980px){ .tiles{ grid-template-columns:1fr; } }

    .tile{
      border:1px solid rgba(255,255,255,.12);
      background: rgba(0,0,0,.14);
      border-radius:18px;
      padding:14px;
      box-shadow: 0 12px 30px rgba(0,0,0,.22);
      position:relative;
      overflow:hidden;
    }
    .tile::before{
      content:'';
      position:absolute; inset:-2px;
      background: radial-gradient(circle at 18% 26%, rgba(120,220,255,.16), transparent 42%),
                  radial-gradient(circle at 84% 70%, rgba(255,120,220,.12), transparent 45%),
                  radial-gradient(circle at 40% 86%, rgba(170,255,140,.10), transparent 46%);
      filter: blur(10px);
      opacity:.85;
      pointer-events:none;
    }
    .tile > *{ position:relative; z-index:2; }

    .tileTitle{ font-weight:950; font-size:16px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .tileSub{ margin-top:6px; opacity:.9; }
    .kpi{ font-weight:950; font-size:18px; margin-top:10px; }
    .muted{ opacity:.85; font-size:12px; }

    .statusPill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.06);
      font-weight:800;
      font-size:12px;
    }
    .dot{ width:10px; height:10px; border-radius:50%; background: rgba(255,255,255,.35); }
    .ok .dot{ background: rgba(160,255,190,.9); box-shadow: 0 0 18px rgba(160,255,190,.18); }
    .warn .dot{ background: rgba(255,210,140,.92); box-shadow: 0 0 18px rgba(255,210,140,.18); }
    .bad .dot{ background: rgba(255,150,150,.92); box-shadow: 0 0 18px rgba(255,150,150,.18); }

    details{ border:1px solid rgba(255,255,255,.10); background: rgba(0,0,0,.12); border-radius:16px; padding:10px 12px; }
    summary{ cursor:pointer; font-weight:900; list-style:none; }
    summary::-webkit-details-marker{ display:none; }
    pre{ white-space:pre-wrap; word-break:break-word; margin:0; }
    .kv{ display:grid; grid-template-columns: 220px 1fr; gap:10px; }
    @media (max-width: 680px){ .kv{ grid-template-columns:1fr; } }
    .row{ display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .smallline{ opacity:.9; font-size:13px; }

    .tag{
      display:inline-block;
      padding:2px 8px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.05);
      font-size:12px;
      opacity:.9;
      margin-left:8px;
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="top">
      <div>
        <h1>🛡️ Admin Security</h1>
        <div class="sub">Internal checks only (no exploitation). Admin-only access.</div>
      </div>
      <div class="nav">
        <a class="btn" href="casino.php">🏠 Hub</a>
        <a class="btn active" href="admin_security.php">🛡️ Security</a>
        <a class="btn" href="logout.php">Logout</a>
      </div>
    </div>

    <div class="pillrow" style="margin-top:12px;">
      <button class="btn" onclick="run()">Run Checks</button>
      <button class="btn secondary" onclick="copyJson()">Copy JSON</button>
      <div class="pill">Last run: <strong id="last">—</strong></div>
      <div class="pill">Overall: <strong id="overall">—</strong></div>
    </div>

    <div class="tiles">
      <div class="tile">
        <div class="tileTitle">
          <span>Run Security Checks</span>
          <span class="statusPill" id="tileStatus"><span class="dot"></span><span id="tileText">—</span></span>
        </div>
        <div class="tileSub">Checks HTTPS + headers + local ports (if allowed) + risky files.</div>
        <div class="kpi" id="kpiSummary">—</div>
        <div class="muted">Tip: run again after SSL is active to confirm HSTS + secure cookies.</div>
      </div>

      <div class="tile">
        <div class="tileTitle">
          <span>Admin Tools</span>
          <span class="tag"><a href="admin_users.php">Admin Tools</a></span>
        </div>
        <div class="tileSub">Manage users, credits, bans, and session highs.</div>
        <div class="kpi">Users panel</div>
      </div>

      <div class="tile">
        <div class="tileTitle">
          <span>Return to Casino</span>
        </div>
        <div class="tileSub">Back to hub and games.</div>
        <div class="kpi"><a class="btn" href="casino.php">🏠 Open Hub</a></div>
        <div class="muted">Use this when you’re done auditing.</div>
      </div>
    </div>
  </div>

  <div class="grid2" style="margin-top:14px;">
    <div class="card">
      <div class="row">
        <h2 style="margin:0; font-size:18px;">Environment</h2>
        <div class="smallline" id="envMini">—</div>
      </div>

      <div class="box" style="margin-top:12px;">
        <div id="env" class="small">—</div>
      </div>

      <details style="margin-top:12px;" open>
        <summary>Security Headers (current response)</summary>
        <div class="box" style="margin-top:10px;">
          <div id="hdrs" class="small">—</div>
        </div>
        <div class="small" style="margin-top:8px; opacity:.9;">
          <b>Note:</b> Add HSTS only after HTTPS is confirmed stable, otherwise you can lock users into broken HTTPS.
        </div>
      </details>
    </div>

    <div class="card">
      <div class="row">
        <h2 style="margin:0; font-size:18px;">Ports & Files</h2>
        <div class="smallline" id="pfMini">—</div>
      </div>

      <details style="margin-top:12px;" open>
        <summary>Local listening ports (best-effort)</summary>
        <div class="box" style="margin-top:10px;">
          <div id="ports" class="small">—</div>
        </div>
      </details>

      <details style="margin-top:12px;" open>
        <summary>Path warnings</summary>
        <div class="box" style="margin-top:10px;">
          <div id="paths" class="small">—</div>
        </div>
      </details>

      <details style="margin-top:12px;">
        <summary>Raw JSON</summary>
        <div class="box" style="margin-top:10px;">
          <pre id="raw" class="small">—</pre>
        </div>
      </details>
    </div>
  </div>
</div>

<script>
let LAST = null;

function money(n){ return String(Math.floor(Number(n||0))).replace(/\B(?=(\d{3})+(?!\d))/g, ","); }

function statusClass(level){
  return level === 'OK' ? 'ok' : (level === 'WARN' ? 'warn' : 'bad');
}

function kv(obj){
  return Object.entries(obj).map(([k,v])=>{
    const vv = (v===null || v===undefined || v==='') ? '<span style="opacity:.85;color:#ffd18a;">missing</span>' : String(v);
    return `<div class="kv"><div><b>${k}</b></div><div>${vv}</div></div>`;
  }).join('<div style="height:8px"></div>');
}

function setOverall(level, text){
  document.getElementById('overall').textContent = `${level}${text ? ' • ' + text : ''}`;
  const tile = document.getElementById('tileStatus');
  tile.className = `statusPill ${statusClass(level)}`;
  document.getElementById('tileText').textContent = level;
}

function evaluate(data){
  // Simple scoring:
  // OK if https_active true and core headers present
  // WARN if missing https or missing important headers
  // BAD if errors/exec issues not relevant here; mostly warn-based
  const env = data.env || {};
  const hdrs = data.headers || {};
  let warns = 0;

  if (!env.https_active) warns++;
  if (!hdrs['X-Content-Type-Options']) warns++;
  if (!hdrs['X-Frame-Options']) warns++;
  if (!hdrs['Referrer-Policy']) warns++;
  // CSP and HSTS are “nice” but can be staged
  if (!hdrs['Content-Security-Policy']) warns++;
  if (env.https_active && !hdrs['Strict-Transport-Security']) warns++;

  if (warns === 0) return {level:'OK', text:'All key checks look good.'};
  return {level:'WARN', text:`${warns} item(s) to review.`};
}

async function run(){
  const res = await fetch('security_runner.php', {cache:'no-store'});
  const data = await res.json();
  if(!data.ok) throw new Error(data.error || 'Failed');
  LAST = data;

  document.getElementById('last').textContent = data.time || new Date().toISOString();

  const env = data.env || {};
  const hdrs = data.headers || {};
  const ports = data.ports || {};
  const paths = data.paths || {};

  const evald = evaluate(data);
  setOverall(evald.level, evald.text);

  // KPI summary
  const portsLine = ports.supported ? `Ports visible (${(ports.ports||[]).length})` : 'Ports: not available';
  const warnsCount = (paths.warnings||[]).length;
  document.getElementById('kpiSummary').textContent = `${env.https_active ? 'HTTPS: ON' : 'HTTPS: OFF'} • ${portsLine} • Warnings: ${warnsCount}`;

  // Mini lines
  document.getElementById('envMini').textContent = `PHP ${env.php_version || '—'} • exec ${env.exec_available ? 'available' : 'blocked'}`;
  document.getElementById('pfMini').textContent = `${ports.supported ? 'ports visible' : 'ports hidden'} • ${warnsCount} path warning(s)`;

  // Environment details
  const envView = {
    php_version: env.php_version,
    https_active: env.https_active ? 'true' : 'false',
    session_cookie_secure: env.session_cookie_secure ? 'true' : 'false',
    session_cookie_httponly: env.session_cookie_httponly ? 'true' : 'false',
    display_errors: env.display_errors ? 'true (turn OFF in prod)' : 'false',
    expose_php: env.expose_php ? 'true (turn OFF in prod)' : 'false',
    exec_available: env.exec_available ? 'true' : 'false',
  };
  document.getElementById('env').innerHTML = kv(envView);

  // Headers details
  const hdrView = {
    'Strict-Transport-Security': hdrs['Strict-Transport-Security'],
    'Content-Security-Policy': hdrs['Content-Security-Policy'],
    'X-Content-Type-Options': hdrs['X-Content-Type-Options'],
    'X-Frame-Options': hdrs['X-Frame-Options'],
    'Referrer-Policy': hdrs['Referrer-Policy'],
    'Permissions-Policy': hdrs['Permissions-Policy'],
  };
  document.getElementById('hdrs').innerHTML = kv(hdrView);

  // Ports
  let pHtml = '';
  if(!ports.supported){
    pHtml = `<div style="color:#ffd18a; opacity:.95;">${ports.note || 'Not available on this host.'}</div>`;
  } else {
    const list = (ports.ports||[]).join(', ') || '—';
    pHtml = `<div><b>Method:</b> ${ports.method}</div>
             <div style="margin-top:6px;"><b>Listening ports:</b> ${list}</div>`;
  }
  document.getElementById('ports').innerHTML = pHtml;

  // Paths
  const warnings = paths.warnings || [];
  document.getElementById('paths').innerHTML = warnings.length
    ? warnings.map(w=>`<div style="color:#ffd18a;">• ${w}</div>`).join('')
    : `<div style="color:#a6ffb8;">OK: No obvious risky files detected.</div>`;

  document.getElementById('raw').textContent = JSON.stringify(data, null, 2);
}

function copyJson(){
  if(!LAST) return alert('Run checks first.');
  navigator.clipboard.writeText(JSON.stringify(LAST, null, 2));
  alert('Copied.');
}

run().catch(e=>alert(e.message));
</script>
</body>
</html>
