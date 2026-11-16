'use strict';

const accountId = window.WHATSAPP_ACCOUNT_ID;
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

const conversationList = document.getElementById('conversationList');
const conversationSearch = document.getElementById('conversationSearch');
const chatHeader = document.getElementById('chatHeader');
const contactName = document.getElementById('contactName');
const contactNumber = document.getElementById('contactNumber');
const messageList = document.getElementById('messageList');
const messageForm = document.getElementById('messageForm');
const messageInput = document.getElementById('messageInput');
const sendButton = document.getElementById('sendButton');
const messageFeedback = document.getElementById('messageFeedback');

let conversations = [];
let selectedConversation = null;
let lastMessageSignature = '';
let loading = false;
let sending = false;

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
    const headers = { ...(options.headers || {}) };

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

function conversationsUrl() {
    return `/api/whatsapp/accounts/${encodeURIComponent(accountId)}/conversations`;
}

function messagesUrl(conversationId) {
    return `${conversationsUrl()}/${encodeURIComponent(conversationId)}/messages`;
}

function displayName(conversation) {
    return conversation.display_name
        || conversation.phone_number
        || conversation.whatsapp_id
        || 'Contact inconnu';
}

function formatTime(value) {
    if (!value) return '';

    const date = String(value);
    return date.length >= 16 ? date.substring(11, 16) : date;
}

function renderConversations() {
    const search = conversationSearch.value.trim().toLowerCase();

    const filtered = conversations.filter(conversation => {
        const name = displayName(conversation).toLowerCase();
        const phone = String(conversation.phone_number || '').toLowerCase();
        const preview = String(conversation.last_message || '').toLowerCase();

        return name.includes(search)
            || phone.includes(search)
            || preview.includes(search);
    });

    if (!filtered.length) {
        conversationList.innerHTML = `
            <div class="empty-state">
                ${conversations.length
                    ? 'Aucune discussion ne correspond à votre recherche.'
                    : 'Aucune discussion disponible.'}
            </div>`;
        return;
    }

    const scrollTop = conversationList.scrollTop;

    conversationList.innerHTML = filtered.map(conversation => {
        const active = selectedConversation
            && Number(selectedConversation.conversation_id) === Number(conversation.conversation_id);

        const preview = conversation.last_message
            || (conversation.last_message_type
                ? `[${conversation.last_message_type}]`
                : 'Aucun message');

        return `
            <button type="button"
                class="conversation-item ${active ? 'active' : ''}"
                data-conversation-id="${Number(conversation.conversation_id)}">

                <div class="conversation-item-header">
                    <strong>${escapeHtml(displayName(conversation))}</strong>
                    <span>${escapeHtml(formatTime(conversation.last_message_at))}</span>
                </div>

                <div class="conversation-item-preview">
                    ${conversation.last_direction === 'outbound' ? 'Vous : ' : ''}
                    ${escapeHtml(preview)}
                </div>
            </button>`;
    }).join('');

    conversationList.scrollTop = scrollTop;
}

function renderMessages(messages) {
    const signature = messages.map(message =>
        `${message.id}:${message.body}`
    ).join('|');

    const shouldScroll = signature !== lastMessageSignature;
    lastMessageSignature = signature;

    if (!messages.length) {
        messageList.innerHTML = `
            <div class="empty-state">
                Aucun message dans cette discussion.
            </div>`;
        return;
    }

    messageList.innerHTML = messages.map(message => {
        const outgoing = message.direction === 'outbound';
        const body = message.body || `[${message.message_type || 'message'}]`;
        const time = formatTime(
            message.whatsapp_timestamp || message.created_at
        );

        return `
            <div class="message-row ${outgoing ? 'outgoing' : 'incoming'}">
                <div class="message-bubble">
                    <div class="message-body">${escapeHtml(body)}</div>
                    <div class="message-time">${escapeHtml(time)}</div>
                </div>
            </div>`;
    }).join('');

    if (shouldScroll) {
        messageList.scrollTop = messageList.scrollHeight;
    }
}

async function loadConversations() {
    const data = await api(conversationsUrl());

    conversations = (data.conversations || []).filter(
        conversation => conversation.whatsapp_id !== 'status@broadcast'
    );

    if (selectedConversation) {
        selectedConversation = conversations.find(
            conversation =>
                Number(conversation.conversation_id)
                === Number(selectedConversation.conversation_id)
        ) || null;
    }

    renderConversations();

    if (!selectedConversation && conversations.length) {
        await selectConversation(conversations[0].conversation_id);
    }
}

async function loadMessages() {
    if (!selectedConversation) return;

    const data = await api(
        messagesUrl(selectedConversation.conversation_id)
    );

    renderMessages(data.messages || []);
}

async function selectConversation(conversationId) {
    selectedConversation = conversations.find(
        conversation =>
            Number(conversation.conversation_id) === Number(conversationId)
    );

    if (!selectedConversation) return;

    lastMessageSignature = '';

    contactName.textContent = displayName(selectedConversation);
    contactNumber.textContent =
        selectedConversation.phone_number
        || selectedConversation.whatsapp_id
        || '';

    messageInput.disabled = false;
    sendButton.disabled = false;
    messageFeedback.textContent = '';

    renderConversations();

    messageList.innerHTML = `
        <div class="empty-state">Chargement des messages...</div>`;

    try {
        await loadMessages();
        messageInput.focus();
    } catch (error) {
        messageList.innerHTML = `
            <div class="empty-state">${escapeHtml(error.message)}</div>`;
    }
}

conversationList.addEventListener('click', event => {
    const button = event.target.closest('[data-conversation-id]');

    if (button) {
        selectConversation(button.dataset.conversationId);
    }
});

conversationSearch.addEventListener('input', renderConversations);

messageForm.addEventListener('submit', async event => {
    event.preventDefault();

    const message = messageInput.value.trim();

    if (!selectedConversation || !message || sending) return;

    const recipient = selectedConversation.whatsapp_id;

    if (!recipient || recipient === 'status@broadcast') {
        messageFeedback.textContent = 'Destinataire WhatsApp invalide.';
        return;
    }

    sending = true;
    sendButton.disabled = true;
    messageFeedback.textContent = 'Envoi en cours...';

    try {
        await api(
            `/api/whatsapp/accounts/${encodeURIComponent(accountId)}/send`,
            {
                method: 'POST',
                body: JSON.stringify({
                    recipient,
                    message
                })
            }
        );

        messageInput.value = '';
        messageFeedback.textContent = 'Message envoyé.';

        await loadMessages();
        await loadConversations();

        messageInput.focus();
    } catch (error) {
        messageFeedback.textContent = error.message;
    } finally {
        sending = false;
        sendButton.disabled = !selectedConversation;
    }
});

async function refresh() {
    if (loading || sending) return;

    loading = true;

    try {
        await loadConversations();

        if (selectedConversation) {
            await loadMessages();
        }
    } catch (error) {
        console.error('Actualisation des discussions :', error);
    } finally {
        loading = false;
    }
}

async function initialize() {
    try {
        await loadConversations();
    } catch (error) {
        conversationList.innerHTML = `
            <div class="empty-state">${escapeHtml(error.message)}</div>`;
    }
}

initialize();
setInterval(refresh, 5000);
