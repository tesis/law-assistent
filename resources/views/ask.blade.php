<!doctype html><html><head><meta charset="utf-8"><title>AI Pravni Asistent</title></head>
<body style="max-width:720px;margin:2rem auto;font-family:sans-serif">
  <h1>AI pravni asistent</h1>
  <form id="f">
    <input id="q" style="width:100%;padding:.6rem" placeholder="Vnesi vprašanje..." />
    <button style="margin-top:.5rem">Vprašaj</button>
  </form>
  <pre id="out" style="white-space:pre-wrap;margin-top:1rem"></pre>
<script>
document.getElementById('f').addEventListener('submit', async e=>{
  e.preventDefault();
  const q = document.getElementById('q').value;
  const r = await fetch('/api/ask',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({q,mode:'hybrid'})});
  document.getElementById('out').textContent = JSON.stringify(await r.json(), null, 2);
});
</script>
</body></html>

