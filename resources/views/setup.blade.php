<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
@if ($device)<meta http-equiv="refresh" content="3">@endif
<title>Sitecommand connector</title>
<style>
  :root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --text:#0d2547; --muted:#5b6b80; --line:#dde3ea; --accent:#0bbfd9; --good:#15803d; --bad:#b91c1c; --warn:#a16207; }
  @media (prefers-color-scheme: dark) { :root { --bg:#0b1726; --card:#10213a; --text:#e8eef5; --muted:#94a3b8; --line:#1e3352; --good:#4ade80; --bad:#f87171; --warn:#facc15; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; }
  main { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
  h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 16px; margin: 0 0 12px; }
  p { margin: 0 0 12px; } .muted { color: var(--muted); font-size: 13px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-top:16px; }
  ul.list { list-style:none; margin:0; padding:0; } ul.list li { display:flex; gap:10px; padding:8px 0; border-top:1px solid var(--line); } ul.list li:first-child { border-top:0; }
  .mark { width:20px; flex:none; font-weight:700; } .good { color:var(--good); } .bad { color:var(--bad); } .info { color:var(--muted); }
  .label { width:120px; flex:none; font-weight:600; } .detail { color:var(--muted); word-break: break-word; }
  .fix { display:block; color:var(--warn); font-size:13px; }
  button { background:var(--accent); color:#04202a; border:0; border-radius:8px; padding:9px 16px; font-weight:600; font-size:14px; cursor:pointer; }
  button.ghost { background:transparent; color:var(--text); border:1px solid var(--line); }
  input[type=url] { width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:8px; background:transparent; color:var(--text); font-size:14px; }
  .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px; }
  .flash { border-radius:10px; padding:12px 14px; margin-top:16px; border:1px solid var(--line); }
  .flash.good { border-color: var(--good); } .flash.bad { border-color: var(--bad); }
  .code { font: 700 28px/1.2 ui-monospace, Menlo, monospace; letter-spacing: 2px; margin: 8px 0; }
  pre { background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:12px; overflow:auto; font-size:13px; }
  a { color: var(--accent); }
</style>
</head>
<body>
<main>
  <h1>Sitecommand connector</h1>
  <p class="muted">Connect this site to Sitecommand and check the connection, without a terminal. Only you can see this page.</p>

  @if ($flash)
    <div class="flash {{ $flash['tone'] }}">{{ $flash['message'] }}</div>
  @endif

  @if ($lines)
    <div class="card">
      <h2>Add these lines to .env</h2>
      <p class="muted">Shown once. Open the site's .env in your host's file manager, add them at the end, save, then press Verify below.</p>
<pre>@foreach ($lines as $k => $v){{ $k }}={{ $v }}
@endforeach</pre>
    </div>
  @endif

  <div class="card">
    <h2>Status</h2>
    <ul class="list">
      @foreach ($status as $s)
        <li>
          <span class="mark {{ $s['ok'] === true ? 'good' : ($s['ok'] === false ? 'bad' : 'info') }}">{{ $s['ok'] === true ? '✓' : ($s['ok'] === false ? '✗' : '•') }}</span>
          <span class="label">{{ $s['label'] }}</span>
          <span class="detail">{{ $s['detail'] }}</span>
        </li>
      @endforeach
    </ul>
  </div>

  @if ($device)
    <div class="card">
      <h2>Approve in Sitecommand</h2>
      <p>Open <a href="{{ $device['link'] }}" target="_blank" rel="noopener">{{ $device['link'] }}</a>, sign in if asked, and approve this code:</p>
      <div class="code">{{ $device['user_code'] }}</div>
      <p class="muted">Connecting {{ $device['url'] }}. This page checks again every few seconds.</p>
      <form method="post" action="{{ $base }}/cancel">@csrf<button class="ghost" type="submit">Cancel</button></form>
    </div>
  @else
    <div class="card">
      <h2>Connect</h2>
      <p class="muted">Gets a code from Sitecommand; you approve it in the dashboard and the secret is saved in this site's .env. Connecting again replaces the secret.</p>
      <form method="post" action="{{ $base }}/connect">
        @csrf
        <input type="url" name="url" value="{{ $defaultUrl }}" required aria-label="Site address">
        <div class="row"><button type="submit">Connect with Sitecommand</button></div>
      </form>
    </div>
  @endif

  <div class="card">
    <h2>Verify</h2>
    <p class="muted">Asks Sitecommand to call this site from the outside, exactly as it will every day, and shows what it found. Use it after connecting and whenever you want to recheck.</p>
    <form method="post" action="{{ $base }}/verify">@csrf<div class="row"><button type="submit">Verify now</button></div></form>
    @if ($report)
      <ul class="list" style="margin-top:12px">
        @foreach ($report['steps'] as $s)
          <li>
            <span class="mark {{ $s['ok'] ? 'good' : 'bad' }}">{{ $s['ok'] ? '✓' : '✗' }}</span>
            <span class="label">{{ $s['label'] }}</span>
            <span class="detail">{{ $s['detail'] }}@if (! $s['ok'] && ! empty($s['fix']))<span class="fix">{{ $s['fix'] }}</span>@endif</span>
          </li>
        @endforeach
      </ul>
      <p class="{{ ($report['ok'] ?? false) ? 'good' : 'bad' }}" style="margin-top:10px;font-weight:600">{{ ($report['ok'] ?? false) ? 'Verified: the site shows as connected in Sitecommand.' : 'Not verified yet: fix the items marked ✗ and verify again.' }}</p>
    @endif
  </div>
</main>
</body>
</html>
