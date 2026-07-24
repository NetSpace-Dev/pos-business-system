import prisma from './prisma';

interface TextLkConfig {
  apiToken: string | null;
  groupId: string | null;
}

// Fetch settings from DB, falling back to process.env
async function getSettings(): Promise<TextLkConfig> {
  try {
    const dbSettings = await prisma.systemSetting.findMany({
      where: {
        key: {
          in: ['text_lk_api_token', 'text_lk_group_id']
        }
      }
    });

    const settingsMap = dbSettings.reduce((acc: any, curr) => {
      acc[curr.key] = curr.value;
      return acc;
    }, {});

    const apiToken = settingsMap['text_lk_api_token'] || process.env.TEXT_LK_API_TOKEN || null;
    const groupId = settingsMap['text_lk_group_id'] || process.env.TEXT_LK_GROUP_ID || null;

    return { apiToken, groupId };
  } catch (error) {
    console.error('[Text.lk] Failed to fetch settings from database:', error);
    return {
      apiToken: process.env.TEXT_LK_API_TOKEN || null,
      groupId: process.env.TEXT_LK_GROUP_ID || null
    };
  }
}

// Clean and format phone numbers to standard Text.lk format (e.g. 94771234567)
export function formatPhoneNumber(phone: string): string {
  // Remove all non-numeric characters
  let digits = phone.replace(/\D/g, '');

  // If it starts with 00, strip it
  if (digits.startsWith('00')) {
    digits = digits.substring(2);
  }

  // If it starts with 94, it is already in international format
  if (digits.startsWith('94') && digits.length === 11) {
    return digits;
  }

  // If it starts with 07 and has length 10 (standard local mobile), convert 0 to 94
  if (digits.startsWith('07') && digits.length === 10) {
    return '94' + digits.substring(1);
  }

  // If it starts with 7 and has length 9, prepend 94
  if (digits.startsWith('7') && digits.length === 9) {
    return '94' + digits;
  }

  // Return digits as is or default
  return digits;
}

// Split full name into first and last name
export function splitName(fullName: string): { firstName: string; lastName: string } {
  const parts = fullName.trim().split(/\s+/);
  const firstName = parts[0] || '';
  const lastName = parts.slice(1).join(' ') || '';
  return { firstName, lastName };
}

// Create a contact in Text.lk and return UID or null
export async function syncContactCreate(phone: string, contactPerson: string, companyName?: string): Promise<string | null> {
  const config = await getSettings();
  if (!config.apiToken || !config.groupId) {
    console.log('[Text.lk] Synchronization skipped: API Token or Group ID is not configured.');
    return null;
  }

  const formattedPhone = formatPhoneNumber(phone);
  const { firstName, lastName } = splitName(contactPerson);

  const endpoint = `https://app.text.lk/api/v3/contacts/${config.groupId}/store`;
  console.log(`[Text.lk] Creating contact on Group ${config.groupId} for ${formattedPhone}...`);

  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${config.apiToken}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        PHONE: formattedPhone,
        FIRST_NAME: firstName,
        LAST_NAME: lastName,
        COMPANY: companyName || ''
      })
    });

    const resData: any = await response.json();
    console.log('[Text.lk] Create response:', JSON.stringify(resData));

    if (response.ok && resData.status === 'success') {
      const uid = resData.data?.uid || resData.uid || (typeof resData.data === 'object' ? resData.data.uid : null);
      if (uid) {
        console.log(`[Text.lk] Contact created successfully. UID: ${uid}`);
        return uid;
      }
      console.warn('[Text.lk] Contact created, but unique UID was not found in response:', resData);
      return null;
    } else {
      console.error('[Text.lk] Failed to create contact:', resData.message || response.statusText);
      return null;
    }
  } catch (error) {
    console.error('[Text.lk] Request error in syncContactCreate:', error);
    return null;
  }
}

// Update a contact in Text.lk
export async function syncContactUpdate(uid: string, phone: string, contactPerson: string, companyName?: string): Promise<boolean> {
  const config = await getSettings();
  if (!config.apiToken || !config.groupId) {
    console.log('[Text.lk] Synchronization skipped: API Token or Group ID is not configured.');
    return false;
  }

  const formattedPhone = formatPhoneNumber(phone);
  const { firstName, lastName } = splitName(contactPerson);

  const endpoint = `https://app.text.lk/api/v3/contacts/${config.groupId}/update/${uid}`;
  console.log(`[Text.lk] Updating contact UID: ${uid} on Group ${config.groupId} to ${formattedPhone}...`);

  try {
    const response = await fetch(endpoint, {
      method: 'PATCH',
      headers: {
        'Authorization': `Bearer ${config.apiToken}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        PHONE: formattedPhone,
        FIRST_NAME: firstName,
        LAST_NAME: lastName,
        COMPANY: companyName || ''
      })
    });

    const resData: any = await response.json();
    console.log('[Text.lk] Update response:', JSON.stringify(resData));

    if (response.ok && resData.status === 'success') {
      console.log(`[Text.lk] Contact updated successfully.`);
      return true;
    } else {
      console.error('[Text.lk] Failed to update contact:', resData.message || response.statusText);
      return false;
    }
  } catch (error) {
    console.error('[Text.lk] Request error in syncContactUpdate:', error);
    return false;
  }
}

// Delete a contact in Text.lk
export async function syncContactDelete(uid: string): Promise<boolean> {
  const config = await getSettings();
  if (!config.apiToken || !config.groupId) {
    console.log('[Text.lk] Synchronization skipped: API Token or Group ID is not configured.');
    return false;
  }

  const endpoint = `https://app.text.lk/api/v3/contacts/${config.groupId}/delete/${uid}`;
  console.log(`[Text.lk] Deleting contact UID: ${uid} from Group ${config.groupId}...`);

  try {
    const response = await fetch(endpoint, {
      method: 'DELETE',
      headers: {
        'Authorization': `Bearer ${config.apiToken}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      }
    });

    const resData: any = await response.json();
    console.log('[Text.lk] Delete response:', JSON.stringify(resData));

    if (response.ok && resData.status === 'success') {
      console.log(`[Text.lk] Contact deleted successfully.`);
      return true;
    } else {
      console.error('[Text.lk] Failed to delete contact:', resData.message || response.statusText);
      return false;
    }
  } catch (error) {
    console.error('[Text.lk] Request error in syncContactDelete:', error);
    return false;
  }
}

// Send an SMS message using Text.lk SMS API
export async function sendSMS(recipient: string, message: string): Promise<boolean> {
  const config = await getSettings();
  if (!config.apiToken) {
    console.log('[Text.lk] SMS sending skipped: API Token is not configured.');
    return false;
  }

  // Fetch sender ID from DB
  let senderId = 'Sandbox';
  try {
    const dbSenderId = await prisma.systemSetting.findUnique({
      where: { key: 'text_lk_sender_id' }
    });
    if (dbSenderId && dbSenderId.value) {
      senderId = dbSenderId.value;
    }
  } catch (e) {
    console.error('[Text.lk] Failed to fetch sender_id setting:', e);
  }

  const formattedPhone = formatPhoneNumber(recipient);
  const endpoint = 'https://app.text.lk/api/v3/sms/send';
  console.log(`[Text.lk] Sending SMS to ${formattedPhone} from ${senderId}...`);
  console.log(`[Text.lk] Message text: ${message}`);

  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${config.apiToken}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        recipient: formattedPhone,
        sender_id: senderId,
        type: 'plain',
        message: message
      })
    });

    const resData: any = await response.json();
    console.log('[Text.lk] Send SMS response:', JSON.stringify(resData));

    if (response.ok && (resData.status === 'success' || resData.status === 'sent')) {
      console.log(`[Text.lk] SMS sent successfully.`);
      return true;
    } else {
      console.error('[Text.lk] Failed to send SMS:', resData.message || response.statusText);
      return false;
    }
  } catch (error) {
    console.error('[Text.lk] Request error in sendSMS:', error);
    return false;
  }
}
