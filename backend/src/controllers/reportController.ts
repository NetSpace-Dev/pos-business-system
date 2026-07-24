import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';

export async function getDashboardSummary(req: AuthenticatedRequest, res: Response) {
  try {
    const currentYear = new Date().getFullYear();
    const yearStart = new Date(currentYear, 0, 1);

    // 1. Total Invoice Earnings, Outstanding Receivables, and Estimated Profit
    const invoices = await prisma.invoice.findMany({
      where: {
        status: {
          not: 'cancelled',
        },
      },
      include: {
        items: {
          include: {
            item: {
              select: {
                unitCost: true,
              },
            },
          },
        },
      },
    });

    // Calculate Cash-basis metrics from payment allocations
    const allocations = await prisma.paymentAllocation.findMany({});
    const totalRevenue = allocations.reduce((sum, a) => sum + Number(a.grossAmount), 0);
    const totalCOGS = allocations.reduce((sum, a) => sum + Number(a.cogsAmount), 0);

    // Calculate Receivables Outstanding from finalized invoices
    let totalOutstanding = 0;
    invoices.forEach((inv) => {
      if (inv.status !== 'draft' && inv.status !== 'cancelled') {
        const total = Number(inv.totalAmount);
        const paid = Number(inv.amountPaid);
        totalOutstanding += (total - paid);
      }
    });

    // Deduct operational expenses from Cash-basis Gross Profit to get Net Profit
    const totalExpensesSum = await prisma.expense.aggregate({
      _sum: {
        amount: true,
      },
    });
    const expensesSum = Number(totalExpensesSum._sum.amount || 0);
    const totalProfit = totalRevenue - totalCOGS - expensesSum;

    // 2. Counts
    const activeClientsCount = await prisma.client.count({
      where: { isActive: true },
    });

    const activeTicketsCount = await prisma.supportTicket.count({
      where: {
        status: {
          in: ['open', 'in_progress'],
        },
      },
    });

    // 3. Low stock alert items list & count
    const allTrackedItems = await prisma.inventoryItem.findMany({
      where: {
        isTracked: true,
        isActive: true,
      },
    });

    const lowStockItems = allTrackedItems
      .filter((item) => item.stockQty <= item.reorderLevel)
      .map((item) => ({
        id: item.id,
        sku: item.sku,
        name: item.name,
        stockQty: item.stockQty,
        reorderLevel: item.reorderLevel,
      }));

    // 4. Monthly sales aggregation (for current year)
    const monthlySales = Array(12).fill(0).map((_, idx) => ({
      month: new Date(currentYear, idx).toLocaleString('default', { month: 'short' }),
      sales: 0,
      payments: 0,
    }));

    invoices.forEach((inv) => {
      const invDate = new Date(inv.issueDate);
      if (invDate.getFullYear() === currentYear && inv.status !== 'draft') {
        const monthIdx = invDate.getMonth();
        monthlySales[monthIdx].sales += Number(inv.totalAmount);
        monthlySales[monthIdx].payments += Number(inv.amountPaid);
      }
    });

    // 5. Recent Invoices (limit to 5)
    const recentInvoices = await prisma.invoice.findMany({
      orderBy: { createdAt: 'desc' },
      take: 5,
      include: {
        client: {
          select: { companyName: true },
        },
      },
    });

    // 6. Recent Tickets (limit to 5)
    const recentTickets = await prisma.supportTicket.findMany({
      orderBy: { createdAt: 'desc' },
      take: 5,
      include: {
        client: {
          select: { companyName: true },
        },
      },
    });

    return res.json({
      metrics: {
        totalRevenue,
        totalOutstanding,
        totalProfit,
        activeClientsCount,
        activeTicketsCount,
        lowStockCount: lowStockItems.length,
      },
      lowStockAlerts: lowStockItems.slice(0, 5), // return top 5 alerts
      monthlySalesChart: monthlySales,
      recentInvoices,
      recentTickets,
    });
  } catch (error: any) {
    console.error('Get dashboard summary error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getProfitLoss(req: AuthenticatedRequest, res: Response) {
  try {
    const invoices = await prisma.invoice.findMany({
      where: { status: { notIn: ['cancelled', 'draft'] } },
      include: {
        items: {
          include: {
            item: true
          }
        }
      }
    });

    const expenses = await prisma.expense.findMany({});
    const expensesSum = expenses.reduce((sum, e) => sum + Number(e.amount), 0);

    // 1. Calculate Estimated Basis (Accrual)
    let estRevenue = 0;
    let estCOGS = 0;

    invoices.forEach(inv => {
      estRevenue += Number(inv.totalAmount);
      inv.items.forEach(line => {
        const cost = line.item ? Number(line.item.unitCost) : 0;
        estCOGS += line.quantity * cost;
      });
    });

    const estGrossProfit = estRevenue - estCOGS;
    const estNetProfit = estGrossProfit - expensesSum;

    // 2. Calculate Actual Basis (Cash)
    const allocations = await prisma.paymentAllocation.findMany({});
    const actRevenue = allocations.reduce((sum, a) => sum + Number(a.grossAmount), 0);
    const actCOGS = allocations.reduce((sum, a) => sum + Number(a.cogsAmount), 0);

    const actGrossProfit = actRevenue - actCOGS;
    const actNetProfit = actGrossProfit - expensesSum;

    return res.json({
      actual: {
        revenue: actRevenue,
        cogs: actCOGS,
        grossProfit: actGrossProfit,
        expenses: expensesSum,
        profit: actNetProfit
      },
      estimated: {
        revenue: estRevenue,
        cogs: estCOGS,
        grossProfit: estGrossProfit,
        expenses: expensesSum,
        profit: estNetProfit
      }
    });
  } catch (err: any) {
    console.error(err);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getInvoiceAging(req: AuthenticatedRequest, res: Response) {
  try {
    const today = new Date();
    const unpaidInvoices = await prisma.invoice.findMany({
      where: {
        status: { in: ['sent', 'partially_paid'] },
        dueDate: { lt: today }
      },
      include: {
        client: { select: { companyName: true } }
      }
    });

    const aging = {
      bucket30: [] as any[], // 0 to 30 days overdue
      bucket60: [] as any[], // 31 to 60 days overdue
      bucket90: [] as any[]  // 61+ days overdue
    };

    unpaidInvoices.forEach(inv => {
      const diffTime = Math.abs(today.getTime() - inv.dueDate.getTime());
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
      const outstanding = Number(inv.totalAmount) - Number(inv.amountPaid);

      const data = {
        invoiceNumber: inv.invoiceNumber,
        companyName: inv.client.companyName,
        dueDate: inv.dueDate,
        overdueDays: diffDays,
        outstanding
      };

      if (diffDays <= 30) {
        aging.bucket30.push(data);
      } else if (diffDays <= 60) {
        aging.bucket60.push(data);
      } else {
        aging.bucket90.push(data);
      }
    });

    return res.json(aging);
  } catch (err: any) {
    console.error(err);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getStockValuation(req: AuthenticatedRequest, res: Response) {
  try {
    const items = await prisma.inventoryItem.findMany({
      where: { isTracked: true, isActive: true }
    });

    let totalCostValue = 0;
    let totalSellValue = 0;
    let totalItemsCount = 0;

    items.forEach(item => {
      const cost = Number(item.unitCost);
      const sell = Number(item.sellPrice);
      const qty = item.stockQty;

      totalCostValue += qty * cost;
      totalSellValue += qty * sell;
      totalItemsCount += qty;
    });

    return res.json({
      totalCostValue,
      totalSellValue,
      totalItemsCount,
      potentialProfit: totalSellValue - totalCostValue
    });
  } catch (err: any) {
    console.error(err);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getCashFlow(req: AuthenticatedRequest, res: Response) {
  try {
    const clientPayments = await prisma.payment.findMany({
      select: { amount: true }
    });
    const dealerPayments = await prisma.dealerTransaction.findMany({
      where: { type: 'payment' },
      select: { amount: true }
    });

    const cashIn = clientPayments.reduce((sum, p) => sum + Number(p.amount), 0);
    const cashOut = dealerPayments.reduce((sum, t) => sum + Number(t.amount), 0);

    return res.json({
      cashIn,
      cashOut,
      netFlow: cashIn - cashOut
    });
  } catch (err: any) {
    console.error(err);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getQuotationConversion(req: AuthenticatedRequest, res: Response) {
  try {
    const totalQuotes = await prisma.quotation.count();
    const convertedQuotes = await prisma.quotation.count({
      where: { status: 'converted' }
    });

    const rate = totalQuotes > 0 ? (convertedQuotes / totalQuotes) * 100 : 0;

    return res.json({
      totalQuotes,
      convertedQuotes,
      rate
    });
  } catch (err: any) {
    console.error(err);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getDealerDues(req: AuthenticatedRequest, res: Response) {
  try {
    const dealers = await prisma.dealer.findMany({
      where: { isActive: true },
      select: { name: true, paymentType: true, balanceDue: true }
    });

    return res.json(dealers);
  } catch (err: any) {
    console.error(err);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

