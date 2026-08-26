<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#17191e">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title>{{ $copy['title'] }} — LOOKDO</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#f8f8f9;background:#17191e}*{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 72% 28%,rgba(255,107,0,.18),transparent 32%),linear-gradient(145deg,#23262c,#101216)}main{width:min(720px,100%);padding:clamp(34px,7vw,72px);border:1px solid #3d424b;border-radius:28px;background:rgba(31,34,40,.92);box-shadow:0 35px 100px rgba(0,0,0,.35)}.brand{display:flex;align-items:center;margin-bottom:74px}.brand img{width:145px;height:auto}.brand small{margin-left:12px;color:#9298a2;font-size:9px;letter-spacing:.2em}.eyebrow{color:#ff6b00;font-size:11px;font-weight:900;letter-spacing:.2em}h1{max-width:560px;margin:18px 0;font-size:clamp(42px,8vw,70px);line-height:.98;letter-spacing:-.055em}p{max-width:560px;margin:0;color:#bec3cb;font-size:18px;line-height:1.65}.note{margin-top:34px;padding-top:22px;border-top:1px solid #3b4048;color:#8f96a1;font-size:13px}@media(max-width:600px){main{padding:30px 24px;border-radius:20px}.brand{margin-bottom:55px}p{font-size:16px}}
    </style>
</head>
<body>
<main>
    <div class="brand"><img src="/brand/lookdo-logo.png" alt="LOOKDO"><small>LOOK. DO.</small></div>
    <div class="eyebrow">{{ $copy['eyebrow'] }}</div>
    <h1>{{ $copy['title'] }}</h1>
    <p>{{ $copy['text'] }}</p>
    <p class="note">{{ $copy['note'] }}</p>
</main>
</body>
</html>