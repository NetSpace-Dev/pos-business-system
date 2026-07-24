import { Router } from 'express';
import { getClients, getClientById, createClient, updateClient, deleteClient } from '../controllers/clientController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

// Apply auth middleware to all client routes
router.use(authenticateJWT as any);

router.get('/', getClients as any);
router.get('/:id', getClientById as any);
router.post('/', createClient as any);
router.put('/:id', updateClient as any);
router.delete('/:id', deleteClient as any);

export default router;
