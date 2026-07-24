<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Utils\Response;

// spl autoloader setup
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

Database::loadEnv();

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getConnection();

    echo json_encode(["message" => "Clearing existing data..."]) . "\n";
    flush();

    // Disable foreign key checks to truncate cleanly
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    $tables = [
        'invoice_reminders', 'sms_templates', 'audit_logs', 'expenses', 
        'reinvestment_usage', 'partner_payouts', 'partners', 'payment_allocations', 
        'setting_history', 'system_settings', 'roles', 'project_deadlines', 
        'ticket_updates', 'support_tickets', 'dealer_transactions', 'dealers', 
        'stock_movements', 'inventory_items', 'payments', 'recurring_invoices', 
        'invoice_items', 'invoices', 'quotation_items', 'quotations', 'clients', 'users'
    ];
    
    foreach ($tables as $t) {
        $db->exec("TRUNCATE TABLE `$t`");
    }
    
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo json_encode(["message" => "Cleared database."]) . "\n";
    flush();

    // 2. Seed System Settings
    echo json_encode(["message" => "Seeding system settings..."]) . "\n";
    flush();
    $defaultSettings = [
        ['reinvestment_percentage', '50'],
        ['tax_percentage', '15'],
        ['invoice_prefix', 'INV'],
        ['default_payment_terms', 'Net 14'],
        ['default_reorder_level', '5']
    ];
    
    $insSetting = $db->prepare("INSERT INTO `system_settings` (`key`, `value`, `updatedBy`, `updatedAt`) VALUES (?, ?, 'system@pos.com', NOW())");
    foreach ($defaultSettings as $s) {
        $insSetting->execute($s);
    }

    // 3. Seed Custom Roles & Permissions
    echo json_encode(["message" => "Seeding roles..."]) . "\n";
    flush();
    $superAdminPermissions = [
        'clients' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'invoices' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'inventory' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'dealers' => ['view' => true, 'create' => true, 'edit' => true],
        'tickets' => ['view' => true, 'create' => true, 'edit' => true],
        'finance' => ['allocations' => true, 'settings' => true, 'partners' => true],
        'reports' => ['view' => true, 'export' => true],
        'users' => ['manage' => true],
        'roles' => ['manage' => true]
    ];

    $staffPermissions = [
        'clients' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'invoices' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'inventory' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'dealers' => ['view' => true, 'create' => false, 'edit' => false],
        'tickets' => ['view' => true, 'create' => true, 'edit' => true],
        'finance' => ['allocations' => false, 'settings' => false, 'partners' => false],
        'reports' => ['view' => true, 'export' => false],
        'users' => ['manage' => false],
        'roles' => ['manage' => false]
    ];

    $financePermissions = [
        'clients' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'invoices' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false],
        'inventory' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'dealers' => ['view' => true, 'create' => false, 'edit' => false],
        'tickets' => ['view' => true, 'create' => false, 'edit' => false],
        'finance' => ['allocations' => true, 'settings' => true, 'partners' => true],
        'reports' => ['view' => true, 'export' => true],
        'users' => ['manage' => false],
        'roles' => ['manage' => false]
    ];

    $insRole = $db->prepare("INSERT INTO `roles` (`name`, `permissionSet`, `createdAt`, `updatedAt`) VALUES (?, ?, NOW(), NOW())");
    
    $insRole->execute(['Super Admin', json_encode($superAdminPermissions)]);
    $adminRoleId = (int)$db->lastInsertId();

    $insRole->execute(['Office Staff', json_encode($staffPermissions)]);
    $staffRoleId = (int)$db->lastInsertId();

    $insRole->execute(['Finance Officer', json_encode($financePermissions)]);
    $financeRoleId = (int)$db->lastInsertId();

    // 4. Create Users linked to Roles
    echo json_encode(["message" => "Seeding users..."]) . "\n";
    flush();
    $adminHash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 10]);
    $staffHash = password_hash('Staff@123', PASSWORD_BCRYPT, ['cost' => 10]);

    $insUser = $db->prepare("
        INSERT INTO `users` (`name`, `email`, `passwordHash`, `role`, `roleId`, `status`, `createdAt`)
        VALUES (?, ?, ?, ?, ?, 'active', NOW())
    ");
    $insUser->execute(['System Admin', 'admin@pos.com', $adminHash, 'admin', $adminRoleId]);
    $insUser->execute(['Sales Assistant', 'staff@pos.com', $staffHash, 'staff', $staffRoleId]);

    // 5. Seed Partners
    echo json_encode(["message" => "Seeding partners..."]) . "\n";
    flush();
    $insPartner = $db->prepare("INSERT INTO `partners` (`name`, `ownershipPct`, `status`, `createdAt`, `updatedAt`) VALUES (?, ?, 'active', NOW(), NOW())");
    $insPartner->execute(['Partner A', 60.00]);
    $insPartner->execute(['Partner B', 40.00]);

    // 6. Create Dealers
    echo json_encode(["message" => "Seeding dealers..."]) . "\n";
    flush();
    $insDealer = $db->prepare("
        INSERT INTO `dealers` (`name`, `contactPerson`, `phone`, `email`, `address`, `paymentType`, `balanceDue`, `isActive`, `createdAt`, `updatedAt`)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");
    $insDealer->execute(['Abans IT Distribution', 'Mr. Perera', '+94771234567', 'perera@abansit.lk', 'No 45, Galle Road, Colombo 03', 'credit', 25000.00]);
    $dealer1Id = (int)$db->lastInsertId();

    $insDealer->execute(['Singer Sri Lanka PLC', 'Mrs. Jayawardene', '+94112300400', 'corporate@singer.lk', 'No 112, Havelock Road, Colombo 05', 'cash', 0.00]);
    $dealer2Id = (int)$db->lastInsertId();

    // 7. Create Clients
    echo json_encode(["message" => "Seeding clients..."]) . "\n";
    flush();
    $insClient = $db->prepare("
        INSERT INTO `clients` (`companyName`, `contactPerson`, `phone`, `email`, `address`, `notes`, `isActive`, `createdAt`, `updatedAt`)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");
    $insClient->execute(['Kandyanpo Enterprises', 'Kamal Bandara', '+94711112222', 'kamal@kandyanpo.lk', '12 Main Street, Kandy', 'Key client. Prefers bank transfer.']);
    $client1Id = (int)$db->lastInsertId();

    $insClient->execute(['Greenfield Tea Exporters', 'Sarah Fernando', '+94723334444', 'logistics@greenfieldtea.com', '45/A, Nuwara Eliya Road, Hatton', 'Requires detailed invoice copies.']);
    $client2Id = (int)$db->lastInsertId();

    // 8. Create Inventory Items
    echo json_encode(["message" => "Seeding inventory..."]) . "\n";
    flush();
    $insItem = $db->prepare("
        INSERT INTO `inventory_items` (
            `sku`, `name`, `category`, `description`, `dealerId`, `stockQty`, 
            `reorderLevel`, `unitCost`, `sellPrice`, `warrantyMonths`, `isTracked`, `isActive`, `createdAt`, `updatedAt`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");
    $insItem->execute(['PRN-THR-001', 'POS 80mm Thermal Receipt Printer', 'hardware', 'High-speed desktop thermal printer with auto-cutter.', $dealer1Id, 8, 3, 4500.00, 7500.00, 12, 1]);
    $item1Id = (int)$db->lastInsertId();

    $insItem->execute(['SCN-BAR-USB', 'Handheld Barcode Scanner USB', 'hardware', '1D/2D wired barcode reader with stand.', $dealer1Id, 15, 5, 2000.00, 3800.00, 6, 1]);
    $item2Id = (int)$db->lastInsertId();

    $insItem->execute(['LIC-POS-ENT', 'POS Business Suite Enterprise License', 'software', '1-Year license subscription for POS client terminal.', $dealer2Id, 0, 0, 12000.00, 24000.00, 0, 0]);
    $item3Id = (int)$db->lastInsertId();

    // 9. Stock Movements
    echo json_encode(["message" => "Seeding stock movements..."]) . "\n";
    flush();
    $insSm = $db->prepare("INSERT INTO `stock_movements` (`itemId`, `type`, `quantity`, `reason`, `createdAt`) VALUES (?, ?, ?, ?, NOW())");
    $insSm->execute([$item1Id, 'in', 8, 'Initial database seed stock load']);
    $insSm->execute([$item2Id, 'in', 15, 'Initial database seed stock load']);

    // 10. Seed one Support Ticket
    echo json_encode(["message" => "Seeding support tickets..."]) . "\n";
    flush();
    $insTkt = $db->prepare("
        INSERT INTO `support_tickets` (`ticketNumber`, `clientId`, `problemDesc`, `priority`, `status`, `createdAt`, `updatedAt`)
        VALUES (?, ?, ?, ?, 'open', NOW(), NOW())
    ");
    $insTkt->execute(['TK-2026-0001', $client1Id, 'Receipt printer printing blank pages after driver update.', 'high']);
    $tktId = (int)$db->lastInsertId();

    $insTktUp = $db->prepare("INSERT INTO `ticket_updates` (`ticketId`, `note`, `statusChange`, `createdAt`) VALUES (?, ?, ?, NOW())");
    $insTktUp->execute([$tktId, 'Ticket logged. Assigned to support queue.', 'open']);

    echo json_encode(["status" => "success", "message" => "Database seeded successfully!"]) . "\n";

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Critical seeding failure", "details" => $e->getMessage()]);
}
