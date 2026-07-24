<?php
namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

class SettingsController {
    public function getSettings(array $req) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT `key`, `value` FROM `system_settings`");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            return Response::json($settings ?: new \stdClass());
        } catch (\Exception $e) {
            error_log('Get settings error: ' . $e->getMessage());
            return Response::error('Failed to retrieve settings', 500);
        }
    }

    public function updateSettings(array $req) {
        $updates = $req['body'];
        $userEmail = $req['user']['email'] ?? 'admin@pos.com';
        $userId = $req['user']['id'] ?? 1;
        $auditDetails = [];

        $db = Database::getConnection();

        try {
            $db->beginTransaction();

            $existingStmt = $db->prepare("SELECT * FROM `system_settings` WHERE `key` = :key LIMIT 1");
            $insHist = $db->prepare("
                INSERT INTO `setting_history` (`key`, `oldValue`, `newValue`, `updatedBy`, `updatedAt`)
                VALUES (:key, :oldValue, :newValue, :updatedBy, NOW())
            ");
            $updSetting = $db->prepare("
                UPDATE `system_settings` SET `value` = :value, `updatedBy` = :updatedBy, `updatedAt` = NOW()
                WHERE `key` = :key
            ");
            $insSetting = $db->prepare("
                INSERT INTO `system_settings` (`key`, `value`, `updatedBy`, `updatedAt`)
                VALUES (:key, :value, :updatedBy, NOW())
            ");

            foreach ($updates as $key => $value) {
                $strVal = (string)$value;
                $existingStmt->execute(['key' => $key]);
                $existing = $existingStmt->fetch();

                if ($existing) {
                    if ($existing['value'] !== $strVal) {
                        // Log history
                        $insHist->execute([
                            'key' => $key,
                            'oldValue' => $existing['value'],
                            'newValue' => $strVal,
                            'updatedBy' => $userEmail
                        ]);

                        // Update setting
                        $updSetting->execute([
                            'value' => $strVal,
                            'updatedBy' => $userEmail,
                            'key' => $key
                        ]);

                        $auditDetails[] = "Changed '{$key}' from '{$existing['value']}' to '{$strVal}'";
                    }
                } else {
                    // Create setting
                    $insSetting->execute([
                        'key' => $key,
                        'value' => $strVal,
                        'updatedBy' => $userEmail
                    ]);

                    // Log history
                    $insHist->execute([
                        'key' => $key,
                        'oldValue' => null,
                        'newValue' => $strVal,
                        'updatedBy' => $userEmail
                    ]);

                    $auditDetails[] = "Set new setting '{$key}' to '{$strVal}'";
                }
            }

            if (count($auditDetails) > 0) {
                $details = implode('; ', $auditDetails);
                $auditStmt = $db->prepare("
                    INSERT INTO `audit_logs` (`userId`, `userEmail`, `action`, `details`, `createdAt`)
                    VALUES (:userId, :userEmail, 'UPDATE_SETTINGS', :details, NOW())
                ");
                $auditStmt->execute([
                    'userId' => $userId,
                    'userEmail' => $userEmail,
                    'details' => $details
                ]);
            }

            $db->commit();

            return Response::json(['message' => 'Settings updated successfully']);
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('Update settings error: ' . $e->getMessage());
            return Response::error('Failed to update settings', 500);
        }
    }

    public function getSettingsHistory(array $req) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT * FROM `setting_history` ORDER BY `updatedAt` DESC");
            $history = $stmt->fetchAll();
            foreach ($history as &$h) {
                $h['id'] = (int)$h['id'];
            }
            return Response::json($history);
        } catch (\Exception $e) {
            error_log('Get settings history error: ' . $e->getMessage());
            return Response::error('Failed to retrieve settings change log', 500);
        }
    }
}
