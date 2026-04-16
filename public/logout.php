<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
auditLog('LOGOUT', 'auth', $_SESSION['username'] ?? '');
Auth::logout();
