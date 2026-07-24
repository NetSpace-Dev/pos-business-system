import { Response } from 'express';
import prisma from '../utils/prisma';
import { AuthenticatedRequest } from '../middleware/auth';

export async function getItems(req: AuthenticatedRequest, res: Response) {
  try {
    const { search, category, lowStock, isActive } = req.query;

    const whereClause: any = {};

    if (isActive !== undefined) {
      whereClause.isActive = isActive === 'true';
    }

    if (category) {
      whereClause.category = String(category);
    }

    if (lowStock === 'true') {
      whereClause.isTracked = true;
      whereClause.stockQty = {
        lte: prisma.inventoryItem.fields.reorderLevel,
      };
      // Note: prisma.inventoryItem.fields.reorderLevel is not directly supported in where query like this in old prisma.
      // A better way is using native Prisma query, or raw queries, or doing stockQty filter dynamically.
      // Since reorderLevel is custom per item, we can fetch all and filter in memory, or use a where filter if Prisma supports it,
      // or simply filter in JS since inventory lists are usually reasonably sized.
      // Wait, let's look up if Prisma supports column comparisons. Standard Prisma does not support comparing two columns directly in `where` unless we use prisma.$queryRaw.
      // Let's implement dynamic low stock filtering: we can fetch items and filter in JS, OR since standard POS lists are a few thousand items, filtering in JS is safe, or we can use raw query.
      // Let's do in-memory filter if lowStock is requested, or use $queryRaw. Let's do in-memory filtering for safety of type casting, or retrieve it with query.
      // Wait, let's write the query to retrieve all and filter.
    }

    if (search) {
      const searchStr = String(search);
      whereClause.OR = [
        { sku: { contains: searchStr, mode: 'insensitive' } },
        { name: { contains: searchStr, mode: 'insensitive' } },
        { description: { contains: searchStr, mode: 'insensitive' } },
      ];
    }

    let items = await prisma.inventoryItem.findMany({
      where: whereClause,
      include: {
        dealer: {
          select: { name: true },
        },
      },
      orderBy: { name: 'asc' },
    });

    if (lowStock === 'true') {
      items = items.filter(item => item.isTracked && item.stockQty <= item.reorderLevel);
    }

    return res.json(items);
  } catch (error: any) {
    console.error('Get inventory items error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function getItemById(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid item ID' });
    }

    const item = await prisma.inventoryItem.findUnique({
      where: { id },
      include: {
        dealer: true,
        stockMovements: {
          orderBy: { createdAt: 'desc' },
          take: 15,
        },
      },
    });

    if (!item) {
      return res.status(404).json({ error: 'Item not found' });
    }

    return res.json(item);
  } catch (error: any) {
    console.error('Get item by ID error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function createItem(req: AuthenticatedRequest, res: Response) {
  try {
    const {
      sku,
      name,
      category,
      description,
      dealerId,
      stockQty,
      reorderLevel,
      unitCost,
      sellPrice,
      warrantyMonths,
      isTracked,
    } = req.body;

    if (!sku || !name || !category || unitCost === undefined || sellPrice === undefined) {
      return res.status(400).json({ error: 'SKU, name, category, unit cost, and sell price are required' });
    }

    const existingItem = await prisma.inventoryItem.findUnique({
      where: { sku },
    });

    if (existingItem) {
      return res.status(400).json({ error: 'An item with this SKU already exists' });
    }

    const initialQty = stockQty !== undefined ? parseInt(stockQty) : 0;

    // Use Prisma transaction to create item and initial stock movement if quantity > 0
    const newItem = await prisma.$transaction(async (tx) => {
      const createdItem = await tx.inventoryItem.create({
        data: {
          sku,
          name,
          category,
          description,
          dealerId: dealerId ? parseInt(dealerId) : null,
          stockQty: initialQty,
          reorderLevel: reorderLevel !== undefined ? parseInt(reorderLevel) : 5,
          unitCost: parseFloat(unitCost),
          sellPrice: parseFloat(sellPrice),
          warrantyMonths: warrantyMonths ? parseInt(warrantyMonths) : null,
          isTracked: isTracked !== undefined ? Boolean(isTracked) : true,
        },
      });

      if (initialQty > 0 && (isTracked !== false)) {
        await tx.stockMovement.create({
          data: {
            itemId: createdItem.id,
            type: 'in',
            quantity: initialQty,
            reason: 'Initial stock load upon creation',
          },
        });
      }

      return createdItem;
    });

    return res.status(201).json(newItem);
  } catch (error: any) {
    console.error('Create item error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function updateItem(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid item ID' });
    }

    const {
      sku,
      name,
      category,
      description,
      dealerId,
      reorderLevel,
      unitCost,
      sellPrice,
      warrantyMonths,
      isTracked,
      isActive,
    } = req.body;

    const existingItem = await prisma.inventoryItem.findUnique({
      where: { id },
    });

    if (!existingItem) {
      return res.status(404).json({ error: 'Item not found' });
    }

    if (sku && sku !== existingItem.sku) {
      const skuConflict = await prisma.inventoryItem.findUnique({
        where: { sku },
      });
      if (skuConflict) {
        return res.status(400).json({ error: 'Another item with this SKU already exists' });
      }
    }

    const updated = await prisma.inventoryItem.update({
      where: { id },
      data: {
        sku,
        name,
        category,
        description,
        dealerId: dealerId !== undefined ? (dealerId ? parseInt(dealerId) : null) : undefined,
        reorderLevel: reorderLevel !== undefined ? parseInt(reorderLevel) : undefined,
        unitCost: unitCost !== undefined ? parseFloat(unitCost) : undefined,
        sellPrice: sellPrice !== undefined ? parseFloat(sellPrice) : undefined,
        warrantyMonths: warrantyMonths !== undefined ? (warrantyMonths ? parseInt(warrantyMonths) : null) : undefined,
        isTracked: isTracked !== undefined ? Boolean(isTracked) : undefined,
        isActive: isActive !== undefined ? Boolean(isActive) : undefined,
      },
    });

    return res.json(updated);
  } catch (error: any) {
    console.error('Update item error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function adjustStock(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid item ID' });
    }

    const { type, quantity, reason } = req.body;

    if (!type || quantity === undefined) {
      return res.status(400).json({ error: 'Stock adjustment type and quantity are required' });
    }

    if (!['in', 'out', 'adjustment'].includes(type)) {
      return res.status(400).json({ error: 'Invalid type. Must be "in", "out" or "adjustment"' });
    }

    const qtyVal = parseInt(quantity);
    if (isNaN(qtyVal)) {
      return res.status(400).json({ error: 'Quantity must be a valid integer' });
    }

    const item = await prisma.inventoryItem.findUnique({
      where: { id },
    });

    if (!item) {
      return res.status(404).json({ error: 'Item not found' });
    }

    let qtyChange = 0;
    if (type === 'in') {
      qtyChange = qtyVal;
    } else if (type === 'out') {
      qtyChange = -qtyVal;
    } else if (type === 'adjustment') {
      qtyChange = qtyVal; // Expecting positive or negative change in adjustment
    }

    const updatedItem = await prisma.$transaction(async (tx) => {
      const updated = await tx.inventoryItem.update({
        where: { id },
        data: {
          stockQty: {
            increment: qtyChange,
          },
        },
      });

      await tx.stockMovement.create({
        data: {
          itemId: id,
          type,
          quantity: Math.abs(qtyChange),
          reason: reason || 'Manual stock adjustment',
        },
      });

      return updated;
    });

    return res.json(updatedItem);
  } catch (error: any) {
    console.error('Adjust stock error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

export async function deleteItem(req: AuthenticatedRequest, res: Response) {
  try {
    const id = parseInt(req.params.id);
    if (isNaN(id)) {
      return res.status(400).json({ error: 'Invalid item ID' });
    }

    const force = req.query.force === 'true';

    const item = await prisma.inventoryItem.findUnique({
      where: { id },
    });

    if (!item) {
      return res.status(404).json({ error: 'Item not found' });
    }

    if (force) {
      await prisma.inventoryItem.delete({
        where: { id },
      });
      return res.json({ message: 'Item deleted permanently' });
    } else {
      await prisma.inventoryItem.update({
        where: { id },
        data: { isActive: false },
      });
      return res.json({ message: 'Item deactivated successfully' });
    }
  } catch (error: any) {
    console.error('Delete item error:', error);
    if (error.code === 'P2003') {
      return res.status(400).json({
        error: 'Cannot delete item because it is referenced in quotations or invoices. Deactivate it instead.',
      });
    }
    return res.status(500).json({ error: 'Internal server error' });
  }
}
