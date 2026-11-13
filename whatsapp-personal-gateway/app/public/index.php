<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);



if (in_array($path, ['/api/whatsapp/status', '/api/whatsapp/qr'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // Protection temporaire : accès réservé au détenteur du jeton interne.
    $providedToken = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $providedToken, $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }

    $expectedToken = getenv('INTERNAL_API_TOKEN') ?: '';

    if (
        $expectedToken === '' ||
        !hash_equals($expectedToken, trim($matches[1]))
    ) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }

    try {
        require_once __DIR__ . '/../services/WhatsAppGateway.php';

        $gateway = new WhatsAppGateway();

        $result = $path === '/api/whatsapp/status'
            ? $gateway->getStatus()
            : $gateway->getQr();

        http_response_code(200);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(502);
        echo json_encode([
            'error' => 'whatsapp_gateway_unavailable'
        ]);
    }

    exit;
}



if ($path === '/api/health') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: 'mysql',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_DATABASE') ?: 'whatsapp_gateway'
            ),
            getenv('DB_USERNAME') ?: 'gateway',
            getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->query('SELECT 1');
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'service' => 'php-api', 'database' => 'ok']);
    } catch (Throwable $exception) {
        http_response_code(503);
        echo json_encode(['status' => 'degraded', 'service' => 'php-api', 'database' => 'unavailable']);
    }
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WhatsApp Personal Gateway</title>
  <style>
    :root { color-scheme: light; font-family: Inter, system-ui, sans-serif; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f5f3f6; color: #25212a; }
    main { max-width: 620px; margin: 24px; padding: 36px; background: white; border-radius: 16px; box-shadow: 0 12px 40px #25132b14; }
    h1 { color: #762372; margin-top: 0; }
    .status { display: inline-block; padding: 6px 10px; border-radius: 999px; background: #fff6cc; color: #665000; font-size: 13px; }
  </style>
</head>
<body>
<main>
  <span class="status">Socle Docker initialisé</span>
  <h1>WhatsApp Personal Gateway</h1>
  <p>Le serveur Web et le backend PHP répondent correctement.</p>
  <p>La prochaine étape consiste à mettre en place l'authentification, les rôles et la liaison sécurisée avec la passerelle WhatsApp.</p>
</main>
</body>
</html>
