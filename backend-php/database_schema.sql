-- POS Business Management System - Database Schema (MySQL/MariaDB)

-- Disable foreign key checks temporarily to allow clean drops
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `invoice_reminders`;
DROP TABLE IF EXISTS `sms_templates`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `expenses`;
DROP TABLE IF EXISTS `reinvestment_usage`;
DROP TABLE IF EXISTS `partner_payouts`;
DROP TABLE IF EXISTS `partners`;
DROP TABLE IF EXISTS `payment_allocations`;
DROP TABLE IF EXISTS `setting_history`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `project_deadlines`;
DROP TABLE IF EXISTS `ticket_updates`;
DROP TABLE IF EXISTS `support_tickets`;
DROP TABLE IF EXISTS `dealer_transactions`;
DROP TABLE IF EXISTS `dealers`;
DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `inventory_items`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `recurring_invoices`;
DROP TABLE IF EXISTS `invoice_items`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `quotation_items`;
DROP TABLE IF EXISTS `quotations`;
DROP TABLE IF EXISTS `clients`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Roles
CREATE TABLE `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) UNIQUE NOT NULL,
  `permissionSet` TEXT NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Users
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) UNIQUE NOT NULL,
  `phone` VARCHAR(191) NULL,
  `passwordHash` VARCHAR(191) NOT NULL,
  `role` VARCHAR(191) NOT NULL DEFAULT 'admin',
  `roleId` INT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'active',
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_users_roleId` FOREIGN KEY (`roleId`) REFERENCES `roles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Clients
CREATE TABLE `clients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `companyName` VARCHAR(191) NOT NULL,
  `contactPerson` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NULL,
  `address` TEXT NULL,
  `notes` TEXT NULL,
  `taxNumber` VARCHAR(191) NULL,
  `isActive` TINYINT(1) NOT NULL DEFAULT 1,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  `textLkUid` VARCHAR(191) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Quotations
CREATE TABLE `quotations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `quoteNumber` VARCHAR(191) UNIQUE NOT NULL,
  `clientId` INT NOT NULL,
  `issueDate` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `validUntil` DATETIME(3) NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'draft',
  `subtotal` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `taxAmount` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `totalAmount` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `terms` TEXT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_quotations_clientId` FOREIGN KEY (`clientId`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Dealers
CREATE TABLE `dealers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `contactPerson` VARCHAR(191) NULL,
  `phone` VARCHAR(191) NULL,
  `email` VARCHAR(191) NULL,
  `address` TEXT NULL,
  `paymentType` VARCHAR(191) NOT NULL DEFAULT 'credit',
  `balanceDue` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `isActive` TINYINT(1) NOT NULL DEFAULT 1,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Inventory Items
CREATE TABLE `inventory_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sku` VARCHAR(191) UNIQUE NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `category` VARCHAR(191) NOT NULL,
  `description` TEXT NULL,
  `dealerId` INT NULL,
  `stockQty` INT NOT NULL DEFAULT 0,
  `reorderLevel` INT NOT NULL DEFAULT 5,
  `unitCost` DECIMAL(12, 2) NOT NULL,
  `sellPrice` DECIMAL(12, 2) NOT NULL,
  `warrantyMonths` INT NULL,
  `licenseCount` INT NULL,
  `renewalDate` DATETIME(3) NULL,
  `isTracked` TINYINT(1) NOT NULL DEFAULT 1,
  `isActive` TINYINT(1) NOT NULL DEFAULT 1,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_inventory_items_dealerId` FOREIGN KEY (`dealerId`) REFERENCES `dealers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Quotation Items
CREATE TABLE `quotation_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `quotationId` INT NOT NULL,
  `itemId` INT NULL,
  `description` TEXT NOT NULL,
  `quantity` INT NOT NULL,
  `unitPrice` DECIMAL(12, 2) NOT NULL,
  `lineTotal` DECIMAL(12, 2) NOT NULL,
  CONSTRAINT `fk_quotation_items_quotationId` FOREIGN KEY (`quotationId`) REFERENCES `quotations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_quotation_items_itemId` FOREIGN KEY (`itemId`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Recurring Invoices
CREATE TABLE `recurring_invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clientId` INT NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `description` TEXT NULL,
  `amount` DECIMAL(12, 2) NOT NULL,
  `frequency` VARCHAR(191) NOT NULL,
  `startDate` DATETIME(3) NOT NULL,
  `nextRunDate` DATETIME(3) NOT NULL,
  `endDate` DATETIME(3) NULL,
  `isActive` TINYINT(1) NOT NULL DEFAULT 1,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_recurring_invoices_clientId` FOREIGN KEY (`clientId`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Invoices
CREATE TABLE `invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoiceNumber` VARCHAR(191) UNIQUE NOT NULL,
  `clientId` INT NOT NULL,
  `quotationId` INT NULL UNIQUE,
  `issueDate` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `dueDate` DATETIME(3) NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'draft',
  `subtotal` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `taxAmount` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `totalAmount` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `amountPaid` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `paymentTerms` VARCHAR(191) NULL,
  `notes` TEXT NULL,
  `isRecurringGenerated` TINYINT(1) NOT NULL DEFAULT 0,
  `recurringInvoiceId` INT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_invoices_clientId` FOREIGN KEY (`clientId`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_invoices_quotationId` FOREIGN KEY (`quotationId`) REFERENCES `quotations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_invoices_recurringInvoiceId` FOREIGN KEY (`recurringInvoiceId`) REFERENCES `recurring_invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Invoice Items
CREATE TABLE `invoice_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoiceId` INT NOT NULL,
  `itemId` INT NULL,
  `description` TEXT NOT NULL,
  `quantity` INT NOT NULL,
  `unitPrice` DECIMAL(12, 2) NOT NULL,
  `lineTotal` DECIMAL(12, 2) NOT NULL,
  CONSTRAINT `fk_invoice_items_invoiceId` FOREIGN KEY (`invoiceId`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_invoice_items_itemId` FOREIGN KEY (`itemId`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Payments
CREATE TABLE `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoiceId` INT NOT NULL,
  `amount` DECIMAL(12, 2) NOT NULL,
  `method` VARCHAR(191) NOT NULL,
  `paidDate` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `reference` VARCHAR(191) NULL,
  `notes` TEXT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_payments_invoiceId` FOREIGN KEY (`invoiceId`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Stock Movements
CREATE TABLE `stock_movements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `itemId` INT NOT NULL,
  `type` VARCHAR(191) NOT NULL,
  `quantity` INT NOT NULL,
  `reason` VARCHAR(191) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_stock_movements_itemId` FOREIGN KEY (`itemId`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Dealer Transactions
CREATE TABLE `dealer_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `dealerId` INT NOT NULL,
  `type` VARCHAR(191) NOT NULL,
  `amount` DECIMAL(12, 2) NOT NULL,
  `description` TEXT NULL,
  `txnDate` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_dealer_transactions_dealerId` FOREIGN KEY (`dealerId`) REFERENCES `dealers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Support Tickets
CREATE TABLE `support_tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticketNumber` VARCHAR(191) UNIQUE NOT NULL,
  `clientId` INT NOT NULL,
  `problemDesc` TEXT NOT NULL,
  `priority` VARCHAR(191) NOT NULL DEFAULT 'medium',
  `status` VARCHAR(191) NOT NULL DEFAULT 'open',
  `solutionNotes` TEXT NULL,
  `handledBy` VARCHAR(191) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `closedAt` DATETIME(3) NULL,
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_support_tickets_clientId` FOREIGN KEY (`clientId`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Ticket Updates
CREATE TABLE `ticket_updates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticketId` INT NOT NULL,
  `note` TEXT NOT NULL,
  `statusChange` VARCHAR(191) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_ticket_updates_ticketId` FOREIGN KEY (`ticketId`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Project Deadlines
CREATE TABLE `project_deadlines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clientId` INT NOT NULL,
  `invoiceId` INT NULL,
  `description` TEXT NOT NULL,
  `deadlineDate` DATETIME(3) NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'pending',
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_project_deadlines_clientId` FOREIGN KEY (`clientId`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_project_deadlines_invoiceId` FOREIGN KEY (`invoiceId`) REFERENCES `invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. System Settings
CREATE TABLE `system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(191) UNIQUE NOT NULL,
  `value` TEXT NOT NULL,
  `updatedBy` VARCHAR(191) NULL,
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Setting History
CREATE TABLE `setting_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(191) NOT NULL,
  `oldValue` TEXT NULL,
  `newValue` TEXT NOT NULL,
  `updatedBy` VARCHAR(191) NULL,
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Payment Allocations
CREATE TABLE `payment_allocations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `paymentId` INT UNIQUE NOT NULL,
  `grossAmount` DECIMAL(12, 2) NOT NULL,
  `cogsAmount` DECIMAL(12, 2) NOT NULL,
  `taxAmount` DECIMAL(12, 2) NOT NULL,
  `netAmount` DECIMAL(12, 2) NOT NULL,
  `reinvestPctUsed` DECIMAL(5, 2) NOT NULL,
  `reinvestAmount` DECIMAL(12, 2) NOT NULL,
  `partnerPoolAmount` DECIMAL(12, 2) NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_payment_allocations_paymentId` FOREIGN KEY (`paymentId`) REFERENCES `payments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Partners
CREATE TABLE `partners` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) UNIQUE NOT NULL,
  `ownershipPct` DECIMAL(5, 2) NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'active',
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Partner Payouts
CREATE TABLE `partner_payouts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `partnerId` INT NOT NULL,
  `allocationId` INT NOT NULL,
  `amount` DECIMAL(12, 2) NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'pending',
  `paidDate` DATETIME(3) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_partner_payouts_partnerId` FOREIGN KEY (`partnerId`) REFERENCES `partners` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_partner_payouts_allocationId` FOREIGN KEY (`allocationId`) REFERENCES `payment_allocations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Reinvestment Fund Usage
CREATE TABLE `reinvestment_usage` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `amountUsed` DECIMAL(12, 2) NOT NULL,
  `purpose` VARCHAR(191) NOT NULL,
  `usedDate` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. Expenses
CREATE TABLE `expenses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category` VARCHAR(191) NOT NULL,
  `amount` DECIMAL(12, 2) NOT NULL,
  `expenseDate` DATETIME(3) NOT NULL,
  `notes` TEXT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. Audit Logs
CREATE TABLE `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `userId` INT NULL,
  `userEmail` VARCHAR(191) NULL,
  `action` VARCHAR(191) NOT NULL,
  `details` TEXT NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25. SMS Templates
CREATE TABLE `sms_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) UNIQUE NOT NULL,
  `body` TEXT NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. Invoice Reminders
CREATE TABLE `invoice_reminders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoiceId` INT NOT NULL,
  `sentBy` VARCHAR(191) NOT NULL,
  `message` TEXT NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'sent',
  `sentAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT `fk_invoice_reminders_invoiceId` FOREIGN KEY (`invoiceId`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
