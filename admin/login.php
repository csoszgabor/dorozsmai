<?php
ini_set('display_errors',1); error_reporting(E_ALL);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['username'] ?? '');
  $pass  = trim($_POST['password'] ?? '');
  if ($email && $pass && auth_login($email, $pass)) {
    header('Location: /admin/index.php'); exit;
  }
  $error = 'Hibás felhasználónév vagy jelszó.';
}
?>

<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Bejelentkezés – Dorozs Hidraulika</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 3.3.5 -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">

  <!-- Oswald font -->
  <link href="https://fonts.googleapis.com/css?family=Oswald:400,600&display=swap" rel="stylesheet">

  <!-- Saját minimál kiegészítés -->
  <link rel="stylesheet" href="../css/login.css">
</head>
<body>

<div class="container">
  <div class="row">
    <div class="col-md-4 col-md-offset-4 col-sm-6 col-sm-offset-3">

      <div class="text-center" style="margin:40px 0 20px;">
        <img src="../images/logo.png" alt="Dorozs Hidraulika" class="img-responsive center-block" style="max-width:220px;">
      </div>

      <h3 class="text-center login-title">Bejelentkezés</h3>

      <form method="post" action="">
        <div class="form-group">
          <input type="text" name="username" class="form-control" placeholder="Felhasználónév (e-mail)" required>
        </div>
        <div class="form-group">
          <input type="password" name="password" class="form-control" placeholder="Jelszó" required>
        </div>
        <button type="submit" class="btn btn-block btn-login">
          <i class="fa fa-sign-in"></i> Belépés
        </button>
      </form>

      <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-top:15px;"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <p class="text-center small text-muted" style="margin-top:15px;">
        &copy; <?= date('Y') ?> Dorozs Hidraulika – Admin
      </p>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js"></script>
</body>
</html>
