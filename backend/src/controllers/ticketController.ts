import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';
import { generateDocNumber } from '../utils/docNumber';

export async function getTickets(req: AuthenticatedRequest, res: Response) {
  try {
    const { status, priority, clientId } = req.query;

    const whereClause: any = {};

    if (status) {
      whereClause.status = String(status);
    }

    if (priority) {
      whereClause.priority = String(priority);
    }

    if (clientId) {
      whereClause.clientId = parseInt(String(clientId));
    }

    const tickets = await prisma.supportTicket.findMany({
      where: whereClause,
      include: {
        client: {
          select: { companyName: true, contactPerson: true },
        },
      },
      orderBy: { createdAt: 'desc' },
    });

    return res.json(tickets);
  } catch (error: any) {
    console.error('Get tickets error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getTicketById(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid ticket ID' });
    }

    const ticket = await prisma.supportTicket.findUnique({
      where: { id },
      include: {
        client: true,
        updates: {
          orderBy: { createdAt: 'asc' },
        },
      },
    });

    if (!ticket) {
      return res.status(404).json({ error: 'Ticket not found' });
    }

    return res.json(ticket);
  } catch (error: any) {
    console.error('Get ticket by ID error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function createTicket(req: AuthenticatedRequest, res: Response) {
  try {
    const { clientId, problemDesc, priority } = req.body;

    if (!clientId || !problemDesc) {
      return res.status(400).json({ error: 'Client ID and problem description are required' });
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

    const ticket = await prisma.$transaction(async (tx) => {
      const count = await tx.supportTicket.count({
        where: {
          createdAt: {
            gte: yearStart,
            lte: yearEnd,
          },
        },
      });

      const ticketNumber = generateDocNumber('TK', count + 1);

      const created = await tx.supportTicket.create({
        data: {
          ticketNumber,
          clientId: parseInt(clientId),
          problemDesc,
          priority: priority || 'medium',
          status: 'open',
        },
      });

      // Log initial update
      await tx.ticketUpdate.create({
        data: {
          ticketId: created.id,
          note: 'Ticket logged in the system.',
          statusChange: 'open',
        },
      });

      return created;
    });

    return res.status(201).json(ticket);
  } catch (error: any) {
    console.error('Create ticket error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function addTicketUpdate(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid ticket ID' });
    }

    const { note, statusChange } = req.body;

    if (!note) {
      return res.status(400).json({ error: 'Note is required for updating ticket' });
    }

    const ticket = await prisma.supportTicket.findUnique({
      where: { id },
    });

    if (!ticket) {
      return res.status(404).json({ error: 'Ticket not found' });
    }

    const handledBy = req.user ? req.user.email : 'System';

    const result = await prisma.$transaction(async (tx) => {
      // 1. Create update note
      const update = await tx.ticketUpdate.create({
        data: {
          ticketId: id,
          note,
          statusChange: statusChange || null,
        },
      });

      // 2. Determine updates for the ticket itself
      const ticketData: any = {};
      if (statusChange) {
        ticketData.status = statusChange;
        if (['resolved', 'closed'].includes(statusChange)) {
          ticketData.closedAt = new Date();
          ticketData.handledBy = handledBy;
        } else {
          ticketData.closedAt = null;
        }
      }

      const updatedTicket = await tx.supportTicket.update({
        where: { id },
        data: ticketData,
      });

      return { updatedTicket, update };
    });

    return res.status(201).json(result);
  } catch (error: any) {
    console.error('Add ticket update error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function deleteTicket(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid ticket ID' });
    }

    const ticket = await prisma.supportTicket.findUnique({
      where: { id },
    });

    if (!ticket) {
      return res.status(404).json({ error: 'Ticket not found' });
    }

    await prisma.supportTicket.delete({
      where: { id },
    });

    return res.json({ message: 'Ticket deleted successfully' });
  } catch (error: any) {
    console.error('Delete ticket error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}
