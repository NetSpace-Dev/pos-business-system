<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

class RecurringInvoiceController {
    public function getRecurringConfigs(array $req) {
        $query = $req['query'];
        $clientId = $query['clientId'] ?? '';
        $isActive = $query['isActive'] ?? null;

        $db = Database::getConnection();
        $sql = "
            SELECT ri.*, c.companyName, c.contactPerson 
            FROM `recurring_invoices` ri 
            JOIN `clients` c ON ri.clientId = c.id 
            WHERE 1=1
        ";
        $params = [];

        if ($clientId) {
            $sql .= " AND ri.clientId = :clientId";
            $params['clientId'] = (int)$clientId;
        }

        if ($isActive !== null) {
            $sql .= " AND ri.isActive = :isActive";
            $params['isActive'] = ($isActive === 'true') ? 1 : 0;
        }

        $sql .= " ORDER BY ri.createdAt DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $configs = $stmt->fetchAll();

        foreach ($configs as &$ri) {
            $ri['id'] = (int)$ri['id'];
            $ri['clientId'] = (int)$ri['clientId'];
            $ri['amount'] = (float)$ri['amount'];
            $ri['isActive'] = (bool)$ri['isActive'];
            $ri['client'] = [
                'companyName' => $ri['companyName'],
                'contactPerson' => $ri['contactPerson']
            ];
            unset($ri['companyName'], $ri['contactPerson']);
        }

        return Response::json($configs);
    }

    public function getRecurringConfigById(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid config ID', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `recurring_invoices` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $config = $stmt->fetch();

        if (!$config) {
            return Response::error('Recurring config not found', 404);
        }

        $config['id'] = (int)$config['id'];
        $config['clientId'] = (int)$config['clientId'];
        $config['amount'] = (float)$config['amount'];
        $config['isActive'] = (bool)$config['isActive'];

        // Fetch client
        $cStmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :clientId LIMIT 1");
        $cStmt->execute(['clientId' => $config['clientId']]);
        $config['client'] = $cStmt->fetch() ?: null;
        if ($config['client']) {
            $config['client']['id'] = (int)$config['client']['id'];
            $config['client']['isActive'] = (bool)$config['client']['isActive'];
        }

        // Fetch generated invoices (limit 10)
        $iStmt = $db->prepare("SELECT * FROM `invoices` WHERE `recurringInvoiceId` = :riId ORDER BY `issueDate` DESC LIMIT 10");
        $iStmt->execute(['riId' => $id]);
        $invoices = $iStmt->fetchAll();
        foreach ($invoices as &$inv) {
            $inv['id'] = (int)$inv['id'];
            $inv['clientId'] = (int)$inv['clientId'];
            $inv['quotationId'] = $inv['quotationId'] ? (int)$inv['quotationId'] : null;
            $inv['recurringInvoiceId'] = $inv['recurringInvoiceId'] ? (int)$inv['recurringInvoiceId'] : null;
            $inv['subtotal'] = (float)$inv['subtotal'];
            $inv['taxAmount'] = (float)$inv['taxAmount'];
            $inv['totalAmount'] = (float)$inv['totalAmount'];
            $inv['amountPaid'] = (float)$inv['amountPaid'];
            $inv['isRecurringGenerated'] = (bool)$inv['isRecurringGenerated'];
        }
        $config['generatedInvoices'] = $invoices;

        return Response::json($config);
    }

    public function createRecurringConfig(array $req) {
        $body = $req['body'];
        $clientId = isset($body['clientId']) ? (int)$body['clientId'] : 0;
        $title = $body['title'] ?? '';
        $description = $body['description'] ?? null;
        $amount = isset($body['amount']) ? (float)$body['amount'] : null;
        $frequency = $body['frequency'] ?? '';
        $startDate = $body['startDate'] ?? '';
        $endDate = $body['endDate'] ?? null;

        if ($clientId <= 0 || !$title || $amount === null || !$frequency || !$startDate) {
            return Response::error('Client ID, title, amount, frequency, and start date are required', 400);
        }

        if (!in_array($frequency, ['monthly', 'quarterly', 'yearly'])) {
            return Response::error('Frequency must be "monthly", "quarterly", or "yearly"', 400);
        }

        $db = Database::getConnection();
        
        // Client check
        $cStmt = $db->prepare("SELECT `id` FROM `clients` WHERE `id` = :id LIMIT 1");
        $cStmt->execute(['id' => $clientId]);
        if (!$cStmt->fetch()) {
            return Response::error('Client not found', 404);
        }

        $startDateStr = date('Y-m-d H:i:s', strtotime($startDate));
        $endDateStr = $endDate ? date('Y-m-d H:i:s', strtotime($endDate)) : null;

        $stmt = $db->prepare("
            INSERT INTO `recurring_invoices` (
                `clientId`, `title`, `description`, `amount`, `frequency`, 
                `startDate`, `nextRunDate`, `endDate`, `isActive`, `createdAt`, `updatedAt`
            ) VALUES (
                :clientId, :title, :description, :amount, :frequency, 
                :startDate, :startDate, :endDate, 1, NOW(), NOW()
            )
        ");
        $stmt->execute([
            'clientId' => $clientId,
            'title' => $title,
            'description' => $description,
            'amount' => $amount,
            'frequency' => $frequency,
            'startDate' => $startDateStr,
            'endDate' => $endDateStr
        ]);

        $newId = (int)$db->lastInsertId();

        $stmt = $db->prepare("SELECT * FROM `recurring_invoices` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $newId]);
        $config = $stmt->fetch();
        $config['id'] = (int)$config['id'];
        $config['clientId'] = (int)$config['clientId'];
        $config['amount'] = (float)$config['amount'];
        $config['isActive'] = (bool)$config['isActive'];

        return Response::json($config, 201);
    }

    public function updateRecurringConfig(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid config ID', 400);
        }

        $body = $req['body'];
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `recurring_invoices` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Recurring config not found', 404);
        }

        $frequency = $body['frequency'] ?? $existing['frequency'];
        if (!in_array($frequency, ['monthly', 'quarterly', 'yearly'])) {
            return Response::error('Frequency must be "monthly", "quarterly", or "yearly"', 400);
        }

        $title = $body['title'] ?? $existing['title'];
        $description = array_key_exists('description', $body) ? $body['description'] : $existing['description'];
        $amount = isset($body['amount']) ? (float)$body['amount'] : (float)$existing['amount'];
        $startDate = $body['startDate'] ? date('Y-m-d H:i:s', strtotime($body['startDate'])) : $existing['startDate'];
        $nextRunDate = $body['nextRunDate'] ? date('Y-m-d H:i:s', strtotime($body['nextRunDate'])) : $existing['nextRunDate'];
        $endDate = array_key_exists('endDate', $body) ? ($body['endDate'] ? date('Y-m-d H:i:s', strtotime($body['endDate'])) : null) : $existing['endDate'];
        $isActive = isset($body['isActive']) ? (bool)$body['isActive'] : (bool)$existing['isActive'];

        $uStmt = $db->prepare("
            UPDATE `recurring_invoices` 
            SET `title` = :title, `description` = :description, `amount` = :amount, 
                `frequency` = :frequency, `startDate` = :startDate, `nextRunDate` = :nextRunDate, 
                `endDate` = :endDate, `isActive` = :isActive, `updatedAt` = NOW()
            WHERE `id` = :id
        ");
        $uStmt->execute([
            'title' => $title,
            'description' => $description,
            'amount' => $amount,
            'frequency' => $frequency,
            'startDate' => $startDate,
            'nextRunDate' => $nextRunDate,
            'endDate' => $endDate,
            'isActive' => $isActive ? 1 : 0,
            'id' => $id
        ]);

        $stmt->execute(['id' => $id]);
        $updated = $stmt->fetch();
        $updated['id'] = (int)$updated['id'];
        $updated['clientId'] = (int)$updated['clientId'];
        $updated['amount'] = (float)$updated['amount'];
        $updated['isActive'] = (bool)$updated['isActive'];

        return Response::json($updated);
    }

    public function deleteRecurringConfig(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid config ID', 400);
        }

        $force = ($req['query']['force'] ?? '') === 'true';
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `recurring_invoices` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Recurring config not found', 404);
        }

        if ($force) {
            try {
                $delStmt = $db->prepare("DELETE FROM `recurring_invoices` WHERE `id` = :id");
                $delStmt->execute(['id' => $id]);
                return Response::json(['message' => 'Recurring configuration deleted permanently']);
            } catch (\PDOException $e) {
                if ($e->getCode() == '23000' || strpos($e->getMessage(), '1451') !== false) {
                    return Response::error('Cannot delete configuration due to existing generated invoices. Deactivate it instead.', 400);
                }
                throw $e;
            }
        } else {
            $uStmt = $db->prepare("UPDATE `recurring_invoices` SET `isActive` = 0 WHERE `id` = :id");
            $uStmt->execute(['id' => $id]);
            return Response::json(['message' => 'Recurring configuration deactivated successfully']);
        }
    }
}
