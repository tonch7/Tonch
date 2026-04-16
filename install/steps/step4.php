<?php
/** Etapa 4 — Criar Administrador */
$_SESSION['install_step'] = 4;

$errors = $_SESSION['install_data']['admin_errors'] ?? [];
unset($_SESSION['install_data']['admin_errors']);
$saved = $_SESSION['install_data']['admin'] ?? [];
?>

<h2 class="heading">Criar Conta de Administrador</h2>
<p class="sub">Configure as credenciais de acesso ao sistema. Anote a senha em local seguro.</p>

<?php if ($errors): ?>
  <div class="alert ae">
    <ul style="margin:0;padding-left:16px;">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="?step=4">
  <input type="hidden" name="step" value="4">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf_token'] ?? '') ?>">

  <div class="fg">
    <label>Nome do Sistema / Empresa</label>
    <input type="text" name="app_name" placeholder="FinApp" maxlength="60"
           value="<?= htmlspecialchars($saved['app_name'] ?? 'FinApp') ?>">
    <span class="help">Aparece no cabeçalho e rodapé do sistema</span>
  </div>

  <div class="fg">
    <label>Usuário administrador <span style="color:#cc0000">*</span></label>
    <input type="text" name="username" placeholder="admin" maxlength="30" autocomplete="off"
           value="<?= htmlspecialchars($saved['username'] ?? 'admin') ?>">
    <span class="help">Somente letras, números e underscore</span>
  </div>

  <div class="fg">
    <label>E-mail do administrador</label>
    <input type="email" name="admin_email" placeholder="admin@empresa.com" maxlength="120"
           value="<?= htmlspecialchars($saved['admin_email'] ?? '') ?>">
    <span class="help">Campo opcional para identificação administrativa.</span>
  </div>

  <div class="fg">
    <label>Senha <span style="color:#cc0000">*</span></label>
    <div style="display:flex;gap:8px;align-items:flex-start;">
      <input type="password" name="password" id="passInput" placeholder="Mínimo 8 caracteres"
             value="<?= htmlspecialchars($saved['password'] ?? '') ?>" autocomplete="new-password"
             style="flex:1;" oninput="checkStrength(this.value)">
      <button type="button" class="btn bd bsm" onclick="genPass()">Gerar</button>
    </div>
    <div id="strengthBar" style="height:4px;border-radius:2px;background:#e0e0e0;margin-top:4px;overflow:hidden;">
      <div id="strengthFill" style="height:100%;width:0;background:#cc0000;transition:width .3s,background .3s;"></div>
    </div>
    <span class="help" id="strengthLabel">Mínimo 8 caracteres, use letras e números</span>
  </div>

  <div class="fg">
    <label>Confirmar senha <span style="color:#cc0000">*</span></label>
    <input type="password" name="password_confirm" placeholder="Repita a senha"
           value="<?= htmlspecialchars($saved['password_confirm'] ?? '') ?>" autocomplete="new-password">
  </div>

  <div class="btn-row">
    <a href="?step=3" class="btn bd">‹ Voltar</a>
    <button type="submit" class="btn bp">Instalar Sistema ›</button>
  </div>
</form>

<script>
function genPass() {
  var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$';
  var pass = '';
  for (var i = 0; i < 16; i++) pass += chars[Math.floor(Math.random() * chars.length)];
  document.getElementById('passInput').value = pass;
  document.querySelector('[name=password_confirm]').value = pass;
  checkStrength(pass);
}

function checkStrength(v) {
  var score = 0;
  if (v.length >= 8)  score++;
  if (v.length >= 12) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/[0-9]/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  var colors = ['#cc0000','#cc6600','#cc9900','#007700','#007700'];
  var labels = ['Muito fraca','Fraca','Razoável','Boa','Forte'];
  var fill = document.getElementById('strengthFill');
  var lbl  = document.getElementById('strengthLabel');
  fill.style.width = (score * 20) + '%';
  fill.style.background = colors[Math.max(0,score-1)] || '#e0e0e0';
  lbl.textContent = v.length ? labels[Math.max(0,score-1)] : 'Mínimo 8 caracteres, use letras e números';
}
</script>
