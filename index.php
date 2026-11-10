<?php
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#111512">
  <meta name="description" content="Acompanhe issues, evidências e testes da integração Mudlet–ChatGPT via MCP.">
  <title>Mudlet · Control Room</title>
  <link rel="stylesheet" href="assets/app.css">
  <script src="assets/app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Ir para conteúdo</a>
<div id="app"><div class="loading"><span class="brand-mark">m<span>_</span></span><p>Preparando o Control Room…</p></div></div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<dialog id="modal"><div id="modal-content"></div></dialog>
</body>
</html>
