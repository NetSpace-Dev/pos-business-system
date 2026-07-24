import { Router } from 'express';
import { login, register, me } from '../controllers/authController';
import { authenticateJWT, requireAdmin } from '../middleware/auth';

const router = Router();

// Public login route
router.post('/login', login);

// Admin-only registration route
router.post('/register', authenticateJWT as any, requireAdmin as any, register as any);

// Authenticated user session
router.get('/me', authenticateJWT as any, me as any);

export default router;
