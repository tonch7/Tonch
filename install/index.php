<?php
/**
 * install/index.php — Wizard de instalação FinApp v3
 */

define('ROOT_PATH',    dirname(__DIR__));
define('INSTALL_PATH', __DIR__);

// ---- Sessão antes de qualquer verificação ----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Detecta BASE_URL do instalador ----
$sFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
$sName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']     ?? '');
$rPath = str_replace('\\', '/', ROOT_PATH);
$base  = '';
if ($sFile && $sName && strpos($sFile, $rPath) === 0) {
    $rel = substr($sFile, strlen($rPath));
    if ($rel && substr($sName, -strlen($rel)) === $rel) {
        $base = substr($sName, 0, strlen($sName) - strlen($rel));
    }
}
define('BASE_URL', rtrim($base, '/'));

// ---- Qual step está sendo pedido? ----
// Se não há ?step= na URL = acesso direto = sempre step 1 (começa do zero)
// Se há ?step=N = navegação interna do wizard = respeita o parâmetro
$requestedStep = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// ---- Se já instalado, bloqueia EXCETO step=6 (tela de conclusão) ----
$isInstalled = file_exists(ROOT_PATH . '/config/config.php')
            && file_exists(ROOT_PATH . '/config/.installed');

if ($isInstalled && $requestedStep !== 5) {
    $loginUrl = BASE_URL . '/public/login.php';
    http_response_code(403);
    die('<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
<title>Instalador Bloqueado</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Helvetica Neue,Helvetica,Arial,sans-serif;background:#f0f0f0;
     display:flex;align-items:center;justify-content:center;min-height:100vh;}
.box{background:#fff;border:2px solid #aaa;border-radius:8px;padding:40px 36px;
     text-align:center;max-width:400px;box-shadow:4px 4px 0 #bbb;}
h2{color:#cc0000;font-size:18px;margin-bottom:10px;}
p{color:#555;font-size:13px;line-height:1.6;margin-bottom:10px;}
a{color:#0066cc;font-weight:bold;text-decoration:none;}
a:hover{text-decoration:underline;}
</style></head>
<body><div class="box">
  <h2>Instalador Bloqueado</h2>
  <p>O sistema j&aacute; foi instalado com sucesso.</p>
  <p>O instalador est&aacute; desativado por seguran&ccedil;a.</p>
  <p><a href="' . htmlspecialchars($loginUrl) . '">Acessar o Sistema &rarr;</a></p>
</div></body></html>');
}

// ---- Inicializa / reseta sessão do wizard ----
// Se o usuário chegou sem ?step= na URL, sempre começa do zero.
// Evita que sessão antiga de instalação prévia pule direto para step=6.
if (!isset($_GET['step'])) {
    // Acesso direto ao instalador (sem parâmetro) → reseta tudo
    $_SESSION['install_step'] = 1;
    $_SESSION['install_data'] = [];
}

if (!isset($_SESSION['install_step'])) {
    $_SESSION['install_step'] = 1;
    $_SESSION['install_data'] = [];
}

if (empty($_SESSION['install_csrf_token'])) {
    $_SESSION['install_csrf_token'] = bin2hex(random_bytes(32));
}

$step = max(1, min(5, $requestedStep));

// ---- Processa POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once INSTALL_PATH . '/steps/process.php';
    exit;
}

$stepTitles = [
    1 => 'Boas-vindas',
    2 => 'Requisitos',
    3 => 'Banco de Dados',
    4 => 'Administrador',
    5 => 'Conclu&iacute;do',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalador &mdash; FinApp v3</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f0f0;--border:#aaa;--blue:#0066cc;--green:#007700;--red:#cc0000;
  --font:Tahoma,Arial,sans-serif;--mono:'Courier New',Courier,monospace;
}
body{font-family:var(--font);font-size:13px;background:var(--bg);color:#111;min-height:100vh;
     display:flex;flex-direction:column;align-items:center;padding:30px 16px;
     background-image:repeating-linear-gradient(45deg,transparent,transparent 3px,rgba(0,0,0,.01) 3px,rgba(0,0,0,.01) 6px);}
.wrap{width:100%;max-width:680px;}
.titlebar{background:linear-gradient(180deg,#d0d0d0,#a8a8a8);border:2px solid #666;border-bottom:none;
          border-radius:8px 8px 0 0;padding:8px 14px;display:flex;align-items:center;gap:10px;}
.dots{display:flex;gap:5px;}
.dot{width:12px;height:12px;border-radius:50%;border:1px solid rgba(0,0,0,.25);}
.dot-r{background:#ff5f57}.dot-y{background:#ffbd2e}.dot-g{background:#28c940}
.titlebar-title{flex:1;text-align:center;font-size:13px;font-weight:bold;color:#222;}
.titlebar-ver{font-size:11px;color:#555;}
.box{background:#f5f5f5;border:2px solid #666;border-top:1px solid #999;
     border-radius:0 0 8px 8px;box-shadow:4px 4px 0 #888;overflow:hidden;}
.steps-bar{background:linear-gradient(180deg,#e8e8e8,#d0d0d0);border-bottom:1px solid var(--border);
           padding:12px 16px;display:flex;overflow-x:auto;}
.si{display:flex;align-items:center;gap:6px;font-size:11px;color:#888;white-space:nowrap;flex:1;justify-content:center;}
.sn{width:22px;height:22px;border-radius:50%;border:2px solid #ccc;display:flex;align-items:center;
    justify-content:center;font-size:10px;font-weight:bold;background:#e8e8e8;color:#888;flex-shrink:0;}
.si.done .sn{background:var(--green);border-color:#005500;color:#fff;}
.si.active .sn{background:var(--blue);border-color:#004499;color:#fff;}
.si.active .sl{color:#111;font-weight:bold;}.si.done .sl{color:var(--green);}
.sep{font-size:10px;color:#ccc;padding:0 4px;}
.content{padding:24px 28px;}
.heading{font-size:17px;font-weight:bold;color:#222;margin-bottom:6px;}
.sub{font-size:12px;color:#666;margin-bottom:20px;line-height:1.5;}
.fg{display:flex;flex-direction:column;gap:4px;margin-bottom:14px;}
label{font-size:11px;font-weight:bold;color:#333;}
input[type=text],input[type=email],input[type=password],input[type=number],select,textarea{
  font-family:var(--font);font-size:13px;padding:7px 10px;background:#fff;
  border:1px solid var(--border);border-radius:4px;color:#111;width:100%;
  box-shadow:inset 1px 1px 2px rgba(0,0,0,.07);transition:border-color .15s;}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--blue);
  box-shadow:inset 1px 1px 2px rgba(0,0,0,.07),0 0 0 2px rgba(0,102,204,.18);}
input[readonly]{background:#f0f0f0;color:#888;}
.help{font-size:11px;color:#888;margin-top:2px;}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 20px;border-radius:4px;border:1px solid;
     cursor:pointer;font-family:var(--font);font-size:13px;font-weight:bold;text-decoration:none;transition:filter .1s;}
.btn:active{filter:brightness(.93);}
.bp{background:linear-gradient(180deg,#5599ff,#0066cc);border-color:#004499;color:#fff;}
.bd{background:linear-gradient(180deg,#f8f8f8,#d8d8d8);border-color:var(--border);color:#333;}
.bs{background:linear-gradient(180deg,#44bb44,#007700);border-color:#005500;color:#fff;}
.bsm{padding:5px 14px;font-size:12px;}
.btn-row{display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:16px;border-top:1px solid #ddd;}
.alert{padding:10px 14px;border:1px solid;border-radius:4px;margin-bottom:14px;font-size:13px;}
.ae{background:#fef0f0;border-color:#dc3545;color:#7a0e18;}
.as{background:#e6fae6;border-color:#28a745;color:#145a1e;}
.ai{background:#e8f0ff;border-color:#0d6efd;color:#0a2e6b;}
.aw{background:#fff8e1;border-color:#f0a000;color:#6b4400;}
.req-list{list-style:none;margin-bottom:16px;}
.req-list li{display:flex;align-items:center;gap:10px;padding:7px 10px;border-bottom:1px solid #e8e8e8;font-size:12px;}
.req-list li:last-child{border-bottom:none;}
.rok{color:var(--green);font-weight:bold;font-size:14px;}
.rerr{color:var(--red);font-weight:bold;font-size:14px;}
.rwarn{color:#cc6600;font-weight:bold;font-size:14px;}
.rname{flex:1;}.rval{font-family:var(--mono);font-size:11px;color:#666;}
.fl{list-style:none;margin:12px 0 20px;}
.fl li{padding:5px 0 5px 20px;font-size:13px;color:#444;border-bottom:1px solid #e8e8e8;position:relative;}
.fl li::before{content:'▸';position:absolute;left:0;color:var(--blue);}
.success-box{text-align:center;padding:20px 0;}
.si-icon{font-size:48px;margin-bottom:12px;}
.si-title{font-size:22px;font-weight:bold;color:var(--green);margin-bottom:8px;}
.si-sub{font-size:13px;color:#555;margin-bottom:20px;}
.cred{background:#111;border-radius:6px;padding:16px 20px;text-align:left;font-family:var(--mono);
      font-size:13px;color:#0f0;margin-bottom:20px;}
.cred span{color:#888;}
.footer{background:#d8d8d8;border-top:1px solid var(--border);padding:6px 16px;text-align:center;font-size:10px;color:#777;}
@media(max-width:520px){body{padding:10px 8px;}.content{padding:16px 14px;}.sl{display:none;}.g2{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="wrap">
  <div class="titlebar">
    <div class="dots">
      <span class="dot dot-r"></span><span class="dot dot-y"></span><span class="dot dot-g"></span>
    </div>
    <div class="titlebar-title">Instalador FinApp</div>
    <div class="titlebar-ver">v3.0</div>
  </div>
  <div class="box">
    <div class="steps-bar">
      <?php foreach ($stepTitles as $n => $title): ?>
        <?php $cls = $n < $step ? 'done' : ($n === $step ? 'active' : ''); ?>
        <div class="si <?= $cls ?>">
          <div class="sn"><?= $n < $step ? '&#10003;' : $n ?></div>
          <span class="sl"><?= $title ?></span>
        </div>
        <?php if ($n < count($stepTitles)): ?><div class="sep">&rsaquo;</div><?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="content">
      <?php
        $stepFile = INSTALL_PATH . '/steps/step' . $step . '.php';
        if (file_exists($stepFile)) require $stepFile;
        else echo '<div class="alert ae">Etapa inv&aacute;lida.</div>';
      ?>
    </div>
    <div class="footer">FinApp v3.0 &mdash; Instalador Autom&aacute;tico &mdash; PHP + SQLite</div>
  </div>
</div>
</body>
</html>
