import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';
import { generateDocNumber } from '../utils/docNumber';
import { sendSMS } from '../utils/textLk';

export async function getInvoices(req: AuthenticatedRequest, res: Response) {
  try {
    const { status, clientId } = req.query;

    const whereClause: any = {};

    if (status) {
      whereClause.status = String(status);
    }

    if (clientId) {
      whereClause.clientId = parseInt(String(clientId));
    }

    const invoices = await prisma.invoice.findMany({
      where: whereClause,
      include: {
        client: {
          select: { companyName: true, contactPerson: true },
        },
      },
      orderBy: { createdAt: 'desc' },
    });

    return res.json(invoices);
  } catch (error: any) {
    console.error('Get invoices error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getInvoiceById(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid invoice ID' });
    }

    const invoice = await prisma.invoice.findUnique({
      where: { id },
      include: {
        client: true,
        items: {
          include: {
            item: true,
          },
        },
        payments: {
          orderBy: { paidDate: 'desc' },
        },
        quotation: {
          select: { id: true, quoteNumber: true },
        },
        recurringInvoice: {
          select: { id: true, title: true },
        },
        reminders: {
          orderBy: { sentAt: 'desc' },
        },
      },
    });

    if (!invoice) {
      return res.status(404).json({ error: 'Invoice not found' });
    }

    return res.json(invoice);
  } catch (error: any) {
    console.error('Get invoice by ID error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function createInvoice(req: AuthenticatedRequest, res: Response) {
  try {
    const { clientId, dueDate, items, notes, taxAmount } = req.body;

    if (!clientId || !dueDate || !items || !Array.isArray(items) || items.length === 0) {
      return res.status(400).json({ error: 'Client ID, due date, and invoice items are required' });
    }

    const client = await prisma.client.findUnique({
      where: { id: parseInt(clientId) },
    });

    if (!client) {
      return res.status(404).json({ error: 'Client not found' });
    }

    const currentYear = new Date().getFullYear();
    const yearStart = new Date(currentYear, 0, 1);
    const yearEnd = new Date(currentYear, 11, 31, 23, 59, 59);

    let subtotal = 0;
    const itemsData = items.map((item: any) => {
      const quantity = parseInt(item.quantity) || 1;
      const unitPrice = parseFloat(item.unitPrice) || 0;
      const lineTotal = quantity * unitPrice;
      subtotal += lineTotal;

      return {
        itemId: item.itemId ? parseInt(item.itemId) : null,
        description: item.description || '',
        quantity,
        unitPrice,
        lineTotal,
      };
    });

    const tax = taxAmount !== undefined ? parseFloat(taxAmount) : 0;
    const totalAmount = subtotal + tax;

    const newInvoice = await prisma.$transaction(async (tx) => {
      const count = await tx.invoice.count({
        where: {
          issueDate: {
            gte: yearStart,
            lte: yearEnd,
          },
        },
      });

      const invoiceNumber = generateDocNumber('INV', count + 1);

      const created = await tx.invoice.create({
        data: {
          invoiceNumber,
          clientId: parseInt(clientId),
          dueDate: new Date(dueDate),
          status: 'draft',
          subtotal,
          taxAmount: tax,
          totalAmount,
          amountPaid: 0,
          notes,
          items: {
            create: itemsData,
          },
        },
        include: {
          items: true,
        },
      });

      return created;
    });

    return res.status(201).json(newInvoice);
  } catch (error: any) {
    console.error('Create invoice error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function updateInvoice(req: AuthenticatedRequest, res: Response) {
  try {
    if (req.user?.role !== 'admin') {
      return res.status(403).json({ error: 'Forbidden: Only Super Admin can perform this action' });
    }

    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid invoice ID' });
    }

    const { dueDate, items, notes, taxAmount, status } = req.body;

    const existingInvoice = await prisma.invoice.findUnique({
      where: { id },
    });

    if (!existingInvoice) {
      return res.status(404).json({ error: 'Invoice not found' });
    }

    let subtotal = Number(existingInvoice.subtotal);
    let tax = Number(existingInvoice.taxAmount);
    let totalAmount = Number(existingInvoice.totalAmount);
    let itemsData: any[] = [];

    if (items && Array.isArray(items)) {
      subtotal = 0;
      itemsData = items.map((item: any) => {
        const quantity = parseInt(item.quantity) || 1;
        const unitPrice = parseFloat(item.unitPrice) || 0;
        const lineTotal = quantity * unitPrice;
        subtotal += lineTotal;

        return {
          itemId: item.itemId ? parseInt(item.itemId) : null,
          description: item.description || '',
          quantity,
          unitPrice,
          lineTotal,
        };
      });

      tax = taxAmount !== undefined ? parseFloat(taxAmount) : tax;
      totalAmount = subtotal + tax;
    } else if (taxAmount !== undefined) {
      tax = parseFloat(taxAmount);
      totalAmount = subtotal + tax;
    }

    const updated = await prisma.$transaction(async (tx) => {
      // 1. Check if stock was already deducted for this invoice
      const movements = await tx.stockMovement.findMany({
        where: {
          reason: {
            startsWith: `Invoice #${existingInvoice.invoiceNumber}`,
          },
        },
      });

      const hadStockDeducted = movements.length > 0;

      if (hadStockDeducted) {
        // Revert old stock quantities
        for (const movement of movements) {
          await tx.inventoryItem.update({
            where: { id: movement.itemId },
            data: {
              stockQty: {
                increment: movement.quantity,
              },
            },
          });
        }
        // Delete old movements
        await tx.stockMovement.deleteMany({
          where: {
            id: {
              in: movements.map(m => m.id),
            },
          },
        });
      }

      // 2. Delete old invoice items
      if (items && Array.isArray(items)) {
        await tx.invoiceItem.deleteMany({
          where: { invoiceId: id },
        });
      }

      // 3. Update invoice details
      const result = await tx.invoice.update({
        where: { id },
        data: {
          dueDate: dueDate ? new Date(dueDate) : undefined,
          status: status || undefined,
          subtotal,
          taxAmount: tax,
          totalAmount,
          notes: notes !== undefined ? notes : undefined,
          items: itemsData.length > 0 ? {
            create: itemsData,
          } : undefined,
        },
        include: {
          items: true,
          payments: true,
        },
      });

      // 4. Re-deduct stock if stock was previously deducted
      if (hadStockDeducted) {
        for (const item of result.items) {
          if (item.itemId) {
            const invItem = await tx.inventoryItem.findUnique({
              where: { id: item.itemId },
            });

            if (invItem && invItem.isTracked) {
              await tx.inventoryItem.update({
                where: { id: item.itemId },
                data: {
                  stockQty: {
                    decrement: item.quantity,
                  },
                },
              });

              await tx.stockMovement.create({
                data: {
                  itemId: item.itemId,
                  type: 'out',
                  quantity: item.quantity,
                  reason: `Invoice #${result.invoiceNumber} sale (edited)`,
                },
              });
            }
          }
        }
      }

      // 5. Recalculate Payment Allocations and Partner Payouts if the total amount has changed
      if (Number(existingInvoice.totalAmount) !== totalAmount) {
        // Calculate new COGS for the edited items
        let newTotalCOGS = 0;
        for (const item of result.items) {
          if (item.itemId) {
            const invItem = await tx.inventoryItem.findUnique({
              where: { id: item.itemId }
            });
            if (invItem) {
              newTotalCOGS += Number(invItem.unitCost) * item.quantity;
            }
          }
        }

        // Fetch Settings
        const reinvestPctSetting = await tx.systemSetting.findUnique({ where: { key: 'reinvestment_percentage' } });
        const taxPctSetting = await tx.systemSetting.findUnique({ where: { key: 'tax_percentage' } });

        const reinvestmentPct = reinvestPctSetting ? parseFloat(reinvestPctSetting.value) : 50.0;
        const taxPct = taxPctSetting ? parseFloat(taxPctSetting.value) : 15.0;

        let allocatedCOGS = 0;
        for (const payment of result.payments) {
          const allocation = await tx.paymentAllocation.findUnique({
            where: { paymentId: payment.id }
          });

          if (allocation) {
            const paymentAmount = Number(payment.amount);
            const remainingCOGS = Math.max(0, newTotalCOGS - allocatedCOGS);

            const paymentTax = paymentAmount * (taxPct / 100);
            const availableForCOGS = Math.max(0, paymentAmount - paymentTax);
            const paymentCOGS = Math.min(availableForCOGS, remainingCOGS);
            allocatedCOGS += paymentCOGS;

            const netAmount = paymentAmount - paymentCOGS - paymentTax;

            const reinvestAmount = netAmount * (reinvestmentPct / 100);
            const partnerPoolAmount = netAmount - reinvestAmount;

            // Update allocation
            await tx.paymentAllocation.update({
              where: { id: allocation.id },
              data: {
                cogsAmount: paymentCOGS,
                taxAmount: paymentTax,
                netAmount: netAmount,
                reinvestAmount: reinvestAmount,
                partnerPoolAmount: partnerPoolAmount
              }
            });

            // Update partner payouts
            const payouts = await tx.partnerPayout.findMany({
              where: { allocationId: allocation.id },
              include: { partner: true }
            });

            for (const payout of payouts) {
              const partnerShare = partnerPoolAmount * (Number(payout.partner.ownershipPct) / 100);
              await tx.partnerPayout.update({
                where: { id: payout.id },
                data: {
                  amount: partnerShare
                }
              });
            }
          }
        }
      }

      return result;
    });

    return res.json(updated);
  } catch (error: any) {
    console.error('Update invoice error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function recordPayment(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid invoice ID' });
    }

    const { amount, method, reference, notes, paidDate } = req.body;

    if (amount === undefined || !method) {
      return res.status(400).json({ error: 'Payment amount and method are required' });
    }

    const paymentAmount = parseFloat(amount);
    if (isNaN(paymentAmount) || paymentAmount <= 0) {
      return res.status(400).json({ error: 'Payment amount must be a positive number' });
    }

    const invoice = await prisma.invoice.findUnique({
      where: { id },
      include: {
        items: true,
      },
    });

    if (!invoice) {
      return res.status(404).json({ error: 'Invoice not found' });
    }

    if (invoice.status === 'paid') {
      return res.status(400).json({ error: 'Invoice is already fully paid' });
    }

    const currentPaid = Number(invoice.amountPaid);
    const totalDue = Number(invoice.totalAmount);
    const newPaidAmount = currentPaid + paymentAmount;

    let newStatus = 'partially_paid';
    if (newPaidAmount >= totalDue) {
      newStatus = 'paid';
    }

    const updated = await prisma.$transaction(async (tx) => {
      // 1. Log payment
      const payment = await tx.payment.create({
        data: {
          invoiceId: id,
          amount: paymentAmount,
          method,
          reference,
          notes,
          paidDate: paidDate ? new Date(paidDate) : new Date(),
        },
      });

      // 2. Update Invoice
      const updatedInvoice = await tx.invoice.update({
        where: { id },
        data: {
          amountPaid: newPaidAmount,
          status: newStatus,
        },
      });

      // 3. Deduct stock if this is the first payment (or transition to paid/partially paid)
      // Check if stock has already been deducted for this invoice
      const hasMovement = await tx.stockMovement.findFirst({
        where: {
          reason: {
            startsWith: `Invoice #${invoice.invoiceNumber}`,
          },
        },
      });

      if (!hasMovement) {
        for (const item of invoice.items) {
          if (item.itemId) {
            const invItem = await tx.inventoryItem.findUnique({
              where: { id: item.itemId },
            });

            if (invItem && invItem.isTracked) {
              await tx.inventoryItem.update({
                where: { id: item.itemId },
                data: {
                  stockQty: {
                    decrement: item.quantity,
                  },
                },
              });

              await tx.stockMovement.create({
                data: {
                  itemId: item.itemId,
                  type: 'out',
                  quantity: item.quantity,
                  reason: `Invoice #${invoice.invoiceNumber} sale`,
                },
              });
            }
          }
        }
      }

      // 4. Calculate COGS for all items
      let totalInvoiceCOGS = 0;
      for (const item of invoice.items) {
        if (item.itemId) {
          const invItem = await tx.inventoryItem.findUnique({
            where: { id: item.itemId }
          });
          if (invItem) {
            totalInvoiceCOGS += Number(invItem.unitCost) * item.quantity;
          }
        }
      }

      // 5. Fetch Settings
      const reinvestPctSetting = await tx.systemSetting.findUnique({ where: { key: 'reinvestment_percentage' } });
      const taxPctSetting = await tx.systemSetting.findUnique({ where: { key: 'tax_percentage' } });

      const reinvestmentPct = reinvestPctSetting ? parseFloat(reinvestPctSetting.value) : 50.0;
      const taxPct = taxPctSetting ? parseFloat(taxPctSetting.value) : 15.0;

      // Fetch other allocations for this invoice to calculate already allocated COGS
      const otherAllocations = await tx.paymentAllocation.findMany({
        where: { payment: { invoiceId: invoice.id } }
      });
      const allocatedCOGS = otherAllocations.reduce((sum, a) => sum + Number(a.cogsAmount), 0);
      const remainingCOGS = Math.max(0, totalInvoiceCOGS - allocatedCOGS);

      const paymentTax = paymentAmount * (taxPct / 100);
      const availableForCOGS = Math.max(0, paymentAmount - paymentTax);
      const paymentCOGS = Math.min(availableForCOGS, remainingCOGS);
      const netAmount = paymentAmount - paymentCOGS - paymentTax;

      const reinvestAmount = netAmount * (reinvestmentPct / 100);
      const partnerPoolAmount = netAmount - reinvestAmount;

      // 6. Create PaymentAllocation
      const allocation = await tx.paymentAllocation.create({
        data: {
          paymentId: payment.id,
          grossAmount: paymentAmount,
          cogsAmount: paymentCOGS,
          taxAmount: paymentTax,
          netAmount: netAmount,
          reinvestPctUsed: reinvestmentPct,
          reinvestAmount: reinvestAmount,
          partnerPoolAmount: partnerPoolAmount
        }
      });

      // 7. Create PartnerPayouts based on active partners at this moment
      const activePartners = await tx.partner.findMany({
        where: { status: 'active' }
      });

      for (const partner of activePartners) {
        const partnerShare = partnerPoolAmount * (Number(partner.ownershipPct) / 100);
        await tx.partnerPayout.create({
          data: {
            partnerId: partner.id,
            allocationId: allocation.id,
            amount: partnerShare,
            status: 'pending'
          }
        });
      }

      return { updatedInvoice, payment };
    });

    return res.status(201).json(updated);
  } catch (error: any) {
    console.error('Record payment error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function deleteInvoice(req: AuthenticatedRequest, res: Response) {
  try {
    if (req.user?.role !== 'admin') {
      return res.status(403).json({ error: 'Forbidden: Only Super Admin can perform this action' });
    }

    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid invoice ID' });
    }

    const invoice = await prisma.invoice.findUnique({
      where: { id },
      include: {
        payments: true,
      },
    });

    if (!invoice) {
      return res.status(404).json({ error: 'Invoice not found' });
    }

    await prisma.$transaction(async (tx) => {
      // 1. Revert stock and delete stock movements
      const movements = await tx.stockMovement.findMany({
        where: {
          reason: {
            startsWith: `Invoice #${invoice.invoiceNumber}`,
          },
        },
      });

      for (const movement of movements) {
        await tx.inventoryItem.update({
          where: { id: movement.itemId },
          data: {
            stockQty: {
              increment: movement.quantity,
            },
          },
        });
      }

      if (movements.length > 0) {
        await tx.stockMovement.deleteMany({
          where: {
            id: {
              in: movements.map(m => m.id),
            },
          },
        });
      }

      // 2. Revert payments, allocations, and payouts
      for (const payment of invoice.payments) {
        const allocation = await tx.paymentAllocation.findUnique({
          where: { paymentId: payment.id },
        });

        if (allocation) {
          await tx.partnerPayout.deleteMany({
            where: { allocationId: allocation.id },
          });

          await tx.paymentAllocation.delete({
            where: { id: allocation.id },
          });
        }

        await tx.payment.delete({
          where: { id: payment.id },
        });
      }

      // 3. Unlink project deadlines
      await tx.projectDeadline.updateMany({
        where: { invoiceId: id },
        data: { invoiceId: null },
      });

      // 4. Delete the Invoice itself (cascades to items)
      await tx.invoice.delete({
        where: { id },
      });
    });

    return res.json({ message: 'Invoice and all associated payments, payouts, allocations, and stock movements reverted and deleted successfully' });
  } catch (error: any) {
    console.error('Delete invoice error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function sendInvoiceReminder(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid invoice ID' });
    }

    const { templateId } = req.body;
    if (!templateId) {
      return res.status(400).json({ error: 'Template ID is required' });
    }

    const invoice = await prisma.invoice.findUnique({
      where: { id },
      include: { client: true }
    });

    if (!invoice) {
      return res.status(404).json({ error: 'Invoice not found' });
    }

    const template = await prisma.smsTemplate.findUnique({
      where: { id: parseInt(templateId) }
    });

    if (!template) {
      return res.status(404).json({ error: 'SMS Template not found' });
    }

    const contactPerson = invoice.client.contactPerson;
    const companyName = invoice.client.companyName;
    const invoiceNumber = invoice.invoiceNumber;
    const totalAmount = Number(invoice.totalAmount).toFixed(2);
    const amountPaid = Number(invoice.amountPaid).toFixed(2);
    const balanceAmount = (Number(invoice.totalAmount) - Number(invoice.amountPaid)).toFixed(2);
    const dueDate = new Date(invoice.dueDate).toLocaleDateString();

    let message = template.body
      .replace(/{contactPerson}/g, contactPerson)
      .replace(/{companyName}/g, companyName)
      .replace(/{invoiceNumber}/g, invoiceNumber)
      .replace(/{totalAmount}/g, totalAmount)
      .replace(/{amountPaid}/g, amountPaid)
      .replace(/{balanceAmount}/g, balanceAmount)
      .replace(/{dueDate}/g, dueDate);

    const senderEmail = req.user?.email || 'admin@pos.com';

    // Send SMS
    const isSent = await sendSMS(invoice.client.phone, message);
    const status = isSent ? 'sent' : 'failed';

    // Create log record
    const reminder = await prisma.invoiceReminder.create({
      data: {
        invoiceId: id,
        sentBy: senderEmail,
        message,
        status
      }
    });

    if (!isSent) {
      return res.status(500).json({ error: 'Failed to send SMS reminder via provider API', log: reminder });
    }

    return res.status(201).json(reminder);
  } catch (error: any) {
    console.error('Send invoice reminder error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

