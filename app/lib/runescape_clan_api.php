<?php
declare(strict_types=1);

function strip_utf8_bom(string $s): string
{
    return strncmp($s, "\xEF\xBB\xBF", 3) === 0 ? substr($s, 3) : $s;
}

function clean_rsn_display(string $rsn): string
{
    $rsn = strip_utf8_bom($rsn);
    $rsn = trim($rsn);
    if ($rsn === '') {
        return '';
    }

    $rsn = str_replace("\xA0", ' ', $rsn);
    $tmp = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}]/u', '', $rsn);
    if ($tmp !== null) {
        $rsn = $tmp;
    }

    $tmp = preg_replace('/\s+/u', ' ', $rsn);
    if ($tmp !== null) {
        $rsn = $tmp;
    }

    $tmp = preg_replace('/[^0-9A-Za-z _-]+/u', ' ', $rsn);
    if ($tmp !== null) {
        $rsn = $tmp;
    }

    $rsn = trim($rsn);
    if (mb_strlen($rsn, 'UTF-8') > 12) {
        $rsn = rtrim(mb_substr($rsn, 0, 12, 'UTF-8'));
    }

    return $rsn;
}

function normalise_rsn_import(string $rsnDisplay): string
{
    $rsn = mb_strtolower(trim($rsnDisplay), 'UTF-8');
    if ($rsn === '') {
        return '';
    }

    if (class_exists('Normalizer')) {
        $normalised = Normalizer::normalize($rsn, Normalizer::FORM_KC);
        if ($normalised !== false) {
            $rsn = $normalised;
        }
    }

    $rsn = str_replace(' ', '_', $rsn);
    $tmp = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $rsn);
    if ($tmp !== null) {
        $rsn = $tmp;
    }

    if (mb_strlen($rsn, 'UTF-8') > 12) {
        $rsn = mb_substr($rsn, 0, 12, 'UTF-8');
    }

    return $rsn;
}

function is_clan_member_header_row(array $row): bool
{
    $c0 = strtolower(trim((string)($row[0] ?? '')));
    $c1 = strtolower(trim((string)($row[1] ?? '')));
    return ($c0 === 'clanmate' || $c0 === 'name' || $c0 === 'rsn')
        || ($c1 === 'clan rank' || $c1 === 'rank');
}

function runescape_http_get(string $url, int $timeoutSec = 25): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeoutSec,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_USERAGENT => 'RS3ClanRanker/1.2',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('HTTP error: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP status ' . $status);
    }

    return (string)$body;
}

function fetch_runescape_clan_members_csv(string $clanName): string
{
    $clanName = trim($clanName);
    if ($clanName === '') {
        throw new RuntimeException('CLAN_NAME is not configured.');
    }

    $url = 'https://secure.runescape.com/m=clan-hiscores/members_lite.ws?clanName=' . rawurlencode($clanName);
    return runescape_http_get($url, 25);
}

function parse_runescape_clan_members_csv(string $csv): array
{
    $lines = preg_split("/\r\n|\n|\r/", $csv) ?: [];
    $members = [];
    $headerRowsSkipped = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $line = strip_utf8_bom($line);
        $row = str_getcsv($line, ',', '"', '\\');
        if (!is_array($row) || count($row) < 1) {
            continue;
        }
        if (is_clan_member_header_row($row)) {
            $headerRowsSkipped++;
            continue;
        }

        $rawRsn = (string)($row[0] ?? '');
        $rawRank = (string)($row[1] ?? '');

        $rsn = clean_rsn_display($rawRsn);
        if ($rsn === '') {
            continue;
        }

        $rsnNormalised = normalise_rsn_import($rsn);
        if ($rsnNormalised === '') {
            continue;
        }

        $rankName = trim($rawRank);
        if ($rankName !== '' && mb_strlen($rankName, 'UTF-8') > 64) {
            $rankName = mb_substr($rankName, 0, 64, 'UTF-8');
        }

        $members[$rsnNormalised] = [
            'rsn' => $rsn,
            'rsn_normalised' => $rsnNormalised,
            'rank_name' => $rankName !== '' ? $rankName : null,
        ];
    }

    return [
        'members' => array_values($members),
        'header_rows_skipped' => $headerRowsSkipped,
    ];
}

function import_runescape_clan_members(PDO $pdo, int $clanId, string $clanName): array
{
    $csv = fetch_runescape_clan_members_csv($clanName);
    $parsed = parse_runescape_clan_members_csv($csv);
    $members = $parsed['members'];
    $headerRowsSkipped = (int)$parsed['header_rows_skipped'];

    if (count($members) === 0) {
        throw new RuntimeException('Parsed 0 clan members from the RuneScape API. No database changes were made.');
    }

    $existingStmt = $pdo->prepare('SELECT id, rsn_normalised, is_active FROM clan_members WHERE clan_id = :clan_id');
    $existingStmt->execute(['clan_id' => $clanId]);
    $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $existingByNorm = [];
    foreach ($existingRows as $row) {
        $existingByNorm[(string)$row['rsn_normalised']] = $row;
    }

    $markInactiveStmt = $pdo->prepare('UPDATE clan_members SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE clan_id = :clan_id AND is_active = 1');
    $upsertStmt = $pdo->prepare(
        'INSERT INTO clan_members (clan_id, rsn, rsn_normalised, rank_name, is_active) '
        . 'VALUES (:clan_id, :rsn, :rsn_normalised, :rank_name, 1) '
        . 'ON DUPLICATE KEY UPDATE '
        . 'rsn = VALUES(rsn), '
        . 'rank_name = VALUES(rank_name), '
        . 'is_active = 1, '
        . 'updated_at = CURRENT_TIMESTAMP'
    );
    $inactiveCountStmt = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE clan_id = :clan_id AND is_active = 0');
    $activeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE clan_id = :clan_id AND is_active = 1');

    $inserted = 0;
    $updated = 0;
    $reactivated = 0;

    $pdo->beginTransaction();
    try {
        $markInactiveStmt->execute(['clan_id' => $clanId]);

        foreach ($members as $member) {
            $norm = (string)$member['rsn_normalised'];
            $existing = $existingByNorm[$norm] ?? null;
            if ($existing === null) {
                $inserted++;
            } elseif ((int)($existing['is_active'] ?? 0) === 0) {
                $reactivated++;
            } else {
                $updated++;
            }

            $upsertStmt->execute([
                'clan_id' => $clanId,
                'rsn' => $member['rsn'],
                'rsn_normalised' => $member['rsn_normalised'],
                'rank_name' => $member['rank_name'],
            ]);
        }

        $inactiveCountStmt->execute(['clan_id' => $clanId]);
        $inactiveAfter = (int)$inactiveCountStmt->fetchColumn();
        $activeCountStmt->execute(['clan_id' => $clanId]);
        $activeAfter = (int)$activeCountStmt->fetchColumn();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'fetched' => count($members),
        'inserted' => $inserted,
        'updated' => $updated,
        'reactivated' => $reactivated,
        'marked_inactive' => $inactiveAfter,
        'active_after' => $activeAfter,
        'header_rows_skipped' => $headerRowsSkipped,
        'clan_name' => $clanName,
    ];
}
