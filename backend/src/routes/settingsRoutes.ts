import { Router } from 'express';
import { getSettings, updateSettings, getSettingsHistory } from '../controllers/settingsController';
import { authenticateJWT, checkPermission } from '../middleware/auth';

const router = Router();

router.use(authenticateJWT as any);

router.get('/', checkPermission('finance', 'settings') as any, getSettings as any);
router.post('/', checkPermission('finance', 'settings') as any, updateSettings as any);
router.get('/history', checkPermission('finance', 'settings') as any, getSettingsHistory as any);

export default router;
