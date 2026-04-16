<?php
/** Etapa 1 — Boas-vindas */
$_SESSION['install_step'] = 1;
?>
<h2 class="heading">Bem-vindo ao FinApp</h2>
<p class="sub">Sistema de Gestão Financeira — pronto para ser instalado no seu servidor.</p>

<ul class="fl">
  <li>Controle financeiro completo: entradas, saídas e transferências</li>
  <li>Múltiplas contas bancárias com saldo individual</li>
  <li>Cartão de crédito com controle de faturas</li>
  <li>Contas a pagar e receber</li>
  <li>Fechamento mensal com imutabilidade de dados</li>
  <li>Relatórios, DRE e exportação para Power BI</li>
  <li>Vault criptografado para senhas e informações sigilosas</li>
  <li>Auditoria completa de ações</li>
  <li>100% auto-hospedado — sem dependências externas</li>
  <li>Funciona em qualquer servidor PHP + Apache/Nginx</li>
</ul>

<div class="alert ai">
  <strong>Antes de continuar, verifique:</strong><br>
  PHP 7.4 ou superior &bull; Extensão PDO SQLite habilitada &bull; Pasta com permissão de escrita
</div>

<form method="post" action="?step=1">
  <input type="hidden" name="step" value="1">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf_token'] ?? '') ?>">
  <div class="btn-row">
    <span style="font-size:12px;color:#888;">Etapa 1 de 6</span>
    <button type="submit" class="btn bp">Verificar Requisitos ›</button>
  </div>
</form>
