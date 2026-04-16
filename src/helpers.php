<?php
/**
 * helpers.php — funções auxiliares e classe Auth
 */

class Auth {
    public static function login(string $user, string $pass): bool {
        $user = trim($user);
        $guard = loginGuardStatus($user);

        if (!empty($guard['blocked'])) {
            auditLog('LOGIN_BLOCKED', 'auth', 'Bloqueado para ' . $user . ' por ' . ($guard['seconds_left'] ?? 0) . 's');
            return false;
        }

        $row = db()->one('SELECT * FROM usuario WHERE username=?', [$user]);
        if (!$row) {
            registerLoginFailure($user);
            return false;
        }

        $hash = $row['password_hash'];
        $ok   = false;

        if (in_array(substr($hash, 0, 4), ['$2y$', '$2a$'])) {
            $ok = password_verify($pass, $hash);
        } elseif ($hash === $pass) {
            $ok = true;
            db()->exec('UPDATE usuario SET password_hash=? WHERE id=?',
                [password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $row['id']]);
        }

        if ($ok) {
            clearLoginFailures($user);
            session_regenerate_id(true);
            $_SESSION['uid']       = $row['id'];
            $_SESSION['username']  = $row['username'];
            $_SESSION['logged_in'] = true;
            $_SESSION['login_at']  = date('Y-m-d H:i:s');
        } else {
            registerLoginFailure($user);
        }
        return $ok;
    }

    public static function logout(): void {
        session_unset();
        session_destroy();
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/public/login.php'); exit;
    }

    public static function require(): void {
        if (empty($_SESSION['logged_in'])) {
            header('Location: ' . url('public/login.php')); exit;
        }
    }

    public static function changePass(string $new): void {
        db()->exec('UPDATE usuario SET password_hash=? WHERE id=?',
            [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $_SESSION['uid']]);
    }
}

/**
 * Cria ou busca a fatura de cartão para o mês/cartão
 */
function getOuCriarFatura(int $cartaoId, int $mes, int $ano): array {
    $f = db()->one(
        'SELECT * FROM faturas_cartao WHERE cartao_id=? AND mes_ref=? AND ano_ref=?',
        [$cartaoId, $mes, $ano]
    );
    if (!$f) {
        $cartao = db()->one('SELECT * FROM cartoes WHERE id=?', [$cartaoId]);
        // Data de vencimento padrão: dia_vencimento do mês seguinte
        $mesVenc = $mes == 12 ? 1  : $mes + 1;
        $anoVenc = $mes == 12 ? $ano + 1 : $ano;
        $dataVenc = sprintf('%04d-%02d-%02d', $anoVenc, $mesVenc, $cartao['dia_vencimento'] ?? 10);

        $id = db()->insert(
            'INSERT INTO faturas_cartao (cartao_id, mes_ref, ano_ref, data_vencimento, valor_total)
             VALUES (?,?,?,?,0)',
            [$cartaoId, $mes, $ano, $dataVenc]
        );
        $f = db()->one('SELECT * FROM faturas_cartao WHERE id=?', [$id]);
    }
    return $f;
}

/**
 * Recalcula o valor_total da fatura somando os gastos
 */
function recalcularFatura(int $faturaId): float {
    $total = db()->one('SELECT SUM(valor) as t FROM gastos_cartao WHERE fatura_id=?', [$faturaId]);
    $v = (float)($total['t'] ?? 0);
    db()->exec('UPDATE faturas_cartao SET valor_total=? WHERE id=?', [$v, $faturaId]);
    return $v;
}

/**
 * Registra lançamento com tratamento de taxa automática
 * Retorna o ID do lançamento principal
 */
function registrarLancamento(array $d): int {
    $db = db();
    $db->beginTransaction();
    try {
        $mes = (int)date('m', strtotime($d['data']));
        $ano = (int)date('Y', strtotime($d['data']));

        // Bloqueia lançamento em mês fechado ou não iniciado
        if (mesFechado($mes, $ano)) {
            throw new Exception('CAIXA FECHADO — O mês ' . nomeMes($mes) . '/' . $ano . ' está fechado. Por gentileza efetue novamente no mês em ABERTO.');
        }
        if (!mesIniciado($mes, $ano)) {
            $abrt = mesAbertoAtual();
            throw new Exception('CAIXA AINDA NÃO INICIADO — O mês ' . nomeMes($mes) . '/' . $ano . ' ainda não foi iniciado. Por gentileza efetue novamente no mês ' . nomeMes($abrt['mes']) . '/' . $abrt['ano'] . ' que está em ABERTO.');
        }

        $valor       = (float)$d['valor'];
        // REGRA: taxa só se aplica em ENTRADAS. Para saídas, o valor pago já é o valor cheio.
        $taxaValor   = ($d['tipo'] === 'entrada') ? (float)($d['taxa_valor'] ?? 0) : 0;
        $taxaTipo    = $d['taxa_tipo'] ?? 'fixo';
        $valorLiq    = $d['tipo'] === 'entrada'
                       ? $valor - $taxaValor
                       : $valor;

        $id = $db->insert(
            'INSERT INTO lancamentos
             (tipo, data, valor, valor_liquido, taxa_valor, taxa_tipo,
              descricao, categoria_id, cliente_id, conta_id, conta_destino_id,
              metodo_id, tem_nf, num_nf, mes, ano, observacoes, origem_conta_pagar_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $d['tipo'], $d['data'], $valor, $valorLiq, $taxaValor, $taxaTipo,
                $d['descricao'] ?? '', $d['categoria_id'] ?? null, $d['cliente_id'] ?? null,
                $d['conta_id'], $d['conta_destino_id'] ?? null,
                $d['metodo_id'] ?? null, $d['tem_nf'] ?? 0, $d['num_nf'] ?? null,
                $mes, $ano, $d['observacoes'] ?? null, $d['origem_id'] ?? null
            ]
        );

        // Se há taxa em entrada → gera saída de taxa automaticamente
        if ($d['tipo'] === 'entrada' && $taxaValor > 0) {
            $catTaxa = db()->one("SELECT id FROM categorias WHERE nome='Taxa bancária' AND tipo='saida'");
            $catId   = $catTaxa ? $catTaxa['id'] : null;
            $taxaId  = $db->insert(
                'INSERT INTO lancamentos
                 (tipo, data, valor, valor_liquido, descricao, categoria_id,
                  conta_id, metodo_id, mes, ano, taxa_lancamento_id)
                 VALUES ("saida",?,?,?,?,?,?,?,?,?,?)',
                [
                    $d['data'], $taxaValor, $taxaValor,
                    'Taxa s/ recebimento: ' . ($d['descricao'] ?? ''),
                    $catId, $d['conta_id'], $d['metodo_id'] ?? null,
                    $mes, $ano, $id
                ]
            );
            // Atualiza referência cruzada
            $db->exec('UPDATE lancamentos SET taxa_lancamento_id=? WHERE id=?', [$taxaId, $id]);
        }

        $db->commit();
        return $id;
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}
