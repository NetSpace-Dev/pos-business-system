<?php
namespace App\Config;

use PDO;
use PDOException;

class Database {
    private static ?PDO $connection = null;
    private static array $env = [];

    public static function loadEnv() {
        if (!empty(self::$env)) {
            return;
        }

        // Locate .env in backend-php directory
        $envPath = dirname(dirname(dirname(dirname(__DIR__)))) . '/.env';
        if (!file_exists($envPath)) {
            // Check fallback for nested routes
            $envPath = __DIR__ . '/../../../.env';
        }

        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    // Strip quotes if they wrap the value
                    if (preg_match('/^"([^"]*)"$/', $value, $matches)) {
                        $value = $matches[1];
                    } elseif (preg_match("/^'([^']*)'$/", $value, $matches)) {
                        $value = $matches[1];
                    }
                    self::$env[$key] = $value;
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
    }

    public static function getEnv(string $key, $default = null) {
        self::loadEnv();
        return self::$env[$key] ?? $_ENV[$key] ?? getenv($key) ?? $default;
    }

    public static function getConnection(): PDO {
        if (self::$connection !== null) {
            return self::$connection;
        }

        self::loadEnv();
        $dbUrl = self::getEnv('DATABASE_URL');

        if (!$dbUrl) {
            throw new PDOException("DATABASE_URL env variable is not set.");
        }

        // Parse connection URL e.g. mysql://root:@127.0.0.1:3306/pos_business_db
        $parsed = parse_url($dbUrl);
        if (!$parsed || ($parsed['scheme'] ?? '') !== 'mysql') {
            throw new PDOException("Invalid DATABASE_URL. Only mysql:// scheme is supported currently.");
        }

        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 3306;
        $user = $parsed['user'] ?? 'root';
        $pass = $parsed['pass'] ?? '';
        $dbname = ltrim($parsed['path'] ?? '', '/');

        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        
        try {
            self::$connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return self::$connection;
        } catch (PDOException $e) {
            error_log("Connection failed: " . $e->getMessage());
            throw $e;
        }
    }
}
