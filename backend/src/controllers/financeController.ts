import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';

// Partners management
export async function getPartners(req: AuthenticatedRequest, res: Response) {
  try {
    const partners = await prisma.partner.findMany({
      orderBy: { name: 'asc' }
    });
    return res.json(partners);
  } catch (error) {
    console.error('Get partners error:', error);
    return res.status(500).json({ error: 'Failed to retrieve partners' });
  }
}

export async function createPartner(req: AuthenticatedRequest, res: Response) {
  try {
    const { name, ownershipPct, status } = req.body;

    if (!name || ownershipPct === undefined) {
      return res.status(400).json({ error: 'Name and ownership percentage are required' });
    }

    const pct = parseFloat(ownershipPct);
    if (isNaN(pct) || pct < 0 || pct > 100) {
      return res.status(400).json({ error: 'Ownership percentage must be between 0 and 100' });
    }

    // Check sum of active partners' ownershipPct + new pct <= 100
    const activePartners = await prisma.partner.findMany({
      where: { status: 'active' }
    });
    const currentSum = activePartners.reduce((sum, p) => sum + Number(p.ownershipPct), 0);
    
    if (status !== 'inactive' && currentSum + pct > 100) {
      return res.status(400).json({ error: `Total ownership of active partners cannot exceed 100%. Current sum: ${currentSum}%.` });
    }

    const partner = await prisma.partner.create({
      data: {
        name,
        ownershipPct: pct,
        status: status || 'active'
      }
    });

    return res.status(201).json(partner);
  } catch (error) {
    console.error('Create partner error:', error);
    return res.status(500).json({ error: 'Failed to create partner' });
  }
}

export async function updatePartner(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    const { name, ownershipPct, status } = req.body;

    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid partner ID' });
    }

    const existing = await prisma.partner.findUnique({ where: { id } });
    if (!existing) {
      return res.status(404).json({ error: 'Partner not found' });
    }

    let pct = existing.ownershipPct;
    if (ownershipPct !== undefined) {
      const parsed = parseFloat(ownershipPct);
      if (isNaN(parsed) || parsed < 0 || parsed > 100) {
        return res.status(400).json({ error: 'Ownership percentage must be between 0 and 100' });
      }
      pct = parsed as any;
    }

    const newStatus = status || existing.status;

    // Check sum of other active partners' ownershipPct + new pct <= 100
    const otherActivePartners = await prisma.partner.findMany({
      where: {
        id: { not: id },
        status: 'active'
      }
    });
    const currentSum = otherActivePartners.reduce((sum, p) => sum + Number(p.ownershipPct), 0);

    if (newStatus === 'active' && currentSum + Number(pct) > 100) {
      return res.status(400).json({ error: `Total ownership of active partners cannot exceed 100%. Current sum of other active partners: ${currentSum}%.` });
    }

    const partner = await prisma.partner.update({
      where: { id },
      data: {
        name: name || existing.name,
        ownershipPct: pct,
        status: newStatus
      }
    });

    return res.json(partner);
  } catch (error) {
    console.error('Update partner error:', error);
    return res.status(500).json({ error: 'Failed to update partner' });
  }
}

// Allocations log history
export async function getAllocations(req: AuthenticatedRequest, res: Response) {
  try {
    const allocations = await prisma.paymentAllocation.findMany({
      include: {
        payment: {
          include: {
            invoice: {
              select: { invoiceNumber: true, client: { select: { companyName: true } } }
            }
          }
        }
      },
      orderBy: { createdAt: 'desc' }
    });
    return res.json(allocations);
  } catch (error) {
    console.error('Get allocations error:', error);
    return res.status(500).json({ error: 'Failed to retrieve profit allocations' });
  }
}

// Partner payouts log list
export async function getPayouts(req: AuthenticatedRequest, res: Response) {
  try {
    const payouts = await prisma.partnerPayout.findMany({
      include: {
        partner: true,
        allocation: {
          include: {
            payment: {
              include: {
                invoice: { select: { invoiceNumber: true } }
              }
            }
          }
        }
      },
      orderBy: { createdAt: 'desc' }
    });

    const expenses = await prisma.expense.findMany({});
    const totalExpenses = expenses.reduce((sum, e) => sum + Number(e.amount), 0);

    const allocations = await prisma.paymentAllocation.findMany({});
    const totalRevenue = allocations.reduce((sum, a) => sum + Number(a.grossAmount), 0);

    const adjustedPayouts = payouts.map(p => {
      if (!p.allocation) return p;
      const paymentAmount = Number(p.allocation.grossAmount);
      const ratio = totalRevenue > 0 ? (paymentAmount / totalRevenue) : 0;
      const propExpense = totalExpenses * ratio;
      
      const paymentCOGS = Number(p.allocation.cogsAmount);
      const paymentTax = Number(p.allocation.taxAmount);
      const netAmount = paymentAmount - paymentCOGS - paymentTax - propExpense;
      const partnerPoolAmount = netAmount * 0.50;
      const partnerAmount = partnerPoolAmount * (Number(p.partner.ownershipPct) / 100);

      return {
        ...p,
        amount: partnerAmount.toString()
      };
    });

    return res.json(adjustedPayouts);
  } catch (error) {
    console.error('Get payouts error:', error);
    return res.status(500).json({ error: 'Failed to retrieve partner payouts' });
  }
}

export async function markPayoutPaid(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);

    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid payout ID' });
    }

    const payout = await prisma.partnerPayout.findUnique({ where: { id } });
    if (!payout) {
      return res.status(404).json({ error: 'Partner payout not found' });
    }

    if (payout.status === 'paid') {
      return res.status(400).json({ error: 'Payout is already marked as paid' });
    }

    const updated = await prisma.partnerPayout.update({
      where: { id },
      data: {
        status: 'paid',
        paidDate: new Date()
      }
    });

    // Audit Log sensitive action
    const userEmail = req.user?.email || 'admin@pos.com';
    const userId = req.user?.id || 1;
    await prisma.auditLog.create({
      data: {
        userId,
        userEmail,
        action: 'PAY_PARTNER',
        details: `Marked payout ID ${payout.id} (Amount: LKR ${payout.amount}) as Paid.`
      }
    });

    return res.json(updated);
  } catch (error) {
    console.error('Mark payout paid error:', error);
    return res.status(500).json({ error: 'Failed to update payout status' });
  }
}

// Reinvestment fund
export async function getReinvestmentStatement(req: AuthenticatedRequest, res: Response) {
  try {
    // 1. Sum of all reinvestment additions
    const additions = await prisma.paymentAllocation.findMany({
      select: {
        id: true,
        grossAmount: true,
        cogsAmount: true,
        taxAmount: true,
        reinvestAmount: true,
        createdAt: true,
        payment: {
          select: { invoice: { select: { invoiceNumber: true } } }
        }
      },
      orderBy: { createdAt: 'desc' }
    });

    // 2. Sum of all reinvestment usages
    const usages = await prisma.reinvestmentUsage.findMany({
      orderBy: { usedDate: 'desc' }
    });

    const expenses = await prisma.expense.findMany({});
    const totalExpenses = expenses.reduce((sum, e) => sum + Number(e.amount), 0);

    const totalRevenue = additions.reduce((sum, a) => sum + Number(a.grossAmount), 0);

    const adjustedAdditions = additions.map(a => {
      const paymentAmount = Number(a.grossAmount);
      const ratio = totalRevenue > 0 ? (paymentAmount / totalRevenue) : 0;
      const propExpense = totalExpenses * ratio;

      const paymentCOGS = Number(a.cogsAmount);
      const paymentTax = Number(a.taxAmount);
      const netAmount = paymentAmount - paymentCOGS - paymentTax - propExpense;
      const reinvestAmount = netAmount * 0.50;

      return {
        id: a.id,
        reinvestAmount: reinvestAmount.toString(),
        createdAt: a.createdAt,
        payment: a.payment
      };
    });

    const totalAdded = adjustedAdditions.reduce((sum, a) => sum + Number(a.reinvestAmount), 0);
    const totalUsed = usages.reduce((sum, u) => sum + Number(u.amountUsed), 0);
    const runningBalance = totalAdded - totalUsed;

    return res.json({
      runningBalance,
      totalAdded,
      totalUsed,
      additions: adjustedAdditions,
      usages
    });
  } catch (error) {
    console.error('Get reinvestment statement error:', error);
    return res.status(500).json({ error: 'Failed to retrieve reinvestment statement' });
  }
}

export async function logReinvestmentUsage(req: AuthenticatedRequest, res: Response) {
  try {
    const { amountUsed, purpose, usedDate } = req.body;

    if (!amountUsed || !purpose) {
      return res.status(400).json({ error: 'Amount used and purpose/description are required' });
    }

    const amt = parseFloat(amountUsed);
    if (isNaN(amt) || amt <= 0) {
      return res.status(400).json({ error: 'Amount used must be a positive number' });
    }

    // Check if sufficient fund balance exists
    const allocations = await prisma.paymentAllocation.findMany({});
    const usages = await prisma.reinvestmentUsage.findMany({});
    const expenses = await prisma.expense.findMany({});

    const totalExpenses = expenses.reduce((sum, e) => sum + Number(e.amount), 0);
    const totalRevenue = allocations.reduce((sum, a) => sum + Number(a.grossAmount), 0);

    const totalAdded = allocations.reduce((sum, a) => {
      const paymentAmount = Number(a.grossAmount);
      const ratio = totalRevenue > 0 ? (paymentAmount / totalRevenue) : 0;
      const propExpense = totalExpenses * ratio;

      const paymentCOGS = Number(a.cogsAmount);
      const paymentTax = Number(a.taxAmount);
      const netAmount = paymentAmount - paymentCOGS - paymentTax - propExpense;
      return sum + (netAmount * 0.50);
    }, 0);

    const totalUsed = usages.reduce((sum, u) => sum + Number(u.amountUsed), 0);
    const runningBalance = totalAdded - totalUsed;

    if (amt > runningBalance) {
      return res.status(400).json({ error: `Insufficient funds. Available reinvestment balance is LKR ${runningBalance.toFixed(2)}` });
    }

    const usage = await prisma.reinvestmentUsage.create({
      data: {
        amountUsed: amt,
        purpose,
        usedDate: usedDate ? new Date(usedDate) : new Date()
      }
    });

    // Audit Log
    const userEmail = req.user?.email || 'admin@pos.com';
    const userId = req.user?.id || 1;
    await prisma.auditLog.create({
      data: {
        userId,
        userEmail,
        action: 'USE_REINVESTMENT_FUND',
        details: `Spent LKR ${amt} from reinvestment fund. Purpose: ${purpose}`
      }
    });

    return res.status(201).json(usage);
  } catch (error) {
    console.error('Log reinvestment usage error:', error);
    return res.status(500).json({ error: 'Failed to log reinvestment usage' });
  }
}

// Operational expenses
export async function getExpenses(req: AuthenticatedRequest, res: Response) {
  try {
    const expenses = await prisma.expense.findMany({
      orderBy: { expenseDate: 'desc' }
    });
    return res.json(expenses);
  } catch (error) {
    console.error('Get expenses error:', error);
    return res.status(500).json({ error: 'Failed to retrieve expenses' });
  }
}

export async function createExpense(req: AuthenticatedRequest, res: Response) {
  try {
    const { category, amount, expenseDate, notes } = req.body;

    if (!category || !amount || !expenseDate) {
      return res.status(400).json({ error: 'Category, amount, and expense date are required' });
    }

    const amt = parseFloat(amount);
    if (isNaN(amt) || amt <= 0) {
      return res.status(400).json({ error: 'Expense amount must be a positive number' });
    }

    const expense = await prisma.expense.create({
      data: {
        category,
        amount: amt,
        expenseDate: new Date(expenseDate),
        notes
      }
    });

    return res.status(201).json(expense);
  } catch (error) {
    console.error('Create expense error:', error);
    return res.status(500).json({ error: 'Failed to log operational expense' });
  }
}

export async function updateExpense(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid expense ID' });
    }

    const { category, amount, expenseDate, notes } = req.body;

    if (!category || !amount || !expenseDate) {
      return res.status(400).json({ error: 'Category, amount, and expense date are required' });
    }

    const amt = parseFloat(amount);
    if (isNaN(amt) || amt <= 0) {
      return res.status(400).json({ error: 'Expense amount must be a positive number' });
    }

    const updated = await prisma.expense.update({
      where: { id },
      data: {
        category,
        amount: amt,
        expenseDate: new Date(expenseDate),
        notes
      }
    });

    return res.json(updated);
  } catch (error) {
    console.error('Update expense error:', error);
    return res.status(500).json({ error: 'Failed to update operational expense' });
  }
}

export async function deleteExpense(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid expense ID' });
    }

    await prisma.expense.delete({
      where: { id }
    });

    return res.json({ message: 'Operational expense deleted successfully' });
  } catch (error) {
    console.error('Delete expense error:', error);
    return res.status(500).json({ error: 'Failed to delete operational expense' });
  }
}
