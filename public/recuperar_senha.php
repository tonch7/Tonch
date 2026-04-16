<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
flash('warning', 'Recuperação pública de senha está desativada por segurança. Solicite a redefinição a um administrador já autenticado no sistema.');
header('Location: ' . url('public/login.php'));
exit;
