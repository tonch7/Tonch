<?php
/**
 * process.php — Processa todos os POSTs do instalador
 */

if (!defined('ROOT_PATH')) die('Acesso direto não permitido.');

$step = (int)($_POST['step'] ?? 1);
$csrf = $_POST['csrf_token'] ?? '';
$csrfSess = $_SESSION['install_csrf_token'] ?? '';
if (!$csrfSess || !hash_equals($csrfSess, $csrf)) {
    $_SESSION['install_data']['admin_errors'] = ['Sessão expirada ou token de instalação inválido. Recarregue a página e tente novamente.'];
    header('Location: ?step=' . max(1, min(4, $step)));
    exit;
}

switch ($step) {
    case 1:
        $_SESSION['install_step'] = 2;
        header('Location: ?step=2');
        exit;

    case 2:
        $_SESSION['install_step'] = 3;
        header('Location: ?step=3');
        exit;

    case 3:
        $dbPath     = ROOT_PATH . '/database/finapp.db';
        $schemaPath = ROOT_PATH . '/database/schema.sql';
        $resetDb    = !empty($_POST['reset_db']);

        try {
            if (!is_dir(ROOT_PATH . '/database')) {
                mkdir(ROOT_PATH . '/database', 0755, true);
            }

            if ($resetDb && file_exists($dbPath)) {
                unlink($dbPath);
            }

            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');

            $schema = file_get_contents($schemaPath);
            $pdo->exec($schema);

            $htaccess = ROOT_PATH . '/database/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "deny from all\n");
            }

            $_SESSION['install_data']['db_ok']     = true;
            $_SESSION['install_data']['db_status'] = 'ok';
            $_SESSION['install_data']['db_path']   = $dbPath;
            $_SESSION['install_step'] = 4;

            header('Location: ?step=4');
            exit;
        } catch (Exception $e) {
            $_SESSION['install_data']['db_status'] = 'error';
            $_SESSION['install_data']['db_error']  = $e->getMessage();
            header('Location: ?step=3');
            exit;
        }

    case 4:
        $appName    = trim($_POST['app_name'] ?? 'FinApp');
        $username   = trim($_POST['username'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $passConf   = $_POST['password_confirm'] ?? '';

        $errors = [];
        if (!$appName)  $errors[] = 'Nome do sistema é obrigatório.';
        if (!$username) $errors[] = 'Usuário é obrigatório.';
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $errors[] = 'Usuário: somente letras, números e underscore (3–30 caracteres).';
        }
        if (strlen($password) < 8) $errors[] = 'Senha deve ter no mínimo 8 caracteres.';
        if ($password !== $passConf) $errors[] = 'As senhas não coincidem.';

        if ($errors) {
            $_SESSION['install_data']['admin_errors'] = $errors;
            $_SESSION['install_data']['admin'] = [
                'app_name' => $appName,
                'username' => $username,
                'admin_email' => $adminEmail,
            ];
            header('Location: ?step=4');
            exit;
        }

        try {
            installSystem($appName, $username, $adminEmail, $password);
            $_SESSION['install_data']['admin'] = [
                'app_name' => $appName,
                'username' => $username,
                'admin_email' => $adminEmail,
            ];
            $_SESSION['install_step'] = 5;
            header('Location: ?step=5');
            exit;
        } catch (Exception $e) {
            $_SESSION['install_data']['admin_errors'] = ['Erro na instalação: ' . $e->getMessage()];
            header('Location: ?step=4');
            exit;
        }

    default:
        header('Location: ?step=1');
        exit;
}

function installSystem(string $appName, string $username, string $adminEmail, string $password): void {
    $dbPath = ROOT_PATH . '/database/finapp.db';

    if (!file_exists($dbPath)) {
        throw new Exception('Banco de dados não encontrado. Volte à Etapa 3.');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->beginTransaction();
    try {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('INSERT OR REPLACE INTO usuario (username, password_hash, email) VALUES (?,?,?)')
            ->execute([$username, $hash, $adminEmail ?: null]);

        insertSeeds($pdo);
        $pdo->prepare('INSERT OR REPLACE INTO config_sistema (chave, valor) VALUES (?,?)')
            ->execute(['app_name', $appName]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    generateConfig($appName);

    $installedFile = ROOT_PATH . '/config/.installed';
    $written = file_put_contents($installedFile, date('Y-m-d H:i:s') . "\n");
    if ($written === false) {
        throw new Exception(
            'Não foi possível gravar o arquivo config/.installed. ' .
            'Verifique se a pasta config/ tem permissão de escrita (chmod 755). ' .
            'Caminho: ' . ROOT_PATH . '/config/'
        );
    }

    if (!file_exists(ROOT_PATH . '/config/config.php')) {
        throw new Exception(
            'Arquivo config/config.php não foi gerado. ' .
            'Verifique as permissões da pasta config/ (chmod 755).'
        );
    }
}

function insertSeeds(PDO $pdo): void {
    $cats = [
        [1,  'Imposto',             'saida'],
        [2,  'Material escritório', 'saida'],
        [3,  'Pró-labore',          'saida'],
        [4,  'Sangria',             'saida'],
        [5,  'Aluguel',             'saida'],
        [6,  'Energia elétrica',    'saida'],
        [7,  'Internet/Telefone',   'saida'],
        [8,  'Salários',            'saida'],
        [9,  'Fornecedores',        'saida'],
        [10, 'Marketing',           'saida'],
        [11, 'Manutenção',          'saida'],
        [12, 'Transporte',          'saida'],
        [13, 'Alimentação',         'saida'],
        [14, 'Cartão de crédito',   'saida'],
        [15, 'Taxa bancária',       'saida'],
        [16, 'Juros cartão',        'saida'],
        [17, 'Despesa pessoal',     'saida'],
        [18, 'Outros',              'saida'],
        [20, 'Serviços prestados',  'entrada'],
        [21, 'Venda de produtos',   'entrada'],
        [22, 'Aluguel recebido',    'entrada'],
        [23, 'Investimento',        'entrada'],
        [24, 'Outros',              'entrada'],
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO categorias (id, nome, tipo) VALUES (?,?,?)');
    foreach ($cats as $c) $stmt->execute($c);

    $metodos = [
        [1, 'PIX',                     0, 'percentual', 0,    0],
        [2, 'Dinheiro',                0, 'percentual', 0,    0],
        [3, 'Boleto',                  1, 'fixo',       3.50, 0],
        [4, 'Cartão de Crédito',       1, 'percentual', 2.99, 0],
        [5, 'Cartão de Débito',        1, 'percentual', 1.50, 0],
        [6, 'Transferência (TED/DOC)', 0, 'percentual', 0,    0],
        [7, 'Transferência Interna',   0, 'percentual', 0,    1],
        [8, 'Cheque',                  0, 'percentual', 0,    0],
        [9, 'Antecipação',             1, 'percentual', 3.50, 0],
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO metodos (id, nome, tem_taxa, taxa_tipo, taxa_valor, interno) VALUES (?,?,?,?,?,?)');
    foreach ($metodos as $m) $stmt->execute($m);
}

function generateConfig(string $appName): void {
    $cfgDir = ROOT_PATH . '/config';
    if (!is_dir($cfgDir)) mkdir($cfgDir, 0755, true);

    $secret = bin2hex(random_bytes(32));
    $appNameEsc = addslashes($appName);
    $now = date('Y-m-d H:i:s');

    $lines = [
        '<?php',
        '/**',
        ' * config.php — Gerado automaticamente em ' . $now,
        ' * Instalador FinApp v3 — NÃO edite manualmente',
        ' */',
        '',
        "define('APP_NAME',     '" . $appNameEsc . "');",
        "define('APP_ENV',      'production');",
        "define('APP_DEBUG',    false);",
        "define('APP_TIMEZONE', 'America/Sao_Paulo');",
        "define('APP_VERSION',  '3.0');",
        '',
        "// Banco de dados",
        "define('DB_PATH', __DIR__ . '/../database/finapp.db');",
        "define('DB_ENCRYPTION_ENABLED', true);",
        '',
        "// Segurança",
        "define('APP_SECRET', '" . $secret . "');",
    ];

    $config = implode("\n", $lines) . "\n";

    if (!is_writable($cfgDir)) {
        throw new Exception(
            'Pasta config/ sem permissão de escrita. ' .
            'No cPanel/Hostinger: selecione a pasta config/ → Permissões → 755.'
        );
    }

    $result = file_put_contents($cfgDir . '/config.php', $config);
    if ($result === false) {
        throw new Exception('Falha ao gravar config/config.php. Verifique permissões da pasta config/.');
    }

    @file_put_contents($cfgDir . '/.htaccess', "deny from all\n");
}
