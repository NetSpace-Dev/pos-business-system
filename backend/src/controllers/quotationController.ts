import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';
import { generateDocNumber } from '../utils/docNumber';

export async function getQuotations(req: AuthenticatedRequest, res: Response) {
  try {
    const { status, clientId } = req.query;

    const whereClause: any = {};

    if (status) {
      whereClause.status = String(status);
    }

    if (clientId) {
      whereClause.clientId = parseInt(String(clientId));
    }

    const quotations = await prisma.quotation.findMany({
      where: whereClause,
      include: {
        client: {
          select: { companyName: true, contactPerson: true },
        },
      },
      orderBy: { createdAt: 'desc' },
    });

    return res.json(quotations);
  } catch (error: any) {
    console.error('Get quotations error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getQuotationById(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid quotation ID' });
    }

    const quotation = await prisma.quotation.findUnique({
      where: { id },
      include: {
        client: true,
        items: {
          include: {
            item: true,
          },
        },
        invoice: {
          select: { id: true, invoiceNumber: true, status: true },
        },
      },
    });

    if (!quotation) {
      return res.status(404).json({ error: 'Quotation not found' });
    }

    return res.json(quotation);
  } catch (error: any) {
    console.error('Get quotation by ID error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function createQuotation(req: AuthenticatedRequest, res: Response) {
  try {
    const { clientId, validUntil, items, terms, taxAmount } = req.body;

    if (!clientId || !validUntil || !items || !Array.isArray(items) || items.length === 0) {
      return res.status(400).json({ error: 'Client ID, validity date, and quotation items are required' });
    }

    const client = await prisma.client.findUnique({
      where: { id: parseInt(clientId) },
    });

    if (!client) {
      return res.status(404).json({ error: 'Client not found' });
    }

    // Sequence for sequential numbering
    const currentYear = new Date().getFullYear();
    const yearStart = new Date(currentYear, 0, 1);
    const yearEnd = new Date(currentYear, 11, 31, 23, 59, 59);

    // Calculate totals and format items
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

    const newQuotation = await prisma.$transaction(async (tx) => {
      // Find count of quotations in the current year
      const count = await tx.quotation.count({
        where: {
          issueDate: {
            gte: yearStart,
            lte: yearEnd,
          },
        },
      });

      const quoteNumber = generateDocNumber('QT', count + 1);

      const created = await tx.quotation.create({
        data: {
          quoteNumber,
          clientId: parseInt(clientId),
          validUntil: new Date(validUntil),
          status: 'draft',
          subtotal,
          taxAmount: tax,
          totalAmount,
          terms,
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

    return res.status(201).json(newQuotation);
  } catch (error: any) {
    console.error('Create quotation error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function updateQuotation(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid quotation ID' });
    }

    const { validUntil, items, terms, taxAmount, status } = req.body;

    const existingQuotation = await prisma.quotation.findUnique({
      where: { id },
    });

    if (!existingQuotation) {
      return res.status(404).json({ error: 'Quotation not found' });
    }

    if (existingQuotation.status === 'converted') {
      return res.status(400).json({ error: 'Cannot modify a quotation that has already been converted to an invoice' });
    }

    let subtotal = Number(existingQuotation.subtotal);
    let tax = Number(existingQuotation.taxAmount);
    let totalAmount = Number(existingQuotation.totalAmount);
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
      // If items are provided, delete the old items first
      if (items && Array.isArray(items)) {
        await tx.quotationItem.deleteMany({
          where: { quotationId: id },
        });
      }

      const result = await tx.quotation.update({
        where: { id },
        data: {
          validUntil: validUntil ? new Date(validUntil) : undefined,
          status: status || undefined,
          subtotal,
          taxAmount: tax,
          totalAmount,
          terms: terms !== undefined ? terms : undefined,
          version: {
            increment: 1,
          },
          items: itemsData.length > 0 ? {
            create: itemsData,
          } : undefined,
        },
        include: {
          items: true,
        },
      });

      return result;
    });

    return res.json(updated);
  } catch (error: any) {
    console.error('Update quotation error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function convertToInvoice(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid quotation ID' });
    }

    const { dueDate } = req.body;

    const quotation = await prisma.quotation.findUnique({
      where: { id },
      include: {
        items: true,
      },
    });

    if (!quotation) {
      return res.status(404).json({ error: 'Quotation not found' });
    }

    if (quotation.status === 'converted') {
      return res.status(400).json({ error: 'Quotation has already been converted to an invoice' });
    }

    const currentYear = new Date().getFullYear();
    const yearStart = new Date(currentYear, 0, 1);
    const yearEnd = new Date(currentYear, 11, 31, 23, 59, 59);

    // Calculate default due date if not provided (e.g., 14 days from now)
    const invoiceDueDate = dueDate ? new Date(dueDate) : new Date(Date.now() + 14 * 24 * 60 * 60 * 1000);

    const newInvoice = await prisma.$transaction(async (tx) => {
      // Generate Invoice sequence
      const count = await tx.invoice.count({
        where: {
          issueDate: {
            gte: yearStart,
            lte: yearEnd,
          },
        },
      });

      const invoiceNumber = generateDocNumber('INV', count + 1);

      // Create invoice linked to quotation
      const invoice = await tx.invoice.create({
        data: {
          invoiceNumber,
          clientId: quotation.clientId,
          quotationId: quotation.id,
          issueDate: new Date(),
          dueDate: invoiceDueDate,
          status: 'draft',
          subtotal: quotation.subtotal,
          taxAmount: quotation.taxAmount,
          totalAmount: quotation.totalAmount,
          amountPaid: 0,
          notes: `Converted from quotation ${quotation.quoteNumber}`,
          items: {
            create: quotation.items.map((item) => ({
              itemId: item.itemId,
              description: item.description,
              quantity: item.quantity,
              unitPrice: item.unitPrice,
              lineTotal: item.lineTotal,
            })),
          },
        },
        include: {
          items: true,
        },
      });

      // Update quotation status
      await tx.quotation.update({
        where: { id },
        data: {
          status: 'converted',
        },
      });

      return invoice;
    });

    return res.status(201).json(newInvoice);
  } catch (error: any) {
    console.error('Convert quotation to invoice error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function deleteQuotation(req: AuthenticatedRequest, res: Response) {
  try {
    if (req.user?.role !== 'admin') {
      return res.status(403).json({ error: 'Forbidden: Only Super Admin can perform this action' });
    }

    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid quotation ID' });
    }

    const quotation = await prisma.quotation.findUnique({
      where: { id },
    });

    if (!quotation) {
      return res.status(404).json({ error: 'Quotation not found' });
    }

    // Check if there is a related invoice in the database referencing this quotation
    const relatedInvoice = await prisma.invoice.findFirst({
      where: { quotationId: id },
    });

    if (relatedInvoice) {
      return res.status(400).json({ error: 'Cannot delete quotation because a related invoice exists' });
    }

    if (quotation.status === 'converted') {
      return res.status(400).json({ error: 'Cannot delete a quotation that has already been converted to an invoice' });
    }

    // Delete quotation (cascade will delete quotation items since it is defined with Cascade delete in schema)
    await prisma.quotation.delete({
      where: { id },
    });

    return res.json({ message: 'Quotation deleted successfully' });
  } catch (error: any) {
    console.error('Delete quotation error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}
