import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';

export async function getDealers(req: AuthenticatedRequest, res: Response) {
  try {
    const { search, isActive } = req.query;

    const whereClause: any = {};

    if (isActive !== undefined) {
      whereClause.isActive = isActive === 'true';
    }

    if (search) {
      const searchStr = String(search);
      whereClause.OR = [
        { name: { contains: searchStr, mode: 'insensitive' } },
        { contactPerson: { contains: searchStr, mode: 'insensitive' } },
        { phone: { contains: searchStr, mode: 'insensitive' } },
        { email: { contains: searchStr, mode: 'insensitive' } },
      ];
    }

    const dealers = await prisma.dealer.findMany({
      where: whereClause,
      orderBy: { name: 'asc' },
    });

    return res.json(dealers);
  } catch (error: any) {
    console.error('Get dealers error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getDealerById(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid dealer ID' });
    }

    const dealer = await prisma.dealer.findUnique({
      where: { id },
      include: {
        items: true,
        transactions: {
          orderBy: { txnDate: 'desc' },
          take: 20,
        },
      },
    });

    if (!dealer) {
      return res.status(404).json({ error: 'Dealer not found' });
    }

    return res.json(dealer);
  } catch (error: any) {
    console.error('Get dealer by ID error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function createDealer(req: AuthenticatedRequest, res: Response) {
  try {
    const { name, contactPerson, phone, email, address, paymentType, balanceDue } = req.body;

    if (!name) {
      return res.status(400).json({ error: 'Dealer name is required' });
    }

    const dealer = await prisma.dealer.create({
      data: {
        name,
        contactPerson,
        phone,
        email,
        address,
        paymentType: paymentType || 'credit',
        balanceDue: balanceDue !== undefined ? parseFloat(balanceDue) : 0,
      },
    });

    return res.status(201).json(dealer);
  } catch (error: any) {
    console.error('Create dealer error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function updateDealer(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid dealer ID' });
    }

    const { name, contactPerson, phone, email, address, paymentType, isActive } = req.body;

    const existingDealer = await prisma.dealer.findUnique({
      where: { id },
    });

    if (!existingDealer) {
      return res.status(404).json({ error: 'Dealer not found' });
    }

    const updated = await prisma.dealer.update({
      where: { id },
      data: {
        name,
        contactPerson,
        phone,
        email,
        address,
        paymentType,
        isActive: isActive !== undefined ? Boolean(isActive) : undefined,
      },
    });

    return res.json(updated);
  } catch (error: any) {
    console.error('Update dealer error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function addTransaction(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid dealer ID' });
    }

    const { type, amount, description, txnDate } = req.body;

    if (!type || amount === undefined) {
      return res.status(400).json({ error: 'Transaction type and amount are required' });
    }

    if (!['purchase', 'payment'].includes(type)) {
      return res.status(400).json({ error: 'Transaction type must be "purchase" or "payment"' });
    }

    const amtVal = parseFloat(amount);
    if (isNaN(amtVal) || amtVal <= 0) {
      return res.status(400).json({ error: 'Amount must be a positive number' });
    }

    const dealer = await prisma.dealer.findUnique({
      where: { id },
    });

    if (!dealer) {
      return res.status(404).json({ error: 'Dealer not found' });
    }

    // A purchase on credit increases balance due to the dealer
    // A payment to the dealer decreases the balance due to the dealer
    const balanceChange = type === 'purchase' ? amtVal : -amtVal;

    const updatedDealer = await prisma.$transaction(async (tx) => {
      const updated = await tx.dealer.update({
        where: { id },
        data: {
          balanceDue: {
            increment: balanceChange,
          },
        },
      });

      await tx.dealerTransaction.create({
        data: {
          dealerId: id,
          type,
          amount: amtVal,
          description: description || (type === 'purchase' ? 'Purchased stock on credit' : 'Paid balance'),
          txnDate: txnDate ? new Date(txnDate) : new Date(),
        },
      });

      return updated;
    });

    return res.json(updatedDealer);
  } catch (error: any) {
    console.error('Add dealer transaction error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function deleteDealer(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid dealer ID' });
    }

    const force = req.query.force === 'true';

    const dealer = await prisma.dealer.findUnique({
      where: { id },
    });

    if (!dealer) {
      return res.status(404).json({ error: 'Dealer not found' });
    }

    if (force) {
      await prisma.dealer.delete({
        where: { id },
      });
      return res.json({ message: 'Dealer permanently deleted' });
    } else {
      await prisma.dealer.update({
        where: { id },
        data: { isActive: false },
      });
      return res.json({ message: 'Dealer deactivated successfully' });
    }
  } catch (error: any) {
    console.error('Delete dealer error:', error);
    if (error.code === 'P2003') {
      return res.status(400).json({
        error: 'Cannot delete dealer because they supply active inventory items or have historical transactions. Deactivate instead.',
      });
    }
    return res.status(500).json({ error: 'Internal server error' });
  }
}
