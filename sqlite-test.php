<?php
require __DIR__ . '/inc/bootstrap.php';
echo "DB_PATH = " . DB_PATH . "<br>";
try {
  $drivers = PDO::getAvailableDrivers();
  echo "PDO drivers: " . implode(', ', $drivers) . "<br>";
  $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
  echo "Táblák: " . implode(', ', $tables);
} catch (Throwable $e) { echo "HIBA: ".$e->getMessage(); }
