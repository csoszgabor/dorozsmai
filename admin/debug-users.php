<?php
require __DIR__.'/../inc/bootstrap.php';
$rows = $pdo->query("SELECT id,email,role,datetime(created_at) AS created_at FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: text/plain; charset=utf-8');
if(!$rows){ echo "Nincs user."; exit; }
foreach($rows as $r){
  echo "{$r['id']}  {$r['email']}  {$r['role']}  {$r['created_at']}\n";
}
