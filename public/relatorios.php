<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();
requireSistemaLiberado();

$_aberto = mesAbertoAtual();
$ano = (int)($_GET['ano'] ?? $_aberto['ano']);
$mes = (int)($_GET['mes'] ?? $_aberto['mes']);
ensureMesAcessivel($mes, $ano, 'relatorios');
$tab = $_GET['tab'] ?? 'dre';

// ── Handle POST: salvar imposto diretamente aqui ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'salvar_imposto') {
    requireCsrfOrAbort(url('public/relatorios.php') . "?mes=$mes&ano=$ano&tab=$tab");
    // IMUTABILIDADE: só permite registrar imposto se caixa estiver aberto
    // Exceção: permite salvar imposto no momento do fechamento (mês ainda aberto)
    // Após fechar, imposto é imutável
    if (!caixaAberto($mes, $ano)) {
        flash('error', '🔒 ' . nomeMes($mes) . '/' . $ano . ' está FECHADO — imutável. O imposto deste mês não pode ser alterado.');
        auditLog('IMPOSTO_BLOQUEADO', 'relatorios', 'Tentativa bloqueada: ' . nomeMes($mes).'/'.$ano.' fechado');
        header('Location: ' . url('public/relatorios.php') . "?mes=$mes&ano=$ano&tab=dre"); exit;
    }
    $iDesc = trim($_POST['imp_descricao'] ?? '');
    $iTipo = $_POST['imp_tipo'] ?? 'fixo';
    $iRaw  = preg_replace('/[^\d,\.]/', '', trim($_POST['imp_valor'] ?? '0'));
    if (strpos($iRaw, ',') !== false) {
        $iRaw = str_replace('.', '', $iRaw);
        $iRaw = str_replace(',', '.', $iRaw);
    }
    $iValor = (float)$iRaw;
    if ($iTipo === 'percentual' && $iValor > 0) {
        $rb = (float)(db()->one('SELECT SUM(COALESCE(valor_liquido,valor)) t FROM lancamentos WHERE tipo="entrada" AND mes=? AND ano=?', [$mes,$ano])['t']??0);
        $iValor = round($rb * ($iValor / 100), 2);
    }
    // Limpa registros anteriores deste mês
    $oldImps = db()->all('SELECT lancamento_id FROM impostos_mes WHERE mes=? AND ano=? AND lancamento_id IS NOT NULL', [$mes,$ano]);
    foreach ($oldImps as $oi) db()->exec('DELETE FROM lancamentos WHERE id=?', [$oi['lancamento_id']]);
    db()->exec('DELETE FROM impostos_mes WHERE mes=? AND ano=?', [$mes,$ano]);

    if ($iDesc !== '' && $iValor > 0) {
        $lid = null;
        $cp  = db()->one('SELECT id FROM contas WHERE ativa=1 ORDER BY id LIMIT 1');
        if ($cp) {
            $ci   = db()->one("SELECT id FROM categorias WHERE nome='Imposto' AND tipo='saida'");
            $ciId = $ci ? (int)$ci['id'] : db()->insert("INSERT INTO categorias (nome,tipo) VALUES ('Imposto','saida')");
            $lid  = db()->insert(
                'INSERT INTO lancamentos (tipo,data,valor,valor_liquido,descricao,categoria_id,conta_id,mes,ano)
                 VALUES ("saida",?,?,?,?,?,?,?,?)',
                [sprintf('%04d-%02d-01',$ano,$mes), $iValor, $iValor, $iDesc, $ciId, (int)$cp['id'], $mes, $ano]
            );
        }
        db()->insert(
            'INSERT INTO impostos_mes (mes,ano,descricao,tipo,valor,lancamento_id) VALUES (?,?,?,?,?,?)',
            [$mes, $ano, $iDesc, $iTipo, $iValor, $lid]
        );
        auditLog('IMPOSTO_SALVO','relatorios', nomeMes($mes).'/'.$ano.' | '.$iDesc.' R$'.number_format($iValor,2,',','.'));
        flash('success','✓ Imposto de '.nomeMes($mes).'/'. $ano.' registrado: R$'.number_format($iValor,2,',','.'));
    } else {
        flash('success','Imposto removido de '.nomeMes($mes).'/'.$ano.'.');
    }
    header('Location: ' . url('public/relatorios.php') . "?mes=$mes&ano=$ano&tab=dre"); exit;
}

// ── IMPOSTOS — fonte da verdade: impostos_mes ─────────────────────────────────
$impMes = db()->all(
    'SELECT id, descricao, tipo, valor FROM impostos_mes WHERE mes=? AND ano=? ORDER BY id',
    [$mes, $ano]);
$impostos = (float)array_sum(array_column($impMes, 'valor'));

// Fallback + auto-sync: se impostos_mes vazio mas há lancamentos categoria=Imposto, sincroniza
if ($impostos == 0) {
    $impLanc = db()->all(
        'SELECT l.id, l.descricao, l.valor FROM lancamentos l
         INNER JOIN categorias c ON l.categoria_id=c.id
         WHERE l.tipo="saida" AND c.nome="Imposto" AND l.mes=? AND l.ano=?', [$mes,$ano]);
    foreach ($impLanc as $il) {
        db()->exec('INSERT OR IGNORE INTO impostos_mes (mes,ano,descricao,tipo,valor,lancamento_id) VALUES (?,?,?,?,?,?)',
            [$mes,$ano,$il['descricao'],'fixo',(float)$il['valor'],$il['id']]);
    }
    if (!empty($impLanc)) {
        $impMes   = db()->all('SELECT id, descricao, tipo, valor FROM impostos_mes WHERE mes=? AND ano=? ORDER BY id', [$mes,$ano]);
        $impostos = (float)array_sum(array_column($impMes, 'valor'));
    }
}

// ── DRE ──────────────────────────────────────────────────────────────────────
$recBruta  = (float)(db()->one('SELECT SUM(COALESCE(valor_liquido,valor)) t FROM lancamentos WHERE tipo="entrada" AND mes=? AND ano=?',[$mes,$ano])['t']??0);
$recLiq    = $recBruta - $impostos;
$custosVar = (float)(db()->one('SELECT SUM(l.valor) t FROM lancamentos l LEFT JOIN categorias c ON l.categoria_id=c.id WHERE l.tipo="saida" AND c.nome NOT IN ("Imposto","Pró-labore","Sangria","Despesa pessoal") AND l.mes=? AND l.ano=?',[$mes,$ano])['t']??0);
$resOp     = $recLiq - $custosVar;
$despFixas = (float)(db()->one('SELECT SUM(l.valor) t FROM lancamentos l LEFT JOIN categorias c ON l.categoria_id=c.id WHERE (l.tipo IN ("saida","sangria") AND c.nome IN ("Pró-labore","Sangria","Despesa pessoal")) AND l.mes=? AND l.ano=?',[$mes,$ano])['t']??0);
$lucroLiq  = $resOp - $despFixas;

// ── Rankings ──────────────────────────────────────────────────────────────────
$topSaidas   = db()->all('SELECT c.nome cat, SUM(l.valor) tot FROM lancamentos l LEFT JOIN categorias c ON l.categoria_id=c.id WHERE l.tipo="saida" AND l.mes=? AND l.ano=? GROUP BY l.categoria_id ORDER BY tot DESC LIMIT 5',[$mes,$ano]);
$topEntradas = db()->all('SELECT c.nome cat, SUM(COALESCE(l.valor_liquido,l.valor)) tot FROM lancamentos l LEFT JOIN categorias c ON l.categoria_id=c.id WHERE l.tipo="entrada" AND l.mes=? AND l.ano=? GROUP BY l.categoria_id ORDER BY tot DESC LIMIT 5',[$mes,$ano]);

// ── Fluxo anual ───────────────────────────────────────────────────────────────
$fluxoAnual = [];
for ($m=1;$m<=12;$m++) {
    $e = (float)(db()->one('SELECT SUM(COALESCE(valor_liquido,valor)) t FROM lancamentos WHERE tipo="entrada" AND mes=? AND ano=?',[$m,$ano])['t']??0);
    $s = (float)(db()->one('SELECT SUM(valor) t FROM lancamentos WHERE tipo="saida" AND mes=? AND ano=?',[$m,$ano])['t']??0);
    $imp = (float)(db()->one('SELECT SUM(valor) t FROM impostos_mes WHERE mes=? AND ano=?',[$m,$ano])['t']??0);
    $fluxoAnual[$m] = ['nome'=>nomeMes($m),'ent'=>$e,'sai'=>$s,'imp'=>$imp,'saldo'=>saldoConsolidado($m,$ano)];
}

$pageTitle='Relatórios'; $activePage='relatorios';
require_once ROOT_PATH.'/src/layout_header.php';
?>

<div style="background:#e8e8e8;padding:6px 8px 0;display:flex;gap:2px;border-bottom:2px solid #aaa;margin-bottom:0;">
  <?php foreach(['dre'=>'📊 DRE','ranking'=>'🏆 Ranking','fluxo'=>'📈 Fluxo Anual','extrato'=>'📋 Extrato'] as $k=>$l): ?>
  <a href="?mes=<?=$mes?>&ano=<?=$ano?>&tab=<?=$k?>" class="mtab <?=$tab===$k?'active':''?>"><?=$l?></a>
  <?php endforeach; ?>
</div>

<!-- ═══ DRE ═══════════════════════════════════════════════════════════════ -->
<?php if($tab==='dre'): ?>
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:0;align-items:flex-start;">

  <!-- DRE TABLE -->
  <div class="win" style="border-radius:0 0 4px 4px;min-width:300px;flex:1;max-width:620px;margin-top:0;">
    <div class="win-title">📊 DRE — <?=nomeMes($mes)?>/<?=$ano?></div>
    <div style="padding:0;">
      <table class="dre-table">
        <tr class="dre-sec"><td colspan="2">RECEITAS</td></tr>
        <tr><td class="dre-ind">Receita Bruta (entradas)</td><td class="dre-r dre-pos"><?=fmt($recBruta)?></td></tr>

        <tr class="dre-sec">
          <td colspan="2">
            DEDUÇÕES — IMPOSTOS
            <span style="font-size:10px;color:#999;font-weight:400;margin-left:6px;">
              <?= empty($impMes) ? '⚠ Nenhum declarado' : count($impMes).' item(ns)' ?>
            </span>
          </td>
        </tr>
        <?php if (!empty($impMes)): foreach ($impMes as $imp): ?>
          <tr>
            <td class="dre-ind" style="padding-left:24px;">
              ↳ <?= h($imp['descricao']) ?>
              <?php if ($imp['tipo']==='percentual'): ?>
                <small style="color:#aaa;"> (% receita bruta)</small>
              <?php endif; ?>
            </td>
            <td class="dre-r dre-neg">(<?= fmt((float)$imp['valor']) ?>)</td>
          </tr>
        <?php endforeach; ?>
          <?php if (count($impMes) > 1): ?>
          <tr style="background:#fff3f3;">
            <td class="dre-ind"><strong>Total Impostos</strong></td>
            <td class="dre-r dre-neg"><strong>(<?=fmt($impostos)?>)</strong></td>
          </tr>
          <?php endif; ?>
        <?php else: ?>
          <tr><td class="dre-ind" style="color:#bbb;font-style:italic;padding-left:24px;">Nenhum imposto declarado</td><td class="dre-r" style="color:#bbb;">R$ 0,00</td></tr>
        <?php endif; ?>

        <tr class="dre-tot"><td>= RECEITA LÍQUIDA</td><td class="dre-r <?=$recLiq>=0?'dre-pos':'dre-neg'?>"><?=fmt($recLiq)?></td></tr>
        <tr class="dre-sec"><td colspan="2">CUSTOS OPERACIONAIS</td></tr>
        <tr><td class="dre-ind">(-) Custos variáveis</td><td class="dre-r dre-neg">(<?=fmt($custosVar)?>)</td></tr>
        <tr class="dre-tot"><td>= RESULTADO OPERACIONAL</td><td class="dre-r <?=$resOp>=0?'dre-pos':'dre-neg'?>"><?=fmt($resOp)?></td></tr>
        <tr class="dre-sec"><td colspan="2">DESPESAS FIXAS</td></tr>
        <tr><td class="dre-ind">(-) Pró-labore, Sangrias, Pessoais</td><td class="dre-r dre-neg">(<?=fmt($despFixas)?>)</td></tr>
        <tr class="dre-lucro"><td>= LUCRO LÍQUIDO</td><td class="dre-r <?=$lucroLiq>=0?'dre-pos':'dre-neg'?>"><?=fmt($lucroLiq)?></td></tr>
      </table>
    </div>
  </div>

  <!-- PAINEL IMPOSTO -->
  <?php $mesFechadoAtual = mesFechado($mes, $ano); $caixaAbertoAtual = caixaAberto($mes, $ano); ?>
  <div class="win" style="border-radius:0 0 4px 4px;min-width:260px;max-width:320px;margin-top:0;border-color:<?= $mesFechadoAtual ? '#ccc' : '#f0a000' ?>;">
    <div class="win-title" style="background:<?= $mesFechadoAtual ? 'linear-gradient(180deg,#eee,#ddd)' : 'linear-gradient(180deg,#fff3b0,#ffd740)' ?>;color:#333;">
      🧾 Imposto de <?=nomeMes($mes)?>/<?=$ano?>
      <?php if ($mesFechadoAtual): ?><span style="font-size:10px;font-weight:400;color:#888;"> — IMUTÁVEL 🔒</span><?php endif; ?>
    </div>
    <div class="win-body">
      <?php if (!empty($impMes)): ?>
        <?php foreach ($impMes as $imp): ?>
        <div style="background:#fff8e1;border:1px solid #ffc107;border-radius:6px;padding:10px 12px;margin-bottom:8px;">
          <div style="font-size:13px;font-weight:700;color:#333;"><?= h($imp['descricao']) ?></div>
          <div style="font-size:18px;font-weight:700;color:#c0392b;margin-top:2px;"><?= fmt((float)$imp['valor']) ?></div>
          <div style="font-size:10px;color:#888;text-transform:uppercase;"><?= $imp['tipo']==='percentual'?'% Receita Bruta':'Valor Fixo' ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (!$mesFechadoAtual): ?><div style="font-size:11px;color:#888;margin-bottom:10px;">Clique em "Alterar" para corrigir.</div><?php endif; ?>
      <?php else: ?>
        <div style="font-size:12px;color:#888;text-align:center;padding:12px 0;">
          Nenhum imposto declarado para este mês.
        </div>
      <?php endif; ?>

      <?php if ($caixaAbertoAtual): ?>
      <!-- Formulário: apenas se caixa ABERTO -->
      <form method="post" id="formImposto">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="salvar_imposto">
        <div class="fg mb-8">
          <label style="font-size:11px;">Descrição (ex: Simples, ISS, IRPJ)</label>
          <input type="text" name="imp_descricao"
                 value="<?= h($impMes[0]['descricao'] ?? '') ?>"
                 placeholder="Ex: Simples Nacional"
                 style="font-size:12px;">
        </div>
        <div class="form-row" style="gap:8px;">
          <div class="fg" style="min-width:100px;">
            <label style="font-size:11px;">Tipo</label>
            <select name="imp_tipo" id="dreImpTipo" onchange="dreCalcImp()" style="font-size:12px;">
              <option value="fixo" <?= ($impMes[0]['tipo']??'fixo')==='fixo'?'selected':'' ?>>Valor fixo (R$)</option>
              <option value="percentual" <?= ($impMes[0]['tipo']??'')==='percentual'?'selected':'' ?>>% Receita</option>
            </select>
          </div>
          <div class="fg">
            <label style="font-size:11px;">Valor / %</label>
            <input type="text" name="imp_valor" id="dreImpValor" class="money-input"
                   value="<?= !empty($impMes) ? number_format((float)$impMes[0]['valor'],2,',','.') : '' ?>"
                   placeholder="0,00" oninput="dreCalcImp()" style="font-size:12px;">
          </div>
        </div>
        <div id="dreImpCalc" style="display:none;font-size:11px;color:#555;margin-bottom:8px;padding:6px;background:#f5f5f5;border-radius:4px;">
          Receita bruta: <?=fmt($recBruta)?> → Imposto: <strong id="dreImpCalcVal">R$ 0,00</strong>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <button type="submit" class="btn btn-primary btn-sm">✓ Salvar Imposto</button>
          <?php if (!empty($impMes)): ?>
          <button type="button" class="btn btn-danger btn-sm"
                  onclick="if(confirm('Remover imposto de <?=nomeMes($mes)?>/<?=$ano?>?')){document.getElementById('dreImpValor').value='';document.getElementById('formImposto').querySelector('[name=imp_descricao]').value='';document.getElementById('formImposto').submit();}">
            🗑 Remover
          </button>
          <?php endif; ?>
        </div>
      </form>
      <?php elseif ($mesFechadoAtual): ?>
      <div class="alert alert-warning" style="font-size:11px;margin-top:8px;">
        <span class="alert-icon">🔒</span>
        <span class="alert-body">Mês FECHADO — imutável. O imposto registrado não pode ser alterado.</span>
      </div>
      <?php endif; ?>

      </form>
    </div>
  </div>

</div>
<script>
var _dreRecBruta = <?= $recBruta ?>;
function dreCalcImp(){
  var tipo = document.getElementById('dreImpTipo').value;
  var el   = document.getElementById('dreImpValor');
  var raw  = (el.value||'0').replace(/\./g,'').replace(',','.');
  var val  = parseFloat(raw)||0;
  var box  = document.getElementById('dreImpCalc');
  var lbl  = document.getElementById('dreImpCalcVal');
  if(tipo==='percentual'){
    box.style.display='block';
    var calc = _dreRecBruta * (val/100);
    lbl.textContent = 'R$ '+calc.toLocaleString('pt-BR',{minimumFractionDigits:2});
  } else {
    box.style.display='none';
  }
}
</script>

<!-- ═══ RANKING ════════════════════════════════════════════════════════════ -->
<?php elseif($tab==='ranking'): ?>
<div class="grid-2" style="margin-top:0;">
  <div class="win"><div class="win-title">⬇ Top Saídas</div><div class="win-body">
    <?php if(empty($topSaidas)): ?><p class="text-muted text-center" style="padding:20px;">Sem dados</p>
    <?php else: $maxS=max(array_column($topSaidas,'tot')); $totS=array_sum(array_column($topSaidas,'tot'));
    foreach($topSaidas as $i=>$r): ?>
    <div style="margin-bottom:8px;"><div class="flex flex-btwn text-sm mb-4"><span><?=$i+1?>. <?=h($r['cat']??'—')?></span><span class="text-red text-mono"><?=fmt($r['tot'])?> (<?=$totS>0?round($r['tot']/$totS*100):0?>%)</span></div>
    <div class="prog-bar"><div class="prog-fill prog-red" style="width:<?=$maxS>0?round($r['tot']/$maxS*100):0?>%"></div></div></div>
    <?php endforeach; endif; ?></div></div>
  <div class="win"><div class="win-title">⬆ Top Entradas</div><div class="win-body">
    <?php if(empty($topEntradas)): ?><p class="text-muted text-center" style="padding:20px;">Sem dados</p>
    <?php else: $maxE=max(array_column($topEntradas,'tot')); $totE=array_sum(array_column($topEntradas,'tot'));
    foreach($topEntradas as $i=>$r): ?>
    <div style="margin-bottom:8px;"><div class="flex flex-btwn text-sm mb-4"><span><?=$i+1?>. <?=h($r['cat']??'—')?></span><span class="text-green text-mono"><?=fmt($r['tot'])?> (<?=$totE>0?round($r['tot']/$totE*100):0?>%)</span></div>
    <div class="prog-bar"><div class="prog-fill prog-grn" style="width:<?=$maxE>0?round($r['tot']/$maxE*100):0?>%"></div></div></div>
    <?php endforeach; endif; ?></div></div>
</div>

<!-- ═══ FLUXO ANUAL ════════════════════════════════════════════════════════ -->
<?php elseif($tab==='fluxo'): ?>
<div class="win" style="margin-top:0;">
  <div class="win-title">📈 Fluxo de Caixa — <?=$ano?></div>
  <div style="padding:0;"><div class="table-wrap"><table>
    <thead><tr>
      <th>Mês</th>
      <th class="text-right">Entradas</th>
      <th class="text-right">Impostos</th>
      <th class="text-right">Saídas</th>
      <th class="text-right">Saldo Final</th>
    </tr></thead>
    <tbody>
    <?php $totE2=0;$totS2=0;$totImp2=0;$ultimoSaldoIniciado=null;
    foreach($fluxoAnual as $m=>$f):
      $totE2+=$f['ent']; $totS2+=$f['sai']; $totImp2+=$f['imp'];
      $ni = !mesIniciado($m,$ano);
      if (!$ni) $ultimoSaldoIniciado = $f['saldo'];
    ?>
    <tr <?=$m==$mes?'style="background:#e8f0ff;font-weight:bold;"':''?> <?=$ni?'style="opacity:.4;"':''?>>
      <td>
        <?php if(!$ni): ?>
          <a href="?mes=<?=$m?>&ano=<?=$ano?>&tab=fluxo" style="color:var(--blue)"><?=$f['nome']?></a>
          <?=mesFechado($m,$ano)?' 🔒':''?>
        <?php else: ?>
          <span style="color:#bbb;"><?=$f['nome']?> <small>—</small></span>
        <?php endif; ?>
      </td>
      <td class="money pos"><?=$ni?'—':fmt($f['ent'])?></td>
      <td class="money neg" style="font-size:11px;"><?=$f['imp']>0?'('.fmt($f['imp']).')':'—'?></td>
      <td class="money neg"><?=$ni?'—':fmt($f['sai'])?></td>
      <td class="money <?=$f['saldo']>=0?'pos':'neg'?>"><?=$ni?'—':fmt($f['saldo'])?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td><strong>TOTAL / SALDO FINAL</strong></td>
      <td class="text-right text-mono"><?=fmt($totE2)?></td>
      <td class="text-right text-mono" style="color:var(--red);"><?=$totImp2>0?'('.fmt($totImp2).')':'—'?></td>
      <td class="text-right text-mono"><?=fmt($totS2)?></td>
      <td class="money <?=($ultimoSaldoIniciado??0)>=0?'pos':'neg'?>" style="font-weight:bold;">
        <?= $ultimoSaldoIniciado !== null ? fmt($ultimoSaldoIniciado) : '—' ?>
      </td>
    </tr></tfoot>
  </table></div></div>
</div>

<!-- ═══ EXTRATO POR CONTA ══════════════════════════════════════════════════ -->
<?php elseif($tab==='extrato'):
  $contaFiltro = (int)($_GET['conta']??0);
  $contas3 = db()->all('SELECT * FROM contas WHERE ativa=1 ORDER BY nome');
  if(!$contaFiltro && !empty($contas3)) $contaFiltro = $contas3[0]['id'];
?>
<div class="win" style="margin-top:0;">
  <div class="win-title">📋 Extrato — <?=nomeMes($mes)?>/<?=$ano?>
    <form method="get" style="display:flex;gap:6px;align-items:center;font-size:12px;">
      <input type="hidden" name="tab" value="extrato">
      <input type="hidden" name="mes" value="<?=$mes?>">
      <input type="hidden" name="ano" value="<?=$ano?>">
      <select name="conta" onchange="this.form.submit()" style="font-size:11px;">
        <?php foreach($contas3 as $c): ?>
          <option value="<?=$c['id']?>" <?=$c['id']==$contaFiltro?'selected':''?>><?=h($c['nome'])?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
<?php
  $extLanc = db()->all(
    'SELECT l.*,c.nome cat,m.nome met,ct2.nome cdest FROM lancamentos l
     LEFT JOIN categorias c ON l.categoria_id=c.id
     LEFT JOIN metodos m ON l.metodo_id=m.id
     LEFT JOIN contas ct2 ON l.conta_destino_id=ct2.id
     WHERE (l.conta_id=? OR l.conta_destino_id=?) AND l.mes=? AND l.ano=? ORDER BY l.data,l.criado_em',
    [$contaFiltro,$contaFiltro,$mes,$ano]);
  $siExt    = db()->one('SELECT valor FROM saldos_iniciais WHERE conta_id=? AND mes=? AND ano=?',[$contaFiltro,$mes,$ano]);
  $saldoAcc = $siExt ? (float)$siExt['valor'] : 0;
?>
<div style="padding:0;"><div class="table-wrap"><table>
  <thead><tr><th>Data</th><th>Tipo</th><th>Descrição</th><th>Método</th><th class="text-right">Valor</th><th class="text-right">Saldo</th></tr></thead>
  <tbody>
    <tr style="background:#eef;font-style:italic;"><td colspan="4">Saldo inicial</td><td class="money">—</td><td class="money <?=$saldoAcc>=0?'pos':'neg'?>"><?=fmt($saldoAcc)?></td></tr>
    <?php foreach($extLanc as $l):
      $isRecDest = $l['conta_destino_id']==$contaFiltro;
      $isEnt = ($l['tipo']==='entrada') || ($l['tipo']==='transferencia' && $isRecDest);
      $val   = $isEnt ? ($l['valor_liquido']??$l['valor']) : $l['valor'];
      if($isEnt) $saldoAcc += $val; else $saldoAcc -= $val;
    ?>
    <tr>
      <td><?=fmtDate($l['data'])?></td>
      <td><span class="badge <?=$isEnt?'b-ent':'b-sai'?>"><?=$isEnt?'Entrada':'Saída'?><?=$l['tipo']==='transferencia'?' (T)':''?></span></td>
      <td><?=h($l['descricao']??'')?></td>
      <td><?=h($l['met']??'—')?></td>
      <td class="money <?=$isEnt?'pos':'neg'?>"><?=fmt($val)?></td>
      <td class="money <?=$saldoAcc>=0?'pos':'neg'?>"><?=fmt($saldoAcc)?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div></div></div>
<?php endif; ?>

<div class="flex flex-end mt-12">
  <a href="exportar.php?ano=<?=$ano?>" class="btn btn-default">&#128190; Exportar CSV/JSON</a>
</div>

<?php require_once ROOT_PATH.'/src/layout_footer.php'; ?>