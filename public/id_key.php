<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();

// ══════════════════════════════════════════════════════════
// HELPERS CRIPTO — AES-256-CBC + PBKDF2
// ══════════════════════════════════════════════════════════
function vaultKey(string $tok): string {
    return hash_pbkdf2('sha256', $tok, 'finapp-vault-salt-v2', 100000, 32, true);
}
function vaultEncrypt(string $plain, string $tok): array {
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'AES-256-CBC', vaultKey($tok), OPENSSL_RAW_DATA, $iv);
    return ['ct' => base64_encode($ct), 'iv' => base64_encode($iv)];
}
function vaultDecrypt(string $ct64, string $iv64, string $tok): ?string {
    $r = openssl_decrypt(base64_decode($ct64), 'AES-256-CBC', vaultKey($tok), OPENSSL_RAW_DATA, base64_decode($iv64));
    return $r === false ? null : $r;
}
function gerarToken17(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz123456789';
    $t = '';
    for ($i = 0; $i < 17; $i++) $t .= $chars[random_int(0, strlen($chars)-1)];
    return $t;
}
function tokenConfigurado(): bool {
    return (bool)db()->one('SELECT 1 FROM id_key_token WHERE id=1');
}
function verificarToken(string $tok): bool {
    $r = db()->one('SELECT hash FROM id_key_token WHERE id=1');
    return $r && password_verify($tok, $r['hash']);
}

// ══════════════════════════════════════════════════════════
// ESTADO
// ══════════════════════════════════════════════════════════
$vaultAberto = !empty($_SESSION['id_key_aberto']);
$tokenConfig = tokenConfigurado();
$action      = $_POST['action'] ?? $_GET['action'] ?? '';
$id          = (int)($_GET['id'] ?? 0);
$viewId      = (int)($_GET['view'] ?? 0);

// ══════════════════════════════════════════════════════════
// POST HANDLERS
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfOrAbort(url('public/id_key.php'));

    if ($action === 'gerar_token' && !$tokenConfig) {
        $token = gerarToken17();
        db()->exec('INSERT INTO id_key_token (id,hash) VALUES (1,?)',
            [password_hash($token, PASSWORD_BCRYPT, ['cost'=>12])]);
        $_SESSION['id_key_token_show'] = $token;
        auditLog('VAULT_TOKEN_GERADO', 'id_key', 'Token gerado');
        header('Location: ' . url('public/id_key.php') . '?show_token=1'); exit;
    }

    if ($action === 'desbloquear') {
        $tok = trim($_POST['token'] ?? '');
        if ($tokenConfig && verificarToken($tok)) {
            $_SESSION['id_key_aberto'] = true;
            $_SESSION['id_key_token']  = $tok;
            auditLog('VAULT_UNLOCK', 'id_key', 'Vault desbloqueado');
            header('Location: ' . url('public/id_key.php') . ''); exit;
        }
        auditLog('VAULT_FAIL', 'id_key', 'Token inválido');
        flash('error', '❌ Token inválido. Acesso negado.');
        header('Location: ' . url('public/id_key.php') . ''); exit;
    }

    if ($action === 'bloquear') {
        auditLog('VAULT_LOCK', 'id_key', 'Vault bloqueado');
        unset($_SESSION['id_key_aberto'], $_SESSION['id_key_token']);
        header('Location: ' . url('public/id_key.php') . ''); exit;
    }

    if ($action === 'salvar_item' && $vaultAberto) {
        $titulo   = trim($_POST['titulo'] ?? '');
        $conteudo = trim($_POST['conteudo'] ?? '');
        $tok      = $_SESSION['id_key_token'] ?? '';
        $editId   = (int)($_POST['item_id'] ?? 0);
        if ($titulo && $conteudo && $tok) {
            $enc = vaultEncrypt($conteudo, $tok);
            if ($editId > 0) {
                db()->exec('UPDATE id_key_vault SET titulo=?,conteudo=?,iv=?,atualizado_em=CURRENT_TIMESTAMP WHERE id=?',
                    [$titulo, $enc['ct'], $enc['iv'], $editId]);
                auditLog('VAULT_ITEM_EDIT', 'id_key', 'Item #'.$editId);
                flash('success', '✓ Item atualizado no vault.');
            } else {
                $nid = db()->insert('INSERT INTO id_key_vault (titulo,conteudo,iv) VALUES (?,?,?)',
                    [$titulo, $enc['ct'], $enc['iv']]);
                auditLog('VAULT_ITEM_NEW', 'id_key', 'Item #'.$nid.' criado');
                flash('success', '✓ Item salvo no vault.');
            }
        }
        header('Location: ' . url('public/id_key.php') . ''); exit;
    }

    if ($action === 'excluir_item' && $vaultAberto) {
        $delId = (int)($_POST['item_id'] ?? 0);
        if ($delId > 0) {
            db()->exec('DELETE FROM id_key_vault WHERE id=?', [$delId]);
            auditLog('VAULT_ITEM_DEL', 'id_key', 'Item #'.$delId.' excluído');
            flash('success', '🗑 Item excluído.');
        }
        header('Location: ' . url('public/id_key.php') . ''); exit;
    }

    if ($action === 'importar_arquivo' && $vaultAberto) {
        $tok = $_SESSION['id_key_token'] ?? '';
        if (!empty($_FILES['arquivo']['tmp_name']) && $tok) {
            $nome = $_FILES['arquivo']['name'];
            $ext  = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
            $tit  = trim($_POST['titulo_arquivo'] ?? '') ?: $nome;
            $tipo_arquivo = $ext;
            if (in_array($ext, ['txt','csv','xml','json','html','md'])) {
                $plain = '[TEXT:'.$ext.']' . file_get_contents($_FILES['arquivo']['tmp_name']);
            } else {
                $plain = '['.strtoupper($ext).':BASE64]' . base64_encode(file_get_contents($_FILES['arquivo']['tmp_name']));
            }
            $enc = vaultEncrypt($plain, $tok);
            $nid = db()->insert('INSERT INTO id_key_vault (titulo,conteudo,iv) VALUES (?,?,?)',
                ['📎 '.$tit, $enc['ct'], $enc['iv']]);
            auditLog('VAULT_FILE_IMPORT', 'id_key', '"'.$nome.'" → #'.$nid);
            flash('success', '📎 "'.$nome.'" importado e criptografado.');
        }
        header('Location: ' . url('public/id_key.php') . ''); exit;
    }
}


// ══════════════════════════════════════════════════════════
// DADOS DO VAULT
// ══════════════════════════════════════════════════════════
$itens = $viewItem = null;
if ($vaultAberto) {
    $tok  = $_SESSION['id_key_token'] ?? '';
    $rows = db()->all('SELECT id, titulo, conteudo, iv, criado_em, atualizado_em FROM id_key_vault ORDER BY atualizado_em DESC');
    $itens = [];
    foreach ($rows as $r) {
        $plain = vaultDecrypt($r['conteudo'], $r['iv'], $tok);
        $itens[] = array_merge($r, ['plain' => $plain ?? '[Erro de descriptografia]']);
    }
    if ($viewId > 0) {
        foreach ($itens as $it) {
            if ($it['id'] == $viewId) { $viewItem = $it; break; }
        }
    }
}

$editItem    = ($action === 'editar' && $id > 0 && $vaultAberto)
    ? array_filter($itens ?? [], fn($i) => $i['id']==$id)
    : null;
$editItem    = $editItem ? array_values($editItem)[0] ?? null : null;

$tokenShow   = $_SESSION['id_key_token_show'] ?? null;
if ($tokenShow) unset($_SESSION['id_key_token_show']);
$showToken   = !empty($_GET['show_token']);

$pageTitle  = '🔐';
$activePage = 'id_key';
$mes = mesAbertoAtual()['mes'];
$ano = mesAbertoAtual()['ano'];
require_once ROOT_PATH . '/src/layout_header.php';
?>

<div style="max-width:800px;margin:0 auto;">

<?php if ($showToken && $tokenShow): ?>
<!-- ===== TOKEN — EXIBIÇÃO ÚNICA ===== -->
<div class="win" style="border-color:#dc3545;">
  <div class="win-title" style="background:linear-gradient(180deg,#ffcccc,#e05050);color:#fff;">
    🔑 TOKEN GERADO — COPIE AGORA — EXIBIDO APENAS UMA VEZ
  </div>
  <div class="win-body" style="text-align:center;padding:32px;">
    <div style="font-size:11px;color:#888;letter-spacing:2px;text-transform:uppercase;margin-bottom:12px;">Token de 17 caracteres (Base58)</div>
    <div id="tokenDisplay"
         style="font-family:monospace;font-size:clamp(18px,4vw,28px);font-weight:700;letter-spacing:5px;
                background:#111;color:#00ff88;padding:18px 24px;border-radius:8px;
                display:inline-block;cursor:pointer;user-select:all;word-break:break-all;"
         onclick="navigator.clipboard.writeText(this.textContent.trim()).then(function(){document.getElementById('copiedOk').style.display='block';});"
         title="Clique para copiar"><?= h($tokenShow) ?></div>
    <div id="copiedOk" style="display:none;color:#28a745;font-weight:700;margin-top:10px;">✓ Copiado!</div>
    <div style="margin-top:16px;font-size:12px;color:#c00;line-height:1.7;">
      ⚠️ <strong>Guarde em local seguro — papel, gerenciador de senhas, etc.</strong><br>
      O token não é armazenado no sistema.<br>
      Sem ele, os dados do vault <strong>não podem ser recuperados</strong>.
    </div>
    <a href="<?= url("public/id_key.php") ?>" class="btn btn-primary" style="margin-top:20px;display:inline-block;min-width:200px;">
      ✓ Já anotei — Ir para o Vault
    </a>
  </div>
</div>

<?php elseif (!$tokenConfig): ?>
<!-- ===== PRIMEIRA VEZ ===== -->
<div class="win">
  <div class="win-title" style="background:linear-gradient(180deg,#1a3a5c,#0d2137);color:#fff;">
    🔐 Configurar Token de Acesso
  </div>
  <div class="win-body" style="padding:24px;">
    <div class="alert alert-info mb-12">
      <span class="alert-icon">ℹ</span>
      <span class="alert-body">
        O <strong>ID Key</strong> é um cofre criptografado pessoal (AES-256-CBC).<br>
        O acesso é protegido por um <strong>token de 17 caracteres</strong> gerado pelo sistema.<br>
        O token <strong>nunca é armazenado</strong> — apenas o hash bcrypt permanece.<br>
        <strong>Sem o token os dados são irrecuperáveis.</strong>
      </span>
      <span class="alert-close">&times;</span>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="gerar_token">
      <button type="submit" class="btn btn-primary"
              onclick="return confirm('Gerar token de acesso?\n\nSerá exibido UMA ÚNICA VEZ.\nAnote antes de fechar a tela.')">
        🔑 Gerar Token de Acesso
      </button>
    </form>
  </div>
</div>

<?php elseif (!$vaultAberto): ?>
<!-- ===== VAULT BLOQUEADO ===== -->
<div class="win" style="max-width:400px;margin:0 auto;">
  <div class="win-title" style="background:linear-gradient(180deg,#1a3a5c,#0d2137);color:#fff;justify-content:center;">
    🔐 ID Key — Acesso Seguro
  </div>
  <div class="win-body" style="padding:28px;text-align:center;">
    <div style="font-size:52px;margin-bottom:16px;">🔒</div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="desbloquear">
      <div class="fg mb-12">
        <label style="display:block;text-align:center;margin-bottom:8px;">Token de 17 Caracteres</label>
        <input type="password" name="token" id="tokenInput"
               maxlength="17" required autocomplete="off"
               placeholder="•••••••••••••••••"
               style="font-family:monospace;font-size:18px;letter-spacing:4px;text-align:center;padding:10px;">
        <div style="font-size:11px;color:#888;text-align:center;margin-top:4px;">
          <span id="tokenLen">0</span>/17 caracteres
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;font-size:13px;">
        🔓 Desbloquear Vault
      </button>
    </form>
  </div>
</div>
<script>
document.getElementById('tokenInput').addEventListener('input', function(){
  document.getElementById('tokenLen').textContent = this.value.length;
});
</script>

<?php else: ?>
<!-- ===== VAULT ABERTO ===== -->

<!-- Status bar -->
<div style="background:linear-gradient(180deg,#d4edda,#a8d5b5);border:1px solid #28a745;border-radius:6px;
     padding:8px 14px;display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
  <span style="font-size:16px;">🔓</span>
  <span style="font-size:12px;color:#155724;font-weight:600;">Vault desbloqueado — Dados criptografados com AES-256</span>
  <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
    <a href="?action=novo_item" class="btn btn-primary btn-sm">+ Novo Item</a>
    <button class="btn btn-default btn-sm" onclick="document.getElementById('modalImportar').classList.add('open')">📎 Importar Arquivo</button>
    <form method="post" style="display:inline;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="bloquear">
      <button type="submit" class="btn btn-danger btn-sm">🔒 Bloquear</button>
    </form>
  </div>
</div>

<?php if ($viewItem): ?>
<!-- VISUALIZADOR DE ARQUIVO -->
<?php
  $plain   = $viewItem['plain'];
  $isText  = str_starts_with($plain, '[TEXT:');
  $isB64   = preg_match('/^\[([A-Z]+):BASE64\]/', $plain, $m);
  $fileExt = $isB64 ? strtolower($m[1]) : ($isText ? preg_replace('/\[TEXT:([a-z]+)\].*/', '$1', $plain) : 'txt');
  $content = $isText ? substr($plain, strpos($plain,']')+1) : ($isB64 ? base64_decode(substr($plain, strlen($m[0]))) : $plain);
?>
<div class="win mb-12">
  <div class="win-title">
    📄 Visualizando: <?= h($viewItem['titulo']) ?>
    <a href="<?= url("public/id_key.php") ?>" class="btn btn-default btn-sm">← Voltar</a>
  </div>
  <div class="win-body">
    <?php if (in_array($fileExt, ['jpg','jpeg','png','gif','webp','bmp'])): ?>
      <img src="data:image/<?=$fileExt?>;base64,<?=base64_encode($content)?>"
           style="max-width:100%;border-radius:4px;" alt="<?=h($viewItem['titulo'])?>">
    <?php elseif ($fileExt === 'pdf'): ?>
      <embed src="data:application/pdf;base64,<?=base64_encode($content)?>"
             type="application/pdf" width="100%" style="height:70vh;border:none;">
    <?php else: ?>
      <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:6px;
                  font-size:12px;overflow-x:auto;white-space:pre-wrap;max-height:70vh;overflow-y:auto;
                  font-family:monospace;line-height:1.5;"><?= h(mb_substr(is_string($content)?$content:'[binário não visualizável]', 0, 50000)) ?></pre>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- LISTA DE ITENS -->
<div class="win">
  <div class="win-title">🗃 Itens no Vault (<?= count($itens ?? []) ?>)</div>
  <div style="padding:0;">
    <?php if (empty($itens)): ?>
      <p class="text-muted text-center" style="padding:24px;">Nenhum item. Use "+ Novo Item" ou "📎 Importar Arquivo".</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table-mobile">
        <thead><tr>
          <th>#</th><th>Título</th><th>Prévia</th><th>Criado</th><th>Atualizado</th><th>Ações</th>
        </tr></thead>
        <tbody>
          <?php foreach ($itens as $it):
            $isFile = preg_match('/^\[(TEXT:|[A-Z]+:BASE64\])/', $it['plain']);
            $plain  = $it['plain'];
            // Determina extensão do arquivo
            $fExt = '';
            if (preg_match('/^\[([A-Z]+):BASE64\]/', $plain, $mm)) $fExt = strtolower($mm[1]);
            if (preg_match('/^\[TEXT:([a-z]+)\]/', $plain, $mm)) $fExt = $mm[1];
            $icon = $fExt ? match(true) {
              in_array($fExt,['jpg','jpeg','png','gif','webp']) => '🖼',
              $fExt==='pdf' => '📕',
              in_array($fExt,['docx','doc']) => '📘',
              in_array($fExt,['xlsx','xls']) => '📗',
              in_array($fExt,['xml','json']) => '⚙',
              default => '📎'
            } : '📝';
            $preview = $isFile ? ($icon.' Arquivo criptografado ('.$fExt.')') : mb_substr(preg_replace('/\s+/',' ',$it['plain']),0,60);
          ?>
          <tr>
            <td data-label="#" style="font-size:11px;color:#888;"><?= $it['id'] ?></td>
            <td data-label="Título"><strong><?= h($it['titulo']) ?></strong></td>
            <td data-label="Prévia" style="font-size:11px;color:#666;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= h($preview) ?>
            </td>
            <td data-label="Criado" style="font-size:11px;white-space:nowrap;"><?= date('d/m/y', strtotime($it['criado_em'])) ?></td>
            <td data-label="Atualizado" style="font-size:11px;white-space:nowrap;"><?= date('d/m/y H:i', strtotime($it['atualizado_em'])) ?></td>
            <td data-label="Ações">
              <?php if ($isFile): ?>
                <a href="?view=<?= $it['id'] ?>" class="btn btn-default btn-xs" title="Visualizar arquivo">👁</a>
              <?php else: ?>
                <a href="?action=editar&id=<?= $it['id'] ?>" class="btn btn-default btn-xs">✎</a>
              <?php endif; ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Excluir permanentemente?');"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="excluir_item"><input type="hidden" name="item_id" value="<?= $it['id'] ?>"><button type="submit" class="btn btn-danger btn-xs">✕</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- MODAL NOVO / EDITAR ITEM -->
<?php if (in_array($action, ['novo_item','editar'])): ?>
<div class="modal-bg open">
  <div class="modal" style="max-width:580px;">
    <div class="modal-head">
      <h3><?= $editItem ? '✎ Editar Item' : '+ Novo Item no Vault' ?></h3>
      <button class="modal-close" onclick="window.location='id_key.php'">✕</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="salvar_item">
      <input type="hidden" name="item_id" value="<?= $editItem['id'] ?? 0 ?>">
      <div class="modal-body">
        <div class="fg mb-8">
          <label>Título / Identificador *</label>
          <input type="text" name="titulo" required
                 value="<?= h($editItem['titulo'] ?? '') ?>"
                 placeholder="Ex: Senha VPS, Chave API, Contrato…">
        </div>
        <div class="fg">
          <label>Conteúdo Confidencial *</label>
          <textarea name="conteudo" rows="10" required
                    style="font-family:monospace;font-size:12px;resize:vertical;"
                    placeholder="Cole qualquer informação confidencial…"><?= h($editItem['plain'] ?? '') ?></textarea>
        </div>
        <div style="font-size:11px;color:#888;margin-top:4px;">
          🔐 Criptografado com AES-256 usando seu token. Sem o token, ilegível.
        </div>
      </div>
      <div class="modal-foot">
        <a href="<?= url("public/id_key.php") ?>" class="btn btn-default">Cancelar</a>
        <button type="submit" class="btn btn-primary">🔐 Salvar Criptografado</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- MODAL IMPORTAR ARQUIVO -->
<div class="modal-bg" id="modalImportar">
  <div class="modal" style="max-width:480px;">
    <div class="modal-head">
      <h3>📎 Importar Arquivo para o Vault</h3>
      <button class="modal-close" onclick="document.getElementById('modalImportar').classList.remove('open')">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="importar_arquivo">
      <div class="modal-body">
        <div class="alert alert-info mb-8" style="font-size:11px;">
          <span class="alert-icon">ℹ</span>
          <span class="alert-body">TXT, XML, JSON, CSV, HTML, PDF, DOCX, XLSX, JPG, PNG e outros.<br>
          Armazenado <strong>criptografado</strong> com AES-256. Visualizável com vault desbloqueado.</span>
          <span class="alert-close">&times;</span>
        </div>
        <div class="fg mb-8">
          <label>Arquivo *</label>
          <input type="file" name="arquivo" required
                 accept=".txt,.xml,.json,.csv,.html,.md,.pdf,.docx,.doc,.xlsx,.xls,.pptx,.jpg,.jpeg,.png,.gif,.webp">
        </div>
        <div class="fg">
          <label>Título (opcional)</label>
          <input type="text" name="titulo_arquivo" placeholder="Deixe em branco para usar o nome do arquivo">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-default"
                onclick="document.getElementById('modalImportar').classList.remove('open')">Cancelar</button>
        <button type="submit" class="btn btn-primary">🔐 Importar e Criptografar</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/src/layout_footer.php'; ?>
