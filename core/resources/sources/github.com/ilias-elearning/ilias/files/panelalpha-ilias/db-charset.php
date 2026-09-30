<?php
// install.md: ILIAS supports 3-byte utf8 only ("CREATE DATABASE ilias CHARACTER SET
// utf8 COLLATE utf8_general_ci"); the account's database is created utf8mb4.
$e = static fn (string $k): string => (string) getenv($k);
$pdo = new PDO('mysql:host=' . $e('DB_HOST') . ';port=' . ($e('DB_PORT') ?: '3306'), $e('DB_USERNAME'), $e('DB_PASSWORD'));
$pdo->exec('ALTER DATABASE `' . str_replace('`', '``', $e('DB_DATABASE')) . '` CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci');
