import express from 'express';
import crypto from 'node:crypto';

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

app.get('/internal/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'whatsapp-node',
        authentication: 'valid'
    });
});

app.listen(port, '0.0.0.0', () => {
    console.log(`WhatsApp service démarré sur le port ${port}`);
});
