<?php
require __DIR__.'/../inc/bootstrap.php';
$email = 'admin@dorozs.hu';  // <-- saját e-mailed
$pass  = 'Admin123!';        // <-- erős jelszó
$hash = password_hash($pass, PASSWORD_BCRYPT);
$st = $pdo->prepare("INSERT OR IGNORE INTO users(email, pass_hash, role) VALUES (:e,:h,'admin')");
$st->execute([':e'=>$email, ':h'=>$hash]);
echo "OK – admin létrehozva: {$email}";
