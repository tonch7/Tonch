<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();
requireSistemaLiberado();

$_aberto = mesAbertoAtual();
$ano = (int)($_GET['ano'] ?? $_aberto['ano']);
$mes = (int)($_GET['mes'] ?? $_aberto['mes']);
if ($mes < 1 || $mes > 12) $mes = $_aberto['mes'];
// Redireciona se mês não foi aberto ainda (exceto se for o próximo aguardando abertura)
ensureMesAcessivel($mes, $ano, 'lancamentos');
$fechado = mesFechado($mes, $ano);

$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) { $id = (int)$_POST['id']; }

// ---- Verificação de caixa disponível ----
$caixaDisponivel = caixaAberto($mes, $ano);
$mesAberto = mesAbertoAtual();

// ---- POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$fechado) {
    requireCsrfOrAbort(url('public/lancamentos.php') . "?mes=$mes&ano=$ano");
    // Bloqueia se mês não foi iniciado ainda
    if (!$caixaDisponivel) {
        flash('error', '🔒 CAIXA FECHADO / AINDA NÃO INICIADO — Por gentileza efetue novamente com apontamento no mês ' . nomeMes($mesAberto['mes']) . '/' . $mesAberto['ano'] . ' que está em ABERTO.');
        header('Location: ' . url('public/lancamentos.php') . "?mes={$mesAberto['mes']}&ano={$mesAberto['ano']}"); exit;
    }
    $a = $_POST['action'] ?? '';

    if ($a === 'salvar') {
        try {
            $tipoLanc    = $_POST['tipo']    ?? 'entrada';
            $dataLanc    = $_POST['data']    ?? date('Y-m-d');
            $valorLanc   = parseMoney($_POST['valor'] ?? '0');
            $descLanc    = trim($_POST['descricao'] ?? '');
            $catId       = !empty($_POST['categoria_id'])    ? (int)$_POST['categoria_id']    : null;
            $cliId       = !empty($_POST['cliente_id'])      ? (int)$_POST['cliente_id']      : null;
            $contaId     = (int)($_POST['conta_id'] ?? 0);
            $metodoId    = !empty($_POST['metodo_id'])        ? (int)$_POST['metodo_id']        : null;

            $lancId = registrarLancamento([
                'tipo'            => $tipoLanc,
                'data'            => $dataLanc,
                'valor'           => $valorLanc,
                'descricao'       => $descLanc,
                'categoria_id'    => $catId,
                'cliente_id'      => $cliId,
                'conta_id'        => $contaId,
                'conta_destino_id'=> !empty($_POST['conta_destino_id']) ? (int)$_POST['conta_destino_id'] : null,
                'metodo_id'       => $metodoId,
                'taxa_valor'      => (float)($_POST['taxa_valor']  ?? 0),
                'taxa_tipo'       => $_POST['taxa_tipo']   ?? 'fixo',
                'tem_nf'          => isset($_POST['tem_nf']) ? 1 : 0,
                'num_nf'          => trim($_POST['num_nf']   ?? ''),
                'observacoes'     => trim($_POST['observacoes'] ?? ''),
            ]);

            // Gera registro em contas_pagar_receber para entrada/saida
            if (in_array($tipoLanc, ['entrada', 'saida'], true)) {
                $tipoCPR = $tipoLanc === 'saida' ? 'pagar' : 'receber';
                $jaPago  = !empty($_POST['ja_pago']) ? 1 : 0;
                $hoje    = date('Y-m-d');

                if ($jaPago) {
                    db()->insert(
                        'INSERT INTO contas_pagar_receber
                            (tipo, data_vencimento, valor, descricao, cliente_id, categoria_id, conta_id, metodo_id,
                             pago_recebido, data_baixa, valor_efetivo, lancamento_id)
                         VALUES (?,?,?,?,?,?,?,?,1,?,?,?)',
                        [$tipoCPR, $dataLanc, $valorLanc, $descLanc, $cliId, $catId, $contaId, $metodoId,
                         $hoje, $valorLanc, $lancId]
                    );
                } else {
                    db()->insert(
                        'INSERT INTO contas_pagar_receber
                            (tipo, data_vencimento, valor, descricao, cliente_id, categoria_id, conta_id, metodo_id,
                             pago_recebido, lancamento_id)
                         VALUES (?,?,?,?,?,?,?,?,0,?)',
                        [$tipoCPR, $dataLanc, $valorLanc, $descLanc, $cliId, $catId, $contaId, $metodoId, $lancId]
                    );
                }
            }

            auditLog('LANCAMENTO_NOVO', 'lancamentos', 'ID #'.$lancId.' | '.$tipoLanc.' | R$ '.$valorLanc);
            $msgExtra = in_array($tipoLanc, ['entrada','saida']) ? ' e conta a ' . ($tipoLanc === 'saida' ? 'pagar' : 'receber') . ' gerada!' : ' com sucesso!';
            flash('success', 'Lançamento registrado' . $msgExtra);
        } catch (Exception $e) {
            auditLog('LANCAMENTO_ERRO', 'lancamentos', $e->getMessage());
            flash('error', 'Erro: ' . $e->getMessage());
        }
        header('Location: ' . url('public/lancamentos.php') . "?mes=$mes&ano=$ano"); exit;
    }

    if ($a === 'atualizar' && $id > 0) {
        // Verifica imutabilidade: busca o mes/ano ATUAL do lançamento no DB
        $lExist = db()->one('SELECT mes, ano FROM lancamentos WHERE id=?', [$id]);
        if ($lExist && !caixaAberto((int)$lExist['mes'], (int)$lExist['ano'])) {
            flash('error', '🔒 CAIXA FECHADO — ' . nomeMes($lExist['mes']) . '/' . $lExist['ano'] . ' é imutável. Edição bloqueada.');
            auditLog('EDIT_BLOQUEADO', 'lancamentos', 'ID #'.$id.' mês '.$lExist['mes'].'/'.$lExist['ano'].' fechado');
            header('Location: ' . url('public/lancamentos.php') . "?mes=$mes&ano=$ano"); exit;
        }
        $mes2 = (int)date('m', strtotime($_POST['data']));
        $ano2 = (int)date('Y', strtotime($_POST['data']));
        $vl   = parseMoney($_POST['valor'] ?? '0');
        $tx   = (float)($_POST['taxa_valor'] ?? 0);
        db()->exec(
            'UPDATE lancamentos SET tipo=?,data=?,valor=?,valor_liquido=?,taxa_valor=?,taxa_tipo=?,
             descricao=?,categoria_id=?,cliente_id=?,conta_id=?,conta_destino_id=?,metodo_id=?,
             tem_nf=?,num_nf=?,mes=?,ano=?,observacoes=? WHERE id=?',
            [
                $_POST['tipo']??'entrada', $_POST['data']??date('Y-m-d'),
                $vl, $vl-$tx, $tx, $_POST['taxa_tipo']??'fixo',
                trim($_POST['descricao']??''),
                !empty($_POST['categoria_id'])   ? (int)$_POST['categoria_id']   : null,
                !empty($_POST['cliente_id'])     ? (int)$_POST['cliente_id']     : null,
                (int)($_POST['conta_id']??0),
                !empty($_POST['conta_destino_id']) ? (int)$_POST['conta_destino_id'] : null,
                !empty($_POST['metodo_id'])       ? (int)$_POST['metodo_id']      : null,
                isset($_POST['tem_nf']) ? 1 : 0, trim($_POST['num_nf']??''),
                $mes2, $ano2, trim($_POST['observacoes']??''), $id
            ]
        );
        auditLog('LANCAMENTO_EDIT', 'lancamentos', 'ID #'.$id.' atualizado');
        flash('success', 'Lançamento atualizado!');
        header('Location: ' . url('public/lancamentos.php') . "?mes=$mes2&ano=$ano2"); exit;
    }
    if ($a === 'excluir' && $id > 0) {
        $lExist = db()->one('SELECT id, mes, ano FROM lancamentos WHERE id=?', [$id]);
        if (!$lExist) {
            flash('error', 'Lançamento não encontrado.');
        } elseif (!caixaAberto((int)$lExist['mes'], (int)$lExist['ano'])) {
            flash('error', '🔒 CAIXA FECHADO — ' . nomeMes($lExist['mes']) . '/' . $lExist['ano'] . ' é imutável. Exclusão bloqueada.');
            auditLog('DELETE_BLOQUEADO', 'lancamentos', 'ID #' . $id . ' mês ' . $lExist['mes'] . '/' . $lExist['ano'] . ' fechado');
        } else {
            db()->exec('DELETE FROM contas_pagar_receber WHERE lancamento_id=?', [$id]);
            db()->exec('DELETE FROM lancamentos WHERE id=? OR taxa_lancamento_id=?', [$id, $id]);
            auditLog('LANCAMENTO_DELETE', 'lancamentos', 'ID #' . $id . ' excluído');
            flash('success', 'Lançamento excluído.');
        }
        header('Location: ' . url('public/lancamentos.php') . "?mes=$mes&ano=$ano"); exit;
    }
}

// Excluir — verifica imutabilidade pelo mes/ano DO PRÓPRIO LANÇAMENTO

// Dados
$lancamentos = db()->all(
    'SELECT l.*, c.nome cat, ct.nome conta_nome, cd.nome conta_dest, cl.nome cli_nome, m.nome met_nome
     FROM lancamentos l
     LEFT JOIN categorias c  ON l.categoria_id=c.id
     LEFT JOIN contas ct     ON l.conta_id=ct.id
     LEFT JOIN contas cd     ON l.conta_destino_id=cd.id
     LEFT JOIN clientes cl   ON l.cliente_id=cl.id
     LEFT JOIN metodos m     ON l.metodo_id=m.id
     WHERE l.mes=? AND l.ano=? AND l.taxa_lancamento_id IS NULL
     ORDER BY l.data DESC, l.criado_em DESC',
    [$mes,$ano]
);

$contas    = db()->all('SELECT * FROM contas WHERE ativa=1 ORDER BY nome');
$clientes  = db()->all('SELECT * FROM clientes ORDER BY nome');
$catEnt    = db()->all('SELECT * FROM categorias WHERE tipo="entrada" ORDER BY nome');
$catSai    = db()->all('SELECT * FROM categorias WHERE tipo="saida" ORDER BY nome');
$metodos   = db()->all('SELECT * FROM metodos ORDER BY nome');

$editLanc = ($action === 'editar' && $id > 0)
    ? db()->one('SELECT * FROM lancamentos WHERE id=?', [$id])
    : null;

// Totais
$totals = ['entrada'=>0,'saida'=>0,'transferencia'=>0];
foreach ($lancamentos as $l) {
    if ($l['tipo']==='entrada') $totals['entrada'] += (float)($l['valor_liquido'] ?? $l['valor']);
    elseif ($l['tipo']==='saida') $totals['saida'] += (float)$l['valor'];
}

$pageTitle  = 'Lançamentos';
$activePage = 'lancamentos';
require_once ROOT_PATH . '/src/layout_header.php';
?>

<!-- RESUMO RÁPIDO -->
<div class="grid-auto mb-12">
  <?php foreach ($contas as $c): $s = saldoConta($c['id'],$mes,$ano); ?>
  <div class="scard">
    <div class="lbl"><?= h($c['nome']) ?></div>
    <div class="val <?= $s>=0?'val-pos':'val-neg' ?>"><?= fmt($s) ?></div>
  </div>
  <?php endforeach; ?>
  <div class="scard">
    <div class="lbl">&#8593; Entradas</div>
    <div class="val val-pos"><?= fmt($totals['entrada']) ?></div>
  </div>
  <div class="scard">
    <div class="lbl">&#8595; Saídas</div>
    <div class="val val-neg"><?= fmt($totals['saida']) ?></div>
  </div>
</div>

<!-- TABELA DE LANÇAMENTOS -->
<div class="win">
  <div class="win-title">
    <span>&#128200; Lançamentos — <?= nomeMes($mes) ?>/<?= $ano ?> (<?= count($lancamentos) ?>)</span>
    <?php if (!$fechado): ?>
    <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&action=novo" class="btn btn-primary btn-sm">+ Novo Lançamento</a>
    <?php endif; ?>
  </div>
  <div style="padding:0;">
    <div class="table-wrap">
      <table class="table-mobile">
        <thead><tr>
          <th>Data</th><th>Tipo</th><th>Descrição</th><th>Conta</th>
          <th>Método</th><th>Categoria</th><th>NF</th>
          <th class="text-right">Valor Bruto</th>
          <th class="text-right">Taxa</th>
          <th class="text-right">Valor Líquido</th>
          <?php if (!$fechado): ?><th>Ações</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (empty($lancamentos)): ?>
          <tr><td colspan="11" class="text-center text-muted" style="padding:20px;">Nenhum lançamento neste mês.</td></tr>
        <?php else: foreach ($lancamentos as $l):
          $isEnt = $l['tipo']==='entrada';
          $isTrf = $l['tipo']==='transferencia';
        ?>
          <tr>
            <td data-label="Data"><?= fmtDate($l['data']) ?></td>
            <td data-label="Tipo">
              <span class="badge <?= $isEnt?'b-ent':($isTrf?'b-trf':'b-sai') ?>">
                <?= ucfirst($l['tipo']) ?>
                <?= $l['tipo']==='saida' && ($l['cat']??'')==='Sangria' ? ' 💸' : '' ?>
              </span>
            </td>
            <td data-label="Descrição">
              <?= h($l['descricao']) ?>
              <?php if ($l['observacoes']): ?>
                <span class="text-muted text-sm" title="<?= h($l['observacoes']) ?>"> &#128172;</span>
              <?php endif; ?>
            </td>
            <td data-label="Conta">
              <?= h($l['conta_nome'] ?? '—') ?>
              <?php if ($isTrf && $l['conta_dest']): ?>
                <span class="text-muted">→ <?= h($l['conta_dest']) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Método"><?= h($l['met_nome'] ?? '—') ?></td>
            <td data-label="Categoria"><?= h($l['cat'] ?? '—') ?></td>
            <td data-label="NF"><?= $l['tem_nf'] ? '<span class="badge b-nf">NF '.h($l['num_nf']).'</span>' : '—' ?></td>
            <td data-label="Valor Bruto" class="money <?= $isEnt?'pos':'neg' ?>"><?= fmt($l['valor']) ?></td>
            <td data-label="Taxa" class="money neg"><?= $l['taxa_valor']>0 ? fmt($l['taxa_valor']) : '—' ?></td>
            <td data-label="Valor Líquido" class="money <?= $isEnt?'pos':'neg' ?>">
              <?= $isEnt ? fmt($l['valor_liquido'] ?? $l['valor']) : '—' ?>
            </td>
            <?php if (!$fechado): ?>
            <td>
              <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&action=editar&id=<?= $l['id'] ?>" class="btn btn-default btn-xs">✎</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Excluir este lançamento?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="excluir">
                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                <button type="submit" class="btn btn-danger btn-xs">✕</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($lancamentos)): ?>
        <tfoot><tr>
          <td colspan="7">Total</td>
          <td class="text-right text-mono"></td>
          <td></td>
          <td class="text-right text-mono"><?= fmt($totals['entrada'] - $totals['saida']) ?></td>
          <?php if (!$fechado): ?><td></td><?php endif; ?>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- FECHAR MÊS -->
<?php if (!$fechado && $caixaDisponivel): ?>
<div class="flex flex-end mt-12">
  <button type="button" class="btn btn-danger"
          onclick="document.getElementById('modalFechar').classList.add('open')">
    🔒 Fechar <?= nomeMes($mes) ?>/<?= $ano ?>
  </button>
</div>

<!-- MODAL FECHAR MÊS COM IMPOSTO -->
<div class="modal-bg" id="modalFechar">
  <div class="modal" style="max-width:500px;">
    <div class="modal-head">
      <h3>🔒 Fechar <?= nomeMes($mes) ?>/<?= $ano ?></h3>
      <button class="modal-close" onclick="document.getElementById('modalFechar').classList.remove('open')">✕</button>
    </div>
    <form method="post" action="fechar_mes.php">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="fechar">
      <input type="hidden" name="mes"    value="<?= $mes ?>">
      <input type="hidden" name="ano"    value="<?= $ano ?>">
      <div class="modal-body">

        <!-- Resumo do mês -->
        <div class="alert alert-info mb-12" style="font-size:12px;">
          📊 <strong><?= nomeMes($mes) ?>/<?= $ano ?></strong> —
          Entradas: <strong style="color:var(--green)"><?= fmt($totals['entrada']) ?></strong> &nbsp;|&nbsp;
          Saídas: <strong style="color:var(--red)"><?= fmt($totals['saida']) ?></strong> &nbsp;|&nbsp;
          Resultado: <strong><?= fmt($totals['entrada'] - $totals['saida']) ?></strong>
        </div>

        <!-- Imposto (opcional) -->
        <div class="win mb-0" style="background:#fffbe6;border-color:#e6b800;">
          <div class="win-title" style="background:linear-gradient(180deg,#fff3b0,#ffe066);font-size:12px;">
            🧾 Imposto do mês (opcional) — DASN, ISS, Simples, IRPJ, etc.
          </div>
          <div class="win-body">
            <p class="text-sm text-muted mb-8">Informe o imposto se houver. Será lançado como saída (Categoria: Imposto) e contabilizado no DRE.</p>
            <div class="form-row">
              <div class="fg w-half">
                <label>Descrição (ex: Simples Nacional, ISS)</label>
                <input type="text" name="imp_descricao" placeholder="Ex: Simples Nacional, DASN, ISS…" maxlength="100">
              </div>
              <div class="fg w-qtr">
                <label>Tipo</label>
                <select name="imp_tipo" id="impTipo" onchange="calcImposto()">
                  <option value="fixo">Valor fixo (R$)</option>
                  <option value="percentual">% sobre receita bruta</option>
                </select>
              </div>
              <div class="fg w-qtr">
                <label>Valor / %</label>
                <input type="text" name="imp_valor" id="impValor" class="money-input" placeholder="0,00" oninput="calcImposto()">
              </div>
            </div>
            <div id="impCalcBox" style="display:none;padding:6px 0;font-size:12px;color:#333;">
              💡 Receita bruta do mês: <strong><?= fmt($totals['entrada']) ?></strong> →
              Imposto calculado: <strong id="impCalcVal">R$ 0,00</strong>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-default"
                onclick="document.getElementById('modalFechar').classList.remove('open')">Cancelar</button>
        <button type="submit" class="btn btn-danger"
                onclick="return confirm('Fechar <?= nomeMes($mes) ?>/<?= $ano ?>?\n\nApós fechado, será necessário clicar em ABRIR CAIXA para o próximo mês.')">
          🔒 Confirmar Fechamento
        </button>
      </div>
    </form>
  </div>
</div>
<script>
var _recBruta = <?= $totals['entrada'] ?>;
function calcImposto() {
  var tipo = document.getElementById('impTipo').value;
  var val  = parseFloat((document.getElementById('impValor').value || '0').replace(/\./g,'').replace(',','.')) || 0;
  var box  = document.getElementById('impCalcBox');
  var lbl  = document.getElementById('impCalcVal');
  if (tipo === 'percentual') {
    box.style.display = 'block';
    var calc = _recBruta * (val / 100);
    lbl.textContent = 'R$ ' + calc.toLocaleString('pt-BR',{minimumFractionDigits:2});
  } else {
    box.style.display = 'none';
  }
}
</script>
<?php endif; ?>

<!-- MODAL NOVO / EDITAR LANÇAMENTO -->
<?php if (($action === 'novo' || $action === 'editar') && !$fechado): ?>
<div class="modal-bg open" id="modalLanc">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $editLanc ? '✎ Editar Lançamento' : '+ Novo Lançamento' ?></h3>
      <button class="modal-close">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="<?= $editLanc ? 'atualizar' : 'salvar' ?>">
      <div class="modal-body">

        <!-- Tipo / Data / Valor -->
        <div class="form-row">
          <div class="fg w-3rd">
            <label>Tipo *</label>
            <select name="tipo" id="tipo">
              <option value="entrada"       <?= ($editLanc['tipo']??'entrada')==='entrada'       ?'selected':''?>>Entrada</option>
              <option value="saida"         <?= ($editLanc['tipo']??'')==='saida'         ?'selected':''?>>Saída</option>
              <option value="transferencia" <?= ($editLanc['tipo']??'')==='transferencia' ?'selected':''?>>Transferência</option>
            </select>
          </div>
          <div class="fg w-3rd">
            <label>Data *</label>
            <input type="date" name="data" id="data"
                   value="<?= $editLanc['data'] ?? date('Y-m-d') ?>" required>
          </div>
          <div class="fg w-3rd">
            <label>Valor Bruto (R$) *</label>
            <input type="text" name="valor" id="valor" class="money-input"
                   value="<?= $editLanc ? number_format($editLanc['valor'],2,',','.') : '' ?>"
                   placeholder="0,00" required>
          </div>
        </div>

        <!-- Conta / Conta Destino (transferência) / Método -->
        <div class="form-row">
          <div class="fg w-half">
            <label>Conta Bancária *</label>
            <select name="conta_id" required>
              <option value="">— selecione —</option>
              <?php foreach ($contas as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($editLanc['conta_id']??0)==$c['id']?'selected':''?>>
                  <?= h($c['nome']) ?> (<?= fmt(saldoConta($c['id'],$mes,$ano)) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg w-half" id="metodo-box">
            <label>Método de Pagto/Recebto</label>
            <select name="metodo_id" id="metodo_id">
              <option value="">— selecione —</option>
              <?php foreach ($metodos as $m): ?>
                <option value="<?= $m['id'] ?>"
                        data-tem-taxa="<?= $m['tem_taxa'] ?>"
                        data-taxa-tipo="<?= $m['taxa_tipo'] ?>"
                        data-taxa-valor="<?= $m['taxa_valor'] ?>"
                        <?= ($editLanc['metodo_id']??0)==$m['id']?'selected':''?>>
                  <?= h($m['nome']) ?><?= $m['tem_taxa'] ? ' (taxa)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Conta destino (só transferência) -->
        <div class="form-row" id="trf-box" style="display:none;">
          <div class="fg w-full">
            <label>Conta Destino *</label>
            <select name="conta_destino_id">
              <option value="">— selecione —</option>
              <?php foreach ($contas as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($editLanc['conta_destino_id']??0)==$c['id']?'selected':''?>>
                  <?= h($c['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- TAXA (exibida conforme método) -->
        <div class="form-row" id="taxa-box" style="display:none;">
          <div class="fg w-3rd">
            <label>Tipo de Taxa</label>
            <select name="taxa_tipo" id="taxa_tipo">
              <option value="fixo"       <?= ($editLanc['taxa_tipo']??'')==='fixo'?'selected':''?>>Valor fixo (R$)</option>
              <option value="percentual" <?= ($editLanc['taxa_tipo']??'')==='percentual'?'selected':''?>>Percentual (%)</option>
            </select>
          </div>
          <div class="fg w-3rd">
            <label>Valor da Taxa</label>
            <input type="text" id="taxa_valor_input" class="money-input"
                   value="<?= $editLanc ? number_format($editLanc['taxa_valor'],2,',','.') : '0,00' ?>"
                   placeholder="0,00">
            <input type="hidden" name="taxa_valor" id="taxa_valor"
                   value="<?= $editLanc['taxa_valor'] ?? 0 ?>">
          </div>
          <div class="fg w-3rd" style="justify-content:flex-end;">
            <label>Taxa calculada</label>
            <div style="padding:5px 8px;background:#f0f0f0;border:1px solid #ccc;border-radius:4px;font-family:var(--mono);font-size:12px;">
              <span id="taxa_calculada">R$ 0,00</span>
            </div>
            <div style="padding:5px 8px;background:#e8ffe8;border:1px solid #aacc88;border-radius:4px;font-family:var(--mono);font-size:12px;margin-top:4px;">
              Líquido: <strong id="valor_liquido_show">R$ 0,00</strong>
            </div>
          </div>
        </div>

        <!-- Descrição / Categoria / Cliente -->
        <div class="form-row">
          <div class="fg w-full">
            <label>Descrição</label>
            <input type="text" name="descricao"
                   value="<?= h($editLanc['descricao'] ?? '') ?>"
                   placeholder="Descreva o lançamento">
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-half">
            <label>Categoria</label>
            <select name="categoria_id">
              <option value="">— selecione —</option>
              <optgroup label="Entradas">
                <?php foreach ($catEnt as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= ($editLanc['categoria_id']??0)==$c['id']?'selected':''?>>
                    <?= h($c['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="Saídas">
                <?php foreach ($catSai as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= ($editLanc['categoria_id']??0)==$c['id']?'selected':''?>>
                    <?= h($c['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </div>
          <div class="fg w-half">
            <label>Cliente (opcional)</label>
            <select name="cliente_id">
              <option value="">— nenhum —</option>
              <?php foreach ($clientes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($editLanc['cliente_id']??0)==$c['id']?'selected':''?>>
                  <?= h($c['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Nota Fiscal (só entrada) -->
        <div class="form-row" id="nf-box">
          <div class="fg" style="flex-direction:row;align-items:center;gap:8px;padding-top:18px;">
            <input type="checkbox" name="tem_nf" id="tem_nf" value="1"
                   <?= ($editLanc['tem_nf']??0) ? 'checked' : '' ?>>
            <label for="tem_nf" style="font-weight:normal;cursor:pointer;">Com Nota Fiscal</label>
          </div>
          <div class="fg">
            <label>Número da NF</label>
            <input type="text" name="num_nf"
                   value="<?= h($editLanc['num_nf'] ?? '') ?>"
                   placeholder="Ex: 000001">
          </div>
        </div>

        <!-- Já foi pago/recebido? (apenas entrada e saída, somente criação) -->
        <?php if (!$editLanc): ?>
        <div class="form-row" id="ja-pago-box">
          <div class="fg" style="flex-direction:row;align-items:center;gap:8px;padding-top:14px;
                                  background:#f0fff4;border:1px solid #a8d5b5;border-radius:6px;
                                  padding:10px 14px;margin-top:4px;">
            <input type="checkbox" name="ja_pago" id="ja_pago" value="1">
            <label for="ja_pago" style="font-weight:600;cursor:pointer;color:#1a6e34;">
              ✅ Já foi pago / recebido?
            </label>
            <span class="text-muted text-sm" style="margin-left:6px;">
              (marcado = baixa automática em contas P/R; desmarcado = fica como pendente)
            </span>
          </div>
        </div>
        <?php endif; ?>

        <!-- Observações -->
        <div class="form-row">
          <div class="fg w-full">
            <label>Observações</label>
            <textarea name="observacoes" rows="2"><?= h($editLanc['observacoes'] ?? '') ?></textarea>
          </div>
        </div>

      </div><!-- modal-body -->
      <div class="modal-foot">
        <button type="button" class="btn btn-default modal-close">Cancelar</button>
        <button type="submit" class="btn btn-primary">✓ Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/src/layout_footer.php'; ?>
<script>
(function () {
  var tipoSel  = document.getElementById('tipo');
  var jaPagoBox = document.getElementById('ja-pago-box');
  if (!tipoSel || !jaPagoBox) return;

  function toggleJaPago() {
    var v = tipoSel.value;
    jaPagoBox.style.display = (v === 'entrada' || v === 'saida') ? '' : 'none';
    if (v === 'transferencia') {
      var cb = document.getElementById('ja_pago');
      if (cb) cb.checked = false;
    }
  }

  tipoSel.addEventListener('change', toggleJaPago);
  toggleJaPago(); // estado inicial
}());
</script>