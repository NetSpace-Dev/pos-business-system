<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

class DeadlineController {
    public function getDeadlines(array $req) {
        $query = $req['query'];
        $status = $query['status'] ?? '';

        $db = Database::getConnection();
        $sql = "
            SELECT d.*, c.companyName, c.contactPerson, i.invoiceNumber 
            FROM `project_deadlines` d 
            JOIN `clients` c ON d.clientId = c.id 
            LEFT JOIN `invoices` i ON d.invoiceId = i.id 
            WHERE 1=1
        ";
        $params = [];

        if ($status) {
            $sql .= " AND d.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY d.deadlineDate ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $deadlines = $stmt->fetchAll();

        foreach ($deadlines as &$d) {
            $d['id'] = (int)$d['id'];
            $d['clientId'] = (int)$d['clientId'];
            $d['invoiceId'] = $d['invoiceId'] ? (int)$d['invoiceId'] : null;
            $d['client'] = [
                'companyName' => $d['companyName'],
                'contactPerson' => $d['contactPerson']
            ];
            $d['invoice'] = $d['invoiceId'] ? ['invoiceNumber' => $d['invoiceNumber']] : null;
            unset($d['companyName'], $d['contactPerson'], $d['invoiceNumber']);
        }

        return Response::json($deadlines);
    }

    public function createDeadline(array $req) {
        $body = $req['body'];
        $clientId = isset($body['clientId']) ? (int)$body['clientId'] : 0;
        $invoiceId = isset($body['invoiceId']) ? (int)$body['invoiceId'] : null;
        $description = $body['description'] ?? '';
        $deadlineDate = $body['deadlineDate'] ?? '';

        if ($clientId <= 0 || !$description || !$deadlineDate) {
            return Response::error('Client ID, description, and deadline date are required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO `project_deadlines` (`clientId`, `invoiceId`, `description`, `deadlineDate`, `status`)
            VALUES (:clientId, :invoiceId, :description, :deadlineDate, 'pending')
        ");
        $stmt->execute([
            'clientId' => $clientId,
            'invoiceId' => $invoiceId ?: null,
            'description' => $description,
            'deadlineDate' => date('Y-m-d H:i:s', strtotime($deadlineDate))
        ]);

        $newId = (int)$db->lastInsertId();

        // Fetch client details
        $cStmt = $db->prepare("SELECT `companyName` FROM `clients` WHERE `id` = :id LIMIT 1");
        $cStmt->execute(['id' => $clientId]);
        $companyName = $cStmt->fetchColumn();

        $stmt = $db->prepare("SELECT * FROM `project_deadlines` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $newId]);
        $deadline = $stmt->fetch();
        $deadline['id'] = (int)$deadline['id'];
        $deadline['clientId'] = (int)$deadline['clientId'];
        $deadline['invoiceId'] = $deadline['invoiceId'] ? (int)$deadline['invoiceId'] : null;
        $deadline['client'] = ['companyName' => $companyName];

        return Response::json($deadline, 201);
    }

    public function updateDeadline(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid deadline ID', 400);
        }

        $body = $req['body'];
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `project_deadlines` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Deadline not found', 404);
        }

        $status = $body['status'] ?? $existing['status'];
        $description = $body['description'] ?? $existing['description'];
        $deadlineDate = $body['deadlineDate'] ? date('Y-m-d H:i:s', strtotime($body['deadlineDate'])) : $existing['deadlineDate'];

        $uStmt = $db->prepare("
            UPDATE `project_deadlines` 
            SET `status` = :status, `description` = :description, `deadlineDate` = :deadlineDate 
            WHERE `id` = :id
        ");
        $uStmt->execute([
            'status' => $status,
            'description' => $description,
            'deadlineDate' => $deadlineDate,
            'id' => $id
        ]);

        $stmt->execute(['id' => $id]);
        $updated = $stmt->fetch();
        $updated['id'] = (int)$updated['id'];
        $updated['clientId'] = (int)$updated['clientId'];
        $updated['invoiceId'] = $updated['invoiceId'] ? (int)$updated['invoiceId'] : null;

        return Response::json($updated);
    }

    public function deleteDeadline(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid deadline ID', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT `id` FROM `project_deadlines` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            return Response::error('Deadline not found', 404);
        }

        $del = $db->prepare("DELETE FROM `project_deadlines` WHERE `id` = :id");
        $del->execute(['id' => $id]);

        return Response::json(['message' => 'Deadline deleted successfully']);
    }
}
