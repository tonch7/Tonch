<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();
requireSistemaLiberado();

$ano = (int)($_GET['ano'] ?? date('Y'));

if (!empty($_GET['download'])) {
    $fmt   = $_GET['fmt'] ?? 'csv';
    $tbl   = $_GET['tbl'] ?? 'lancamentos';

    $queries = [
        'lancamentos'   => ['SELECT l.*,c.nome cat,ct.nome conta,m.nome metodo,cl.nome cliente FROM lancamentos l LEFT JOIN categorias c ON l.categoria_id=c.id LEFT JOIN contas ct ON l.conta_id=ct.id LEFT JOIN metodos m ON l.metodo_id=m.id LEFT JOIN clientes cl ON l.cliente_id=cl.id WHERE l.ano=? ORDER BY l.data', [$ano]],
        'notas_fiscais' => ['SELECT l.id,l.data,l.num_nf,l.valor,l.valor_liquido,l.taxa_valor,l.descricao,l.mes,l.ano,c.nome cat,cl.nome cliente,ct.nome conta,m.nome metodo FROM lancamentos l LEFT JOIN categorias c ON l.categoria_id=c.id LEFT JOIN clientes cl ON l.cliente_id=cl.id LEFT JOIN contas ct ON l.conta_id=ct.id LEFT JOIN metodos m ON l.metodo_id=m.id WHERE l.tem_nf=1 AND l.ano=? ORDER BY l.data DESC', [$ano]],
        'contas'        => ['SELECT * FROM contas', []],
        'clientes'      => ['SELECT * FROM clientes', []],
        'cartao'        => ['SELECT f.*,ct.nome cartao FROM faturas_cartao f LEFT JOIN cartoes ct ON f.cartao_id=ct.id WHERE f.ano_ref=?', [$ano]],
        'gastos'        => ['SELECT g.*,c.nome cat,ct.nome cartao FROM gastos_cartao g LEFT JOIN categorias c ON g.categoria_id=c.id LEFT JOIN cartoes ct ON g.cartao_id=ct.id', []],
    ];

    $q    = $queries[$tbl] ?? $queries['lancamentos'];
    $rows = db()->all($q[0], $q[1]);

    if ($fmt === 'json') {
        header('Content-Type: application/json;charset=UTF-8');
        header("Content-Disposition: attachment;filename=\"tonch_{$tbl}_{$ano}.json\"");
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($fmt === 'xml') {
        header('Content-Type: application/xml;charset=UTF-8');
        header("Content-Disposition: attachment;filename=\"tonch_{$tbl}_{$ano}.xml\"");
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo "<tonch>\n  <tabela>{$tbl}</tabela>\n  <ano>{$ano}</ano>\n  <registros>\n";
        foreach ($rows as $r) {
            echo "    <registro>\n";
            foreach ($r as $k => $v) {
                $k = preg_replace('/[^a-zA-Z0-9_]/', '_', $k);
                $v = htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
                echo "      <{$k}>{$v}</{$k}>\n";
            }
            echo "    </registro>\n";
        }
        echo "  </registros>\n</tonch>\n";
        exit;
    }

    if ($fmt === 'txt') {
        header('Content-Type: text/plain;charset=UTF-8');
        header("Content-Disposition: attachment;filename=\"tonch_{$tbl}_{$ano}.txt\"");
        if (!empty($rows)) {
            $cols = array_keys($rows[0]);
            $widths = array_fill_keys($cols, 0);
            foreach ($cols as $c) $widths[$c] = strlen($c);
            foreach ($rows as $r) foreach ($cols as $c) $widths[$c] = max($widths[$c], strlen((string)$r[$c]));
            $sep = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';
            $line = function($row) use ($cols, $widths) {
                return '| ' . implode(' | ', array_map(fn($c) => str_pad((string)$row[$c], $widths[$c]), $cols)) . ' |';
            };
            echo "tonch — Exportação: {$tbl} — Ano: {$ano}\n";
            echo date('d/m/Y H:i:s') . "\n\n";
            echo $sep . "\n";
            echo $line(array_combine($cols, $cols)) . "\n";
            echo $sep . "\n";
            foreach ($rows as $r) echo $line($r) . "\n";
            echo $sep . "\n";
            echo "\nTotal de registros: " . count($rows) . "\n";
        }
        exit;
    }

    header('Content-Type: text/csv;charset=UTF-8');
    header("Content-Disposition: attachment;filename=\"tonch_{$tbl}_{$ano}.csv\"");
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]), ';');
        foreach ($rows as $r) fputcsv($out, $r, ';');
    }
    fclose($out);
    exit;
}

$mes = (int)($_GET['mes'] ?? date('n'));
$pageTitle='Exportar'; $activePage='relatorios';
require_once ROOT_PATH.'/src/layout_header.php';
?>
<div class="win">
  <div class="win-title">&#128190; Exportar Dados para Power BI / Excel</div>
  <div class="win-body">
    <div class="alert alert-info mb-12">&#128161; Use CSV para Excel/Power BI (separador ;) ou JSON para APIs e outras ferramentas.</div>
    <div class="fg mb-12" style="max-width:200px;">
      <label>Ano dos dados</label>
      <form method="get">
        <input type="hidden" name="mes" value="<?=$mes?>">
        <input type="number" name="ano" value="<?=$ano?>" min="2020" max="2099">
        <button type="submit" class="btn btn-default btn-sm mt-4">Filtrar</button>
      </form>
    </div>
    <div class="grid-2">
      <?php $opcoes=[
        ['lancamentos',   'Lançamentos do Ano',    'Todas as movimentações financeiras do ano'],
        ['notas_fiscais', 'Notas Fiscais',          'Apenas lançamentos com NF emitida'],
        ['contas',        'Contas Bancárias',       'Cadastro de contas'],
        ['clientes',      'Clientes',               'Cadastro completo de clientes'],
      ];
      foreach($opcoes as [$k,$nome,$desc]): ?>
      <div class="win">
        <div class="win-title"><?=$nome?></div>
        <div class="win-body">
          <p class="text-muted text-sm mb-8"><?=$desc?></p>
          <div class="flex flex-gap flex-wrap">
            <a href="?mes=<?=$mes?>&ano=<?=$ano?>&download=1&tbl=<?=$k?>&fmt=csv"  class="btn btn-default btn-sm" title="Excel / Power BI (separador ;)">&#128196; CSV</a>
            <a href="?mes=<?=$mes?>&ano=<?=$ano?>&download=1&tbl=<?=$k?>&fmt=json" class="btn btn-default btn-sm" title="APIs e ferramentas web">{} JSON</a>
            <a href="?mes=<?=$mes?>&ano=<?=$ano?>&download=1&tbl=<?=$k?>&fmt=xml"  class="btn btn-default btn-sm" title="XML estruturado">&#128196; XML</a>
            <a href="?mes=<?=$mes?>&ano=<?=$ano?>&download=1&tbl=<?=$k?>&fmt=txt"  class="btn btn-default btn-sm" title="Relatório texto formatado">&#128203; TXT</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php require_once ROOT_PATH.'/src/layout_footer.php'; ?>
