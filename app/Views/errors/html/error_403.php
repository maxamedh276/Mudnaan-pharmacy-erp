<?php
// Keep permission errors independent of database-backed layouts and never expose exception details.
$dashboardUrl = rtrim(config(\Config\App::class)->baseURL, '/') . '/dashboard';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Access denied — BOOKSAFE</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f8fafc; color: #0f172a; font-family: Arial, sans-serif; }
        main { width: 100%; max-width: 520px; padding: 40px 32px; border: 1px solid #e2e8f0; border-radius: 20px; background: #fff; text-align: center; box-shadow: 0 12px 36px rgb(15 23 42 / 5%); }
        .brand { margin: 0 0 32px; color: #314f78; font-size: 14px; font-weight: 700; letter-spacing: .08em; }
        .icon { display: grid; place-items: center; width: 64px; height: 64px; margin: 0 auto 24px; border-radius: 16px; color: #314f78; background: #eef3f9; }
        .code { margin: 0 0 10px; color: #64748b; font-size: 13px; font-weight: 700; letter-spacing: .1em; }
        h1 { margin: 0 0 16px; font-size: 30px; line-height: 1.2; }
        .description { margin: 0 auto; max-width: 370px; color: #475569; font-size: 16px; line-height: 1.65; }
        a { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; margin-top: 28px; padding: 12px 22px; border-radius: 10px; background: #314f78; color: #fff; font-size: 14px; font-weight: 700; text-decoration: none; }
        a:hover { background: #243c5c; }
        a:focus-visible { outline: 3px solid #1967d2; outline-offset: 4px; }
        .help { margin: 28px 0 0; color: #64748b; font-size: 13px; line-height: 1.6; }
        @media (max-width: 480px) { main { padding: 32px 20px; } h1 { font-size: 26px; } a { width: 100%; } }
    </style>
</head>
<body>
    <main aria-labelledby="deniedTitle">
        <p class="brand">BOOKSAFE</p>
        <div class="icon" aria-hidden="true">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>
        </div>
        <p class="code">403</p>
        <h1 id="deniedTitle">Access denied</h1>
        <p class="description">Your account doesn’t have permission to open this page.</p>
        <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>">Back to dashboard</a>
        <p class="help">If you need access, contact your Superadmin.</p>
        <?php require APPPATH . 'Views/partials/copyright.php'; ?>
    </main>
</body>
</html>
