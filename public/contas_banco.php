<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();
requireSistemaLiberado();

$_aberto = mesAbertoAtual();
$ano = (int)($_GET['ano'] ?? $_aberto['ano']);
$mes = (int)($_GET['mes'] ?? $_aberto['mes']);
if ($mes < 1 || $mes > 12) $mes = $_aberto['mes'];
ensureMesAcessivel($mes, $ano, 'contas_banco');
$fechado = mesFechado($mes, $ano);
$action  = $_GET['action'] ?? '';
$id      = (int)($_GET['id'] ?? 0);

$caixaDisponivel = caixaAberto($mes, $ano);
$mesAberto = mesAbertoAtual();

// ---- POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfOrAbort(url('public/contas_banco.php') . "?mes=$mes&ano=$ano");
    $a = $_POST['action'] ?? '';

    // Criar conta — permitido independente do mês
    if ($a === 'criar_conta') {
        $nome = trim($_POST['nome'] ?? '');
        $tipo = $_POST['tipo_conta'] ?? 'corrente';
        $si   = parseMoney($_POST['saldo_inicial'] ?? '0');
        if ($nome) {
            $contaId = db()->insert(
                'INSERT INTO contas (nome, tipo_conta, saldo_inicial) VALUES (?,?,?)',
                [$nome, $tipo, $si]
            );
            // Registra saldo inicial no mês corrente
            db()->exec(
                'INSERT OR REPLACE INTO saldos_iniciais (conta_id, mes, ano, valor) VALUES (?,?,?,?)',
                [$contaId, $mes, $ano, $si]
            );
            flash('success', "Conta '$nome' criada!");
        }
        header('Location: ' . url('public/contas_banco.php') . "?mes=$mes&ano=$ano"); exit;
    }

    // Editar conta
    if ($a === 'editar_conta' && $id > 0) {
        db()->exec('UPDATE contas SET nome=?, tipo_conta=? WHERE id=?',
            [trim($_POST['nome']??''), $_POST['tipo_conta']??'corrente', $id]);
        flash('success', 'Conta atualizada!');
        header('Location: ' . url('public/contas_banco.php') . "?mes=$mes&ano=$ano"); exit;
    }

    // Salvar saldo inicial do mês — apenas se caixa disponível
    if ($a === 'salvar_saldo_inicial') {
        if (!$caixaDisponivel) {
            flash('error', '🔒 CAIXA FECHADO / AINDA NÃO INICIADO — Por gentileza efetue novamente com apontamento no mês ' . nomeMes($mesAberto['mes']) . '/' . $mesAberto['ano'] . ' que está em ABERTO.');
            header('Location: ' . url('public/contas_banco.php') . "?mes={$mesAberto['mes']}&ano={$mesAberto['ano']}"); exit;
        }
        foreach ($_POST['saldo'] as $contaId => $valor) {
            db()->exec(
                'INSERT OR REPLACE INTO saldos_iniciais (conta_id, mes, ano, valor) VALUES (?,?,?,?)',
                [(int)$contaId, $mes, $ano, parseMoney($valor)]
            );
        }
        flash('success', 'Saldos iniciais atualizados!');
        header('Location: ' . url('public/contas_banco.php') . "?mes=$mes&ano=$ano"); exit;
    }
}


$contas    = db()->all('SELECT * FROM contas WHERE ativa=1 ORDER BY nome');
$editConta = ($action === 'editar' && $id > 0)
    ? db()->one('SELECT * FROM contas WHERE id=?', [$id])
    : null;

// Extrato: todos os lançamentos da conta selecionada no mês
$contaExtrato = (int)($_GET['extrato'] ?? 0);
$extrato = [];
if ($contaExtrato) {
    $extrato = db()->all(
        'SELECT l.*, c.nome cat, m.nome met_nome, ct2.nome conta_dest
         FROM lancamentos l
         LEFT JOIN categorias c ON l.categoria_id=c.id
         LEFT JOIN metodos m ON l.metodo_id=m.id
         LEFT JOIN contas ct2 ON l.conta_destino_id=ct2.id
         WHERE (l.conta_id=? OR l.conta_destino_id=?) AND l.mes=? AND l.ano=?
         ORDER BY l.data, l.criado_em',
        [$contaExtrato, $contaExtrato, $mes, $ano]
    );
}

$pageTitle  = 'Contas Bancárias';
$activePage = 'contas_banco';
require_once ROOT_PATH . '/src/layout_header.php';
?>

<div class="flex flex-btwn flex-center mb-12">
  <h2 style="font-size:15px;font-weight:bold;">&#127974; Contas Bancárias — <?= nomeMes($mes) ?>/<?= $ano ?></h2>
  <div class="flex flex-gap">
    <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&action=nova_conta" class="btn btn-primary btn-sm">+ Nova Conta</a>
    <?php if (!$fechado): ?>
    <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&action=saldo_inicial" class="btn btn-default btn-sm">⚙ Saldos Iniciais</a>
    <?php endif; ?>
  </div>
</div>

<!-- CARDS DAS CONTAS -->
<div class="grid-auto mb-12">
  <?php foreach ($contas as $c):
    $saldo = saldoConta($c['id'], $mes, $ano);
    $si    = db()->one('SELECT valor FROM saldos_iniciais WHERE conta_id=? AND mes=? AND ano=?', [$c['id'],$mes,$ano]);
    $siVal = $si ? (float)$si['valor'] : 0;

    $totEnt = (float)(db()->one('SELECT SUM(COALESCE(valor_liquido,valor)) t FROM lancamentos WHERE conta_id=? AND tipo="entrada" AND mes=? AND ano=?',[$c['id'],$mes,$ano])['t']??0);
    $totSai = (float)(db()->one('SELECT SUM(valor) t FROM lancamentos WHERE conta_id=? AND tipo IN ("saida","transferencia") AND mes=? AND ano=?',[$c['id'],$mes,$ano])['t']??0);
    $totRec = (float)(db()->one('SELECT SUM(valor) t FROM lancamentos WHERE conta_destino_id=? AND tipo="transferencia" AND mes=? AND ano=?',[$c['id'],$mes,$ano])['t']??0);
  ?>
  <div class="win">
    <div class="win-title" style="font-size:13px;">
      <span>&#127974; <?= h($c['nome']) ?> <span class="badge b-fech" style="font-size:9px;"><?= ucfirst($c['tipo_conta']) ?></span></span>
      <div class="flex flex-gap">
        <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&extrato=<?= $c['id'] ?>" class="btn btn-default btn-xs">Extrato</a>
        <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&action=editar&id=<?= $c['id'] ?>" class="btn btn-default btn-xs">✎</a>
      </div>
    </div>
    <div class="win-body">
      <div style="font-size:22px;font-family:var(--mono);font-weight:bold;text-align:center;
                  color:<?= $saldo>=0 ? 'var(--green)' : 'var(--red)' ?>;margin-bottom:10px;">
        <?= fmt($saldo) ?>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:11px;">
        <div style="background:#f0fff0;padding:4px 8px;border-radius:3px;">
          <div style="color:#888;">Saldo inicial</div>
          <div class="text-mono text-green"><?= fmt($siVal) ?></div>
        </div>
        <div style="background:#f0fff0;padding:4px 8px;border-radius:3px;">
          <div style="color:#888;">+ Entradas</div>
          <div class="text-mono text-green"><?= fmt($totEnt) ?></div>
        </div>
        <div style="background:#fff0f0;padding:4px 8px;border-radius:3px;">
          <div style="color:#888;">- Saídas</div>
          <div class="text-mono text-red"><?= fmt($totSai) ?></div>
        </div>
        <div style="background:#f0f0ff;padding:4px 8px;border-radius:3px;">
          <div style="color:#888;">+ Transf. recebidas</div>
          <div class="text-mono text-blue"><?= fmt($totRec) ?></div>
        </div>
      </div>
      <div style="margin-top:10px;">
        <a href="lancamentos.php?mes=<?= $mes ?>&ano=<?= $ano ?>&action=novo" class="btn btn-success btn-sm" style="width:100%;text-align:center;">+ Lançamento nesta conta</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- EXTRATO DA CONTA -->
<?php if ($contaExtrato && !empty($extrato)):
  $contaInfo = db()->one('SELECT * FROM contas WHERE id=?', [$contaExtrato]);
?>
<div class="win mb-12">
  <div class="win-title">
    Extrato: <?= h($contaInfo['nome'] ?? '') ?> — <?= nomeMes($mes) ?>/<?= $ano ?>
    <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>" class="btn btn-default btn-xs">Fechar extrato</a>
  </div>
  <div style="padding:0;">
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Data</th><th>Tipo</th><th>Descrição</th><th>Categoria</th><th>Método</th>
          <th class="text-right">Valor</th><th class="text-right">Saldo</th>
        </tr></thead>
        <tbody>
          <?php
          $si = db()->one('SELECT valor FROM saldos_iniciais WHERE conta_id=? AND mes=? AND ano=?', [$contaExtrato,$mes,$ano]);
          $saldoAcc = $si ? (float)$si['valor'] : 0;
          ?>
          <tr style="background:#eef;">
            <td colspan="5" style="font-style:italic;color:#666;">Saldo inicial do mês</td>
            <td class="money">—</td>
            <td class="money <?= $saldoAcc>=0?'pos':'neg' ?>"><?= fmt($saldoAcc) ?></td>
          </tr>
          <?php foreach ($extrato as $l):
            $eDestino = $l['conta_destino_id'] == $contaExtrato;
            $isEnt = ($l['tipo']==='entrada') || ($l['tipo']==='transferencia' && $eDestino);
            $valor = $isEnt ? ($l['valor_liquido'] ?? $l['valor']) : $l['valor'];
            if ($isEnt) $saldoAcc += $valor;
            else $saldoAcc -= $valor;
          ?>
          <tr>
            <td><?= fmtDate($l['data']) ?></td>
            <td><span class="badge <?= $isEnt?'b-ent':'b-sai' ?>"><?= $isEnt?'Entrada':'Saída' ?><?= $l['tipo']==='transferencia'?' (Transf.)':'' ?></span></td>
            <td>
              <?= h($l['descricao']) ?>
              <?php if ($l['tipo']==='transferencia' && $eDestino): ?>
                <span class="text-muted text-sm"> ← Recebida</span>
              <?php elseif ($l['tipo']==='transferencia'): ?>
                <span class="text-muted text-sm"> → <?= h($l['conta_dest']??'') ?></span>
              <?php endif; ?>
            </td>
            <td><?= h($l['cat'] ?? '—') ?></td>
            <td><?= h($l['met_nome'] ?? '—') ?></td>
            <td class="money <?= $isEnt?'pos':'neg' ?>"><?= fmt($valor) ?></td>
            <td class="money <?= $saldoAcc>=0?'pos':'neg' ?>"><?= fmt($saldoAcc) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL SALDOS INICIAIS -->
<?php if ($action === 'saldo_inicial' && !$fechado): ?>
<div class="modal-bg open">
  <div class="modal">
    <div class="modal-head"><h3>⚙ Saldos Iniciais — <?= nomeMes($mes) ?>/<?= $ano ?></h3><button class="modal-close">✕</button></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="salvar_saldo_inicial">
      <div class="modal-body">
        <p class="text-muted text-sm mb-8">Informe o saldo inicial de cada conta no início de <?= nomeMes($mes) ?>/<?= $ano ?>.</p>
        <?php foreach ($contas as $c):
          $si = db()->one('SELECT valor FROM saldos_iniciais WHERE conta_id=? AND mes=? AND ano=?', [$c['id'],$mes,$ano]);
          // Sugere saldo final do mês anterior
          $mesPrev = $mes == 1 ? 12 : $mes - 1;
          $anoPrev = $mes == 1 ? $ano - 1 : $ano;
          $siPrev  = saldoConta($c['id'], $mesPrev, $anoPrev);
        ?>
        <div class="form-row">
          <div class="fg w-half">
            <label><?= h($c['nome']) ?> (<?= ucfirst($c['tipo_conta']) ?>)</label>
            <input type="text" name="saldo[<?= $c['id'] ?>]" class="money-input"
                   value="<?= number_format($si ? $si['valor'] : $siPrev, 2, ',', '.') ?>">
          </div>
          <div class="fg w-half" style="justify-content:flex-end;">
            <label>Saldo final <?= abrevMes($mesPrev) ?>/<?= $anoPrev ?> (sugestão)</label>
            <div style="padding:5px 8px;background:#f8f8f8;border:1px solid #ccc;border-radius:3px;font-family:var(--mono);font-size:12px;">
              <?= fmt($siPrev) ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-default modal-close">Cancelar</button>
        <button type="submit" class="btn btn-primary">✓ Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- MODAL NOVA CONTA -->
<?php if ($action === 'nova_conta'): ?>
<div class="modal-bg open">
  <div class="modal" style="max-width:420px;">
    <div class="modal-head"><h3>+ Nova Conta Bancária</h3><button class="modal-close">✕</button></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="criar_conta">
      <div class="modal-body">
        <div class="form-row">
          <div class="fg w-full">
            <label>Nome da Conta *</label>
            <input type="text" name="nome" placeholder="Ex: Itaú Conta Corrente, Caixa Física" autofocus required>
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-half">
            <label>Tipo</label>
            <select name="tipo_conta">
              <option value="corrente">Conta Corrente</option>
              <option value="poupanca">Poupança</option>
              <option value="digital">Conta Digital</option>
              <option value="dinheiro">Dinheiro (Caixa Físico)</option>
              <option value="investimento">Investimento</option>
            </select>
          </div>
          <div class="fg w-half">
            <label>Saldo Inicial (R$)</label>
            <input type="text" name="saldo_inicial" class="money-input" value="0,00">
          </div>
        </div>
        <p class="text-muted text-sm">O saldo inicial será registrado no mês atual (<?= nomeMes($mes) ?>/<?= $ano ?>).</p>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-default modal-close">Cancelar</button>
        <button type="submit" class="btn btn-primary">✓ Criar Conta</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- MODAL EDITAR CONTA -->
<?php if ($action === 'editar' && $editConta): ?>
<div class="modal-bg open">
  <div class="modal" style="max-width:420px;">
    <div class="modal-head"><h3>✎ Editar Conta</h3><button class="modal-close">✕</button></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="editar_conta">
      <div class="modal-body">
        <div class="fg mb-8">
          <label>Nome</label>
          <input type="text" name="nome" value="<?= h($editConta['nome']) ?>" required>
        </div>
        <div class="fg">
          <label>Tipo</label>
          <select name="tipo_conta">
            <?php foreach (['corrente'=>'Conta Corrente','poupanca'=>'Poupança','digital'=>'Conta Digital','dinheiro'=>'Dinheiro','investimento'=>'Investimento'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= $editConta['tipo_conta']===$v?'selected':''?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-foot">
        <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&action=desativar&id=<?= $editConta['id'] ?>"
           class="btn btn-danger btn-sm" onclick="return confirm('Desativar esta conta?')">Desativar</a>
        <button type="button" class="btn btn-default modal-close">Cancelar</button>
        <button type="submit" class="btn btn-primary">✓ Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/src/layout_footer.php'; ?>
