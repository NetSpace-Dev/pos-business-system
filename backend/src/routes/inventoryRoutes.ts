import { Router } from 'express';
import { getItems, getItemById, createItem, updateItem, adjustStock, deleteItem } from '../controllers/inventoryController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

// Apply auth middleware to all inventory routes
router.use(authenticateJWT as any);

router.get('/', getItems as any);
router.get('/:id', getItemById as any);
router.post('/', createItem as any);
router.put('/:id', updateItem as any);
router.post('/:id/stock', adjustStock as any);
router.delete('/:id', deleteItem as any);

export default router;
