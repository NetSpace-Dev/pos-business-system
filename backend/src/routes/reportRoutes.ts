import { Router } from 'express';
import {
  getDashboardSummary,
  getProfitLoss,
  getInvoiceAging,
  getStockValuation,
  getCashFlow,
  getQuotationConversion,
  getDealerDues
} from '../controllers/reportController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

router.use(authenticateJWT as any);

router.get('/dashboard', getDashboardSummary as any);
router.get('/profit-loss', getProfitLoss as any);
router.get('/invoice-aging', getInvoiceAging as any);
router.get('/stock-valuation', getStockValuation as any);
router.get('/cash-flow', getCashFlow as any);
router.get('/quotation-conversion', getQuotationConversion as any);
router.get('/dealer-dues', getDealerDues as any);

export default router;
