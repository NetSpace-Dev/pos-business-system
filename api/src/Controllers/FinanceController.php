<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

class FinanceController {
    public function getPartners(array $req) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT * FROM `partners` ORDER BY `name` ASC");
            $partners = $stmt->fetchAll();
            foreach ($partners as &$p) {
                $p['id'] = (int)$p['id'];
                $p['ownershipPct'] = (float)$p['ownershipPct'];
            }
            return Response::json($partners);
        } catch (\Exception $e) {
            error_log('Get partners error: ' . $e->getMessage());
            return Response::error('Failed to retrieve partners', 500);
        }
    }

    public function createPartner(array $req) {
        $body = $req['body'];
        $name = $body['name'] ?? '';
        $ownershipPct = isset($body['ownershipPct']) ? (float)$body['ownershipPct'] : null;
        $status = $body['status'] ?? 'active';

        if (!$name || $ownershipPct === null) {
            return Response::error('Name and ownership percentage are required', 400);
        }

        if ($ownershipPct < 0 || $ownershipPct > 100) {
            return Response::error('Ownership percentage must be between 0 and 100', 400);
        }

        $db = Database::getConnection();

        // Check active sum
        $stmt = $db->query("SELECT SUM(ownershipPct) FROM `partners` WHERE `status` = 'active'");
        $currentSum = (float)$stmt->fetchColumn();

        if ($status !== 'inactive' && ($currentSum + $ownershipPct) > 100.0) {
            return Response::error("Total ownership of active partners cannot exceed 100%. Current sum: {$currentSum}%.", 400);
        }

        try {
            $ins = $db->prepare("
                INSERT INTO `partners` (`name`, `ownershipPct`, `status`, `createdAt`, `updatedAt`)
                VALUES (:name, :ownershipPct, :status, NOW(), NOW())
            ");
            $ins->execute([
                'name' => $name,
                'ownershipPct' => $ownershipPct,
                'status' => $status
            ]);
            $newId = (int)$db->lastInsertId();

            $stmt = $db->prepare("SELECT * FROM `partners` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $newId]);
            $partner = $stmt->fetch();
            $partner['id'] = (int)$partner['id'];
            $partner['ownershipPct'] = (float)$partner['ownershipPct'];

            return Response::json($partner, 201);
        } catch (\Exception $e) {
            error_log('Create partner error: ' . $e->getMessage());
            return Response::error('Failed to create partner', 500);
        }
    }

    public function updatePartner(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid partner ID', 400);
        }

        $body = $req['body'];
        $name = $body['name'] ?? null;
        $ownershipPct = isset($body['ownershipPct']) ? (float)$body['ownershipPct'] : null;
        $status = $body['status'] ?? null;

        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT * FROM `partners` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Partner not found', 404);
        }

        $pct = ($ownershipPct !== null) ? $ownershipPct : (float)$existing['ownershipPct'];
        if ($pct < 0 || $pct > 100) {
            return Response::error('Ownership percentage must be between 0 and 100', 400);
        }

        $newStatus = $status ?: $existing['status'];

        // Sum other active partners
        $sumStmt = $db->prepare("SELECT SUM(ownershipPct) FROM `partners` WHERE `status` = 'active' AND `id` != :id");
        $sumStmt->execute(['id' => $id]);
        $currentSum = (float)$sumStmt->fetchColumn();

        if ($newStatus === 'active' && ($currentSum + $pct) > 100.0) {
            return Response::error("Total ownership of active partners cannot exceed 100%. Current sum of other active partners: {$currentSum}%.", 400);
        }

        $partnerName = $name ?: $existing['name'];

        try {
            $upd = $db->prepare("
                UPDATE `partners` 
                SET `name` = :name, `ownershipPct` = :ownershipPct, `status` = :status, `updatedAt` = NOW()
                WHERE `id` = :id
            ");
            $upd->execute([
                'name' => $partnerName,
                'ownershipPct' => $pct,
                'status' => $newStatus,
                'id' => $id
            ]);

            $stmt->execute(['id' => $id]);
            $updated = $stmt->fetch();
            $updated['id'] = (int)$updated['id'];
            $updated['ownershipPct'] = (float)$updated['ownershipPct'];

            return Response::json($updated);
        } catch (\Exception $e) {
            error_log('Update partner error: ' . $e->getMessage());
            return Response::error('Failed to update partner', 500);
        }
    }

    public function getAllocations(array $req) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT pa.*, p.amount as paymentAmount, p.method, p.paidDate, p.reference, p.notes,
                       i.invoiceNumber, c.companyName
                FROM `payment_allocations` pa 
                JOIN `payments` p ON pa.paymentId = p.id 
                JOIN `invoices` i ON p.invoiceId = i.id 
                JOIN `clients` c ON i.clientId = c.id 
                ORDER BY pa.createdAt DESC
            ");
            $allocations = $stmt->fetchAll();

            $result = [];
            foreach ($allocations as $row) {
                $result[] = [
                    'id' => (int)$row['id'],
                    'paymentId' => (int)$row['paymentId'],
                    'grossAmount' => (float)$row['grossAmount'],
                    'cogsAmount' => (float)$row['cogsAmount'],
                    'taxAmount' => (float)$row['taxAmount'],
                    'netAmount' => (float)$row['netAmount'],
                    'reinvestPctUsed' => (float)$row['reinvestPctUsed'],
                    'reinvestAmount' => (float)$row['reinvestAmount'],
                    'partnerPoolAmount' => (float)$row['partnerPoolAmount'],
                    'createdAt' => $row['createdAt'],
                    'payment' => [
                        'id' => (int)$row['paymentId'],
                        'amount' => (float)$row['paymentAmount'],
                        'method' => $row['method'],
                        'paidDate' => $row['paidDate'],
                        'reference' => $row['reference'],
                        'notes' => $row['notes'],
                        'invoice' => [
                            'invoiceNumber' => $row['invoiceNumber'],
                            'client' => [
                                'companyName' => $row['companyName']
                            ]
                        ]
                    ]
                ];
            }

            return Response::json($result);
        } catch (\Exception $e) {
            error_log('Get allocations error: ' . $e->getMessage());
            return Response::error('Failed to retrieve profit allocations', 500);
        }
    }

    public function getPayouts(array $req) {
        try {
            $db = Database::getConnection();

            // 1. Fetch payouts with partner and allocation info
            $stmt = $db->query("
                SELECT pp.*, p.name as partnerName, p.ownershipPct,
                       pa.grossAmount, pa.cogsAmount, pa.taxAmount,
                       pay.invoiceId, inv.invoiceNumber
                FROM `partner_payouts` pp
                JOIN `partners` p ON pp.partnerId = p.id
                JOIN `payment_allocations` pa ON pp.allocationId = pa.id
                JOIN `payments` pay ON pa.paymentId = pay.id
                JOIN `invoices` inv ON pay.invoiceId = inv.id
                ORDER BY pp.createdAt DESC
            ");
            $payouts = $stmt->fetchAll();

            // Fetch expenses to calculate prop expense ratio
            $expStmt = $db->query("SELECT SUM(amount) FROM `expenses`");
            $totalExpenses = (float)$expStmt->fetchColumn();

            $revStmt = $db->query("SELECT SUM(grossAmount) FROM `payment_allocations`");
            $totalRevenue = (float)$revStmt->fetchColumn();

            $adjustedPayouts = [];
            foreach ($payouts as $p) {
                $paymentAmount = (float)$p['grossAmount'];
                $ratio = $totalRevenue > 0.0 ? ($paymentAmount / $totalRevenue) : 0.0;
                $propExpense = $totalExpenses * $ratio;

                $paymentCOGS = (float)$p['cogsAmount'];
                $paymentTax = (float)$p['taxAmount'];
                
                // Net Amount = Revenue - COGS - Tax - Proportional Expense
                $netAmount = $paymentAmount - $paymentCOGS - $paymentTax - $propExpense;
                $partnerPoolAmount = $netAmount * 0.50;
                $partnerAmount = $partnerPoolAmount * ((float)$p['ownershipPct'] / 100.0);

                $adjustedPayouts[] = [
                    'id' => (int)$p['id'],
                    'partnerId' => (int)$p['partnerId'],
                    'allocationId' => (int)$p['allocationId'],
                    'amount' => number_format($partnerAmount, 2, '.', ''),
                    'status' => $p['status'],
                    'paidDate' => $p['paidDate'],
                    'createdAt' => $p['createdAt'],
                    'updatedAt' => $p['updatedAt'],
                    'partner' => [
                        'id' => (int)$p['partnerId'],
                        'name' => $p['partnerName'],
                        'ownershipPct' => (float)$p['ownershipPct']
                    ],
                    'allocation' => [
                        'id' => (int)$p['allocationId'],
                        'grossAmount' => (float)$p['grossAmount'],
                        'cogsAmount' => (float)$p['cogsAmount'],
                        'taxAmount' => (float)$p['taxAmount'],
                        'payment' => [
                            'invoice' => [
                                'invoiceNumber' => $p['invoiceNumber']
                            ]
                        ]
                    ]
                ];
            }

            return Response::json($adjustedPayouts);
        } catch (\Exception $e) {
            error_log('Get payouts error: ' . $e->getMessage());
            return Response::error('Failed to retrieve partner payouts', 500);
        }
    }

    public function markPayoutPaid(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid payout ID', 400);
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `partner_payouts` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $payout = $stmt->fetch();

        if (!$payout) {
            return Response::error('Partner payout not found', 404);
        }

        if ($payout['status'] === 'paid') {
            return Response::error('Payout is already marked as paid', 400);
        }

        $userEmail = $req['user']['email'] ?? 'admin@pos.com';
        $userId = $req['user']['id'] ?? 1;

        try {
            $db->beginTransaction();

            $upd = $db->prepare("UPDATE `partner_payouts` SET `status` = 'paid', `paidDate` = NOW(), `updatedAt` = NOW() WHERE `id` = :id");
            $upd->execute(['id' => $id]);

            // Add Audit Log
            $aud = $db->prepare("
                INSERT INTO `audit_logs` (`userId`, `userEmail`, `action`, `details`, `createdAt`)
                VALUES (:userId, :userEmail, 'PAY_PARTNER', :details, NOW())
            ");
            $aud->execute([
                'userId' => $userId,
                'userEmail' => $userEmail,
                'details' => "Marked payout ID {$payout['id']} (Amount: LKR {$payout['amount']}) as Paid."
            ]);

            $db->commit();

            $stmt->execute(['id' => $id]);
            $updated = $stmt->fetch();
            $updated['id'] = (int)$updated['id'];
            $updated['partnerId'] = (int)$updated['partnerId'];
            $updated['allocationId'] = (int)$updated['allocationId'];
            $updated['amount'] = (float)$updated['amount'];

            return Response::json($updated);
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('Mark payout paid error: ' . $e->getMessage());
            return Response::error('Failed to update payout status', 500);
        }
    }

    public function getReinvestmentStatement(array $req) {
        try {
            $db = Database::getConnection();

            // Fetch allocations to compute reinvestments
            $allocStmt = $db->query("
                SELECT pa.*, pay.invoiceId, inv.invoiceNumber
                FROM `payment_allocations` pa
                JOIN `payments` pay ON pa.paymentId = pay.id
                JOIN `invoices` inv ON pay.invoiceId = inv.id
                ORDER BY pa.createdAt DESC
            ");
            $additions = $allocStmt->fetchAll();

            // Fetch usages
            $useStmt = $db->query("SELECT * FROM `reinvestment_usage` ORDER BY `usedDate` DESC");
            $usages = $useStmt->fetchAll();
            foreach ($usages as &$u) {
                $u['id'] = (int)$u['id'];
                $u['amountUsed'] = (float)$u['amountUsed'];
            }

            // Expenses and total revenue for proportional calculations
            $expStmt = $db->query("SELECT SUM(amount) FROM `expenses`");
            $totalExpenses = (float)$expStmt->fetchColumn();

            $revStmt = $db->query("SELECT SUM(grossAmount) FROM `payment_allocations`");
            $totalRevenue = (float)$revStmt->fetchColumn();

            $adjustedAdditions = [];
            $totalAdded = 0.0;

            foreach ($additions as $a) {
                $paymentAmount = (float)$a['grossAmount'];
                $ratio = $totalRevenue > 0.0 ? ($paymentAmount / $totalRevenue) : 0.0;
                $propExpense = $totalExpenses * $ratio;

                $paymentCOGS = (float)$a['cogsAmount'];
                $paymentTax = (float)$a['taxAmount'];
                
                $netAmount = $paymentAmount - $paymentCOGS - $paymentTax - $propExpense;
                $reinvestAmount = $netAmount * 0.50;
                $totalAdded += $reinvestAmount;

                $adjustedAdditions[] = [
                    'id' => (int)$a['id'],
                    'reinvestAmount' => number_format($reinvestAmount, 2, '.', ''),
                    'createdAt' => $a['createdAt'],
                    'payment' => [
                        'invoice' => [
                            'invoiceNumber' => $a['invoiceNumber']
                        ]
                    ]
                ];
            }

            $totalUsed = 0.0;
            foreach ($usages as $u) {
                $totalUsed += (float)$u['amountUsed'];
            }

            $runningBalance = $totalAdded - $totalUsed;

            return Response::json([
                'runningBalance' => $runningBalance,
                'totalAdded' => $totalAdded,
                'totalUsed' => $totalUsed,
                'additions' => $adjustedAdditions,
                'usages' => $usages
            ]);
        } catch (\Exception $e) {
            error_log('Get reinvestment statement error: ' . $e->getMessage());
            return Response::error('Failed to retrieve reinvestment statement', 500);
        }
    }

    public function logReinvestmentUsage(array $req) {
        $body = $req['body'];
        $amountUsed = isset($body['amountUsed']) ? (float)$body['amountUsed'] : null;
        $purpose = $body['purpose'] ?? '';
        $usedDate = $body['usedDate'] ?? null;

        if ($amountUsed === null || !$purpose) {
            return Response::error('Amount used and purpose/description are required', 400);
        }

        if ($amountUsed <= 0) {
            return Response::error('Amount used must be a positive number', 400);
        }

        $db = Database::getConnection();

        // Calculate available reinvestment balance
        try {
            $allocStmt = $db->query("SELECT * FROM `payment_allocations`");
            $allocations = $allocStmt->fetchAll();

            $useStmt = $db->query("SELECT * FROM `reinvestment_usage`");
            $usages = $useStmt->fetchAll();

            $expStmt = $db->query("SELECT SUM(amount) FROM `expenses`");
            $totalExpenses = (float)$expStmt->fetchColumn();

            $revStmt = $db->query("SELECT SUM(grossAmount) FROM `payment_allocations`");
            $totalRevenue = (float)$revStmt->fetchColumn();

            $totalAdded = 0.0;
            foreach ($allocations as $a) {
                $paymentAmount = (float)$a['grossAmount'];
                $ratio = $totalRevenue > 0.0 ? ($paymentAmount / $totalRevenue) : 0.0;
                $propExpense = $totalExpenses * $ratio;

                $paymentCOGS = (float)$a['cogsAmount'];
                $paymentTax = (float)$a['taxAmount'];
                $netAmount = $paymentAmount - $paymentCOGS - $paymentTax - $propExpense;
                $totalAdded += ($netAmount * 0.50);
            }

            $totalUsed = 0.0;
            foreach ($usages as $u) {
                $totalUsed += (float)$u['amountUsed'];
            }

            $runningBalance = $totalAdded - $totalUsed;

            if ($amountUsed > $runningBalance) {
                return Response::error("Insufficient funds. Available reinvestment balance is LKR " . number_format($runningBalance, 2, '.', ''), 400);
            }

            $dateStr = $usedDate ? date('Y-m-d H:i:s', strtotime($usedDate)) : date('Y-m-d H:i:s');
            $userEmail = $req['user']['email'] ?? 'admin@pos.com';
            $userId = $req['user']['id'] ?? 1;

            $db->beginTransaction();

            $ins = $db->prepare("
                INSERT INTO `reinvestment_usage` (`amountUsed`, `purpose`, `usedDate`, `createdAt`)
                VALUES (:amount, :purpose, :usedDate, NOW())
            ");
            $ins->execute([
                'amount' => $amountUsed,
                'purpose' => $purpose,
                'usedDate' => $dateStr
            ]);
            $newId = (int)$db->lastInsertId();

            // Log
            $aud = $db->prepare("
                INSERT INTO `audit_logs` (`userId`, `userEmail`, `action`, `details`, `createdAt`)
                VALUES (:userId, :userEmail, 'USE_REINVESTMENT_FUND', :details, NOW())
            ");
            $aud->execute([
                'userId' => $userId,
                'userEmail' => $userEmail,
                'details' => "Spent LKR {$amountUsed} from reinvestment fund. Purpose: {$purpose}"
            ]);

            $db->commit();

            $stmt = $db->prepare("SELECT * FROM `reinvestment_usage` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $newId]);
            $usage = $stmt->fetch();
            $usage['id'] = (int)$usage['id'];
            $usage['amountUsed'] = (float)$usage['amountUsed'];

            return Response::json($usage, 201);
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function getExpenses(array $req) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT * FROM `expenses` ORDER BY `expenseDate` DESC");
            $expenses = $stmt->fetchAll();
            foreach ($expenses as &$e) {
                $e['id'] = (int)$e['id'];
                $e['amount'] = (float)$e['amount'];
            }
            return Response::json($expenses);
        } catch (\Exception $e) {
            error_log('Get expenses error: ' . $e->getMessage());
            return Response::error('Failed to retrieve expenses', 500);
        }
    }

    public function createExpense(array $req) {
        $body = $req['body'];
        $category = $body['category'] ?? '';
        $amount = isset($body['amount']) ? (float)$body['amount'] : null;
        $expenseDate = $body['expenseDate'] ?? '';
        $notes = $body['notes'] ?? null;

        if (!$category || $amount === null || !$expenseDate) {
            return Response::error('Category, amount, and expense date are required', 400);
        }

        if ($amount <= 0) {
            return Response::error('Expense amount must be a positive number', 400);
        }

        $db = Database::getConnection();

        try {
            $ins = $db->prepare("
                INSERT INTO `expenses` (`category`, `amount`, `expenseDate`, `notes`, `createdAt`, `updatedAt`)
                VALUES (:category, :amount, :expenseDate, :notes, NOW(), NOW())
            ");
            $ins->execute([
                'category' => $category,
                'amount' => $amount,
                'expenseDate' => date('Y-m-d H:i:s', strtotime($expenseDate)),
                'notes' => $notes
            ]);
            $newId = (int)$db->lastInsertId();

            $stmt = $db->prepare("SELECT * FROM `expenses` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $newId]);
            $expense = $stmt->fetch();
            $expense['id'] = (int)$expense['id'];
            $expense['amount'] = (float)$expense['amount'];

            return Response::json($expense, 201);
        } catch (\Exception $e) {
            error_log('Create expense error: ' . $e->getMessage());
            return Response::error('Failed to log operational expense', 500);
        }
    }

    public function updateExpense(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid expense ID', 400);
        }

        $body = $req['body'];
        $category = $body['category'] ?? '';
        $amount = isset($body['amount']) ? (float)$body['amount'] : null;
        $expenseDate = $body['expenseDate'] ?? '';
        $notes = $body['notes'] ?? null;

        if (!$category || $amount === null || !$expenseDate) {
            return Response::error('Category, amount, and expense date are required', 400);
        }

        if ($amount <= 0) {
            return Response::error('Expense amount must be a positive number', 400);
        }

        $db = Database::getConnection();

        try {
            $upd = $db->prepare("
                UPDATE `expenses` 
                SET `category` = :category, `amount` = :amount, `expenseDate` = :expenseDate, 
                    `notes` = :notes, `updatedAt` = NOW()
                WHERE `id` = :id
            ");
            $upd->execute([
                'category' => $category,
                'amount' => $amount,
                'expenseDate' => date('Y-m-d H:i:s', strtotime($expenseDate)),
                'notes' => $notes,
                'id' => $id
            ]);

            $stmt = $db->prepare("SELECT * FROM `expenses` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $id]);
            $expense = $stmt->fetch();
            $expense['id'] = (int)$expense['id'];
            $expense['amount'] = (float)$expense['amount'];

            return Response::json($expense);
        } catch (\Exception $e) {
            error_log('Update expense error: ' . $e->getMessage());
            return Response::error('Failed to update operational expense', 500);
        }
    }

    public function deleteExpense(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid expense ID', 400);
        }

        $db = Database::getConnection();

        try {
            $del = $db->prepare("DELETE FROM `expenses` WHERE `id` = :id");
            $del->execute(['id' => $id]);
            return Response::json(['message' => 'Operational expense deleted successfully']);
        } catch (\Exception $e) {
            error_log('Delete expense error: ' . $e->getMessage());
            return Response::error('Failed to delete operational expense', 500);
        }
    }
}
