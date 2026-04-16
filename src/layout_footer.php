<?php /** layout_footer.php */ ?>
</div><!-- .main-wrap -->

<div style="background:#c0c0c0;border-top:1px solid #888;padding:4px 14px;font-size:10px;color:#555;text-align:center;">
  Mateus 11:28 "Vinde a mim todos os que estais cansados e oprimidos e eu vos aliviarei" &copy; <?= date('Y') ?> <?= h(defined('APP_NAME') ? APP_NAME : 'Tonch') ?> &mdash; Gestão Financeira
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php if (!empty($js)): foreach ($js as $s): ?>
<script><?= $s ?></script>
<?php endforeach; endif; ?>
</body>
</html>
