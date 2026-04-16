<?php
/**
 * index.php — Ponto de entrada principal do FinApp v3
 *
 * Detecta o estado do sistema e redireciona:
 * - Não instalado  → wizard de instalação
 * - Instalado      → login
 */

// Define ROOT_PATH antes de qualquer coisa
define('ROOT_PATH', __DIR__);

// Detecta BASE_URL (mesmo algoritmo do bootstrap, mas aqui SCRIPT_NAME aponta para este arquivo)
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
$BASE_URL = rtrim($base, '/');

$installed = file_exists(__DIR__ . '/config/config.php')
          && file_exists(__DIR__ . '/config/.installed');

if ($installed) {
    header('Location: ' . $BASE_URL . '/public/login.php');
} else {
    header('Location: ' . $BASE_URL . '/install/index.php');
}
exit;
