<?php
/**
 * Database — Conexão PDO SQLite singleton
 * Com selagem criptografada opcional do arquivo principal para uso privado/local.
 */
class Database {
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private string $dbPath;
    private string $dbDir;
    private bool $encryptionEnabled = false;
    private string $encPath = '';
    private string $lockPath = '';
    private $lockHandle = null;

    private function __construct() {
        $this->dbPath = defined('DB_PATH') ? DB_PATH : dirname(__DIR__) . '/database/finapp.db';
        $this->dbDir  = dirname($this->dbPath);

        if (!is_dir($this->dbDir)) {
            mkdir($this->dbDir, 0700, true);
        }
        @chmod($this->dbDir, 0700);

        $this->encryptionEnabled = defined('DB_ENCRYPTION_ENABLED')
            && DB_ENCRYPTION_ENABLED
            && defined('APP_SECRET')
            && APP_SECRET !== ''
            && function_exists('openssl_encrypt');

        if ($this->encryptionEnabled) {
            $this->encPath  = $this->dbPath . '.enc';
            $this->lockPath = $this->dbPath . '.lock';
            $this->acquireLock();

            if (file_exists($this->encPath) && !file_exists($this->dbPath)) {
                $this->decryptDatabaseFile();
            }

            register_shutdown_function([$this, 'sealAndRelease']);
        }

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec($this->encryptionEnabled ? 'PRAGMA journal_mode = DELETE' : 'PRAGMA journal_mode = WAL');
    }

    public static function get(): Database {
        if (!self::$instance) self::$instance = new Database();
        return self::$instance;
    }

    public static function reset(): void {
        if (self::$instance) {
            self::$instance->sealAndRelease();
        }
        self::$instance = null;
    }

    public function pdo(): PDO { return $this->pdo; }

    public function run(string $sql, array $p = []): PDOStatement {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st;
    }

    public function all(string $sql, array $p = []): array {
        return $this->run($sql, $p)->fetchAll();
    }

    public function one(string $sql, array $p = []) {
        return $this->run($sql, $p)->fetch() ?: null;
    }

    public function insert(string $sql, array $p = []): int {
        $this->run($sql, $p);
        return (int)$this->pdo->lastInsertId();
    }

    public function exec(string $sql, array $p = []): int {
        return $this->run($sql, $p)->rowCount();
    }

    public function beginTransaction(): void { $this->pdo->beginTransaction(); }
    public function commit(): void           { $this->pdo->commit(); }
    public function rollback(): void         { $this->pdo->rollBack(); }

    public function execFile(string $path): void {
        $sql = file_get_contents($path);
        $this->pdo->exec($sql);
    }

    public function tableExists(string $table): bool {
        $r = $this->one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]);
        return (bool)$r;
    }

    private function acquireLock(): void {
        $handle = fopen($this->lockPath, 'c+');
        if (!$handle) {
            throw new RuntimeException('Falha ao abrir lock do banco criptografado.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Falha ao obter lock exclusivo do banco criptografado.');
        }
        $this->lockHandle = $handle;
        @chmod($this->lockPath, 0600);
    }

    private function getBinaryKey(): string {
        return hash('sha256', APP_SECRET, true);
    }

    private function decryptDatabaseFile(): void {
        $payload = @file_get_contents($this->encPath);
        if ($payload === false || strlen($payload) < 58) {
            throw new RuntimeException('Falha ao ler banco criptografado.');
        }

        if (substr($payload, 0, 10) !== 'FINAPPENC1') {
            throw new RuntimeException('Formato de criptografia inválido.');
        }

        $iv   = substr($payload, 10, 16);
        $hmac = substr($payload, 26, 32);
        $ct   = substr($payload, 58);
        $calc = hash_hmac('sha256', $iv . $ct, $this->getBinaryKey(), true);

        if (!hash_equals($hmac, $calc)) {
            throw new RuntimeException('Integridade do banco criptografado inválida.');
        }

        $plain = openssl_decrypt($ct, 'AES-256-CBC', $this->getBinaryKey(), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('Falha ao descriptografar banco principal.');
        }

        if (@file_put_contents($this->dbPath, $plain, LOCK_EX) === false) {
            throw new RuntimeException('Falha ao restaurar banco descriptografado.');
        }
        @chmod($this->dbPath, 0600);
    }

    private function encryptDatabaseFile(): void {
        if (!file_exists($this->dbPath)) {
            return;
        }

        $plain = @file_get_contents($this->dbPath);
        if ($plain === false) {
            throw new RuntimeException('Falha ao ler banco principal para criptografia.');
        }

        $iv = random_bytes(16);
        $ct = openssl_encrypt($plain, 'AES-256-CBC', $this->getBinaryKey(), OPENSSL_RAW_DATA, $iv);
        if ($ct === false) {
            throw new RuntimeException('Falha ao criptografar banco principal.');
        }

        $hmac = hash_hmac('sha256', $iv . $ct, $this->getBinaryKey(), true);
        $blob = 'FINAPPENC1' . $iv . $hmac . $ct;

        $tmp = $this->encPath . '.tmp';
        if (@file_put_contents($tmp, $blob, LOCK_EX) === false) {
            throw new RuntimeException('Falha ao gravar banco criptografado.');
        }
        @chmod($tmp, 0600);
        rename($tmp, $this->encPath);
        @chmod($this->encPath, 0600);
    }

    public function sealAndRelease(): void {
        if (!$this->encryptionEnabled) {
            return;
        }

        try {
            if ($this->pdo instanceof PDO) {
                try { $this->pdo->exec('PRAGMA optimize'); } catch (Throwable $e) {}
            }
            $this->pdo = null;
            $this->encryptDatabaseFile();
            @unlink($this->dbPath . '-wal');
            @unlink($this->dbPath . '-shm');
            @unlink($this->dbPath);
        } catch (Throwable $e) {
            // não quebra o fluxo de resposta
        } finally {
            if (is_resource($this->lockHandle)) {
                flock($this->lockHandle, LOCK_UN);
                fclose($this->lockHandle);
                $this->lockHandle = null;
            }
        }
    }
}
