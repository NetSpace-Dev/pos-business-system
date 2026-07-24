import { Router } from 'express';
import {
  getSmsTemplates,
  getSmsTemplateById,
  createSmsTemplate,
  updateSmsTemplate,
  deleteSmsTemplate
} from '../controllers/smsTemplateController';
import { authenticateJWT } from '../middleware/auth';

const router = Router();

router.use(authenticateJWT as any);

router.get('/', getSmsTemplates as any);
router.get('/:id', getSmsTemplateById as any);
router.post('/', createSmsTemplate as any);
router.put('/:id', updateSmsTemplate as any);
router.delete('/:id', deleteSmsTemplate as any);

export default router;
