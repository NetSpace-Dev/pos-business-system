import { Router } from 'express';
import { getUsers, updateUserStatus, updateUserRole, getRoles, createRole, updateRole, getAuditLogs } from '../controllers/userController';
import { authenticateJWT, checkPermission } from '../middleware/auth';

const router = Router();

router.use(authenticateJWT as any);

// Users management
router.get('/', checkPermission('users', 'manage') as any, getUsers as any);
router.post('/:id/status', checkPermission('users', 'manage') as any, updateUserStatus as any);
router.put('/:id/role', checkPermission('users', 'manage') as any, updateUserRole as any);
router.get('/audit-logs', checkPermission('users', 'manage') as any, getAuditLogs as any);

// Roles management
router.get('/roles', checkPermission('roles', 'manage') as any, getRoles as any);
router.post('/roles', checkPermission('roles', 'manage') as any, createRole as any);
router.put('/roles/:id', checkPermission('roles', 'manage') as any, updateRole as any);

export default router;
