import { Request, Response, NextFunction } from 'express';
import jwt from 'jsonwebtoken';
import prisma from '../utils/prisma';

export interface AuthenticatedRequest extends Request {
  user?: {
    id: number;
    email: string;
    role: string;
  };
}

export function authenticateJWT(req: AuthenticatedRequest, res: Response, next: NextFunction) {
  const authHeader = req.headers.authorization;

  if (authHeader) {
    const token = authHeader.split(' ')[1]; // Bearer <token>
    const secret = process.env.JWT_SECRET || 'fallback-secret';

    jwt.verify(token, secret, async (err, decoded: any) => {
      if (err) {
        return res.status(403).json({ error: 'Forbidden: Invalid or expired token' });
      }
      
      try {
        const dbUser = await prisma.user.findUnique({
          where: { id: decoded.id }
        });

        if (!dbUser) {
          return res.status(404).json({ error: 'User not found' });
        }

        if (dbUser.status === 'suspended') {
          return res.status(403).json({ error: 'Forbidden: User account is suspended' });
        }

        req.user = decoded;
        next();
      } catch (dbErr) {
        return res.status(500).json({ error: 'Internal server error during authentication' });
      }
    });
  } else {
    res.status(401).json({ error: 'Unauthorized: No token provided' });
  }
}

export function requireAdmin(req: AuthenticatedRequest, res: Response, next: NextFunction) {
  if (req.user && req.user.role === 'admin') {
    next();
  } else {
    res.status(403).json({ error: 'Forbidden: Admin access required' });
  }
}

export function checkPermission(module: string, action: string) {
  return async (req: AuthenticatedRequest, res: Response, next: NextFunction) => {
    try {
      if (!req.user) {
        return res.status(401).json({ error: 'Unauthorized' });
      }

      const dbUser = await prisma.user.findUnique({
        where: { id: req.user.id },
        include: { roleRef: true }
      });

      if (!dbUser) {
        return res.status(404).json({ error: 'User not found' });
      }

      if (dbUser.status === 'suspended') {
        return res.status(403).json({ error: 'Forbidden: User account is suspended' });
      }

      // Backward compatibility or Super Admin fallback bypass
      if (dbUser.role === 'admin' || (dbUser.roleRef && dbUser.roleRef.name === 'Super Admin')) {
        return next();
      }

      if (!dbUser.roleRef) {
        return res.status(403).json({ error: 'Forbidden: No role assigned' });
      }

      const permissions = JSON.parse(dbUser.roleRef.permissionSet);
      if (permissions[module] && permissions[module][action] === true) {
        return next();
      }

      return res.status(403).json({ error: `Forbidden: Insufficient permissions for ${module}:${action}` });
    } catch (err) {
      console.error('Permission check error:', err);
      return res.status(500).json({ error: 'Internal server error checking permissions' });
    }
  };
}
