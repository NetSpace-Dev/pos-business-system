import { Router } from 'express';
import { getRecurringConfigs, getRecurringConfigById, createRecurringConfig, updateRecurringConfig, deleteRecurringConfig } from '../controllers/recurringInvoiceController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

// Apply auth middleware to all recurring invoice routes
router.use(authenticateJWT as any);

router.get('/', getRecurringConfigs as any);
router.get('/:id', getRecurringConfigById as any);
router.post('/', createRecurringConfig as any);
router.put('/:id', updateRecurringConfig as any);
router.delete('/:id', deleteRecurringConfig as any);

export default router;
