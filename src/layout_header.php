<?php
/**
 * layout_header.php — Layout igual ao beta, paths dinâmicos do finapp_v3
 */
$pageTitle  = $pageTitle  ?? (defined('APP_NAME') ? APP_NAME : 'Tonch');
$activePage = $activePage ?? '';
$mes        = $mes        ?? mesAbertoAtual()['mes'];
$ano        = $ano        ?? mesAbertoAtual()['ano'];
$fechado    = mesFechado($mes, $ano);
$_aberto    = mesAbertoAtual();
$_proximo   = proximoMesAbrir();
$_iniciado  = mesIniciado($mes, $ano);
$_ehProximo = ($_proximo['mes'] === $mes && $_proximo['ano'] === $ano);
$_ehAberto  = caixaAberto($mes, $ano);
$appName    = defined('APP_NAME') ? APP_NAME : 'Tonch';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> — <?= h($appName) ?></title>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><polygon points='16,2 30,28 2,28' fill='%230066cc'/><line x1='16' y1='2' x2='16' y2='28' stroke='%23fff' stroke-width='1.2' opacity='0.5'/><line x1='2' y1='28' x2='30' y2='28' stroke='%23fff' stroke-width='1.2' opacity='0.5'/><line x1='9' y1='14' x2='23' y2='14' stroke='%23fff' stroke-width='1.2' opacity='0.5'/></svg>">
</head>
<body>

<!-- TITLEBAR -->
<div class="titlebar">
  <div class="titlebar-dots">
    <span class="dot dot-r"></span>
    <span class="dot dot-y"></span>
    <span class="dot dot-g"></span>
  </div>
  <div class="titlebar-title"><?= h($appName) ?></div>
  <div class="titlebar-user" style="display:flex;align-items:center;gap:10px;">
    <span id="relogioSP" style="font-size:11px;font-family:monospace;color:#3cb371;" title="Horário Brasília"></span>
    <span><?= h($_SESSION['username'] ?? '') ?></span>
    <span>&bull;</span>
    <a href="<?= url('public/logout.php') ?>">Sair</a>
  </div>
</div>

<!-- NAV PRINCIPAL (Chrome-style tabs) -->
<div class="nav-tabs-bar">
  <a href="<?= url('public/dashboard.php') ?>?mes=<?= $_aberto['mes'] ?>&ano=<?= $_aberto['ano'] ?>"
     class="nav-tab <?= $activePage==='dashboard'    ? 'active' : '' ?>">&#127968; Dashboard</a>
  <a href="<?= url('public/lancamentos.php') ?>?mes=<?= $_aberto['mes'] ?>&ano=<?= $_aberto['ano'] ?>"
     class="nav-tab <?= $activePage==='lancamentos'   ? 'active' : '' ?>">&#128200; Lançamentos</a>
  <a href="<?= url('public/contas_banco.php') ?>?mes=<?= $_aberto['mes'] ?>&ano=<?= $_aberto['ano'] ?>"
     class="nav-tab <?= $activePage==='contas_banco'  ? 'active' : '' ?>">&#127974; Contas</a>
  <a href="<?= url('public/contas_pr.php') ?>?mes=<?= $_aberto['mes'] ?>&ano=<?= $_aberto['ano'] ?>"
     class="nav-tab <?= $activePage==='contas_pr'     ? 'active' : '' ?>">&#128203; Contas P/R</a>
  <a href="<?= url('public/relatorios.php') ?>?mes=<?= $_aberto['mes'] ?>&ano=<?= $_aberto['ano'] ?>"
     class="nav-tab <?= $activePage==='relatorios'    ? 'active' : '' ?>">&#128196; Relatórios</a>
  <a href="<?= url('public/notas_fiscais.php') ?>?ano=<?= $ano ?>"
     class="nav-tab <?= $activePage==='notas_fiscais' ? 'active' : '' ?>">&#128204; Notas Fiscais</a>
  <a href="<?= url('public/cadastros.php') ?>"
     class="nav-tab <?= $activePage==='cadastros'     ? 'active' : '' ?>">&#9881; Cadastros</a>
  <a href="<?= url('public/id_key.php') ?>"
     class="nav-tab <?= $activePage==='id_key'        ? 'active' : '' ?>" style="font-weight:700;">&#128272; Vault</a>
  <a href="<?= url('public/contato.php') ?>"
     class="nav-tab <?= $activePage==='contato'       ? 'active' : '' ?>" style="margin-left:auto;">&#128222; Contato</a>
</div>

<!-- ABAS MENSAIS -->
<div class="month-tabs-bar">
  <?php for ($m = 1; $m <= 12; $m++):
    $mFech  = mesFechado($m, $ano);
    $mInic  = mesIniciado($m, $ano);
    $mAbrt  = ($m === $_aberto['mes'] && $ano === $_aberto['ano']);
    $mProx  = ($m === $_proximo['mes'] && $ano === $_proximo['ano']);
    $href   = $mInic
              ? (url('public/' . $activePage . '.php') . '?mes=' . $m . '&ano=' . $ano)
              : (url('public/' . $activePage . '.php') . '?mes=' . $_aberto['mes'] . '&ano=' . $_aberto['ano']);
    $cls    = '';
    if ($m == $mes && $_iniciado) $cls .= ' active';
    if ($mFech)  $cls .= ' fechado';
    if (!$mInic) $cls .= ' mes-futuro';
    $title  = $mFech ? '🔒 Fechado' : ($mAbrt ? '🟢 Aberto' : ($mProx ? '⏳ Aguardando abertura' : (!$mInic ? '🔒 Não iniciado' : '')));
  ?>
  <a href="<?= $href ?>"
     class="mtab<?= $cls ?>"
     title="<?= $title ?>"
     <?= !$mInic ? 'style="opacity:0.4;cursor:default;"' : '' ?>>
    <?= abrevMes($m) ?>
    <?php if ($mAbrt): ?><span style="display:block;font-size:7px;color:#2a2;">●</span><?php endif; ?>
    <?php if ($mFech): ?><span style="display:block;font-size:7px;">🔒</span><?php endif; ?>
  </a>
  <?php endfor; ?>
  &nbsp;
  <select onchange="window.location.href=this.value" style="font-size:11px;padding:2px 4px;border-radius:3px;border:1px solid #aaa;margin-bottom:4px;">
    <?php for ($y = (int)date('Y')-1; $y <= (int)date('Y')+2; $y++): ?>
      <option value="<?= url('public/' . $activePage . '.php') ?>?mes=<?= $mes ?>&ano=<?= $y ?>"
              <?= $y == $ano ? 'selected' : '' ?>><?= $y ?></option>
    <?php endfor; ?>
  </select>
</div>

<!-- FLASH MESSAGE — persistente, clique para fechar -->
<?php $flash = getFlash(); if ($flash):
  $icons = ['success'=>'✓','error'=>'✗','warning'=>'⚠','info'=>'ℹ'];
  $icon  = $icons[$flash['type']] ?? 'ℹ';
?>
<div class="flash-container">
  <div class="alert alert-<?= h($flash['type']) ?> alert-flash" data-dismissible="1" role="alert">
    <span class="alert-icon"><?= $icon ?></span>
    <span class="alert-body"><?= h($flash['msg']) ?></span>
    <span class="alert-close" title="Fechar">&times;</span>
  </div>
</div>
<?php endif; ?>

<?php if ($fechado): ?>
<!-- BANNER: MÊS FECHADO -->
<div style="padding:4px 12px 0;">
  <div class="mes-fechado-bar">
    🔒 <?= nomeMes($mes) ?>/<?= $ano ?> está FECHADO — somente leitura. Registros imutáveis.
  </div>
</div>

<?php elseif ($_ehProximo && !$_iniciado): ?>
<!-- BANNER: PRÓXIMO MÊS — AGUARDANDO ABERTURA -->
<?php
  $mesAntBanner = ($mes == 1) ? 12 : $mes - 1;
  $anoAntBanner = ($mes == 1) ? $ano - 1 : $ano;
  $antFechado = mesFechado($mesAntBanner, $anoAntBanner);
  $ab = dataAberturaSistema();
  $ehPrimeiro = $ab ? ($mes == (int)$ab['mes'] && $ano == (int)$ab['ano']) : false;
  $antFechado = $antFechado || $ehPrimeiro;
?>
<div style="padding:4px 12px 0;">
  <div class="mes-fechado-bar" style="background:linear-gradient(180deg,#d4edda,#a8d5b5);border-color:#28a745;color:#155724;">
    🟢 <?= nomeMes($mes) ?>/<?= $ano ?> aguarda abertura do Caixa.
    <?php if ($antFechado): ?>
      <span style="font-size:11px;margin-left:4px;">(<?= nomeMes($mesAntBanner) ?>/<?= $anoAntBanner ?> fechado — pronto para abrir)</span>
    <?php endif; ?>
    <form method="post" action="<?= url('public/fechar_mes.php') ?>" style="display:inline;margin-left:auto;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="abrir">
      <input type="hidden" name="mes"    value="<?= $mes ?>">
      <input type="hidden" name="ano"    value="<?= $ano ?>">
      <button type="submit" class="btn btn-xs"
              style="background:#28a745;color:#fff;font-weight:700;"
              onclick="return confirm('Abrir o Caixa de <?= nomeMes($mes) . '/' . $ano ?>?\n\nApós aberto, você poderá registrar movimentações.')">
        🟢 Abrir Caixa
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="main-wrap">

<!-- RELÓGIO BRASÍLIA -->
<script>
(function() {
  function updateClock() {
    var now = new Date();
    var sp = new Date(now.toLocaleString('en-US', {timeZone: 'America/Sao_Paulo'}));
    var h = String(sp.getHours()).padStart(2,'0');
    var m = String(sp.getMinutes()).padStart(2,'0');
    var s = String(sp.getSeconds()).padStart(2,'0');
    var d = String(sp.getDate()).padStart(2,'0');
    var mo = String(sp.getMonth()+1).padStart(2,'0');
    var y = sp.getFullYear();
    var el = document.getElementById('relogioSP');
    if (el) el.textContent = d+'/'+mo+'/'+y+' '+h+':'+m+':'+s+' (Brasil)';
  }
  updateClock();
  setInterval(updateClock, 1000);
})();
</script>
