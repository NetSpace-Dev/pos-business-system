<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Utils\Response;
use App\Utils\DocNumber;
use App\Router;

// Setup custom spl autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Load Env
Database::loadEnv();

// Simple optional security token check
$cronSecret = Database::getEnv('CRON_SECRET');
if ($cronSecret && ($_GET['secret'] ?? '') !== $cronSecret) {
    Response::error('Forbidden: Invalid cron secret key', 403);
}

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getConnection();
    
    echo json_encode(["message" => "Starting recurring invoice generation cron..."]) . "\n";
    flush();

    $todayStr = date('Y-m-d H:i:s');
    
    // Find active configurations to process
    $stmt = $db->prepare("
        SELECT * FROM `recurring_invoices` 
        WHERE `isActive` = 1 
          AND `nextRunDate` <= :today 
          AND (`endDate` IS NULL OR `endDate` >= :today)
    ");
    $stmt->execute(['today' => $todayStr]);
    $activeConfigs = $stmt->fetchAll();

    echo json_encode(["message" => "Found " . count($activeConfigs) . " configurations to process."]) . "\n";
    flush();

    $successCount = 0;

    foreach ($activeConfigs as $config) {
        try {
            $db->beginTransaction();

            $nextRunTs = strtotime($config['nextRunDate']);
            $currentYear = date('Y', $nextRunTs);
            $yearStart = "$currentYear-01-01 00:00:00";
            $yearEnd = "$currentYear-12-31 23:59:59";

            // Count invoices in the year of nextRunDate for sequence number
            $countStmt = $db->prepare("SELECT COUNT(*) FROM `invoices` WHERE `issueDate` >= :yearStart AND `issueDate` <= :yearEnd");
            $countStmt->execute(['yearStart' => $yearStart, 'yearEnd' => $yearEnd]);
            $count = (int)$countStmt->fetchColumn();

            $invoiceNumber = DocNumber::generate('INV', $count + 1);

            // Due date is 14 days from issue date (nextRunDate)
            $dueDateTs = $nextRunTs + (14 * 24 * 60 * 60);
            $dueDateStr = date('Y-m-d H:i:s', $dueDateTs);

            // 1. Create Invoice
            $insInv = $db->prepare("
                INSERT INTO `invoices` (
                    `invoiceNumber`, `clientId`, `issueDate`, `dueDate`, `status`, 
                    `subtotal`, `taxAmount`, `totalAmount`, `amountPaid`, `notes`, 
                    `isRecurringGenerated`, `recurringInvoiceId`, `createdAt`, `updatedAt`
                ) VALUES (
                    :invoiceNumber, :clientId, :issueDate, :dueDate, 'draft', 
                    :amount, 0.00, :amount, 0.00, :notes, 
                    1, :riId, NOW(), NOW()
                )
            ");
            
            $notes = "Auto-generated from recurring billing template: " . $config['title'];
            
            $insInv->execute([
                'invoiceNumber' => $invoiceNumber,
                'clientId' => $config['clientId'],
                'issueDate' => $config['nextRunDate'],
                'dueDate' => $dueDateStr,
                'amount' => $config['amount'],
                'notes' => $notes,
                'riId' => $config['id']
            ]);

            $newInvoiceId = (int)$db->lastInsertId();

            // 2. Create Invoice Item
            $insItem = $db->prepare("
                INSERT INTO `invoice_items` (`invoiceId`, `itemId`, `description`, `quantity`, `unitPrice`, `lineTotal`)
                VALUES (:invoiceId, NULL, :description, 1, :amount, :amount)
            ");
            
            $description = $config['title'] . " - " . ($config['description'] ?: 'Recurring fee');
            
            $insItem->execute([
                'invoiceId' => $newInvoiceId,
                'description' => $description,
                'amount' => $config['amount']
            ]);

            // 3. Compute next run date
            $nextRunDateTime = new \DateTime($config['nextRunDate']);
            if ($config['frequency'] === 'monthly') {
                $nextRunDateTime->modify('+1 month');
            } else if ($config['frequency'] === 'quarterly') {
                $nextRunDateTime->modify('+3 months');
            } else if ($config['frequency'] === 'yearly') {
                $nextRunDateTime->modify('+1 year');
            }
            $nextRunStr = $nextRunDateTime->format('Y-m-d H:i:s');

            // Check if next run date exceeds end date
            $isActive = 1;
            if ($config['endDate'] && $nextRunDateTime > new \DateTime($config['endDate'])) {
                $isActive = 0;
            }

            // 4. Update config nextRunDate and isActive status
            $updConfig = $db->prepare("
                UPDATE `recurring_invoices` 
                SET `nextRunDate` = :nextRun, `isActive` = :isActive, `updatedAt` = NOW() 
                WHERE `id` = :id
            ");
            $updConfig->execute([
                'nextRun' => $nextRunStr,
                'isActive' => $isActive,
                'id' => $config['id']
            ]);

            $db->commit();
            
            echo json_encode([
                "status" => "success", 
                "message" => "Generated Invoice $invoiceNumber for Client ID {$config['clientId']} ({$config['title']})"
            ]) . "\n";
            flush();
            $successCount++;
        } catch (\Exception $configErr) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Failed to process recurring config ID {$config['id']}: " . $configErr->getMessage());
            echo json_encode([
                "status" => "error", 
                "message" => "Failed configuration ID {$config['id']}: " . $configErr->getMessage()
            ]) . "\n";
            flush();
        }
    }

    echo json_encode(["status" => "completed", "processed" => count($activeConfigs), "generated" => $successCount]) . "\n";

} catch (\Exception $e) {
    error_log("Cron execution critical error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Critical cron failure", "details" => $e->getMessage()]);
}
