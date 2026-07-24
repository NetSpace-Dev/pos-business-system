import { PrismaClient } from '@prisma/client';
import bcrypt from 'bcryptjs';

const prisma = new PrismaClient();

async function main() {
  console.log('Seeding database...');

  // 1. Clear database in dependency order
  await prisma.ticketUpdate.deleteMany({});
  await prisma.supportTicket.deleteMany({});
  await prisma.partnerPayout.deleteMany({});
  await prisma.paymentAllocation.deleteMany({});
  await prisma.payment.deleteMany({});
  await prisma.invoiceItem.deleteMany({});
  await prisma.invoice.deleteMany({});
  await prisma.recurringInvoice.deleteMany({});
  await prisma.quotationItem.deleteMany({});
  await prisma.quotation.deleteMany({});
  await prisma.stockMovement.deleteMany({});
  await prisma.inventoryItem.deleteMany({});
  await prisma.dealerTransaction.deleteMany({});
  await prisma.dealer.deleteMany({});
  await prisma.projectDeadline.deleteMany({});
  await prisma.client.deleteMany({});
  
  await prisma.partner.deleteMany({});
  await prisma.reinvestmentUsage.deleteMany({});
  await prisma.expense.deleteMany({});
  await prisma.systemSetting.deleteMany({});
  await prisma.settingHistory.deleteMany({});
  await prisma.auditLog.deleteMany({});
  await prisma.user.deleteMany({});
  await prisma.role.deleteMany({});

  console.log('Existing data cleared.');

  // 2. Seed System Settings
  const defaultSettings = [
    { key: 'reinvestment_percentage', value: '50' },
    { key: 'tax_percentage', value: '15' },
    { key: 'invoice_prefix', value: 'INV' },
    { key: 'default_payment_terms', value: 'Net 14' },
    { key: 'default_reorder_level', value: '5' }
  ];

  for (const s of defaultSettings) {
    await prisma.systemSetting.create({
      data: {
        key: s.key,
        value: s.value,
        updatedBy: 'system@pos.com'
      }
    });
  }
  console.log('Default settings seeded.');

  // 3. Seed Custom Roles & Permissions
  const superAdminPermissions = {
    clients: { view: true, create: true, edit: true, delete: true },
    invoices: { view: true, create: true, edit: true, delete: true },
    inventory: { view: true, create: true, edit: true, delete: true },
    dealers: { view: true, create: true, edit: true },
    tickets: { view: true, create: true, edit: true },
    finance: { allocations: true, settings: true, partners: true },
    reports: { view: true, export: true },
    users: { manage: true },
    roles: { manage: true }
  };

  const staffPermissions = {
    clients: { view: true, create: true, edit: true, delete: false },
    invoices: { view: true, create: true, edit: true, delete: false },
    inventory: { view: true, create: true, edit: true, delete: false },
    dealers: { view: true, create: false, edit: false },
    tickets: { view: true, create: true, edit: true },
    finance: { allocations: false, settings: false, partners: false },
    reports: { view: true, export: false },
    users: { manage: false },
    roles: { manage: false }
  };

  const financePermissions = {
    clients: { view: true, create: false, edit: false, delete: false },
    invoices: { view: true, create: true, edit: false, delete: false },
    inventory: { view: true, create: false, edit: false, delete: false },
    dealers: { view: true, create: false, edit: false },
    tickets: { view: true, create: false, edit: false },
    finance: { allocations: true, settings: true, partners: true },
    reports: { view: true, export: true },
    users: { manage: false },
    roles: { manage: false }
  };

  const adminRole = await prisma.role.create({
    data: {
      name: 'Super Admin',
      permissionSet: JSON.stringify(superAdminPermissions)
    }
  });

  const staffRole = await prisma.role.create({
    data: {
      name: 'Office Staff',
      permissionSet: JSON.stringify(staffPermissions)
    }
  });

  const financeRole = await prisma.role.create({
    data: {
      name: 'Finance Officer',
      permissionSet: JSON.stringify(financePermissions)
    }
  });
  console.log('Default permission roles seeded.');

  // 4. Create Users linked to Roles
  const passwordHash = await bcrypt.hash('Admin@123', 10);
  const admin = await prisma.user.create({
    data: {
      name: 'System Admin',
      email: 'admin@pos.com',
      passwordHash,
      role: 'admin', // backup field
      roleId: adminRole.id,
      status: 'active'
    },
  });

  const staffHash = await bcrypt.hash('Staff@123', 10);
  const staff = await prisma.user.create({
    data: {
      name: 'Sales Assistant',
      email: 'staff@pos.com',
      passwordHash: staffHash,
      role: 'staff', // backup field
      roleId: staffRole.id,
      status: 'active'
    },
  });

  console.log('Users seeded successfully:');
  console.log(`- Admin: ${admin.email} (Password: Admin@123)`);
  console.log(`- Staff: ${staff.email} (Password: Staff@123)`);

  // 5. Seed Partners
  await prisma.partner.create({
    data: { name: 'Partner A', ownershipPct: 60.00, status: 'active' }
  });
  await prisma.partner.create({
    data: { name: 'Partner B', ownershipPct: 40.00, status: 'active' }
  });
  console.log('Default partners seeded (60%/40%).');

  // 6. Create Dealers
  const dealer1 = await prisma.dealer.create({
    data: {
      name: 'Abans IT Distribution',
      contactPerson: 'Mr. Perera',
      phone: '+94771234567',
      email: 'perera@abansit.lk',
      address: 'No 45, Galle Road, Colombo 03',
      paymentType: 'credit',
      balanceDue: 25000.00,
    },
  });

  const dealer2 = await prisma.dealer.create({
    data: {
      name: 'Singer Sri Lanka PLC',
      contactPerson: 'Mrs. Jayawardene',
      phone: '+94112300400',
      email: 'corporate@singer.lk',
      address: 'No 112, Havelock Road, Colombo 05',
      paymentType: 'cash',
      balanceDue: 0.00,
    },
  });

  console.log('Dealers seeded successfully.');

  // 7. Create Clients
  const client1 = await prisma.client.create({
    data: {
      companyName: 'Kandyanpo Enterprises',
      contactPerson: 'Kamal Bandara',
      phone: '+94711112222',
      email: 'kamal@kandyanpo.lk',
      address: '12 Main Street, Kandy',
      notes: 'Key client. Prefers bank transfer.',
    },
  });

  const client2 = await prisma.client.create({
    data: {
      companyName: 'Greenfield Tea Exporters',
      contactPerson: 'Sarah Fernando',
      phone: '+94723334444',
      email: 'logistics@greenfieldtea.com',
      address: '45/A, Nuwara Eliya Road, Hatton',
      notes: 'Requires detailed invoice copies.',
    },
  });

  console.log('Clients seeded successfully.');

  // 8. Create Inventory Items
  const item1 = await prisma.inventoryItem.create({
    data: {
      sku: 'PRN-THR-001',
      name: 'POS 80mm Thermal Receipt Printer',
      category: 'hardware',
      description: 'High-speed desktop thermal printer with auto-cutter.',
      dealerId: dealer1.id,
      stockQty: 8,
      reorderLevel: 3,
      unitCost: 4500.00,
      sellPrice: 7500.00,
      warrantyMonths: 12,
      isTracked: true,
    },
  });

  const item2 = await prisma.inventoryItem.create({
    data: {
      sku: 'SCN-BAR-USB',
      name: 'Handheld Barcode Scanner USB',
      category: 'hardware',
      description: '1D/2D wired barcode reader with stand.',
      dealerId: dealer1.id,
      stockQty: 15,
      reorderLevel: 5,
      unitCost: 2000.00,
      sellPrice: 3800.00,
      warrantyMonths: 6,
      isTracked: true,
    },
  });

  const item3 = await prisma.inventoryItem.create({
    data: {
      sku: 'LIC-POS-ENT',
      name: 'POS Business Suite Enterprise License',
      category: 'software',
      description: '1-Year license subscription for POS client terminal.',
      dealerId: dealer2.id,
      stockQty: 0,
      reorderLevel: 0,
      unitCost: 12000.00,
      sellPrice: 24000.00,
      warrantyMonths: 0,
      isTracked: false,
    },
  });

  console.log('Inventory Items seeded successfully.');

  // 9. Create Initial Stock Movements for Hardware Items
  await prisma.stockMovement.create({
    data: {
      itemId: item1.id,
      type: 'in',
      quantity: 8,
      reason: 'Initial database seed stock load',
    },
  });

  await prisma.stockMovement.create({
    data: {
      itemId: item2.id,
      type: 'in',
      quantity: 15,
      reason: 'Initial database seed stock load',
    },
  });

  // 10. Seed one Support Ticket
  const ticket = await prisma.supportTicket.create({
    data: {
      ticketNumber: 'TK-2026-0001',
      clientId: client1.id,
      problemDesc: 'Receipt printer printing blank pages after driver update.',
      priority: 'high',
      status: 'open',
    },
  });

  await prisma.ticketUpdate.create({
    data: {
      ticketId: ticket.id,
      note: 'Ticket logged. Assigned to support queue.',
      statusChange: 'open',
    },
  });

  console.log('Support tickets seeded.');
  console.log('Seed completed successfully!');
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
