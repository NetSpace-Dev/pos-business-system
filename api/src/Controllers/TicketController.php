<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use App\Utils\DocNumber;
use PDO;

class TicketController {
    public function getTickets(array $req) {
        $query = $req['query'];
        $status = $query['status'] ?? '';
        $priority = $query['priority'] ?? '';
        $clientId = $query['clientId'] ?? '';

        $db = Database::getConnection();
        $sql = "
            SELECT t.*, c.companyName, c.contactPerson 
            FROM `support_tickets` t 
            JOIN `clients` c ON t.clientId = c.id 
            WHERE 1=1
        ";
        $params = [];

        if ($status) {
            $sql .= " AND t.status = :status";
            $params['status'] = $status;
        }

        if ($priority) {
            $sql .= " AND t.priority = :priority";
            $params['priority'] = $priority;
        }

        if ($clientId) {
            $sql .= " AND t.clientId = :clientId";
            $params['clientId'] = (int)$clientId;
        }

        $sql .= " ORDER BY t.createdAt DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();

        foreach ($tickets as &$t) {
            $t['id'] = (int)$t['id'];
            $t['clientId'] = (int)$t['clientId'];
            $t['client'] = [
                'companyName' => $t['companyName'],
                'contactPerson' => $t['contactPerson']
            ];
            unset($t['companyName'], $t['contactPerson']);
        }

        return Response::json($tickets);
    }

    public function getTicketById(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid ticket ID', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `support_tickets` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            return Response::error('Ticket not found', 404);
        }

        $ticket['id'] = (int)$ticket['id'];
        $ticket['clientId'] = (int)$ticket['clientId'];

        // Fetch client
        $cStmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :clientId LIMIT 1");
        $cStmt->execute(['clientId' => $ticket['clientId']]);
        $ticket['client'] = $cStmt->fetch() ?: null;
        if ($ticket['client']) {
            $ticket['client']['id'] = (int)$ticket['client']['id'];
            $ticket['client']['isActive'] = (bool)$ticket['client']['isActive'];
        }

        // Fetch updates
        $uStmt = $db->prepare("SELECT * FROM `ticket_updates` WHERE `ticketId` = :ticketId ORDER BY `createdAt` ASC");
        $uStmt->execute(['ticketId' => $id]);
        $updates = $uStmt->fetchAll();
        foreach ($updates as &$u) {
            $u['id'] = (int)$u['id'];
            $u['ticketId'] = (int)$u['ticketId'];
        }
        $ticket['updates'] = $updates;

        return Response::json($ticket);
    }

    public function createTicket(array $req) {
        $body = $req['body'];
        $clientId = isset($body['clientId']) ? (int)$body['clientId'] : 0;
        $problemDesc = $body['problemDesc'] ?? '';
        $priority = $body['priority'] ?? 'medium';

        if ($clientId <= 0 || !$problemDesc) {
            return Response::error('Client ID and problem description are required', 400);
        }

        $db = Database::getConnection();
        
        // Client check
        $cStmt = $db->prepare("SELECT `id` FROM `clients` WHERE `id` = :id LIMIT 1");
        $cStmt->execute(['id' => $clientId]);
        if (!$cStmt->fetch()) {
            return Response::error('Client not found', 404);
        }

        $currentYear = date('Y');
        $yearStart = "$currentYear-01-01 00:00:00";
        $yearEnd = "$currentYear-12-31 23:59:59";

        try {
            $db->beginTransaction();

            // Count tickets in current year
            $countStmt = $db->prepare("SELECT COUNT(*) FROM `support_tickets` WHERE `createdAt` >= :yearStart AND `createdAt` <= :yearEnd");
            $countStmt->execute(['yearStart' => $yearStart, 'yearEnd' => $yearEnd]);
            $count = (int)$countStmt->fetchColumn();

            $ticketNumber = DocNumber::generate('TK', $count + 1);

            $insStmt = $db->prepare("
                INSERT INTO `support_tickets` (`ticketNumber`, `clientId`, `problemDesc`, `priority`, `status`, `createdAt`, `updatedAt`)
                VALUES (:ticketNumber, :clientId, :problemDesc, :priority, 'open', NOW(), NOW())
            ");
            $insStmt->execute([
                'ticketNumber' => $ticketNumber,
                'clientId' => $clientId,
                'problemDesc' => $problemDesc,
                'priority' => $priority
            ]);

            $newId = (int)$db->lastInsertId();

            // Insert initial update note
            $upStmt = $db->prepare("
                INSERT INTO `ticket_updates` (`ticketId`, `note`, `statusChange`, `createdAt`)
                VALUES (:ticketId, 'Ticket logged in the system.', 'open', NOW())
            ");
            $upStmt->execute(['ticketId' => $newId]);

            $db->commit();

            // Fetch and return the newly created ticket
            $stmt = $db->prepare("SELECT * FROM `support_tickets` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $newId]);
            $created = $stmt->fetch();
            $created['id'] = (int)$created['id'];
            $created['clientId'] = (int)$created['clientId'];

            return Response::json($created, 201);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function addTicketUpdate(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid ticket ID', 400);
        }

        $body = $req['body'];
        $note = $body['note'] ?? '';
        $statusChange = $body['statusChange'] ?? null;

        if (!$note) {
            return Response::error('Note is required for updating ticket', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `support_tickets` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            return Response::error('Ticket not found', 404);
        }

        $handledBy = $req['user'] ? $req['user']['email'] : 'System';

        try {
            $db->beginTransaction();

            // 1. Create update note
            $insUpStmt = $db->prepare("
                INSERT INTO `ticket_updates` (`ticketId`, `note`, `statusChange`, `createdAt`)
                VALUES (:ticketId, :note, :statusChange, NOW())
            ");
            $insUpStmt->execute([
                'ticketId' => $id,
                'note' => $note,
                'statusChange' => $statusChange ?: null
            ]);
            $newUpdateId = (int)$db->lastInsertId();

            // 2. Determine ticket level changes
            if ($statusChange) {
                if (in_array($statusChange, ['resolved', 'closed'])) {
                    $updTicket = $db->prepare("
                        UPDATE `support_tickets` 
                        SET `status` = :status, `closedAt` = NOW(), `handledBy` = :handledBy, `updatedAt` = NOW() 
                        WHERE `id` = :id
                    ");
                    $updTicket->execute([
                        'status' => $statusChange,
                        'handledBy' => $handledBy,
                        'id' => $id
                    ]);
                } else {
                    $updTicket = $db->prepare("
                        UPDATE `support_tickets` 
                        SET `status` = :status, `closedAt` = NULL, `updatedAt` = NOW() 
                        WHERE `id` = :id
                    ");
                    $updTicket->execute([
                        'status' => $statusChange,
                        'id' => $id
                    ]);
                }
            } else {
                $updTicket = $db->prepare("UPDATE `support_tickets` SET `updatedAt` = NOW() WHERE `id` = :id");
                $updTicket->execute(['id' => $id]);
            }

            $db->commit();

            // Fetch final ticket status
            $stmt->execute(['id' => $id]);
            $updatedTicket = $stmt->fetch();
            $updatedTicket['id'] = (int)$updatedTicket['id'];
            $updatedTicket['clientId'] = (int)$updatedTicket['clientId'];

            // Fetch new update note
            $resUpStmt = $db->prepare("SELECT * FROM `ticket_updates` WHERE `id` = :id LIMIT 1");
            $resUpStmt->execute(['id' => $newUpdateId]);
            $updateNote = $resUpStmt->fetch();
            $updateNote['id'] = (int)$updateNote['id'];
            $updateNote['ticketId'] = (int)$updateNote['ticketId'];

            return Response::json([
                'updatedTicket' => $updatedTicket,
                'update' => $updateNote
            ], 201);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function deleteTicket(array $req) {
        $id = isset($req['params']['id']) ? (int)$req['params']['id'] : 0;
        if ($id <= 0) {
            return Response::error('Invalid ticket ID', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT `id` FROM `support_tickets` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            return Response::error('Ticket not found', 404);
        }

        // Delete ticket (and updates because constraint is cascade or we delete them explicitly)
        try {
            $db->beginTransaction();

            $delUpdates = $db->prepare("DELETE FROM `ticket_updates` WHERE `ticketId` = :ticketId");
            $delUpdates->execute(['ticketId' => $id]);

            $delTicket = $db->prepare("DELETE FROM `support_tickets` WHERE `id` = :id");
            $delTicket->execute(['id' => $id]);

            $db->commit();

            return Response::json(['message' => 'Ticket deleted successfully']);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
