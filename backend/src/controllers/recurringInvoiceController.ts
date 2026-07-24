import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';

export async function getRecurringConfigs(req: AuthenticatedRequest, res: Response) {
  try {
    const { clientId, isActive } = req.query;

    const whereClause: any = {};

    if (clientId) {
      whereClause.clientId = parseInt(String(clientId));
    }

    if (isActive !== undefined) {
      whereClause.isActive = isActive === 'true';
    }

    const configs = await prisma.recurringInvoice.findMany({
      where: whereClause,
      include: {
        client: {
          select: { companyName: true, contactPerson: true },
        },
      },
      orderBy: { createdAt: 'desc' },
    });

    return res.json(configs);
  } catch (error: any) {
    console.error('Get recurring configs error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getRecurringConfigById(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid config ID' });
    }

    const config = await prisma.recurringInvoice.findUnique({
      where: { id },
      include: {
        client: true,
        generatedInvoices: {
          orderBy: { issueDate: 'desc' },
          take: 10,
        },
      },
    });

    if (!config) {
      return res.status(404).json({ error: 'Recurring config not found' });
    }

    return res.json(config);
  } catch (error: any) {
    console.error('Get recurring config by ID error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function createRecurringConfig(req: AuthenticatedRequest, res: Response) {
  try {
    const { clientId, title, description, amount, frequency, startDate, endDate } = req.body;

    if (!clientId || !title || amount === undefined || !frequency || !startDate) {
      return res.status(400).json({ error: 'Client ID, title, amount, frequency, and start date are required' });
    }

    if (!['monthly', 'quarterly', 'yearly'].includes(frequency)) {
      return res.status(400).json({ error: 'Frequency must be "monthly", "quarterly", or "yearly"' });
    }

    const client = await prisma.client.findUnique({
      where: { id: parseInt(clientId) },
    });

    if (!client) {
      return res.status(404).json({ error: 'Client not found' });
    }

    const nextRun = new Date(startDate);

    const config = await prisma.recurringInvoice.create({
      data: {
        clientId: parseInt(clientId),
        title,
        description,
        amount: parseFloat(amount),
        frequency,
        startDate: new Date(startDate),
        nextRunDate: nextRun,
        endDate: endDate ? new Date(endDate) : null,
      },
    });

    return res.status(201).json(config);
  } catch (error: any) {
    console.error('Create recurring config error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function updateRecurringConfig(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid config ID' });
    }

    const { title, description, amount, frequency, startDate, nextRunDate, endDate, isActive } = req.body;

    const existing = await prisma.recurringInvoice.findUnique({
      where: { id },
    });

    if (!existing) {
      return res.status(404).json({ error: 'Recurring config not found' });
    }

    if (frequency && !['monthly', 'quarterly', 'yearly'].includes(frequency)) {
      return res.status(400).json({ error: 'Frequency must be "monthly", "quarterly", or "yearly"' });
    }

    const updated = await prisma.recurringInvoice.update({
      where: { id },
      data: {
        title,
        description,
        amount: amount !== undefined ? parseFloat(amount) : undefined,
        frequency,
        startDate: startDate ? new Date(startDate) : undefined,
        nextRunDate: nextRunDate ? new Date(nextRunDate) : undefined,
        endDate: endDate !== undefined ? (endDate ? new Date(endDate) : null) : undefined,
        isActive: isActive !== undefined ? Boolean(isActive) : undefined,
      },
    });

    return res.json(updated);
  } catch (error: any) {
    console.error('Update recurring config error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function deleteRecurringConfig(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid config ID' });
    }

    const force = req.query.force === 'true';

    const existing = await prisma.recurringInvoice.findUnique({
      where: { id },
    });

    if (!existing) {
      return res.status(404).json({ error: 'Recurring config not found' });
    }

    if (force) {
      await prisma.recurringInvoice.delete({
        where: { id },
      });
      return res.json({ message: 'Recurring configuration deleted permanently' });
    } else {
      await prisma.recurringInvoice.update({
        where: { id },
        data: { isActive: false },
      });
      return res.json({ message: 'Recurring configuration deactivated successfully' });
    }
  } catch (error: any) {
    console.error('Delete recurring config error:', error);
    if (error.code === 'P2003') {
      return res.status(400).json({
        error: 'Cannot delete configuration due to existing generated invoices. Deactivate it instead.',
      });
    }
    return res.status(500).json({ error: 'Internal server error' });
  }
}
