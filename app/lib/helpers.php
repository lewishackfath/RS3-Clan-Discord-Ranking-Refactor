<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flashes) ? $flashes : [];
}

function post_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_or_fail(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    $session = (string)($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $session === '' || !hash_equals($session, $token)) {
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}

function app_url(string $path = ''): string
{
    $base = rtrim((string)env('APP_URL', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function csv_ids(string $value): array
{
    $parts = array_map('trim', explode(',', $value));
    return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
}

function normalise_rsn(string $value): string
{
    $value = preg_replace('/[\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u', ' ', $value) ?? $value;
    $value = str_replace('_', ' ', $value);
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return mb_strtolower($value, 'UTF-8');
}


function normalise_match_source(string $value): string
{
    $value = strip_tags($value);
    $value = preg_replace('/[\x{00A0}\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', ' ', $value) ?? $value;
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (class_exists('Normalizer')) {
        $normalised = Normalizer::normalize($value, Normalizer::FORM_KC);
        if ($normalised !== false) {
            $value = $normalised;
        }
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = preg_replace('/[^0-9A-Za-z _-]+/u', ' ', $value) ?? $value;
    $value = trim($value);
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(' ', '_', $value);
    $value = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value) ?? $value;

    if (mb_strlen($value, 'UTF-8') > 12) {
        $value = mb_substr($value, 0, 12, 'UTF-8');
    }

    return $value;
}

function now_utc(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function discord_avatar_url(array $user, int $size = 64): string
{
    $userId = (string)($user['id'] ?? '');
    $avatar = (string)($user['avatar'] ?? '');
    if ($userId !== '' && $avatar !== '') {
        return sprintf('https://cdn.discordapp.com/avatars/%s/%s.png?size=%d', $userId, $avatar, $size);
    }

    $discriminator = (int)($user['discriminator'] ?? 0);
    $index = $discriminator > 0 ? $discriminator % 5 : ((int)$userId >> 22) % 6;
    return 'https://cdn.discordapp.com/embed/avatars/' . $index . '.png';
}

function table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
    $stmt->execute(['table' => $tableName]);
    return (bool)$stmt->fetchColumn();
}

function require_tables(PDO $pdo, array $tables): array
{
    $missing = [];
    foreach ($tables as $table) {
        if (!table_exists($pdo, $table)) {
            $missing[] = $table;
        }
    }
    return $missing;
}

function rs_rank_order(): array
{
    return [
        'Guest',
        'Clan Member',
        'Recruit',
        'Corporal',
        'Sergeant',
        'Lieutenant',
        'Captain',
        'General',
        'Admin',
        'Organiser',
        'Coordinator',
        'Overseer',
        'Deputy Owner',
        'Owner',
    ];
}
