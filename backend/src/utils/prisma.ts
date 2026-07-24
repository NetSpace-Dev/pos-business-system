import { PrismaClient } from '@prisma/client';

// Single shared Prisma instance across the app (avoids exhausting DB connections in dev)
const prisma = new PrismaClient();

export default prisma;
