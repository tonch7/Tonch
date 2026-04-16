<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();
requireSistemaLiberado();

$_aberto = mesAbertoAtual();
$ano = (int)($_GET['ano'] ?? $_aberto['ano']);
$mes = (int)($_GET['mes'] ?? $_aberto['mes']);
if ($mes < 1 || $mes > 12) $mes = $_aberto['mes'];
ensureMesAcessivel($mes, $ano, 'contas_pr');
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

$caixaDisponivel = caixaAberto($mes, $ano);
$mesAberto = mesAbertoAtual();

if (!function_exists('excluirLancamentoVinculadoContaPR')) {
    function excluirLancamentoVinculadoContaPR(array $cp): void
    {
        if (!empty($cp['lancamento_id'])) {
            db()->exec('DELETE FROM lancamentos WHERE id=?', [(int)$cp['lancamento_id']]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfOrAbort(url('public/contas_pr.php') . "?mes=$mes&ano=$ano");
    $a = $_POST['action'] ?? '';

    if ($a === 'inserir') {
        db()->insert(
            'INSERT INTO contas_pagar_receber (tipo, data_vencimento, valor, descricao, cliente_fornecedor, cliente_id, categoria_id, conta_id, metodo_id, observacoes)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $_POST['tipo'] ?? 'pagar',
                $_POST['data_vencimento'] ?? date('Y-m-d'),
                parseMoney($_POST['valor'] ?? '0'),
                trim($_POST['descricao'] ?? ''),
                trim($_POST['cliente_fornecedor'] ?? ''),
                !empty($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : null,
                !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null,
                !empty($_POST['conta_id']) ? (int)$_POST['conta_id'] : null,
                !empty($_POST['metodo_id']) ? (int)$_POST['metodo_id'] : null,
                trim($_POST['observacoes'] ?? ''),
            ]
        );
        flash('success', 'Conta cadastrada!');
        header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano");
        exit;
    }

    if ($a === 'salvar_edicao') {
        $cpId = (int)($_POST['id'] ?? 0);
        $cp   = db()->one('SELECT * FROM contas_pagar_receber WHERE id=?', [$cpId]);

        if (!$cp) {
            flash('error', 'Conta não encontrada.');
            header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano");
            exit;
        }

        $tipo           = $_POST['tipo'] ?? $cp['tipo'];
        $dataVencimento = $_POST['data_vencimento'] ?? $cp['data_vencimento'];
        $valor          = parseMoney($_POST['valor'] ?? (string)$cp['valor']);
        $descricao      = trim($_POST['descricao'] ?? $cp['descricao']);
        $cliFornecedor  = trim($_POST['cliente_fornecedor'] ?? ($cp['cliente_fornecedor'] ?? ''));
        $clienteId      = !empty($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : null;
        $categoriaId    = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
        $contaId        = !empty($_POST['conta_id']) ? (int)$_POST['conta_id'] : null;
        $metodoId       = !empty($_POST['metodo_id']) ? (int)$_POST['metodo_id'] : null;
        $observacoes    = trim($_POST['observacoes'] ?? ($cp['observacoes'] ?? ''));

        db()->exec(
            'UPDATE contas_pagar_receber
                SET tipo=?, data_vencimento=?, valor=?, descricao=?, cliente_fornecedor=?, cliente_id=?, categoria_id=?, conta_id=?, metodo_id=?, observacoes=?
              WHERE id=?',
            [$tipo, $dataVencimento, $valor, $descricao, $cliFornecedor, $clienteId, $categoriaId, $contaId, $metodoId, $observacoes, $cpId]
        );

        if ((int)$cp['pago_recebido'] === 1 && !empty($cp['lancamento_id'])) {
            $dataBaixaEdit = $_POST['data_baixa'] ?? $cp['data_baixa'];

            $valorEfetivoRaw = trim((string)($_POST['valor_efetivo'] ?? ''));
            $valorEfetivo = $valorEfetivoRaw === ''
                ? (float)($cp['valor_efetivo'] ?? $valor)
                : parseMoney($valorEfetivoRaw);

            $contaBaixaId  = !empty($_POST['conta_id_baixa']) ? (int)$_POST['conta_id_baixa'] : ($contaId ?: null);
            $metodoBaixaId = !empty($_POST['metodo_id_baixa']) ? (int)$_POST['metodo_id_baixa'] : ($metodoId ?: null);
            $taxaVal       = parseMoney($_POST['taxa_valor_baixa'] ?? '0');

            db()->exec(
                'UPDATE contas_pagar_receber
                    SET data_baixa=?, valor_efetivo=?, conta_id=?, metodo_id=?
                  WHERE id=?',
                [$dataBaixaEdit, $valorEfetivo, $contaBaixaId, $metodoBaixaId, $cpId]
            );

            $tipoLanc = $tipo === 'pagar' ? 'saida' : 'entrada';
            db()->exec(
                'UPDATE lancamentos
                    SET tipo=?, data=?, valor=?, descricao=?, categoria_id=?, conta_id=?, metodo_id=?, taxa_valor=?
                  WHERE id=?',
                [
                    $tipoLanc,
                    $dataBaixaEdit,
                    $valorEfetivo,
                    'Baixa: ' . $descricao,
                    $categoriaId,
                    $contaBaixaId,
                    $metodoBaixaId,
                    $taxaVal,
                    (int)$cp['lancamento_id']
                ]
            );
        }

        flash('success', 'Conta atualizada com sucesso!');
        header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano&stab=" . (((int)$cp['pago_recebido'] === 1) ? 'pagas' : 'pendentes'));
        exit;
    }

    if ($a === 'excluir') {
        $cpId = (int)($_POST['id'] ?? 0);
        $cp   = db()->one('SELECT * FROM contas_pagar_receber WHERE id=?', [$cpId]);

        if (!$cp) {
            flash('error', 'Conta não encontrada.');
            header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano");
            exit;
        }

        if ((int)$cp['pago_recebido'] === 1) {
            excluirLancamentoVinculadoContaPR($cp);
        }

        db()->exec('DELETE FROM contas_pagar_receber WHERE id=?', [$cpId]);
        flash('success', 'Conta excluída com sucesso!');
        header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano");
        exit;
    }

    if ($a === 'estornar_baixa') {
        $cpId = (int)($_POST['id'] ?? 0);
        $cp   = db()->one('SELECT * FROM contas_pagar_receber WHERE id=?', [$cpId]);

        if (!$cp) {
            flash('error', 'Conta não encontrada.');
            header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano&stab=pagas");
            exit;
        }

        if ((int)$cp['pago_recebido'] !== 1) {
            flash('error', 'Esta conta ainda não foi baixada/recebida.');
            header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano&stab=pagas");
            exit;
        }

        excluirLancamentoVinculadoContaPR($cp);

        db()->exec(
            'UPDATE contas_pagar_receber
                SET pago_recebido=0, data_baixa=NULL, valor_efetivo=NULL, lancamento_id=NULL
              WHERE id=?',
            [$cpId]
        );

        flash('success', 'Baixa estornada com sucesso!');
        header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano&stab=pendentes");
        exit;
    }

    if ($a === 'baixar') {
        $cpId      = (int)($_POST['id'] ?? 0);
        $cp        = db()->one('SELECT * FROM contas_pagar_receber WHERE id=?', [$cpId]);
        $dataBaixa = $_POST['data_baixa'] ?? date('Y-m-d');

        if (!$cp) {
            flash('error', 'Conta não encontrada.');
            header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano");
            exit;
        }

        $mesBaixa = (int)date('m', strtotime($dataBaixa));
        $anoBaixa = (int)date('Y', strtotime($dataBaixa));
        if (!caixaAberto($mesBaixa, $anoBaixa)) {
            $abrt = mesAbertoAtual();
            flash('error', '🔒 CAIXA FECHADO / AINDA NÃO INICIADO — A data informada (' . fmtDate($dataBaixa) . ') pertence a um mês sem caixa aberto. Por gentileza efetue novamente com apontamento no mês ' . nomeMes($abrt['mes']) . '/' . $abrt['ano'] . ' que está em ABERTO.');
            header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano");
            exit;
        }

        $valorEfetivoRaw = trim((string)($_POST['valor_efetivo'] ?? ''));
        $valEfetivo = $valorEfetivoRaw === '' ? (float)$cp['valor'] : parseMoney($valorEfetivoRaw);

        $contaId    = !empty($_POST['conta_id_baixa']) ? (int)$_POST['conta_id_baixa'] : $cp['conta_id'];
        $metodoId   = !empty($_POST['metodo_id_baixa']) ? (int)$_POST['metodo_id_baixa'] : $cp['metodo_id'];
        $taxaVal    = parseMoney($_POST['taxa_valor_baixa'] ?? '0');

        if ($contaId) {
            $tipoLanc = $cp['tipo'] === 'pagar' ? 'saida' : 'entrada';

            $lancId = registrarLancamento([
                'tipo'         => $tipoLanc,
                'data'         => $dataBaixa,
                'valor'        => $valEfetivo,
                'descricao'    => 'Baixa: ' . $cp['descricao'],
                'categoria_id' => $cp['categoria_id'],
                'conta_id'     => $contaId,
                'metodo_id'    => $metodoId,
                'taxa_valor'   => $taxaVal,
                'origem_id'    => $cpId,
            ]);

            db()->exec(
                'UPDATE contas_pagar_receber
                    SET pago_recebido=1, data_baixa=?, valor_efetivo=?, conta_id=?, metodo_id=?, lancamento_id=?
                  WHERE id=?',
                [$dataBaixa, $valEfetivo, $contaId, $metodoId, $lancId, $cpId]
            );
            flash('success', 'Baixa registrada e lançamento criado!');
        } else {
            flash('error', 'Selecione a conta para baixa.');
        }

        header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano");
        exit;
    }
}

$hoje     = date('Y-m-d');
$limite7  = date('Y-m-d', strtotime('+7 days'));
$contas   = db()->all('SELECT * FROM contas WHERE ativa=1 ORDER BY nome');
$clientes = db()->all('SELECT * FROM clientes ORDER BY nome');
$catList  = db()->all('SELECT * FROM categorias ORDER BY tipo, nome');
$metodos  = db()->all('SELECT * FROM metodos WHERE interno=0 ORDER BY nome');

$inicioMes = sprintf('%04d-%02d-01', $ano, $mes);
$fimMes    = date('Y-m-t', strtotime($inicioMes));

$registrosCompetencia = db()->all(
    'SELECT cpr.*, cl.nome cli_nome
       FROM contas_pagar_receber cpr
  LEFT JOIN clientes cl ON cpr.cliente_id = cl.id
      WHERE cpr.data_vencimento BETWEEN ? AND ?
   ORDER BY cpr.data_vencimento, cpr.id DESC',
    [$inicioMes, $fimMes]
);

$pendentes = array_values(array_filter($registrosCompetencia, function ($c) {
    return (int)$c['pago_recebido'] === 0;
}));

$pagas = array_values(array_filter($registrosCompetencia, function ($c) {
    return (int)$c['pago_recebido'] === 1;
}));

$vencidas  = array_values(array_filter($pendentes, function ($c) use ($hoje) {
    return $c['data_vencimento'] < $hoje;
}));
$proximas  = array_values(array_filter($pendentes, function ($c) use ($hoje, $limite7) {
    return $c['data_vencimento'] >= $hoje && $c['data_vencimento'] <= $limite7;
}));
$futuras   = array_values(array_filter($pendentes, function ($c) use ($limite7) {
    return $c['data_vencimento'] > $limite7;
}));

$registroEdicao = null;
if ($action === 'editar' && $id > 0) {
    $registroEdicao = db()->one('SELECT * FROM contas_pagar_receber WHERE id=?', [$id]);
    if (!$registroEdicao) {
        flash('error', 'Registro não encontrado para edição.');
        header('Location: ' . url('public/contas_pr.php') . "?mes=$mes&ano=$ano");
        exit;
    }
}

$totalEsperadoPagar = array_sum(array_map(function ($c) {
    return $c['tipo'] === 'pagar' ? (float)$c['valor'] : 0;
}, $registrosCompetencia));

$totalEsperadoReceber = array_sum(array_map(function ($c) {
    return $c['tipo'] === 'receber' ? (float)$c['valor'] : 0;
}, $registrosCompetencia));

$totalJaPago = array_sum(array_map(function ($c) {
    return $c['tipo'] === 'pagar' ? (float)($c['valor_efetivo'] ?? $c['valor']) : 0;
}, $pagas));

$totalJaRecebido = array_sum(array_map(function ($c) {
    return $c['tipo'] === 'receber' ? (float)($c['valor_efetivo'] ?? $c['valor']) : 0;
}, $pagas));

$totalPagar = array_sum(array_map(function ($c) {
    return $c['tipo'] === 'pagar' ? (float)$c['valor'] : 0;
}, $pendentes));

$totalReceber = array_sum(array_map(function ($c) {
    return $c['tipo'] === 'receber' ? (float)$c['valor'] : 0;
}, $pendentes));

$totalEsperadoGeral = $totalEsperadoPagar + $totalEsperadoReceber;
$totalRealizadoGeral = $totalJaPago + $totalJaRecebido;

$saldoProjetado = $totalReceber - $totalPagar;
$saldoEsperado  = $totalEsperadoReceber - $totalEsperadoPagar;
$saldoRealizado = $totalJaRecebido - $totalJaPago;

$diferencaPagar   = $totalJaPago - $totalEsperadoPagar;
$diferencaReceber = $totalJaRecebido - $totalEsperadoReceber;
$diferencaSaldo   = $saldoRealizado - $saldoEsperado;
$diferencaGeral   = $totalRealizadoGeral - $totalEsperadoGeral;

$totalProximasPagar    = array_sum(array_map(fn($c) => $c['tipo'] === 'pagar'    ? (float)$c['valor'] : 0, $proximas));
$totalProximasReceber  = array_sum(array_map(fn($c) => $c['tipo'] === 'receber'  ? (float)$c['valor'] : 0, $proximas));

$pageTitle  = 'Contas P/R';
$activePage = 'contas_pr';
require_once ROOT_PATH . '/src/layout_header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     BLOCO 1 — TOTAL CADASTRADO PARA RECEBER / PAGAR (pendentes)
     Toda conta cadastrada e ainda não baixada entra aqui.
════════════════════════════════════════════════════════════════ -->
<div class="dash-section-label">📋 Total Cadastrado para Receber / Pagar</div>
<div class="grid-4 mb-4">
  <div class="scard scard-group">
    <div class="lbl">&#8595; A Pagar (pendente)</div>
    <div class="val val-neg"><?= fmt($totalPagar) ?></div>
  </div>
  <div class="scard scard-group">
    <div class="lbl">&#8593; A Receber (pendente)</div>
    <div class="val val-pos"><?= fmt($totalReceber) ?></div>
  </div>
  <div class="scard scard-group">
    <div class="lbl">&#9888; Contas Vencidas</div>
    <div class="val val-neg"><?= count($vencidas) ?></div>
  </div>
  <div class="scard scard-group">
    <div class="lbl">Saldo Projetado</div>
    <div class="val <?= $saldoProjetado >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($saldoProjetado) ?></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     BLOCO 2 — TOTAL GERAL JÁ RECEBIDO / PAGO (baixados — ACUMULADO)
     Soma de todas as baixas do período selecionado, sem misturar
     com pendentes. Subdividido em PAGO NO MÊS e RECEBIDO NO MÊS.
════════════════════════════════════════════════════════════════ -->
<div class="dash-section-label">✅ Total Geral Já Recebido / Pago</div>
<div class="grid-4 mb-4">
  <div class="scard scard-done">
    <div class="lbl">&#8595; Total Pago no Mês</div>
    <div class="val val-neg"><?= fmt($totalJaPago) ?></div>
  </div>
  <div class="scard scard-done">
    <div class="lbl">&#8593; Total Recebido no Mês</div>
    <div class="val val-pos"><?= fmt($totalJaRecebido) ?></div>
  </div>
  <div class="scard scard-done">
    <div class="lbl">Baixado: Entradas − Saídas</div>
    <div class="val <?= $saldoRealizado >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($totalJaRecebido) ?> − <?= fmt($totalJaPago) ?></div>
    <div class="lbl-sub">Líquido: <?= fmt($saldoRealizado) ?></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     BLOCO 3 — RESUMO DA OPERAÇÃO (comparativo esperado × realizado)
════════════════════════════════════════════════════════════════ -->
<div class="dash-section-label">📊 Resumo da Operação</div>
<div class="grid-4 mb-12">
  <div class="scard">
    <div class="lbl">Esperado Pagar</div>
    <div class="val val-neg"><?= fmt($totalEsperadoPagar) ?></div>
    <div class="lbl-sub">Já pago: <?= fmt($totalJaPago) ?></div>
  </div>
  <div class="scard">
    <div class="lbl">Esperado Receber</div>
    <div class="val val-pos"><?= fmt($totalEsperadoReceber) ?></div>
    <div class="lbl-sub">Já recebido: <?= fmt($totalJaRecebido) ?></div>
  </div>
  <div class="scard">
    <div class="lbl">Saldo Esperado</div>
    <div class="val <?= $saldoEsperado >= 0 ? 'val-pos' : 'val-neg' ?>"><?= fmt($saldoEsperado) ?></div>
    <div class="lbl-sub">Realizado vs Esperado: <?= fmt($diferencaSaldo) ?></div>
  </div>
  <div class="scard" style="border-top:3px solid #e07b00;">
    <div class="lbl">⏰ Vencem em 7 dias (<?= count($proximas) ?>)</div>
    <div class="val val-neg"><?= fmt($totalProximasPagar) ?></div>
    <div class="lbl-sub">A receber: <?= fmt($totalProximasReceber) ?></div>
  </div>
</div>

<style>
.dash-section-label {
  font-size: .72rem;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #666;
  margin: 0 0 6px 2px;
  padding-left: 2px;
  border-left: 3px solid #888;
  padding-left: 8px;
}
.scard-group { border-top: 3px solid #e0a800; }
.scard-done  { border-top: 3px solid #28a745; }
.lbl-sub {
  font-size: .68rem;
  color: #888;
  margin-top: 4px;
}
</style>

<div class="win">
  <div class="win-title">
    &#128203; Contas a Pagar / Receber
    <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&action=nova" class="btn btn-primary btn-sm">+ Nova Conta</a>
  </div>

  <div style="background:#e8e8e8;padding:6px 8px 0;display:flex;gap:2px;border-bottom:1px solid #aaa;">
    <?php
    $stab = $_GET['stab'] ?? 'pendentes';
    $tabs = ['pendentes' => 'Pendentes (' . count($pendentes) . ')', 'pagas' => 'Pagas/Recebidas'];
    foreach ($tabs as $k => $l): ?>
    <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&stab=<?= $k ?>"
       class="mtab <?= $stab === $k ? 'active' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($stab === 'pendentes'): ?>
  <div style="padding:0;">
    <div class="table-wrap">
      <table class="table-mobile">
        <thead><tr>
          <th>Tipo</th><th>Vencimento</th><th>Descrição</th>
          <th>Fornecedor/Cliente</th><th class="text-right">Valor</th><th>Status</th><th>Ações</th>
        </tr></thead>
        <tbody>
          <?php if (!$pendentes): ?>
          <tr>
            <td colspan="7" style="text-align:center;padding:18px;">Nenhuma conta pendente nesta competência.</td>
          </tr>
          <?php endif; ?>

          <?php foreach ($pendentes as $cp):
            $isVenc = $cp['data_vencimento'] < $hoje;
            $isProx = !$isVenc && $cp['data_vencimento'] <= $limite7;
          ?>
          <tr style="<?= $isVenc ? 'background:#fff5f5;' : ($isProx ? 'background:#fffbe6;' : '') ?>">
            <td data-label="Tipo">
              <span class="badge <?= $cp['tipo'] === 'pagar' ? 'b-sai' : 'b-ent' ?>">
                <?= $cp['tipo'] === 'pagar' ? '&#8595; Pagar' : '&#8593; Receber' ?>
              </span>
            </td>
            <td data-label="Vencimento">
              <?= fmtDate($cp['data_vencimento']) ?>
              <?= $isVenc ? ' <span class="badge b-venc">VENCIDA</span>' : ($isProx ? ' <span class="badge b-pend">7 dias</span>' : '') ?>
            </td>
            <td data-label="Descrição"><?= h($cp['descricao']) ?></td>
            <td data-label="Fornecedor"><?= h($cp['cli_nome'] ?? $cp['cliente_fornecedor'] ?? '—') ?></td>
            <td data-label="Valor" class="money <?= $cp['tipo'] === 'pagar' ? 'neg' : 'pos' ?>"><?= fmt($cp['valor']) ?></td>
            <td><span class="badge b-pend">Pendente</span></td>
            <td style="white-space:nowrap;">
              <button class="btn btn-success btn-xs" onclick="openModal('modalBaixa_<?= $cp['id'] ?>')">
                <?= $cp['tipo'] === 'pagar' ? '✓ Pagar' : '✓ Receber' ?>
              </button>
              <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&stab=pendentes&action=editar&id=<?= $cp['id'] ?>" class="btn btn-default btn-xs">✎ Editar</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Excluir esta conta pendente?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="excluir">
                <input type="hidden" name="id" value="<?= $cp['id'] ?>">
                <button type="submit" class="btn btn-danger btn-xs">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php else: ?>
  <div style="padding:0;">
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Tipo</th><th>Vencimento</th><th>Descrição</th>
          <th class="text-right">Valor</th><th>Baixa</th><th class="text-right">Valor Pago</th><th>Ações</th>
        </tr></thead>
        <tbody>
          <?php if (!$pagas): ?>
          <tr>
            <td colspan="7" style="text-align:center;padding:18px;">Nenhuma conta paga/recebida nesta competência.</td>
          </tr>
          <?php endif; ?>

          <?php foreach ($pagas as $cp): ?>
          <tr>
            <td><span class="badge <?= $cp['tipo'] === 'pagar' ? 'b-sai' : 'b-ent' ?>"><?= $cp['tipo'] === 'pagar' ? 'Pagar' : 'Receber' ?></span></td>
            <td><?= fmtDate($cp['data_vencimento']) ?></td>
            <td><?= h($cp['descricao']) ?></td>
            <td class="money"><?= fmt($cp['valor']) ?></td>
            <td><?= fmtDate($cp['data_baixa']) ?></td>
            <td class="money <?= $cp['tipo'] === 'pagar' ? 'neg' : 'pos' ?>"><?= fmt($cp['valor_efetivo'] ?? $cp['valor']) ?></td>
            <td style="white-space:nowrap;">
              <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&stab=pagas&action=editar&id=<?= $cp['id'] ?>" class="btn btn-default btn-xs">✎ Editar</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Estornar a baixa e devolver para pendentes?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="estornar_baixa">
                <input type="hidden" name="id" value="<?= $cp['id'] ?>">
                <button type="submit" class="btn btn-warning btn-xs">↺ Estornar</button>
              </form>
              <form method="post" style="display:inline;" onsubmit="return confirm('Excluir esta conta já baixada? O lançamento vinculado também será removido.');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="excluir">
                <input type="hidden" name="id" value="<?= $cp['id'] ?>">
                <button type="submit" class="btn btn-danger btn-xs">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php foreach ($pendentes as $cp): ?>
<div class="modal-bg" id="modalBaixa_<?= $cp['id'] ?>">
  <div class="modal" style="max-width:460px;">
    <div class="modal-head">
      <h3>✓ <?= $cp['tipo'] === 'pagar' ? 'Registrar Pagamento' : 'Registrar Recebimento' ?></h3>
      <button class="modal-close">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="baixar">
      <input type="hidden" name="id" value="<?= $cp['id'] ?>">
      <div class="modal-body">
        <p class="text-sm mb-8"><strong><?= h($cp['descricao']) ?></strong> — Valor original: <?= fmt($cp['valor']) ?></p>
        <div class="form-row">
          <div class="fg w-half">
            <label>Data da Baixa *</label>
            <input type="date" name="data_baixa" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="fg w-half">
            <label>Valor Efetivo (R$)</label>
            <input type="text" name="valor_efetivo" class="money-input" value="<?= number_format((float)$cp['valor'], 2, ',', '.') ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-half">
            <label>Conta *</label>
            <select name="conta_id_baixa" required>
              <option value="">— selecione —</option>
              <?php foreach ($contas as $ct): ?>
                <option value="<?= $ct['id'] ?>" <?= $cp['conta_id'] == $ct['id'] ? 'selected' : '' ?>>
                  <?= h($ct['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg w-half">
            <label>Método</label>
            <select name="metodo_id_baixa">
              <option value="">— selecione —</option>
              <?php foreach ($metodos as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $cp['metodo_id'] == $m['id'] ? 'selected' : '' ?>>
                  <?= h($m['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="fg">
          <label>Taxa / Desconto (R$)</label>
          <input type="text" name="taxa_valor_baixa" class="money-input" value="0,00">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-default modal-close">Cancelar</button>
        <button type="submit" class="btn btn-success">✓ Confirmar</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php if ($action === 'nova'): ?>
<div class="modal-bg open">
  <div class="modal">
    <div class="modal-head"><h3>+ Nova Conta a Pagar/Receber</h3><button class="modal-close">✕</button></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="inserir">
      <div class="modal-body">
        <div class="form-row">
          <div class="fg w-3rd"><label>Tipo *</label>
            <select name="tipo"><option value="pagar">A Pagar</option><option value="receber">A Receber</option></select></div>
          <div class="fg w-3rd"><label>Vencimento *</label>
            <input type="date" name="data_vencimento" value="<?= date('Y-m-d') ?>" required></div>
          <div class="fg w-3rd"><label>Valor (R$) *</label>
            <input type="text" name="valor" class="money-input" placeholder="0,00" required></div>
        </div>
        <div class="fg mb-8"><label>Descrição</label><input type="text" name="descricao"></div>
        <div class="form-row">
          <div class="fg w-half"><label>Fornecedor / Cliente</label>
            <input type="text" name="cliente_fornecedor"></div>
          <div class="fg w-half"><label>Vincular Cliente</label>
            <select name="cliente_id"><option value="">—</option>
              <?php foreach ($clientes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= h($c['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-half"><label>Categoria</label>
            <select name="categoria_id"><option value="">—</option>
              <?php foreach ($catList as $c): ?>
                <option value="<?= $c['id'] ?>"><?= h($c['nome']) ?> (<?= $c['tipo'] ?>)</option>
              <?php endforeach; ?>
            </select></div>
          <div class="fg w-half"><label>Conta Bancária</label>
            <select name="conta_id"><option value="">—</option>
              <?php foreach ($contas as $c): ?>
                <option value="<?= $c['id'] ?>"><?= h($c['nome']) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="fg"><label>Método</label>
          <select name="metodo_id"><option value="">—</option>
            <?php foreach ($metodos as $m): ?>
              <option value="<?= $m['id'] ?>"><?= h($m['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Observações</label><textarea name="observacoes" rows="2"></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-default modal-close">Cancelar</button>
        <button type="submit" class="btn btn-primary">✓ Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($action === 'editar' && $registroEdicao): ?>
<div class="modal-bg open">
  <div class="modal" style="max-width:700px;">
    <div class="modal-head"><h3>✎ Editar Conta</h3><button class="modal-close">✕</button></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="salvar_edicao">
      <input type="hidden" name="id" value="<?= (int)$registroEdicao['id'] ?>">
      <div class="modal-body">
        <div class="form-row">
          <div class="fg w-3rd"><label>Tipo *</label>
            <select name="tipo">
              <option value="pagar" <?= $registroEdicao['tipo'] === 'pagar' ? 'selected' : '' ?>>A Pagar</option>
              <option value="receber" <?= $registroEdicao['tipo'] === 'receber' ? 'selected' : '' ?>>A Receber</option>
            </select>
          </div>
          <div class="fg w-3rd"><label>Vencimento *</label>
            <input type="date" name="data_vencimento" value="<?= h($registroEdicao['data_vencimento']) ?>" required>
          </div>
          <div class="fg w-3rd"><label>Valor (R$) *</label>
            <input type="text" name="valor" class="money-input" value="<?= number_format((float)$registroEdicao['valor'], 2, ',', '.') ?>" required>
          </div>
        </div>

        <div class="fg mb-8"><label>Descrição</label>
          <input type="text" name="descricao" value="<?= h($registroEdicao['descricao']) ?>">
        </div>

        <div class="form-row">
          <div class="fg w-half"><label>Fornecedor / Cliente</label>
            <input type="text" name="cliente_fornecedor" value="<?= h($registroEdicao['cliente_fornecedor'] ?? '') ?>">
          </div>
          <div class="fg w-half"><label>Vincular Cliente</label>
            <select name="cliente_id">
              <option value="">—</option>
              <?php foreach ($clientes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (int)$registroEdicao['cliente_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="fg w-half"><label>Categoria</label>
            <select name="categoria_id">
              <option value="">—</option>
              <?php foreach ($catList as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (int)$registroEdicao['categoria_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['nome']) ?> (<?= $c['tipo'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg w-half"><label>Conta Bancária</label>
            <select name="conta_id">
              <option value="">—</option>
              <?php foreach ($contas as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (int)$registroEdicao['conta_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="fg"><label>Método</label>
          <select name="metodo_id">
            <option value="">—</option>
            <?php foreach ($metodos as $m): ?>
              <option value="<?= $m['id'] ?>" <?= (int)$registroEdicao['metodo_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="fg"><label>Observações</label>
          <textarea name="observacoes" rows="2"><?= h($registroEdicao['observacoes'] ?? '') ?></textarea>
        </div>

        <?php if ((int)$registroEdicao['pago_recebido'] === 1): ?>
        <hr style="margin:14px 0;">
        <div class="fg mb-8"><strong>Dados da baixa / recebimento</strong></div>
        <div class="form-row">
          <div class="fg w-3rd"><label>Data da Baixa</label>
            <input type="date" name="data_baixa" value="<?= h($registroEdicao['data_baixa']) ?>">
          </div>
          <div class="fg w-3rd"><label>Valor Efetivo (R$)</label>
            <input type="text" name="valor_efetivo" class="money-input" value="<?= number_format((float)($registroEdicao['valor_efetivo'] ?? $registroEdicao['valor']), 2, ',', '.') ?>">
          </div>
          <div class="fg w-3rd"><label>Taxa / Desconto (R$)</label>
            <input type="text" name="taxa_valor_baixa" class="money-input" value="0,00">
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-half"><label>Conta da Baixa</label>
            <select name="conta_id_baixa">
              <option value="">—</option>
              <?php foreach ($contas as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (int)$registroEdicao['conta_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg w-half"><label>Método da Baixa</label>
            <select name="metodo_id_baixa">
              <option value="">—</option>
              <?php foreach ($metodos as $m): ?>
                <option value="<?= $m['id'] ?>" <?= (int)$registroEdicao['metodo_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-foot">
        <a href="<?= url('public/contas_pr.php') . '?mes=' . $mes . '&ano=' . $ano . '&stab=' . (((int)$registroEdicao['pago_recebido'] === 1) ? 'pagas' : 'pendentes') ?>" class="btn btn-default">Cancelar</a>
        <button type="submit" class="btn btn-primary">✓ Salvar Alterações</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/src/layout_footer.php'; ?>