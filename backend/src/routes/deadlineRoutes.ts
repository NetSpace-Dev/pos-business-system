import { Router } from 'express';
import { getDeadlines, createDeadline, updateDeadline, deleteDeadline } from '../controllers/deadlineController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

router.use(authenticateJWT as any);

router.get('/', getDeadlines as any);
router.post('/', createDeadline as any);
router.put('/:id', updateDeadline as any);
router.delete('/:id', deleteDeadline as any);

export default router;
