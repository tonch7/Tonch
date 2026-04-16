<?php
/** Etapa 2 — Verificação de requisitos */
$_SESSION['install_step'] = 2;

$checks = [];
$hasError = false;

// PHP Version
$phpVer = phpversion();
$phpOk  = version_compare($phpVer, '7.4.0', '>=');
$checks[] = [
    'name'  => 'PHP Version',
    'val'   => $phpVer,
    'ok'    => $phpOk,
    'fatal' => true,
    'note'  => $phpOk ? '' : 'Requer PHP 7.4 ou superior.',
];
if (!$phpOk) $hasError = true;

// PDO
$pdoOk = extension_loaded('PDO');
$checks[] = ['name' => 'Extensão PDO', 'val' => $pdoOk ? 'Instalada' : 'Ausente', 'ok' => $pdoOk, 'fatal' => true,
             'note' => $pdoOk ? '' : 'Extensão PDO é obrigatória.'];
if (!$pdoOk) $hasError = true;

// PDO SQLite
$pdoSqlite = in_array('sqlite', PDO::getAvailableDrivers());
$checks[] = ['name' => 'PDO SQLite', 'val' => $pdoSqlite ? 'Disponível' : 'Ausente', 'ok' => $pdoSqlite, 'fatal' => true,
             'note' => $pdoSqlite ? '' : 'Ative pdo_sqlite no php.ini ou painel de controle.'];
if (!$pdoSqlite) $hasError = true;

// Pasta database writable
$dbDir  = ROOT_PATH . '/database';
$dbDirOk = is_writable($dbDir) || (!file_exists($dbDir) && is_writable(dirname($dbDir)));
if (!is_dir($dbDir)) @mkdir($dbDir, 0755, true);
$dbDirOk = is_writable($dbDir);
$checks[] = ['name' => 'Pasta /database', 'val' => $dbDirOk ? 'Gravável' : 'Sem permissão', 'ok' => $dbDirOk, 'fatal' => true,
             'note' => $dbDirOk ? '' : 'Defina chmod 755 na pasta database/.'];
if (!$dbDirOk) $hasError = true;

// Pasta config writable
$cfgDir  = ROOT_PATH . '/config';
if (!is_dir($cfgDir)) @mkdir($cfgDir, 0755, true);
$cfgDirOk = is_writable($cfgDir);
$checks[] = ['name' => 'Pasta /config', 'val' => $cfgDirOk ? 'Gravável' : 'Sem permissão', 'ok' => $cfgDirOk, 'fatal' => true,
             'note' => $cfgDirOk ? '' : 'Defina chmod 755 na pasta config/.'];
if (!$cfgDirOk) $hasError = true;

// Storage writable
$storDir  = ROOT_PATH . '/storage';
if (!is_dir($storDir)) @mkdir($storDir . '/logs', 0755, true);
$storOk = is_writable($storDir);
$checks[] = ['name' => 'Pasta /storage', 'val' => $storOk ? 'Gravável' : 'Sem permissão', 'ok' => $storOk, 'fatal' => false,
             'note' => $storOk ? '' : 'Recomendado chmod 755 na pasta storage/.'];

// session_save_path
$sessOk = is_writable(session_save_path() ?: sys_get_temp_dir());
$checks[] = ['name' => 'Sessões PHP', 'val' => $sessOk ? 'OK' : 'Verificar', 'ok' => $sessOk, 'fatal' => false,
             'note' => $sessOk ? '' : 'Verifique session.save_path no php.ini.'];

// OpenSSL (para CSRF tokens)
$sslOk = extension_loaded('openssl');
$checks[] = ['name' => 'OpenSSL', 'val' => $sslOk ? 'Disponível' : 'Ausente', 'ok' => $sslOk, 'fatal' => false,
             'note' => $sslOk ? '' : 'Recomendado para segurança máxima.'];

// mbstring
$mbOk = extension_loaded('mbstring');
$checks[] = ['name' => 'mbstring', 'val' => $mbOk ? 'Disponível' : 'Ausente', 'ok' => $mbOk, 'fatal' => false,
             'note' => ''];

// max_execution_time
$execTime = ini_get('max_execution_time');
$checks[] = ['name' => 'max_execution_time', 'val' => $execTime . 's', 'ok' => true, 'fatal' => false, 'note' => ''];

// upload_max_filesize
$checks[] = ['name' => 'upload_max_filesize', 'val' => ini_get('upload_max_filesize'), 'ok' => true, 'fatal' => false, 'note' => ''];

// Teste real de escrita na pasta config/
$writeTest = false;
$writeNote = '';
$testFile  = ROOT_PATH . '/config/.write_test';
try {
    if (!is_dir(ROOT_PATH . '/config')) @mkdir(ROOT_PATH . '/config', 0755, true);
    $r = @file_put_contents($testFile, 'ok');
    if ($r !== false) {
        $writeTest = true;
        @unlink($testFile);
    } else {
        $writeNote = 'file_put_contents falhou. Execute: chmod 755 config/';
    }
} catch (Exception $e) {
    $writeNote = $e->getMessage();
}
$checks[] = ['name' => 'Escrita em config/ (teste real)', 'val' => $writeTest ? 'OK' : 'FALHOU', 'ok' => $writeTest, 'fatal' => true,
             'note' => $writeNote];
if (!$writeTest) $hasError = true;

// Teste real de escrita na pasta database/
$writeTestDb = false;
$testFileDb  = ROOT_PATH . '/database/.write_test';
try {
    if (!is_dir(ROOT_PATH . '/database')) @mkdir(ROOT_PATH . '/database', 0755, true);
    $r = @file_put_contents($testFileDb, 'ok');
    if ($r !== false) { $writeTestDb = true; @unlink($testFileDb); }
} catch (Exception $e) {}
$checks[] = ['name' => 'Escrita em database/ (teste real)', 'val' => $writeTestDb ? 'OK' : 'FALHOU', 'ok' => $writeTestDb, 'fatal' => true,
             'note' => $writeTestDb ? '' : 'Execute: chmod 755 database/'];
if (!$writeTestDb) $hasError = true;
?>

<h2 class="heading">Verificação de Requisitos</h2>
<p class="sub">Checando o ambiente do servidor antes de prosseguir.</p>

<?php if ($hasError): ?>
<div class="alert ae">
  Alguns requisitos <strong>obrigatórios</strong> não foram atendidos. Corrija-os antes de continuar.
</div>
<?php else: ?>
<div class="alert as">
  Todos os requisitos obrigatórios foram atendidos. Pode prosseguir com a instalação.
</div>
<?php endif; ?>

<ul class="req-list" style="background:#fff;border:1px solid #ddd;border-radius:4px;">
  <?php foreach ($checks as $c): ?>
  <li>
    <span class="<?= $c['ok'] ? 'req-ok' : ($c['fatal'] ? 'req-err' : 'req-warn') ?>"><?= $c['ok'] ? '✓' : ($c['fatal'] ? '✗' : '!') ?></span>
    <span class="rname"><?= $c['name'] ?><?= $c['note'] ? ' <span style="color:#cc0000;font-size:10px;">— ' . $c['note'] . '</span>' : '' ?></span>
    <span class="rval"><?= $c['val'] ?></span>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($hasError): ?>
<form method="post" action="?step=2">
  <input type="hidden" name="step" value="2">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf_token'] ?? '') ?>">
  <div class="btn-row">
    <a href="?step=1" class="btn bd">‹ Voltar</a>
    <button type="submit" class="btn bd">Verificar Novamente</button>
  </div>
</form>
<?php else: ?>
<form method="post" action="?step=2">
  <input type="hidden" name="step" value="2">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf_token'] ?? '') ?>">
  <div class="btn-row">
    <a href="?step=1" class="btn bd">‹ Voltar</a>
    <button type="submit" class="btn bp">Configurar Banco ›</button>
  </div>
</form>
<?php endif; ?>
