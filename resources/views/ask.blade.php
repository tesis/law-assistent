<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8">
  <title>AI Pravni Asistent — Ask Demo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* Hide default marker, use our chevron, rotate on open */
    details > summary { list-style: none; }
    details > summary::marker { display: none; }
    summary::-webkit-details-marker { display: none; }
    details > summary .chev { transition: transform .2s ease; }
    details[open] > summary .chev { transform: rotate(180deg); }

    /* Nicer scrollbar for long člén text */
    .overflow-y-auto::-webkit-scrollbar { width: 8px }
    .overflow-y-auto::-webkit-scrollbar-thumb { background: #c7c7c7; border-radius: 6px }
    .overflow-y-auto:hover::-webkit-scrollbar-thumb { background: #a8a8a8 }
  </style>
</head>
<body class="bg-gray-50 text-gray-900">
  <div class="max-w-5xl mx-auto px-4 py-6">
    <h1 class="text-2xl font-semibold mb-4">AI pravni asistent</h1>

    <form id="f" class="mb-6">
      <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3 items-end">
        <input id="q" name="q" placeholder="Vnesi vprašanje (npr. Kateri člen ureja odpoved pogodbe o zaposlitvi?)"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Vprašaj</button>
      </div>
    </form>

    <div id="status" class="hidden mb-4 text-sm text-gray-600">Obdelujem ...</div>

    <!-- LLM odgovor -->
    <section id="answerBox" class="hidden mb-3 p-4 bg-white border rounded-xl shadow-sm">
      <div class="text-sm text-gray-500 mb-1">Odgovor</div>
      <div id="answer" class="prose max-w-none leading-relaxed"></div>
    </section>

    <!-- Enkratni gumbi za 1. citirani zakon -->
    <div id="lawActionsBox" class="hidden mb-6">
      <div class="flex flex-wrap gap-2">
        <a id="openPisrs" href="#" target="_blank" class="px-3 py-2 border rounded bg-white hover:bg-gray-50">Odpri PISRS</a>
        <a id="openAll" href="#" class="px-3 py-2 border rounded bg-white hover:bg-gray-50">Odpri vse člene zakona</a>
      </div>
    </div>

    <!-- Citati — vsak kot accordion s polnim besedilom člena -->
    <section id="citationsBox" class="hidden mb-6">
      <div class="text-sm text-gray-500 mb-2">Citati iz /api/ask</div>
      <div id="citations" class="grid md:grid-cols-2 gap-3"></div>
    </section>

    <!-- Povzetek zakona -->
    <section id="summaryBox" class="hidden mb-6 p-4 bg-white border rounded-xl shadow-sm">
      <div class="flex items-center justify-between mb-2">
        <div class="text-sm text-gray-500">Povzetek zakona</div>
        <div class="flex gap-2">
          <button id="refreshSummary" class="text-xs px-2 py-1 border rounded bg-white hover:bg-gray-50">Osveži</button>
        </div>
      </div>
      <div id="summaryContent" class="prose max-w-none text-sm leading-relaxed whitespace-pre-line"></div>
    </section>

    <!-- Napake -->
    <div id="errorBox" class="hidden p-3 mt-4 bg-red-50 border border-red-200 text-red-800 rounded"></div>
  </div>

<script>
/* ---------- helpers ---------- */
const $ = sel => document.querySelector(sel);
const lawCache = {}; // cache: { [lawId]: items[] } from /api/laws/{lawId}/articles

function escapeHtml(s) {
  const div = document.createElement('div');
  div.textContent = s ?? '';
  return div.innerHTML;
}
function highlight(text, needle) {
  if (!needle) return escapeHtml(text);
  const esc = needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp(esc, 'giu');
  return escapeHtml(text).replace(re, m => `<mark class="px-1 rounded bg-yellow-200">${escapeHtml(m)}</mark>`);
}
function extractLawId(url) {
  try {
    const u = new URL(url, window.location.origin);
    const id = u.searchParams.get('id') || u.searchParams.get('mopedId');
    return (/^[A-Z]{4}\d{4,}$/.test(id||'')) ? id : null;
  } catch { return null; }
}
function extractArticleKey(label) {
  // e.g. "92. člen", "92a. člen", "člen 92"
  const m = String(label||'').match(/(\d{1,4}[a-z]?)/i);
  return m ? m[1].toLowerCase() : null;
}
function splitHeadingBody(text) {
  const t = String(text || '');
  const nl = t.indexOf('\n');
  if (nl >= 0) return { first: t.slice(0, nl), body: t.slice(nl + 1) };
  const m = t.match(/^(.{0,200}?\bčlen\b[^\n]*)(.*)$/iu);
  if (m) return { first: (m[1]||'').trim(), body: (m[2]||'').trim() || t };
  return { first: t, body: t };
}
function keyFrom(str){ const m = String(str||'').toLowerCase().match(/(\d{1,4}[a-z]?)/i); return m ? m[1] : null; }
function findArticle(items, artKey){
  artKey = (artKey||'').toLowerCase();
  let hit = items.find(it => keyFrom(it.citation) === artKey);
  if (hit) return hit;
  hit = items.find(it => keyFrom(it.title) === artKey);
  if (hit) return hit;
  const re = new RegExp(`\\b${artKey}\\b|\\b${artKey}\\.?\\s*člen\\b`, 'i');
  hit = items.find(it => re.test(it.citation || ''));
  if (hit) return hit;
  const reBody = new RegExp(`\\b${artKey}\\b\\s*\\.\\s*člen|\\bčlen\\s*${artKey}\\b`, 'i');
  return items.find(it => reBody.test(it.text || '')) || null;
}

/** Build one citation card as an accordion.
 * If /api/ask gives us c.text, render it immediately (scrollable).
 * Otherwise show a hint and lazy-load on first open from /api/laws/{lawId}/articles.
 */
function renderCitationCard(c, q) {
  const lawId = c.law_id || extractLawId(c.url || '');
  const artKey = extractArticleKey(c.citation || '');
  const title = c.title || 'Vir';
  const label = c.citation || '';
  const hasText = !!(c.text && c.text.length);

  let bodyInner = '';
  if (hasText) {
    const { first, body } = splitHeadingBody(c.text);
    bodyInner = `
      <div class="font-medium mb-2">${highlight(first, q)}</div>
      <div class="whitespace-pre-line max-h-72 overflow-y-auto pr-2">${highlight(body, q)}</div>
    `;
  } else {
    bodyInner = lawId && artKey
      ? '<span class="text-gray-500">Klikni za prikaz besedila člena.</span>'
      : '<span class="text-gray-500">Manjka identifikator člena ali zakona.</span>';
  }

  return `
    <details class="p-3 bg-white border rounded-xl shadow-sm js-citation"
             data-law="${lawId || ''}" data-article="${artKey || ''}">
      <summary class="list-none cursor-pointer flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="font-medium">${escapeHtml(title)}</div>
          <div class="text-sm text-gray-600">${escapeHtml(label)}</div>
        </div>
        <svg class="chev w-5 h-5 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
        </svg>
      </summary>
      <div class="mt-3 text-sm text-gray-800" data-body data-loaded="${hasText ? '1':'0'}">
        ${bodyInner}
      </div>
    </details>
  `;
}

/** Lazy-load člén body from /api/laws/{lawId}/articles if citation had no text */
async function loadCitationBody(detailsEl, needleQ) {
  const lawId = detailsEl.getAttribute('data-law');
  const artKey = detailsEl.getAttribute('data-article');
  const bodyEl = detailsEl.querySelector('[data-body]');
  if (!lawId || !artKey || !bodyEl) return;

  if (bodyEl.getAttribute('data-loaded') === '1') return;
  bodyEl.innerHTML = '<span class="text-gray-500">Nalagam besedilo člena…</span>';

  try {
    let items = lawCache[lawId];
    if (!items) {
      const res = await fetch(`/api/laws/${lawId}/articles`, { headers: { Accept: 'application/json' }});
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const j = await res.json();
      items = Array.isArray(j.items) ? j.items : [];
      lawCache[lawId] = items;
    }
    const hit = findArticle(items, artKey);
    if (!hit) {
      bodyEl.innerHTML = '<span class="text-gray-500">Člena ni bilo mogoče najti v bazi.</span>';
      return;
    }
    const { first, body } = splitHeadingBody(hit.text || '');
    bodyEl.innerHTML = `
      <div class="font-medium mb-2">${highlight(first, needleQ)}</div>
      <div class="whitespace-pre-line max-h-72 overflow-y-auto pr-2">${highlight(body, needleQ)}</div>
    `;
    bodyEl.setAttribute('data-loaded','1');
  } catch (e) {
    console.warn('loadCitationBody failed', e);
    bodyEl.innerHTML = '<span class="text-red-600">Napaka pri nalaganju besedila člena.</span>';
  }
}

/** Povzetek (brez fokusa za zanesljivost) */
async function loadSummaryFor(lawId, focus) {
  try {
    const url = `/api/laws/${lawId}/summary` + (focus ? `?focus=${encodeURIComponent(focus)}` : '');
    const res = await fetch(url, { headers: {Accept:'application/json'} });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const j = await res.json();
    const txt = j.summary || 'Ni povzetka.';
    const el = document.getElementById('summaryContent');
    el.textContent = txt;
    document.getElementById('summaryBox').classList.remove('hidden');

    document.getElementById('refreshSummary').onclick = async () => {
      const ref = `/api/laws/${lawId}/summary` + (focus ? `?focus=${encodeURIComponent(focus)}&refresh=1` : '?refresh=1');
      const rr = await fetch(ref, { headers:{Accept:'application/json'} });
      if (rr.ok) {
        const jj = await rr.json();
        el.textContent = jj.summary || 'Ni povzetka.';
      }
    };
  } catch (e) {
    const el = document.getElementById('summaryContent');
    el.textContent = 'Povzetka ni bilo mogoče naložiti.';
    document.getElementById('summaryBox').classList.remove('hidden');
    console.warn('summary load failed', e);
  }
}

/* ---------- main ---------- */
document.getElementById('f').addEventListener('submit', async (e) => {
  e.preventDefault();
  const q = document.getElementById('q').value.trim();
  if (!q) return;

  // reset UI
  $('#status').classList.remove('hidden');
  $('#answerBox').classList.add('hidden');
  $('#lawActionsBox').classList.add('hidden');
  $('#citationsBox').classList.add('hidden');
  $('#summaryBox').classList.add('hidden');
  $('#errorBox').classList.add('hidden');
  $('#answer').innerHTML = '';
  $('#citations').innerHTML = '';
  $('#summaryContent').innerHTML = '';
  for (const k in lawCache) delete lawCache[k];

  try {
    // 1) LLM odgovor + citati
    const r = await fetch('/api/ask', {
      method: 'POST',
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({ q, mode: 'hybrid' })
    });
    const data = await r.json();

    const ans = (data && data.answer) ? String(data.answer) : '';
    $('#answer').innerHTML = ans ? ans.replace(/\n/g, '<br>') : 'Ni odgovora.';
    $('#answerBox').classList.remove('hidden');

    const cits = Array.isArray(data?.citations) ? data.citations : [];
    if (cits.length) {
      // Render all citation accordions
      $('#citations').innerHTML = cits.map(c => renderCitationCard(c, q)).join('');
      $('#citationsBox').classList.remove('hidden');

      // Attach per-details toggle (toggle does NOT bubble)
      const box = document.getElementById('citations');
      box.querySelectorAll('details.js-citation').forEach((d) => {
        d.addEventListener('toggle', () => { if (d.open) loadCitationBody(d, q); });
      });
      // Also load on summary click (robust across browsers)
      box.querySelectorAll('details.js-citation summary').forEach((s) => {
        s.addEventListener('click', () => {
          const d = s.parentElement;
          setTimeout(() => { if (d.open) loadCitationBody(d, q); }, 0);
        });
      });
    }

    // 2) One-time buttons + summary for first cited law
    let primaryLawId = null, primaryPisrsUrl = null;
    for (const c of cits) {
      const lawId = c.law_id || extractLawId(c.url || '');
      if (lawId) { primaryLawId = lawId; primaryPisrsUrl = c.url || (`https://www.pisrs.si/Pis.web/pregledPredpisa?id=${lawId}`); break; }
    }
    if (primaryLawId) {
      document.getElementById('openPisrs').href = primaryPisrsUrl;
      document.getElementById('openAll').href = `/laws/${primaryLawId}`;
      document.getElementById('lawActionsBox').classList.remove('hidden');
      await loadSummaryFor(primaryLawId, null); // brez fokusa za zanesljivost
    }

  } catch (err) {
    $('#errorBox').textContent = 'Napaka pri poizvedbi: ' + (err?.message || err);
    $('#errorBox').classList.remove('hidden');
  } finally {
    $('#status').classList.add('hidden');
  }
});
</script>
</body>
</html>
