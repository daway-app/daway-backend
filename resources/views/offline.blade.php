<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دواي — غير متصل</title>
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#0B8FAC">
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F5FAF9;font-family:'Cairo',system-ui,sans-serif;color:#0C2224;}
        .offline-box{background:#fff;border:1px solid #EEF4F3;border-radius:16px;box-shadow:0 1px 3px rgba(12,34,36,.06);padding:48px 40px;max-width:460px;text-align:center;}
        .offline-box .icon{width:64px;height:64px;border-radius:9999px;background:#EAF5F4;color:#0B8FAC;display:grid;place-items:center;font-size:1.6rem;margin:0 auto 18px;}
        .offline-box h1{font-size:1.3rem;margin:0 0 10px;}
        .offline-box p{color:#4C6669;font-size:.95rem;line-height:1.8;margin:0 0 24px;}
        .offline-box button{background:#0B8FAC;color:#fff;border:none;border-radius:12px;padding:12px 28px;font-weight:600;font-size:.95rem;cursor:pointer;font-family:inherit;}
        .offline-box button:hover{background:#00657A;}
    </style>
</head>
<body>
    <div class="offline-box">
        <div class="icon">&#x1F50C;</div>
        <h1>أنت غير متصل بالإنترنت</h1>
        <p>الصفحة المطلوبة غير متوفرة بدون اتصال. التغييرات التي أجريتها محفوظة وستتم مزامنتها تلقائياً.</p>
        <button type="button" onclick="location.reload()">إعادة المحاولة</button>
    </div>
</body>
</html>
