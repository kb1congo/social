'use strict';

const express = require('express');
const crypto = require('node:crypto');
const { Client, LocalAuth } = require('whatsapp-web.js');

const app = express();
app.disable('x-powered-by');
app.use(express.json({ limit: '1mb' }));

const PORT = Number(process.env.PORT || 3000);
const INTERNAL_API_TOKEN = process.env.INTERNAL_API_TOKEN || '';
const DATA_PATH = process.env.WHATSAPP_DATA_PATH || '/data/wwebjs';
const CLIENT_ID = process.env.WHATSAPP_CLIENT_ID || 'personal-gateway';

if (!INTERNAL_API_TOKEN) {
  throw new Error('INTERNAL_API_TOKEN est obligatoire.');
}

function safeEqual(a, b) {
  const left = Buffer.from(String(a));
  const right = Buffer.from(String(b));
  return left.length === right.length && crypto.timingSafeEqual(left, right);
}


function requireInternalToken(req, res, next) {
  const supplied = (req.headers.authorization || '').replace(/^Bearer\s+/i, '');

  if (!safeEqual(supplied, INTERNAL_API_TOKEN)) {
    return res.status(401).json({ error: 'unauthorized' });
  }

  next();
}

const clients = new Set();
let whatsappState = 'initializing';
let lastQr = null;

const client = new Client({
  authStrategy: new LocalAuth({
    clientId: CLIENT_ID,
    dataPath: DATA_PATH
  }),
  puppeteer: {
    headless: true,
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  }
});

function publish(event, data) {
  const payload = `event: ${event}\\ndata: ${JSON.stringify(data)}\\n\\n`;
  for (const response of clients) response.write(payload);
}

client.on('qr', (qr) => {
  lastQr = qr;
  whatsappState = 'qr';
  publish('whatsapp.status', { status: whatsappState });
  // Le QR brut n'est volontairement pas écrit dans les logs.
  console.log('Un QR code WhatsApp est disponible (consultation via endpoint interne à implémenter).');
});

client.on('authenticated', () => {
  whatsappState = 'authenticated';
  lastQr = null;
  publish('whatsapp.status', { status: whatsappState });
});

client.on('ready', () => {
  whatsappState = 'ready';
  lastQr = null;
  publish('whatsapp.status', { status: whatsappState });
  console.log('Client WhatsApp prêt.');
});

client.on('auth_failure', (message) => {
  whatsappState = 'auth_failure';
  publish('whatsapp.status', { status: whatsappState });
  console.error('Échec d’authentification WhatsApp :', message);
});

client.on('disconnected', (reason) => {
  whatsappState = 'disconnected';
  publish('whatsapp.status', { status: whatsappState, reason });
  console.warn('Client WhatsApp déconnecté :', reason);
});

client.on('message', (message) => {
  // À compléter : transmettre le message à l'API PHP interne avec le jeton serveur.
  // Ne pas exposer directement les événements WhatsApp aux utilisateurs non autorisés.
  publish('whatsapp.message.received', {
    id: message.id?._serialized || null,
    from: message.from,
    body: message.body,
    timestamp: message.timestamp
  });
});

app.get('/health', (_req, res) => {
  res.json({ status: 'ok', service: 'whatsapp-gateway', whatsapp: whatsappState });
});

app.get('/events', (req, res) => {
  // IMPORTANT : endpoint de développement. Ajouter la validation d'un jeton SSE
  // court, signé par PHP, avant toute exposition à plusieurs utilisateurs.
  const token = req.query.token;
  if (!token || !safeEqual(token, INTERNAL_API_TOKEN)) {
    return res.status(401).end('Unauthorized');
  }

  res.set({
    'Content-Type': 'text/event-stream',
    'Cache-Control': 'no-cache, no-transform',
    'Connection': 'keep-alive',
    'X-Accel-Buffering': 'no'
  });
  res.flushHeaders?.();
  res.write(`event: connected\\ndata: ${JSON.stringify({ status: 'connected' })}\\n\\n`);
  clients.add(res);

  const heartbeat = setInterval(() => res.write(': heartbeat\\n\\n'), 25000);
  req.on('close', () => {
    clearInterval(heartbeat);
    clients.delete(res);
  });
});

app.get('/internal/whatsapp/status', requireInternalToken, (_req, res) => {
  res.json({ status: whatsappState, qrAvailable: Boolean(lastQr) });
});

app.get('/internal/whatsapp/qr', requireInternalToken, (_req, res) => {
  res.set('Cache-Control', 'no-store');

  if (!lastQr) {
    return res.status(404).json({
      error: 'qr_not_available',
      status: whatsappState
    });
  }

  res.json({
    status: whatsappState,
    qr: lastQr
  });
});

app.post('/internal/whatsapp/send', requireInternalToken, async (req, res) => {
  const { to, message } = req.body || {};
  if (whatsappState !== 'ready') {
    return res.status(503).json({ error: 'whatsapp_not_ready', status: whatsappState });
  }
  if (typeof to !== 'string' || typeof message !== 'string' || !to.trim() || !message.trim()) {
    return res.status(400).json({ error: 'to_and_message_required' });
  }

  try {
    const chatId = to.includes('@c.us') ? to : `${to.replace(/\\D/g, '')}@c.us`;
    const result = await client.sendMessage(chatId, message);
    res.json({ accepted: true, messageId: result.id?._serialized || null });
  } catch (error) {
    console.error('Échec envoi WhatsApp :', error.message);
    res.status(502).json({ error: 'whatsapp_send_failed' });
  }
});

const server = app.listen(PORT, '0.0.0.0', () => {
  console.log(`Passerelle démarrée sur le port ${PORT}.`);
});

client.initialize().catch((error) => {
  whatsappState = 'initialization_error';
  console.error('Impossible d’initialiser WhatsApp :', error.message);
});

async function shutdown() {
  console.log('Arrêt de la passerelle...');
  server.close();
  try { await client.destroy(); } catch (_) {}
  process.exit(0);
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
