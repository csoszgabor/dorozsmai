<?php
require __DIR__.'/../inc/auth.php';
auth_logout();
header('Location: /admin/login.php');
