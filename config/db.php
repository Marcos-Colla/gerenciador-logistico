<?php
// Para o XAMPP padrão: usuário root e senha vazia. Ajuste para seu ambiente.
$host = '127.0.0.1';
$banco = 'apo_logistica';
$usuario = 'root';
$senha = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$banco;charset=utf8mb4", $usuario, $senha, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Não foi possível conectar ao banco. Importe database/banco.sql no phpMyAdmin e confira config/db.php.');
}
