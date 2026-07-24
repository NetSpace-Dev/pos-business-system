<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

class SmsTemplateController {
    public function getSmsTemplates(array $req) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT * FROM `sms_templates` ORDER BY `name` ASC");
            $templates = $stmt->fetchAll();
            foreach ($templates as &$t) {
                $t['id'] = (int)$t['id'];
            }
            return Response::json($templates);
        } catch (\Exception $e) {
            error_log('Get SMS templates error: ' . $e->getMessage());
            return Response::error('Failed to retrieve SMS templates', 500);
        }
    }

    public function getSmsTemplateById(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid template ID', 400);
        }

        $db = Database::getConnection();
        
        try {
            $stmt = $db->prepare("SELECT * FROM `sms_templates` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $id]);
            $template = $stmt->fetch();

            if (!$template) {
                return Response::error('SMS template not found', 404);
            }

            $template['id'] = (int)$template['id'];
            return Response::json($template);
        } catch (\Exception $e) {
            error_log('Get SMS template by ID error: ' . $e->getMessage());
            return Response::error('Internal server error', 500);
        }
    }

    public function createSmsTemplate(array $req) {
        $body = $req['body'];
        $name = $body['name'] ?? '';
        $bodyText = $body['body'] ?? '';

        if (!$name || !$bodyText) {
            return Response::error('Name and body are required', 400);
        }

        $db = Database::getConnection();

        try {
            // Check unique name
            $stmt = $db->prepare("SELECT `id` FROM `sms_templates` WHERE `name` = :name LIMIT 1");
            $stmt->execute(['name' => $name]);
            if ($stmt->fetch()) {
                return Response::error('A template with this name already exists', 400);
            }

            $ins = $db->prepare("
                INSERT INTO `sms_templates` (`name`, `body`, `createdAt`, `updatedAt`)
                VALUES (:name, :body, NOW(), NOW())
            ");
            $ins->execute(['name' => $name, 'body' => $bodyText]);
            $newId = (int)$db->lastInsertId();

            $stmt = $db->prepare("SELECT * FROM `sms_templates` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $newId]);
            $template = $stmt->fetch();
            $template['id'] = (int)$template['id'];

            return Response::json($template, 201);
        } catch (\Exception $e) {
            error_log('Create SMS template error: ' . $e->getMessage());
            return Response::error('Failed to create SMS template', 500);
        }
    }

    public function updateSmsTemplate(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid template ID', 400);
        }

        $body = $req['body'];
        $name = $body['name'] ?? '';
        $bodyText = $body['body'] ?? '';

        if (!$name || !$bodyText) {
            return Response::error('Name and body are required', 400);
        }

        $db = Database::getConnection();

        try {
            $stmt = $db->prepare("SELECT * FROM `sms_templates` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $id]);
            $existing = $stmt->fetch();

            if (!$existing) {
                return Response::error('SMS template not found', 404);
            }

            // Check if name duplicate for another template
            $dupStmt = $db->prepare("SELECT `id` FROM `sms_templates` WHERE `name` = :name AND `id` != :id LIMIT 1");
            $dupStmt->execute(['name' => $name, 'id' => $id]);
            if ($dupStmt->fetch()) {
                return Response::error('A template with this name already exists', 400);
            }

            $upd = $db->prepare("UPDATE `sms_templates` SET `name` = :name, `body` = :body, `updatedAt` = NOW() WHERE `id` = :id");
            $upd->execute(['name' => $name, 'body' => $bodyText, 'id' => $id]);

            $stmt->execute(['id' => $id]);
            $updated = $stmt->fetch();
            $updated['id'] = (int)$updated['id'];

            return Response::json($updated);
        } catch (\Exception $e) {
            error_log('Update SMS template error: ' . $e->getMessage());
            return Response::error('Failed to update SMS template', 500);
        }
    }

    public function deleteSmsTemplate(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid template ID', 400);
        }

        $db = Database::getConnection();

        try {
            $stmt = $db->prepare("SELECT `id` FROM `sms_templates` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                return Response::error('SMS template not found', 404);
            }

            $del = $db->prepare("DELETE FROM `sms_templates` WHERE `id` = :id");
            $del->execute(['id' => $id]);

            return Response::json(['message' => 'SMS template deleted successfully']);
        } catch (\Exception $e) {
            error_log('Delete SMS template error: ' . $e->getMessage());
            return Response::error('Failed to delete SMS template', 500);
        }
    }
}
