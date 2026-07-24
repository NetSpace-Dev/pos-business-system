import cron from 'node-cron';
import prisma from './prisma';
import { generateDocNumber } from './docNumber';

export async function processRecurringInvoices() {
  console.log('[Cron] Checking for recurring invoices...');
  try {
    const today = new Date();
    // Find all active configs where nextRunDate is today or in the past
    const activeConfigs = await prisma.recurringInvoice.findMany({
      where: {
        isActive: true,
        nextRunDate: {
          lte: today,
        },
        OR: [
          { endDate: null },
          { endDate: { gte: today } },
        ],
      },
    });

    console.log(`[Cron] Found ${activeConfigs.length} active configurations to process.`);

    for (const config of activeConfigs) {
      try {
        await prisma.$transaction(async (tx) => {
          const currentYear = today.getFullYear();
          const yearStart = new Date(currentYear, 0, 1);
          const yearEnd = new Date(currentYear, 11, 31, 23, 59, 59);

          // Get count for sequence
          const count = await tx.invoice.count({
            where: {
              issueDate: {
                gte: yearStart,
                lte: yearEnd,
              },
            },
          });

          const invoiceNumber = generateDocNumber('INV', count + 1);
          // Due date is 14 days from issue date
          const dueDate = new Date(config.nextRunDate.getTime() + 14 * 24 * 60 * 60 * 1000);

          // 1. Create the invoice
          await tx.invoice.create({
            data: {
              invoiceNumber,
              clientId: config.clientId,
              issueDate: config.nextRunDate,
              dueDate,
              status: 'draft',
              subtotal: config.amount,
              taxAmount: 0,
              totalAmount: config.amount,
              amountPaid: 0,
              notes: `Auto-generated from recurring billing template: ${config.title}`,
              isRecurringGenerated: true,
              recurringInvoiceId: config.id,
              items: {
                create: [
                  {
                    description: `${config.title} - ${config.description || 'Recurring fee'}`,
                    quantity: 1,
                    unitPrice: config.amount,
                    lineTotal: config.amount,
                  },
                ],
              },
            },
          });

          // 2. Calculate next run date
          let nextRun = new Date(config.nextRunDate);
          if (config.frequency === 'monthly') {
            nextRun.setMonth(nextRun.getMonth() + 1);
          } else if (config.frequency === 'quarterly') {
            nextRun.setMonth(nextRun.getMonth() + 3);
          } else if (config.frequency === 'yearly') {
            nextRun.setFullYear(nextRun.getFullYear() + 1);
          }

          // Check if new next run date exceeds end date
          let isActive = true;
          if (config.endDate && nextRun > config.endDate) {
            isActive = false;
          }

          // 3. Update the recurring config
          await tx.recurringInvoice.update({
            where: { id: config.id },
            data: {
              nextRunDate: nextRun,
              isActive,
            },
          });

          console.log(`[Cron] Generated Invoice ${invoiceNumber} for Client ID ${config.clientId} (${config.title})`);
        });
      } catch (error) {
        console.error(`[Cron] Failed to process recurring invoice ID ${config.id}:`, error);
      }
    }
  } catch (error) {
    console.error('[Cron] Critical error checking recurring invoices:', error);
  }
}

export function startCronJobs() {
  // Run daily at midnight: '0 0 * * *'
  // For safety and testability, we also run it immediately when the server starts.
  
  // Run everyday at 00:00
  cron.schedule('0 0 * * *', () => {
    processRecurringInvoices();
  });
  
  console.log('[Cron] Scheduler initialized to run daily at midnight.');
  
  // Optional: Run immediately on startup for convenience
  processRecurringInvoices();
}
