import express from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import path from 'path';

// Import routes
import authRoutes from './routes/authRoutes';
import clientRoutes from './routes/clientRoutes';
import inventoryRoutes from './routes/inventoryRoutes';
import dealerRoutes from './routes/dealerRoutes';
import quotationRoutes from './routes/quotationRoutes';
import invoiceRoutes from './routes/invoiceRoutes';
import recurringInvoiceRoutes from './routes/recurringInvoiceRoutes';
import ticketRoutes from './routes/ticketRoutes';
import reportRoutes from './routes/reportRoutes';
import deadlineRoutes from './routes/deadlineRoutes';
import settingsRoutes from './routes/settingsRoutes';
import userRoutes from './routes/userRoutes';
import financeRoutes from './routes/financeRoutes';

// Import cron scheduler
import { startCronJobs } from './utils/cron';

dotenv.config();

const app = express();
const PORT = process.env.PORT || 4000;

app.use(cors());
app.use(express.json());

// Serve static UI client dashboard
app.use(express.static(path.join(__dirname, '../public')));

// API health check
app.get('/api/health', (req, res) => {
  res.json({ status: 'ok', message: 'POS Business backend running' });
});

// Mount modular API routes
app.use('/api/auth', authRoutes);
app.use('/api/clients', clientRoutes);
app.use('/api/inventory', inventoryRoutes);
app.use('/api/dealers', dealerRoutes);
app.use('/api/quotations', quotationRoutes);
app.use('/api/invoices', invoiceRoutes);
app.use('/api/recurring-invoices', recurringInvoiceRoutes);
app.use('/api/tickets', ticketRoutes);
app.use('/api/reports', reportRoutes);
app.use('/api/deadlines', deadlineRoutes);
app.use('/api/settings', settingsRoutes);
app.use('/api/users', userRoutes);
app.use('/api/finance', financeRoutes);

// Global Error Handler
app.use((err: any, req: express.Request, res: express.Response, next: express.NextFunction) => {
  console.error('Unhandled server error:', err);
  res.status(500).json({ error: 'Internal server error occurred' });
});

// Start server
app.listen(PORT, () => {
  console.log(`Server running on http://localhost:${PORT}`);
  
  // Start background automation cron tasks
  startCronJobs();
});
