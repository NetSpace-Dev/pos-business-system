import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';

export async function getSmsTemplates(req: AuthenticatedRequest, res: Response) {
  try {
    const templates = await prisma.smsTemplate.findMany({
      orderBy: { name: 'asc' }
    });
    return res.json(templates);
  } catch (error) {
    console.error('Get SMS templates error:', error);
    return res.status(500).json({ error: 'Failed to retrieve SMS templates' });
  }
}

export async function getSmsTemplateById(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid template ID' });
    }

    const template = await prisma.smsTemplate.findUnique({
      where: { id }
    });

    if (!template) {
      return res.status(404).json({ error: 'SMS template not found' });
    }

    return res.json(template);
  } catch (error) {
    console.error('Get SMS template by ID error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function createSmsTemplate(req: AuthenticatedRequest, res: Response) {
  try {
    const { name, body } = req.body;

    if (!name || !body) {
      return res.status(400).json({ error: 'Name and body are required' });
    }

    const existing = await prisma.smsTemplate.findUnique({
      where: { name }
    });

    if (existing) {
      return res.status(400).json({ error: 'A template with this name already exists' });
    }

    const template = await prisma.smsTemplate.create({
      data: { name, body }
    });

    return res.status(201).json(template);
  } catch (error) {
    console.error('Create SMS template error:', error);
    return res.status(500).json({ error: 'Failed to create SMS template' });
  }
}

export async function updateSmsTemplate(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid template ID' });
    }

    const { name, body } = req.body;

    if (!name || !body) {
      return res.status(400).json({ error: 'Name and body are required' });
    }

    const existingTemplate = await prisma.smsTemplate.findUnique({
      where: { id }
    });

    if (!existingTemplate) {
      return res.status(404).json({ error: 'SMS template not found' });
    }

    // Check if name is taken by another template
    const duplicate = await prisma.smsTemplate.findFirst({
      where: {
        name,
        id: { not: id }
      }
    });

    if (duplicate) {
      return res.status(400).json({ error: 'A template with this name already exists' });
    }

    const updatedTemplate = await prisma.smsTemplate.update({
      where: { id },
      data: { name, body }
    });

    return res.json(updatedTemplate);
  } catch (error) {
    console.error('Update SMS template error:', error);
    return res.status(500).json({ error: 'Failed to update SMS template' });
  }
}

export async function deleteSmsTemplate(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid template ID' });
    }

    const existingTemplate = await prisma.smsTemplate.findUnique({
      where: { id }
    });

    if (!existingTemplate) {
      return res.status(404).json({ error: 'SMS template not found' });
    }

    await prisma.smsTemplate.delete({
      where: { id }
    });

    return res.json({ message: 'SMS template deleted successfully' });
  } catch (error) {
    console.error('Delete SMS template error:', error);
    return res.status(500).json({ error: 'Failed to delete SMS template' });
  }
}
