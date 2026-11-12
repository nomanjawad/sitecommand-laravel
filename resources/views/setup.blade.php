@php
  use Illuminate\Support\Carbon;
  $ago = fn ($t) => $t ? Carbon::parse($t)->diffForHumans() : null;
  $daysTo = fn ($t) => $t ? (int) floor((Carbon::parse($t)->getTimestamp() - time()) / 86400) : null;
  $expiry = function ($t) use ($daysTo) {
      $d = $daysTo($t);
      if ($d === null) return [null, ''];
      if ($d < 0) return ['Expired', 'bad'];
      return ['In '.$d.' '.($d === 1 ? 'day' : 'days').' ('.Carbon::parse($t)->format('j M Y').')', $d <= 14 ? 'warn' : 'good'];
  };
  $s = $summary;
  $rows = [];
  if ($s) {
      $h = $s['health'] ?? null;
      if ($h) {
          $rows[] = ['Status', ($h['status'] === 'up' ? 'Online' : $h['label']).($h['ms'] ? ' · '.$h['ms'].' ms' : ''), $h['status'] === 'up' ? 'good' : 'bad'];
      } else {
          $rows[] = ['Status', 'Appears after the first check (within the hour)', ''];
      }
      $u = $s['uptime'] ?? null;
      if ($u && $u['pct30'] !== null) {
          $rows[] = ['Uptime', rtrim(rtrim(number_format($u['pct30'], 2), '0'), '.').'% · last 30 days'.($u['avg_ms7'] ? ' · '.$u['avg_ms7'].' ms average' : ''), $u['pct30'] >= 99 ? 'good' : 'warn', $u['days'] ?? []];
      }
      if (! empty($s['ssl']['problem'])) {
          $rows[] = ['SSL', $s['ssl']['problem'], 'bad'];
      } elseif (! empty($s['ssl']['expires_at'])) {
          [$t, $tone] = $expiry($s['ssl']['expires_at']);
          $rows[] = ['SSL', $t, $tone];
      }
      if (! empty($s['domain']['expires_at'])) {
          [$t, $tone] = $expiry($s['domain']['expires_at']);
          $left = $daysTo($s['domain']['expires_at']);
          $rows[] = ['Domain', $t, $left < 0 ? 'bad' : ($left <= 30 ? 'warn' : 'good')];
      } elseif ($s['domain'] ?? null) {
          $rows[] = ['Domain', 'The registry does not publish the expiry date', ''];
      }
      $deps = $s['dependencies'] ?? ['total' => 0, 'vulnerable' => 0];
      if ($deps['total'] > 0) {
          $rows[] = ['Packages', $deps['vulnerable'] ? $deps['vulnerable'].' of '.$deps['total'].' with known vulnerabilities' : $deps['total'].' packages, no known vulnerabilities', $deps['vulnerable'] ? (in_array($deps['worst'], ['critical', 'high'], true) ? 'bad' : 'warn') : 'good'];
      }
      foreach ($s['problems'] ?? [] as $p) {
          $rows[] = ['Problem', $p['label'].' · since '.$ago($p['since']), 'bad'];
      }
      $rows[] = ['Connector', 'sitecommand/laravel '.($s['site']['connector'] ?? '?').($s['site']['runtime'] ? ' · PHP '.$s['site']['runtime'] : ''), ''];
      $rows[] = ['Verified', $s['site']['verified_at'] ? $ago($s['site']['verified_at']) : 'Not yet', $s['site']['verified_at'] ? 'good' : 'warn'];
  }
  $mark = fn ($tone) => ['good' => '✓', 'warn' => '!', 'bad' => '✗'][$tone] ?? '•';
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
@if ($device)<meta http-equiv="refresh" content="4">@endif
<title>Sitecommand</title>
<style>
  :root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --text:#0d2547; --muted:#5b6b80; --line:#dde3ea; --accent:#2f6bff; --accent-text:#fff; --good:#15803d; --bad:#b91c1c; --warn:#a16207; --shade:rgba(13,37,71,.45); }
  @media (prefers-color-scheme: dark) { :root { --bg:#0b1726; --card:#10213a; --text:#e8eef5; --muted:#94a3b8; --line:#1e3352; --accent:#5b8cff; --accent-text:#04102a; --good:#4ade80; --bad:#f87171; --warn:#facc15; --shade:rgba(0,0,0,.6); } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; }
  main { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
  header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
  h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 16px; margin: 0 0 12px; }
  p { margin: 0 0 12px; } .muted { color: var(--muted); font-size: 13px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-top:16px; }
  ul.list { list-style:none; margin:0; padding:0; } ul.list li { display:flex; gap:10px; padding:9px 0; border-top:1px solid var(--line); align-items:flex-start; } ul.list li:first-child { border-top:0; }
  .mark { width:18px; flex:none; font-weight:700; text-align:center; } .good { color:var(--good); } .bad { color:var(--bad); } .warn { color:var(--warn); } .info { color:var(--muted); }
  .label { width:110px; flex:none; font-weight:600; } .detail { color:var(--muted); word-break: break-word; flex:1; }
  .fix { display:block; color:var(--warn); font-size:13px; }
  .bars { display:flex; gap:2px; margin-top:6px; height:18px; align-items:stretch; }
  .bars span { flex:1; border-radius:2px; background:var(--line); } .bars .up { background:var(--good); } .bars .part { background:var(--warn); } .bars .down { background:var(--bad); }
  .btn { display:inline-block; background:var(--accent); color:var(--accent-text); border:0; border-radius:999px; padding:10px 18px; font-weight:600; font-size:14px; cursor:pointer; text-decoration:none; }
  .btn.ghost { background:transparent; color:var(--text); border:1px solid var(--line); }
  .btn.big { padding:13px 24px; font-size:15px; }
  .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:12px; }
  .flash { border-radius:10px; padding:12px 14px; margin-top:16px; border:1px solid var(--line); background:var(--card); }
  .flash.good { border-color: var(--good); } .flash.bad { border-color: var(--bad); }
  pre { background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:12px; overflow:auto; font-size:13px; }
  details summary { cursor:pointer; color:var(--muted); font-size:13px; }
  a { color: var(--accent); }
  .pill { display:inline-block; font-size:12px; font-weight:600; border-radius:999px; padding:3px 10px; border:1px solid currentColor; }
  .overlay { position:fixed; inset:0; background:var(--shade); display:flex; align-items:center; justify-content:center; padding:16px; z-index:10; }
  .popup { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:28px 24px; max-width:420px; width:100%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,.25); }
  .popup .tick { width:56px; height:56px; border-radius:50%; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; font-size:30px; font-weight:700; color:#fff; background:var(--good); }
  .popup h2 { font-size:20px; margin:0 0 6px; }
  .popup .row { justify-content:center; }
</style>
</head>
<body>
<main>
  <header>
    <div>
      <h1>Sitecommand</h1>
      <p class="muted">{{ $siteUrl ?? config('app.url') }} · only you can see this page.</p>
    </div>
    @if ($connected)
      <span class="pill good">Connected</span>
    @endif
  </header>

  @if ($flash)
    <div class="flash {{ $flash['tone'] }}">{{ $flash['message'] }}</div>
  @endif

  @if ($lines)
    <div class="card">
      <h2>Add these lines to .env</h2>
      <p class="muted">Shown once. Open the site's .env in your host's file manager, add them at the end, save, then press Verify.</p>
<pre>@foreach ($lines as $k => $v){{ $k }}={{ $v }}
@endforeach</pre>
    </div>
  @endif

  @if ($device)
    <div class="card">
      <h2>Waiting for your approval in Sitecommand</h2>
      <p>Approve the connection on <a href="{{ $device['link'] }}&amp;return={{ rawurlencode(($siteUrl ?? '').$base.'/callback') }}">Sitecommand</a>. This page finishes by itself once you have.</p>
      <form method="post" action="{{ $base }}/cancel">@csrf<button class="btn ghost" type="submit">Cancel</button></form>
    </div>
  @elseif (! $connected)
    <div class="card">
      <h2>Connect this site</h2>
      <p class="muted">You sign in to Sitecommand and approve the connection; you come straight back here and the site saves its secret and verifies itself. Sitecommand then monitors uptime, SSL, the domain, errors and packages.</p>
      <form method="post" action="{{ $base }}/connect">@csrf<div class="row"><button class="btn big" type="submit">Connect with Sitecommand</button></div></form>
    </div>
  @else
    <div class="card">
      <h2>Site status</h2>
      @if ($summary)
        <ul class="list">
          @foreach ($rows as $r)
            <li>
              <span class="mark {{ $r[2] ?: 'info' }}">{{ $mark($r[2]) }}</span>
              <span class="label">{{ $r[0] }}</span>
              <span class="detail">{{ $r[1] }}
                @if (! empty($r[3]))
                  <span class="bars" aria-hidden="true">@foreach ($r[3] as $d)<span class="{{ $d['pct'] === null ? '' : ($d['pct'] >= 99.5 ? 'up' : ($d['pct'] >= 95 ? 'part' : 'down')) }}" title="{{ $d['day'] }}: {{ $d['pct'] === null ? 'no data' : $d['pct'].'%' }}"></span>@endforeach</span>
                @endif
              </span>
            </li>
          @endforeach
        </ul>
        @if (! empty($summary['dependencies']['top']))
          <details style="margin-top:10px"><summary>Packages with known vulnerabilities</summary>
            <ul class="list" style="margin-top:6px">
              @foreach ($summary['dependencies']['top'] as $d)
                <li><span class="mark {{ in_array($d['severity'], ['critical', 'high'], true) ? 'bad' : 'warn' }}">!</span><span class="label" style="width:auto;flex:1">{{ $d['name'] }} {{ $d['version'] }}</span><span class="detail" style="flex:none">{{ $d['count'] }} · {{ $d['severity'] }}</span></li>
              @endforeach
            </ul>
          </details>
        @endif
        <p class="muted" style="margin:12px 0 0">
          @if (! empty($summary['health']['checked_at']))Checked {{ $ago($summary['health']['checked_at']) }}.@endif
          @if (! empty($summary['site']['dashboard_url'])) <a href="{{ $summary['site']['dashboard_url'] }}" target="_blank" rel="noopener">Open in Sitecommand</a>@endif
        </p>
      @else
        <p class="bad">{{ $summaryError }}</p>
      @endif
    </div>

    <div class="card">
      <h2>Connection</h2>
      <ul class="list">
        <li><span class="label">Sitecommand</span><span class="detail">{{ implode(', then ', $hubs) }}</span></li>
        <li><span class="label">Site id</span><span class="detail">{{ $siteId }}</span></li>
        <li><span class="label">Secret</span><span class="detail">Saved in .env (…{{ $secretTail }})</span></li>
      </ul>
      <div class="row">
        <form method="post" action="{{ $base }}/verify">@csrf<button class="btn" type="submit">Verify again</button></form>
        <form method="post" action="{{ $base }}/connect">@csrf<button class="btn ghost" type="submit" title="Replaces the secret">Reconnect</button></form>
      </div>
      @if ($report)
        <ul class="list" style="margin-top:12px">
          @foreach ($report['steps'] as $st)
            <li>
              <span class="mark {{ $st['ok'] ? 'good' : 'bad' }}">{{ $st['ok'] ? '✓' : '✗' }}</span>
              <span class="label">{{ $st['label'] }}</span>
              <span class="detail">{{ $st['detail'] }}@if (! $st['ok'] && ! empty($st['fix']))<span class="fix">{{ $st['fix'] }}</span>@endif</span>
            </li>
          @endforeach
        </ul>
        <p class="bad" style="margin-top:10px;font-weight:600">Not verified yet: fix the items marked ✗ and verify again.</p>
      @endif
    </div>
  @endif

  @if (! $connected && $report)
    <div class="card">
      <h2>Verify</h2>
      <ul class="list">
        @foreach ($report['steps'] as $st)
          <li>
            <span class="mark {{ $st['ok'] ? 'good' : 'bad' }}">{{ $st['ok'] ? '✓' : '✗' }}</span>
            <span class="label">{{ $st['label'] }}</span>
            <span class="detail">{{ $st['detail'] }}@if (! $st['ok'] && ! empty($st['fix']))<span class="fix">{{ $st['fix'] }}</span>@endif</span>
          </li>
        @endforeach
      </ul>
      <form method="post" action="{{ $base }}/verify">@csrf<div class="row"><button class="btn" type="submit">Verify now</button></div></form>
    </div>
  @endif

  <div class="card">
    <details>
      <summary>Setup details</summary>
      <ul class="list" style="margin-top:8px">
        @foreach ($status as $st)
          <li>
            <span class="mark {{ $st['ok'] === true ? 'good' : ($st['ok'] === false ? 'bad' : 'info') }}">{{ $st['ok'] === true ? '✓' : ($st['ok'] === false ? '✗' : '•') }}</span>
            <span class="label">{{ $st['label'] }}</span>
            <span class="detail">{{ $st['detail'] }}</span>
          </li>
        @endforeach
      </ul>
      @if (! $connected && $lines === null)
        <form method="post" action="{{ $base }}/verify" style="margin-top:8px">@csrf<p class="muted">Pasted the SITECOMMAND_ lines into .env by hand? <button class="btn ghost" type="submit">Verify now</button></p></form>
      @endif
    </details>
  </div>
</main>

@if ($popup)
  <div class="overlay" role="dialog" aria-modal="true" aria-labelledby="sc-popup-title">
    <div class="popup">
      <div class="tick" aria-hidden="true">✓</div>
      <h2 id="sc-popup-title">{{ $popup['title'] }}</h2>
      <p class="muted">{{ $popup['message'] }}</p>
      <div class="row">
        <a class="btn" href="/">Back to the website</a>
        <a class="btn ghost" href="{{ $base }}">See the status</a>
      </div>
    </div>
  </div>
@endif
</body>
</html>
