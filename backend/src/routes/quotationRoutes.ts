import { Router } from 'express';
import { getQuotations, getQuotationById, createQuotation, updateQuotation, convertToInvoice, deleteQuotation } from '../controllers/quotationController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

// Apply auth middleware to all quotation routes
router.use(authenticateJWT as any);

router.get('/', getQuotations as any);
router.get('/:id', getQuotationById as any);
router.post('/', createQuotation as any);
router.put('/:id', updateQuotation as any);
router.post('/:id/convert', convertToInvoice as any);
router.delete('/:id', deleteQuotation as any);

export default router;
