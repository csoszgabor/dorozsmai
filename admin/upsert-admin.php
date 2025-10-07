<?php
require __DIR__.'/../inc/bootstrap.php';

$email = 'admin@dorozs.hu';   // ← IDE írd, amivel belépsz
$pass  = 'Admin123!';         // ← IDE egy erős jelszó

$hash = password_hash($pass, PASSWORD_BCRYPT);

/* SQLite UPSERT: ha létezik az email, frissítjük a jelszót; ha nem, beszúrjuk */
$sql = "INSERT INTO users(email, pass_hash, role)
        VALUES (:e, :h, 'admin')
        ON CONFLICT(email) DO UPDATE SET pass_hash = excluded.pass_hash, role='admin'";
$st = $pdo->prepare($sql);
$st->execute([':e'=>$email, ':h'=>$hash]);

echo "OK – admin beállítva: {$email}";
