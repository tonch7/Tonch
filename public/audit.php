<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
Auth::require();

$modulo = $_GET['modulo'] ?? '';
$limit  = 200;
$params = [];
$where  = '';
if ($modulo) { $where = 'WHERE modulo=?'; $params[] = $modulo; }

$logs = db()->all(
    "SELECT * FROM audit_log {$where} ORDER BY criado_em DESC LIMIT {$limit}",
    $params
);
$modulos = db()->all('SELECT DISTINCT modulo FROM audit_log WHERE modulo IS NOT NULL ORDER BY modulo');

$pageTitle  = 'Auditoria & Logs';
$activePage = 'cadastros';
$mes = mesAbertoAtual()['mes'];
$ano = mesAbertoAtual()['ano'];
require_once ROOT_PATH . '/src/layout_header.php';
?>
<div class="win">
  <div class="win-title">📊 Auditoria — Logs do Sistema
    <form method="get" style="display:inline-flex;gap:6px;align-items:center;">
      <select name="modulo" style="font-size:11px;padding:2px 6px;">
        <option value="">Todos os módulos</option>
        <?php foreach ($modulos as $m): ?>
        <option value="<?= h($m['modulo']) ?>" <?= $m['modulo']===$modulo ? 'selected' : '' ?>><?= h($m['modulo']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-default btn-xs">Filtrar</button>
      <a href="<?= url("public/audit.php") ?>" class="btn btn-default btn-xs">Limpar</a>
    </form>
  </div>
  <div style="padding:0;">
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>#</th><th>Data/Hora</th><th>Usuário</th><th>Ação</th><th>Módulo</th><th>Detalhe</th><th>IP</th>
        </tr></thead>
        <tbody>
          <?php if (empty($logs)): ?>
          <tr><td colspan="7" class="text-center text-muted" style="padding:20px;">Nenhum log encontrado.</td></tr>
          <?php else: foreach ($logs as $l): ?>
          <tr>
            <td class="text-mono" style="font-size:10px;color:#888;"><?= $l['id'] ?></td>
            <td class="text-mono" style="font-size:11px;white-space:nowrap;"><?= date('d/m/y H:i:s', strtotime($l['criado_em'])) ?></td>
            <td><strong><?= h($l['usuario'] ?? '—') ?></strong></td>
            <td>
              <?php
                $color = str_contains($l['acao'],'FAIL')||str_contains($l['acao'],'ERR') ? 'var(--red)' :
                        (str_contains($l['acao'],'DEL') ? '#cc6600' :
                        (str_contains($l['acao'],'NOVO')||str_contains($l['acao'],'ABERTO') ? 'var(--green)' : 'var(--blue)'));
              ?>
              <span style="color:<?= $color ?>;font-weight:600;font-size:11px;"><?= h($l['acao']) ?></span>
            </td>
            <td><span style="font-size:11px;background:#f0f0f0;padding:1px 6px;border-radius:3px;"><?= h($l['modulo'] ?? '—') ?></span></td>
            <td style="font-size:12px;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($l['detalhe'] ?? '') ?>"><?= h($l['detalhe'] ?? '—') ?></td>
            <td class="text-mono" style="font-size:10px;color:#888;"><?= h($l['ip'] ?? '') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once ROOT_PATH . '/src/layout_footer.php'; ?>
