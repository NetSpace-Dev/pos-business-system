<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

class InventoryController {
    public function getItems(array $req) {
        $query = $req['query'];
        $search = $query['search'] ?? '';
        $category = $query['category'] ?? '';
        $lowStock = $query['lowStock'] ?? '';
        $isActive = $query['isActive'] ?? null;

        $db = Database::getConnection();
        
        $sql = "
            SELECT i.*, d.name as dealerName 
            FROM `inventory_items` i 
            LEFT JOIN `dealers` d ON i.dealerId = d.id 
            WHERE 1=1
        ";
        $params = [];

        if ($isActive !== null) {
            $sql .= " AND i.isActive = :isActive";
            $params['isActive'] = ($isActive === 'true') ? 1 : 0;
        }

        if ($category) {
            $sql .= " AND i.category = :category";
            $params['category'] = $category;
        }

        if ($search) {
            $sql .= " AND (i.sku LIKE :search OR i.name LIKE :search OR i.description LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY i.name ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        // Map related dealer object if present and apply types
        $result = [];
        foreach ($items as $item) {
            $item['id'] = (int)$item['id'];
            $item['dealerId'] = $item['dealerId'] ? (int)$item['dealerId'] : null;
            $item['stockQty'] = (int)$item['stockQty'];
            $item['reorderLevel'] = (int)$item['reorderLevel'];
            $item['warrantyMonths'] = $item['warrantyMonths'] !== null ? (int)$item['warrantyMonths'] : null;
            $item['licenseCount'] = $item['licenseCount'] !== null ? (int)$item['licenseCount'] : null;
            $item['isTracked'] = (bool)$item['isTracked'];
            $item['isActive'] = (bool)$item['isActive'];
            
            // Map relation structure
            $item['dealer'] = $item['dealerId'] ? ['name' => $item['dealerName']] : null;
            unset($item['dealerName']);

            // Low stock filter in memory
            if ($lowStock === 'true') {
                if (!$item['isTracked'] || $item['stockQty'] > $item['reorderLevel']) {
                    continue;
                }
            }

            $result[] = $item;
        }

        return Response::json($result);
    }

    public function getItemById(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid item ID', 400);
        }

        $db = Database::getConnection();
        
        // Fetch Item
        $stmt = $db->prepare("SELECT * FROM `inventory_items` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $item = $stmt->fetch();

        if (!$item) {
            return Response::error('Item not found', 404);
        }

        $item['id'] = (int)$item['id'];
        $item['dealerId'] = $item['dealerId'] ? (int)$item['dealerId'] : null;
        $item['stockQty'] = (int)$item['stockQty'];
        $item['reorderLevel'] = (int)$item['reorderLevel'];
        $item['warrantyMonths'] = $item['warrantyMonths'] !== null ? (int)$item['warrantyMonths'] : null;
        $item['licenseCount'] = $item['licenseCount'] !== null ? (int)$item['licenseCount'] : null;
        $item['isTracked'] = (bool)$item['isTracked'];
        $item['isActive'] = (bool)$item['isActive'];

        // Fetch Dealer
        $item['dealer'] = null;
        if ($item['dealerId']) {
            $dStmt = $db->prepare("SELECT * FROM `dealers` WHERE `id` = :dealerId LIMIT 1");
            $dStmt->execute(['dealerId' => $item['dealerId']]);
            $item['dealer'] = $dStmt->fetch() ?: null;
            if ($item['dealer']) {
                $item['dealer']['id'] = (int)$item['dealer']['id'];
                $item['dealer']['isActive'] = (bool)$item['dealer']['isActive'];
            }
        }

        // Fetch Stock Movements (limit 15)
        $smStmt = $db->prepare("SELECT * FROM `stock_movements` WHERE `itemId` = :itemId ORDER BY `createdAt` DESC LIMIT 15");
        $smStmt->execute(['itemId' => $id]);
        $movements = $smStmt->fetchAll();
        foreach ($movements as &$m) {
            $m['id'] = (int)$m['id'];
            $m['itemId'] = (int)$m['itemId'];
            $m['quantity'] = (int)$m['quantity'];
        }
        $item['stockMovements'] = $movements;

        return Response::json($item);
    }

    public function createItem(array $req) {
        $body = $req['body'];
        $sku = $body['sku'] ?? '';
        $name = $body['name'] ?? '';
        $category = $body['category'] ?? '';
        $description = $body['description'] ?? null;
        $dealerId = isset($body['dealerId']) ? (int)$body['dealerId'] : null;
        $stockQty = isset($body['stockQty']) ? (int)$body['stockQty'] : 0;
        $reorderLevel = isset($body['reorderLevel']) ? (int)$body['reorderLevel'] : 5;
        $unitCost = isset($body['unitCost']) ? (float)$body['unitCost'] : null;
        $sellPrice = isset($body['sellPrice']) ? (float)$body['sellPrice'] : null;
        $warrantyMonths = isset($body['warrantyMonths']) ? (int)$body['warrantyMonths'] : null;
        $isTracked = isset($body['isTracked']) ? (bool)$body['isTracked'] : true;

        if (!$sku || !$name || !$category || $unitCost === null || $sellPrice === null) {
            return Response::error('SKU, name, category, unit cost, and sell price are required', 400);
        }

        $db = Database::getConnection();

        // Check unique SKU
        $stmt = $db->prepare("SELECT `id` FROM `inventory_items` WHERE `sku` = :sku LIMIT 1");
        $stmt->execute(['sku' => $sku]);
        if ($stmt->fetch()) {
            return Response::error('An item with this SKU already exists', 400);
        }

        try {
            $db->beginTransaction();

            $iStmt = $db->prepare("
                INSERT INTO `inventory_items` (
                    `sku`, `name`, `category`, `description`, `dealerId`, `stockQty`, 
                    `reorderLevel`, `unitCost`, `sellPrice`, `warrantyMonths`, `isTracked`, `isActive`, `createdAt`, `updatedAt`
                ) VALUES (
                    :sku, :name, :category, :description, :dealerId, :stockQty, 
                    :reorderLevel, :unitCost, :sellPrice, :warrantyMonths, :isTracked, 1, NOW(), NOW()
                )
            ");
            $iStmt->execute([
                'sku' => $sku,
                'name' => $name,
                'category' => $category,
                'description' => $description,
                'dealerId' => $dealerId ?: null,
                'stockQty' => $stockQty,
                'reorderLevel' => $reorderLevel,
                'unitCost' => $unitCost,
                'sellPrice' => $sellPrice,
                'warrantyMonths' => $warrantyMonths,
                'isTracked' => $isTracked ? 1 : 0
            ]);

            $newItemId = (int)$db->lastInsertId();

            if ($stockQty > 0 && $isTracked) {
                $mStmt = $db->prepare("
                    INSERT INTO `stock_movements` (`itemId`, `type`, `quantity`, `reason`, `createdAt`)
                    VALUES (:itemId, 'in', :quantity, 'Initial stock load upon creation', NOW())
                ");
                $mStmt->execute([
                    'itemId' => $newItemId,
                    'quantity' => $stockQty
                ]);
            }

            $db->commit();

            // Fetch and return new item
            $stmt = $db->prepare("SELECT * FROM `inventory_items` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $newItemId]);
            $item = $stmt->fetch();
            $item['id'] = (int)$item['id'];
            $item['dealerId'] = $item['dealerId'] ? (int)$item['dealerId'] : null;
            $item['stockQty'] = (int)$item['stockQty'];
            $item['reorderLevel'] = (int)$item['reorderLevel'];
            $item['warrantyMonths'] = $item['warrantyMonths'] !== null ? (int)$item['warrantyMonths'] : null;
            $item['isTracked'] = (bool)$item['isTracked'];
            $item['isActive'] = (bool)$item['isActive'];

            return Response::json($item, 201);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function updateItem(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid item ID', 400);
        }

        $body = $req['body'];
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `inventory_items` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Item not found', 404);
        }

        $sku = $body['sku'] ?? $existing['sku'];
        $name = $body['name'] ?? $existing['name'];
        $category = $body['category'] ?? $existing['category'];
        $description = array_key_exists('description', $body) ? $body['description'] : $existing['description'];
        $dealerId = array_key_exists('dealerId', $body) ? ($body['dealerId'] ? (int)$body['dealerId'] : null) : $existing['dealerId'];
        $reorderLevel = isset($body['reorderLevel']) ? (int)$body['reorderLevel'] : (int)$existing['reorderLevel'];
        $unitCost = isset($body['unitCost']) ? (float)$body['unitCost'] : (float)$existing['unitCost'];
        $sellPrice = isset($body['sellPrice']) ? (float)$body['sellPrice'] : (float)$existing['sellPrice'];
        $warrantyMonths = array_key_exists('warrantyMonths', $body) ? ($body['warrantyMonths'] ? (int)$body['warrantyMonths'] : null) : $existing['warrantyMonths'];
        $isTracked = isset($body['isTracked']) ? (bool)$body['isTracked'] : (bool)$existing['isTracked'];
        $isActive = isset($body['isActive']) ? (bool)$body['isActive'] : (bool)$existing['isActive'];

        if ($sku !== $existing['sku']) {
            $conflictStmt = $db->prepare("SELECT `id` FROM `inventory_items` WHERE `sku` = :sku LIMIT 1");
            $conflictStmt->execute(['sku' => $sku]);
            if ($conflictStmt->fetch()) {
                return Response::error('Another item with this SKU already exists', 400);
            }
        }

        $uStmt = $db->prepare("
            UPDATE `inventory_items` 
            SET `sku` = :sku, `name` = :name, `category` = :category, `description` = :description,
                `dealerId` = :dealerId, `reorderLevel` = :reorderLevel, `unitCost` = :unitCost,
                `sellPrice` = :sellPrice, `warrantyMonths` = :warrantyMonths, `isTracked` = :isTracked,
                `isActive` = :isActive, `updatedAt` = NOW()
            WHERE `id` = :id
        ");
        $uStmt->execute([
            'sku' => $sku,
            'name' => $name,
            'category' => $category,
            'description' => $description,
            'dealerId' => $dealerId,
            'reorderLevel' => $reorderLevel,
            'unitCost' => $unitCost,
            'sellPrice' => $sellPrice,
            'warrantyMonths' => $warrantyMonths,
            'isTracked' => $isTracked ? 1 : 0,
            'isActive' => $isActive ? 1 : 0,
            'id' => $id
        ]);

        // Return updated item
        $stmt->execute(['id' => $id]);
        $updated = $stmt->fetch();
        $updated['id'] = (int)$updated['id'];
        $updated['dealerId'] = $updated['dealerId'] ? (int)$updated['dealerId'] : null;
        $updated['stockQty'] = (int)$updated['stockQty'];
        $updated['reorderLevel'] = (int)$updated['reorderLevel'];
        $updated['warrantyMonths'] = $updated['warrantyMonths'] !== null ? (int)$updated['warrantyMonths'] : null;
        $updated['isTracked'] = (bool)$updated['isTracked'];
        $updated['isActive'] = (bool)$updated['isActive'];

        return Response::json($updated);
    }

    public function adjustStock(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid item ID', 400);
        }

        $body = $req['body'];
        $type = $body['type'] ?? '';
        $quantity = $body['quantity'] ?? null;
        $reason = $body['reason'] ?? 'Manual stock adjustment';

        if (!$type || $quantity === null) {
            return Response::error('Stock adjustment type and quantity are required', 400);
        }

        if (!in_array($type, ['in', 'out', 'adjustment'])) {
            return Response::error('Invalid type. Must be "in", "out" or "adjustment"', 400);
        }

        $qtyVal = (int)$quantity;
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `inventory_items` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $item = $stmt->fetch();

        if (!$item) {
            return Response::error('Item not found', 404);
        }

        $qtyChange = 0;
        if ($type === 'in') {
            $qtyChange = $qtyVal;
        } else if ($type === 'out') {
            $qtyChange = -$qtyVal;
        } else if ($type === 'adjustment') {
            $qtyChange = $qtyVal;
        }

        try {
            $db->beginTransaction();

            // Update item stockQty
            $uStmt = $db->prepare("UPDATE `inventory_items` SET `stockQty` = `stockQty` + :change WHERE `id` = :id");
            $uStmt->execute(['change' => $qtyChange, 'id' => $id]);

            // Log movement
            $mStmt = $db->prepare("
                INSERT INTO `stock_movements` (`itemId`, `type`, `quantity`, `reason`, `createdAt`)
                VALUES (:itemId, :type, :quantity, :reason, NOW())
            ");
            $mStmt->execute([
                'itemId' => $id,
                'type' => $type,
                'quantity' => abs($qtyChange),
                'reason' => $reason
            ]);

            $db->commit();

            // Return updated item
            $stmt->execute(['id' => $id]);
            $updated = $stmt->fetch();
            $updated['id'] = (int)$updated['id'];
            $updated['dealerId'] = $updated['dealerId'] ? (int)$updated['dealerId'] : null;
            $updated['stockQty'] = (int)$updated['stockQty'];
            $updated['reorderLevel'] = (int)$updated['reorderLevel'];
            $updated['warrantyMonths'] = $updated['warrantyMonths'] !== null ? (int)$updated['warrantyMonths'] : null;
            $updated['isTracked'] = (bool)$updated['isTracked'];
            $updated['isActive'] = (bool)$updated['isActive'];

            return Response::json($updated);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function deleteItem(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid item ID', 400);
        }

        $force = ($req['query']['force'] ?? '') === 'true';
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `inventory_items` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $item = $stmt->fetch();

        if (!$item) {
            return Response::error('Item not found', 404);
        }

        if ($force) {
            try {
                $delStmt = $db->prepare("DELETE FROM `inventory_items` WHERE `id` = :id");
                $delStmt->execute(['id' => $id]);
                return Response::json(['message' => 'Item deleted permanently']);
            } catch (\PDOException $e) {
                // Check foreign key constraint
                if ($e->getCode() == '23000' || strpos($e->getMessage(), '1451') !== false) {
                    return Response::error('Cannot delete item because it is referenced in quotations or invoices. Deactivate it instead.', 400);
                }
                throw $e;
            }
        } else {
            $uStmt = $db->prepare("UPDATE `inventory_items` SET `isActive` = 0 WHERE `id` = :id");
            $uStmt->execute(['id' => $id]);
            return Response::json(['message' => 'Item deactivated successfully']);
        }
    }
}
