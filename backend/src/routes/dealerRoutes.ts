import { Router } from 'express';
import { getDealers, getDealerById, createDealer, updateDealer, addTransaction, deleteDealer } from '../controllers/dealerController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

// Apply auth middleware to all dealer routes
router.use(authenticateJWT as any);

router.get('/', getDealers as any);
router.get('/:id', getDealerById as any);
router.post('/', createDealer as any);
router.put('/:id', updateDealer as any);
router.post('/:id/transactions', addTransaction as any);
router.delete('/:id', deleteDealer as any);

export default router;
