
'use strict';

const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
const accountList = document.getElementById('accountList');
const globalMessage = document.getElementById('globalMessage');

let redirectWhenConnected = null;

const labels = {
    idle: 'En attente',
    connecting: 'Connexion en cours',
    qr: 'QR code disponible',
    connected: 'Connecté',
    disconnected: 'Déconnecté',
    error: 'Erreur'
};

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[char]);
}

async function api(url, options = {}) {
    const headers = {
        ...(options.headers || {})
    };

    if (options.method && options.method !== 'GET') {
        headers['X-CSRF-Token'] = csrfToken;
    }

    if (options.body) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        ...options,
        headers,
        credentials: 'same-origin'
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(data.error || 'Erreur de communication avec le serveur.');
    }

    return data;
}

function renderAccounts(accounts) {
    if (!accounts.length) {
        accountList.innerHTML = '<div class="empty">Aucun compte WhatsApp enregistré. Ajoutez votre premier numéro pour commencer.</div>';
        return;
    }

    accountList.innerHTML = accounts.map(account => {
        const status = account.status || 'idle';
        const statusClass = ['connected', 'qr', 'error'].includes(status) ? status : '';
        const accountId = escapeHtml(account.account_id);
        const name = escapeHtml(account.display_name);
        const phone = escapeHtml(account.phone_number || 'Numéro non encore identifié');

        return `
            <article class="account" data-account="${accountId}">
                <div class="account-top">
                    <div>
                        <div class="account-name">${name}</div>
                        <div class="phone">${phone}</div>
                    </div>
                    <span class="status ${statusClass}">${labels[status] || escapeHtml(status)}</span>
                </div>

                <div class="account-actions">
                    <button type="button" data-action="connect">Connecter</button>
                    <button type="button" data-action="qr">Afficher QR</button>
                </div>

                <div class="qr-area" hidden></div>

                ${status === 'connected' ? `
                    <form class="send-form">
                        <h3>Envoyer un message</h3>
                        <input type="tel" name="recipient"
                            placeholder="Numéro du destinataire (ex. 242066123456)" required>
                        <textarea name="message" maxlength="4096"
                            placeholder="Saisissez votre message..." required></textarea>
                        <button class="primary" type="submit">Envoyer le message</button>
                        <div class="send-feedback" aria-live="polite"></div>
                    </form>
                ` : `
                    <p class="send-feedback">Connectez ce compte pour pouvoir envoyer des messages.</p>
                `}
            </article>
        `;
    }).join('');
}

function updateAccountStatus(account) {
    const card = [...document.querySelectorAll('.account')]
        .find(item => item.dataset.account === account.account_id);

    if (!card) return;

    const status = account.status || 'idle';
    const statusElement = card.querySelector('.status');
    const phoneElement = card.querySelector('.phone');

    statusElement.textContent = labels[status] || status;
    statusElement.className = 'status';

    if (['connected', 'qr', 'error'].includes(status)) {
        statusElement.classList.add(status);
    }

    if (phoneElement && account.phone_number) {
        phoneElement.textContent = account.phone_number;
    }

    if (status === 'connected' && !card.querySelector('.send-form')) {
        const oldFeedback = card.querySelector('.send-feedback');

        if (oldFeedback) {
            oldFeedback.remove();
        }

        const form = document.createElement('form');
        form.className = 'send-form';
        form.innerHTML = `
            <h3>Envoyer un message</h3>
            <input type="tel" name="recipient"
                placeholder="Numéro du destinataire (ex. 242066123456)" required>
            <textarea name="message" maxlength="4096"
                placeholder="Saisissez votre message..." required></textarea>
            <button class="primary" type="submit">Envoyer le message</button>
            <div class="send-feedback" aria-live="polite"></div>
        `;

        card.appendChild(form);
    }
}

async function loadAccounts() {
    try {
        const data = await api('/api/whatsapp/accounts');
        const accounts = data.accounts || [];

        if (!document.querySelector('.account')) {
            renderAccounts(accounts);
            return;
        }

        accounts.forEach(updateAccountStatus);

        const connectedAccount = accounts.find(account =>
            account.account_id === redirectWhenConnected &&
            account.status === 'connected'
        );

        if (connectedAccount) {
            const accountId = redirectWhenConnected;
            redirectWhenConnected = null;
            window.location.href = `/dialog.php?account=${encodeURIComponent(accountId)}`;
        }

    } catch (error) {
        console.error('Actualisation des comptes :', error);
    }
}

async function connectAccount(accountId) {
    redirectWhenConnected = accountId;  
    try {
        await api(`/api/whatsapp/accounts/${encodeURIComponent(accountId)}/connect`, {
            method: 'POST',
            body: JSON.stringify({})
        });

        await loadAccounts();
        await showQr(accountId);
    } catch (error) {
        redirectWhenConnected = null;
        globalMessage.textContent = error.message;
        globalMessage.className = 'message error';
    }
}

async function showQr(accountId) {
    const card = [...document.querySelectorAll('.account')]
        .find(item => item.dataset.account === accountId);

    if (!card) return;

    const area = card.querySelector('.qr-area');
    area.hidden = false;
    area.innerHTML = '<p>Récupération du QR code...</p>';

    try {
        const data = await api(`/api/whatsapp/accounts/${encodeURIComponent(accountId)}/qr`);

        if (data.qrDataUrl) {
            area.innerHTML = `<img src="${escapeHtml(data.qrDataUrl)}" alt="QR code WhatsApp"><p>Scannez ce QR code avec WhatsApp sur votre téléphone.</p>`;
        } else if (data.qr) {
            area.innerHTML = '<p>QR code disponible, mais son format image n’est pas fourni par le serveur.</p>';
        } else {
            area.innerHTML = '<p>Aucun QR code disponible pour le moment. Réessayez dans quelques secondes.</p>';
        }
    } catch (error) {
        area.innerHTML = `<p class="error">${escapeHtml(error.message)}</p>`;
    }
}

document.getElementById('addAccountForm').addEventListener('submit', async event => {
    event.preventDefault();

    const input = document.getElementById('displayName');
    const displayName = input.value.trim();

    if (!displayName) return;

    globalMessage.textContent = 'Création du compte et démarrage de la connexion...';

    try {
        const result = await api('/api/whatsapp/accounts', {
            method: 'POST',
            body: JSON.stringify({ displayName })
        });

        input.value = '';
        globalMessage.textContent = 'Compte ajouté. Préparation de la connexion WhatsApp...';

        await loadAccounts();

        if (result.accountId) {
            redirectWhenConnected = result.accountId;
            await showQr(result.accountId);
        }
    } catch (error) {
        globalMessage.textContent = error.message;
        globalMessage.className = 'message error';
    }
});

accountList.addEventListener('submit', async event => {
    const form = event.target.closest('.send-form');
    if (!form) return;

    event.preventDefault();

    const card = form.closest('.account');
    const accountId = card.dataset.account;
    const recipient = form.elements.recipient.value.trim();
    const message = form.elements.message.value.trim();
    const button = form.querySelector('button[type="submit"]');
    const feedback = form.querySelector('.send-feedback');

    if (!recipient || !message) {
        feedback.textContent = 'Le destinataire et le message sont obligatoires.';
        feedback.className = 'send-feedback error';
        return;
    }

    button.disabled = true;
    button.textContent = 'Envoi en cours...';
    feedback.textContent = '';

    try {
        const result = await api(
            `/api/whatsapp/accounts/${encodeURIComponent(accountId)}/send`,
            {
                method: 'POST',
                body: JSON.stringify({ recipient, message })
            }
        );

        if (result.accepted) {
            feedback.textContent = 'Message accepté par WhatsApp.';
            feedback.className = 'send-feedback success';
            form.elements.message.value = '';
        } else {
            feedback.textContent = 'L’envoi n’a pas été confirmé.';
            feedback.className = 'send-feedback error';
        }
    } catch (error) {
        feedback.textContent = error.message;
        feedback.className = 'send-feedback error';
    } finally {
        button.disabled = false;
        button.textContent = 'Envoyer le message';
    }
});

accountList.addEventListener('click', event => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;

    const card = button.closest('.account');
    if (!card) return;

    const accountId = card.dataset.account;

    if (button.dataset.action === 'connect') connectAccount(accountId);
    if (button.dataset.action === 'qr') showQr(accountId);
});

document.getElementById('refreshButton').addEventListener('click', loadAccounts);

loadAccounts();

setInterval(async () => {
    const pendingAccounts = [...document.querySelectorAll('.account')]
        .filter(card => {
            const status = card.querySelector('.status');
            return status && status.textContent !== 'Connecté';
        });

    if (pendingAccounts.length > 0) {
        await loadAccounts();
    }
}, 5000);
