<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

class DealerController {
    public function getDealers(array $req) {
        $query = $req['query'];
        $search = $query['search'] ?? '';
        $isActive = $query['isActive'] ?? null;

        $db = Database::getConnection();
        $sql = "SELECT * FROM `dealers` WHERE 1=1";
        $params = [];

        if ($isActive !== null) {
            $sql .= " AND `isActive` = :isActive";
            $params['isActive'] = ($isActive === 'true') ? 1 : 0;
        }

        if ($search) {
            $sql .= " AND (`name` LIKE :search 
                        OR `contactPerson` LIKE :search 
                        OR `phone` LIKE :search 
                        OR `email` LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY `name` ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $dealers = $stmt->fetchAll();

        foreach ($dealers as &$d) {
            $d['id'] = (int)$d['id'];
            $d['balanceDue'] = (float)$d['balanceDue'];
            $d['isActive'] = (bool)$d['isActive'];
        }

        return Response::json($dealers);
    }

    public function getDealerById(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid dealer ID', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `dealers` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $dealer = $stmt->fetch();

        if (!$dealer) {
            return Response::error('Dealer not found', 404);
        }

        $dealer['id'] = (int)$dealer['id'];
        $dealer['balanceDue'] = (float)$dealer['balanceDue'];
        $dealer['isActive'] = (bool)$dealer['isActive'];

        // Fetch items
        $iStmt = $db->prepare("SELECT * FROM `inventory_items` WHERE `dealerId` = :dealerId ORDER BY `name` ASC");
        $iStmt->execute(['dealerId' => $id]);
        $items = $iStmt->fetchAll();
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['dealerId'] = (int)$item['dealerId'];
            $item['stockQty'] = (int)$item['stockQty'];
            $item['reorderLevel'] = (int)$item['reorderLevel'];
            $item['isTracked'] = (bool)$item['isTracked'];
            $item['isActive'] = (bool)$item['isActive'];
        }
        $dealer['items'] = $items;

        // Fetch transactions (limit 20)
        $tStmt = $db->prepare("SELECT * FROM `dealer_transactions` WHERE `dealerId` = :dealerId ORDER BY `txnDate` DESC LIMIT 20");
        $tStmt->execute(['dealerId' => $id]);
        $transactions = $tStmt->fetchAll();
        foreach ($transactions as &$t) {
            $t['id'] = (int)$t['id'];
            $t['dealerId'] = (int)$t['dealerId'];
            $t['amount'] = (float)$t['amount'];
        }
        $dealer['transactions'] = $transactions;

        return Response::json($dealer);
    }

    public function createDealer(array $req) {
        $body = $req['body'];
        $name = $body['name'] ?? '';
        $contactPerson = $body['contactPerson'] ?? null;
        $phone = $body['phone'] ?? null;
        $email = $body['email'] ?? null;
        $address = $body['address'] ?? null;
        $paymentType = $body['paymentType'] ?? 'credit';
        $balanceDue = isset($body['balanceDue']) ? (float)$body['balanceDue'] : 0.0;

        if (!$name) {
            return Response::error('Dealer name is required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO `dealers` (`name`, `contactPerson`, `phone`, `email`, `address`, `paymentType`, `balanceDue`, `isActive`, `createdAt`, `updatedAt`)
            VALUES (:name, :contactPerson, :phone, :email, :address, :paymentType, :balanceDue, 1, NOW(), NOW())
        ");
        $stmt->execute([
            'name' => $name,
            'contactPerson' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'paymentType' => $paymentType,
            'balanceDue' => $balanceDue
        ]);

        $newId = (int)$db->lastInsertId();

        $stmt = $db->prepare("SELECT * FROM `dealers` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $newId]);
        $dealer = $stmt->fetch();
        $dealer['id'] = (int)$dealer['id'];
        $dealer['balanceDue'] = (float)$dealer['balanceDue'];
        $dealer['isActive'] = (bool)$dealer['isActive'];

        return Response::json($dealer, 201);
    }

    public function updateDealer(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid dealer ID', 400);
        }

        $body = $req['body'];
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `dealers` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Dealer not found', 404);
        }

        $name = $body['name'] ?? $existing['name'];
        $contactPerson = array_key_exists('contactPerson', $body) ? $body['contactPerson'] : $existing['contactPerson'];
        $phone = array_key_exists('phone', $body) ? $body['phone'] : $existing['phone'];
        $email = array_key_exists('email', $body) ? $body['email'] : $existing['email'];
        $address = array_key_exists('address', $body) ? $body['address'] : $existing['address'];
        $paymentType = $body['paymentType'] ?? $existing['paymentType'];
        $isActive = isset($body['isActive']) ? (bool)$body['isActive'] : (bool)$existing['isActive'];

        $uStmt = $db->prepare("
            UPDATE `dealers` 
            SET `name` = :name, `contactPerson` = :contactPerson, `phone` = :phone, 
                `email` = :email, `address` = :address, `paymentType` = :paymentType, 
                `isActive` = :isActive, `updatedAt` = NOW()
            WHERE `id` = :id
        ");
        $uStmt->execute([
            'name' => $name,
            'contactPerson' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'paymentType' => $paymentType,
            'isActive' => $isActive ? 1 : 0,
            'id' => $id
        ]);

        $stmt->execute(['id' => $id]);
        $updated = $stmt->fetch();
        $updated['id'] = (int)$updated['id'];
        $updated['balanceDue'] = (float)$updated['balanceDue'];
        $updated['isActive'] = (bool)$updated['isActive'];

        return Response::json($updated);
    }

    public function addTransaction(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid dealer ID', 400);
        }

        $body = $req['body'];
        $type = $body['type'] ?? '';
        $amount = $body['amount'] ?? null;
        $description = $body['description'] ?? null;
        $txnDate = $body['txnDate'] ?? null;

        if (!$type || $amount === null) {
            return Response::error('Transaction type and amount are required', 400);
        }

        if (!in_array($type, ['purchase', 'payment'])) {
            return Response::error('Transaction type must be "purchase" or "payment"', 400);
        }

        $amtVal = (float)$amount;
        if ($amtVal <= 0) {
            return Response::error('Amount must be a positive number', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `dealers` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $dealer = $stmt->fetch();

        if (!$dealer) {
            return Response::error('Dealer not found', 404);
        }

        $balanceChange = ($type === 'purchase') ? $amtVal : -$amtVal;
        $desc = $description ?: (($type === 'purchase') ? 'Purchased stock on credit' : 'Paid balance');
        $dateStr = $txnDate ? date('Y-m-d H:i:s', strtotime($txnDate)) : date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();

            // Update dealer balance due
            $uStmt = $db->prepare("UPDATE `dealers` SET `balanceDue` = `balanceDue` + :change WHERE `id` = :id");
            $uStmt->execute(['change' => $balanceChange, 'id' => $id]);

            // Add transaction log
            $tStmt = $db->prepare("
                INSERT INTO `dealer_transactions` (`dealerId`, `type`, `amount`, `description`, `txnDate`, `createdAt`)
                VALUES (:dealerId, :type, :amount, :description, :txnDate, NOW())
            ");
            $tStmt->execute([
                'dealerId' => $id,
                'type' => $type,
                'amount' => $amtVal,
                'description' => $desc,
                'txnDate' => $dateStr
            ]);

            $db->commit();

            // Fetch and return updated dealer
            $stmt->execute(['id' => $id]);
            $updated = $stmt->fetch();
            $updated['id'] = (int)$updated['id'];
            $updated['balanceDue'] = (float)$updated['balanceDue'];
            $updated['isActive'] = (bool)$updated['isActive'];

            return Response::json($updated);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function deleteDealer(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid dealer ID', 400);
        }

        $force = ($req['query']['force'] ?? '') === 'true';
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `dealers` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $dealer = $stmt->fetch();

        if (!$dealer) {
            return Response::error('Dealer not found', 404);
        }

        if ($force) {
            try {
                $delStmt = $db->prepare("DELETE FROM `dealers` WHERE `id` = :id");
                $delStmt->execute(['id' => $id]);
                return Response::json(['message' => 'Dealer permanently deleted']);
            } catch (\PDOException $e) {
                // Check foreign key constraint
                if ($e->getCode() == '23000' || strpos($e->getMessage(), '1451') !== false) {
                    return Response::error('Cannot delete dealer because they supply active inventory items or have historical transactions. Deactivate instead.', 400);
                }
                throw $e;
            }
        } else {
            $uStmt = $db->prepare("UPDATE `dealers` SET `isActive` = 0 WHERE `id` = :id");
            $uStmt->execute(['id' => $id]);
            return Response::json(['message' => 'Dealer deactivated successfully']);
        }
    }
}
