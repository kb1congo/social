import makeWASocket, {
    useMultiFileAuthState,
    DisconnectReason
} from '@whiskeysockets/baileys';
import pino from 'pino';
import QRCode from 'qrcode';
import path from 'node:path';
import fs from 'node:fs/promises';

const sessions = new Map();
const logger = pino({ level: 'silent' });

const sessionsDirectory = path.resolve(
    process.cwd(),
    'sessions'
);

async function synchronizeStatusWithPhp(accountId, session) {
    const phpUrl = (process.env.PHP_INTERNAL_URL || '').replace(/\/+$/, '');
    const apiSecret = process.env.NODE_API_SECRET || '';

    if (!phpUrl || !apiSecret) {
        console.error('[SYNC] Configuration PHP ou secret manquant.');
        return;
    }

    try {
        const response = await fetch(
            `${phpUrl}/internal/accounts/status`,
            {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${apiSecret}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    accountId: Number(accountId),
                    status: session.status,
                    phoneNumber: session.phoneNumber,
                    connectedAt: session.connectedAt
                }),
                signal: AbortSignal.timeout(10000)
            }
        );

        if (!response.ok) {
            console.error(
                `[SYNC] Échec PHP pour le compte ${accountId} : HTTP ${response.status}`
            );
            return;
        }

        console.log(
            `[SYNC] Compte ${accountId} synchronisé : ${session.status}`
        );
    } catch (error) {
        console.error(
            `[SYNC] Erreur pour le compte ${accountId} : ${error.message}`
        );
    }
}


async function forwardMessageToPhp(accountId, socket, message) {
    const phpUrl = (process.env.PHP_INTERNAL_URL || '').replace(/\/+$/, '');
    const apiSecret = process.env.NODE_API_SECRET || '';

    if (!phpUrl || !apiSecret) {
        throw new Error('Configuration PHP ou secret interne manquant.');
    }

    const key = message.key;
    const remoteJid = key?.remoteJid;
    const selfJid = socket.user?.id;

    if (!remoteJid || !selfJid || !key?.id || !message.message) {
        return;
    }

    if (remoteJid === 'status@broadcast') {
        return;
    }

    let content = message.message;

    while (
        content?.ephemeralMessage?.message ||
        content?.viewOnceMessage?.message ||
        content?.viewOnceMessageV2?.message ||
        content?.documentWithCaptionMessage?.message
    ) {
        content =
            content.ephemeralMessage?.message ||
            content.viewOnceMessage?.message ||
            content.viewOnceMessageV2?.message ||
            content.documentWithCaptionMessage?.message;
    }

    const messageType = Object.keys(content).find(
        key => key !== 'messageContextInfo'
    ) || 'unknown';

    const contentData = content[messageType] || {};

    const body =
        content.conversation ||
        content.extendedTextMessage?.text ||
        content.imageMessage?.caption ||
        content.videoMessage?.caption ||
        content.documentMessage?.caption ||
        content.buttonsResponseMessage?.selectedDisplayText ||
        content.listResponseMessage?.title ||
        content.templateButtonReplyMessage?.selectedDisplayText ||
        null;

    const rawTimestamp = message.messageTimestamp;
    const timestamp = typeof rawTimestamp === 'number'
        ? rawTimestamp
        : typeof rawTimestamp?.toNumber === 'function'
            ? rawTimestamp.toNumber()
            : Number(rawTimestamp) || Math.floor(Date.now() / 1000);

    const payload = {
        accountId: Number(accountId),
        remoteJid,
        selfJid,
        messageId: key.id,
        fromMe: Boolean(key.fromMe),
        participantJid: key.participant || null,
        pushName: message.pushName || null,
        messageType,
        body: body || null,
        mediaMimeType: contentData.mimetype || null,
        timestamp,
        payload: {
            messageType
        }
    };

    const response = await fetch(`${phpUrl}/internal/messages`, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${apiSecret}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload),
        signal: AbortSignal.timeout(15000)
    });

    if (!response.ok) {
        const responseText = await response.text();

        throw new Error(
            `PHP HTTP ${response.status}: ${responseText.slice(0, 300)}`
        );
    }

    console.log(
        `[MESSAGE] Compte ${accountId} : message ${key.id} transmis à PHP.`
    );
}

export async function connectAccount(accountId) {
    const id = String(accountId);

    if (!/^[1-9]\d*$/.test(id)) {
        throw new Error('Identifiant de compte invalide.');
    }

    const existing = sessions.get(id);

    if (existing) {
        return getAccountStatus(id);
    }

    const sessionDirectory = path.join(sessionsDirectory, id);

    await fs.mkdir(sessionDirectory, { recursive: true });

    const { state, saveCreds } = await useMultiFileAuthState(
        sessionDirectory
    );

    const socket = makeWASocket({
        auth: state,
        logger,
        printQRInTerminal: false,
        browser: ['WhatsApp Gateway', 'Chrome', '1.0.0'],
        markOnlineOnConnect: false,
        syncFullHistory: false
    });

    const session = {
        socket,
        status: 'connecting',
        qr: null,
        qrImage: null,
        phoneNumber: null,
        connectedAt: null,
        lastError: null
    };

    sessions.set(id, session);

    socket.ev.on('creds.update', saveCreds);

    socket.ev.on('messages.upsert', async ({ messages, type }) => {
        console.log(
            `[MESSAGE EVENT] Compte ${id} : type=${type}, nombre=${messages?.length || 0}`
        );

        if (!Array.isArray(messages)) {
            return;
        }

        for (const message of messages) {
            const current = sessions.get(id);

            if (!current || current.socket !== socket) {
                return;
            }

            if (type !== 'notify') {
                continue;
            }

            try {
                await forwardMessageToPhp(id, socket, message);
            } catch (error) {
                console.error(
                    `[MESSAGE] Échec de transmission du compte ${id} : ${error.message}`
                );
            }
        }
    });



    socket.ev.on('connection.update', async (update) => {
        const current = sessions.get(id);

        if (!current || current.socket !== socket) {
            return;
        }

        let statusChanged = false;

        console.log('[Baileys connection.update]', {
            accountId: id,
            connection: update.connection ?? null,
            hasQr: Boolean(update.qr),
            statusCode: update.lastDisconnect?.error?.output?.statusCode ?? null,
            disconnectMessage: update.lastDisconnect?.error?.message ?? null
        });

        if (update.qr) {
            current.status = 'qr';
            current.qr = update.qr;
            statusChanged = true;

            try {
                current.qrImage = await QRCode.toDataURL(update.qr, {
                    errorCorrectionLevel: 'M',
                    margin: 2,
                    width: 320
                });
            } catch (error) {
                current.lastError = 'Impossible de générer l’image QR.';
            }
        }

        if (update.connection === 'connecting') {
            current.status = 'connecting';
            current.qr = null;
            current.qrImage = null;
            statusChanged = true;
        }

        if (update.connection === 'open') {
            current.status = 'connected';
            current.qr = null;
            current.qrImage = null;
            current.connectedAt = new Date().toISOString();
            current.phoneNumber = socket.user?.id?.split(':')[0] || null;
            current.lastError = null;
            statusChanged = true;
        }

        if (update.connection === 'close') {
            const statusCode = update.lastDisconnect?.error?.output?.statusCode;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

            current.status = shouldReconnect ? 'disconnected' : 'logged_out';
            current.qr = null;
            current.qrImage = null;
            statusChanged = true;

            if (shouldReconnect) {
                sessions.delete(id);
                setTimeout(() => {
                    connectAccount(id).catch((error) => {
                        logger.error({ accountId: id, error: error.message });
                    });
                }, 3000);
            }
        }

        if (statusChanged) {
            void synchronizeStatusWithPhp(id, current);
        }
    });

    return getAccountStatus(id);
}

export async function sendTextMessage(accountId, remoteJid, text) {
    const id = String(accountId);
    const session = sessions.get(id);

    if (!session || session.status !== 'connected') {
        throw new Error('Le compte WhatsApp n’est pas connecté.');
    }

    if (
        typeof remoteJid !== 'string' ||
        !/^[0-9]+(?:-[0-9]+)?@(s\.whatsapp\.net|g\.us|lid)$/.test(remoteJid)
    ) {
        throw new Error('Destinataire WhatsApp invalide.');
    }

    if (typeof text !== 'string' || !text.trim()) {
        throw new Error('Le message ne peut pas être vide.');
    }

    const result = await session.socket.sendMessage(remoteJid, {
        text: text.trim()
    });

    if (!result?.key?.id) {
        throw new Error('WhatsApp n’a pas confirmé la création du message.');
    }

    return {
        messageId: result.key.id,
        remoteJid,
        selfJid: session.socket.user?.id || null,
        fromMe: true,
        timestamp: Math.floor(Date.now() / 1000)
    };
}

export function getAccountStatus(accountId) {
    const id = String(accountId);
    const session = sessions.get(id);

    if (!session) {
        return {
            accountId: Number(id),
            status: 'disconnected',
            qrImage: null,
            phoneNumber: null,
            connectedAt: null
        };
    }

    return {
        accountId: Number(id),
        status: session.status,
        qrImage: session.qrImage,
        phoneNumber: session.phoneNumber,
        connectedAt: session.connectedAt,
        lastError: session.lastError
    };
}

export async function disconnectAccount(accountId, logout = false) {
    const id = String(accountId);
    const session = sessions.get(id);

    if (!session) {
        return getAccountStatus(id);
    }

    sessions.delete(id);

    if (logout) {
        await session.socket.logout();
        await fs.rm(path.join(sessionsDirectory, id), {
            recursive: true,
            force: true
        });
    } else {
        session.socket.end(undefined);
    }

    return {
        accountId: Number(id),
        status: logout ? 'logged_out' : 'disconnected',
        qr: null,
        qrImage: null,
        phoneNumber: session.phoneNumber,
        connectedAt: session.connectedAt
    };
}
