import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';

export async function getUsers(req: AuthenticatedRequest, res: Response) {
  try {
    const users = await prisma.user.findMany({
      include: {
        roleRef: {
          select: { id: true, name: true }
        }
      },
      orderBy: { createdAt: 'desc' }
    });
    return res.json(users);
  } catch (error) {
    console.error('Get users error:', error);
    return res.status(500).json({ error: 'Failed to retrieve users' });
  }
}

export async function updateUserStatus(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    const { status } = req.body;

    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid user ID' });
    }

    if (status !== 'active' && status !== 'suspended') {
      return res.status(400).json({ error: 'Invalid status value' });
    }

    const targetUser = await prisma.user.findUnique({ where: { id } });
    if (!targetUser) {
      return res.status(404).json({ error: 'User not found' });
    }

    if (req.user?.id === id) {
      return res.status(400).json({ error: 'Cannot modify your own status' });
    }

    const updatedUser = await prisma.user.update({
      where: { id },
      data: { status }
    });

    const userEmail = req.user?.email || 'admin@pos.com';
    const userId = req.user?.id || 1;
    await prisma.auditLog.create({
      data: {
        userId,
        userEmail,
        action: 'UPDATE_USER_STATUS',
        details: `Changed status of user '${targetUser.email}' to '${status}'`
      }
    });

    return res.json(updatedUser);
  } catch (error) {
    console.error('Update user status error:', error);
    return res.status(500).json({ error: 'Failed to update user status' });
  }
}

export async function updateUserRole(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    const { roleId } = req.body;

    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid user ID' });
    }

    const targetUser = await prisma.user.findUnique({ where: { id } });
    if (!targetUser) {
      return res.status(404).json({ error: 'User not found' });
    }

    const role = await prisma.role.findUnique({ where: { id: parseInt(roleId) } });
    if (!role) {
      return res.status(400).json({ error: 'Role does not exist' });
    }

    const updatedUser = await prisma.user.update({
      where: { id },
      data: { roleId: role.id }
    });

    const userEmail = req.user?.email || 'admin@pos.com';
    const userId = req.user?.id || 1;
    await prisma.auditLog.create({
      data: {
        userId,
        userEmail,
        action: 'UPDATE_USER_ROLE',
        details: `Assigned role '${role.name}' to user '${targetUser.email}'`
      }
    });

    return res.json(updatedUser);
  } catch (error) {
    console.error('Update user role error:', error);
    return res.status(500).json({ error: 'Failed to update user role' });
  }
}

export async function getRoles(req: AuthenticatedRequest, res: Response) {
  try {
    const roles = await prisma.role.findMany({
      orderBy: { name: 'asc' }
    });
    return res.json(roles);
  } catch (error) {
    console.error('Get roles error:', error);
    return res.status(500).json({ error: 'Failed to retrieve roles' });
  }
}

export async function createRole(req: AuthenticatedRequest, res: Response) {
  try {
    const { name, permissionSet } = req.body;

    if (!name) {
      return res.status(400).json({ error: 'Role name is required' });
    }

    const existing = await prisma.role.findUnique({ where: { name } });
    if (existing) {
      return res.status(400).json({ error: 'Role with this name already exists' });
    }

    const role = await prisma.role.create({
      data: {
        name,
        permissionSet: typeof permissionSet === 'string' ? permissionSet : JSON.stringify(permissionSet)
      }
    });

    const userEmail = req.user?.email || 'admin@pos.com';
    const userId = req.user?.id || 1;
    await prisma.auditLog.create({
      data: {
        userId,
        userEmail,
        action: 'CREATE_ROLE',
        details: `Created new custom role: '${name}'`
      }
    });

    return res.status(201).json(role);
  } catch (error) {
    console.error('Create role error:', error);
    return res.status(500).json({ error: 'Failed to create role' });
  }
}

export async function updateRole(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    const { name, permissionSet } = req.body;

    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid role ID' });
    }

    const existingRole = await prisma.role.findUnique({ where: { id } });
    if (!existingRole) {
      return res.status(404).json({ error: 'Role not found' });
    }

    if (existingRole.name === 'Super Admin') {
      return res.status(400).json({ error: 'Cannot modify Super Admin role configuration' });
    }

    const role = await prisma.role.update({
      where: { id },
      data: {
        name: name || existingRole.name,
        permissionSet: permissionSet ? (typeof permissionSet === 'string' ? permissionSet : JSON.stringify(permissionSet)) : existingRole.permissionSet
      }
    });

    const userEmail = req.user?.email || 'admin@pos.com';
    const userId = req.user?.id || 1;
    await prisma.auditLog.create({
      data: {
        userId,
        userEmail,
        action: 'UPDATE_ROLE',
        details: `Updated role configuration for role: '${existingRole.name}'`
      }
    });

    return res.json(role);
  } catch (error) {
    console.error('Update role error:', error);
    return res.status(500).json({ error: 'Failed to update role' });
  }
}

export async function getAuditLogs(req: AuthenticatedRequest, res: Response) {
  try {
    const logs = await prisma.auditLog.findMany({
      orderBy: { createdAt: 'desc' },
      take: 100
    });
    return res.json(logs);
  } catch (error) {
    console.error('Get audit logs error:', error);
    return res.status(500).json({ error: 'Failed to retrieve audit log entries' });
  }
}
