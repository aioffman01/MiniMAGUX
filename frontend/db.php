<?php
/**
 * Database connection helper for Manticore Search via PDO (MySQL protocol)
 */

class Db {
    private static $pdo = null;

    public static function getConnection() {
        if (self::$pdo === null) {
            $host = '127.0.0.1';
            $port = 9306; // Manticore Search SQL Port
            $dsn = "mysql:host=$host;port=$port;charset=utf8";
            
            try {
                self::$pdo = new PDO($dsn, 'root', '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                die("Manticore Search connection failed: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }
}
