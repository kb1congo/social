
'use strict';

const express = require('express');
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const pino = require('pino');
const QRCode = require('qrcode');

const app = express();

app.disable('x-powered-by');
app.use(express.json({ limit: '1mb' }));

const PORT = Number(process.env.PORT || 3000);
const INTERNAL_API_TOKEN = process.env.INTERNAL_API_TOKEN || '';
const DATA_PATH = process.env.WHATSAPP_DATA_PATH || '/data/baileys';
const ACCOUNTS_PATH = path.join(DATA_PATH, 'accounts');
const DEFAULT_ACCOUNT_ID = 'personal-gateway';

if (!INTERNAL_API_TOKEN) {
  throw new Error('INTERNAL_API_TOKEN est obligatoire.');
}

// ---------------------------------------------------------------------
// Journalisation
// ---------------------------------------------------------------------

const logger = pino({
  level: process.env.BAILEYS_LOG_LEVEL || 'silent'
});

// ---------------------------------------------------------------------
// Gestion des connexions
// ---------------------------------------------------------------------

const accounts = new Map();
const sseClients = new Set();

let baileysModule = null;
let server = null;

// ---------------------------------------------------------------------
// Sécurité
// ---------------------------------------------------------------------

function safeEqual(a, b) {
  const left = Buffer.from(String(a));
  const right = Buffer.from(String(b));

  return left.length === right.length &&
    crypto.timingSafeEqual(left, right);
}

function requireInternalToken(req, res, next) {
  const supplied = (req.headers.authorization || '')
    .replace(/^Bearer\s+/i, '');

  if (!safeEqual(supplied, INTERNAL_API_TOKEN)) {
    return res.status(401).json({ error: 'unauthorized' });
  }

  next();
}

function validAccountId(accountId) {
  return typeof accountId === 'string' &&
    /^[a-zA-Z0-9_-]{1,64}$/.test(accountId);
}

// ---------------------------------------------------------------------
// Comptes WhatsApp
// ---------------------------------------------------------------------

function getAccount(accountId) {
  if (!validAccountId(accountId)) {
    return null;
  }

  if (!accounts.has(accountId)) {
    accounts.set(accountId, {
      id: accountId,
      status: 'idle',
      qr: null,
      qrDataUrl: null,
      phoneNumber: null,
      socket: null,
      startPromise: null,
      reconnectTimer: null,
      reconnectAttempts: 0,
      manualLogout: false
    });
  }

  return accounts.get(accountId);
}

// ---------------------------------------------------------------------
// Événements SSE
// ---------------------------------------------------------------------

function publish(event, data) {
  const payload = `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`;

  for (const client of sseClients) {
    if (
      client.accountId &&
      client.accountId !== data.accountId
    ) {
      continue;
    }

    try {
      client.response.write(payload);
    } catch {
      sseClients.delete(client);
    }
  }
}

// ---------------------------------------------------------------------
// Persistance des messages dans PHP / MySQL
// ---------------------------------------------------------------------

async function persistWhatsAppMessage(event) {
  try {
    const response = await fetch(
      `${process.env.PHP_INTERNAL_URL || 'http://nginx'}/internal/whatsapp-event.php`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${INTERNAL_API_TOKEN}`
        },
        body: JSON.stringify(event),
        signal: AbortSignal.timeout(10000)
      }
    );

    if (!response.ok) {
      const details = await response.text();

      console.error(
        `[${event.accountId}] Échec de persistance HTTP ${response.status}:`,
        details
      );

      return false;
    }

    const result = await response.json();

    if (result.success !== true) {
      console.error(
        `[${event.accountId}] Persistance refusée :`,
        result.error || 'Erreur inconnue'
      );

      return false;
    }

    return true;

  } catch (error) {
    console.error(
      `[${event.accountId}] Erreur de persistance :`,
      error.message
    );

    return false;
  }
}

// ---------------------------------------------------------------------
// Persistance des correspondances LID / JID
// ---------------------------------------------------------------------

async function persistWhatsAppContactMapping(accountId, lid, jid, source) {
  const normalizedLid = String(lid || '').trim();
  const normalizedJid = String(jid || '').trim();

  // Ne jamais déduire un numéro de téléphone à partir d'un LID.
  if (!/^[0-9]{1,30}@lid$/.test(normalizedLid)) {
    return false;
  }

  if (!/^[0-9]{5,20}@s\.whatsapp\.net$/.test(normalizedJid)) {
    return false;
  }

  const persisted = await persistWhatsAppMessage({
    eventType: 'contact_mapping',
    accountId,
    lid: normalizedLid,
    jid: normalizedJid
  });

  console.log(
    `[${accountId}] Correspondance LID/JID (${source}) : ${persisted ? 'OK' : 'ÉCHEC'}`,
    {
      lid: normalizedLid,
      jid: normalizedJid
    }
  );

  return persisted;
}

async function handleContactMappings(account, socket, contacts, source) {
  // Ignorer les événements d'une ancienne connexion.
  if (account.socket !== socket || !Array.isArray(contacts)) {
    return;
  }

  for (const contact of contacts) {
    const id = String(contact?.id || '').trim();

    const lid = contact?.lid ||
      (/^[0-9]{1,30}@lid$/.test(id) ? id : null);

    const jid = contact?.jid ||
      (/^[0-9]{5,20}@s\.whatsapp\.net$/.test(id) ? id : null);

    if (!lid || !jid) {
      continue;
    }

    await persistWhatsAppContactMapping(
      account.id,
      lid,
      jid,
      source
    );
  }
}


// ---------------------------------------------------------------------
// Statut des comptes
// ---------------------------------------------------------------------

function updateStatus(account, status, extra = {}) {
  account.status = status;

  publish('whatsapp.status', {
    accountId: account.id,
    status,
    phoneNumber: account.phoneNumber,
    qrAvailable: Boolean(account.qr),
    ...extra
  });
}

function getDisconnectCode(error) {
  return error?.output?.statusCode ||
    error?.data?.statusCode ||
    null;
}

// ---------------------------------------------------------------------
// Extraction du contenu des messages
// ---------------------------------------------------------------------

function unwrapMessageContent(message) {
  let content = message?.message;

  while (content) {
    if (content.ephemeralMessage?.message) {
      content = content.ephemeralMessage.message;
      continue;
    }

    if (content.viewOnceMessage?.message) {
      content = content.viewOnceMessage.message;
      continue;
    }

    if (content.viewOnceMessageV2?.message) {
      content = content.viewOnceMessageV2.message;
      continue;
    }

    if (content.documentWithCaptionMessage?.message) {
      content = content.documentWithCaptionMessage.message;
      continue;
    }

    break;
  }

  return content;
}


function extractMessageText(message) {
  const content = unwrapMessageContent(message);

  if (!content) {
    return null;
  }

  const candidates = [
    content.conversation,
    content.extendedTextMessage?.text,
    content.imageMessage?.caption,
    content.videoMessage?.caption,
    content.documentMessage?.caption,
    content.buttonsResponseMessage?.selectedDisplayText,
    content.buttonsResponseMessage?.selectedButtonId,
    content.listResponseMessage?.title,
    content.listResponseMessage?.singleSelectReply?.selectedRowId,
    content.templateButtonReplyMessage?.selectedDisplayText,
    content.templateButtonReplyMessage?.selectedId,
    content.reactionMessage?.text,
    content.pollCreationMessage?.name,
    content.pollCreationMessageV2?.name,
    content.pollCreationMessageV3?.name,
    content.contactMessage?.displayName,
    content.locationMessage?.name,
    content.locationMessage?.address,
    content.editedMessage?.message?.conversation,
    content.editedMessage?.message?.extendedTextMessage?.text
  ];

  const text = candidates.find(
    value => typeof value === 'string' && value.trim() !== ''
  );

  return text ? text.trim() : null;
}

function extractMessageType(message) {
  const content = unwrapMessageContent(message);

  if (!content) {
    return 'unknown';
  }

  const types = {
    conversation: 'chat',
    extendedTextMessage: 'chat',
    imageMessage: 'image',
    videoMessage: 'video',
    audioMessage: 'audio',
    documentMessage: 'document',
    stickerMessage: 'sticker',
    locationMessage: 'location',
    liveLocationMessage: 'location',
    contactMessage: 'contact',
    contactsArrayMessage: 'contact',
    buttonsResponseMessage: 'interactive',
    listResponseMessage: 'interactive',
    templateButtonReplyMessage: 'interactive',
    interactiveResponseMessage: 'interactive',
    interactiveMessage: 'interactive',
    reactionMessage: 'reaction',
    pollCreationMessage: 'poll',
    pollCreationMessageV2: 'poll',
    pollCreationMessageV3: 'poll',
    pollUpdateMessage: 'poll_vote',
    protocolMessage: 'protocol',
    senderKeyDistributionMessage: 'system',
    messageContextInfo: 'system',
    ephemeralMessage: 'system',
    viewOnceMessage: 'system',
    viewOnceMessageV2: 'system',
    documentWithCaptionMessage: 'document',
    editedMessage: 'chat',
    ptvMessage: 'video',
    eventMessage: 'event',
    keepInChatMessage: 'system',
    pinInChatMessage: 'system',
    requestPhoneNumberMessage: 'contact'
  };

  for (const [key, type] of Object.entries(types)) {
    if (content[key]) {
      return type;
    }
  }

  // Identification des autres types de contenus non encore répertoriés.
  const ignoredKeys = [
    'messageContextInfo',
    'senderKeyDistributionMessage'
  ];

  const firstType = Object.keys(content).find(
    key =>
      !ignoredKeys.includes(key) &&
      content[key] !== null &&
      content[key] !== undefined
  );

  if (!firstType) {
    return 'unknown';
  }

  return firstType
    .replace(/Message$/, '')
    .replace(/([A-Z])/g, '_$1')
    .replace(/^_/, '')
    .toLowerCase();
}


// ---------------------------------------------------------------------
// Chargement de Baileys
// ---------------------------------------------------------------------

async function loadBaileys() {
  if (baileysModule) {
    return baileysModule;
  }

  const imported = await import('@whiskeysockets/baileys');

  baileysModule = {
    makeWASocket: imported.default || imported.makeWASocket,
    DisconnectReason: imported.DisconnectReason,
    useMultiFileAuthState: imported.useMultiFileAuthState
  };

  if (typeof baileysModule.makeWASocket !== 'function') {
    throw new Error('Impossible de charger makeWASocket depuis Baileys.');
  }

  if (typeof baileysModule.useMultiFileAuthState !== 'function') {
    throw new Error('Impossible de charger useMultiFileAuthState depuis Baileys.');
  }

  return baileysModule;
}

// ---------------------------------------------------------------------
// Reconnexion automatique
// ---------------------------------------------------------------------

function scheduleReconnect(account) {
  if (account.manualLogout || account.reconnectTimer) {
    return;
  }

  const delay = Math.min(
    1000 * (2 ** Math.min(account.reconnectAttempts, 6)),
    60000
  );

  account.reconnectAttempts++;

  console.log(
    `[${account.id}] Nouvelle tentative de connexion dans ${delay} ms.`
  );

  account.reconnectTimer = setTimeout(async () => {
    account.reconnectTimer = null;

    try {
      await startAccount(account.id);
    } catch (error) {
      console.error(
        `[${account.id}] Échec de reconnexion :`,
        error.message
      );

      scheduleReconnect(account);
    }
  }, delay);

  account.reconnectTimer.unref?.();
}

// ---------------------------------------------------------------------
// Traitement des événements WhatsApp
// ---------------------------------------------------------------------

async function handleMessagesUpsert(account, socket, data) {
  // Ignorer les événements provenant d'une ancienne connexion.
  if (account.socket !== socket) {
    return;
  }

  const { messages, type } = data;

  console.log(
    `[${account.id}] Messages WhatsApp : type=${type}, nombre=${messages?.length || 0}`
  );

  if (!['notify', 'append'].includes(type)) {
    return;
  }

  for (const message of messages || []) {
    const key = message.key || {};
    const remoteJid = key.remoteJid || null;

    // Exclure les statuts WhatsApp et les événements sans destinataire.
    if (!remoteJid || remoteJid === 'status@broadcast') {
      continue;
    }

    const messageId = key.id || null;

    if (!messageId) {
      continue;
    }

    const event = {
      accountId: account.id,
      messageId,
      remoteJid,
      participant: key.participant || null,
      participantPn: key.participantPn || null,
      fromMe: Boolean(key.fromMe),
      pushName: message.pushName || null,
      body: extractMessageText(message),
      timestamp: Number(message.messageTimestamp || 0),
      messageType: extractMessageType(message)
    };

    console.log(`[${account.id}] Message WhatsApp :`, {
      id: event.messageId,
      from: event.remoteJid,
      fromMe: event.fromMe,
      type: event.messageType
    });

    const stored = await persistWhatsAppMessage(event);

    console.log(
      `[${account.id}] Persistance du message ${event.messageId} : ${stored ? 'OK' : 'ÉCHEC'}`
    );

    // Publier l'événement après son traitement.
    publish('whatsapp.message.received', {
      ...event,
      persisted: stored
    });
  }
}

// ---------------------------------------------------------------------
// Démarrage d'un compte WhatsApp
// ---------------------------------------------------------------------

async function startAccount(accountId) {
  if (!validAccountId(accountId)) {
    throw new Error('Identifiant de compte invalide.');
  }

  const account = getAccount(accountId);

  if (account.startPromise) {
    return account.startPromise;
  }

  if (
    account.socket &&
    ['connecting', 'qr', 'connected'].includes(account.status)
  ) {
    return account;
  }

  account.manualLogout = false;

  if (account.reconnectTimer) {
    clearTimeout(account.reconnectTimer);
    account.reconnectTimer = null;
  }

  account.startPromise = (async () => {
    try {
      const {
        makeWASocket,
        DisconnectReason
      } = await loadBaileys();

      const sessionPath = path.join(ACCOUNTS_PATH, accountId);

      await fs.mkdir(sessionPath, {
        recursive: true,
        mode: 0o700
      });

      const {
        state,
        saveCreds
      } = await baileysModule.useMultiFileAuthState(sessionPath);

      updateStatus(account, 'connecting');

      const socket = makeWASocket({
        auth: state,
        logger,
        markOnlineOnConnect: false,
        syncFullHistory: false,
        generateHighQualityLinkPreview: false
      });

      account.socket = socket;

      // Sauvegarde des identifiants de session.
      socket.ev.on('creds.update', async () => {
        try {
          await saveCreds();
        } catch (error) {
          console.error(
            `[${accountId}] Erreur de sauvegarde des identifiants :`,
            error.message
          );
        }
      });

      // ---------------------------------------------------------------
      // Synchronisation des correspondances LID / JID
      // ---------------------------------------------------------------

      // Partage explicite d'un numéro de téléphone.
      socket.ev.on('chats.phoneNumberShare', async ({ lid, jid } = {}) => {
        console.log(`[${accountId}] Événement phoneNumberShare reçu :`, { lid, jid });
        try {
          if (account.socket !== socket) {
            return;
          }

          await persistWhatsAppContactMapping(
            accountId,
            lid,
            jid,
            'chats.phoneNumberShare'
          );
        } catch (error) {
          console.error(
            `[${accountId}] Erreur phoneNumberShare :`,
            error.message
          );
        }
      });

      // Nouveaux contacts synchronisés.
      socket.ev.on('contacts.upsert', async (contacts) => {
        console.log(`[${accountId}] contacts.upsert reçu :`, JSON.stringify(contacts));
        try {
          await handleContactMappings(
            account,
            socket,
            contacts,
            'contacts.upsert'
          );
        } catch (error) {
          console.error(
            `[${accountId}] Erreur contacts.upsert :`,
            error.message
          );
        }
      });

      // Mise à jour des contacts.
      socket.ev.on('contacts.update', async (contacts) => {
        try {
          await handleContactMappings(
            account,
            socket,
            contacts,
            'contacts.update'
          );
        } catch (error) {
          console.error(
            `[${accountId}] Erreur contacts.update :`,
            error.message
          );
        }
      });

      // Contacts issus de la synchronisation de l'historique.
      socket.ev.on('messaging-history.set', async ({ contacts } = {}) => {
        console.log(`[${accountId}] messaging-history.set reçu :`, contacts?.length || 0, 'contacts');
        try {
          await handleContactMappings(
            account,
            socket,
            contacts,
            'messaging-history.set'
          );
        } catch (error) {
          console.error(
            `[${accountId}] Erreur messaging-history.set :`,
            error.message
          );
        }
      });

      // Gestion de la connexion.
      socket.ev.on('connection.update', async (update) => {
        if (account.socket !== socket) {
          return;
        }

        const { connection, qr, lastDisconnect } = update;

        if (qr) {
          try {
            const qrDataUrl = await QRCode.toDataURL(qr);

            if (account.socket !== socket) {
              return;
            }

            account.qr = qr;
            account.qrDataUrl = qrDataUrl;

            updateStatus(account, 'qr');

            console.log(`[${accountId}] QR code disponible.`);

          } catch (error) {
            console.error(
              `[${accountId}] Erreur de génération du QR :`,
              error.message
            );
          }
        }

        if (connection === 'connecting') {
          updateStatus(account, 'connecting');
        }

        if (connection === 'open') {
          account.qr = null;
          account.qrDataUrl = null;
          account.reconnectAttempts = 0;

          account.phoneNumber = socket.user?.id
            ?.split(':')[0]
            ?.split('@')[0] || null;

          updateStatus(account, 'connected');

          console.log(
            `[${accountId}] WhatsApp connecté.`,
            account.phoneNumber || ''
          );
        }

        if (connection === 'close') {
          account.socket = null;
          account.qr = null;
          account.qrDataUrl = null;

          const code = getDisconnectCode(lastDisconnect?.error);

          if (code === DisconnectReason.loggedOut) {
            updateStatus(account, 'logged_out');

            console.warn(
              `[${accountId}] Session déconnectée. Une nouvelle association sera nécessaire.`
            );

            return;
          }

          updateStatus(account, 'disconnected', {
            disconnectCode: code
          });

          console.warn(
            `[${accountId}] Connexion fermée :`,
            lastDisconnect?.error?.message || 'cause inconnue'
          );

          scheduleReconnect(account);
        }
      });

      // Réception et persistance des messages.
      socket.ev.on('messages.upsert', async (data) => {
        try {
          await handleMessagesUpsert(account, socket, data);
        } catch (error) {
          console.error(
            `[${accountId}] Erreur de traitement des messages :`,
            error.message
          );
        }
      });

      return account;

    } catch (error) {
      account.socket = null;

      updateStatus(account, 'error');

      console.error(
        `[${accountId}] Erreur d'initialisation :`,
        error.stack || error.message
      );

      throw error;
    }
  })();

  try {
    return await account.startPromise;
  } finally {
    account.startPromise = null;
  }
}

// ---------------------------------------------------------------------
// Informations sur les comptes
// ---------------------------------------------------------------------

function accountStatus(account) {
  return {
    accountId: account.id,
    status: account.status,
    phoneNumber: account.phoneNumber,
    qrAvailable: Boolean(account.qr)
  };
}

// ---------------------------------------------------------------------
// Normalisation des destinataires
// ---------------------------------------------------------------------

function normalizeRecipient(to) {
  if (typeof to !== 'string' || !to.trim()) {
    return null;
  }

  const value = to.trim();

  // Identifiant WhatsApp individuel.
  if (/^\d+@s\.whatsapp\.net$/.test(value)) {
    return value;
  }

  // Identifiant de groupe WhatsApp.
  if (/^\d{5,30}-\d+@g\.us$/.test(value)) {
    return value;
  }

  // Numéro international.
  const phone = value.replace(/[^\d]/g, '');

  if (phone.length < 7 || phone.length > 15) {
    return null;
  }

  return `${phone}@s.whatsapp.net`;
}

// ---------------------------------------------------------------------
// Routes publiques de santé
// ---------------------------------------------------------------------

app.get('/health', (_req, res) => {
  const connected = [...accounts.values()]
    .filter(account => account.status === 'connected').length;

  res.json({
    status: 'ok',
    service: 'whatsapp-gateway',
    whatsapp: connected > 0 ? 'ready' : 'not_connected',
    accounts: accounts.size,
    connected
  });
});

// ---------------------------------------------------------------------
// Événements SSE
// ---------------------------------------------------------------------

app.get('/events', (req, res) => {
  const token = req.query.token;

  if (!token || !safeEqual(token, INTERNAL_API_TOKEN)) {
    return res.status(401).end('Unauthorized');
  }

  const accountId = req.query.accountId
    ? String(req.query.accountId)
    : null;

  if (accountId && !validAccountId(accountId)) {
    return res.status(400).json({ error: 'invalid_account_id' });
  }

  res.set({
    'Content-Type': 'text/event-stream',
    'Cache-Control': 'no-cache, no-transform',
    'Connection': 'keep-alive',
    'X-Accel-Buffering': 'no'
  });

  res.flushHeaders?.();

  res.write(
    `event: connected\ndata: ${JSON.stringify({
      status: 'connected',
      accountId
    })}\n\n`
  );

  const client = { response: res, accountId };

  sseClients.add(client);

  for (const account of accounts.values()) {
    if (!accountId || account.id === accountId) {
      res.write(
        `event: whatsapp.status\ndata: ${JSON.stringify(accountStatus(account))}\n\n`
      );
    }
  }

  const heartbeat = setInterval(() => {
    try {
      res.write(': heartbeat\n\n');
    } catch {
      clearInterval(heartbeat);
      sseClients.delete(client);
    }
  }, 25000);

  req.on('close', () => {
    clearInterval(heartbeat);
    sseClients.delete(client);
  });
});

// ---------------------------------------------------------------------
// Routes internes : gestion des comptes
// ---------------------------------------------------------------------

app.get('/internal/whatsapp/accounts', requireInternalToken, (_req, res) => {
  res.json({
    accounts: [...accounts.values()].map(accountStatus)
  });
});

app.post(
  '/internal/whatsapp/accounts/:accountId/connect',
  requireInternalToken,
  async (req, res) => {
    const { accountId } = req.params;

    if (!validAccountId(accountId)) {
      return res.status(400).json({ error: 'invalid_account_id' });
    }

    try {
      const account = await startAccount(accountId);

      res.json({
        accepted: true,
        ...accountStatus(account)
      });

    } catch (error) {
      res.status(502).json({
        error: 'whatsapp_connection_failed',
        message: error.message
      });
    }
  }
);

app.get(
  '/internal/whatsapp/accounts/:accountId/status',
  requireInternalToken,
  (req, res) => {
    const account = getAccount(req.params.accountId);

    if (!account) {
      return res.status(400).json({ error: 'invalid_account_id' });
    }

    res.json(accountStatus(account));
  }
);

app.get(
  '/internal/whatsapp/accounts/:accountId/qr',
  requireInternalToken,
  (req, res) => {
    const account = getAccount(req.params.accountId);

    res.set('Cache-Control', 'no-store');

    if (!account) {
      return res.status(400).json({ error: 'invalid_account_id' });
    }

    if (!account.qr) {
      return res.status(404).json({
        error: 'qr_not_available',
        status: account.status
      });
    }

    res.json({
      accountId: account.id,
      status: account.status,
      qr: account.qr,
      qrDataUrl: account.qrDataUrl
    });
  }
);

// ---------------------------------------------------------------------
// Envoi d'un message pour un compte
// ---------------------------------------------------------------------

app.post(
  '/internal/whatsapp/accounts/:accountId/send',
  requireInternalToken,
  async (req, res) => {
    const account = getAccount(req.params.accountId);

    if (!account) {
      return res.status(400).json({ error: 'invalid_account_id' });
    }

    const { to, message } = req.body || {};

    if (typeof message !== 'string' || !message.trim()) {
      return res.status(400).json({
        error: 'message_required'
      });
    }

    const recipient = normalizeRecipient(to);

    if (!recipient) {
      return res.status(400).json({
        error: 'invalid_recipient'
      });
    }

    if (account.status !== 'connected' || !account.socket) {
      return res.status(503).json({
        error: 'whatsapp_not_ready',
        status: account.status
      });
    }

    try {
      const result = await account.socket.sendMessage(
        recipient,
        { text: message.trim() }
      );

      const messageId = result?.key?.id || null;

      let persisted = false;

      if (messageId) {
        persisted = await persistWhatsAppMessage({
          accountId: account.id,
          messageId,
          remoteJid: result?.key?.remoteJid || recipient,
          participant: result?.key?.participant || null,
          participantPn: result?.key?.participantPn || null,
          fromMe: true,
          pushName: null,
          body: message.trim(),
          timestamp: Number(
            result?.messageTimestamp || Math.floor(Date.now() / 1000)
          ),
          messageType: 'chat'
        });
      }

      console.log(
        `[${account.id}] Message sortant ${messageId || 'sans ID'} : ${persisted ? 'persisté' : 'non persisté'}`
      );

      res.json({
        accepted: true,
        accountId: account.id,
        messageId,
        persisted
      });

    } catch (error) {
      console.error(
        `[${account.id}] Échec de l'envoi :`,
        error.message
      );

      res.status(502).json({
        error: 'whatsapp_send_failed'
      });
    }
  }
);

// ---------------------------------------------------------------------
// Compatibilité avec les anciennes routes : compte par défaut
// ---------------------------------------------------------------------

app.get(
  '/internal/whatsapp/status',
  requireInternalToken,
  (_req, res) => {
    const account = getAccount(DEFAULT_ACCOUNT_ID);

    res.json({
      status: account.status,
      qrAvailable: Boolean(account.qr),
      accountId: account.id
    });
  }
);

app.get(
  '/internal/whatsapp/qr',
  requireInternalToken,
  (req, res) => {
    const account = getAccount(DEFAULT_ACCOUNT_ID);

    res.set('Cache-Control', 'no-store');

    if (!account.qr) {
      return res.status(404).json({
        error: 'qr_not_available',
        status: account.status
      });
    }

    res.json({
      status: account.status,
      qr: account.qr,
      qrDataUrl: account.qrDataUrl
    });
  }
);

app.post(
  '/internal/whatsapp/send',
  requireInternalToken,
  async (req, res) => {
    const account = getAccount(DEFAULT_ACCOUNT_ID);
    const { to, message } = req.body || {};

    const recipient = normalizeRecipient(to);

    if (!recipient || typeof message !== 'string' || !message.trim()) {
      return res.status(400).json({
        error: 'to_and_message_required'
      });
    }

    if (account.status !== 'connected' || !account.socket) {
      return res.status(503).json({
        error: 'whatsapp_not_ready',
        status: account.status
      });
    }

    try {
      const result = await account.socket.sendMessage(
        recipient,
        { text: message.trim() }
      );

      res.json({
        accepted: true,
        messageId: result?.key?.id || null
      });

    } catch (error) {
      console.error(
        `[${account.id}] Échec de l'envoi :`,
        error.message
      );

      res.status(502).json({
        error: 'whatsapp_send_failed'
      });
    }
  }
);

// ---------------------------------------------------------------------
// Restauration des sessions existantes
// ---------------------------------------------------------------------

async function restoreAccounts() {
  await fs.mkdir(ACCOUNTS_PATH, {
    recursive: true,
    mode: 0o700
  });

  const entries = await fs.readdir(ACCOUNTS_PATH, {
    withFileTypes: true
  });

  for (const entry of entries) {
    if (!entry.isDirectory() || !validAccountId(entry.name)) {
      continue;
    }

    console.log(`[${entry.name}] Restauration de la session...`);

    startAccount(entry.name).catch(error => {
      console.error(
        `[${entry.name}] Restauration impossible :`,
        error.message
      );
    });
  }
}

// ---------------------------------------------------------------------
// Arrêt propre du serveur
// ---------------------------------------------------------------------

async function shutdown(signal) {
  console.log(`Arrêt de la passerelle (${signal})...`);

  for (const account of accounts.values()) {
    account.manualLogout = true;

    if (account.reconnectTimer) {
      clearTimeout(account.reconnectTimer);
      account.reconnectTimer = null;
    }

    try {
      account.socket?.end(undefined);
    } catch {
      // Arrêt best effort.
    }
  }

  for (const client of sseClients) {
    try {
      client.response.end();
    } catch {
      // Arrêt best effort.
    }
  }

  sseClients.clear();

  if (server) {
    await new Promise(resolve => server.close(resolve));
  }

  process.exit(0);
}

// ---------------------------------------------------------------------
// Démarrage
// ---------------------------------------------------------------------

async function main() {
  await fs.mkdir(ACCOUNTS_PATH, {
    recursive: true,
    mode: 0o700
  });

  server = app.listen(PORT, '0.0.0.0', () => {
    console.log(`Passerelle Baileys démarrée sur le port ${PORT}.`);
  });

  await restoreAccounts();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

main().catch(error => {
  console.error('Erreur fatale au démarrage :', error);
  process.exit(1);
});
