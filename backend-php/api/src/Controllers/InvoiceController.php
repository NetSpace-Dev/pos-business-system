<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use App\Utils\DocNumber;
use App\Utils\TextLk;
use PDO;

class InvoiceController {
    public function getInvoices(array $req) {
        $query = $req['query'];
        $status = $query['status'] ?? '';
        $clientId = $query['clientId'] ?? '';

        $db = Database::getConnection();
        $sql = "
            SELECT i.*, c.companyName, c.contactPerson 
            FROM `invoices` i 
            JOIN `clients` c ON i.clientId = c.id 
            WHERE 1=1
        ";
        $params = [];

        if ($status) {
            $sql .= " AND i.status = :status";
            $params['status'] = $status;
        }

        if ($clientId) {
            $sql .= " AND i.clientId = :clientId";
            $params['clientId'] = (int)$clientId;
        }

        $sql .= " ORDER BY i.createdAt DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();

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
            $inv['client'] = [
                'companyName' => $inv['companyName'],
                'contactPerson' => $inv['contactPerson']
            ];
            unset($inv['companyName'], $inv['contactPerson']);
        }

        return Response::json($invoices);
    }

    public function getInvoiceById(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid invoice ID', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `invoices` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            return Response::error('Invoice not found', 404);
        }

        $invoice['id'] = (int)$invoice['id'];
        $invoice['clientId'] = (int)$invoice['clientId'];
        $invoice['quotationId'] = $invoice['quotationId'] ? (int)$invoice['quotationId'] : null;
        $invoice['recurringInvoiceId'] = $invoice['recurringInvoiceId'] ? (int)$invoice['recurringInvoiceId'] : null;
        $invoice['subtotal'] = (float)$invoice['subtotal'];
        $invoice['taxAmount'] = (float)$invoice['taxAmount'];
        $invoice['totalAmount'] = (float)$invoice['totalAmount'];
        $invoice['amountPaid'] = (float)$invoice['amountPaid'];
        $invoice['isRecurringGenerated'] = (bool)$invoice['isRecurringGenerated'];

        // Fetch client
        $cStmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :clientId LIMIT 1");
        $cStmt->execute(['clientId' => $invoice['clientId']]);
        $invoice['client'] = $cStmt->fetch() ?: null;
        if ($invoice['client']) {
            $invoice['client']['id'] = (int)$invoice['client']['id'];
            $invoice['client']['isActive'] = (bool)$invoice['client']['isActive'];
        }

        // Fetch items
        $iStmt = $db->prepare("
            SELECT ii.*, inv.sku, inv.name as itemName, inv.category, inv.unitCost, inv.sellPrice, inv.isTracked, inv.isActive as itemIsActive
            FROM `invoice_items` ii 
            LEFT JOIN `inventory_items` inv ON ii.itemId = inv.id 
            WHERE ii.invoiceId = :invoiceId
        ");
        $iStmt->execute(['invoiceId' => $id]);
        $items = $iStmt->fetchAll();
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['invoiceId'] = (int)$item['invoiceId'];
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
            unset($item['sku'], $item['itemName'], $item['category'], $item['unitCost'], $item['sellPrice'], $item['isTracked'], $item['itemIsActive']);
        }
        $invoice['items'] = $items;

        // Fetch payments
        $pStmt = $db->prepare("SELECT * FROM `payments` WHERE `invoiceId` = :invoiceId ORDER BY `paidDate` DESC");
        $pStmt->execute(['invoiceId' => $id]);
        $payments = $pStmt->fetchAll();
        foreach ($payments as &$p) {
            $p['id'] = (int)$p['id'];
            $p['invoiceId'] = (int)$p['invoiceId'];
            $p['amount'] = (float)$p['amount'];
        }
        $invoice['payments'] = $payments;

        // Fetch quotation
        $invoice['quotation'] = null;
        if ($invoice['quotationId']) {
            $qStmt = $db->prepare("SELECT `id`, `quoteNumber` FROM `quotations` WHERE `id` = :quotationId LIMIT 1");
            $qStmt->execute(['quotationId' => $invoice['quotationId']]);
            $invoice['quotation'] = $qStmt->fetch() ?: null;
            if ($invoice['quotation']) {
                $invoice['quotation']['id'] = (int)$invoice['quotation']['id'];
            }
        }

        // Fetch recurring invoice link
        $invoice['recurringInvoice'] = null;
        if ($invoice['recurringInvoiceId']) {
            $rStmt = $db->prepare("SELECT `id`, `title` FROM `recurring_invoices` WHERE `id` = :riId LIMIT 1");
            $rStmt->execute(['riId' => $invoice['recurringInvoiceId']]);
            $invoice['recurringInvoice'] = $rStmt->fetch() ?: null;
            if ($invoice['recurringInvoice']) {
                $invoice['recurringInvoice']['id'] = (int)$invoice['recurringInvoice']['id'];
            }
        }

        // Fetch reminders
        $remStmt = $db->prepare("SELECT * FROM `invoice_reminders` WHERE `invoiceId` = :invoiceId ORDER BY `sentAt` DESC");
        $remStmt->execute(['invoiceId' => $id]);
        $reminders = $remStmt->fetchAll();
        foreach ($reminders as &$r) {
            $r['id'] = (int)$r['id'];
            $r['invoiceId'] = (int)$r['invoiceId'];
        }
        $invoice['reminders'] = $reminders;

        return Response::json($invoice);
    }

    public function createInvoice(array $req) {
        $body = $req['body'];
        $clientId = isset($body['clientId']) ? (int)$body['clientId'] : 0;
        $dueDate = $body['dueDate'] ?? '';
        $items = $body['items'] ?? null;
        $notes = $body['notes'] ?? null;
        $taxAmount = isset($body['taxAmount']) ? (float)$body['taxAmount'] : 0.0;

        if ($clientId <= 0 || !$dueDate || !$items || !is_array($items) || count($items) === 0) {
            return Response::error('Client ID, due date, and invoice items are required', 400);
        }

        $db = Database::getConnection();
        
        // Client check
        $cStmt = $db->prepare("SELECT `id` FROM `clients` WHERE `id` = :id LIMIT 1");
        $cStmt->execute(['id' => $clientId]);
        if (!$cStmt->fetch()) {
            return Response::error('Client not found', 404);
        }

        $currentYear = date('Y');
        $yearStart = "$currentYear-01-01 00:00:00";
        $yearEnd = "$currentYear-12-31 23:59:59";

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
        $dueDateVal = date('Y-m-d H:i:s', strtotime($dueDate));

        try {
            $db->beginTransaction();

            // Count invoices in the current year
            $countStmt = $db->prepare("SELECT COUNT(*) FROM `invoices` WHERE `issueDate` >= :yearStart AND `issueDate` <= :yearEnd");
            $countStmt->execute(['yearStart' => $yearStart, 'yearEnd' => $yearEnd]);
            $count = (int)$countStmt->fetchColumn();

            $invoiceNumber = DocNumber::generate('INV', $count + 1);

            $insStmt = $db->prepare("
                INSERT INTO `invoices` (
                    `invoiceNumber`, `clientId`, `issueDate`, `dueDate`, `status`, 
                    `subtotal`, `taxAmount`, `totalAmount`, `amountPaid`, `notes`, `isRecurringGenerated`, `createdAt`, `updatedAt`
                ) VALUES (
                    :invoiceNumber, :clientId, NOW(), :dueDate, 'draft', 
                    :subtotal, :taxAmount, :totalAmount, 0, :notes, 0, NOW(), NOW()
                )
            ");
            $insStmt->execute([
                'invoiceNumber' => $invoiceNumber,
                'clientId' => $clientId,
                'dueDate' => $dueDateVal,
                'subtotal' => $subtotal,
                'taxAmount' => $taxAmount,
                'totalAmount' => $totalAmount,
                'notes' => $notes
            ]);

            $newInvId = (int)$db->lastInsertId();

            // Insert invoice items
            $itemStmt = $db->prepare("
                INSERT INTO `invoice_items` (`invoiceId`, `itemId`, `description`, `quantity`, `unitPrice`, `lineTotal`)
                VALUES (:invoiceId, :itemId, :description, :quantity, :unitPrice, :lineTotal)
            ");
            foreach ($itemsData as $item) {
                $itemStmt->execute([
                    'invoiceId' => $newInvId,
                    'itemId' => $item['itemId'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unitPrice' => $item['unitPrice'],
                    'lineTotal' => $item['lineTotal']
                ]);
            }

            $db->commit();

            // Fetch and return created invoice with items
            $stmt = $db->prepare("SELECT * FROM `invoices` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $newInvId]);
            $created = $stmt->fetch();
            $created['id'] = (int)$created['id'];
            $created['clientId'] = (int)$created['clientId'];
            $created['subtotal'] = (float)$created['subtotal'];
            $created['taxAmount'] = (float)$created['taxAmount'];
            $created['totalAmount'] = (float)$created['totalAmount'];
            $created['amountPaid'] = (float)$created['amountPaid'];
            $created['isRecurringGenerated'] = (bool)$created['isRecurringGenerated'];

            $itemsStmt = $db->prepare("SELECT * FROM `invoice_items` WHERE `invoiceId` = :invoiceId");
            $itemsStmt->execute(['invoiceId' => $newInvId]);
            $createdItems = $itemsStmt->fetchAll();
            foreach ($createdItems as &$ci) {
                $ci['id'] = (int)$ci['id'];
                $ci['invoiceId'] = (int)$ci['invoiceId'];
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

    public function updateInvoice(array $req) {
        if (($req['user']['role'] ?? '') !== 'admin') {
            return Response::error('Forbidden: Only Super Admin can perform this action', 403);
        }

        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid invoice ID', 400);
        }

        $body = $req['body'];
        $dueDate = $body['dueDate'] ?? null;
        $items = $body['items'] ?? null;
        $notes = $body['notes'] ?? null;
        $taxAmount = isset($body['taxAmount']) ? (float)$body['taxAmount'] : null;
        $status = $body['status'] ?? null;

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `invoices` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Invoice not found', 404);
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

        $dueDateVal = $dueDate ? date('Y-m-d H:i:s', strtotime($dueDate)) : $existing['dueDate'];
        $statusVal = $status ?: $existing['status'];
        $notesVal = ($notes !== null) ? $notes : $existing['notes'];

        try {
            $db->beginTransaction();

            // 1. Revert stock and delete previous stock movements for this invoice
            $smStmt = $db->prepare("SELECT * FROM `stock_movements` WHERE `reason` LIKE :reason");
            $smStmt->execute(['reason' => "Invoice #{$existing['invoiceNumber']}%"]);
            $movements = $smStmt->fetchAll();
            $hadStockDeducted = count($movements) > 0;

            if ($hadStockDeducted) {
                // Revert
                $revStmt = $db->prepare("UPDATE `inventory_items` SET `stockQty` = `stockQty` + :qty WHERE `id` = :itemId");
                foreach ($movements as $m) {
                    $revStmt->execute(['qty' => (int)$m['quantity'], 'itemId' => $m['itemId']]);
                }
                // Delete stock movements
                $delSmStmt = $db->prepare("DELETE FROM `stock_movements` WHERE `reason` LIKE :reason");
                $delSmStmt->execute(['reason' => "Invoice #{$existing['invoiceNumber']}%"]);
            }

            // 2. Delete old items
            if ($items && is_array($items)) {
                $delItemsStmt = $db->prepare("DELETE FROM `invoice_items` WHERE `invoiceId` = :invoiceId");
                $delItemsStmt->execute(['invoiceId' => $id]);
            }

            // 3. Update Invoice
            $updStmt = $db->prepare("
                UPDATE `invoices` 
                SET `dueDate` = :dueDate, `status` = :status, `subtotal` = :subtotal, 
                    `taxAmount` = :taxAmount, `totalAmount` = :totalAmount, `notes` = :notes, `updatedAt` = NOW()
                WHERE `id` = :id
            ");
            $updStmt->execute([
                'dueDate' => $dueDateVal,
                'status' => $statusVal,
                'subtotal' => $subtotal,
                'taxAmount' => $tax,
                'totalAmount' => $totalAmount,
                'notes' => $notesVal,
                'id' => $id
            ]);

            // Re-create items if supplied
            if ($items && is_array($items)) {
                $insItemsStmt = $db->prepare("
                    INSERT INTO `invoice_items` (`invoiceId`, `itemId`, `description`, `quantity`, `unitPrice`, `lineTotal`)
                    VALUES (:invoiceId, :itemId, :description, :quantity, :unitPrice, :lineTotal)
                ");
                foreach ($itemsData as $item) {
                    $insItemsStmt->execute([
                        'invoiceId' => $id,
                        'itemId' => $item['itemId'],
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unitPrice' => $item['unitPrice'],
                        'lineTotal' => $item['lineTotal']
                    ]);
                }
            }

            // 4. Fetch updated items
            $fetchItemsStmt = $db->prepare("SELECT * FROM `invoice_items` WHERE `invoiceId` = :invoiceId");
            $fetchItemsStmt->execute(['invoiceId' => $id]);
            $updatedItems = $fetchItemsStmt->fetchAll();

            // Re-deduct stock if stock was previously deducted
            if ($hadStockDeducted) {
                $decStmt = $db->prepare("UPDATE `inventory_items` SET `stockQty` = `stockQty` - :qty WHERE `id` = :itemId");
                $insSmStmt = $db->prepare("
                    INSERT INTO `stock_movements` (`itemId`, `type`, `quantity`, `reason`, `createdAt`)
                    VALUES (:itemId, 'out', :qty, :reason, NOW())
                ");
                
                foreach ($updatedItems as $item) {
                    if ($item['itemId']) {
                        // Check if item is tracked
                        $itStmt = $db->prepare("SELECT `isTracked` FROM `inventory_items` WHERE `id` = :itemId LIMIT 1");
                        $itStmt->execute(['itemId' => $item['itemId']]);
                        $isTracked = (bool)$itStmt->fetchColumn();

                        if ($isTracked) {
                            $decStmt->execute(['qty' => (int)$item['quantity'], 'itemId' => $item['itemId']]);
                            $insSmStmt->execute([
                                'itemId' => $item['itemId'],
                                'qty' => (int)$item['quantity'],
                                'reason' => "Invoice #{$existing['invoiceNumber']} sale (edited)"
                            ]);
                        }
                    }
                }
            }

            // 5. Recalculate Payment Allocations and Partner Payouts if amount changed
            if ((float)$existing['totalAmount'] !== (float)$totalAmount) {
                // Calculate new COGS
                $newTotalCOGS = 0.0;
                $costStmt = $db->prepare("SELECT `unitCost` FROM `inventory_items` WHERE `id` = :itemId LIMIT 1");
                foreach ($updatedItems as $item) {
                    if ($item['itemId']) {
                        $costStmt->execute(['itemId' => $item['itemId']]);
                        $unitCost = (float)$costStmt->fetchColumn();
                        $newTotalCOGS += $unitCost * (int)$item['quantity'];
                    }
                }

                // Fetch current settings
                $setStmt = $db->prepare("SELECT `value` FROM `system_settings` WHERE `key` = :key LIMIT 1");
                
                $setStmt->execute(['key' => 'reinvestment_percentage']);
                $reinvestVal = $setStmt->fetchColumn();
                $reinvestmentPct = $reinvestVal !== false ? (float)$reinvestVal : 50.0;

                $setStmt->execute(['key' => 'tax_percentage']);
                $taxVal = $setStmt->fetchColumn();
                $taxPct = $taxVal !== false ? (float)$taxVal : 15.0;

                // Fetch payments
                $pStmt = $db->prepare("SELECT * FROM `payments` WHERE `invoiceId` = :invoiceId ORDER BY `createdAt` ASC");
                $pStmt->execute(['invoiceId' => $id]);
                $payments = $pStmt->fetchAll();

                $allocatedCOGS = 0.0;
                foreach ($payments as $payment) {
                    $allocStmt = $db->prepare("SELECT * FROM `payment_allocations` WHERE `paymentId` = :paymentId LIMIT 1");
                    $allocStmt->execute(['paymentId' => $payment['id']]);
                    $allocation = $allocStmt->fetch();

                    if ($allocation) {
                        $paymentAmount = (float)$payment['amount'];
                        $remainingCOGS = max(0.0, $newTotalCOGS - $allocatedCOGS);

                        $paymentTax = $paymentAmount * ($taxPct / 100);
                        $availableForCOGS = max(0.0, $paymentAmount - $paymentTax);
                        $paymentCOGS = min($availableForCOGS, $remainingCOGS);
                        $allocatedCOGS += $paymentCOGS;

                        $netAmount = $paymentAmount - $paymentCOGS - $paymentTax;
                        $reinvestAmount = $netAmount * ($reinvestmentPct / 100);
                        $partnerPoolAmount = $netAmount - $reinvestAmount;

                        // Update payment allocation
                        $updAlloc = $db->prepare("
                            UPDATE `payment_allocations` 
                            SET `cogsAmount` = :cogsAmount, `taxAmount` = :taxAmount, 
                                `netAmount` = :netAmount, `reinvestAmount` = :reinvestAmount, 
                                `partnerPoolAmount` = :partnerPoolAmount 
                            WHERE `id` = :id
                        ");
                        $updAlloc->execute([
                            'cogsAmount' => $paymentCOGS,
                            'taxAmount' => $paymentTax,
                            'netAmount' => $netAmount,
                            'reinvestAmount' => $reinvestAmount,
                            'partnerPoolAmount' => $partnerPoolAmount,
                            'id' => $allocation['id']
                        ]);

                        // Update partner payouts
                        $payoutsStmt = $db->prepare("
                            SELECT pp.*, p.ownershipPct 
                            FROM `partner_payouts` pp 
                            JOIN `partners` p ON pp.partnerId = p.id 
                            WHERE pp.allocationId = :allocId
                        ");
                        $payoutsStmt->execute(['allocId' => $allocation['id']]);
                        $payouts = $payoutsStmt->fetchAll();

                        $updPayout = $db->prepare("UPDATE `partner_payouts` SET `amount` = :amount WHERE `id` = :id");
                        foreach ($payouts as $payout) {
                            $partnerShare = $partnerPoolAmount * ((float)$payout['ownershipPct'] / 100);
                            $updPayout->execute([
                                'amount' => $partnerShare,
                                'id' => $payout['id']
                            ]);
                        }
                    }
                }
            }

            $db->commit();

            // Return updated invoice
            return $this->getInvoiceById(['params' => ['id' => $id]]);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function recordPayment(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid invoice ID', 400);
        }

        $body = $req['body'];
        $amount = isset($body['amount']) ? (float)$body['amount'] : null;
        $method = $body['method'] ?? '';
        $reference = $body['reference'] ?? null;
        $notes = $body['notes'] ?? null;
        $paidDate = $body['paidDate'] ?? null;

        if ($amount === null || !$method) {
            return Response::error('Payment amount and method are required', 400);
        }

        if ($amount <= 0) {
            return Response::error('Payment amount must be a positive number', 400);
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `invoices` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            return Response::error('Invoice not found', 404);
        }

        if ($invoice['status'] === 'paid') {
            return Response::error('Invoice is already fully paid', 400);
        }

        $currentPaid = (float)$invoice['amountPaid'];
        $totalDue = (float)$invoice['totalAmount'];
        $newPaidAmount = $currentPaid + $amount;

        $newStatus = 'partially_paid';
        if ($newPaidAmount >= $totalDue) {
            $newStatus = 'paid';
        }

        // Fetch invoice items
        $iStmt = $db->prepare("SELECT * FROM `invoice_items` WHERE `invoiceId` = :invoiceId");
        $iStmt->execute(['invoiceId' => $id]);
        $invoiceItems = $iStmt->fetchAll();

        try {
            $db->beginTransaction();

            // 1. Log Payment
            $paidDateVal = $paidDate ? date('Y-m-d H:i:s', strtotime($paidDate)) : date('Y-m-d H:i:s');
            $insPayStmt = $db->prepare("
                INSERT INTO `payments` (`invoiceId`, `amount`, `method`, `reference`, `notes`, `paidDate`, `createdAt`)
                VALUES (:invoiceId, :amount, :method, :reference, :notes, :paidDate, NOW())
            ");
            $insPayStmt->execute([
                'invoiceId' => $id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'paidDate' => $paidDateVal
            ]);
            $paymentId = (int)$db->lastInsertId();

            // 2. Update Invoice
            $updInvStmt = $db->prepare("UPDATE `invoices` SET `amountPaid` = :paid, `status` = :status, `updatedAt` = NOW() WHERE `id` = :id");
            $updInvStmt->execute([
                'paid' => $newPaidAmount,
                'status' => $newStatus,
                'id' => $id
            ]);

            // 3. Deduct stock if first payment
            $hasSmStmt = $db->prepare("SELECT `id` FROM `stock_movements` WHERE `reason` LIKE :reason LIMIT 1");
            $hasSmStmt->execute(['reason' => "Invoice #{$invoice['invoiceNumber']}%"]);
            $hasMovement = $hasSmStmt->fetch();

            if (!$hasMovement) {
                $decItemStmt = $db->prepare("UPDATE `inventory_items` SET `stockQty` = `stockQty` - :qty WHERE `id` = :itemId");
                $insSmStmt = $db->prepare("
                    INSERT INTO `stock_movements` (`itemId`, `type`, `quantity`, `reason`, `createdAt`)
                    VALUES (:itemId, 'out', :qty, :reason, NOW())
                ");

                foreach ($invoiceItems as $item) {
                    if ($item['itemId']) {
                        $itStmt = $db->prepare("SELECT `isTracked` FROM `inventory_items` WHERE `id` = :itemId LIMIT 1");
                        $itStmt->execute(['itemId' => $item['itemId']]);
                        $isTracked = (bool)$itStmt->fetchColumn();

                        if ($isTracked) {
                            $decItemStmt->execute(['qty' => (int)$item['quantity'], 'itemId' => $item['itemId']]);
                            $insSmStmt->execute([
                                'itemId' => $item['itemId'],
                                'qty' => (int)$item['quantity'],
                                'reason' => "Invoice #{$invoice['invoiceNumber']} sale"
                            ]);
                        }
                    }
                }
            }

            // 4. Calculate COGS
            $totalInvoiceCOGS = 0.0;
            $costStmt = $db->prepare("SELECT `unitCost` FROM `inventory_items` WHERE `id` = :itemId LIMIT 1");
            foreach ($invoiceItems as $item) {
                if ($item['itemId']) {
                    $costStmt->execute(['itemId' => $item['itemId']]);
                    $unitCost = (float)$costStmt->fetchColumn();
                    $totalInvoiceCOGS += $unitCost * (int)$item['quantity'];
                }
            }

            // 5. Fetch settings
            $setStmt = $db->prepare("SELECT `value` FROM `system_settings` WHERE `key` = :key LIMIT 1");
            
            $setStmt->execute(['key' => 'reinvestment_percentage']);
            $reinvestVal = $setStmt->fetchColumn();
            $reinvestmentPct = $reinvestVal !== false ? (float)$reinvestVal : 50.0;

            $setStmt->execute(['key' => 'tax_percentage']);
            $taxVal = $setStmt->fetchColumn();
            $taxPct = $taxVal !== false ? (float)$taxVal : 15.0;

            // Fetch already allocated COGS
            $otherAllocStmt = $db->prepare("
                SELECT SUM(pa.cogsAmount) 
                FROM `payment_allocations` pa 
                JOIN `payments` p ON pa.paymentId = p.id 
                WHERE p.invoiceId = :invoiceId
            ");
            $otherAllocStmt->execute(['invoiceId' => $id]);
            $allocatedCOGS = (float)$otherAllocStmt->fetchColumn();
            $remainingCOGS = max(0.0, $totalInvoiceCOGS - $allocatedCOGS);

            $paymentTax = $amount * ($taxPct / 100);
            $availableForCOGS = max(0.0, $amount - $paymentTax);
            $paymentCOGS = min($availableForCOGS, $remainingCOGS);
            $netAmount = $amount - $paymentCOGS - $paymentTax;

            $reinvestAmount = $netAmount * ($reinvestmentPct / 100);
            $partnerPoolAmount = $netAmount - $reinvestAmount;

            // 6. Create payment allocation
            $insAllocStmt = $db->prepare("
                INSERT INTO `payment_allocations` (
                    `paymentId`, `grossAmount`, `cogsAmount`, `taxAmount`, `netAmount`, 
                    `reinvestPctUsed`, `reinvestAmount`, `partnerPoolAmount`, `createdAt`
                ) VALUES (
                    :paymentId, :grossAmount, :cogsAmount, :taxAmount, :netAmount, 
                    :reinvestPctUsed, :reinvestAmount, :partnerPoolAmount, NOW()
                )
            ");
            $insAllocStmt->execute([
                'paymentId' => $paymentId,
                'grossAmount' => $amount,
                'cogsAmount' => $paymentCOGS,
                'taxAmount' => $paymentTax,
                'netAmount' => $netAmount,
                'reinvestPctUsed' => $reinvestmentPct,
                'reinvestAmount' => $reinvestAmount,
                'partnerPoolAmount' => $partnerPoolAmount
            ]);
            $allocationId = (int)$db->lastInsertId();

            // 7. Create partner payouts
            $partStmt = $db->prepare("SELECT * FROM `partners` WHERE `status` = 'active'");
            $partStmt->execute();
            $activePartners = $partStmt->fetchAll();

            $insPayoutStmt = $db->prepare("
                INSERT INTO `partner_payouts` (`partnerId`, `allocationId`, `amount`, `status`, `createdAt`, `updatedAt`)
                VALUES (:partnerId, :allocationId, :amount, 'pending', NOW(), NOW())
            ");
            foreach ($activePartners as $partner) {
                $partnerShare = $partnerPoolAmount * ((float)$partner['ownershipPct'] / 100);
                $insPayoutStmt->execute([
                    'partnerId' => $partner['id'],
                    'allocationId' => $allocationId,
                    'amount' => $partnerShare
                ]);
            }

            $db->commit();

            // Return updated invoice and payment details
            $resInvoiceStmt = $db->prepare("SELECT * FROM `invoices` WHERE `id` = :id LIMIT 1");
            $resInvoiceStmt->execute(['id' => $id]);
            $updatedInvoice = $resInvoiceStmt->fetch();
            $updatedInvoice['id'] = (int)$updatedInvoice['id'];
            $updatedInvoice['clientId'] = (int)$updatedInvoice['clientId'];
            $updatedInvoice['subtotal'] = (float)$updatedInvoice['subtotal'];
            $updatedInvoice['taxAmount'] = (float)$updatedInvoice['taxAmount'];
            $updatedInvoice['totalAmount'] = (float)$updatedInvoice['totalAmount'];
            $updatedInvoice['amountPaid'] = (float)$updatedInvoice['amountPaid'];
            $updatedInvoice['isRecurringGenerated'] = (bool)$updatedInvoice['isRecurringGenerated'];

            $resPaymentStmt = $db->prepare("SELECT * FROM `payments` WHERE `id` = :id LIMIT 1");
            $resPaymentStmt->execute(['id' => $paymentId]);
            $payment = $resPaymentStmt->fetch();
            $payment['id'] = (int)$payment['id'];
            $payment['invoiceId'] = (int)$payment['invoiceId'];
            $payment['amount'] = (float)$payment['amount'];

            return Response::json([
                'updatedInvoice' => $updatedInvoice,
                'payment' => $payment
            ], 201);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function deleteInvoice(array $req) {
        if (($req['user']['role'] ?? '') !== 'admin') {
            return Response::error('Forbidden: Only Super Admin can perform this action', 403);
        }

        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid invoice ID', 400);
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `invoices` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            return Response::error('Invoice not found', 404);
        }

        // Fetch payments for this invoice
        $pStmt = $db->prepare("SELECT * FROM `payments` WHERE `invoiceId` = :invoiceId");
        $pStmt->execute(['invoiceId' => $id]);
        $payments = $pStmt->fetchAll();

        try {
            $db->beginTransaction();

            // 1. Revert stock and delete stock movements
            $smStmt = $db->prepare("SELECT * FROM `stock_movements` WHERE `reason` LIKE :reason");
            $smStmt->execute(['reason' => "Invoice #{$invoice['invoiceNumber']}%"]);
            $movements = $smStmt->fetchAll();

            if (count($movements) > 0) {
                $revStmt = $db->prepare("UPDATE `inventory_items` SET `stockQty` = `stockQty` + :qty WHERE `id` = :itemId");
                foreach ($movements as $m) {
                    $revStmt->execute(['qty' => (int)$m['quantity'], 'itemId' => $m['itemId']]);
                }
                $delSmStmt = $db->prepare("DELETE FROM `stock_movements` WHERE `reason` LIKE :reason");
                $delSmStmt->execute(['reason' => "Invoice #{$invoice['invoiceNumber']}%"]);
            }

            // 2. Revert payments, allocations, and partner payouts
            foreach ($payments as $payment) {
                $allocStmt = $db->prepare("SELECT `id` FROM `payment_allocations` WHERE `paymentId` = :paymentId LIMIT 1");
                $allocStmt->execute(['paymentId' => $payment['id']]);
                $allocationId = $allocStmt->fetchColumn();

                if ($allocationId) {
                    // Delete payouts
                    $delPayouts = $db->prepare("DELETE FROM `partner_payouts` WHERE `allocationId` = :allocId");
                    $delPayouts->execute(['allocId' => $allocationId]);

                    // Delete allocation
                    $delAlloc = $db->prepare("DELETE FROM `payment_allocations` WHERE `id` = :allocId");
                    $delAlloc->execute(['allocId' => $allocationId]);
                }

                // Delete payment
                $delPay = $db->prepare("DELETE FROM `payments` WHERE `id` = :payId");
                $delPay->execute(['payId' => $payment['id']]);
            }

            // 3. Unlink project deadlines
            $updDeadStmt = $db->prepare("UPDATE `project_deadlines` SET `invoiceId` = NULL WHERE `invoiceId` = :invoiceId");
            $updDeadStmt->execute(['invoiceId' => $id]);

            // 4. Delete the Invoice (will cascade delete items in invoice_items)
            // For safety we explicitly delete invoice items first
            $delItems = $db->prepare("DELETE FROM `invoice_items` WHERE `invoiceId` = :invoiceId");
            $delItems->execute(['invoiceId' => $id]);

            $delInv = $db->prepare("DELETE FROM `invoices` WHERE `id` = :id");
            $delInv->execute(['id' => $id]);

            $db->commit();

            return Response::json(['message' => 'Invoice and all associated payments, payouts, allocations, and stock movements reverted and deleted successfully']);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function sendInvoiceReminder(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid invoice ID', 400);
        }

        $body = $req['body'];
        $templateId = isset($body['templateId']) ? (int)$body['templateId'] : 0;

        if ($templateId <= 0) {
            return Response::error('Template ID is required', 400);
        }

        $db = Database::getConnection();

        // Fetch invoice with client details
        $stmt = $db->prepare("
            SELECT i.*, c.contactPerson, c.companyName, c.phone 
            FROM `invoices` i 
            JOIN `clients` c ON i.clientId = c.id 
            WHERE i.id = :id 
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            return Response::error('Invoice not found', 404);
        }

        // Fetch template
        $tempStmt = $db->prepare("SELECT * FROM `sms_templates` WHERE `id` = :templateId LIMIT 1");
        $tempStmt->execute(['templateId' => $templateId]);
        $template = $tempStmt->fetch();

        if (!$template) {
            return Response::error('SMS Template not found', 404);
        }

        $contactPerson = $invoice['contactPerson'];
        $companyName = $invoice['companyName'];
        $invoiceNumber = $invoice['invoiceNumber'];
        $totalAmount = number_format((float)$invoice['totalAmount'], 2, '.', '');
        $amountPaid = number_format((float)$invoice['amountPaid'], 2, '.', '');
        $balanceAmount = number_format((float)$invoice['totalAmount'] - (float)$invoice['amountPaid'], 2, '.', '');
        $dueDate = date('d/m/Y', strtotime($invoice['dueDate']));

        $message = str_replace(
            ['{contactPerson}', '{companyName}', '{invoiceNumber}', '{totalAmount}', '{amountPaid}', '{balanceAmount}', '{dueDate}'],
            [$contactPerson, $companyName, $invoiceNumber, $totalAmount, $amountPaid, $balanceAmount, $dueDate],
            $template['body']
        );

        $senderEmail = $req['user']['email'] ?? 'admin@pos.com';

        // Send SMS
        $isSent = false;
        try {
            $isSent = TextLk::sendSMS($invoice['phone'], $message);
        } catch (\Exception $smsErr) {
            error_log('[Text.lk] Failed to send SMS reminder: ' . $smsErr->getMessage());
        }

        $status = $isSent ? 'sent' : 'failed';

        // Create log record
        $insRemStmt = $db->prepare("
            INSERT INTO `invoice_reminders` (`invoiceId`, `sentBy`, `message`, `status`, `sentAt`)
            VALUES (:invoiceId, :sentBy, :message, :status, NOW())
        ");
        $insRemStmt->execute([
            'invoiceId' => $id,
            'sentBy' => $senderEmail,
            'message' => $message,
            'status' => $status
        ]);
        $reminderId = (int)$db->lastInsertId();

        // Fetch reminder log
        $resRemStmt = $db->prepare("SELECT * FROM `invoice_reminders` WHERE `id` = :id LIMIT 1");
        $resRemStmt->execute(['id' => $reminderId]);
        $reminder = $resRemStmt->fetch();
        $reminder['id'] = (int)$reminder['id'];
        $reminder['invoiceId'] = (int)$reminder['invoiceId'];

        if (!$isSent) {
            return Response::error('Failed to send SMS reminder via provider API', 500, ['log' => $reminder]);
        }

        return Response::json($reminder, 201);
    }
}
