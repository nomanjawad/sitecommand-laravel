<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connecting to Sitecommand…</title>
<noscript><meta http-equiv="refresh" content="0;url={{ $next }}"></noscript>
{{-- The same page the WordPress plugin shows while it pairs (class-admin-page.php render_connect_progress):
     the bar picks up where the hub's bar left off and eases towards 95 %; the steps light up on a timer.
     The browser keeps showing it while the next request saves the secret and verifies. --}}
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d2547;color:#f4f7fb;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:1.5rem;box-sizing:border-box}
  .card{width:100%;max-width:30rem;background:#132e57;border:1px solid #27477a;border-radius:10px;padding:2rem}
  .brand{display:flex;align-items:center;gap:.5rem;font-weight:700;font-size:1.05rem;margin-bottom:1.5rem}
  h1{font-size:1.3rem;margin:0 0 .4rem}p{margin:0;color:#a9b8d0;font-size:.9rem;line-height:1.5}
  .track{position:relative;height:10px;border-radius:999px;background:#1a3966;overflow:hidden;margin:1.5rem 0 1.25rem}
  .fill{position:relative;height:100%;width:40%;border-radius:999px;background:#0bbfd9;overflow:hidden;animation:fill 45s cubic-bezier(.1,.6,.2,1) forwards}
  .fill:after{content:"";position:absolute;inset:0;width:35%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent);animation:sweep 1.6s ease-in-out infinite}
  @keyframes fill{from{width:40%}to{width:95%}}@keyframes sweep{from{transform:translateX(-100%)}to{transform:translateX(300%)}}
  ol{list-style:none;margin:0;padding:0}li{display:flex;align-items:center;gap:.7rem;font-size:.9rem;padding:.3rem 0;color:#a9b8d0;opacity:.45;animation:on .4s ease forwards}
  li .dot{width:.6rem;height:.6rem;border-radius:50%;border:2px solid #0bbfd9;flex:none}@keyframes on{to{opacity:1;color:#f4f7fb}}
  @media (prefers-reduced-motion:reduce){.fill,.fill:after,li{animation:none}.fill{width:70%}li{opacity:1}}
</style>
</head>
<body>
<main class="card" role="status" aria-live="polite">
  <div class="brand"><svg viewBox="0 0 108 138" width="18" height="23" aria-hidden="true"><path fill="#0bbfd9" d="M54 0 108 31v20L30 96l24 14 47-27 7 4v20l-54 31L0 107V87l78-45-24-14L7 55l-7-4V31z"/></svg>Sitecommand</div>
  <h1>Finishing the connection…</h1>
  <p>Sitecommand is verifying this site. This usually takes a few seconds and at most a minute. Keep this tab open; you will be taken back to the Sitecommand page when it is done.</p>
  <div class="track" role="progressbar" aria-label="Connection progress"><div class="fill"></div></div>
  <ol>
    <li style="animation-delay:0s"><span class="dot"></span>Approved in Sitecommand</li>
    <li style="animation-delay:1s"><span class="dot"></span>Collecting this site's secret</li>
    <li style="animation-delay:3s"><span class="dot"></span>Saving it in .env</li>
    <li style="animation-delay:5s"><span class="dot"></span>Sitecommand is calling this site back to verify it</li>
    <li style="animation-delay:18s"><span class="dot"></span>Finishing the connection</li>
  </ol>
</main>
<script>setTimeout(function () { window.location.replace(@json($next)); }, 300);</script>
</body>
</html>
