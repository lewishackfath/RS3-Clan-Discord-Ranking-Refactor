<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
clear_admin_session();
flash('success', 'You have been logged out.');
redirect('/auth/login.php');
