import express from 'express';
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import {
    connectAccount,
    getAccountStatus,
    disconnectAccount,
    sendTextMessage
} from './session-manager.js';

const app = express();
const port = Number(process.env.PORT || 3000);
const apiSecret = process.env.NODE_API_SECRET;

app.use(express.json({ limit: '1mb' }));

app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'whatsapp-node'
    });
});

function authenticateInternalRequest(req, res, next) {
    const authorization = req.get('authorization') || '';
    const match = authorization.match(/^Bearer\s+(.+)$/i);

    if (!apiSecret || !match) {
        return res.status(401).json({
            status: 'error',
            message: 'Authentification interne requise.'
        });
    }

    const received = Buffer.from(match[1]);
    const expected = Buffer.from(apiSecret);

    if (
        received.length !== expected.length ||
        !crypto.timingSafeEqual(received, expected)
    ) {
        return res.status(401).json({
            status: 'error',
            message: 'Authentification interne invalide.'
        });
    }

    next();
}

app.use('/internal', authenticateInternalRequest);

function validateAccountId(req, res, next) {
    if (!/^[1-9]\d*$/.test(req.params.accountId)) {
        return res.status(400).json({
            status: 'error',
            message: 'Identifiant de compte invalide.'
        });
    }

    next();
}

app.get('/internal/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'whatsapp-node',
        authentication: 'valid'
    });
});

app.post(
    '/internal/accounts/:accountId/connect',
    validateAccountId,
    async (req, res) => {
        try {
            const result = await connectAccount(req.params.accountId);
            res.status(202).json({
                status: 'success',
                data: result
            });
        } catch (error) {
            res.status(500).json({
                status: 'error',
                message: 'Impossible de démarrer la connexion WhatsApp.'
            });
        }
    }
);

app.get(
    '/internal/accounts/:accountId/status',
    validateAccountId,
    (req, res) => {
        res.json({
            status: 'success',
            data: getAccountStatus(req.params.accountId)
        });
    }
);

app.post(
    '/internal/accounts/:accountId/disconnect',
    validateAccountId,
    async (req, res) => {
        try {
            const result = await disconnectAccount(
                req.params.accountId,
                false
            );

            res.json({
                status: 'success',
                data: result
            });
        } catch (error) {
            res.status(500).json({
                status: 'error',
                message: 'Impossible de déconnecter le compte WhatsApp.'
            });
        }
    }
);

app.post(
    '/internal/accounts/:accountId/logout',
    validateAccountId,
    async (req, res) => {
        try {
            const result = await disconnectAccount(
                req.params.accountId,
                true
            );

            res.json({
                status: 'success',
                data: result
            });
        } catch (error) {
            res.status(500).json({
                status: 'error',
                message: 'Impossible de fermer la session WhatsApp.'
            });
        }
    }
);


app.post(
    '/internal/accounts/:accountId/messages/text',
    validateAccountId,
    async (req, res) => {
        try {
            const { remoteJid, text } = req.body || {};

            if (typeof remoteJid !== 'string' || typeof text !== 'string') {
                return res.status(400).json({
                    status: 'error',
                    message: 'Destinataire et texte requis.'
                });
            }

            const result = await sendTextMessage(
                req.params.accountId,
                remoteJid,
                text
            );

            return res.status(201).json({
                status: 'success',
                data: result
            });
        } catch (error) {
            console.error('[SEND MESSAGE]', error.message);

            return res.status(400).json({
                status: 'error',
                message: error.message
            });
        }
    }
);


async function restoreSavedAccounts() {
    const phpUrl = process.env.PHP_INTERNAL_URL;

    if (!phpUrl || !apiSecret) {
        console.error('[RESTORE] Configuration PHP ou secret manquant.');
        return;
    }

    let accountIds = null;

    for (let attempt = 1; attempt <= 5; attempt++) {
        try {
            const response = await fetch(
                `${phpUrl}/internal/accounts/restore`,
                {
                    headers: {
                        Authorization: `Bearer ${apiSecret}`
                    },
                    signal: AbortSignal.timeout(10000)
                }
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.status !== 'ok' || !Array.isArray(data.account_ids)) {
                throw new Error('Réponse PHP invalide.');
            }

            accountIds = data.account_ids;
            break;
        } catch (error) {
            console.error(
                `[RESTORE] Tentative ${attempt}/5 échouée : ${error.message}`
            );

            if (attempt < 5) {
                await new Promise(resolve => setTimeout(resolve, 3000));
            }
        }
    }

    if (accountIds === null) {
        console.error('[RESTORE] Impossible de récupérer les comptes auprès de PHP.');
        return;
    }

    console.log(`[RESTORE] ${accountIds.length} compte(s) à vérifier.`);

    for (const accountId of accountIds) {
        const id = String(accountId);

        if (!/^[1-9]\d*$/.test(id)) {
            console.error('[RESTORE] Identifiant invalide ignoré.');
            continue;
        }

        const credentialsPath = path.join(
            process.cwd(),
            'sessions',
            id,
            'creds.json'
        );

        try {
            await fs.access(credentialsPath);
        } catch {
            console.log(`[RESTORE] Compte ${id} ignoré : identifiants absents.`);
            continue;
        }

        try {
            await connectAccount(id);
            console.log(`[RESTORE] Restauration démarrée pour le compte ${id}.`);
        } catch (error) {
            console.error(
                `[RESTORE] Échec du compte ${id} : ${error.message}`
            );
        }
    }
}

app.listen(port, '0.0.0.0', () => {
    console.log(`WhatsApp service démarré sur le port ${port}`);
    void restoreSavedAccounts();
});
