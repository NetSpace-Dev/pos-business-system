<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use Firebase\JWT\JWT;
use PDO;

class AuthController {
    public function login(array $req) {
        $body = $req['body'];
        $email = $body['email'] ?? '';
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            return Response::error('Email and password are required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `users` WHERE `email` = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            return Response::error('Invalid email or password', 401);
        }

        if (!password_verify($password, $user['passwordHash'])) {
            return Response::error('Invalid email or password', 401);
        }

        $secret = Database::getEnv('JWT_SECRET', 'fallback-secret');
        $payload = [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'exp' => time() + (24 * 60 * 60) // 24 hours
        ];

        $token = JWT::encode($payload, $secret, 'HS256');

        return Response::json([
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
    }

    public function register(array $req) {
        $body = $req['body'];
        $name = $body['name'] ?? '';
        $email = $body['email'] ?? '';
        $password = $body['password'] ?? '';
        $role = $body['role'] ?? 'admin';

        if (!$name || !$email || !$password) {
            return Response::error('Name, email, and password are required', 400);
        }

        $db = Database::getConnection();
        
        // Check if user already exists
        $stmt = $db->prepare("SELECT `id` FROM `users` WHERE `email` = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            return Response::error('User with this email already exists', 400);
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $userRole = ($role === 'staff') ? 'staff' : 'admin';

        $stmt = $db->prepare("
            INSERT INTO `users` (`name`, `email`, `passwordHash`, `role`, `status`, `createdAt`) 
            VALUES (:name, :email, :passwordHash, :role, 'active', NOW())
        ");
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'passwordHash' => $passwordHash,
            'role' => $userRole
        ]);

        $newId = $db->lastInsertId();

        return Response::json([
            'message' => 'User registered successfully',
            'user' => [
                'id' => (int) $newId,
                'name' => $name,
                'email' => $email,
                'role' => $userRole
            ]
        ], 201);
    }

    public function me(array $req) {
        if (!isset($req['user'])) {
            return Response::error('Unauthorized', 401);
        }

        $userId = $req['user']['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return Response::error('User not found', 404);
        }

        // Fetch roleRef relation if roleId is set
        $roleRef = null;
        if ($user['roleId']) {
            $rStmt = $db->prepare("SELECT * FROM `roles` WHERE `id` = :roleId LIMIT 1");
            $rStmt->execute(['roleId' => $user['roleId']]);
            $roleRef = $rStmt->fetch() ?: null;
        }

        return Response::json([
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'roleId' => $user['roleId'] ? (int) $user['roleId'] : null,
            'roleRef' => $roleRef,
            'status' => $user['status'],
            'createdAt' => $user['createdAt']
        ]);
    }
}
