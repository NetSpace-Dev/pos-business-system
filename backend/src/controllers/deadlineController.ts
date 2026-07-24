import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';

export async function getDeadlines(req: AuthenticatedRequest, res: Response) {
  try {
    const { status } = req.query;
    const whereClause: any = {};

    if (status) {
      whereClause.status = String(status);
    }

    const deadlines = await prisma.projectDeadline.findMany({
      where: whereClause,
      include: {
        client: {
          select: { companyName: true, contactPerson: true }
        },
        invoice: {
          select: { invoiceNumber: true }
        }
      },
      orderBy: { deadlineDate: 'asc' }
    });

    return res.json(deadlines);
  } catch (error: any) {
    console.error('Get deadlines error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function createDeadline(req: AuthenticatedRequest, res: Response) {
  try {
    const { clientId, invoiceId, description, deadlineDate } = req.body;

    if (!clientId || !description || !deadlineDate) {
      return res.status(400).json({ error: 'Client ID, description, and deadline date are required' });
    }

    const deadline = await prisma.projectDeadline.create({
      data: {
        clientId: parseInt(clientId),
        invoiceId: invoiceId ? parseInt(invoiceId) : null,
        description,
        deadlineDate: new Date(deadlineDate),
        status: 'pending'
      },
      include: {
        client: {
          select: { companyName: true }
        }
      }
    });

    return res.status(201).json(deadline);
  } catch (error: any) {
    console.error('Create deadline error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function updateDeadline(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid deadline ID' });
    }

    const { status, description, deadlineDate } = req.body;

    const deadline = await prisma.projectDeadline.update({
      where: { id },
      data: {
        status,
        description,
        deadlineDate: deadlineDate ? new Date(deadlineDate) : undefined
      }
    });

    return res.json(deadline);
  } catch (error: any) {
    console.error('Update deadline error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function deleteDeadline(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid deadline ID' });
    }

    await prisma.projectDeadline.delete({
      where: { id }
    });

    return res.json({ message: 'Deadline deleted successfully' });
  } catch (error: any) {
    console.error('Delete deadline error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}
