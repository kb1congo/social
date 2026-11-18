(function () {
    var form = document.getElementById('loginForm');
    var loginButton = document.getElementById('loginButton');
    var qrSection = document.getElementById('qrSection');
    var qrImage = document.getElementById('qrImage');
    var qrInstructions = document.getElementById('qrInstructions');
    var qrHelp = document.getElementById('qrHelp');
    var spinner = document.getElementById('spinner');
    var message = document.getElementById('message');

    var token = sessionStorage.getItem('whatsapp_gateway_token');
    var polling = false;
    var pollTimer = null;

    function showMessage(text, type) {
        type = type || 'info';
        message.textContent = text;
        message.className = 'message ' + type;
    }

    function clearMessage() {
        message.textContent = '';
        message.className = 'message';
    }

    function showQrSection() {
        form.classList.add('hidden');
        qrSection.style.display = 'block';
    }

    function showQr(data) {
        if (data.qrImage) {
            qrImage.src = data.qrImage;
            qrImage.classList.remove('hidden');
            qrHelp.classList.remove('hidden');
            spinner.classList.add('hidden');
            qrInstructions.textContent = 'Scannez ce QR code avec votre téléphone.';
        } else if (data.status === 'connecting') {
            qrInstructions.textContent = 'Initialisation de la session WhatsApp...';
            spinner.classList.remove('hidden');
        } else if (data.status === 'qr') {
            qrInstructions.textContent = 'Génération du QR code...';
            spinner.classList.remove('hidden');
        }
    }

    function api(path, options, callback) {
        options = options || {};

        var xhr = new XMLHttpRequest();
        xhr.open(options.method || 'GET', path, true);
        xhr.setRequestHeader('Content-Type', 'application/json');

        if (token) {
            xhr.setRequestHeader('Authorization', 'Bearer ' + token);
        }

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
                sessionStorage.removeItem('whatsapp_gateway_token');
                token = null;
                callback(new Error('Votre session a expiré. Veuillez vous reconnecter.'));
                return;
            }

            if (xhr.status < 200 || xhr.status >= 300 || result.status === 'error') {
                callback(new Error(result.message || 'Une erreur est survenue.'));
                return;
            }

            callback(null, result);
        };

        xhr.onerror = function () {
            callback(new Error('Impossible de contacter le serveur.'));
        };

        xhr.send(options.body || null);
    }

    function checkWhatsAppStatus(callback) {
        api('/api/whatsapp/status', {}, function (error, result) {
            if (error) {
                callback(error);
                return;
            }

            var data = result.data || {};

            if (data.status === 'connected') {
                clearInterval(pollTimer);
                location.replace('/chat.html');
                callback(null, true);
                return;
            }

            showQr(data);

            if (data.lastError) {
                showMessage(data.lastError, 'error');
            }

            callback(null, false);
        });
    }

    function startPolling() {
        if (polling) {
            return;
        }

        polling = true;

        pollTimer = setInterval(function () {
            checkWhatsAppStatus(function (error) {
                if (error) {
                    clearInterval(pollTimer);
                    polling = false;
                    showMessage(error.message, 'error');
                }
            });
        }, 2000);
    }

    function startWhatsAppConnection() {
        showQrSection();
        showMessage('Vérification de votre compte WhatsApp...');

        api('/api/whatsapp/status', {}, function (error, result) {
            if (error) {
                showMessage(error.message, 'error');

                if (!token) {
                    form.classList.remove('hidden');
                    qrSection.style.display = 'none';
                }
                return;
            }

            var status = result.data || {};

            if (status.status === 'connected') {
                location.replace('/chat.html');
                return;
            }

            if (status.status === 'qr' || status.status === 'connecting') {
                showQr(status);
                clearMessage();
                startPolling();
                return;
            }

            qrInstructions.textContent = 'Démarrage de la connexion WhatsApp...';

            api('/api/whatsapp/connect', {
                method: 'POST',
                body: '{}'
            }, function (connectError) {
                if (connectError) {
                    showMessage(connectError.message, 'error');
                    return;
                }

                clearMessage();
                startPolling();
            });
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        clearMessage();

        loginButton.disabled = true;
        loginButton.textContent = 'Connexion...';

        api('/api/auth/login', {
            method: 'POST',
            body: JSON.stringify({
                email: document.getElementById('email').value.replace(/^\s+|\s+$/g, ''),
                password: document.getElementById('password').value
            })
        }, function (error, result) {
            if (error) {
                showMessage(error.message, 'error');
                loginButton.disabled = false;
                loginButton.textContent = 'Se connecter';
                return;
            }

            token = result.data.token;
            sessionStorage.setItem('whatsapp_gateway_token', token);
            document.getElementById('password').value = '';

            startWhatsAppConnection();

            loginButton.disabled = false;
            loginButton.textContent = 'Se connecter';
        });
    });

    if (token) {
        startWhatsAppConnection();
    }
})();
