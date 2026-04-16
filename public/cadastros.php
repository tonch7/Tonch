<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();

$tab    = $_GET['tab']    ?? 'clientes';
$tabsValidas = ['clientes','categorias','metodos','config'];
if (!in_array($tab, $tabsValidas, true)) { $tab = 'config'; }
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

// ---- POST handlers ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfOrAbort(url('public/cadastros.php') . '?tab=' . urlencode($tab));
    $a = $_POST['action'] ?? '';

    // CLIENTES
    if ($a === 'salvar_cliente') {
        $dados = [trim($_POST['nome']??''), trim($_POST['cpf_cnpj']??''), trim($_POST['endereco']??''), trim($_POST['telefone']??''), validEmailOrNull($_POST['email'] ?? '')];
        if ($id > 0) { db()->exec('UPDATE clientes SET nome=?,cpf_cnpj=?,endereco=?,telefone=?,email=? WHERE id=?', array_merge($dados,[$id])); flash('success','Cliente atualizado!'); }
        else { db()->insert('INSERT INTO clientes (nome,cpf_cnpj,endereco,telefone,email) VALUES (?,?,?,?,?)', $dados); flash('success','Cliente cadastrado!'); }
        header('Location: ' . url('public/cadastros.php') . '?tab=clientes'); exit;
    }

    // CATEGORIAS
    if ($a === 'salvar_cat') {
        $dados = [trim($_POST['nome']??''), $_POST['tipo']??'saida'];
        if ($id > 0) { db()->exec('UPDATE categorias SET nome=?,tipo=? WHERE id=?', array_merge($dados,[$id])); flash('success','Categoria atualizada!'); }
        else { db()->insert('INSERT INTO categorias (nome,tipo) VALUES (?,?)', $dados); flash('success','Categoria criada!'); }
        header('Location: ' . url('public/cadastros.php') . '?tab=categorias'); exit;
    }

    // MÉTODOS
    if ($a === 'salvar_metodo') {
        $dados = [trim($_POST['nome']??''), isset($_POST['tem_taxa'])?1:0, $_POST['taxa_tipo']??'percentual', parseMoney($_POST['taxa_valor']??'0'), isset($_POST['interno'])?1:0];
        if ($id > 0) { db()->exec('UPDATE metodos SET nome=?,tem_taxa=?,taxa_tipo=?,taxa_valor=?,interno=? WHERE id=?', array_merge($dados,[$id])); flash('success','Método atualizado!'); }
        else { db()->insert('INSERT INTO metodos (nome,tem_taxa,taxa_tipo,taxa_valor,interno) VALUES (?,?,?,?,?)', $dados); flash('success','Método criado!'); }
        header('Location: ' . url('public/cadastros.php') . '?tab=metodos'); exit;
    }

    // LIBERAR SISTEMA (marco zero — imutável)
    if ($a === 'liberar_sistema') {
        if (!sistemaLiberado()) {
            // Usa data do servidor (GMT Brasília) — nunca aceita input do usuário
            $dataHoje = date('Y-m-d'); // timezone já definido como America/Sao_Paulo
            $mesInicio = (int)date('n');
            $anoInicio = (int)date('Y');
            // Grava data_abertura como imutável
            configSet('data_abertura', $dataHoje, true);
            configSet('mes_inicio', (string)$mesInicio, true);
            configSet('ano_inicio', (string)$anoInicio, true);
            // Abre o caixa do mês atual automaticamente
            db()->exec('DELETE FROM caixas_abertos');   // remove seed Jan/2026 se diferente
            db()->exec('INSERT OR IGNORE INTO caixas_abertos (mes, ano) VALUES (?,?)', [$mesInicio, $anoInicio]);
            // Limpa dados de meses anteriores ao marco zero
            db()->exec('DELETE FROM meses_fechados WHERE (ano < ?) OR (ano = ? AND mes < ?)', [$anoInicio, $anoInicio, $mesInicio]);
            flash('success', '🚀 Sistema liberado! Marco zero: ' . date('d/m/Y') . '. Caixa aberto em ' . nomeMes($mesInicio) . '/' . $anoInicio . '. Tudo anterior ao marco é Nulo/R$0,00.');
        } else {
            flash('error', '⛔ O sistema já foi liberado. A data de abertura não pode ser alterada.');
        }
        header('Location: ' . url('public/cadastros.php') . '?tab=config'); exit;
    }


    if ($a === 'excluir_cliente' && !empty($_POST['id'])) {
        $delId = (int)$_POST['id'];
        $usos = db()->one('SELECT COUNT(*) as n FROM lancamentos WHERE cliente_id=?', [$delId]);
        if ($usos && $usos['n'] > 0) db()->exec('UPDATE lancamentos SET cliente_id=NULL WHERE cliente_id=?', [$delId]);
        db()->exec('UPDATE contas_pagar_receber SET cliente_id=NULL WHERE cliente_id=?', [$delId]);
        db()->exec('DELETE FROM clientes WHERE id=?', [$delId]);
        flash('success', 'Cliente excluído. Lançamentos anteriores preservados sem vínculo de cliente.');
        header('Location: ' . url('public/cadastros.php') . '?tab=clientes'); exit;
    }

    if ($a === 'excluir_categoria' && !empty($_POST['id'])) {
        db()->exec('DELETE FROM categorias WHERE id=?', [(int)$_POST['id']]);
        flash('success', 'Categoria excluída.');
        header('Location: ' . url('public/cadastros.php') . '?tab=categorias'); exit;
    }

    if ($a === 'excluir_metodo' && !empty($_POST['id'])) {
        db()->exec('DELETE FROM metodos WHERE id=?', [(int)$_POST['id']]);
        flash('success', 'Método excluído.');
        header('Location: ' . url('public/cadastros.php') . '?tab=metodos'); exit;
    }

    // ALTERAR SENHA
    if ($a === 'alterar_senha') {
        $atual  = $_POST['senha_atual'] ?? '';
        $nova   = $_POST['nova_senha']  ?? '';
        $conf   = $_POST['confirmar']   ?? '';
        $user   = db()->one('SELECT * FROM usuario WHERE id=?',[$_SESSION['uid']]);
        $h      = $user['password_hash'];
        $ok     = (substr($h,0,4)==='$2y$'||substr($h,0,4)==='$2a$') ? password_verify($atual,$h) : ($h===$atual);
        if (!$ok) flash('error','Senha atual incorreta.');
        elseif (strlen($nova) < 4) flash('error','Senha deve ter ao menos 4 caracteres.');
        elseif ($nova !== $conf) flash('error','As senhas não coincidem.');
        else { Auth::changePass($nova); flash('success','Senha alterada!'); }
        header('Location: ' . url('public/cadastros.php') . '?tab=config'); exit;
    }
}


$clientes   = db()->all('SELECT * FROM clientes ORDER BY nome');
$categorias = db()->all('SELECT * FROM categorias ORDER BY tipo,nome');
$metodos    = db()->all('SELECT * FROM metodos ORDER BY nome');
$editItem   = null;
if ($id > 0) {
    if ($tab==='clientes')   $editItem = db()->one('SELECT * FROM clientes WHERE id=?',[$id]);
    if ($tab==='categorias') $editItem = db()->one('SELECT * FROM categorias WHERE id=?',[$id]);
    if ($tab==='metodos')    $editItem = db()->one('SELECT * FROM metodos WHERE id=?',[$id]);
}

// Diagnóstico
$checks = [
    ['PDO SQLite', extension_loaded('pdo_sqlite')],
    ['password_hash', function_exists('password_hash')],
    ['Pasta database/ gravável', is_writable(ROOT_PATH.'/database')],
    ['PHP >= 7.4', version_compare(phpversion(),'7.4','>=')],
    ['Sessão ativa', session_status()===PHP_SESSION_ACTIVE],
];
$dbSize = round(filesize(ROOT_PATH.'/database/finapp.db')/1024,1);

$pageTitle='Cadastros'; $activePage='cadastros';
require_once ROOT_PATH.'/src/layout_header.php';
?>

<div style="background:#e8e8e8;padding:6px 8px 0;display:flex;gap:2px;border-bottom:2px solid #aaa;margin-bottom:0;">
  <?php foreach(['clientes'=>'Clientes','categorias'=>'Categorias','metodos'=>'Métodos','config'=>'Config/Sistema'] as $k=>$l): ?>
  <a href="?tab=<?=$k?>" class="mtab <?=$tab===$k?'active':''?>"><?=$l?></a>
  <?php endforeach; ?>
</div>

<!-- CLIENTES -->
<?php if($tab==='clientes'): ?>
<div class="win" style="border-radius:0 0 4px 4px;margin-top:0;">
  <div class="win-title">&#128101; Clientes
    <a href="?tab=clientes&action=novo" class="btn btn-primary btn-sm">+ Novo</a></div>
  <div style="padding:0;"><div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Nome</th><th>CPF/CNPJ</th><th>Telefone</th><th>E-mail</th><th>Ações</th></tr></thead>
    <tbody>
      <?php foreach($clientes as $c): ?>
      <tr><td><?=$c['id']?></td><td><b><?=h($c['nome'])?></b></td>
          <td class="text-mono" style="font-size:11px;"><?=h($c['cpf_cnpj'])?></td>
          <td><?=h($c['telefone'])?></td><td><?=h($c['email'])?></td>
          <td><a href="?tab=clientes&action=editar&id=<?=$c['id']?>" class="btn btn-default btn-xs">✎</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Excluir este cliente?');"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="excluir_cliente"><input type="hidden" name="id" value="<?=$c['id']?>"><button type="submit" class="btn btn-danger btn-xs">✕</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div></div>
</div>

<?php if($action==='novo'||$action==='editar'): ?>
<div class="modal-bg open"><div class="modal" style="max-width:500px;">
  <div class="modal-head"><h3><?=$editItem?'✎ Editar Cliente':'+ Novo Cliente'?></h3><button class="modal-close">✕</button></div>
  <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="salvar_cliente">
  <div class="modal-body">
    <div class="fg mb-8"><label>Nome *</label><input type="text" name="nome" value="<?=h($editItem['nome']??'')?>" required autofocus></div>
    <div class="form-row">
      <div class="fg w-half"><label>CPF/CNPJ</label><input type="text" name="cpf_cnpj" value="<?=h($editItem['cpf_cnpj']??'')?>"></div>
      <div class="fg w-half"><label>Telefone</label><input type="tel" name="telefone" value="<?=h($editItem['telefone']??'')?>"></div>
    </div>
    <div class="fg mb-8"><label>E-mail</label><input type="email" name="email" value="<?=h($editItem['email']??'')?>"></div>
    <div class="fg"><label>Endereço</label><textarea name="endereco"><?=h($editItem['endereco']??'')?></textarea></div>
  </div>
  <div class="modal-foot"><a href="?tab=clientes" class="btn btn-default">Cancelar</a><button type="submit" class="btn btn-primary">✓ Salvar</button></div>
  </form>
</div></div>
<?php endif; ?>

<!-- CATEGORIAS -->
<?php elseif($tab==='categorias'): ?>
<div class="win" style="border-radius:0 0 4px 4px;margin-top:0;">
  <div class="win-title">&#127991; Categorias
    <a href="?tab=categorias&action=novo" class="btn btn-primary btn-sm">+ Nova</a></div>
  <div class="grid-2" style="gap:0;">
    <?php foreach(['saida'=>['&#8595; Saída','b-sai'],'entrada'=>['&#8593; Entrada','b-ent']] as $tipo=>[$label,$badge]): ?>
    <div>
      <div style="padding:6px 12px;background:#e0e0e0;font-size:11px;font-weight:bold;border-bottom:1px solid #bbb;"><?=$label?></div>
      <div class="table-wrap" style="border:none;border-radius:0;"><table>
        <tbody>
          <?php foreach(array_filter($categorias,fn($c)=>$c['tipo']===$tipo) as $c): ?>
          <tr><td><?=h($c['nome'])?></td>
              <td style="width:80px;white-space:nowrap;">
                <a href="?tab=categorias&action=editar&id=<?=$c['id']?>" class="btn btn-default btn-xs">✎</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Excluir esta categoria?');"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="excluir_categoria"><input type="hidden" name="id" value="<?=$c['id']?>"><button type="submit" class="btn btn-danger btn-xs">✕</button></form>
              </td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php if($action==='novo'||$action==='editar'): ?>
<div class="modal-bg open"><div class="modal" style="max-width:380px;">
  <div class="modal-head"><h3><?=$editItem?'✎ Editar':'+ Nova'?> Categoria</h3><button class="modal-close">✕</button></div>
  <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
<input type="hidden" name="action" value="salvar_cat">
  <div class="modal-body">
    <div class="fg mb-8"><label>Nome *</label><input type="text" name="nome" value="<?=h($editItem['nome']??'')?>" required autofocus></div>
    <div class="fg"><label>Tipo</label><select name="tipo">
      <option value="saida" <?=($editItem['tipo']??'')==='saida'?'selected':''?>>Saída</option>
      <option value="entrada" <?=($editItem['tipo']??'')==='entrada'?'selected':''?>>Entrada</option>
    </select></div>
  </div>
  <div class="modal-foot"><a href="?tab=categorias" class="btn btn-default">Cancelar</a><button type="submit" class="btn btn-primary">✓ Salvar</button></div>
  </form>
</div></div>
<?php endif; ?>

<!-- MÉTODOS -->
<?php elseif($tab==='metodos'): ?>
<div class="win" style="border-radius:0 0 4px 4px;margin-top:0;">
  <div class="win-title">&#128179; Métodos de Pagto/Recebto
    <a href="?tab=metodos&action=novo" class="btn btn-primary btn-sm">+ Novo</a></div>
  <div style="padding:0;"><div class="table-wrap"><table>
    <thead><tr><th>Nome</th><th>Taxa</th><th>Tipo taxa</th><th>Valor padrão</th><th>Interno</th><th>Ações</th></tr></thead>
    <tbody>
      <?php foreach($metodos as $m): ?>
      <tr><td><b><?=h($m['nome'])?></b></td>
          <td><?=$m['tem_taxa']?'<span class="badge b-sai">Sim</span>':'—'?></td>
          <td><?=$m['taxa_tipo']?></td>
          <td class="text-mono"><?=$m['tem_taxa']?($m['taxa_tipo']==='percentual'?$m['taxa_valor'].'%':fmt($m['taxa_valor'])):'—'?></td>
          <td><?=$m['interno']?'Sim':'—'?></td>
          <td><a href="?tab=metodos&action=editar&id=<?=$m['id']?>" class="btn btn-default btn-xs">✎</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Excluir este método?');"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="excluir_metodo"><input type="hidden" name="id" value="<?=$m['id']?>"><button type="submit" class="btn btn-danger btn-xs">✕</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div></div>
</div>
<?php if($action==='novo'||$action==='editar'): ?>
<div class="modal-bg open"><div class="modal" style="max-width:440px;">
  <div class="modal-head"><h3><?=$editItem?'✎ Editar':'+ Novo'?> Método</h3><button class="modal-close">✕</button></div>
  <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
<input type="hidden" name="action" value="salvar_metodo">
  <div class="modal-body">
    <div class="fg mb-8"><label>Nome *</label><input type="text" name="nome" value="<?=h($editItem['nome']??'')?>" required></div>
    <div class="form-row">
      <div class="fg" style="flex-direction:row;align-items:center;gap:6px;padding-top:18px;">
        <input type="checkbox" name="tem_taxa" id="tem_taxa" value="1" <?=($editItem['tem_taxa']??0)?'checked':''?>>
        <label for="tem_taxa" style="font-weight:normal;cursor:pointer;">Possui taxa</label>
      </div>
      <div class="fg"><label>Tipo</label><select name="taxa_tipo">
        <option value="percentual" <?=($editItem['taxa_tipo']??'')==='percentual'?'selected':''?>>Percentual (%)</option>
        <option value="fixo" <?=($editItem['taxa_tipo']??'')==='fixo'?'selected':''?>>Valor fixo (R$)</option>
      </select></div>
      <div class="fg"><label>Valor padrão</label><input type="text" name="taxa_valor" class="money-input" value="<?=number_format($editItem['taxa_valor']??0,2,',','.')?>"></div>
    </div>
    <div class="fg" style="flex-direction:row;align-items:center;gap:6px;">
      <input type="checkbox" name="interno" id="interno" value="1" <?=($editItem['interno']??0)?'checked':''?>>
      <label for="interno" style="font-weight:normal;cursor:pointer;">Transferência interna (não afeta DRE)</label>
    </div>
  </div>
  <div class="modal-foot"><a href="?tab=metodos" class="btn btn-default">Cancelar</a><button type="submit" class="btn btn-primary">✓ Salvar</button></div>
  </form>
</div></div>
<?php endif; ?>

<!-- CONFIG -->
<?php elseif($tab==='config'):
  $dataAb = dataAberturaSistema();
  $sysLib = sistemaLiberado();
?>
<div style="margin-top:0;">

  <!-- LIBERAR SISTEMA -->
  <div class="win mb-12" style="<?= $sysLib ? 'border-color:#28a745;background:#f0fff4;' : 'border-color:#e6b800;background:#fffbe6;' ?>">
    <div class="win-title" style="background:<?= $sysLib ? 'linear-gradient(180deg,#c8f5d0,#8ed8a0)' : 'linear-gradient(180deg,#fff3b0,#ffd740)' ?>;">
      <?= $sysLib ? '✅ Sistema Liberado (Marco Zero Gravado)' : '🚀 Liberar Sistema — Configurar Marco Zero' ?>
    </div>
    <div class="win-body">
      <?php if ($sysLib): ?>
        <div class="alert alert-success" style="margin-bottom:0;">
          🔒 <strong>Data de Abertura do Sistema:</strong>
          <?= date('d/m/Y', strtotime($dataAb['data'])) ?> —
          <?= nomeMes($dataAb['mes']) ?>/<?= $dataAb['ano'] ?>
          <br><small>Esta data é <strong>imutável</strong>. Todos os meses anteriores a ela exibem R$&nbsp;0,00 / Nulo.</small>
        </div>
      <?php else: ?>
        <p class="text-sm mb-12">
          Ao clicar em <strong>"Liberar Sistema"</strong>, a <strong>data atual do servidor</strong> (Horário Brasília) será gravada como marco zero permanente.<br>
          Meses anteriores ficarão bloqueados com valor <strong>R$ 0,00 / Nulo</strong>.<br>
          O caixa será aberto a partir do <strong>mês vigente</strong>.<br>
          <strong>⚠️ Esta operação é irreversível.</strong>
        </p>
        <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px;">
          📅 <strong>Data/Hora atual do servidor (Brasília):</strong>
          <span style="font-family:monospace;font-size:14px;font-weight:700;color:#555;">
            <?= date('d/m/Y H:i:s') ?>
          </span>
          — Esta será gravada como marco zero se você confirmar.
        </div>
        <button type="button" class="btn btn-primary"
                onclick="document.getElementById('modalLiberarSistema').classList.add('open')"
                style="background:#e6b800;color:#333;border-color:#cc9900;font-weight:700;font-size:13px;">
          🚀 LIBERAR SISTEMA
        </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid-2">
    <!-- ALTERAR SENHA -->
    <div class="win">
      <div class="win-title">🔑 Alterar Senha</div>
      <div class="win-body">
        <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
<input type="hidden" name="action" value="alterar_senha">
          <div class="fg mb-8"><label>Senha atual</label><input type="password" name="senha_atual" required></div>
          <div class="fg mb-8"><label>Nova senha</label><input type="password" name="nova_senha" required></div>
          <div class="fg mb-8"><label>Confirmar</label><input type="password" name="confirmar" required></div>
          <button type="submit" class="btn btn-primary">Alterar Senha</button>
        </form>
      </div>
    </div>

    <!-- DIAGNÓSTICO -->
    <div class="win">
      <div class="win-title">&#128202; Diagnóstico do Sistema</div>
      <div class="win-body">
        <table style="width:100%;font-size:12px;margin-bottom:12px;">
          <tr><td style="font-weight:bold;">PHP</td><td><?=phpversion()?></td></tr>
          <tr><td style="font-weight:bold;">Banco</td><td><?=$dbSize?> KB</td></tr>
          <tr><td style="font-weight:bold;">Usuário</td><td><?=h($_SESSION['username']??'')?></td></tr>
          <tr><td style="font-weight:bold;">Horário Servidor</td><td style="font-family:monospace;"><?=date('d/m/Y H:i:s')?> (SP)</td></tr>
          <tr><td style="font-weight:bold;">Marco Zero</td><td><?= $sysLib ? '<span style="color:var(--green)">✓ ' . date('d/m/Y', strtotime($dataAb['data'])) . '</span>' : '<span style="color:var(--red)">Não configurado</span>' ?></td></tr>
        </table>
        <hr class="sep">
        <?php foreach($checks as [$lbl,$ok]): ?>
        <div class="flex flex-btwn text-sm" style="padding:3px 0;border-bottom:1px solid #eee;">
          <span><?=$lbl?></span>
          <span style="color:<?=$ok?'var(--green)':'var(--red)'?>"><?=$ok?'✓ OK':'✗ Falha'?></span>
        </div>
        <?php endforeach; ?>
        <div class="mt-8">
          <a href="<?= url("public/exportar.php") ?>?ano=<?=date('Y')?>" class="btn btn-default btn-sm">&#128190; Exportar Dados</a>
          &nbsp;
          <a href="<?= url("public/audit.php") ?>" class="btn btn-default btn-sm">📊 Ver Logs de Auditoria</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- MODAL CONFIRMAR LIBERAÇÃO DO SISTEMA -->
<div class="modal-bg" id="modalLiberarSistema">
  <div class="modal" style="max-width:480px;">
    <div class="modal-head" style="background:linear-gradient(180deg,#fff3b0,#ffd740);">
      <h3 style="color:#333;">🚀 Confirmar Liberação do Sistema</h3>
      <button class="modal-close" onclick="document.getElementById('modalLiberarSistema').classList.remove('open')">✕</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-error mb-12" style="font-size:12px;">
        ⚠️ <strong>ATENÇÃO — OPERAÇÃO IRREVERSÍVEL</strong><br>
        A data abaixo será gravada como <strong>Marco Zero do Sistema</strong>.<br>
        Não poderá ser alterada depois.
      </div>
      <div style="text-align:center;padding:16px;background:#f8f8f8;border-radius:6px;margin-bottom:12px;">
        <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px;">Data/Hora Atual do Servidor (Brasília)</div>
        <div style="font-size:28px;font-weight:700;font-family:monospace;color:#222;" id="modalDataAtual"><?=date('d/m/Y H:i:s')?></div>
        <div style="font-size:13px;color:#555;margin-top:4px;">Esta data será o marco zero. Meses anteriores = R$ 0,00 / Nulo.</div>
      </div>
      <p class="text-sm text-muted">Ao confirmar, o caixa de <strong><?=nomeMes((int)date('n'))?>/<?=date('Y')?></strong> será aberto automaticamente.</p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-default"
              onclick="document.getElementById('modalLiberarSistema').classList.remove('open')">Cancelar</button>
      <form method="post" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="liberar_sistema">
        <button type="submit" class="btn btn-danger"
                style="background:#e6b800;color:#333;border-color:#cc9900;font-weight:700;">
          🚀 CONFIRMAR — GRAVAR MARCO ZERO
        </button>
      </form>
    </div>
  </div>
</div>
<script>
// Atualiza relógio no modal em tempo real
(function(){
  function pad(n){return String(n).padStart(2,'0');}
  function tick(){
    var now = new Date();
    var sp = new Date(now.toLocaleString('en-US',{timeZone:'America/Sao_Paulo'}));
    var str = pad(sp.getDate())+'/'+pad(sp.getMonth()+1)+'/'+sp.getFullYear()+
              ' '+pad(sp.getHours())+':'+pad(sp.getMinutes())+':'+pad(sp.getSeconds());
    var el = document.getElementById('modalDataAtual');
    if(el) el.textContent = str;
  }
  tick(); setInterval(tick,1000);
})();
</script>


<?php endif; ?>

<?php require_once ROOT_PATH.'/src/layout_footer.php'; ?>
