<?php
namespace App\Utils;

use App\Config\Database;
use PDO;

class TextLk {
    private static function getSettings(): array {
        $apiToken = Database::getEnv('TEXT_LK_API_TOKEN');
        $groupId = Database::getEnv('TEXT_LK_GROUP_ID');

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT `key`, `value` FROM `system_settings` WHERE `key` IN ('text_lk_api_token', 'text_lk_group_id')");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            if (isset($settings['text_lk_api_token'])) {
                $apiToken = $settings['text_lk_api_token'];
            }
            if (isset($settings['text_lk_group_id'])) {
                $groupId = $settings['text_lk_group_id'];
            }
        } catch (\Exception $e) {
            error_log("[Text.lk] Failed to fetch settings from database: " . $e->getMessage());
        }

        return [
            'apiToken' => $apiToken ?: null,
            'groupId' => $groupId ?: null
        ];
    }

    public static function formatPhoneNumber(string $phone): string {
        // Remove all non-numeric characters
        $digits = preg_replace('/\D/', '', $phone);

        // If it starts with 00, strip it
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        // If it starts with 94, it is already in international format
        if (strpos($digits, '94') === 0 && strlen($digits) === 11) {
            return $digits;
        }

        // If it starts with 07 and has length 10 (standard local mobile), convert 0 to 94
        if (strpos($digits, '07') === 0 && strlen($digits) === 10) {
            return '94' . substr($digits, 1);
        }

        // If it starts with 7 and has length 9, prepend 94
        if (strpos($digits, '7') === 0 && strlen($digits) === 9) {
            return '94' . $digits;
        }

        return $digits;
    }

    public static function splitName(string $fullName): array {
        $parts = preg_split('/\s+/', trim($fullName));
        $firstName = $parts[0] ?? '';
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        return [
            'firstName' => $firstName,
            'lastName' => $lastName
        ];
    }

    private static function makeRequest(string $url, string $method, array $headers, $body = null) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($body !== null) {
            $jsonBody = is_array($body) ? json_encode($body) : $body;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            error_log("[Text.lk] Curl error: $error");
            return null;
        }

        curl_close($ch);

        return [
            'code' => $httpCode,
            'body' => json_decode($response, true) ?? $response
        ];
    }

    public static function syncContactCreate(string $phone, string $contactPerson, string $companyName = ''): ?string {
        $config = self::getSettings();
        if (!$config['apiToken'] || !$config['groupId']) {
            error_log('[Text.lk] Synchronization skipped: API Token or Group ID is not configured.');
            return null;
        }

        $formattedPhone = self::formatPhoneNumber($phone);
        $nameParts = self::splitName($contactPerson);

        $endpoint = "https://app.text.lk/api/v3/contacts/{$config['groupId']}/store";
        
        $headers = [
            "Authorization: Bearer {$config['apiToken']}",
            "Content-Type: application/json",
            "Accept: application/json"
        ];

        $payload = [
            'PHONE' => $formattedPhone,
            'FIRST_NAME' => $nameParts['firstName'],
            'LAST_NAME' => $nameParts['lastName'],
            'COMPANY' => $companyName
        ];

        $res = self::makeRequest($endpoint, 'POST', $headers, $payload);
        if ($res && ($res['code'] === 200 || $res['code'] === 201)) {
            $resData = $res['body'];
            if (is_array($resData) && ($resData['status'] ?? '') === 'success') {
                $uid = $resData['data']['uid'] ?? $resData['uid'] ?? (is_array($resData['data'] ?? null) ? ($resData['data']['uid'] ?? null) : null);
                if ($uid) {
                    return $uid;
                }
            }
        }
        
        error_log('[Text.lk] Failed to create contact: ' . json_encode($res));
        return null;
    }

    public static function syncContactUpdate(string $uid, string $phone, string $contactPerson, string $companyName = ''): bool {
        $config = self::getSettings();
        if (!$config['apiToken'] || !$config['groupId']) {
            error_log('[Text.lk] Synchronization skipped: API Token or Group ID is not configured.');
            return false;
        }

        $formattedPhone = self::formatPhoneNumber($phone);
        $nameParts = self::splitName($contactPerson);

        $endpoint = "https://app.text.lk/api/v3/contacts/{$config['groupId']}/update/{$uid}";

        $headers = [
            "Authorization: Bearer {$config['apiToken']}",
            "Content-Type: application/json",
            "Accept: application/json"
        ];

        $payload = [
            'PHONE' => $formattedPhone,
            'FIRST_NAME' => $nameParts['firstName'],
            'LAST_NAME' => $nameParts['lastName'],
            'COMPANY' => $companyName
        ];

        $res = self::makeRequest($endpoint, 'PATCH', $headers, $payload);
        if ($res && $res['code'] === 200) {
            $resData = $res['body'];
            if (is_array($resData) && ($resData['status'] ?? '') === 'success') {
                return true;
            }
        }

        error_log('[Text.lk] Failed to update contact: ' . json_encode($res));
        return false;
    }

    public static function syncContactDelete(string $uid): bool {
        $config = self::getSettings();
        if (!$config['apiToken'] || !$config['groupId']) {
            error_log('[Text.lk] Synchronization skipped: API Token or Group ID is not configured.');
            return false;
        }

        $endpoint = "https://app.text.lk/api/v3/contacts/{$config['groupId']}/delete/{$uid}";

        $headers = [
            "Authorization: Bearer {$config['apiToken']}",
            "Content-Type: application/json",
            "Accept: application/json"
        ];

        $res = self::makeRequest($endpoint, 'DELETE', $headers);
        if ($res && $res['code'] === 200) {
            $resData = $res['body'];
            if (is_array($resData) && ($resData['status'] ?? '') === 'success') {
                return true;
            }
        }

        error_log('[Text.lk] Failed to delete contact: ' . json_encode($res));
        return false;
    }

    public static function sendSMS(string $recipient, string $message): bool {
        $config = self::getSettings();
        if (!$config['apiToken']) {
            error_log('[Text.lk] SMS sending skipped: API Token is not configured.');
            return false;
        }

        $senderId = 'Sandbox';
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT `value` FROM `system_settings` WHERE `key` = 'text_lk_sender_id' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val) {
                $senderId = $val;
            }
        } catch (\Exception $e) {
            error_log('[Text.lk] Failed to fetch sender_id setting: ' . $e->getMessage());
        }

        $formattedPhone = self::formatPhoneNumber($recipient);
        $endpoint = 'https://app.text.lk/api/v3/sms/send';

        $headers = [
            "Authorization: Bearer {$config['apiToken']}",
            "Content-Type: application/json",
            "Accept: application/json"
        ];

        $payload = [
            'recipient' => $formattedPhone,
            'sender_id' => $senderId,
            'type' => 'plain',
            'message' => $message
        ];

        $res = self::makeRequest($endpoint, 'POST', $headers, $payload);
        if ($res && ($res['code'] === 200 || $res['code'] === 201)) {
            $resData = $res['body'];
            if (is_array($resData) && (($resData['status'] ?? '') === 'success' || ($resData['status'] ?? '') === 'sent')) {
                return true;
            }
        }

        error_log('[Text.lk] Failed to send SMS: ' . json_encode($res));
        return false;
    }
}
