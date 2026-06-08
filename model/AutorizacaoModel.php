<?php

require_once __DIR__ . '/connection.php';

class AutorizacaoModel
{
    private $conn;

    public function __construct()
    {
        $this->conn = Connection::getConnection();
    }

    public function salvar(
        string $email,
        bool $autorizado,
        ?string $dataEmail = null
    )
    {
        $sql = "
            INSERT INTO autorizacoes
            (
                email,
                autorizado,
                data_email
            )
            VALUES
            (
                :email,
                :autorizado,
                :data_email
            )
            ON DUPLICATE KEY UPDATE
                autorizado = VALUES(autorizado),
                data_email = VALUES(data_email)
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':email' => $email,
            ':autorizado' => $autorizado,
            ':data_email' => $dataEmail
        ]);
    }

    public function listarAutorizados()
    {
        $stmt = $this->conn->query("
            SELECT *
            FROM autorizacoes
            WHERE autorizado = 1
            ORDER BY data_verificacao DESC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}