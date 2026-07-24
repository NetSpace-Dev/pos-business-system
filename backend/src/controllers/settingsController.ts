import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';

export async function getSettings(req: AuthenticatedRequest, res: Response) {
  try {
    const settings = await prisma.systemSetting.findMany();
    const settingsObj = settings.reduce((acc: any, curr) => {
      acc[curr.key] = curr.value;
      return acc;
    }, {});
    return res.json(settingsObj);
  } catch (error) {
    console.error('Get settings error:', error);
    return res.status(500).json({ error: 'Failed to retrieve settings' });
  }
}

export async function updateSettings(req: AuthenticatedRequest, res: Response) {
  try {
    const updates = req.body;
    const userEmail = req.user?.email || 'admin@pos.com';
    const userId = req.user?.id || 1;
    const auditDetails: string[] = [];

    await prisma.$transaction(async (tx) => {
      for (const [key, value] of Object.entries(updates)) {
        const strVal = String(value);
        const existing = await tx.systemSetting.findUnique({
          where: { key }
        });

        if (existing) {
          if (existing.value !== strVal) {
            await tx.settingHistory.create({
              data: {
                key,
                oldValue: existing.value,
                newValue: strVal,
                updatedBy: userEmail
              }
            });

            await tx.systemSetting.update({
              where: { key },
              data: {
                value: strVal,
                updatedBy: userEmail
              }
            });

            auditDetails.push(`Changed '${key}' from '${existing.value}' to '${strVal}'`);
          }
        } else {
          await tx.systemSetting.create({
            data: {
              key,
              value: strVal,
              updatedBy: userEmail
            }
          });

          await tx.settingHistory.create({
            data: {
              key,
              oldValue: null,
              newValue: strVal,
              updatedBy: userEmail
            }
          });

          auditDetails.push(`Set new setting '${key}' to '${strVal}'`);
        }
      }

      if (auditDetails.length > 0) {
        await tx.auditLog.create({
          data: {
            userId,
            userEmail,
            action: 'UPDATE_SETTINGS',
            details: auditDetails.join('; ')
          }
        });
      }
    });

    return res.json({ message: 'Settings updated successfully' });
  } catch (error) {
    console.error('Update settings error:', error);
    return res.status(500).json({ error: 'Failed to update settings' });
  }
}

export async function getSettingsHistory(req: AuthenticatedRequest, res: Response) {
  try {
    const history = await prisma.settingHistory.findMany({
      orderBy: { updatedAt: 'desc' }
    });
    return res.json(history);
  } catch (error) {
    console.error('Get settings history error:', error);
    return res.status(500).json({ error: 'Failed to retrieve settings change log' });
  }
}
