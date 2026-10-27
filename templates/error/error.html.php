<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Error <?= $statusCode ?> — ARCHE</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #262626; font-family: Arial, sans-serif; }
        main { max-width: 1000px; margin: auto; padding: clamp(40px, 8vh, 100px) 24px 48px; text-align: center; }
        .box { display: block; width: min(280px, 65vw); height: auto; margin: 0 auto 24px; }
        h1 { font-size: clamp(44px, 7vw, 68px); font-weight: 400; margin: 0 0 16px; }
        .description, .apology { color: #595959; font-size: clamp(18px, 2.5vw, 24px); line-height: 1.5; }
        .description { max-width: 760px; margin: 0 auto; }
        .apology { margin: 24px 0; }
        details { max-width: 760px; margin: 32px auto; border: 1px solid #d5d5d5; border-radius: 8px; text-align: left; }
        summary { padding: 18px 24px; cursor: pointer; font-weight: 600; }
        summary:hover { background: #f7f7f7; border-radius: 8px; }
        .error-message { padding: 0 24px 20px; }
        .status { color: #666; font-size: 14px; }
        pre { margin-bottom: 0; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.6; font-size: 14px; }
        footer { display: flex; align-items: center; justify-content: space-between; gap: 32px; max-width: 760px; margin: 64px auto 0; text-align: left; }
        .brand { font-size: 36px; letter-spacing: 4px; }
        .brand span { color: #35818d; }
        .brand small { display: block; margin-top: 10px; font-size: 14px; letter-spacing: 0; color: #595959; }
        .actions { display: grid; gap: 12px; min-width: 250px; }
        .actions a { display: block; padding: 15px 20px; border: 2px solid #262626; text-align: center; color: inherit; text-decoration: none; font-weight: 600; }
        .actions a:hover { background: #262626; color: #fff; }
        :focus-visible { outline: 3px solid #35818d; outline-offset: 4px; }
        @media (max-width: 600px) { footer { flex-direction: column; text-align: center; margin-top: 40px; } .actions { width: 100%; } }
    </style>
</head>
<body>
<main>
    <svg class="box" viewBox="0 0 320 260" aria-hidden="true">
        <path fill="#9d7d5e" d="M65 85 213 60 267 118 105 153Z"/>
        <path fill="#bd9c7c" d="m65 85 40 68 6 100-48-81Z"/>
        <path fill="#b18c67" d="m105 153 162-35-5 96-151 39Z"/>
        <path fill="#e1c9b0" d="m65 85-20-54L191 8l22 52Z"/>
        <path fill="#d4b596" d="m213 60 40-51 57 47-43 62Z"/>
        <path fill="#d6bda3" d="m65 85-52-29 34 61 58 36Z"/>
        <path fill="#e7cdb2" d="m105 153 162-35 30 4-169 40Z"/>
        <text x="184" y="211" text-anchor="middle" fill="#795b40" font-family="Arial, sans-serif" font-size="46" font-weight="700" transform="rotate(-12 184 211)"><?= $statusCode ?></text>
    </svg>
    <h1>Error</h1>
    <p class="description"><?= $escape($description) ?></p>
    <p class="apology">We apologize for the inconvenience.</p>
    <details>
        <summary>Error details</summary>
        <div class="error-message">
            <p class="status">HTTP <?= $statusCode ?> — <?= $escape($statusText) ?></p>
            <pre><?= $escape($message) ?></pre>
        </div>
    </details>
    <footer>
        <div class="brand">ARC<span>HE</span><small>A Resource Centre for the HumanitiEs</small></div>
        <nav class="actions" aria-label="Helpful links">
            <a href="https://arche.acdh.oeaw.ac.at/">VISIT ARCHE WEBSITE</a>
        </nav>
    </footer>
</main>
</body>
</html>
