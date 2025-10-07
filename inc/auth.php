<?php
// inc/auth.php
require_once __DIR__.'/bootstrap.php';

function auth_login(string $email, string $pass): bool {
  $st = $GLOBALS['pdo']->prepare("SELECT * FROM users WHERE email=:e LIMIT 1");
  $st->execute([':e'=>$email]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if ($u && password_verify($pass, $u['pass_hash'])) {
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['uemail'] = $u['email'];
    return true;
  }
  return false;
}
function auth_logout(): void { $_SESSION = []; session_destroy(); }
function auth_user(): ?array {
  if (empty($_SESSION['uid'])) return null;
  $st = $GLOBALS['pdo']->prepare("SELECT id,email,role FROM users WHERE id=:id");
  $st->execute([':id'=>(int)$_SESSION['uid']]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function require_admin(): void {
  if (!auth_user()) { header('Location: /admin/login.php'); exit; }
}
