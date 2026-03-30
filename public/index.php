<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config/bootstrap.php';
if (!empty($_SESSION['admin_user'])) {
    redirect('/admin/index.php');
}
redirect('/auth/login.php');
