<?php
require_once dirname(__DIR__) . "/src/bootstrap.php";
Auth::require();
requireSistemaLiberado();

$_aberto = mesAbertoAtual();
$ano = (int)($_GET['ano'] ?? $_aberto['ano']);
$mes = (int)($_GET['mes'] ?? $_aberto['mes']);
if ($mes < 1 || $mes > 12) $mes = $_aberto['mes'];
ensureMesAcessivel($mes, $ano, "dashboard");

// ---- Base ----
$contas = db()->all('SELECT * FROM contas WHERE ativa=1 ORDER BY nome');

$totEnt = (float)(db()->one(
    'SELECT SUM(COALESCE(valor_liquido,valor)) t FROM lancamentos WHERE tipo="entrada" AND mes=? AND ano=?',
    [$mes, $ano]
)['t'] ?? 0);

$totSai = (float)(db()->one(
    'SELECT SUM(valor) t FROM lancamentos WHERE tipo IN ("saida") AND mes=? AND ano=?',
    [$mes, $ano]
)['t'] ?? 0);

$totTrf = (float)(db()->one(
    'SELECT SUM(valor) t FROM lancamentos WHERE tipo IN ("transferencia","trf") AND mes=? AND ano=?',
    [$mes, $ano]
)['t'] ?? 0);

$saldoConsolid = saldoConsolidado($mes, $ano);
$resMes = $totEnt - $totSai;

$ultimos = db()->all(
    'SELECT l.*, c.nome cat, ct.nome conta_nome, cl.nome cliente_nome
     FROM lancamentos l
     LEFT JOIN categorias c ON l.categoria_id=c.id
     LEFT JOIN contas ct ON l.conta_id=ct.id
     LEFT JOIN clientes cl ON l.cliente_id=cl.id
     WHERE l.mes=? AND l.ano=?
     ORDER BY l.data DESC, l.criado_em DESC LIMIT 8',
    [$mes, $ano]
);

$evolucao = [];
for ($m = 1; $m <= 12; $m++) {
    $e = (float)(db()->one(
        'SELECT SUM(COALESCE(valor_liquido,valor)) t FROM lancamentos WHERE tipo="entrada" AND mes=? AND ano=?',
        [$m, $ano]
    )['t'] ?? 0);

    $s = (float)(db()->one(
        'SELECT SUM(valor) t FROM lancamentos WHERE tipo="saida" AND mes=? AND ano=?',
        [$m, $ano]
    )['t'] ?? 0);

    $evolucao[$m] = [
        'e'     => $e,
        's'     => $s,
        'saldo' => saldoConsolidado($m, $ano),
        'r'     => $e - $s,
    ];
}

$hoje = date('Y-m-d');
$limite = date('Y-m-d', strtotime('+7 days'));

$proximas = db()->all(
    'SELECT * FROM contas_pagar_receber
     WHERE pago_recebido=0 AND data_vencimento BETWEEN ? AND ?
     ORDER BY data_vencimento',
    [$hoje, $limite]
);

$vencidas = db()->all(
    'SELECT * FROM contas_pagar_receber
     WHERE pago_recebido=0 AND data_vencimento < ?
     ORDER BY data_vencimento',
    [$hoje]
);

$topSaidas = db()->all(
    'SELECT c.nome cat, SUM(l.valor) total
     FROM lancamentos l
     LEFT JOIN categorias c ON l.categoria_id=c.id
     WHERE l.tipo="saida" AND l.mes=? AND l.ano=?
     GROUP BY l.categoria_id
     ORDER BY total DESC LIMIT 5',
    [$mes, $ano]
);

$topEntradas = db()->all(
    'SELECT c.nome cat, SUM(COALESCE(l.valor_liquido,l.valor)) total
     FROM lancamentos l
     LEFT JOIN categorias c ON l.categoria_id=c.id
     WHERE l.tipo="entrada" AND l.mes=? AND l.ano=?
     GROUP BY l.categoria_id
     ORDER BY total DESC LIMIT 5',
    [$mes, $ano]
);

$cpResumo = db()->one(
    'SELECT
        SUM(CASE WHEN tipo="receber" AND pago_recebido=1 THEN valor ELSE 0 END) AS recebido,
        SUM(CASE WHEN tipo="pagar"   AND pago_recebido=1 THEN valor ELSE 0 END) AS pago,
        SUM(CASE WHEN tipo="receber" THEN valor ELSE 0 END) AS receber_total,
        SUM(CASE WHEN tipo="pagar"   THEN valor ELSE 0 END) AS pagar_total,
        SUM(CASE WHEN tipo="receber" AND pago_recebido=0 THEN valor ELSE 0 END) AS receber_aberto,
        SUM(CASE WHEN tipo="pagar"   AND pago_recebido=0 THEN valor ELSE 0 END) AS pagar_aberto
     FROM contas_pagar_receber'
) ?? [];

$cpRecebido      = (float)($cpResumo['recebido'] ?? 0);
$cpPago          = (float)($cpResumo['pago'] ?? 0);
$cpReceberTotal  = (float)($cpResumo['receber_total'] ?? 0);
$cpPagarTotal    = (float)($cpResumo['pagar_total'] ?? 0);
$cpReceberAberto = (float)($cpResumo['receber_aberto'] ?? 0);
$cpPagarAberto   = (float)($cpResumo['pagar_aberto'] ?? 0);

$caixaHoje = (float)(db()->one(
    'SELECT SUM(CASE WHEN tipo="entrada" THEN COALESCE(valor_liquido,valor) ELSE -valor END) AS t
     FROM lancamentos
     WHERE data=?',
    [$hoje]
)['t'] ?? 0);

$totalLancMes = (int)(db()->one(
    'SELECT COUNT(*) q FROM lancamentos WHERE mes=? AND ano=?',
    [$mes, $ano]
)['q'] ?? 0);

$ticketMedioEntradas = $totEnt > 0 && $totalLancMes > 0
    ? (float)(db()->one(
        'SELECT AVG(v) m FROM (
            SELECT COALESCE(valor_liquido,valor) v
            FROM lancamentos
            WHERE tipo="entrada" AND mes=? AND ano=?
        ) x',
        [$mes, $ano]
    )['m'] ?? 0)
    : 0;

$ticketMedioSaidas = $totSai > 0
    ? (float)(db()->one(
        'SELECT AVG(valor) m FROM lancamentos WHERE tipo="saida" AND mes=? AND ano=?',
        [$mes, $ano]
    )['m'] ?? 0)
    : 0;

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once ROOT_PATH . '/src/layout_header.php';
?>

<style>
  .dashboard-hero {
    position: relative;
    overflow: hidden;
    border-radius: 18px;
    border: 1px solid rgba(15,23,42,.10);
    background:
      radial-gradient(circle at top right, rgba(59,130,246,.12), transparent 24%),
      radial-gradient(circle at bottom left, rgba(16,185,129,.10), transparent 26%),
      linear-gradient(135deg, #f8fafc, #e2e8f0);
    box-shadow: 0 18px 60px rgba(15,23,42,.10);
    margin-bottom: 14px;
    color: #0f172a;
  }
  .dashboard-hero::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent);
    transform: translateX(-100%);
    animation: dashShine 9s linear infinite;
  }
  @keyframes dashShine {
    to { transform: translateX(100%); }
  }
  .hero-grid {
    display: grid;
    grid-template-columns: 1.3fr .9fr;
    gap: 16px;
    align-items: center;
    padding: 18px;
  }
  .hero-title {
    font-size: clamp(1.3rem, 2vw, 2rem);
    font-weight: 800;
    letter-spacing: -.02em;
    margin: 0 0 6px;
    color: #0f172a;
  }
  .hero-sub {
    color: #334155;
    line-height: 1.5;
    max-width: 720px;
  }
  .hero-kpis {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-top: 14px;
  }
  .hero-kpi {
    border-radius: 14px;
    padding: 12px;
    background: rgba(255,255,255,.72);
    border: 1px solid rgba(148,163,184,.22);
    backdrop-filter: blur(4px);
  }
  .hero-kpi .k {
    font-size: .78rem;
    color: #475569;
    margin-bottom: 4px;
  }
  .hero-kpi .v {
    font-size: 1.05rem;
    font-weight: 800;
  }
  .btc-card {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 220px;
    border-radius: 18px;
    padding: 18px;
    background: linear-gradient(180deg, #ffffff, #f8fafc);
    border: 1px solid rgba(245,158,11,.25);
    box-shadow: 0 10px 30px rgba(15,23,42,.08);
    color: #0f172a;
  }
  .btc-badge {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    gap: 8px;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: .76rem;
    font-weight: 700;
    background: #f8fafc;
    border: 1px solid rgba(148,163,184,.25);
    margin-bottom: 12px;
    color: #334155;
  }
  .btc-symbol {
    font-size: .92rem;
    color: #475569;
    letter-spacing: .08em;
    text-transform: uppercase;
  }
  .btc-price {
    font-size: clamp(1.8rem, 4vw, 2.8rem);
    font-weight: 900;
    line-height: 1;
    margin: 6px 0 8px;
    letter-spacing: -.03em;
    color: #0f172a;
  }
  .btc-meta {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
  }
  .btc-pill {
    padding: 7px 10px;
    border-radius: 999px;
    font-size: .78rem;
    background: #f8fafc;
    border: 1px solid rgba(148,163,184,.25);
    color: #334155;
  }
  .btc-positive { color: #16a34a; }
  .btc-negative { color: #dc2626; }
  .spotlight-center {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
    margin: 0 0 14px;
  }
  .spot-card {
    border-radius: 16px;
    padding: 14px;
    border: 1px solid rgba(148,163,184,.18);
    background: linear-gradient(180deg, #ffffff, #f8fafc);
    color: #0f172a;
  }
  .spot-card h4 {
    margin: 0 0 8px;
    font-size: .92rem;
    color: #475569;
  }
  .spot-card .big {
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: -.02em;
    color: #0f172a;
  }
  .grid-3 { display:grid; grid-template-columns: repeat(3, 1fr); gap:12px; }
  .grid-2-1 { display:grid; grid-template-columns: 1.4fr .8fr; gap:12px; }
  .chart-wrap-lg { min-height: 280px; }
  .chart-wrap-md { min-height: 240px; }
  .chart-wrap-sm { min-height: 220px; }
  .insight-list { display:grid; gap:10px; }
  .insight-item {
    padding: 11px 12px;
    border-radius: 12px;
    background: rgba(255,255,255,.75);
    border: 1px solid rgba(148,163,184,.18);
    color: #0f172a;
  }
  .soft-sep {
    height:1px;
    background:linear-gradient(90deg, transparent, rgba(148,163,184,.25), transparent);
    margin:12px 0;
  }
  .mini-muted {
    font-size:.8rem;
    color:#64748b;
  }
  .orbe {
    position:absolute; right:-18px; top:-18px; width:120px; height:120px; border-radius:50%;
    background: radial-gradient(circle, rgba(245,158,11,.22), transparent 70%);
    filter: blur(6px); opacity:.9; pointer-events:none;
  }
  @media (max-width: 1100px) {
    .hero-grid, .grid-2-1, .spotlight-center, .grid-3 { grid-template-columns: 1fr; }
    .hero-kpis { grid-template-columns: 1fr; }
  }
</style>

<div class="dashboard-hero">
  <div class="orbe"></div>
  <div class="hero-grid">
    <div>
      <div class="mini-muted">Painel central · visão viva do sistema</div>
      <h1 class="hero-title">Dashboard como coração do sistema</h1>
      <div class="hero-sub">
        Mantive a lógica original e reforcei a leitura estratégica: visão financeira, pendências,
        lançamentos, tendências, concentração por categoria e agora um bloco central de BTC/USDT ao vivo.
      </div>
      <div class="hero-kpis">
        <div class="hero-kpi">
          <div class="k">Resultado do mês</div>
          <div class="v <?= $resMes >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($resMes) ?></div>
        </div>
        <div class="hero-kpi">
          <div class="k">Saldo consolidado</div>
          <div class="v <?= $saldoConsolid >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($saldoConsolid) ?></div>
        </div>
        <div class="hero-kpi">
          <div class="k">Movimento hoje</div>
          <div class="v <?= $caixaHoje >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($caixaHoje) ?></div>
        </div>
      </div>
    </div>

    <div class="btc-card" id="btcSpotCard">
      <div class="btc-badge">₿ BTC live center</div>
      <div class="btc-symbol">Bitcoin / USDT</div>
      <div class="btc-price" id="btcPrice">Carregando...</div>
      <div class="mini-muted" id="btcUpdated">Buscando cotação em tempo real...</div>
      <div class="btc-meta">
        <div class="btc-pill">24h: <strong id="btcChange">--</strong></div>
        <div class="btc-pill">Máx 24h: <strong id="btcHigh">--</strong></div>
        <div class="btc-pill">Mín 24h: <strong id="btcLow">--</strong></div>
      </div>
    </div>
  </div>
</div>

<div class="spotlight-center">
  <div class="spot-card">
    <h4>Total já recebido</h4>
    <div class="big val-pos"><?= fmt($cpRecebido) ?></div>
    <div class="mini-muted">Contas marcadas como recebidas</div>
  </div>
  <div class="spot-card" style="text-align:center;">
    <h4>Centro operacional</h4>
    <div class="big"><?= (int)$totalLancMes ?></div>
    <div class="mini-muted">lançamentos no mês · <?= h(nomeMes($mes)) ?>/<?= (int)$ano ?></div>
  </div>
  <div class="spot-card" style="text-align:right;">
    <h4>Total já pago</h4>
    <div class="big val-neg"><?= fmt($cpPago) ?></div>
    <div class="mini-muted">Contas marcadas como pagas</div>
  </div>
</div>

<div class="grid-auto mb-12">
  <?php foreach ($contas as $c):
    $s = saldoConta($c['id'], $mes, $ano);
  ?>
  <div class="scard">
    <div class="lbl">&#127974; <?= h($c['nome']) ?></div>
    <div class="val <?= $s >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($s) ?></div>
    <div class="sub"><?= ucfirst($c['tipo_conta']) ?></div>
  </div>
  <?php endforeach; ?>

  <div class="scard">
    <div class="lbl">&#128181; Consolidado</div>
    <div class="val <?= $saldoConsolid >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($saldoConsolid) ?></div>
    <div class="sub">Todas as contas</div>
  </div>
  <div class="scard">
    <div class="lbl">&#8593; Entradas</div>
    <div class="val val-pos"><?= fmt($totEnt) ?></div>
    <div class="sub"><?= nomeMes($mes) ?></div>
  </div>
  <div class="scard">
    <div class="lbl">&#8595; Saídas</div>
    <div class="val val-neg"><?= fmt($totSai) ?></div>
    <div class="sub"><?= nomeMes($mes) ?></div>
  </div>
  <div class="scard">
    <div class="lbl">&#9654; Resultado</div>
    <div class="val <?= $resMes >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($resMes) ?></div>
    <div class="sub">Entradas - Saídas</div>
  </div>
  <div class="scard">
    <div class="lbl">&#8646; Transferências</div>
    <div class="val"><?= fmt($totTrf) ?></div>
    <div class="sub">Volume interno do mês</div>
  </div>
  <div class="scard">
    <div class="lbl">&#128176; Ticket médio entrada</div>
    <div class="val val-pos"><?= fmt($ticketMedioEntradas) ?></div>
    <div class="sub">Média por lançamento</div>
  </div>
  <div class="scard">
    <div class="lbl">&#128178; Ticket médio saída</div>
    <div class="val val-neg"><?= fmt($ticketMedioSaidas) ?></div>
    <div class="sub">Média por lançamento</div>
  </div>
</div>

<div class="grid-2-1 mb-12">
  <div class="win">
    <div class="win-title">&#128200; Evolução premium <?= $ano ?></div>
    <div class="win-body chart-wrap-lg"><canvas id="evolChart" height="170"></canvas></div>
  </div>
  <div class="win">
    <div class="win-title">&#129504; Leitura rápida</div>
    <div class="win-body">
      <div class="insight-list">
        <div class="insight-item">
          <div class="mini-muted">Receber total</div>
          <strong class="val-pos"><?= fmt($cpReceberTotal) ?></strong>
        </div>
        <div class="insight-item">
          <div class="mini-muted">Pagar total</div>
          <strong class="val-neg"><?= fmt($cpPagarTotal) ?></strong>
        </div>
        <div class="insight-item">
          <div class="mini-muted">A receber em aberto</div>
          <strong class="val-pos"><?= fmt($cpReceberAberto) ?></strong>
        </div>
        <div class="insight-item">
          <div class="mini-muted">A pagar em aberto</div>
          <strong class="val-neg"><?= fmt($cpPagarAberto) ?></strong>
        </div>
        <div class="insight-item">
          <div class="mini-muted">Gap financeiro projetado</div>
          <?php $gapProjetado = $cpReceberAberto - $cpPagarAberto; ?>
          <strong class="<?= $gapProjetado >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($gapProjetado) ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="grid-3 mb-12">
  <div class="win">
    <div class="win-title">&#127919; Composição do mês</div>
    <div class="win-body chart-wrap-sm"><canvas id="mixChart" height="200"></canvas></div>
  </div>
  <div class="win">
    <div class="win-title">&#8595; Top Saídas — <?= nomeMes($mes) ?></div>
    <div class="win-body">
      <?php if (empty($topSaidas)): ?>
        <p class="text-muted text-center" style="padding:20px 0;">Sem dados</p>
      <?php else:
        $maxS = max(array_column($topSaidas, 'total'));
        foreach ($topSaidas as $r): ?>
        <div style="margin-bottom:10px;">
          <div class="flex flex-btwn text-sm mb-4">
            <span><?= h($r['cat'] ?? '—') ?></span>
            <span class="text-red text-mono"><?= fmt($r['total']) ?></span>
          </div>
          <div class="prog-bar">
            <div class="prog-fill prog-red" style="width:<?= $maxS > 0 ? round($r['total'] / $maxS * 100) : 0 ?>%"></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <div class="win">
    <div class="win-title">&#8593; Top Entradas — <?= nomeMes($mes) ?></div>
    <div class="win-body">
      <?php if (empty($topEntradas)): ?>
        <p class="text-muted text-center" style="padding:20px 0;">Sem dados</p>
      <?php else:
        $maxE = max(array_column($topEntradas, 'total'));
        foreach ($topEntradas as $r): ?>
        <div style="margin-bottom:10px;">
          <div class="flex flex-btwn text-sm mb-4">
            <span><?= h($r['cat'] ?? '—') ?></span>
            <span class="text-green text-mono"><?= fmt($r['total']) ?></span>
          </div>
          <div class="prog-bar">
            <div class="prog-fill" style="width:<?= $maxE > 0 ? round($r['total'] / $maxE * 100) : 0 ?>%"></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<div class="grid-2 mb-12">
  <div class="win">
    <div class="win-title">&#128202; Recebido x Pago x Em Aberto</div>
    <div class="win-body chart-wrap-md"><canvas id="flowChart" height="180"></canvas></div>
  </div>
  <div class="win">
    <div class="win-title">&#128640; Radar de performance</div>
    <div class="win-body">
      <div class="insight-list">
        <div class="insight-item">
          <div class="mini-muted">Entradas vs saídas</div>
          <strong><?= fmt($totEnt) ?> / <?= fmt($totSai) ?></strong>
        </div>
        <div class="insight-item">
          <div class="mini-muted">Recebido vs esperado receber</div>
          <strong><?= fmt($cpRecebido) ?> / <?= fmt($cpReceberTotal) ?></strong>
        </div>
        <div class="insight-item">
          <div class="mini-muted">Pago vs esperado pagar</div>
          <strong><?= fmt($cpPago) ?> / <?= fmt($cpPagarTotal) ?></strong>
        </div>
        <div class="insight-item">
          <div class="mini-muted">Pendências</div>
          <strong><?= count($vencidas) ?> vencida(s) · <?= count($proximas) ?> próximas</strong>
        </div>
        <div class="insight-item">
          <div class="mini-muted">Direção do mês</div>
          <strong class="<?= $resMes >= 0 ? 'val-pos' : 'val-neg' ?>">
            <?= $resMes >= 0 ? 'Operando no azul' : 'Operando no vermelho' ?>
          </strong>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($vencidas) || !empty($proximas)): ?>
<div class="win mb-12">
  <div class="win-title" style="background:linear-gradient(180deg,#ffccaa,#ff8844);">
    &#9888; Contas Pendentes
    (<?= count($vencidas) ?> vencida<?= count($vencidas) != 1 ? 's' : '' ?>,
     <?= count($proximas) ?> vencendo em 7 dias)
  </div>
  <div class="win-body" style="padding:0;">
    <div class="table-wrap">
      <table class="table-mobile">
        <thead><tr>
          <th>Tipo</th><th>Vencimento</th><th>Descrição</th><th>Valor</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach (array_merge($vencidas, $proximas) as $cp): ?>
          <tr style="<?= $cp['data_vencimento'] < $hoje ? 'background:#fff5f5;' : '' ?>">
            <td data-label="Tipo"><span class="badge <?= $cp['tipo'] === 'pagar' ? 'b-sai' : 'b-ent' ?>"><?= $cp['tipo'] === 'pagar' ? 'Pagar' : 'Receber' ?></span></td>
            <td data-label="Vencto"><?= fmtDate($cp['data_vencimento']) ?><?= $cp['data_vencimento'] < $hoje ? ' <span class="badge b-venc">VENCIDA</span>' : '' ?></td>
            <td data-label="Descrição"><?= h($cp['descricao']) ?></td>
            <td data-label="Valor" class="money <?= $cp['tipo'] === 'pagar' ? 'neg' : 'pos' ?>"><?= fmt($cp['valor']) ?></td>
            <td><a href="<?= url("public/contas_pr.php") ?>?mes=<?= $mes ?>&ano=<?= $ano ?>" class="btn btn-default btn-xs">Ver</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="win">
  <div class="win-title">
    &#128203; Últimos Lançamentos — <?= nomeMes($mes) ?>/<?= $ano ?>
    <a href="<?= url("public/lancamentos.php") ?>?mes=<?= $mes ?>&ano=<?= $ano ?>" class="btn btn-default btn-sm">Ver tudo</a>
  </div>
  <div class="win-body" style="padding:0;">
    <div class="table-wrap">
      <table class="table-mobile">
        <thead>
          <tr>
            <th>Data</th>
            <th>Tipo</th>
            <th>Descrição</th>
            <th>Categoria</th>
            <th>Conta</th>
            <th>Valor</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($ultimos)): ?>
          <tr><td colspan="6" class="text-center" style="padding:20px;">Sem lançamentos recentes.</td></tr>
        <?php else: ?>
          <?php foreach ($ultimos as $l): ?>
          <tr>
            <td data-label="Data"><?= fmtDate($l['data']) ?></td>
            <td data-label="Tipo">
              <span class="badge <?= ($l['tipo'] ?? '') === 'saida' ? 'b-sai' : (($l['tipo'] ?? '') === 'entrada' ? 'b-ent' : '') ?>">
                <?= h(ucfirst((string)($l['tipo'] ?? '—'))) ?>
              </span>
            </td>
            <td data-label="Descrição"><?= h($l['descricao'] ?? '—') ?></td>
            <td data-label="Categoria"><?= h($l['cat'] ?? '—') ?></td>
            <td data-label="Conta"><?= h($l['conta_nome'] ?? '—') ?></td>
            <td data-label="Valor" class="money <?= ($l['tipo'] ?? '') === 'saida' ? 'neg' : 'pos' ?>">
              <?= fmt((float)(($l['tipo'] ?? '') === 'entrada' ? ($l['valor_liquido'] ?? $l['valor'] ?? 0) : ($l['valor'] ?? 0))) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$labelsMes = [];
$chartEntradas = [];
$chartSaidas = [];
$chartSaldos = [];
foreach ($evolucao as $mNum => $dados) {
    $labelsMes[] = abrevMes((int)$mNum);
    $chartEntradas[] = (float)($dados['e'] ?? 0);
    $chartSaidas[] = (float)($dados['s'] ?? 0);
    $chartSaldos[] = (float)($dados['saldo'] ?? 0);
}

$mixLabels = ['Entradas', 'Saídas', 'Transferências'];
$mixData = [$totEnt, $totSai, $totTrf];
$flowLabels = ['Recebido', 'Pago', 'Receber aberto', 'Pagar aberto'];
$flowData = [$cpRecebido, $cpPago, $cpReceberAberto, $cpPagarAberto];

$js[] = <<<JS
(function(){
  renderEvolucaoChart('evolChart', <?= json_encode($labelsMes, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($chartEntradas) ?>, <?= json_encode($chartSaidas) ?>, <?= json_encode($chartSaldos) ?>);
  renderDoughnut('mixChart', <?= json_encode($mixLabels, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($mixData) ?>);
  renderDoughnut('flowChart', <?= json_encode($flowLabels, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($flowData) ?>);

  const usdEl = document.getElementById('btcPrice');
  const updatedEl = document.getElementById('btcUpdated');
  const changeEl = document.getElementById('btcChange');
  const highEl = document.getElementById('btcHigh');
  const lowEl = document.getElementById('btcLow');
  const cardEl = document.getElementById('btcSpotCard');

  if (!usdEl || !updatedEl || !changeEl || !highEl || !lowEl || !cardEl) return;

  const meta = document.createElement('div');
  meta.className = 'btc-meta';
  meta.innerHTML = '<div class="btc-pill">BTC/USDT: <strong id="btcUsdLive">--<\/strong><\/div><div class="btc-pill">BTC/BRL: <strong id="btcBrlLive">--<\/strong><\/div>';
  cardEl.appendChild(meta);

  const usdLiveEl = document.getElementById('btcUsdLive');
  const brlLiveEl = document.getElementById('btcBrlLive');
  const moneyUsd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 });
  const moneyBrl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 2 });
  const pctFmt = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  let currentUsd = null;
  let currentBrl = null;
  let reconnectTimer = null;
  let ws = null;

  function setBadgeTrend(changePct) {
    changeEl.classList.remove('btc-positive', 'btc-negative');
    if (changePct > 0) changeEl.classList.add('btc-positive');
    if (changePct < 0) changeEl.classList.add('btc-negative');
  }

  function renderNow() {
    if (currentUsd !== null) {
      usdEl.textContent = moneyUsd.format(currentUsd);
      if (usdLiveEl) usdLiveEl.textContent = moneyUsd.format(currentUsd);
    }
    if (currentBrl !== null && brlLiveEl) {
      brlLiveEl.textContent = moneyBrl.format(currentBrl);
    }
  }

  async function fetchStats() {
    try {
      const resUsd = await fetch('https://api.binance.com/api/v3/ticker/24hr?symbol=BTCUSDT', { cache: 'no-store' });
      const resBrl = await fetch('https://api.binance.com/api/v3/ticker/24hr?symbol=BTCBRL', { cache: 'no-store' });
      if (!resUsd.ok || !resBrl.ok) throw new Error('Falha ao buscar cotações');
      const usd = await resUsd.json();
      const brl = await resBrl.json();

      currentUsd = Number(usd.lastPrice || usd.weightedAvgPrice || 0);
      currentBrl = Number(brl.lastPrice || brl.weightedAvgPrice || 0);
      renderNow();

      const changePct = Number(usd.priceChangePercent || 0);
      changeEl.textContent = (changePct >= 0 ? '+' : '') + pctFmt.format(changePct) + '%';
      setBadgeTrend(changePct);
      highEl.textContent = moneyUsd.format(Number(usd.highPrice || 0));
      lowEl.textContent = moneyUsd.format(Number(usd.lowPrice || 0));
      updatedEl.textContent = 'Sincronizado via Binance · ' + new Date().toLocaleString('pt-BR');
    } catch (err) {
      updatedEl.textContent = 'Falha ao sincronizar BTC agora. Tentando novamente...';
    }
  }

  function connectWs() {
    if (ws) { try { ws.close(); } catch (e) {} }
    ws = new WebSocket('wss://stream.binance.com:9443/stream?streams=btcusdt@bookTicker/btcbrl@bookTicker');

    ws.onmessage = function(event) {
      try {
        const payload = JSON.parse(event.data);
        const stream = payload && payload.stream ? String(payload.stream).toLowerCase() : '';
        const data = payload && payload.data ? payload.data : {};
        const bid = Number(data.b || 0);
        const ask = Number(data.a || 0);
        const price = bid > 0 && ask > 0 ? ((bid + ask) / 2) : (bid || ask || 0);
        if (!price) return;

        if (stream.indexOf('btcusdt') !== -1) currentUsd = price;
        if (stream.indexOf('btcbrl') !== -1) currentBrl = price;

        renderNow();
        updatedEl.textContent = 'Ao vivo via WebSocket · ' + new Date().toLocaleTimeString('pt-BR');
      } catch (e) {}
    };

    ws.onclose = function() {
      clearTimeout(reconnectTimer);
      reconnectTimer = setTimeout(connectWs, 2500);
    };

    ws.onerror = function() {
      try { ws.close(); } catch (e) {}
    };
  }

  fetchStats();
  connectWs();
  setInterval(fetchStats, 15000);
})();
JS;

require_once ROOT_PATH . '/src/layout_footer.php';
