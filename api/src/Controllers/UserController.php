<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

class UserController {
    public function getUsers(array $req) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT u.*, r.name as roleName 
                FROM `users` u 
                LEFT JOIN `roles` r ON u.roleId = r.id 
                ORDER BY u.createdAt DESC
            ");
            $users = $stmt->fetchAll();

            foreach ($users as &$u) {
                $u['id'] = (int)$u['id'];
                $u['roleId'] = $u['roleId'] ? (int)$u['roleId'] : null;
                $u['roleRef'] = $u['roleId'] ? ['id' => $u['roleId'], 'name' => $u['roleName']] : null;
                unset($u['roleName'], $u['passwordHash']); // never return passwordHash in lists
            }

            return Response::json($users);
        } catch (\Exception $e) {
            error_log('Get users error: ' . $e->getMessage());
            return Response::error('Failed to retrieve users', 500);
        }
    }

    public function updateUserStatus(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        $body = $req['body'];
        $status = $body['status'] ?? '';

        if ($id <= 0) {
            return Response::error('Invalid user ID', 400);
        }

        if ($status !== 'active' && $status !== 'suspended') {
            return Response::error('Invalid status value', 400);
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            return Response::error('User not found', 404);
        }

        if (isset($req['user']) && $req['user']['id'] === $id) {
            return Response::error('Cannot modify your own status', 400);
        }

        $userEmail = $req['user']['email'] ?? 'admin@pos.com';
        $userId = $req['user']['id'] ?? 1;

        try {
            $db->beginTransaction();

            $upd = $db->prepare("UPDATE `users` SET `status` = :status WHERE `id` = :id");
            $upd->execute(['status' => $status, 'id' => $id]);

            // Log sensitive action to audit logs
            $aud = $db->prepare("
                INSERT INTO `audit_logs` (`userId`, `userEmail`, `action`, `details`, `createdAt`)
                VALUES (:userId, :userEmail, 'UPDATE_USER_STATUS', :details, NOW())
            ");
            $aud->execute([
                'userId' => $userId,
                'userEmail' => $userEmail,
                'details' => "Changed status of user '{$targetUser['email']}' to '{$status}'"
            ]);

            $db->commit();

            // Fetch and return final user details
            $stmt->execute(['id' => $id]);
            $updated = $stmt->fetch();
            $updated['id'] = (int)$updated['id'];
            $updated['roleId'] = $updated['roleId'] ? (int)$updated['roleId'] : null;
            unset($updated['passwordHash']);

            return Response::json($updated);
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('Update user status error: ' . $e->getMessage());
            return Response::error('Failed to update user status', 500);
        }
    }

    public function updateUserRole(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        $body = $req['body'];
        $roleId = isset($body['roleId']) ? (int)$body['roleId'] : 0;

        if ($id <= 0) {
            return Response::error('Invalid user ID', 400);
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            return Response::error('User not found', 404);
        }

        $rStmt = $db->prepare("SELECT * FROM `roles` WHERE `id` = :roleId LIMIT 1");
        $rStmt->execute(['roleId' => $roleId]);
        $role = $rStmt->fetch();

        if (!$role) {
            return Response::error('Role does not exist', 400);
        }

        $userEmail = $req['user']['email'] ?? 'admin@pos.com';
        $userId = $req['user']['id'] ?? 1;

        try {
            $db->beginTransaction();

            $upd = $db->prepare("UPDATE `users` SET `roleId` = :roleId WHERE `id` = :id");
            $upd->execute(['roleId' => $roleId, 'id' => $id]);

            // Log to audit log
            $aud = $db->prepare("
                INSERT INTO `audit_logs` (`userId`, `userEmail`, `action`, `details`, `createdAt`)
                VALUES (:userId, :userEmail, 'UPDATE_USER_ROLE', :details, NOW())
            ");
            $aud->execute([
                'userId' => $userId,
                'userEmail' => $userEmail,
                'details' => "Assigned role '{$role['name']}' to user '{$targetUser['email']}'"
            ]);

            $db->commit();

            $stmt->execute(['id' => $id]);
            $updated = $stmt->fetch();
            $updated['id'] = (int)$updated['id'];
            $updated['roleId'] = (int)$updated['roleId'];
            unset($updated['passwordHash']);

            return Response::json($updated);
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('Update user role error: ' . $e->getMessage());
            return Response::error('Failed to update user role', 500);
        }
    }

    public function getRoles(array $req) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT * FROM `roles` ORDER BY `name` ASC");
            $roles = $stmt->fetchAll();
            foreach ($roles as &$r) {
                $r['id'] = (int)$r['id'];
            }
            return Response::json($roles);
        } catch (\Exception $e) {
            error_log('Get roles error: ' . $e->getMessage());
            return Response::error('Failed to retrieve roles', 500);
        }
    }

    public function createRole(array $req) {
        $body = $req['body'];
        $name = $body['name'] ?? '';
        $permissionSet = $body['permissionSet'] ?? null;

        if (!$name) {
            return Response::error('Role name is required', 400);
        }

        $db = Database::getConnection();

        // Check uniqueness
        $stmt = $db->prepare("SELECT `id` FROM `roles` WHERE `name` = :name LIMIT 1");
        $stmt->execute(['name' => $name]);
        if ($stmt->fetch()) {
            return Response::error('Role with this name already exists', 400);
        }

        $permString = is_string($permissionSet) ? $permissionSet : json_encode($permissionSet);
        $userEmail = $req['user']['email'] ?? 'admin@pos.com';
        $userId = $req['user']['id'] ?? 1;

        try {
            $db->beginTransaction();

            $ins = $db->prepare("
                INSERT INTO `roles` (`name`, `permissionSet`, `createdAt`, `updatedAt`)
                VALUES (:name, :permissionSet, NOW(), NOW())
            ");
            $ins->execute([
                'name' => $name,
                'permissionSet' => $permString
            ]);
            $newRoleId = (int)$db->lastInsertId();

            // Log
            $aud = $db->prepare("
                INSERT INTO `audit_logs` (`userId`, `userEmail`, `action`, `details`, `createdAt`)
                VALUES (:userId, :userEmail, 'CREATE_ROLE', :details, NOW())
            ");
            $aud->execute([
                'userId' => $userId,
                'userEmail' => $userEmail,
                'details' => "Created new custom role: '{$name}'"
            ]);

            $db->commit();

            // Return new role
            $stmt = $db->prepare("SELECT * FROM `roles` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $newRoleId]);
            $role = $stmt->fetch();
            $role['id'] = (int)$role['id'];

            return Response::json($role, 201);
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('Create role error: ' . $e->getMessage());
            return Response::error('Failed to create role', 500);
        }
    }

    public function updateRole(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid role ID', 400);
        }

        $body = $req['body'];
        $name = $body['name'] ?? null;
        $permissionSet = $body['permissionSet'] ?? null;

        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT * FROM `roles` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Role not found', 404);
        }

        if ($existing['name'] === 'Super Admin') {
            return Response::error('Cannot modify Super Admin role configuration', 400);
        }

        $roleName = $name ?: $existing['name'];
        $permString = $permissionSet ? (is_string($permissionSet) ? $permissionSet : json_encode($permissionSet)) : $existing['permissionSet'];

        $userEmail = $req['user']['email'] ?? 'admin@pos.com';
        $userId = $req['user']['id'] ?? 1;

        try {
            $db->beginTransaction();

            $upd = $db->prepare("
                UPDATE `roles` 
                SET `name` = :name, `permissionSet` = :permissionSet, `updatedAt` = NOW() 
                WHERE `id` = :id
            ");
            $upd->execute([
                'name' => $roleName,
                'permissionSet' => $permString,
                'id' => $id
            ]);

            // Log
            $aud = $db->prepare("
                INSERT INTO `audit_logs` (`userId`, `userEmail`, `action`, `details`, `createdAt`)
                VALUES (:userId, :userEmail, 'UPDATE_ROLE', :details, NOW())
            ");
            $aud->execute([
                'userId' => $userId,
                'userEmail' => $userEmail,
                'details' => "Updated role configuration for role: '{$existing['name']}'"
            ]);

            $db->commit();

            // Return updated role
            $stmt->execute(['id' => $id]);
            $updated = $stmt->fetch();
            $updated['id'] = (int)$updated['id'];

            return Response::json($updated);
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('Update role error: ' . $e->getMessage());
            return Response::error('Failed to update role', 500);
        }
    }

    public function getAuditLogs(array $req) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT * FROM `audit_logs` ORDER BY `createdAt` DESC LIMIT 100");
            $logs = $stmt->fetchAll();
            foreach ($logs as &$l) {
                $l['id'] = (int)$l['id'];
                $l['userId'] = $l['userId'] ? (int)$l['userId'] : null;
            }
            return Response::json($logs);
        } catch (\Exception $e) {
            error_log('Get audit logs error: ' . $e->getMessage());
            return Response::error('Failed to retrieve audit log entries', 500);
        }
    }
}
