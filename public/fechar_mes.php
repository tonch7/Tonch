<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();

$mes    = (int)($_POST['mes'] ?? $_GET['mes'] ?? 1);
$ano    = (int)($_POST['ano'] ?? $_GET['ano'] ?? 2026);
$action = $_POST['action'] ?? '';

// ----------------------------------------------------------------
// FECHAR mês (com registro opcional de imposto)
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $aberto = mesAbertoAtual();
    header('Location: ' . url('public/lancamentos.php') . "?mes={$aberto['mes']}&ano={$aberto['ano']}");
    exit;
}

requireCsrfOrAbort(url('public/lancamentos.php') . "?mes=$mes&ano=$ano");
if ($action === 'fechar') {
    if (!mesFechado($mes, $ano) && mesIniciado($mes, $ano)) {

        // ── Parse do imposto ────────────────────────────────────
        $impDesc = trim($_POST['imp_descricao'] ?? '');
        $impTipo = $_POST['imp_tipo'] ?? 'fixo';

        // Parse robusto — aceita "100,00" e "100.00" e "1.234,56"
        $impRaw = trim($_POST['imp_valor'] ?? '0');
        $impRaw = preg_replace('/[^\d,\.]/', '', $impRaw); // deixa só dígitos, vírgula e ponto
        if (strpos($impRaw, ',') !== false) {
            // Formato BR: 1.234,56 → remove pontos, troca vírgula por ponto
            $impRaw = str_replace('.', '', $impRaw);
            $impRaw = str_replace(',', '.', $impRaw);
        }
        $impValor = (float)$impRaw;

        // ── Salva imposto em impostos_mes (SEMPRE, independente de conta) ──
        if ($impDesc !== '' && $impValor > 0) {

            // Se percentual, calcula sobre receita bruta
            if ($impTipo === 'percentual') {
                $recBruta = (float)(db()->one(
                    'SELECT SUM(COALESCE(valor_liquido,valor)) t FROM lancamentos
                     WHERE tipo="entrada" AND mes=? AND ano=?',
                    [$mes, $ano])['t'] ?? 0);
                $impValor = round($recBruta * ($impValor / 100), 2);
            }

            // Limpa registro anterior do mesmo mês (idempotente)
            db()->exec('DELETE FROM impostos_mes WHERE mes=? AND ano=?', [$mes, $ano]);

            $lancId = null;

            // Tenta criar lançamento de saída se houver conta cadastrada
            $contaPad = db()->one('SELECT id FROM contas WHERE ativa=1 ORDER BY id LIMIT 1');
            if ($contaPad) {
                $contaId = (int)$contaPad['id'];

                // Garante categoria Imposto existe
                $catImp = db()->one("SELECT id FROM categorias WHERE nome='Imposto' AND tipo='saida'");
                if (!$catImp) {
                    $catId = db()->insert("INSERT INTO categorias (nome,tipo) VALUES ('Imposto','saida')");
                } else {
                    $catId = (int)$catImp['id'];
                }

                $dataImp = sprintf('%04d-%02d-01', $ano, $mes);
                $lancId  = db()->insert(
                    'INSERT INTO lancamentos
                     (tipo,data,valor,valor_liquido,descricao,categoria_id,conta_id,mes,ano)
                     VALUES ("saida",?,?,?,?,?,?,?,?)',
                    [$dataImp, $impValor, $impValor, $impDesc, $catId, $contaId, $mes, $ano]
                );
            }

            // Grava em impostos_mes (fonte da verdade para DRE)
            db()->insert(
                'INSERT INTO impostos_mes (mes,ano,descricao,tipo,valor,lancamento_id)
                 VALUES (?,?,?,?,?,?)',
                [$mes, $ano, $impDesc, $impTipo, $impValor, $lancId]
            );

            auditLog('IMPOSTO_REGISTRADO', 'caixa',
                nomeMes($mes).'/'.$ano.' | '.$impDesc.' | R$'.number_format($impValor,2,',','.'));
        }

        // ── Fecha o mês ─────────────────────────────────────────
        db()->exec('INSERT OR IGNORE INTO meses_fechados (mes,ano) VALUES (?,?)', [$mes, $ano]);
        auditLog('MES_FECHADO', 'caixa', nomeMes($mes).'/'.$ano);

        // ── Propaga saldos para o próximo mês ───────────────────
        $proxMes = ($mes == 12) ? 1  : $mes + 1;
        $proxAno = ($mes == 12) ? $ano + 1 : $ano;
        $contas  = db()->all('SELECT id FROM contas WHERE ativa=1');
        foreach ($contas as $c) {
            $saldo = saldoConta($c['id'], $mes, $ano);
            db()->exec(
                'INSERT OR IGNORE INTO saldos_iniciais (conta_id,mes,ano,valor) VALUES (?,?,?,?)',
                [$c['id'], $proxMes, $proxAno, $saldo]
            );
        }

        $msgImp = ($impDesc && $impValor > 0)
            ? ' Imposto "' . $impDesc . '" R$ ' . number_format($impValor,2,',','.') . ' registrado.'
            : '';

        flash('success',
            '🔒 ' . nomeMes($mes) . '/' . $ano . ' fechado!' . $msgImp .
            ' Clique em "🟢 Abrir Caixa" para iniciar ' . nomeMes($proxMes) . '/' . $proxAno . '.'
        );
        header('Location: ' . url('public/lancamentos.php') . "?mes={$proxMes}&ano={$proxAno}"); exit;
    }
    header('Location: ' . url('public/lancamentos.php') . "?mes=$mes&ano=$ano"); exit;
}

// ----------------------------------------------------------------
// ABRIR próximo mês — regra: só pode abrir se NÃO há caixa aberto
// ----------------------------------------------------------------
if ($action === 'abrir') {
    // Regra de ouro: só pode abrir se o mês atual estiver fechado (um caixa por vez)
    if (temCaixaAberto()) {
        $aberto = mesAbertoAtual();
        flash('error', '🔒 Não é possível abrir novo caixa. ' . nomeMes($aberto['mes']) . '/' . $aberto['ano'] . ' ainda está ABERTO. Feche-o primeiro antes de abrir o próximo.');
        header('Location: ' . url('public/lancamentos.php') . "?mes={$aberto['mes']}&ano={$aberto['ano']}"); exit;
    }
    $proxCalc = proximoMesAbrir();
    if ($proxCalc['mes'] === $mes && $proxCalc['ano'] === $ano) {
        $mesAnt = ($mes == 1) ? 12 : $mes - 1;
        $anoAnt = ($mes == 1) ? $ano - 1 : $ano;
        $ab = dataAberturaSistema();
        $ehPrimeiro = $ab
            ? ($mes == (int)$ab['mes'] && $ano == (int)$ab['ano'])
            : ($mes == 1 && $ano == 2026);
        $podeAbrir = $ehPrimeiro || mesFechado($mesAnt, $anoAnt);
        if ($podeAbrir && !mesIniciado($mes, $ano)) {
            db()->exec('INSERT OR IGNORE INTO caixas_abertos (mes,ano) VALUES (?,?)', [$mes, $ano]);
            auditLog('CAIXA_ABERTO', 'caixa', nomeMes($mes).'/'.$ano);
            flash('success', '🟢 Caixa de ' . nomeMes($mes) . '/' . $ano . ' aberto!');
            header('Location: ' . url('public/lancamentos.php') . "?mes=$mes&ano=$ano"); exit;
        }
    }
    $aberto = mesAbertoAtual();
    flash('error', 'Não é possível abrir este mês fora da sequência.');
    header('Location: ' . url('public/lancamentos.php') . "?mes={$aberto['mes']}&ano={$aberto['ano']}"); exit;
}

// ----------------------------------------------------------------
// REABRIR — DESABILITADO: meses fechados são imutáveis
// ----------------------------------------------------------------
if ($action === 'reabrir') {
    auditLog('REABRIR_BLOQUEADO', 'caixa', 'Tentativa de reabrir ' . nomeMes($mes).'/'.$ano . ' bloqueada — imutável');
    flash('error', '🔒 ' . nomeMes($mes) . '/' . $ano . ' está FECHADO e é imutável. Registros não podem ser alterados após o fechamento.');
    header('Location: ' . url('public/lancamentos.php') . "?mes=$mes&ano=$ano"); exit;
}

// Fallback
$aberto = mesAbertoAtual();
header('Location: ' . url('public/lancamentos.php') . "?mes={$aberto['mes']}&ano={$aberto['ano']}"); exit;
