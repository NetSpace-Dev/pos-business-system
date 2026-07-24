<?php
namespace App\Middleware;

use App\Router;
use App\Config\Database;
use App\Utils\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;

class AuthMiddleware {
    public static function authenticateJWT(array &$req, Router $router, callable $next) {
        $authHeader = $req['headers']['Authorization'] ?? '';
        if (!$authHeader) {
            return Response::error('Unauthorized: No token provided', 401);
        }

        $parts = explode(' ', $authHeader);
        if (count($parts) !== 2 || strcasecmp($parts[0], 'Bearer') !== 0) {
            return Response::error('Unauthorized: Invalid authorization header format', 401);
        }

        $token = $parts[1];
        $secret = Database::getEnv('JWT_SECRET', 'fallback-secret');

        try {
            $decoded = (array) JWT::decode($token, new Key($secret, 'HS256'));
            
            // Check in DB if user is suspended or doesn't exist
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $decoded['id']]);
            $dbUser = $stmt->fetch();

            if (!$dbUser) {
                return Response::error('User not found', 404);
            }

            if ($dbUser['status'] === 'suspended') {
                return Response::error('Forbidden: User account is suspended', 403);
            }

            $req['user'] = $decoded;
            return $next();
        } catch (\Exception $e) {
            return Response::error('Forbidden: Invalid or expired token', 403);
        }
    }

    public static function requireAdmin(array &$req, Router $router, callable $next) {
        if (isset($req['user']) && ($req['user']['role'] ?? '') === 'admin') {
            return $next();
        }
        return Response::error('Forbidden: Admin access required', 403);
    }

    public static function checkPermission(string $module, string $action) {
        return function(array &$req, Router $router, callable $next) use ($module, $action) {
            if (!isset($req['user'])) {
                return Response::error('Unauthorized', 401);
            }

            try {
                $db = Database::getConnection();
                
                // Fetch user with their role definition
                $stmt = $db->prepare("
                    SELECT u.*, r.name as role_name, r.permissionSet 
                    FROM `users` u
                    LEFT JOIN `roles` r ON u.roleId = r.id 
                    WHERE u.id = :id 
                    LIMIT 1
                ");
                $stmt->execute(['id' => $req['user']['id']]);
                $dbUser = $stmt->fetch();

                if (!$dbUser) {
                    return Response::error('User not found', 404);
                }

                if ($dbUser['status'] === 'suspended') {
                    return Response::error('Forbidden: User account is suspended', 403);
                }

                // Super Admin bypass
                if ($dbUser['role'] === 'admin' || ($dbUser['role_name'] ?? '') === 'Super Admin') {
                    return $next();
                }

                if (empty($dbUser['roleId']) || empty($dbUser['permissionSet'])) {
                    return Response::error('Forbidden: No role assigned', 403);
                }

                $permissions = json_decode($dbUser['permissionSet'], true);
                if (isset($permissions[$module][$action]) && $permissions[$module][$action] === true) {
                    return $next();
                }

                return Response::error("Forbidden: Insufficient permissions for {$module}:{$action}", 403);
            } catch (\Exception $e) {
                error_log("Permission check error: " . $e->getMessage());
                return Response::error('Internal server error checking permissions', 500);
            }
        };
    }
}
