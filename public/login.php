<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';

if (!empty($_SESSION['logged_in'])) {
    header('Location: ' . url('public/dashboard.php'));
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $erro = 'Token de segurança inválido. Tente novamente.';
    } elseif (($guard = loginGuardStatus(trim($_POST['username'] ?? ''))) && !empty($guard['blocked'])) {
        $mins = max(1, (int)ceil(($guard['seconds_left'] ?? 0) / 60));
        auditLog('LOGIN_BLOCKED', 'auth', 'Bloqueio ativo para ' . trim($_POST['username'] ?? ''));
        $erro = 'Muitas tentativas inválidas. Aguarde ' . $mins . ' minuto(s) e tente novamente.';
    } elseif (Auth::login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        auditLog('LOGIN_OK', 'auth', 'Usuário: ' . trim($_POST['username'] ?? ''));
        header('Location: ' . url('public/dashboard.php'));
        exit;
    } else {
        auditLog('LOGIN_FAIL', 'auth', 'Tentativa falha: ' . trim($_POST['username'] ?? ''));
        $erro = 'Usuário ou senha incorretos.';
    }
}
$appName = defined('APP_NAME') ? APP_NAME : 'Tonch';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($appName) ?> | Autenticação</title>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><polygon points='16,2 30,28 2,28' fill='%230066cc'/><line x1='16' y1='2' x2='16' y2='28' stroke='%23fff' stroke-width='1.2' opacity='0.5'/><line x1='2' y1='28' x2='30' y2='28' stroke='%23fff' stroke-width='1.2' opacity='0.5'/><line x1='9' y1='14' x2='23' y2='14' stroke='%23fff' stroke-width='1.2' opacity='0.5'/></svg>">
</head>
<body>
<div class="login-page">
  <div class="login-box">
    <div class="login-head">
      <div style="margin-bottom:6px;"></div>
      <h1><?= h($appName) ?></h1>
      <p>Gestão Financeira</p>
    </div>
    <div class="login-body">
      <?php if ($erro): ?>
        <div class="alert alert-error" style="margin-bottom:12px;">&#10007; <?= h($erro) ?></div>
      <?php endif; ?>
      <?php $flash = getFlash(); if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>" style="margin-bottom:12px;"><?= h($flash['msg']) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="fg mb-12">
          <label for="username">Usuário</label>
          <input type="text" id="username" name="username"
                 value="<?= h($_POST['username'] ?? '') ?>" autofocus autocomplete="username">
        </div>
        <div class="fg mb-12">
          <label for="password">Senha</label>
          <input type="password" id="password" name="password" autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;padding:8px;font-size:13px;">
          Entrar no Sistema
        </button>
      </form>
</div>
    <div class="login-foot">▲ <?= h($appName) ?></div>
  </div>
</div>
</body>
</html>
