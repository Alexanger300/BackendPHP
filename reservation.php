<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Charger les variables d'environnement depuis .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (!str_starts_with(trim($line), '#') && str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

// Configuration Neon PostgreSQL
// Format: postgresql://user:password@host/database?sslmode=require
// Remplacez par votre URL Neon depuis https://console.neon.tech
define('DB_URL', getenv('DATABASE_URL') ?: 'postgresql://neondb_owner:YOUR_PASSWORD@YOUR_HOST.neon.tech/neondb?sslmode=require');

function buildPgsqlPdoConfig(string $databaseUrl): array
{
    $parts = parse_url($databaseUrl);
    if ($parts === false) {
        throw new Exception('DATABASE_URL invalide (parse_url a échoué).');
    }

    $scheme = $parts['scheme'] ?? '';
    if (!in_array($scheme, ['postgres', 'postgresql'], true)) {
        throw new Exception('DATABASE_URL doit commencer par postgres:// ou postgresql://');
    }

    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? 5432;
    $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
    $user = isset($parts['user']) ? urldecode($parts['user']) : '';
    $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';

    if ($host === '' || $dbname === '' || $user === '') {
        throw new Exception('DATABASE_URL doit contenir host, database et user.');
    }

    $dsnParams = [
        'host=' . $host,
        'port=' . $port,
        'dbname=' . $dbname
    ];
    $hasOptionsParam = false;

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        foreach ($query as $key => $value) {
            if ($key === 'channel_binding') {
                continue;
            }
            if ($key === 'options') {
                $hasOptionsParam = true;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $dsnParams[] = $key . '=' . $value;
        }
    }

    if (!$hasOptionsParam) {
        $endpointId = explode('.', $host)[0] ?? '';
        if (str_ends_with($endpointId, '-pooler')) {
            $endpointId = substr($endpointId, 0, -7);
        }
        if ($endpointId !== '') {
            $dsnParams[] = 'options=endpoint=' . $endpointId;
        }
    }

    if (stripos(implode(';', $dsnParams), 'sslmode=') === false) {
        $dsnParams[] = 'sslmode=require';
    }

    return [
        'dsn' => 'pgsql:' . implode(';', $dsnParams),
        'user' => $user,
        'pass' => $pass
    ];
}

// Réponse par défaut
$response = [
    'success' => false,
    'message' => 'Erreur inconnue',
    'data' => null
];

if (!extension_loaded('pdo_pgsql')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'L\'extension PHP pdo_pgsql n\'est pas activée. Activez extension=pdo_pgsql et extension=pgsql dans C:\\xampp\\php\\php.ini puis redémarrez Apache.',
        'data' => [
            'sapi' => php_sapi_name(),
            'loaded_ini' => php_ini_loaded_file(),
            'extension_dir' => ini_get('extension_dir'),
            'pdo_loaded' => extension_loaded('pdo'),
            'pdo_pgsql_loaded' => extension_loaded('pdo_pgsql'),
            'pgsql_loaded' => extension_loaded('pgsql')
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    $dbConfig = buildPgsqlPdoConfig(DB_URL);

    // Créer la connexion PDO PostgreSQL via Neon
    $pdo = new PDO(
        $dbConfig['dsn'],
        $dbConfig['user'],
        $dbConfig['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false
        ]
    );

    // Créer la table des réservations si elle n'existe pas (PostgreSQL)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reservations (
            id SERIAL PRIMARY KEY,
            fullname VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            reservation_date DATE NOT NULL,
            time_slot VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'cancelled')),
            UNIQUE(reservation_date, time_slot)
        )
    ");

    // Créer les indexes pour meilleures performances
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email ON reservations(email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_date ON reservations(reservation_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_status ON reservations(status)");

    // Traiter uniquement les requêtes POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Récupérer et valider les données JSON
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            throw new Exception('Aucune donnée reçue');
        }

        $fullname = trim($input['fullname'] ?? '');
        $email = trim($input['email'] ?? '');
        $reservation_date = trim($input['selectedDate'] ?? '');
        $time_slot = trim($input['selectedSlot'] ?? '');

        // Validations
        if (empty($fullname) || strlen($fullname) < 2) {
            throw new Exception('Le nom doit contenir au moins 2 caractères');
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email invalide');
        }

        if (empty($reservation_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservation_date)) {
            throw new Exception('Format de date invalide');
        }

        if (empty($time_slot) || !preg_match('/^\d{2}:\d{2}$/', $time_slot)) {
            throw new Exception('Format d\'heure invalide');
        }

        // Vérifier que la date n'est pas dans le passé
        $now = new DateTime('now', new DateTimeZone('Europe/Paris'));
        $reservation_dt = DateTime::createFromFormat('Y-m-d', $reservation_date, new DateTimeZone('Europe/Paris'));

        if ($reservation_dt < $now) {
            throw new Exception('La date doit être dans le futur');
        }

        // Vérifier si le créneau est déjà réservé
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM reservations 
            WHERE reservation_date = :date AND time_slot = :slot AND status != 'cancelled'
        ");
        $checkStmt->execute([':date' => $reservation_date, ':slot' => $time_slot]);
        $result = $checkStmt->fetch();

        if ($result['count'] > 0) {
            throw new Exception('Ce créneau est déjà réservé');
        }

        // Insérer la réservation avec RETURNING pour récupérer l'ID (PostgreSQL)
        $stmt = $pdo->prepare("
            INSERT INTO reservations (fullname, email, reservation_date, time_slot, status)
            VALUES (:fullname, :email, :date, :slot, 'pending')
            RETURNING id
        ");
        $stmt->execute([
            ':fullname' => $fullname,
            ':email' => $email,
            ':date' => $reservation_date,
            ':slot' => $time_slot
        ]);

        $result = $stmt->fetch();
        $reservation_id = $result['id'];

        $response['success'] = true;
        $response['message'] = 'Réservation enregistrée avec succès';
        $response['data'] = [
            'id' => $reservation_id,
            'fullname' => $fullname,
            'email' => $email,
            'date' => $reservation_date,
            'slot' => $time_slot
        ];

        // Envoyer un email de confirmation (optionnel)
        // sendConfirmationEmail($email, $fullname, $reservation_date, $time_slot);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Récupérer les réservations de l'utilisateur ou vérifier la disponibilité
        $action = $_GET['action'] ?? '';

        if ($action === 'check-availability') {
            $date = $_GET['date'] ?? '';
            
            if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('Date invalide');
            }

            $stmt = $pdo->prepare("
                SELECT time_slot FROM reservations 
                WHERE reservation_date = :date AND status != 'cancelled'
                ORDER BY time_slot
            ");
            $stmt->execute([':date' => $date]);
            $bookedSlots = $stmt->fetchAll();

            $response['success'] = true;
            $response['message'] = 'Créneaux vérifiés';
            $response['data'] = [
                'date' => $date,
                'bookedSlots' => array_column($bookedSlots, 'time_slot')
            ];

        } elseif ($action === 'user-reservations') {
            $email = $_GET['email'] ?? '';

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email invalide');
            }

            $stmt = $pdo->prepare("
                SELECT id, fullname, email, reservation_date, time_slot, created_at, status
                FROM reservations 
                WHERE email = :email
                ORDER BY reservation_date DESC
            ");
            $stmt->execute([':email' => $email]);
            $reservations = $stmt->fetchAll();

            $response['success'] = true;
            $response['message'] = 'Réservations trouvées';
            $response['data'] = [
                'email' => $email,
                'reservations' => $reservations
            ];
        }
    } else {
        throw new Exception('Méthode HTTP non autorisée');
    }

} catch (PDOException $e) {
    $response['message'] = 'Erreur de connexion à Neon: ' . $e->getMessage();
    if (stripos($e->getMessage(), 'could not find driver') !== false) {
        $response['message'] = 'Le driver PostgreSQL n\'est pas chargé. Activez extension=pdo_pgsql et extension=pgsql dans C:\\xampp\\php\\php.ini puis redémarrez Apache.';
        $response['data'] = [
            'sapi' => php_sapi_name(),
            'loaded_ini' => php_ini_loaded_file(),
            'extension_dir' => ini_get('extension_dir'),
            'pdo_loaded' => extension_loaded('pdo'),
            'pdo_pgsql_loaded' => extension_loaded('pdo_pgsql'),
            'pgsql_loaded' => extension_loaded('pgsql'),
            'original_error' => $e->getMessage()
        ];
    }
    http_response_code(500);
    error_log('PDO Error: ' . $e->getMessage());
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
