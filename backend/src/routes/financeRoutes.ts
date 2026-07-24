import { Router } from 'express';
import {
  getPartners,
  createPartner,
  updatePartner,
  getAllocations,
  getPayouts,
  markPayoutPaid,
  getReinvestmentStatement,
  logReinvestmentUsage,
  getExpenses,
  createExpense,
  updateExpense,
  deleteExpense
} from '../controllers/financeController';
import { authenticateJWT, checkPermission } from '../middleware/auth';

const router = Router();

router.use(authenticateJWT as any);

// Partners management
router.get('/partners', checkPermission('finance', 'partners') as any, getPartners as any);
router.post('/partners', checkPermission('finance', 'partners') as any, createPartner as any);
router.put('/partners/:id', checkPermission('finance', 'partners') as any, updatePartner as any);

// Payout allocations
router.get('/allocations', checkPermission('finance', 'allocations') as any, getAllocations as any);

// Partner payouts
router.get('/payouts', checkPermission('finance', 'allocations') as any, getPayouts as any);
router.post('/payouts/:id/pay', checkPermission('finance', 'allocations') as any, markPayoutPaid as any);

// Reinvestment fund
router.get('/reinvestment', checkPermission('finance', 'allocations') as any, getReinvestmentStatement as any);
router.post('/reinvestment/use', checkPermission('finance', 'allocations') as any, logReinvestmentUsage as any);

// Operational expenses
router.get('/expenses', checkPermission('finance', 'allocations') as any, getExpenses as any);
router.post('/expenses', checkPermission('finance', 'allocations') as any, createExpense as any);
router.put('/expenses/:id', checkPermission('finance', 'allocations') as any, updateExpense as any);
router.delete('/expenses/:id', checkPermission('finance', 'allocations') as any, deleteExpense as any);

export default router;
