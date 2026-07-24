import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';
import { syncContactCreate, syncContactUpdate, syncContactDelete, sendSMS } from '../utils/textLk';

export async function getClients(req: AuthenticatedRequest, res: Response) {
  try {
    const { search, isActive } = req.query;

    const whereClause: any = {};

    if (isActive !== undefined) {
      whereClause.isActive = isActive === 'true';
    }

    if (search) {
      const searchStr = String(search);
      whereClause.OR = [
        { companyName: { contains: searchStr, mode: 'insensitive' } },
        { contactPerson: { contains: searchStr, mode: 'insensitive' } },
        { phone: { contains: searchStr, mode: 'insensitive' } },
        { email: { contains: searchStr, mode: 'insensitive' } },
      ];
    }

    const clients = await prisma.client.findMany({
      where: whereClause,
      orderBy: { createdAt: 'desc' },
    });

    return res.json(clients);
  } catch (error: any) {
    console.error('Get clients error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getClientById(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid client ID' });
    }

    const client = await prisma.client.findUnique({
      where: { id },
      include: {
        quotations: {
          orderBy: { createdAt: 'desc' },
          take: 5,
        },
        invoices: {
          orderBy: { createdAt: 'desc' },
          take: 5,
        },
        supportTickets: {
          orderBy: { createdAt: 'desc' },
          take: 5,
        },
        recurringInvoices: {
          where: { isActive: true },
        },
      },
    });

    if (!client) {
      return res.status(404).json({ error: 'Client not found' });
    }

    return res.json(client);
  } catch (error: any) {
    console.error('Get client by ID error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function createClient(req: AuthenticatedRequest, res: Response) {
  try {
    const { companyName, contactPerson, phone, email, address, notes } = req.body;

    if (!companyName || !contactPerson || !phone) {
      return res.status(400).json({ error: 'Company name, contact person, and phone are required' });
    }

    const client = await prisma.client.create({
      data: {
        companyName,
        contactPerson,
        phone,
        email,
        address,
        notes,
      },
    });

    // Sync to Text.lk Contacts API
    let syncedUid: string | null = null;
    try {
      syncedUid = await syncContactCreate(phone, contactPerson, companyName);
      if (syncedUid) {
        await prisma.client.update({
          where: { id: client.id },
          data: { textLkUid: syncedUid }
        });
        client.textLkUid = syncedUid;
      }
    } catch (apiError) {
      console.error('[Text.lk] Failed to synchronize newly created client:', apiError);
    }

    // Send Welcome SMS
    try {
      const welcomeTemplate = await prisma.systemSetting.findUnique({
        where: { key: 'text_lk_welcome_message' }
      });
      if (welcomeTemplate && welcomeTemplate.value) {
        const message = welcomeTemplate.value
          .replace(/{contactPerson}/g, contactPerson)
          .replace(/{companyName}/g, companyName);
        await sendSMS(phone, message);
      }
    } catch (smsError) {
      console.error('[Text.lk] Failed to send welcome SMS:', smsError);
    }

    return res.status(201).json(client);
  } catch (error: any) {
    console.error('Create client error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function updateClient(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid client ID' });
    }

    const { companyName, contactPerson, phone, email, address, notes, isActive } = req.body;

    const existingClient = await prisma.client.findUnique({
      where: { id },
    });

    if (!existingClient) {
      return res.status(404).json({ error: 'Client not found' });
    }

    const updatedClient = await prisma.client.update({
      where: { id },
      data: {
        companyName,
        contactPerson,
        phone,
        email,
        address,
        notes,
        isActive: isActive !== undefined ? Boolean(isActive) : undefined,
      },
    });

    // Sync to Text.lk Contacts API
    try {
      const hasChanged =
        existingClient.phone !== phone ||
        existingClient.contactPerson !== contactPerson ||
        existingClient.companyName !== companyName;

      if (existingClient.textLkUid) {
        if (hasChanged) {
          await syncContactUpdate(
            existingClient.textLkUid,
            phone || existingClient.phone,
            contactPerson || existingClient.contactPerson,
            companyName || existingClient.companyName
          );
        }
      } else {
        // If not previously synced, create now
        const newTextLkUid = await syncContactCreate(
          phone || existingClient.phone,
          contactPerson || existingClient.contactPerson,
          companyName || existingClient.companyName
        );
        if (newTextLkUid) {
          const finalClient = await prisma.client.update({
            where: { id },
            data: { textLkUid: newTextLkUid }
          });
          return res.json(finalClient);
        }
      }
    } catch (apiError) {
      console.error('[Text.lk] Failed to synchronize client update:', apiError);
    }

    return res.json(updatedClient);
  } catch (error: any) {
    console.error('Update client error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function deleteClient(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid client ID' });
    }

    const force = req.query.force === 'true';

    const client = await prisma.client.findUnique({
      where: { id },
    });

    if (!client) {
      return res.status(404).json({ error: 'Client not found' });
    }

    if (force) {
      // Hard delete from database
      await prisma.client.delete({
        where: { id },
      });

      // Sync Delete with Text.lk
      if (client.textLkUid) {
        try {
          await syncContactDelete(client.textLkUid);
        } catch (apiError) {
          console.error('[Text.lk] Failed to delete synced contact:', apiError);
        }
      }

      return res.json({ message: 'Client hard deleted successfully' });
    } else {
      // Soft delete: toggle isActive to false
      await prisma.client.update({
        where: { id },
        data: { isActive: false },
      });
      return res.json({ message: 'Client deactivated successfully' });
    }
  } catch (error: any) {
    console.error('Delete client error:', error);
    // If foreign key constraint prevents deletion
    if (error.code === 'P2003') {
      return res.status(400).json({
        error: 'Cannot delete client with active invoices, quotations, or tickets. Use soft delete or deactivate instead.',
      });
    }
    return res.status(500).json({ error: 'Internal server error' });
  }
}
