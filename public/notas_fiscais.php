<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();
requireSistemaLiberado();

$_aberto = mesAbertoAtual();
$ano  = (int)($_GET['ano']  ?? $_aberto['ano']);
$mes  = (int)($_GET['mes']  ?? 0);
$cli  = (int)($_GET['cli']  ?? 0);
$tab  = $_GET['tab'] ?? 'lista';
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) { $id = (int)$_POST['id']; }

$contas   = db()->all('SELECT * FROM contas WHERE ativa=1 ORDER BY nome');
$clientes = db()->all('SELECT * FROM clientes ORDER BY nome');
$catEnt   = db()->all('SELECT * FROM categorias WHERE tipo="entrada" ORDER BY nome');
$metodos  = db()->all('SELECT * FROM metodos ORDER BY nome');


function nfEnsureUploadColumns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [];
    try {
        foreach ((array) db()->all('PRAGMA table_info(lancamentos)') as $col) {
            if (!empty($col['name'])) $cols[$col['name']] = true;
        }
    } catch (Throwable $e) {
        return;
    }

    $needed = [
        'anexo_xml_path'  => 'ALTER TABLE lancamentos ADD COLUMN anexo_xml_path TEXT NULL',
        'anexo_xml_nome'  => 'ALTER TABLE lancamentos ADD COLUMN anexo_xml_nome TEXT NULL',
        'anexo_pdf_path'  => 'ALTER TABLE lancamentos ADD COLUMN anexo_pdf_path TEXT NULL',
        'anexo_pdf_nome'  => 'ALTER TABLE lancamentos ADD COLUMN anexo_pdf_nome TEXT NULL',
    ];

    foreach ($needed as $name => $sql) {
        if (!isset($cols[$name])) {
            try { db()->exec($sql); } catch (Throwable $e) { /* mantém silencioso para não quebrar a tela */ }
        }
    }
}

function nfEnsureContasReceberColumns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [];
    try {
        foreach ((array) db()->all('PRAGMA table_info(contas_pagar_receber)') as $col) {
            if (!empty($col['name'])) $cols[$col['name']] = true;
        }
    } catch (Throwable $e) {
        return;
    }

    $needed = [
        'origem_nf_id' => 'ALTER TABLE contas_pagar_receber ADD COLUMN origem_nf_id INTEGER NULL',
    ];

    foreach ($needed as $name => $sql) {
        if (!isset($cols[$name])) {
            try { db()->exec($sql); } catch (Throwable $e) { /* silencioso para não quebrar */ }
        }
    }
}

function nfSyncContaReceber(int $lancamentoId, array $payload): void
{
    nfEnsureContasReceberColumns();

    $clienteNome = '';
    if (!empty($payload['cliente_id'])) {
        $cli = db()->one('SELECT nome FROM clientes WHERE id=?', [(int)$payload['cliente_id']]);
        $clienteNome = trim((string)($cli['nome'] ?? ''));
    }

    $descricao = trim((string)($payload['descricao'] ?? ''));
    $numNf = trim((string)($payload['num_nf'] ?? ''));
    if ($descricao === '') {
        $descricao = $numNf !== '' ? 'NF ' . $numNf : 'Nota fiscal';
    } elseif ($numNf !== '' && stripos($descricao, 'NF') === false) {
        $descricao .= ' | NF ' . $numNf;
    }

    $params = [
        'receber',
        $payload['data'],
        (float)$payload['valor'],
        $descricao,
        $clienteNome,
        !empty($payload['cliente_id']) ? (int)$payload['cliente_id'] : null,
        !empty($payload['categoria_id']) ? (int)$payload['categoria_id'] : null,
        !empty($payload['conta_id']) ? (int)$payload['conta_id'] : null,
        !empty($payload['metodo_id']) ? (int)$payload['metodo_id'] : null,
        trim((string)($payload['observacoes'] ?? '')),
        1,
        $payload['data'],
        (float)$payload['valor'],
        $lancamentoId,
        $lancamentoId,
    ];

    $exist = db()->one('SELECT id FROM contas_pagar_receber WHERE origem_nf_id=?', [$lancamentoId]);
    if ($exist) {
        db()->exec(
            'UPDATE contas_pagar_receber SET tipo=?, data_vencimento=?, valor=?, descricao=?, cliente_fornecedor=?, cliente_id=?, categoria_id=?, conta_id=?, metodo_id=?, observacoes=?, pago_recebido=?, data_baixa=?, valor_efetivo=?, lancamento_id=? WHERE id=?',
            [
                'receber',
                $payload['data'],
                (float)$payload['valor'],
                $descricao,
                $clienteNome,
                !empty($payload['cliente_id']) ? (int)$payload['cliente_id'] : null,
                !empty($payload['categoria_id']) ? (int)$payload['categoria_id'] : null,
                !empty($payload['conta_id']) ? (int)$payload['conta_id'] : null,
                !empty($payload['metodo_id']) ? (int)$payload['metodo_id'] : null,
                trim((string)($payload['observacoes'] ?? '')),
                1,
                $payload['data'],
                (float)$payload['valor'],
                $lancamentoId,
                $exist['id']
            ]
        );
    } else {
        db()->insert(
            'INSERT INTO contas_pagar_receber (tipo, data_vencimento, valor, descricao, cliente_fornecedor, cliente_id, categoria_id, conta_id, metodo_id, observacoes, pago_recebido, data_baixa, valor_efetivo, lancamento_id, origem_nf_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            $params
        );
    }
}

function nfUploadBaseDir(): string
{
    return ROOT_PATH . '/public/uploads/notas_fiscais';
}

function nfEnsureUploadDir(): void
{
    $dir = nfUploadBaseDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function nfUploadUrl(?string $path): ?string
{
    if (!$path) return null;
    return url('public/uploads/notas_fiscais/' . rawurlencode(basename($path)));
}

function nfDeleteFileIfExists(?string $path): void
{
    if (!$path) return;
    $realBase = realpath(nfUploadBaseDir());
    $realFile = realpath($path);
    if ($realBase && $realFile && strpos($realFile, $realBase) === 0 && is_file($realFile)) {
        @unlink($realFile);
    }
}

function nfStoreUploadedFile(array $file, string $type, ?string $numNf = null): ?array
{
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload do arquivo ' . strtoupper($type) . '.');
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = [
        'xml' => ['xml'],
        'pdf' => ['pdf'],
    ];

    if (!isset($allowed[$type]) || !in_array($ext, $allowed[$type], true)) {
        throw new RuntimeException('Arquivo inválido para ' . strtoupper($type) . '. Envie apenas .' . $type . '.');
    }

    nfEnsureUploadDir();

    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)($numNf ?: 'nf'));
    $base = trim($base, '-');
    if ($base === '') $base = 'nf';

    $stored = $base . '-' . $type . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = nfUploadBaseDir() . '/' . $stored;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Não foi possível salvar o arquivo ' . strtoupper($type) . '.');
    }

    return [
        'path' => $target,
        'name' => $file['name'] ?? $stored,
    ];
}

nfEnsureUploadColumns();
nfEnsureContasReceberColumns();


// ---- POST: salvar / atualizar NF ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfOrAbort(url('public/notas_fiscais.php') . "?ano=$ano&mes=$mes&cli=$cli&tab=$tab");
    $a = $_POST['action'] ?? '';

    if ($a === 'salvar_nf') {
        $data  = $_POST['data'] ?? date('Y-m-d');
        $mesPl = (int)date('m', strtotime($data));
        $anoPl = (int)date('Y', strtotime($data));

        if ($id > 0) {
            // Editar NF — verifica imutabilidade pelo mês original do lançamento
            $lExist = db()->one('SELECT mes, ano FROM lancamentos WHERE id=?', [$id]);
            if ($lExist && !caixaAberto((int)$lExist['mes'], (int)$lExist['ano'])) {
                flash('error', '🔒 ' . nomeMes($lExist['mes']) . '/' . $lExist['ano'] . ' está FECHADO — imutável.');
                header('Location: ' . url('public/notas_fiscais.php') . "?ano=$ano&mes=$mes&cli=$cli&tab=$tab"); exit;
            }
            $vl = parseMoney($_POST['valor'] ?? '0');
            $tx = (float)($_POST['taxa_valor'] ?? 0);
            $anexosAtuais = db()->one('SELECT anexo_xml_path, anexo_xml_nome, anexo_pdf_path, anexo_pdf_nome FROM lancamentos WHERE id=?', [$id]) ?: [];

            $novoXml = nfStoreUploadedFile($_FILES['anexo_xml'] ?? [], 'xml', trim($_POST['num_nf'] ?? ''));
            $novoPdf = nfStoreUploadedFile($_FILES['anexo_pdf'] ?? [], 'pdf', trim($_POST['num_nf'] ?? ''));

            $xmlPath = $novoXml['path'] ?? ($anexosAtuais['anexo_xml_path'] ?? null);
            $xmlNome = $novoXml['name'] ?? ($anexosAtuais['anexo_xml_nome'] ?? null);
            $pdfPath = $novoPdf['path'] ?? ($anexosAtuais['anexo_pdf_path'] ?? null);
            $pdfNome = $novoPdf['name'] ?? ($anexosAtuais['anexo_pdf_nome'] ?? null);

            db()->exec(
                'UPDATE lancamentos SET data=?,valor=?,valor_liquido=?,taxa_valor=?,descricao=?,
                 categoria_id=?,cliente_id=?,conta_id=?,metodo_id=?,num_nf=?,observacoes=?,
                 anexo_xml_path=?,anexo_xml_nome=?,anexo_pdf_path=?,anexo_pdf_nome=?,
                 tem_nf=1,mes=?,ano=? WHERE id=?',
                [
                    $data, $vl, $vl - $tx, $tx,
                    trim($_POST['descricao'] ?? ''),
                    !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null,
                    !empty($_POST['cliente_id'])   ? (int)$_POST['cliente_id']   : null,
                    (int)($_POST['conta_id'] ?? 0),
                    !empty($_POST['metodo_id'])    ? (int)$_POST['metodo_id']    : null,
                    trim($_POST['num_nf'] ?? ''),
                    trim($_POST['observacoes'] ?? ''),
                    $xmlPath, $xmlNome, $pdfPath, $pdfNome,
                    $mesPl, $anoPl, $id
                ]
            );
            nfSyncContaReceber($id, [
                'data'         => $data,
                'valor'        => $vl,
                'descricao'    => trim($_POST['descricao'] ?? ''),
                'categoria_id' => !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null,
                'cliente_id'   => !empty($_POST['cliente_id'])   ? (int)$_POST['cliente_id']   : null,
                'conta_id'     => (int)($_POST['conta_id'] ?? 0),
                'metodo_id'    => !empty($_POST['metodo_id'])    ? (int)$_POST['metodo_id']    : null,
                'num_nf'       => trim($_POST['num_nf'] ?? ''),
                'observacoes'  => trim($_POST['observacoes'] ?? ''),
            ]);
            if ($novoXml && !empty($anexosAtuais['anexo_xml_path']) && $anexosAtuais['anexo_xml_path'] !== $xmlPath) {
                nfDeleteFileIfExists($anexosAtuais['anexo_xml_path']);
            }
            if ($novoPdf && !empty($anexosAtuais['anexo_pdf_path']) && $anexosAtuais['anexo_pdf_path'] !== $pdfPath) {
                nfDeleteFileIfExists($anexosAtuais['anexo_pdf_path']);
            }
            flash('success', '✓ Nota Fiscal atualizada!');
        } else {
            // Nova NF — registrarLancamento já bloqueia mês fechado/não aberto
            try {
                $novoXml = nfStoreUploadedFile($_FILES['anexo_xml'] ?? [], 'xml', trim($_POST['num_nf'] ?? ''));
                $novoPdf = nfStoreUploadedFile($_FILES['anexo_pdf'] ?? [], 'pdf', trim($_POST['num_nf'] ?? ''));

                $vl = parseMoney($_POST['valor'] ?? '0');
                $lancId = registrarLancamento([
                    'tipo'         => 'entrada',
                    'data'         => $data,
                    'valor'        => $vl,
                    'descricao'    => trim($_POST['descricao'] ?? ''),
                    'categoria_id' => !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null,
                    'cliente_id'   => !empty($_POST['cliente_id'])   ? (int)$_POST['cliente_id']   : null,
                    'conta_id'     => (int)($_POST['conta_id'] ?? 0),
                    'metodo_id'    => !empty($_POST['metodo_id'])    ? (int)$_POST['metodo_id']    : null,
                    'taxa_valor'   => (float)($_POST['taxa_valor'] ?? 0),
                    'taxa_tipo'    => $_POST['taxa_tipo'] ?? 'fixo',
                    'tem_nf'       => 1,
                    'num_nf'       => trim($_POST['num_nf'] ?? ''),
                    'observacoes'  => trim($_POST['observacoes'] ?? ''),
                    'anexo_xml_path' => $novoXml['path'] ?? null,
                    'anexo_xml_nome' => $novoXml['name'] ?? null,
                    'anexo_pdf_path' => $novoPdf['path'] ?? null,
                    'anexo_pdf_nome' => $novoPdf['name'] ?? null,
                ]);
                nfSyncContaReceber((int)$lancId, [
                    'data'         => $data,
                    'valor'        => $vl,
                    'descricao'    => trim($_POST['descricao'] ?? ''),
                    'categoria_id' => !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null,
                    'cliente_id'   => !empty($_POST['cliente_id'])   ? (int)$_POST['cliente_id']   : null,
                    'conta_id'     => (int)($_POST['conta_id'] ?? 0),
                    'metodo_id'    => !empty($_POST['metodo_id'])    ? (int)$_POST['metodo_id']    : null,
                    'num_nf'       => trim($_POST['num_nf'] ?? ''),
                    'observacoes'  => trim($_POST['observacoes'] ?? ''),
                ]);
                flash('success', '✓ Nota Fiscal registrada e conta a receber vinculada automaticamente como recebida!');
            } catch (Exception $e) {
                flash('error', $e->getMessage());
            }
        }
        header('Location: ' . url('public/notas_fiscais.php') . "?ano=$ano&mes=$mes&cli=$cli&tab=$tab"); exit;
    }
    if ($a === 'excluir_nf' && $id > 0) {
        $lExist = db()->one('SELECT id, mes, ano FROM lancamentos WHERE id=? AND tem_nf=1', [$id]);
        if (!$lExist) {
            flash('error', 'Nota fiscal não encontrada.');
        } elseif (!caixaAberto((int)$lExist['mes'], (int)$lExist['ano'])) {
            flash('error', '🔒 ' . nomeMes($lExist['mes']) . '/' . $lExist['ano'] . ' está FECHADO — exclusão bloqueada.');
            auditLog('NF_DELETE_BLOQUEADO', 'notas_fiscais', 'ID #' . $id . ' mês ' . $lExist['mes'] . '/' . $lExist['ano'] . ' fechado');
        } else {
            $anexos = db()->one('SELECT anexo_xml_path, anexo_pdf_path FROM lancamentos WHERE id=?', [$id]) ?: [];
            db()->exec('DELETE FROM contas_pagar_receber WHERE origem_nf_id=?', [$id]);
            db()->exec('DELETE FROM lancamentos WHERE id=? OR taxa_lancamento_id=?', [$id, $id]);
            nfDeleteFileIfExists($anexos['anexo_xml_path'] ?? null);
            nfDeleteFileIfExists($anexos['anexo_pdf_path'] ?? null);
            auditLog('NF_DELETE', 'notas_fiscais', 'ID #' . $id . ' excluída');
            flash('success', 'Nota fiscal excluída.');
        }
        header('Location: ' . url('public/notas_fiscais.php') . "?ano=$ano&mes=$mes&cli=$cli&tab=$tab"); exit;
    }
}

// ---- Excluir NF — verifica imutabilidade pelo mês do lançamento ----

$editNF = ($action === 'editar_nf' && $id > 0)
    ? db()->one('SELECT *, anexo_xml_path, anexo_xml_nome, anexo_pdf_path, anexo_pdf_nome FROM lancamentos WHERE id=? AND tem_nf=1', [$id])
    : null;

// ---- Filtros ----
$params  = [];
$where   = ['l.tem_nf = 1'];

if ($ano)  { $where[] = 'l.ano = ?';         $params[] = $ano; }
if ($mes)  { $where[] = 'l.mes = ?';         $params[] = $mes; }
if ($cli)  { $where[] = 'l.cliente_id = ?';  $params[] = $cli; }

$whereStr = implode(' AND ', $where);

// ---- Dados ----
$notas = db()->all(
    "SELECT l.id, l.data, l.num_nf, l.valor, l.valor_liquido, l.taxa_valor,
            l.descricao, l.mes, l.ano, l.observacoes,
            l.anexo_xml_path, l.anexo_xml_nome, l.anexo_pdf_path, l.anexo_pdf_nome,
            c.nome AS categoria, cl.nome AS cliente, ct.nome AS conta,
            m.nome AS metodo
     FROM lancamentos l
     LEFT JOIN categorias c  ON l.categoria_id = c.id
     LEFT JOIN clientes cl   ON l.cliente_id   = cl.id
     LEFT JOIN contas ct     ON l.conta_id     = ct.id
     LEFT JOIN metodos m     ON l.metodo_id    = m.id
     WHERE {$whereStr}
     ORDER BY l.data DESC, l.criado_em DESC",
    $params
);

// Totais
$totBruto  = array_sum(array_column($notas, 'valor'));
$totLiq    = array_sum(array_column($notas, 'valor_liquido'));
$totTaxa   = array_sum(array_column($notas, 'taxa_valor'));

// Clientes para filtro
$clientes = db()->all('SELECT id, nome FROM clientes ORDER BY nome');

// Ranking por cliente
$rankCliente = db()->all(
    "SELECT cl.nome AS cliente, COUNT(*) AS qtd, SUM(l.valor) AS total
     FROM lancamentos l
     LEFT JOIN clientes cl ON l.cliente_id = cl.id
     WHERE l.tem_nf = 1 AND l.ano = ?
     GROUP BY l.cliente_id ORDER BY total DESC",
    [$ano]
);

$pageTitle  = 'Notas Fiscais';
$activePage = 'notas_fiscais';
require_once ROOT_PATH . '/src/layout_header.php';
?>

<!-- CARDS RESUMO -->
<div class="grid-auto mb-12">
  <div class="scard">
    <div class="lbl">&#128204; Total de NFs</div>
    <div class="val val-pos"><?= count($notas) ?></div>
    <div class="sub">Notas emitidas<?= $ano ? ' em '.$ano : '' ?></div>
  </div>
  <div class="scard">
    <div class="lbl">&#8593; Valor Bruto</div>
    <div class="val val-pos"><?= fmt($totBruto) ?></div>
    <div class="sub">Soma dos valores brutos</div>
  </div>
  <div class="scard">
    <div class="lbl">&#9654; Valor Líquido</div>
    <div class="val val-pos"><?= fmt($totLiq ?: $totBruto) ?></div>
    <div class="sub">Após taxas/descontos</div>
  </div>
  <div class="scard">
    <div class="lbl">&#8595; Taxas Descontadas</div>
    <div class="val val-neg"><?= fmt($totTaxa) ?></div>
    <div class="sub">Total de taxas</div>
  </div>
</div>

<!-- FILTROS -->
<div class="win mb-12">
  <div class="win-title">&#128269; Filtros</div>
  <div class="win-body">
    <form method="get" class="flex flex-gap flex-wrap" style="align-items:flex-end;gap:10px;">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <div class="fg" style="min-width:120px;">
        <label>Ano</label>
        <select name="ano">
          <?php for ($y = (int)date('Y'); $y >= 2020; $y--): ?>
            <option value="<?= $y ?>" <?= $y == $ano ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="fg" style="min-width:130px;">
        <label>Mês</label>
        <select name="mes">
          <option value="0" <?= !$mes ? 'selected' : '' ?>>Todos</option>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m == $mes ? 'selected' : '' ?>><?= nomeMes($m) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="fg" style="min-width:180px;">
        <label>Cliente</label>
        <select name="cli">
          <option value="0" <?= !$cli ? 'selected' : '' ?>>Todos</option>
          <?php foreach ($clientes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $c['id'] == $cli ? 'selected' : '' ?>><?= h($c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">&#128269; Filtrar</button>
      <a href="notas_fiscais.php?ano=<?= $ano ?>" class="btn btn-default">Limpar</a>
    </form>
  </div>
</div>

<!-- ABAS -->
<div style="background:#e8e8e8;padding:6px 8px 0;display:flex;gap:2px;border-bottom:2px solid #aaa;margin-bottom:0;">
  <a href="?ano=<?= $ano ?>&mes=<?= $mes ?>&cli=<?= $cli ?>&tab=lista"
     class="mtab <?= $tab === 'lista' ? 'active' : '' ?>">&#128204; Lista de Notas (<?= count($notas) ?>)</a>
  <a href="?ano=<?= $ano ?>&mes=<?= $mes ?>&cli=<?= $cli ?>&tab=ranking"
     class="mtab <?= $tab === 'ranking' ? 'active' : '' ?>">&#127942; Ranking por Cliente</a>
</div>

<!-- TAB: LISTA -->
<?php if ($tab === 'lista'): ?>
<div class="win" style="border-top:none;border-radius:0 0 4px 4px;">
  <div class="win-title">
    &#128204; Notas Fiscais Emitidas — <?= $mes ? nomeMes($mes).'/' : '' ?><?= $ano ?>
    <a href="?ano=<?= $ano ?>&mes=<?= $mes ?>&cli=<?= $cli ?>&tab=lista&action=nova_nf" class="btn btn-primary btn-sm">+ Nova NF</a>
    <?php if (!empty($notas)): ?>
    <a href="exportar.php?ano=<?= $ano ?>&download=1&tbl=notas_fiscais&fmt=csv" class="btn btn-default btn-sm">&#128196; CSV</a>
    <?php endif; ?>
  </div>
  <div style="padding:0;">
    <div class="table-wrap">
      <table class="table-mobile">
        <thead>
          <tr>
            <th>Data</th>
            <th>Nº NF</th>
            <th>Cliente</th>
            <th>Conta</th>
            <th>Método</th>
            <th>Categoria</th>
            <th>Descrição</th>
            <th class="text-right">Valor Bruto</th>
            <th class="text-right">Taxa</th>
            <th class="text-right">Valor Líquido</th>
            <th>Ref.</th>
            <th>Arquivos</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($notas)): ?>
            <tr>
              <td colspan="13" class="text-center text-muted" style="padding:24px;">
                Nenhuma nota fiscal encontrada com os filtros selecionados.
              </td>
            </tr>
          <?php else: foreach ($notas as $n): ?>
          <tr>
            <td data-label="Data"><?= fmtDate($n['data']) ?></td>
            <td data-label="Nº NF">
              <?php if ($n['num_nf']): ?>
                <span class="badge b-nf">NF <?= h($n['num_nf']) ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td data-label="Cliente">
              <?= $n['cliente'] ? '<strong>'.h($n['cliente']).'</strong>' : '<span class="text-muted">Sem cliente</span>' ?>
            </td>
            <td data-label="Conta"><?= h($n['conta'] ?? '—') ?></td>
            <td data-label="Método"><?= h($n['metodo'] ?? '—') ?></td>
            <td data-label="Categoria"><?= h($n['categoria'] ?? '—') ?></td>
            <td data-label="Descrição">
              <?= h($n['descricao'] ?? '') ?>
              <?php if ($n['observacoes']): ?>
                <span class="text-muted text-sm" title="<?= h($n['observacoes']) ?>"> &#128172;</span>
              <?php endif; ?>
            </td>
            <td data-label="Valor Bruto" class="money pos"><?= fmt($n['valor']) ?></td>
            <td data-label="Taxa" class="money neg">
              <?= $n['taxa_valor'] > 0 ? fmt($n['taxa_valor']) : '—' ?>
            </td>
            <td data-label="Valor Líquido" class="money pos">
              <?= fmt($n['valor_liquido'] ?: $n['valor']) ?>
            </td>
            <td data-label="Ref." style="white-space:nowrap;">
              <?= nomeMes($n['mes']) ?>/<?= $n['ano'] ?>
            </td>
            <td data-label="Arquivos">
              <?php $xmlUrl = nfUploadUrl($n['anexo_xml_path'] ?? null); ?>
              <?php $pdfUrl = nfUploadUrl($n['anexo_pdf_path'] ?? null); ?>
              <?php if ($xmlUrl || $pdfUrl): ?>
                <div style="display:flex;flex-direction:column;gap:4px;">
                  <?php if ($xmlUrl): ?><a href="<?= h($xmlUrl) ?>" target="_blank" class="btn btn-default btn-xs">XML</a><?php endif; ?>
                  <?php if ($pdfUrl): ?><a href="<?= h($pdfUrl) ?>" target="_blank" class="btn btn-default btn-xs">PDF</a><?php endif; ?>
                </div>
              <?php else: ?>
                <span class="text-muted">NULL</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="?ano=<?=$ano?>&mes=<?=$mes?>&cli=<?=$cli?>&tab=lista&action=editar_nf&id=<?=$n['id']?>" class="btn btn-default btn-xs">✎</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Excluir esta Nota Fiscal?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="excluir_nf">
                <input type="hidden" name="id" value="<?= $n['id'] ?>">
                <button type="submit" class="btn btn-danger btn-xs">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($notas)): ?>
        <tfoot>
          <tr>
            <td colspan="7"><strong>TOTAIS</strong></td>
            <td class="text-right text-mono" style="color:var(--green);font-weight:bold;"><?= fmt($totBruto) ?></td>
            <td class="text-right text-mono" style="color:var(--red);"><?= $totTaxa > 0 ? fmt($totTaxa) : '—' ?></td>
            <td class="text-right text-mono" style="color:var(--green);font-weight:bold;"><?= fmt($totLiq ?: $totBruto) ?></td>
            <td></td><td></td><td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- TAB: RANKING POR CLIENTE -->
<?php if ($tab === 'ranking'): ?>
<div class="win" style="border-top:none;border-radius:0 0 4px 4px;">
  <div class="win-title">&#127942; Ranking por Cliente — <?= $ano ?></div>
  <div class="win-body">
    <?php if (empty($rankCliente)): ?>
      <p class="text-muted text-center" style="padding:24px;">Nenhuma nota fiscal emitida em <?= $ano ?>.</p>
    <?php else:
      $maxTot = max(array_column($rankCliente, 'total'));
      $totalGeral = array_sum(array_column($rankCliente, 'total'));
    ?>
    <div class="table-wrap" style="margin-bottom:16px;">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Cliente</th>
            <th class="text-right">Qtd. NFs</th>
            <th class="text-right">Total</th>
            <th style="width:200px;">Participação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rankCliente as $i => $r): ?>
          <tr>
            <td style="font-weight:bold;color:var(--blue);"><?= $i + 1 ?></td>
            <td><strong><?= h($r['cliente'] ?? 'Sem cliente') ?></strong></td>
            <td class="text-right"><?= $r['qtd'] ?></td>
            <td class="text-right money pos"><?= fmt($r['total']) ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:6px;">
                <div class="prog-bar" style="flex:1;">
                  <div class="prog-fill prog-grn" style="width:<?= $maxTot > 0 ? round($r['total'] / $maxTot * 100) : 0 ?>%"></div>
                </div>
                <span class="text-sm text-mono"><?= $totalGeral > 0 ? round($r['total'] / $totalGeral * 100) : 0 ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><strong>TOTAL</strong></td>
            <td class="text-right"><strong><?= array_sum(array_column($rankCliente, 'qtd')) ?></strong></td>
            <td class="text-right money pos"><strong><?= fmt($totalGeral) ?></strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- MODAL NOVA / EDITAR NF -->
<?php if (in_array($action, ['nova_nf','editar_nf'])): ?>
<div class="modal-bg open">
  <div class="modal" style="max-width:560px;">
    <div class="modal-head">
      <h3><?= $editNF ? '✎ Editar Nota Fiscal' : '+ Nova Nota Fiscal' ?></h3>
      <button class="modal-close" onclick="window.location='notas_fiscais.php?ano=<?=$ano?>&mes=<?=$mes?>&cli=<?=$cli?>&tab=lista'">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="salvar_nf">
      <div class="modal-body">
        <div class="form-row">
          <div class="fg w-3rd">
            <label>Data *</label>
            <input type="date" name="data" value="<?= $editNF['data'] ?? date('Y-m-d') ?>" required>
          </div>
          <div class="fg w-3rd">
            <label>Número da NF</label>
            <input type="text" name="num_nf" value="<?= h($editNF['num_nf'] ?? '') ?>" placeholder="000001">
          </div>
          <div class="fg w-3rd">
            <label>Valor Bruto (R$) *</label>
            <input type="text" name="valor" class="money-input"
                   value="<?= $editNF ? number_format($editNF['valor'],2,',','.') : '' ?>"
                   placeholder="0,00" required>
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-half">
            <label>Conta *</label>
            <select name="conta_id" required>
              <option value="">— selecione —</option>
              <?php foreach ($contas as $c): ?>
                <option value="<?=$c['id']?>" <?= ($editNF['conta_id'] ?? 0)==$c['id'] ? 'selected' : '' ?>><?= h($c['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg w-half">
            <label>Método</label>
            <select name="metodo_id">
              <option value="">— selecione —</option>
              <?php foreach ($metodos as $m): ?>
                <option value="<?=$m['id']?>" <?= ($editNF['metodo_id'] ?? 0)==$m['id'] ? 'selected' : '' ?>><?= h($m['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-half">
            <label>Taxa (R$)</label>
            <input type="text" name="taxa_valor" class="money-input"
                   value="<?= $editNF ? number_format($editNF['taxa_valor'] ?? 0,2,',','.') : '0,00' ?>">
          </div>
          <div class="fg w-half">
            <label>Cliente</label>
            <select name="cliente_id">
              <option value="">— nenhum —</option>
              <?php foreach ($clientes as $c): ?>
                <option value="<?=$c['id']?>" <?= ($editNF['cliente_id'] ?? 0)==$c['id'] ? 'selected' : '' ?>><?= h($c['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-full">
            <label>Descrição</label>
            <input type="text" name="descricao" value="<?= h($editNF['descricao'] ?? '') ?>" placeholder="Serviço / produto…">
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-half">
            <label>XML da NF (opcional)</label>
            <input type="file" name="anexo_xml" accept=".xml,text/xml,application/xml">
            <?php if (!empty($editNF['anexo_xml_path'])): ?>
              <div class="text-sm text-muted" style="margin-top:4px;">Atual: <a href="<?= h(nfUploadUrl($editNF['anexo_xml_path'])) ?>" target="_blank"><?= h($editNF['anexo_xml_nome'] ?: basename($editNF['anexo_xml_path'])) ?></a></div>
            <?php else: ?>
              <div class="text-sm text-muted" style="margin-top:4px;">Atual: NULL</div>
            <?php endif; ?>
          </div>
          <div class="fg w-half">
            <label>PDF da NF (opcional)</label>
            <input type="file" name="anexo_pdf" accept=".pdf,application/pdf">
            <?php if (!empty($editNF['anexo_pdf_path'])): ?>
              <div class="text-sm text-muted" style="margin-top:4px;">Atual: <a href="<?= h(nfUploadUrl($editNF['anexo_pdf_path'])) ?>" target="_blank"><?= h($editNF['anexo_pdf_nome'] ?: basename($editNF['anexo_pdf_path'])) ?></a></div>
            <?php else: ?>
              <div class="text-sm text-muted" style="margin-top:4px;">Atual: NULL</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="form-row">
          <div class="fg w-half">
            <label>Categoria</label>
            <select name="categoria_id">
              <option value="">— selecione —</option>
              <?php foreach ($catEnt as $c): ?>
                <option value="<?=$c['id']?>" <?= ($editNF['categoria_id'] ?? 0)==$c['id'] ? 'selected' : '' ?>><?= h($c['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg w-half">
            <label>Observações</label>
            <input type="text" name="observacoes" value="<?= h($editNF['observacoes'] ?? '') ?>">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <a href="notas_fiscais.php?ano=<?=$ano?>&mes=<?=$mes?>&cli=<?=$cli?>&tab=lista" class="btn btn-default">Cancelar</a>
        <button type="submit" class="btn btn-primary">✓ Salvar Nota Fiscal</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/src/layout_footer.php'; ?>
