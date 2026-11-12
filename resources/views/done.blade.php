<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sitecommand connector</title>
<style>
  :root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --text:#0d2547; --muted:#5b6b80; --line:#dde3ea; --good:#15803d; --bad:#b91c1c; --warn:#a16207; }
  @media (prefers-color-scheme: dark) { :root { --bg:#0b1726; --card:#10213a; --text:#e8eef5; --muted:#94a3b8; --line:#1e3352; --good:#4ade80; --bad:#f87171; --warn:#facc15; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; }
  main { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
  h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 16px; margin: 0 0 12px; }
  p { margin: 0 0 12px; } .muted { color: var(--muted); font-size: 13px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-top:16px; }
  ul.list { list-style:none; margin:0; padding:0; } ul.list li { display:flex; gap:10px; padding:8px 0; border-top:1px solid var(--line); } ul.list li:first-child { border-top:0; }
  .mark { width:20px; flex:none; font-weight:700; } .good { color:var(--good); } .warn { color:var(--warn); }
  .label { width:120px; flex:none; font-weight:600; } .detail { color:var(--muted); word-break: break-word; }
  pre { background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:12px; overflow:auto; font-size:13px; }
</style>
</head>
<body>
<main>
  <h1>Sitecommand connector</h1>
  <div class="card">
    <h2 class="good">Verified: the site shows as connected in Sitecommand.</h2>
    <ul class="list">
      @foreach ($report['steps'] as $s)
        <li>
          <span class="mark good">✓</span>
          <span class="label">{{ $s['label'] }}</span>
          <span class="detail">{{ $s['detail'] }}</span>
        </li>
      @endforeach
    </ul>
  </div>
  <div class="card">
    @if ($written)
      <h2>This page is now switched off</h2>
      <p>Its job is done, so {{ $base }} answers "not found" from now on, for everyone. Sitecommand keeps monitoring the site.</p>
      <p class="muted">To open it again (to reconnect), delete the line <code>SITECOMMAND_SETUP=false</code> from the site's .env.</p>
    @else
      <h2 class="warn">Switch this page off</h2>
      <p>The page could not write .env here. Add this line to the site's .env (file manager) so {{ $base }} answers "not found" from now on:</p>
<pre>SITECOMMAND_SETUP=false</pre>
    @endif
  </div>
</main>
</body>
</html>
