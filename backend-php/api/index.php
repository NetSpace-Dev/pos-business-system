<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\AuthMiddleware;
use App\Utils\Response;

// Setup custom autoloader for App namespace classes
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

$router = new Router();

// Apply global CORS middleware
$router->use(new CorsMiddleware());

// Instantiate Controllers
$authController = new \App\Controllers\AuthController();
$clientController = new \App\Controllers\ClientController();
$inventoryController = new \App\Controllers\InventoryController();
$dealerController = new \App\Controllers\DealerController();
$quotationController = new \App\Controllers\QuotationController();
$invoiceController = new \App\Controllers\InvoiceController();
$recurringInvoiceController = new \App\Controllers\RecurringInvoiceController();
$ticketController = new \App\Controllers\TicketController();
$reportController = new \App\Controllers\ReportController();
$deadlineController = new \App\Controllers\DeadlineController();
$settingsController = new \App\Controllers\SettingsController();
$userController = new \App\Controllers\UserController();
$financeController = new \App\Controllers\FinanceController();
$smsTemplateController = new \App\Controllers\SmsTemplateController();

// ----------------------------------------------------
// ROUTES CONFIGURATION
// ----------------------------------------------------

// API Health Check
$router->get('/api/health', [], function($req) {
    return Response::json(['status' => 'ok', 'message' => 'POS Business backend running']);
});

// Authentication
$router->post('/api/auth/login', [], [$authController, 'login']);
$router->post('/api/auth/register', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::class . '::requireAdmin'], [$authController, 'register']);
$router->get('/api/auth/me', [AuthMiddleware::class . '::authenticateJWT'], [$authController, 'me']);

// Clients Management
$router->get('/api/clients', [AuthMiddleware::class . '::authenticateJWT'], [$clientController, 'getClients']);
$router->get('/api/clients/:id', [AuthMiddleware::class . '::authenticateJWT'], [$clientController, 'getClientById']);
$router->post('/api/clients', [AuthMiddleware::class . '::authenticateJWT'], [$clientController, 'createClient']);
$router->put('/api/clients/:id', [AuthMiddleware::class . '::authenticateJWT'], [$clientController, 'updateClient']);
$router->delete('/api/clients/:id', [AuthMiddleware::class . '::authenticateJWT'], [$clientController, 'deleteClient']);

// Inventory Items
$router->get('/api/inventory', [AuthMiddleware::class . '::authenticateJWT'], [$inventoryController, 'getItems']);
$router->get('/api/inventory/:id', [AuthMiddleware::class . '::authenticateJWT'], [$inventoryController, 'getItemById']);
$router->post('/api/inventory', [AuthMiddleware::class . '::authenticateJWT'], [$inventoryController, 'createItem']);
$router->put('/api/inventory/:id', [AuthMiddleware::class . '::authenticateJWT'], [$inventoryController, 'updateItem']);
$router->post('/api/inventory/:id/stock', [AuthMiddleware::class . '::authenticateJWT'], [$inventoryController, 'adjustStock']);
$router->delete('/api/inventory/:id', [AuthMiddleware::class . '::authenticateJWT'], [$inventoryController, 'deleteItem']);

// Dealers Management
$router->get('/api/dealers', [AuthMiddleware::class . '::authenticateJWT'], [$dealerController, 'getDealers']);
$router->get('/api/dealers/:id', [AuthMiddleware::class . '::authenticateJWT'], [$dealerController, 'getDealerById']);
$router->post('/api/dealers', [AuthMiddleware::class . '::authenticateJWT'], [$dealerController, 'createDealer']);
$router->put('/api/dealers/:id', [AuthMiddleware::class . '::authenticateJWT'], [$dealerController, 'updateDealer']);
$router->post('/api/dealers/:id/transactions', [AuthMiddleware::class . '::authenticateJWT'], [$dealerController, 'addTransaction']);
$router->delete('/api/dealers/:id', [AuthMiddleware::class . '::authenticateJWT'], [$dealerController, 'deleteDealer']);

// Quotations Management
$router->get('/api/quotations', [AuthMiddleware::class . '::authenticateJWT'], [$quotationController, 'getQuotations']);
$router->get('/api/quotations/:id', [AuthMiddleware::class . '::authenticateJWT'], [$quotationController, 'getQuotationById']);
$router->post('/api/quotations', [AuthMiddleware::class . '::authenticateJWT'], [$quotationController, 'createQuotation']);
$router->put('/api/quotations/:id', [AuthMiddleware::class . '::authenticateJWT'], [$quotationController, 'updateQuotation']);
$router->post('/api/quotations/:id/convert', [AuthMiddleware::class . '::authenticateJWT'], [$quotationController, 'convertToInvoice']);
$router->delete('/api/quotations/:id', [AuthMiddleware::class . '::authenticateJWT'], [$quotationController, 'deleteQuotation']);

// Invoices Management
$router->get('/api/invoices', [AuthMiddleware::class . '::authenticateJWT'], [$invoiceController, 'getInvoices']);
$router->get('/api/invoices/:id', [AuthMiddleware::class . '::authenticateJWT'], [$invoiceController, 'getInvoiceById']);
$router->post('/api/invoices', [AuthMiddleware::class . '::authenticateJWT'], [$invoiceController, 'createInvoice']);
$router->put('/api/invoices/:id', [AuthMiddleware::class . '::authenticateJWT'], [$invoiceController, 'updateInvoice']);
$router->post('/api/invoices/:id/payments', [AuthMiddleware::class . '::authenticateJWT'], [$invoiceController, 'recordPayment']);
$router->post('/api/invoices/:id/remind', [AuthMiddleware::class . '::authenticateJWT'], [$invoiceController, 'sendInvoiceReminder']);
$router->delete('/api/invoices/:id', [AuthMiddleware::class . '::authenticateJWT'], [$invoiceController, 'deleteInvoice']);

// Recurring Invoices Config
$router->get('/api/recurring-invoices', [AuthMiddleware::class . '::authenticateJWT'], [$recurringInvoiceController, 'getRecurringConfigs']);
$router->get('/api/recurring-invoices/:id', [AuthMiddleware::class . '::authenticateJWT'], [$recurringInvoiceController, 'getRecurringConfigById']);
$router->post('/api/recurring-invoices', [AuthMiddleware::class . '::authenticateJWT'], [$recurringInvoiceController, 'createRecurringConfig']);
$router->put('/api/recurring-invoices/:id', [AuthMiddleware::class . '::authenticateJWT'], [$recurringInvoiceController, 'updateRecurringConfig']);
$router->delete('/api/recurring-invoices/:id', [AuthMiddleware::class . '::authenticateJWT'], [$recurringInvoiceController, 'deleteRecurringConfig']);

// Support Tickets
$router->get('/api/tickets', [AuthMiddleware::class . '::authenticateJWT'], [$ticketController, 'getTickets']);
$router->get('/api/tickets/:id', [AuthMiddleware::class . '::authenticateJWT'], [$ticketController, 'getTicketById']);
$router->post('/api/tickets', [AuthMiddleware::class . '::authenticateJWT'], [$ticketController, 'createTicket']);
$router->post('/api/tickets/:id/updates', [AuthMiddleware::class . '::authenticateJWT'], [$ticketController, 'addTicketUpdate']);
$router->delete('/api/tickets/:id', [AuthMiddleware::class . '::authenticateJWT'], [$ticketController, 'deleteTicket']);

// Reports Module
$router->get('/api/reports/dashboard', [AuthMiddleware::class . '::authenticateJWT'], [$reportController, 'getDashboardSummary']);
$router->get('/api/reports/profit-loss', [AuthMiddleware::class . '::authenticateJWT'], [$reportController, 'getProfitLoss']);
$router->get('/api/reports/invoice-aging', [AuthMiddleware::class . '::authenticateJWT'], [$reportController, 'getInvoiceAging']);
$router->get('/api/reports/stock-valuation', [AuthMiddleware::class . '::authenticateJWT'], [$reportController, 'getStockValuation']);
$router->get('/api/reports/cash-flow', [AuthMiddleware::class . '::authenticateJWT'], [$reportController, 'getCashFlow']);
$router->get('/api/reports/quotation-conversion', [AuthMiddleware::class . '::authenticateJWT'], [$reportController, 'getQuotationConversion']);
$router->get('/api/reports/dealer-dues', [AuthMiddleware::class . '::authenticateJWT'], [$reportController, 'getDealerDues']);

// Project Timelines / Deadlines
$router->get('/api/deadlines', [AuthMiddleware::class . '::authenticateJWT'], [$deadlineController, 'getDeadlines']);
$router->post('/api/deadlines', [AuthMiddleware::class . '::authenticateJWT'], [$deadlineController, 'createDeadline']);
$router->put('/api/deadlines/:id', [AuthMiddleware::class . '::authenticateJWT'], [$deadlineController, 'updateDeadline']);
$router->delete('/api/deadlines/:id', [AuthMiddleware::class . '::authenticateJWT'], [$deadlineController, 'deleteDeadline']);

// Settings Configuration
$router->get('/api/settings', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'settings')], [$settingsController, 'getSettings']);
$router->post('/api/settings', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'settings')], [$settingsController, 'updateSettings']);
$router->get('/api/settings/history', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'settings')], [$settingsController, 'getSettingsHistory']);

// Users Configuration
$router->get('/api/users', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('users', 'manage')], [$userController, 'getUsers']);
$router->post('/api/users/:id/status', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('users', 'manage')], [$userController, 'updateUserStatus']);
$router->put('/api/users/:id/role', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('users', 'manage')], [$userController, 'updateUserRole']);
$router->get('/api/users/audit-logs', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('users', 'manage')], [$userController, 'getAuditLogs']);

// Custom Roles Configuration
$router->get('/api/users/roles', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('roles', 'manage')], [$userController, 'getRoles']);
$router->post('/api/users/roles', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('roles', 'manage')], [$userController, 'createRole']);
$router->put('/api/users/roles/:id', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('roles', 'manage')], [$userController, 'updateRole']);

// Finance allocations, reinvestments & payouts
$router->get('/api/finance/partners', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'partners')], [$financeController, 'getPartners']);
$router->post('/api/finance/partners', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'partners')], [$financeController, 'createPartner']);
$router->put('/api/finance/partners/:id', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'partners')], [$financeController, 'updatePartner']);

$router->get('/api/finance/allocations', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'allocations')], [$financeController, 'getAllocations']);

$router->get('/api/finance/payouts', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'allocations')], [$financeController, 'getPayouts']);
$router->post('/api/finance/payouts/:id/pay', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'allocations')], [$financeController, 'markPayoutPaid']);

$router->get('/api/finance/reinvestment', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'allocations')], [$financeController, 'getReinvestmentStatement']);
$router->post('/api/finance/reinvestment/use', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'allocations')], [$financeController, 'logReinvestmentUsage']);

$router->get('/api/finance/expenses', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'allocations')], [$financeController, 'getExpenses']);
$router->post('/api/finance/expenses', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'allocations')], [$financeController, 'createExpense']);
$router->put('/api/finance/expenses/:id', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'allocations')], [$financeController, 'updateExpense']);
$router->delete('/api/finance/expenses/:id', [AuthMiddleware::class . '::authenticateJWT', AuthMiddleware::checkPermission('finance', 'allocations')], [$financeController, 'deleteExpense']);

// SMS Templates Management
$router->get('/api/sms-templates', [AuthMiddleware::class . '::authenticateJWT'], [$smsTemplateController, 'getSmsTemplates']);
$router->get('/api/sms-templates/:id', [AuthMiddleware::class . '::authenticateJWT'], [$smsTemplateController, 'getSmsTemplateById']);
$router->post('/api/sms-templates', [AuthMiddleware::class . '::authenticateJWT'], [$smsTemplateController, 'createSmsTemplate']);
$router->put('/api/sms-templates/:id', [AuthMiddleware::class . '::authenticateJWT'], [$smsTemplateController, 'updateSmsTemplate']);
$router->delete('/api/sms-templates/:id', [AuthMiddleware::class . '::authenticateJWT'], [$smsTemplateController, 'deleteSmsTemplate']);

// Execute Request Routing
$router->handle($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
