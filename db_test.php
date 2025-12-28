<?php
$url = getenv('DATABASE_URL');
if (!$url) { die("No DATABASE_URL"); }

$db = parse_url($url);
$scheme = $db['scheme'] ?? '';
if ($scheme === 'postgres' || $scheme === 'postgresql') $scheme = 'pgsql';

$host = $db['host'];
$port = $db['port'] ?? 5432;
$user = rawurldecode($db['user'] ?? '');
$pass = rawurldecode($db['pass'] ?? '');
$name = ltrim($db['path'] ?? '', '/');
$query = $db['query'] ?? '';

$extra = $query ? ';' . str_replace('&',';',$query) : '';
$dsn = "pgsql:host=$host;port=$port;dbname=$name$extra";

$pdo = new PDO($dsn, $user, $pass, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

echo "OK\n";
echo $pdo->query("select now()")->fetchColumn();
