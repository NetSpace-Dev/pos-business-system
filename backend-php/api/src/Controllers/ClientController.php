<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use App\Utils\TextLk;
use PDO;

class ClientController {
    public function getClients(array $req) {
        $query = $req['query'];
        $search = $query['search'] ?? '';
        $isActive = $query['isActive'] ?? null;

        $db = Database::getConnection();
        $sql = "SELECT * FROM `clients` WHERE 1=1";
        $params = [];

        if ($isActive !== null) {
            $sql .= " AND `isActive` = :isActive";
            $params['isActive'] = ($isActive === 'true') ? 1 : 0;
        }

        if ($search) {
            $sql .= " AND (`companyName` LIKE :search 
                        OR `contactPerson` LIKE :search 
                        OR `phone` LIKE :search 
                        OR `email` LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY `createdAt` DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $clients = $stmt->fetchAll();

        // Convert isActive to boolean in response
        foreach ($clients as &$c) {
            $c['isActive'] = (bool)$c['isActive'];
            $c['id'] = (int)$c['id'];
        }

        return Response::json($clients);
    }

    public function getClientById(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid client ID', 400);
        }

        $db = Database::getConnection();
        
        // Fetch Client
        $stmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $client = $stmt->fetch();

        if (!$client) {
            return Response::error('Client not found', 404);
        }

        $client['isActive'] = (bool)$client['isActive'];
        $client['id'] = (int)$client['id'];

        // Fetch Quotations (limit 5)
        $qStmt = $db->prepare("SELECT * FROM `quotations` WHERE `clientId` = :clientId ORDER BY `createdAt` DESC LIMIT 5");
        $qStmt->execute(['clientId' => $id]);
        $quotations = $qStmt->fetchAll();
        foreach ($quotations as &$q) {
            $q['id'] = (int)$q['id'];
            $q['clientId'] = (int)$q['clientId'];
            $q['version'] = (int)$q['version'];
        }

        // Fetch Invoices (limit 5)
        $iStmt = $db->prepare("SELECT * FROM `invoices` WHERE `clientId` = :clientId ORDER BY `createdAt` DESC LIMIT 5");
        $iStmt->execute(['clientId' => $id]);
        $invoices = $iStmt->fetchAll();
        foreach ($invoices as &$inv) {
            $inv['id'] = (int)$inv['id'];
            $inv['clientId'] = (int)$inv['clientId'];
            $inv['isRecurringGenerated'] = (bool)$inv['isRecurringGenerated'];
        }

        // Fetch Support Tickets (limit 5)
        $tStmt = $db->prepare("SELECT * FROM `support_tickets` WHERE `clientId` = :clientId ORDER BY `createdAt` DESC LIMIT 5");
        $tStmt->execute(['clientId' => $id]);
        $tickets = $tStmt->fetchAll();
        foreach ($tickets as &$t) {
            $t['id'] = (int)$t['id'];
            $t['clientId'] = (int)$t['clientId'];
        }

        // Fetch Active Recurring Invoices
        $rStmt = $db->prepare("SELECT * FROM `recurring_invoices` WHERE `clientId` = :clientId AND `isActive` = 1");
        $rStmt->execute(['clientId' => $id]);
        $recurringInvoices = $rStmt->fetchAll();
        foreach ($recurringInvoices as &$ri) {
            $ri['id'] = (int)$ri['id'];
            $ri['clientId'] = (int)$ri['clientId'];
            $ri['isActive'] = (bool)$ri['isActive'];
        }

        $client['quotations'] = $quotations;
        $client['invoices'] = $invoices;
        $client['supportTickets'] = $tickets;
        $client['recurringInvoices'] = $recurringInvoices;

        return Response::json($client);
    }

    public function createClient(array $req) {
        $body = $req['body'];
        $companyName = $body['companyName'] ?? '';
        $contactPerson = $body['contactPerson'] ?? '';
        $phone = $body['phone'] ?? '';
        $email = $body['email'] ?? null;
        $address = $body['address'] ?? null;
        $notes = $body['notes'] ?? null;

        if (!$companyName || !$contactPerson || !$phone) {
            return Response::error('Company name, contact person, and phone are required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO `clients` (`companyName`, `contactPerson`, `phone`, `email`, `address`, `notes`, `isActive`, `createdAt`, `updatedAt`)
            VALUES (:companyName, :contactPerson, :phone, :email, :address, :notes, 1, NOW(), NOW())
        ");
        $stmt->execute([
            'companyName' => $companyName,
            'contactPerson' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'notes' => $notes
        ]);

        $clientId = (int)$db->lastInsertId();

        // Sync to Text.lk Contacts API
        $textLkUid = null;
        try {
            $textLkUid = TextLk::syncContactCreate($phone, $contactPerson, $companyName);
            if ($textLkUid) {
                $uStmt = $db->prepare("UPDATE `clients` SET `textLkUid` = :uid WHERE `id` = :id");
                $uStmt->execute(['uid' => $textLkUid, 'id' => $clientId]);
            }
        } catch (\Exception $apiError) {
            error_log('[Text.lk] Failed to synchronize newly created client: ' . $apiError->getMessage());
        }

        // Send Welcome SMS
        try {
            $sStmt = $db->prepare("SELECT `value` FROM `system_settings` WHERE `key` = 'text_lk_welcome_message' LIMIT 1");
            $sStmt->execute();
            $welcomeTemplate = $sStmt->fetchColumn();

            if ($welcomeTemplate) {
                $message = str_replace(
                    ['{contactPerson}', '{companyName}'],
                    [$contactPerson, $companyName],
                    $welcomeTemplate
                );
                TextLk::sendSMS($phone, $message);
            }
        } catch (\Exception $smsError) {
            error_log('[Text.lk] Failed to send welcome SMS: ' . $smsError->getMessage());
        }

        // Return newly created client
        $stmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $clientId]);
        $client = $stmt->fetch();
        $client['isActive'] = (bool)$client['isActive'];
        $client['id'] = (int)$client['id'];

        return Response::json($client, 201);
    }

    public function updateClient(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid client ID', 400);
        }

        $body = $req['body'];
        $companyName = $body['companyName'] ?? null;
        $contactPerson = $body['contactPerson'] ?? null;
        $phone = $body['phone'] ?? null;
        $email = $body['email'] ?? null;
        $address = $body['address'] ?? null;
        $notes = $body['notes'] ?? null;
        $isActive = isset($body['isActive']) ? (bool)$body['isActive'] : null;

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return Response::error('Client not found', 404);
        }

        // Prepare fields
        $companyName = ($companyName !== null) ? $companyName : $existing['companyName'];
        $contactPerson = ($contactPerson !== null) ? $contactPerson : $existing['contactPerson'];
        $phone = ($phone !== null) ? $phone : $existing['phone'];
        $email = ($email !== null) ? $email : $existing['email'];
        $address = ($address !== null) ? $address : $existing['address'];
        $notes = ($notes !== null) ? $notes : $existing['notes'];
        $isActiveValue = ($isActive !== null) ? ($isActive ? 1 : 0) : $existing['isActive'];

        $uStmt = $db->prepare("
            UPDATE `clients` 
            SET `companyName` = :companyName, `contactPerson` = :contactPerson, `phone` = :phone, 
                `email` = :email, `address` = :address, `notes` = :notes, `isActive` = :isActive, `updatedAt` = NOW()
            WHERE `id` = :id
        ");
        $uStmt->execute([
            'companyName' => $companyName,
            'contactPerson' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'notes' => $notes,
            'isActive' => $isActiveValue,
            'id' => $id
        ]);

        // Sync updates to Text.lk Contacts API
        try {
            $hasChanged = 
                $existing['phone'] !== $phone ||
                $existing['contactPerson'] !== $contactPerson ||
                $existing['companyName'] !== $companyName;

            if ($existing['textLkUid']) {
                if ($hasChanged) {
                    TextLk::syncContactUpdate($existing['textLkUid'], $phone, $contactPerson, $companyName);
                }
            } else {
                $newUid = TextLk::syncContactCreate($phone, $contactPerson, $companyName);
                if ($newUid) {
                    $uUidStmt = $db->prepare("UPDATE `clients` SET `textLkUid` = :uid WHERE `id` = :id");
                    $uUidStmt->execute(['uid' => $newUid, 'id' => $id]);
                }
            }
        } catch (\Exception $apiError) {
            error_log('[Text.lk] Failed to synchronize client update: ' . $apiError->getMessage());
        }

        // Fetch and return updated client
        $stmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $client = $stmt->fetch();
        $client['isActive'] = (bool)$client['isActive'];
        $client['id'] = (int)$client['id'];

        return Response::json($client);
    }

    public function deleteClient(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid client ID', 400);
        }

        $force = ($req['query']['force'] ?? '') === 'true';
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $client = $stmt->fetch();

        if (!$client) {
            return Response::error('Client not found', 404);
        }

        if ($force) {
            try {
                $delStmt = $db->prepare("DELETE FROM `clients` WHERE `id` = :id");
                $delStmt->execute(['id' => $id]);

                // Sync Delete with Text.lk
                if ($client['textLkUid']) {
                    try {
                        TextLk::syncContactDelete($client['textLkUid']);
                    } catch (\Exception $apiError) {
                        error_log('[Text.lk] Failed to delete synced contact: ' . $apiError->getMessage());
                    }
                }

                return Response::json(['message' => 'Client hard deleted successfully']);
            } catch (\PDOException $e) {
                // Check for foreign key constraint violation (Error Code 1451)
                if ($e->getCode() == '23000' || strpos($e->getMessage(), '1451') !== false) {
                    return Response::error('Cannot delete client with active invoices, quotations, or tickets. Use soft delete or deactivate instead.', 400);
                }
                throw $e;
            }
        } else {
            // Soft delete
            $uStmt = $db->prepare("UPDATE `clients` SET `isActive` = 0 WHERE `id` = :id");
            $uStmt->execute(['id' => $id]);
            return Response::json(['message' => 'Client deactivated successfully']);
        }
    }
}
