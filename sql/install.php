<?php
require __DIR__ . '/../inc/bootstrap.php';

$sql = @file_get_contents(__DIR__ . '/001_init.sql');
if (!$sql) { exit('Nem található vagy nem olvasható a 001_init.sql!'); }

try {
  $pdo->exec($sql);
  echo "<b>✅ Adatbázis inicializálva!</b><br>Táblák és mintaadatok létrehozva.";
} catch (Throwable $e) {
  echo "<b>❌ Hiba:</b> " . htmlspecialchars($e->getMessage());
}
