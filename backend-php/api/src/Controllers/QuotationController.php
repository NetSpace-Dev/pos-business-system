<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use App\Utils\DocNumber;
use PDO;

class QuotationController {
    public function getQuotations(array $req) {
        $query = $req['query'];
        $status = $query['status'] ?? '';
        $clientId = $query['clientId'] ?? '';

        $db = Database::getConnection();
        $sql = "
            SELECT q.*, c.companyName, c.contactPerson 
            FROM `quotations` q 
            JOIN `clients` c ON q.clientId = c.id 
            WHERE 1=1
        ";
        $params = [];

        if ($status) {
            $sql .= " AND q.status = :status";
            $params['status'] = $status;
        }

        if ($clientId) {
            $sql .= " AND q.clientId = :clientId";
            $params['clientId'] = (int)$clientId;
        }

        $sql .= " ORDER BY q.createdAt DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $quotes = $stmt->fetchAll();

        foreach ($quotes as &$q) {
            $q['id'] = (int)$q['id'];
            $q['clientId'] = (int)$q['clientId'];
            $q['version'] = (int)$q['version'];
            $q['subtotal'] = (float)$q['subtotal'];
            $q['taxAmount'] = (float)$q['taxAmount'];
            $q['totalAmount'] = (float)$q['totalAmount'];
            $q['client'] = [
                'companyName' => $q['companyName'],
                'contactPerson' => $q['contactPerson']
            ];
            unset($q['companyName'], $q['contactPerson']);
        }

        return Response::json($quotes);
    }

    public function getQuotationById(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid quotation ID', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `quotations` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $quotation = $stmt->fetch();

        if (!$quotation) {
            return Response::error('Quotation not found', 404);
        }

        $quotation['id'] = (int)$quotation['id'];
        $quotation['clientId'] = (int)$quotation['clientId'];
        $quotation['version'] = (int)$quotation['version'];
        $quotation['subtotal'] = (float)$quotation['subtotal'];
        $quotation['taxAmount'] = (float)$quotation['taxAmount'];
        $quotation['totalAmount'] = (float)$quotation['totalAmount'];

        // Fetch client
        $cStmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :clientId LIMIT 1");
        $cStmt->execute(['clientId' => $quotation['clientId']]);
        $quotation['client'] = $cStmt->fetch() ?: null;
        if ($quotation['client']) {
            $quotation['client']['id'] = (int)$quotation['client']['id'];
            $quotation['client']['isActive'] = (bool)$quotation['client']['isActive'];
        }

        // Fetch items and resolve item
        $iStmt = $db->prepare("
            SELECT qi.*, ii.sku, ii.name as itemName, ii.category, ii.unitCost, ii.sellPrice, ii.isTracked, ii.isActive as itemIsActive
            FROM `quotation_items` qi 
            LEFT JOIN `inventory_items` ii ON qi.itemId = ii.id 
            WHERE qi.quotationId = :quotationId
        ");
        $iStmt->execute(['quotationId' => $id]);
        $items = $iStmt->fetchAll();
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['quotationId'] = (int)$item['quotationId'];
            $item['itemId'] = $item['itemId'] ? (int)$item['itemId'] : null;
            $item['quantity'] = (int)$item['quantity'];
            $item['unitPrice'] = (float)$item['unitPrice'];
            $item['lineTotal'] = (float)$item['lineTotal'];

            $item['item'] = null;
            if ($item['itemId']) {
                $item['item'] = [
                    'id' => $item['itemId'],
                    'sku' => $item['sku'],
                    'name' => $item['itemName'],
                    'category' => $item['category'],
                    'unitCost' => (float)$item['unitCost'],
                    'sellPrice' => (float)$item['sellPrice'],
                    'isTracked' => (bool)$item['isTracked'],
                    'isActive' => (bool)$item['itemIsActive']
                ];
            }
            // Cleanup flat fields
            unset($item['sku'], $item['itemName'], $item['category'], $item['unitCost'], $item['sellPrice'], $item['isTracked'], $item['itemIsActive']);
        }
        $quotation['items'] = $items;

        // Fetch related invoice
        $invStmt = $db->prepare("SELECT `id`, `invoiceNumber`, `status` FROM `invoices` WHERE `quotationId` = :quotationId LIMIT 1");
        $invStmt->execute(['quotationId' => $id]);
        $invoice = $invStmt->fetch();
        if ($invoice) {
            $invoice['id'] = (int)$invoice['id'];
        }
        $quotation['invoice'] = $invoice ?: null;

        return Response::json($quotation);
    }

    public function createQuotation(array $req) {
        $body = $req['body'];
        $clientId = isset($body['clientId']) ? (int)$body['clientId'] : 0;
        $validUntil = $body['validUntil'] ?? '';
        $items = $body['items'] ?? null;
        $terms = $body['terms'] ?? null;
        $taxAmount = isset($body['taxAmount']) ? (float)$body['taxAmount'] : 0.0;

        if ($clientId <= 0 || !$validUntil || !$items || !is_array($items) || count($items) === 0) {
            return Response::error('Client ID, validity date, and quotation items are required', 400);
        }

        $db = Database::getConnection();

        // Check client exists
        $cStmt = $db->prepare("SELECT `id` FROM `clients` WHERE `id` = :clientId LIMIT 1");
        $cStmt->execute(['clientId' => $clientId]);
        if (!$cStmt->fetch()) {
            return Response::error('Client not found', 404);
        }

        $currentYear = date('Y');
        $yearStart = "$currentYear-01-01 00:00:00";
        $yearEnd = "$currentYear-12-31 23:59:59";

        // Calculate subtotal and construct items data
        $subtotal = 0.0;
        $itemsData = [];
        foreach ($items as $item) {
            $qty = isset($item['quantity']) ? (int)$item['quantity'] : 1;
            $unitPrice = isset($item['unitPrice']) ? (float)$item['unitPrice'] : 0.0;
            $lineTotal = $qty * $unitPrice;
            $subtotal += $lineTotal;

            $itemsData[] = [
                'itemId' => !empty($item['itemId']) ? (int)$item['itemId'] : null,
                'description' => $item['description'] ?? '',
                'quantity' => $qty,
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineTotal
            ];
        }

        $totalAmount = $subtotal + $taxAmount;
        $validUntilDate = date('Y-m-d H:i:s', strtotime($validUntil));

        try {
            $db->beginTransaction();

            // Count quotations in current year
            $countStmt = $db->prepare("SELECT COUNT(*) FROM `quotations` WHERE `issueDate` >= :yearStart AND `issueDate` <= :yearEnd");
            $countStmt->execute(['yearStart' => $yearStart, 'yearEnd' => $yearEnd]);
            $count = (int)$countStmt->fetchColumn();

            $quoteNumber = DocNumber::generate('QT', $count + 1);

            $qStmt = $db->prepare("
                INSERT INTO `quotations` (`quoteNumber`, `clientId`, `issueDate`, `validUntil`, `status`, `subtotal`, `taxAmount`, `totalAmount`, `terms`, `version`, `createdAt`, `updatedAt`)
                VALUES (:quoteNumber, :clientId, NOW(), :validUntil, 'draft', :subtotal, :taxAmount, :totalAmount, :terms, 1, NOW(), NOW())
            ");
            $qStmt->execute([
                'quoteNumber' => $quoteNumber,
                'clientId' => $clientId,
                'validUntil' => $validUntilDate,
                'subtotal' => $subtotal,
                'taxAmount' => $taxAmount,
                'totalAmount' => $totalAmount,
                'terms' => $terms
            ]);

            $newQuoteId = (int)$db->lastInsertId();

            // Insert quotation items
            $qiStmt = $db->prepare("
                INSERT INTO `quotation_items` (`quotationId`, `itemId`, `description`, `quantity`, `unitPrice`, `lineTotal`)
                VALUES (:quotationId, :itemId, :description, :quantity, :unitPrice, :lineTotal)
            ");
            foreach ($itemsData as $item) {
                $qiStmt->execute([
                    'quotationId' => $newQuoteId,
                    'itemId' => $item['itemId'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unitPrice' => $item['unitPrice'],
                    'lineTotal' => $item['lineTotal']
                ]);
            }

            $db->commit();

            // Return new quotation details
            $stmt = $db->prepare("SELECT * FROM `quotations` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $newQuoteId]);
            $created = $stmt->fetch();
            $created['id'] = (int)$created['id'];
            $created['clientId'] = (int)$created['clientId'];
            $created['version'] = (int)$created['version'];
            $created['subtotal'] = (float)$created['subtotal'];
            $created['taxAmount'] = (float)$created['taxAmount'];
            $created['totalAmount'] = (float)$created['totalAmount'];

            // Fetch and attach items
            $itemsStmt = $db->prepare("SELECT * FROM `quotation_items` WHERE `quotationId` = :quotationId");
            $itemsStmt->execute(['quotationId' => $newQuoteId]);
            $createdItems = $itemsStmt->fetchAll();
            foreach ($createdItems as &$ci) {
                $ci['id'] = (int)$ci['id'];
                $ci['quotationId'] = (int)$ci['quotationId'];
                $ci['itemId'] = $ci['itemId'] ? (int)$ci['itemId'] : null;
                $ci['quantity'] = (int)$ci['quantity'];
                $ci['unitPrice'] = (float)$ci['unitPrice'];
                $ci['lineTotal'] = (float)$ci['lineTotal'];
            }
            $created['items'] = $createdItems;

            return Response::json($created, 201);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function updateQuotation(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid quotation ID', 400);
        }

        $body = $req['body'];
        $validUntil = $body['validUntil'] ?? null;
        $items = $body['items'] ?? null;
        $terms = $body['terms'] ?? null;
        $taxAmount = isset($body['taxAmount']) ? (float)$body['taxAmount'] : null;
        $status = $body['status'] ?? null;

        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT * FROM `quotations` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Quotation not found', 404);
        }

        if ($existing['status'] === 'converted') {
            return Response::error('Cannot modify a quotation that has already been converted to an invoice', 400);
        }

        $subtotal = (float)$existing['subtotal'];
        $tax = ($taxAmount !== null) ? $taxAmount : (float)$existing['taxAmount'];
        $totalAmount = (float)$existing['totalAmount'];
        $itemsData = [];

        if ($items && is_array($items)) {
            $subtotal = 0.0;
            foreach ($items as $item) {
                $qty = isset($item['quantity']) ? (int)$item['quantity'] : 1;
                $unitPrice = isset($item['unitPrice']) ? (float)$item['unitPrice'] : 0.0;
                $lineTotal = $qty * $unitPrice;
                $subtotal += $lineTotal;

                $itemsData[] = [
                    'itemId' => !empty($item['itemId']) ? (int)$item['itemId'] : null,
                    'description' => $item['description'] ?? '',
                    'quantity' => $qty,
                    'unitPrice' => $unitPrice,
                    'lineTotal' => $lineTotal
                ];
            }
            $totalAmount = $subtotal + $tax;
        } else if ($taxAmount !== null) {
            $totalAmount = $subtotal + $tax;
        }

        $validUntilDate = $validUntil ? date('Y-m-d H:i:s', strtotime($validUntil)) : $existing['validUntil'];
        $statusVal = $status ?: $existing['status'];
        $termsVal = ($terms !== null) ? $terms : $existing['terms'];

        try {
            $db->beginTransaction();

            if ($items && is_array($items)) {
                // Delete old items
                $delItemsStmt = $db->prepare("DELETE FROM `quotation_items` WHERE `quotationId` = :quotationId");
                $delItemsStmt->execute(['quotationId' => $id]);

                // Create new items
                $insItemsStmt = $db->prepare("
                    INSERT INTO `quotation_items` (`quotationId`, `itemId`, `description`, `quantity`, `unitPrice`, `lineTotal`)
                    VALUES (:quotationId, :itemId, :description, :quantity, :unitPrice, :lineTotal)
                ");
                foreach ($itemsData as $item) {
                    $insItemsStmt->execute([
                        'quotationId' => $id,
                        'itemId' => $item['itemId'],
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unitPrice' => $item['unitPrice'],
                        'lineTotal' => $item['lineTotal']
                    ]);
                }
            }

            // Update main quotation record
            $updStmt = $db->prepare("
                UPDATE `quotations` 
                SET `validUntil` = :validUntil, `status` = :status, `subtotal` = :subtotal, 
                    `taxAmount` = :taxAmount, `totalAmount` = :totalAmount, `terms` = :terms, 
                    `version` = `version` + 1, `updatedAt` = NOW()
                WHERE `id` = :id
            ");
            $updStmt->execute([
                'validUntil' => $validUntilDate,
                'status' => $statusVal,
                'subtotal' => $subtotal,
                'taxAmount' => $tax,
                'totalAmount' => $totalAmount,
                'terms' => $termsVal,
                'id' => $id
            ]);

            $db->commit();

            // Fetch and return the updated quotation with items
            $stmt->execute(['id' => $id]);
            $updated = $stmt->fetch();
            $updated['id'] = (int)$updated['id'];
            $updated['clientId'] = (int)$updated['clientId'];
            $updated['version'] = (int)$updated['version'];
            $updated['subtotal'] = (float)$updated['subtotal'];
            $updated['taxAmount'] = (float)$updated['taxAmount'];
            $updated['totalAmount'] = (float)$updated['totalAmount'];

            $itemsStmt = $db->prepare("SELECT * FROM `quotation_items` WHERE `quotationId` = :quotationId");
            $itemsStmt->execute(['quotationId' => $id]);
            $updatedItems = $itemsStmt->fetchAll();
            foreach ($updatedItems as &$ui) {
                $ui['id'] = (int)$ui['id'];
                $ui['quotationId'] = (int)$ui['quotationId'];
                $ui['itemId'] = $ui['itemId'] ? (int)$ui['itemId'] : null;
                $ui['quantity'] = (int)$ui['quantity'];
                $ui['unitPrice'] = (float)$ui['unitPrice'];
                $ui['lineTotal'] = (float)$ui['lineTotal'];
            }
            $updated['items'] = $updatedItems;

            return Response::json($updated);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function convertToInvoice(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid quotation ID', 400);
        }

        $body = $req['body'];
        $dueDate = $body['dueDate'] ?? null;

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `quotations` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $quotation = $stmt->fetch();

        if (!$quotation) {
            return Response::error('Quotation not found', 404);
        }

        if ($quotation['status'] === 'converted') {
            return Response::error('Quotation has already been converted to an invoice', 400);
        }

        $currentYear = date('Y');
        $yearStart = "$currentYear-01-01 00:00:00";
        $yearEnd = "$currentYear-12-31 23:59:59";

        $invoiceDueDate = $dueDate ? date('Y-m-d H:i:s', strtotime($dueDate)) : date('Y-m-d H:i:s', time() + 14 * 24 * 60 * 60);

        // Fetch quotation items
        $iStmt = $db->prepare("SELECT * FROM `quotation_items` WHERE `quotationId` = :quotationId");
        $iStmt->execute(['quotationId' => $id]);
        $quotationItems = $iStmt->fetchAll();

        try {
            $db->beginTransaction();

            // Count invoices in the current year
            $countStmt = $db->prepare("SELECT COUNT(*) FROM `invoices` WHERE `issueDate` >= :yearStart AND `issueDate` <= :yearEnd");
            $countStmt->execute(['yearStart' => $yearStart, 'yearEnd' => $yearEnd]);
            $count = (int)$countStmt->fetchColumn();

            $invoiceNumber = DocNumber::generate('INV', $count + 1);

            // Create invoice linked to quotation
            $insInvStmt = $db->prepare("
                INSERT INTO `invoices` (
                    `invoiceNumber`, `clientId`, `quotationId`, `issueDate`, `dueDate`, `status`, 
                    `subtotal`, `taxAmount`, `totalAmount`, `amountPaid`, `notes`, `isRecurringGenerated`, `createdAt`, `updatedAt`
                ) VALUES (
                    :invoiceNumber, :clientId, :quotationId, NOW(), :dueDate, 'draft', 
                    :subtotal, :taxAmount, :totalAmount, 0, :notes, 0, NOW(), NOW()
                )
            ");
            $insInvStmt->execute([
                'invoiceNumber' => $invoiceNumber,
                'clientId' => $quotation['clientId'],
                'quotationId' => $id,
                'dueDate' => $invoiceDueDate,
                'subtotal' => $quotation['subtotal'],
                'taxAmount' => $quotation['taxAmount'],
                'totalAmount' => $quotation['totalAmount'],
                'notes' => "Converted from quotation " . $quotation['quoteNumber']
            ]);

            $newInvoiceId = (int)$db->lastInsertId();

            // Copy items to invoice_items
            $insItemStmt = $db->prepare("
                INSERT INTO `invoice_items` (`invoiceId`, `itemId`, `description`, `quantity`, `unitPrice`, `lineTotal`)
                VALUES (:invoiceId, :itemId, :description, :quantity, :unitPrice, :lineTotal)
            ");
            foreach ($quotationItems as $qi) {
                $insItemStmt->execute([
                    'invoiceId' => $newInvoiceId,
                    'itemId' => $qi['itemId'] ? (int)$qi['itemId'] : null,
                    'description' => $qi['description'],
                    'quantity' => (int)$qi['quantity'],
                    'unitPrice' => (float)$qi['unitPrice'],
                    'lineTotal' => (float)$qi['lineTotal']
                ]);
            }

            // Update quotation status
            $updQuoteStmt = $db->prepare("UPDATE `quotations` SET `status` = 'converted', `updatedAt` = NOW() WHERE `id` = :id");
            $updQuoteStmt->execute(['id' => $id]);

            $db->commit();

            // Fetch newly created invoice
            $resStmt = $db->prepare("SELECT * FROM `invoices` WHERE `id` = :id LIMIT 1");
            $resStmt->execute(['id' => $newInvoiceId]);
            $invoice = $resStmt->fetch();
            $invoice['id'] = (int)$invoice['id'];
            $invoice['clientId'] = (int)$invoice['clientId'];
            $invoice['quotationId'] = (int)$invoice['quotationId'];
            $invoice['subtotal'] = (float)$invoice['subtotal'];
            $invoice['taxAmount'] = (float)$invoice['taxAmount'];
            $invoice['totalAmount'] = (float)$invoice['totalAmount'];
            $invoice['amountPaid'] = (float)$invoice['amountPaid'];
            $invoice['isRecurringGenerated'] = (bool)$invoice['isRecurringGenerated'];

            $itemsStmt = $db->prepare("SELECT * FROM `invoice_items` WHERE `invoiceId` = :invoiceId");
            $itemsStmt->execute(['invoiceId' => $newInvoiceId]);
            $invoiceItems = $itemsStmt->fetchAll();
            foreach ($invoiceItems as &$ii) {
                $ii['id'] = (int)$ii['id'];
                $ii['invoiceId'] = (int)$ii['invoiceId'];
                $ii['itemId'] = $ii['itemId'] ? (int)$ii['itemId'] : null;
                $ii['quantity'] = (int)$ii['quantity'];
                $ii['unitPrice'] = (float)$ii['unitPrice'];
                $ii['lineTotal'] = (float)$ii['lineTotal'];
            }
            $invoice['items'] = $invoiceItems;

            return Response::json($invoice, 201);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function deleteQuotation(array $req) {
        if (($req['user']['role'] ?? '') !== 'admin') {
            return Response::error('Forbidden: Only Super Admin can perform this action', 403);
        }

        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid quotation ID', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `quotations` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $quotation = $stmt->fetch();

        if (!$quotation) {
            return Response::error('Quotation not found', 404);
        }

        // Check related invoice
        $invStmt = $db->prepare("SELECT `id` FROM `invoices` WHERE `quotationId` = :quotationId LIMIT 1");
        $invStmt->execute(['quotationId' => $id]);
        if ($invStmt->fetch()) {
            return Response::error('Cannot delete quotation because a related invoice exists', 400);
        }

        if ($quotation['status'] === 'converted') {
            return Response::error('Cannot delete a quotation that has already been converted to an invoice', 400);
        }

        // Delete quotation. Cascade deletes quotation items.
        // In MySQL we will let Cascade delete do it (if constraint is set up), or explicitly delete first for safety
        try {
            $db->beginTransaction();

            $delItems = $db->prepare("DELETE FROM `quotation_items` WHERE `quotationId` = :quotationId");
            $delItems->execute(['quotationId' => $id]);

            $delQuote = $db->prepare("DELETE FROM `quotations` WHERE `id` = :id");
            $delQuote->execute(['id' => $id]);

            $db->commit();

            return Response::json(['message' => 'Quotation deleted successfully']);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
