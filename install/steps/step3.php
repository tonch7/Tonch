<?php
/** Etapa 3 — Configuração do Banco de Dados SQLite */
$_SESSION['install_step'] = 3;

$dbPath    = ROOT_PATH . '/database/finapp.db';
$dbExists  = file_exists($dbPath);
$schemaPath = ROOT_PATH . '/database/schema.sql';
$status    = $_SESSION['install_data']['db_status'] ?? null;
?>

<h2 class="heading">Configuração do Banco de Dados</h2>
<p class="sub">O sistema usa SQLite — não precisa de servidor de banco de dados separado.</p>

<div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
    <strong style="font-size:13px;">SQLite</strong>
    <span style="background:#cffccc;border:1px solid #007700;color:#004400;font-size:10px;font-weight:bold;padding:2px 8px;border-radius:10px;">Recomendado</span>
  </div>
  <table style="width:100%;font-size:12px;border-collapse:collapse;">
    <tr><td style="padding:4px 0;color:#666;width:140px;">Arquivo do banco:</td><td style="font-family:monospace;">/database/finapp.db</td></tr>
    <tr><td style="padding:4px 0;color:#666;">Status:</td>
        <td><?= $dbExists ? '<span style="color:#007700;font-weight:bold;">Arquivo existente</span>' : '<span style="color:#0066cc;font-weight:bold;">Será criado agora</span>' ?></td></tr>
    <tr><td style="padding:4px 0;color:#666;">Schema:</td>
        <td><?= file_exists($schemaPath) ? '<span style="color:#007700;">schema.sql encontrado ✓</span>' : '<span style="color:#cc0000;">schema.sql não encontrado ✗</span>' ?></td></tr>
  </table>
</div>

<?php if ($status === 'ok'): ?>
<div class="alert as">Banco de dados criado e inicializado com sucesso!</div>
<?php elseif ($status === 'error'): ?>
<div class="alert ae">Erro ao criar banco: <?= htmlspecialchars($_SESSION['install_data']['db_error'] ?? '') ?></div>
<?php endif; ?>

<div class="alert ai">
  O banco SQLite é um arquivo único em <code style="font-size:11px;">/database/finapp.db</code>.
  Não requer usuário, senha ou servidor separado. Ideal para instalações compartilhadas.
</div>

<?php if (!file_exists($schemaPath)): ?>
<div class="alert ae">Arquivo <code>database/schema.sql</code> não encontrado. Reinstale o pacote.</div>
<?php else: ?>
<form method="post" action="?step=3">
  <input type="hidden" name="step" value="3">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf_token'] ?? '') ?>">
  <?php if ($dbExists): ?>
  <div class="fg">
    <label>
      <input type="checkbox" name="reset_db" value="1">
      Recriar banco do zero (apaga todos os dados existentes)
    </label>
    <span class="help">Marque apenas se quiser uma instalação limpa.</span>
  </div>
  <?php endif; ?>
  <div class="btn-row">
    <a href="?step=2" class="btn bd">‹ Voltar</a>
    <button type="submit" class="btn bp">Criar Banco de Dados ›</button>
  </div>
</form>
<?php endif; ?>
