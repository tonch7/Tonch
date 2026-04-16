<?php
/**
 * bootstrap.php — Núcleo do sistema FinApp v3
 * Carregado por todas as páginas públicas.
 */

// ================================================================
// 1. ROOT_PATH — caminho físico absoluto da raiz da aplicação
//    Exemplo: /home/user/public_html/finapp
// ================================================================
if (!defined('ROOT_PATH')) {
    // bootstrap.php está em /src/, então pai é a raiz
    define('ROOT_PATH', dirname(__DIR__));
}

// ================================================================
// 2. BASE_URL — caminho web relativo ao domínio (sem trailing slash)
//    Exemplos: ''  /  '/finapp'  /  '/app/financeiro'
//    Método: compara SCRIPT_FILENAME com SCRIPT_NAME
//    Não depende de DOCUMENT_ROOT (instável em symlinks/cPanel).
// ================================================================
if (!defined('BASE_URL')) {
    $sFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $sName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']     ?? '');
    $rPath = str_replace('\\', '/', ROOT_PATH);
    $base  = '';

    if ($sFile && $sName && strpos($sFile, $rPath) === 0) {
        $rel = substr($sFile, strlen($rPath)); // ex: /public/login.php
        if ($rel && substr($sName, -strlen($rel)) === $rel) {
            $base = substr($sName, 0, strlen($sName) - strlen($rel));
        }
    }

    define('BASE_URL', rtrim($base, '/'));
}

// ================================================================
// 3. VERIFICAÇÃO DE INSTALAÇÃO
//    Redireciona para o wizard APENAS se o sistema não estiver
//    instalado E se não estivermos já dentro do /install/
// ================================================================
$_isInstalled = file_exists(ROOT_PATH . '/config/config.php')
             && file_exists(ROOT_PATH . '/config/.installed');

if (!$_isInstalled) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // Já está no instalador — não redireciona, só retorna
    if (strpos($uri, '/install') !== false) {
        return;
    }
    // Está tentando acessar uma página pública sem instalar
    header('Location: ' . BASE_URL . '/install/index.php');
    exit;
}

// ================================================================
// 4. CARREGA CONFIG
// ================================================================
require_once ROOT_PATH . '/config/config.php';

// ================================================================
// 5. TIMEZONE
// ================================================================
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Sao_Paulo');

// ================================================================
// 6. SESSÃO SEGURA
// ================================================================
if (session_status() === PHP_SESSION_NONE) {
    $cookiePath = (BASE_URL === '') ? '/' : BASE_URL . '/';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ================================================================
// 7. CSRF TOKEN
// ================================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ================================================================
// 8. AUTOLOAD + DEPENDÊNCIAS
// ================================================================
spl_autoload_register(function ($class) {
    $file = ROOT_PATH . '/src/' . $class . '.php';
    if (file_exists($file)) require_once $file;
});

require_once ROOT_PATH . '/src/Database.php';
require_once ROOT_PATH . '/src/helpers.php';

// ================================================================
// 9. HELPERS GLOBAIS
// ================================================================

function db(): Database { return Database::get(); }

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmt(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function fmtDate(string $d): string {
    if (!$d) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d/m/Y') : $d;
}

function nomeMes(int $m): string {
    return ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho',
            'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'][$m] ?? '';
}

function abrevMes(int $m): string {
    return ['','Jan','Fev','Mar','Abr','Mai','Jun',
            'Jul','Ago','Set','Out','Nov','Dez'][$m] ?? '';
}

function parseMoney(string $v): float {
    $v = preg_replace('/[R$\s]/', '', $v);
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return (float)$v;
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = compact('type', 'msg');
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function csrfToken(): string {
    return $_SESSION['csrf_token'] ?? '';
}

function csrfVerify(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sess  = $_SESSION['csrf_token'] ?? '';
    return $sess && hash_equals($sess, $token);
}

function requireCsrfOrAbort(?string $redirect = null, string $message = 'Token de segurança inválido. Recarregue a página e tente novamente.'): void {
    if (csrfVerify()) return;
    if ($redirect) {
        flash('error', $message);
        header('Location: ' . $redirect);
        exit;
    }
    http_response_code(419);
    exit($message);
}

function validEmailOrNull(?string $email): ?string {
    $email = trim((string)$email);
    if ($email === '') return null;
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/** Gera URL absoluta relativa ao BASE_URL */
function baseUrl(): string {
    return BASE_URL;
}

function pathJoin(string ...$parts): string {
    $clean = [];
    foreach ($parts as $i => $part) {
        if ($part === '') continue;
        $part = str_replace('\\', '/', $part);
        $part = $i === 0 ? rtrim($part, '/') : trim($part, '/');
        if ($part !== '') $clean[] = $part;
    }
    return implode('/', $clean);
}

/** Gera URL absoluta relativa ao BASE_URL */
function url(string $path = ''): string {
    if ($path === '') return BASE_URL;
    $path = ltrim($path, '/');
    return BASE_URL === '' ? '/' . $path : BASE_URL . '/' . $path;
}

/** Gera URL de asset */
function asset(string $path): string {
    return url(pathJoin('assets', $path));
}


// ================================================================
// 11. GUARDA DE LOGIN / BRUTE FORCE
// ================================================================

function loginGuardPath(): string {
    $dir = ROOT_PATH . '/storage/cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @chmod($dir, 0700);
    return $dir . '/login_guard.json';
}

function loginGuardRead(): array {
    $path = loginGuardPath();
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function loginGuardWrite(array $data): void {
    $path = loginGuardPath();
    $fp = fopen($path, 'c+');
    if (!$fp) return;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    @chmod($path, 0600);
}

function loginGuardKey(string $user): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    return hash('sha256', strtolower(trim($user)) . '|' . $ip);
}

function loginGuardStatus(string $user): array {
    $now = time();
    $window = 15 * 60;
    $maxAttempts = 5;

    $all = loginGuardRead();
    $key = loginGuardKey($user);
    $entry = $all[$key] ?? ['fails' => [], 'blocked_until' => 0];
    $fails = array_values(array_filter($entry['fails'] ?? [], fn($ts) => ($now - (int)$ts) <= $window));
    $blockedUntil = (int)($entry['blocked_until'] ?? 0);

    if ($blockedUntil > $now) {
        return ['blocked' => true, 'seconds_left' => $blockedUntil - $now, 'attempts' => count($fails)];
    }

    if (count($fails) >= $maxAttempts) {
        $blockedUntil = $now + $window;
        $all[$key] = ['fails' => $fails, 'blocked_until' => $blockedUntil];
        loginGuardWrite($all);
        return ['blocked' => true, 'seconds_left' => $blockedUntil - $now, 'attempts' => count($fails)];
    }

    return ['blocked' => false, 'seconds_left' => 0, 'attempts' => count($fails)];
}

function registerLoginFailure(string $user): void {
    $now = time();
    $window = 15 * 60;
    $maxAttempts = 5;

    $all = loginGuardRead();
    $key = loginGuardKey($user);
    $entry = $all[$key] ?? ['fails' => [], 'blocked_until' => 0];
    $fails = array_values(array_filter($entry['fails'] ?? [], fn($ts) => ($now - (int)$ts) <= $window));
    $fails[] = $now;
    $blockedUntil = count($fails) >= $maxAttempts ? $now + $window : 0;
    $all[$key] = ['fails' => $fails, 'blocked_until' => $blockedUntil];
    loginGuardWrite($all);
}

function clearLoginFailures(string $user): void {
    $all = loginGuardRead();
    $key = loginGuardKey($user);
    unset($all[$key]);
    loginGuardWrite($all);
}

// ================================================================
// 10. LÓGICA DE MESES / CAIXAS
// ================================================================

function mesFechado(int $mes, int $ano): bool {
    $r = db()->one('SELECT 1 FROM meses_fechados WHERE mes=? AND ano=? AND reaberto=0', [$mes, $ano]);
    return (bool)$r;
}

function mesIniciado(int $mes, int $ano): bool {
    if (antesDoMarcoZero($mes, $ano)) return false;
    return (bool)db()->one('SELECT 1 FROM caixas_abertos WHERE mes=? AND ano=?', [$mes, $ano]);
}

function mesAbertoAtual(): array {
    $r = db()->one(
        'SELECT ca.mes, ca.ano
         FROM caixas_abertos ca
         WHERE NOT EXISTS (
             SELECT 1 FROM meses_fechados mf
             WHERE mf.mes = ca.mes AND mf.ano = ca.ano AND mf.reaberto = 0
         )
         ORDER BY ca.ano DESC, ca.mes DESC LIMIT 1'
    );
    if ($r) return ['mes' => (int)$r['mes'], 'ano' => (int)$r['ano']];
    return proximoMesAbrir();
}

function proximoMesAbrir(): array {
    $r = db()->one('SELECT mes, ano FROM caixas_abertos ORDER BY ano DESC, mes DESC LIMIT 1');
    if (!$r) {
        $ab = dataAberturaSistema();
        return $ab
            ? ['mes' => (int)$ab['mes'], 'ano' => (int)$ab['ano']]
            : ['mes' => (int)date('n'),  'ano' => (int)date('Y')];
    }
    $mes = (int)$r['mes'];
    $ano = (int)$r['ano'];
    return $mes == 12 ? ['mes' => 1, 'ano' => $ano + 1] : ['mes' => $mes + 1, 'ano' => $ano];
}

function caixaAberto(int $mes, int $ano): bool {
    if (!mesIniciado($mes, $ano)) return false;
    if (mesFechado($mes, $ano))   return false;
    return true;
}

function ensureMesAcessivel(int $mes, int $ano, string $page): void {
    if (mesIniciado($mes, $ano)) return;
    $prox = proximoMesAbrir();
    if ($prox['mes'] === $mes && $prox['ano'] === $ano) return;
    if (antesDoMarcoZero($mes, $ano)) {
        $ab = dataAberturaSistema();
        $mM = $ab ? (int)$ab['mes'] : (int)date('n');
        $aM = $ab ? (int)$ab['ano'] : (int)date('Y');
        flash('error', '⛔ ' . nomeMes($mes) . '/' . $ano . ' está antes do Marco Zero. Redirecionado para o início.');
        header('Location: ' . url('public/' . $page . '.php') . '?mes=' . $mM . '&ano=' . $aM);
        exit;
    }
    $aberto = mesAbertoAtual();
    flash('error', '⏳ ' . nomeMes($mes) . '/' . $ano . ' ainda não foi aberto.');
    header('Location: ' . url('public/' . $page . '.php') . '?mes=' . $aberto['mes'] . '&ano=' . $aberto['ano']);
    exit;
}

function saldoConta(int $contaId, int $mes, int $ano): float {
    $si    = db()->one('SELECT valor FROM saldos_iniciais WHERE conta_id=? AND mes=? AND ano=?', [$contaId, $mes, $ano]);
    $saldo = $si ? (float)$si['valor'] : 0;
    $ent   = db()->one('SELECT SUM(COALESCE(valor_liquido, valor)) t FROM lancamentos WHERE conta_id=? AND mes=? AND ano=? AND tipo="entrada"', [$contaId, $mes, $ano]);
    $saldo += (float)($ent['t'] ?? 0);
    $trRec = db()->one('SELECT SUM(valor) t FROM lancamentos WHERE conta_destino_id=? AND mes=? AND ano=? AND tipo="transferencia"', [$contaId, $mes, $ano]);
    $saldo += (float)($trRec['t'] ?? 0);
    $sai   = db()->one('SELECT SUM(valor) t FROM lancamentos WHERE conta_id=? AND mes=? AND ano=? AND tipo IN ("saida","transferencia")', [$contaId, $mes, $ano]);
    $saldo -= (float)($sai['t'] ?? 0);
    return $saldo;
}

function saldoConsolidado(int $mes, int $ano): float {
    $contas = db()->all('SELECT id FROM contas WHERE ativa=1');
    $total  = 0;
    foreach ($contas as $c) $total += saldoConta($c['id'], $mes, $ano);
    return $total;
}

// ================================================================
// 11. CONFIG DO SISTEMA (banco)
// ================================================================

function configGet(string $chave): ?string {
    $r = db()->one('SELECT valor FROM config_sistema WHERE chave=?', [$chave]);
    return $r ? $r['valor'] : null;
}

function configSet(string $chave, string $valor, bool $imutavel = false): bool {
    if ($imutavel && configGet($chave) !== null) return false;
    db()->exec(
        'INSERT INTO config_sistema (chave, valor) VALUES (?,?)
         ON CONFLICT(chave) DO UPDATE SET valor=excluded.valor',
        [$chave, $valor]
    );
    return true;
}

function sistemaLiberado(): bool {
    return configGet('data_abertura') !== null;
}

function dataAberturaSistema(): ?array {
    $d = configGet('data_abertura');
    if (!$d) return null;
    [$ano, $mes] = explode('-', substr($d, 0, 7));
    return ['mes' => (int)$mes, 'ano' => (int)$ano, 'data' => $d];
}

function antesDoMarcoZero(int $mes, int $ano): bool {
    $ab = dataAberturaSistema();
    if (!$ab) return false;
    if ($ano < $ab['ano']) return true;
    if ($ano == $ab['ano'] && $mes < $ab['mes']) return true;
    return false;
}

function requireSistemaLiberado(): void {
    if (!sistemaLiberado()) {
        flash('error', 'Configure o Marco Zero antes de usar o sistema.');
        header('Location: ' . url('public/cadastros.php') . '?tab=config');
        exit;
    }
}

function podEscrever(int $mes, int $ano): bool {
    return caixaAberto($mes, $ano);
}

function requireCaixaAberto(int $mes, int $ano, string $redirectPage = ''): void {
    if (caixaAberto($mes, $ano)) return;
    $aberto = mesAbertoAtual();
    if (mesFechado($mes, $ano)) {
        $msg = '🔒 CAIXA FECHADO — ' . nomeMes($mes) . '/' . $ano . ' é imutável.';
    } elseif (!mesIniciado($mes, $ano)) {
        $msg = '⏳ ' . nomeMes($mes) . '/' . $ano . ' ainda não foi aberto. Mês em aberto: ' . nomeMes($aberto['mes']) . '/' . $aberto['ano'];
    } else {
        $msg = '🔒 Operação bloqueada para ' . nomeMes($mes) . '/' . $ano . '.';
    }
    flash('error', $msg);
    $page = $redirectPage ?: 'lancamentos';
    header('Location: ' . url('public/' . $page . '.php') . '?mes=' . $aberto['mes'] . '&ano=' . $aberto['ano']);
    exit;
}

function temCaixaAberto(): bool {
    $r = db()->one(
        'SELECT COUNT(*) n FROM caixas_abertos ca
         WHERE NOT EXISTS (
             SELECT 1 FROM meses_fechados mf
             WHERE mf.mes=ca.mes AND mf.ano=ca.ano AND mf.reaberto=0
         )'
    );
    return $r && (int)$r['n'] > 0;
}

// ================================================================
// 12. AUDITORIA
// ================================================================

function auditLog(string $acao, string $modulo = '', string $detalhe = ''): void {
    try {
        $usuario = $_SESSION['username'] ?? 'sistema';
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        db()->exec(
            'INSERT INTO audit_log (usuario, acao, modulo, detalhe, ip) VALUES (?,?,?,?,?)',
            [$usuario, $acao, $modulo, $detalhe, $ip]
        );
    } catch (Exception $e) { /* nunca quebra a app */ }
}
