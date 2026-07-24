-- AlterTable
ALTER TABLE `clients` ADD COLUMN `taxNumber` VARCHAR(191) NULL;

-- AlterTable
ALTER TABLE `inventory_items` ADD COLUMN `licenseCount` INTEGER NULL,
    ADD COLUMN `renewalDate` DATETIME(3) NULL;

-- AlterTable
ALTER TABLE `invoices` ADD COLUMN `paymentTerms` VARCHAR(191) NULL;

-- CreateTable
CREATE TABLE `project_deadlines` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `clientId` INTEGER NOT NULL,
    `invoiceId` INTEGER NULL,
    `description` VARCHAR(191) NOT NULL,
    `deadlineDate` DATETIME(3) NOT NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'pending',
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- AddForeignKey
ALTER TABLE `project_deadlines` ADD CONSTRAINT `project_deadlines_clientId_fkey` FOREIGN KEY (`clientId`) REFERENCES `clients`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_deadlines` ADD CONSTRAINT `project_deadlines_invoiceId_fkey` FOREIGN KEY (`invoiceId`) REFERENCES `invoices`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;
