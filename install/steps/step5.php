<?php
/** Etapa 5 — Instalação concluída */
$_SESSION['install_step'] = 5;

$data     = $_SESSION['install_data'] ?? [];
$username = $data['admin']['username'] ?? 'admin';
$appName  = $data['admin']['app_name'] ?? 'FinApp';

// Detecta URL do sistema
$scheme  = isset($_SERVER['HTTPS']) ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')), '/');
$loginUrl = $scheme . '://' . $host . $baseUrl . '/public/login.php';
?>

<div class="success-box">
  <div class="si-icon">✓</div>
  <div class="si-title"><?= htmlspecialchars($appName) ?> instalado com sucesso!</div>
  <p class="si-sub">O sistema está pronto para uso. Guarde suas credenciais com segurança.</p>

  <div class="cred">
    <div style="margin-bottom:8px;"><span>URL de acesso:</span></div>
    <div style="word-break:break-all;color:#fff;margin-bottom:12px;"><?= htmlspecialchars($loginUrl) ?></div>
    <div><span>Usuário:&nbsp;&nbsp;</span><?= htmlspecialchars($username) ?></div>
    <div style="margin-top:4px;"><span>Senha:&nbsp;&nbsp;&nbsp;&nbsp;</span>a definida na etapa anterior</div>
  </div>

  <div class="alert aw" style="text-align:left;">
    <strong>Importante por segurança:</strong>
    <ul style="margin:6px 0 0 16px;font-size:12px;">
      <li>Acesse o sistema e configure o <strong>Marco Zero</strong> em Cadastros → Config. Sistema</li>
      <li>A pasta <code>/install</code> foi <strong>bloqueada automaticamente</strong></li>
      <li>Exclua ou renomeie a pasta <code>/install</code> por segurança adicional</li>
      <li>Verifique as permissões da pasta <code>/database</code> (755)</li>
    </ul>
  </div>

  <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn bs" style="font-size:15px;padding:10px 32px;">
    Acessar o Sistema ›
  </a>
</div>
