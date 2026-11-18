(function () {
    var TOKEN_KEY = 'whatsapp_gateway_token';
    var token = sessionStorage.getItem(TOKEN_KEY);

    if (!token) {
        location.replace('/index.html');
        return;
    }

    var app = document.getElementById('app');
    var list = document.getElementById('conversationList');
    var messagesBox = document.getElementById('messages');
    var search = document.getElementById('search');
    var messageInput = document.getElementById('messageInput');
    var sendButton = document.getElementById('sendButton');
    var messageForm = document.getElementById('messageForm');
    var notice = document.getElementById('notice');

    var conversations = [];
    var selectedConversation = null;
    var loadingMessages = false;
    var sending = false;
    var refreshing = false;

    function escapeText(value) {
        return value === null || typeof value === 'undefined'
            ? ''
            : String(value);
    }

    function conversationName(conversation) {
        return conversation.display_name ||
            conversation.push_name ||
            conversation.subject ||
            conversation.phone_number ||
            conversation.remote_jid ||
            'Conversation';
    }

    function initials(name) {
        name = String(name || '').replace(/^\s+|\s+$/g, '');
        return name ? name.charAt(0).toUpperCase() : '?';
    }

    function showNotice(text) {
        notice.textContent = text;
        notice.style.display = 'block';
    }

    function hideNotice() {
        notice.textContent = '';
        notice.style.display = 'none';
    }

    function logout() {
        sessionStorage.removeItem(TOKEN_KEY);
        location.replace('/index.html');
    }

    function api(path, options, callback) {
        options = options || {};

        var xhr = new XMLHttpRequest();

        xhr.open(options.method || 'GET', path, true);
        xhr.setRequestHeader('Authorization', 'Bearer ' + token);
        xhr.setRequestHeader('Content-Type', 'application/json');

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            var result = {};

            try {
                result = JSON.parse(xhr.responseText || '{}');
            } catch (e) {
                callback(new Error('Réponse invalide du serveur.'));
                return;
            }

            if (xhr.status === 401) {
                logout();
                callback(new Error('Session expirée.'));
                return;
            }

            if (xhr.status < 200 || xhr.status >= 300 ||
                result.status === 'error') {
                callback(new Error(
                    result.message || 'Une erreur est survenue.'
                ));
                return;
            }

            callback(null, result);
        };

        xhr.onerror = function () {
            callback(new Error('Impossible de contacter le serveur.'));
        };

        xhr.send(
            typeof options.body !== 'undefined' ? options.body : null
        );
    }

    function formatTime(value) {
        if (!value) {
            return '';
        }

        var date = new Date(String(value).replace(' ', 'T'));

        if (isNaN(date.getTime())) {
            return '';
        }

        var hours = date.getHours();
        var minutes = date.getMinutes();

        return (hours < 10 ? '0' : '') + hours + ':' +
            (minutes < 10 ? '0' : '') + minutes;
    }

    function clearElement(element) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function renderConversations() {
        var query = search.value.replace(/^\s+|\s+$/g, '').toLowerCase();
        var filtered = [];
        var i;

        for (i = 0; i < conversations.length; i++) {
            var conversation = conversations[i];

            var searchable = [
                conversationName(conversation),
                conversation.phone_number,
                conversation.last_message_body
            ].join(' ').toLowerCase();

            if (searchable.indexOf(query) !== -1) {
                filtered.push(conversation);
            }
        }

        clearElement(list);

        if (filtered.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'empty';

            empty.textContent = conversations.length
                ? 'Aucune conversation ne correspond à votre recherche.'
                : 'Aucune conversation pour le moment. Les nouveaux échanges apparaîtront ici.';

            list.appendChild(empty);
            return;
        }

        for (i = 0; i < filtered.length; i++) {
            (function (conversation) {
                var item = document.createElement('div');

                item.className = 'conversation';

                if (selectedConversation &&
                    selectedConversation.id == conversation.id) {
                    item.className += ' active';
                }

                var avatar = document.createElement('div');
                avatar.className = 'avatar';
                avatar.textContent = initials(conversationName(conversation));

                var info = document.createElement('div');
                info.className = 'conversation-info';

                var top = document.createElement('div');
                top.className = 'conversation-top';

                var name = document.createElement('div');
                name.className = 'conversation-name';
                name.textContent = conversationName(conversation);

                var time = document.createElement('div');
                time.className = 'conversation-time';
                time.textContent = formatTime(conversation.last_message_at);

                var preview = document.createElement('div');
                preview.className = 'conversation-preview';

                preview.textContent =
                    (Number(conversation.last_message_from_me) === 1
                        ? 'Vous : '
                        : '') +
                    (conversation.last_message_body || 'Aucun message');

                top.appendChild(name);
                top.appendChild(time);

                info.appendChild(top);
                info.appendChild(preview);

                item.appendChild(avatar);
                item.appendChild(info);

                item.addEventListener('click', function () {
                    selectConversation(conversation);
                });

                list.appendChild(item);
            })(filtered[i]);
        }
    }

    function renderMessages(messages, keepScroll) {
        var nearBottom = messagesBox.scrollHeight -
            messagesBox.scrollTop -
            messagesBox.clientHeight < 100;

        clearElement(messagesBox);

        if (!messages || messages.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'Aucun message dans cette conversation.';
            messagesBox.appendChild(empty);
            return;
        }

        var i;

        for (i = 0; i < messages.length; i++) {
            var currentMessage = messages[i];

            var bubble = document.createElement('article');

            bubble.className = 'bubble ' +
                (Number(currentMessage.from_me) === 1
                    ? 'outgoing'
                    : 'incoming');

            var content = document.createElement('div');
            content.className = 'bubble-text';

            content.textContent = currentMessage.body ||
                '[' + (currentMessage.message_type || 'Message') + ']';

            var meta = document.createElement('div');
            meta.className = 'bubble-meta';

            var time = document.createElement('span');
            time.textContent = formatTime(currentMessage.sent_at);

            meta.appendChild(time);

            if (Number(currentMessage.from_me) === 1 &&
                currentMessage.delivery_status) {

                var status = document.createElement('span');
                status.textContent = currentMessage.delivery_status;
                meta.appendChild(status);
            }

            bubble.appendChild(content);
            bubble.appendChild(meta);

            messagesBox.appendChild(bubble);
        }

        if (!keepScroll || nearBottom) {
            messagesBox.scrollTop = messagesBox.scrollHeight;
        }
    }

    function loadConversations(callback) {
        api('/api/conversations', {}, function (error, result) {
            if (error) {
                if (callback) {
                    callback(error);
                }
                return;
            }

            conversations = result.data &&
                result.data.conversations
                ? result.data.conversations
                : [];

            if (selectedConversation) {
                var i;

                for (i = 0; i < conversations.length; i++) {
                    if (conversations[i].id == selectedConversation.id) {
                        selectedConversation = conversations[i];
                        break;
                    }
                }
            }

            renderConversations();

            if (callback) {
                callback(null);
            }
        });
    }

    function loadMessages(keepScroll, callback) {
        if (!selectedConversation || loadingMessages) {
            if (callback) {
                callback(null);
            }
            return;
        }

        loadingMessages = true;

        var conversationId = selectedConversation.id;

        api(
            '/api/conversations/' +
            encodeURIComponent(conversationId) +
            '/messages?limit=100',
            {},
            function (error, result) {
                loadingMessages = false;

                if (error) {
                    if (callback) {
                        callback(error);
                    }
                    return;
                }

                if (selectedConversation &&
                    selectedConversation.id == conversationId) {

                    var messages = result.data && result.data.messages
                        ? result.data.messages
                        : [];

                    renderMessages(messages, keepScroll);
                }

                if (callback) {
                    callback(null);
                }
            }
        );
    }

    function selectConversation(conversation) {
        selectedConversation = conversation;

        app.classList.add('chat-open');

        var name = conversationName(conversation);

        document.getElementById('chatName').textContent = name;

        document.getElementById('chatNumber').textContent =
            conversation.phone_number ||
            conversation.remote_jid ||
            '';

        document.getElementById('chatAvatar').textContent = initials(name);

        messageInput.disabled = false;
        sendButton.disabled = false;

        renderConversations();

        messagesBox.innerHTML =
            '<div class="empty">Chargement des messages...</div>';

        loadMessages(false, function (error) {
            if (error) {
                showNotice(error.message);
                return;
            }

            messageInput.focus();
        });
    }

    function sendMessage() {
        var text = messageInput.value.replace(/^\s+|\s+$/g, '');

        if (!text || !selectedConversation || sending) {
            return;
        }

        sending = true;
        sendButton.disabled = true;
        hideNotice();

        var conversationId = selectedConversation.id;

        api(
            '/api/conversations/' +
            encodeURIComponent(conversationId) +
            '/messages',
            {
                method: 'POST',
                body: JSON.stringify({
                    text: text
                })
            },
            function (error) {
                if (error) {
                    sending = false;
                    sendButton.disabled = !selectedConversation;
                    showNotice(error.message);
                    return;
                }

                messageInput.value = '';

                loadMessages(false, function (messageError) {
                    if (messageError) {
                        showNotice(messageError.message);
                    }

                    loadConversations(function (conversationError) {
                        if (conversationError) {
                            showNotice(conversationError.message);
                        }

                        sending = false;
                        sendButton.disabled = !selectedConversation;
                        messageInput.focus();
                    });
                });
            }
        );
    }

    messageForm.addEventListener('submit', function (event) {
        event.preventDefault();
        sendMessage();
    });

    messageInput.addEventListener('keydown', function (event) {
        event = event || window.event;

        var key = event.key || event.keyCode;

        if ((key === 'Enter' || key === 13) && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    });

    search.addEventListener('input', function () {
        renderConversations();
    });

    document.getElementById('logoutButton').addEventListener(
        'click',
        logout
    );

    document.getElementById('backButton').addEventListener(
        'click',
        function () {
            app.classList.remove('chat-open');
        }
    );

    function initialize() {
        api('/api/whatsapp/status', {}, function (error, result) {
            if (error) {
                showNotice(error.message);
                return;
            }

            var status = result.data || {};

            if (status.status !== 'connected') {
                location.replace('/index.html');
                return;
            }

            document.getElementById('accountStatus').textContent =
                status.phoneNumber
                    ? 'Connecté · ' + status.phoneNumber
                    : 'Compte WhatsApp connecté';

            loadConversations(function (conversationError) {
                if (conversationError) {
                    showNotice(conversationError.message);
                }
            });
        });
    }

    function refresh() {
        if (refreshing || sending) {
            return;
        }

        refreshing = true;

        loadConversations(function (error) {
            if (error) {
                refreshing = false;
                showNotice(error.message);
                return;
            }

            if (selectedConversation) {
                loadMessages(true, function (messageError) {
                    if (messageError) {
                        showNotice(messageError.message);
                    }

                    refreshing = false;
                });
            } else {
                refreshing = false;
            }
        });
    }

    initialize();

    setInterval(refresh, 5000);
})();
