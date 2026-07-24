import { Router } from 'express';
import { getTickets, getTicketById, createTicket, addTicketUpdate, deleteTicket } from '../controllers/ticketController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

// Apply auth middleware to all ticket routes
router.use(authenticateJWT as any);

router.get('/', getTickets as any);
router.get('/:id', getTicketById as any);
router.post('/', createTicket as any);
router.post('/:id/updates', addTicketUpdate as any);
router.delete('/:id', deleteTicket as any);

export default router;
