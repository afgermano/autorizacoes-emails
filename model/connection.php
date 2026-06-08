<?php

class Connection
{
    private static $conn;

    public static function getConnection()
    {
        if (!isset(self::$conn)) {

            self::$conn = new PDO(
                "mysql:host=localhost;dbname=teste-autorizacoes;charset=utf8mb4",
                "root",
                ""
            );

            self::$conn->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );
        }

        return self::$conn;
    }
}