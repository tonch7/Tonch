<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
flash('warning', 'Redefinição pública de senha está desativada. Faça a troca em Cadastros → Config/Sistema com um administrador autenticado.');
header('Location: ' . url('public/login.php'));
exit;
