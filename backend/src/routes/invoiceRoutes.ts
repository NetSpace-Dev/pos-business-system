import { Router } from 'express';
import { getInvoices, getInvoiceById, createInvoice, updateInvoice, recordPayment, deleteInvoice, sendInvoiceReminder } from '../controllers/invoiceController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

// Apply auth middleware to all invoice routes
router.use(authenticateJWT as any);

router.get('/', getInvoices as any);
router.get('/:id', getInvoiceById as any);
router.post('/', createInvoice as any);
router.put('/:id', updateInvoice as any);
router.post('/:id/payments', recordPayment as any);
router.post('/:id/remind', sendInvoiceReminder as any);
router.delete('/:id', deleteInvoice as any);

export default router;
