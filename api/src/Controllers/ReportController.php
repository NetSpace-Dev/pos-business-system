<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

class ReportController {
    public function getDashboardSummary(array $req) {
        $currentYear = (int)date('Y');
        $yearStart = "$currentYear-01-01 00:00:00";

        $db = Database::getConnection();

        // 1. Fetch Invoices and calculate metrics
        // In Express: invoices are retrieved with items and resolved item details
        $invStmt = $db->prepare("
            SELECT i.*, c.companyName 
            FROM `invoices` i 
            JOIN `clients` c ON i.clientId = c.id 
            WHERE i.status != 'cancelled'
        ");
        $invStmt->execute();
        $invoices = $invStmt->fetchAll();

        // Fetch all invoice items with unitCosts to calculate COGS if needed
        // (Cash basis metrics are fetched from payment_allocations, outstanding is calculated from invoices)
        
        // Cash basis revenue & cogs
        $allocStmt = $db->prepare("SELECT SUM(grossAmount) as totalRev, SUM(cogsAmount) as totalCogs FROM `payment_allocations`");
        $allocStmt->execute();
        $allocData = $allocStmt->fetch();
        $totalRevenue = (float)($allocData['totalRev'] ?? 0.0);
        $totalCOGS = (float)($allocData['totalCogs'] ?? 0.0);

        // Outstanding receivables
        $totalOutstanding = 0.0;
        foreach ($invoices as $inv) {
            if ($inv['status'] !== 'draft' && $inv['status'] !== 'cancelled') {
                $total = (float)$inv['totalAmount'];
                $paid = (float)$inv['amountPaid'];
                $totalOutstanding += ($total - $paid);
            }
        }

        // Net Profit = Cash revenue - Cash COGS - Expenses sum
        $expStmt = $db->prepare("SELECT SUM(amount) FROM `expenses`");
        $expStmt->execute();
        $expensesSum = (float)$expStmt->fetchColumn();
        $totalProfit = $totalRevenue - $totalCOGS - $expensesSum;

        // 2. Active Clients Count
        $cCountStmt = $db->prepare("SELECT COUNT(*) FROM `clients` WHERE `isActive` = 1");
        $cCountStmt->execute();
        $activeClientsCount = (int)$cCountStmt->fetchColumn();

        // Active Tickets Count
        $tCountStmt = $db->prepare("SELECT COUNT(*) FROM `support_tickets` WHERE `status` IN ('open', 'in_progress')");
        $tCountStmt->execute();
        $activeTicketsCount = (int)$tCountStmt->fetchColumn();

        // 3. Low stock alert items list
        $itemStmt = $db->prepare("SELECT id, sku, name, stockQty, reorderLevel FROM `inventory_items` WHERE `isTracked` = 1 AND `isActive` = 1");
        $itemStmt->execute();
        $trackedItems = $itemStmt->fetchAll();
        
        $lowStockItems = [];
        foreach ($trackedItems as $item) {
            $qty = (int)$item['stockQty'];
            $reorder = (int)$item['reorderLevel'];
            if ($qty <= $reorder) {
                $lowStockItems[] = [
                    'id' => (int)$item['id'],
                    'sku' => $item['sku'],
                    'name' => $item['name'],
                    'stockQty' => $qty,
                    'reorderLevel' => $reorder
                ];
            }
        }

        // 4. Monthly sales aggregation (for current year)
        $monthlySales = [];
        for ($m = 0; $m < 12; $m++) {
            $monthName = date('M', mktime(0, 0, 0, $m + 1, 10));
            $monthlySales[$m] = [
                'month' => $monthName,
                'sales' => 0.0,
                'payments' => 0.0
            ];
        }

        foreach ($invoices as $inv) {
            $invDate = strtotime($inv['issueDate']);
            if ((int)date('Y', $invDate) === $currentYear && $inv['status'] !== 'draft') {
                $monthIdx = (int)date('n', $invDate) - 1;
                $monthlySales[$monthIdx]['sales'] += (float)$inv['totalAmount'];
                $monthlySales[$monthIdx]['payments'] += (float)$inv['amountPaid'];
            }
        }

        // 5. Recent Invoices (limit 5)
        $recInvStmt = $db->prepare("
            SELECT i.*, c.companyName 
            FROM `invoices` i 
            JOIN `clients` c ON i.clientId = c.id 
            ORDER BY i.createdAt DESC 
            LIMIT 5
        ");
        $recInvStmt->execute();
        $recentInvoices = $recInvStmt->fetchAll();
        foreach ($recentInvoices as &$ri) {
            $ri['id'] = (int)$ri['id'];
            $ri['clientId'] = (int)$ri['clientId'];
            $ri['subtotal'] = (float)$ri['subtotal'];
            $ri['taxAmount'] = (float)$ri['taxAmount'];
            $ri['totalAmount'] = (float)$ri['totalAmount'];
            $ri['amountPaid'] = (float)$ri['amountPaid'];
            $ri['isRecurringGenerated'] = (bool)$ri['isRecurringGenerated'];
            $ri['client'] = ['companyName' => $ri['companyName']];
            unset($ri['companyName']);
        }

        // 6. Recent Tickets (limit 5)
        $recTktStmt = $db->prepare("
            SELECT t.*, c.companyName 
            FROM `support_tickets` t 
            JOIN `clients` c ON t.clientId = c.id 
            ORDER BY t.createdAt DESC 
            LIMIT 5
        ");
        $recTktStmt->execute();
        $recentTickets = $recTktStmt->fetchAll();
        foreach ($recentTickets as &$rt) {
            $rt['id'] = (int)$rt['id'];
            $rt['clientId'] = (int)$rt['clientId'];
            $rt['client'] = ['companyName' => $rt['companyName']];
            unset($rt['companyName']);
        }

        return Response::json([
            'metrics' => [
                'totalRevenue' => $totalRevenue,
                'totalOutstanding' => $totalOutstanding,
                'totalProfit' => $totalProfit,
                'activeClientsCount' => $activeClientsCount,
                'activeTicketsCount' => $activeTicketsCount,
                'lowStockCount' => count($lowStockItems)
            ],
            'lowStockAlerts' => array_slice($lowStockItems, 0, 5),
            'monthlySalesChart' => $monthlySales,
            'recentInvoices' => $recentInvoices,
            'recentTickets' => $recentTickets
        ]);
    }

    public function getProfitLoss(array $req) {
        $db = Database::getConnection();

        // 1. Estimated Basis (Accrual)
        // Fetch all invoices that are not cancelled or draft
        $invStmt = $db->prepare("SELECT * FROM `invoices` WHERE `status` NOT IN ('cancelled', 'draft')");
        $invStmt->execute();
        $invoices = $invStmt->fetchAll();

        // Fetch invoice items to calculate estimated COGS
        $estRevenue = 0.0;
        $estCOGS = 0.0;

        foreach ($invoices as $inv) {
            $estRevenue += (float)$inv['totalAmount'];

            // Get items
            $itemsStmt = $db->prepare("
                SELECT ii.quantity, inv.unitCost 
                FROM `invoice_items` ii 
                LEFT JOIN `inventory_items` inv ON ii.itemId = inv.id 
                WHERE ii.invoiceId = :invoiceId
            ");
            $itemsStmt->execute(['invoiceId' => $inv['id']]);
            $items = $itemsStmt->fetchAll();

            foreach ($items as $line) {
                $cost = (float)($line['unitCost'] ?? 0.0);
                $estCOGS += (int)$line['quantity'] * $cost;
            }
        }

        $expStmt = $db->prepare("SELECT SUM(amount) FROM `expenses`");
        $expStmt->execute();
        $expensesSum = (float)$expStmt->fetchColumn();

        $estGrossProfit = $estRevenue - $estCOGS;
        $estNetProfit = $estGrossProfit - $expensesSum;

        // 2. Actual Basis (Cash)
        $allocStmt = $db->prepare("SELECT SUM(grossAmount) as totalRev, SUM(cogsAmount) as totalCogs FROM `payment_allocations`");
        $allocStmt->execute();
        $allocData = $allocStmt->fetch();
        $actRevenue = (float)($allocData['totalRev'] ?? 0.0);
        $actCOGS = (float)($allocData['totalCogs'] ?? 0.0);

        $actGrossProfit = $actRevenue - $actCOGS;
        $actNetProfit = $actGrossProfit - $expensesSum;

        return Response::json([
            'actual' => [
                'revenue' => $actRevenue,
                'cogs' => $actCOGS,
                'grossProfit' => $actGrossProfit,
                'expenses' => $expensesSum,
                'profit' => $actNetProfit
            ],
            'estimated' => [
                'revenue' => $estRevenue,
                'cogs' => $estCOGS,
                'grossProfit' => $estGrossProfit,
                'expenses' => $expensesSum,
                'profit' => $estNetProfit
            ]
        ]);
    }

    public function getInvoiceAging(array $req) {
        $db = Database::getConnection();

        // Get sent or partially paid invoices that are past due
        $stmt = $db->prepare("
            SELECT i.*, c.companyName 
            FROM `invoices` i 
            JOIN `clients` c ON i.clientId = c.id 
            WHERE i.status IN ('sent', 'partially_paid') AND i.dueDate < NOW()
        ");
        $stmt->execute();
        $unpaidInvoices = $stmt->fetchAll();

        $aging = [
            'bucket30' => [],
            'bucket60' => [],
            'bucket90' => []
        ];

        $today = time();

        foreach ($unpaidInvoices as $inv) {
            $dueDateTs = strtotime($inv['dueDate']);
            $diffTime = abs($today - $dueDateTs);
            $diffDays = (int)ceil($diffTime / (60 * 60 * 24));
            $outstanding = (float)$inv['totalAmount'] - (float)$inv['amountPaid'];

            $data = [
                'invoiceNumber' => $inv['invoiceNumber'],
                'companyName' => $inv['companyName'],
                'dueDate' => $inv['dueDate'],
                'overdueDays' => $diffDays,
                'outstanding' => $outstanding
            ];

            if ($diffDays <= 30) {
                $aging['bucket30'][] = $data;
            } else if ($diffDays <= 60) {
                $aging['bucket60'][] = $data;
            } else {
                $aging['bucket90'][] = $data;
            }
        }

        return Response::json($aging);
    }

    public function getStockValuation(array $req) {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT * FROM `inventory_items` WHERE `isTracked` = 1 AND `isActive` = 1");
        $stmt->execute();
        $items = $stmt->fetchAll();

        $totalCostValue = 0.0;
        $totalSellValue = 0.0;
        $totalItemsCount = 0;

        foreach ($items as $item) {
            $cost = (float)$item['unitCost'];
            $sell = (float)$item['sellPrice'];
            $qty = (int)$item['stockQty'];

            $totalCostValue += $qty * $cost;
            $totalSellValue += $qty * $sell;
            $totalItemsCount += $qty;
        }

        return Response::json([
            'totalCostValue' => $totalCostValue,
            'totalSellValue' => $totalSellValue,
            'totalItemsCount' => $totalItemsCount,
            'potentialProfit' => $totalSellValue - $totalCostValue
        ]);
    }

    public function getCashFlow(array $req) {
        $db = Database::getConnection();

        // Cash In from client payments
        $inStmt = $db->prepare("SELECT SUM(amount) FROM `payments`");
        $inStmt->execute();
        $cashIn = (float)$inStmt->fetchColumn();

        // Cash Out from dealer payments
        $outStmt = $db->prepare("SELECT SUM(amount) FROM `dealer_transactions` WHERE `type` = 'payment'");
        $outStmt->execute();
        $cashOut = (float)$outStmt->fetchColumn();

        return Response::json([
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
            'netFlow' => $cashIn - $cashOut
        ]);
    }

    public function getQuotationConversion(array $req) {
        $db = Database::getConnection();

        // Total quotes
        $tStmt = $db->prepare("SELECT COUNT(*) FROM `quotations`");
        $tStmt->execute();
        $totalQuotes = (int)$tStmt->fetchColumn();

        // Converted quotes
        $cStmt = $db->prepare("SELECT COUNT(*) FROM `quotations` WHERE `status` = 'converted'");
        $cStmt->execute();
        $convertedQuotes = (int)$cStmt->fetchColumn();

        $rate = $totalQuotes > 0 ? ($convertedQuotes / $totalQuotes) * 100 : 0.0;

        return Response::json([
            'totalQuotes' => $totalQuotes,
            'convertedQuotes' => $convertedQuotes,
            'rate' => $rate
        ]);
    }

    public function getDealerDues(array $req) {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT `name`, `paymentType`, `balanceDue` FROM `dealers` WHERE `isActive` = 1");
        $stmt->execute();
        $dealers = $stmt->fetchAll();

        foreach ($dealers as &$d) {
            $d['balanceDue'] = (float)$d['balanceDue'];
        }

        return Response::json($dealers);
    }
}
