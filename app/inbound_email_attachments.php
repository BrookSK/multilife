<?php

declare(strict_types=1);

/**
 * ANEXOS E IMAGENS dos e-mails de captação.
 *
 * Antes o sistema só lia o texto do e-mail: imagens (inclusive as coladas no
 * corpo), PDFs e planilhas eram descartados e o card nascia sem endereço,
 * paciente etc. quando esses dados vinham dentro deles.
 *
 * Fluxo:
 *  1) cron/smtp_poll.php guarda os anexos (inbound_email_attachments + arquivo em
 *     disco). E-mails antigos: os anexos são buscados no IMAP na hora de processar.
 *  2) cron/openai_extract_email_to_demand.php pede o texto de cada anexo
 *     (inbound_attachments_extracted_text) e junta ao corpo antes da IA montar o card.
 *     - imagens (jpg, png, gif, webp, bmp): leitura pela IA (visão/OCR);
 *     - PDF: enviado à IA como arquivo;
 *     - Word (docx), LibreOffice (odt), Excel (xlsx), txt/csv/html: texto lido aqui;
 *       imagens dentro do docx/odt também são lidas.
 *     O texto fica guardado (não paga a IA de novo ao reprocessar).
 *  3) No card: botão "Ler anexos e completar o card" (cards já criados).
 */

const INBOUND_ATT_MAX_BYTES = 15 * 1024 * 1024;   // arquivo maior que isso não é guardado
const INBOUND_ATT_MAX_FILES = 20;                 // por e-mail
const INBOUND_ATT_MAX_EXTRACT_PER_RUN = 10;       // leituras pela IA por execução
const INBOUND_ATT_MAX_TEXT = 15000;               // caracteres guardados por anexo
const INBOUND_ATT_MAX_TOTAL_TEXT = 30000;         // caracteres juntados ao e-mail

const INBOUND_ATT_EXT_MIME = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jfif' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'tif' => 'image/tiff',
    'tiff' => 'image/tiff', 'heic' => 'image/heic', 'pdf' => 'application/pdf',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'odt' => 'application/vnd.oasis.opendocument.text', 'doc' => 'application/msword',
    'xls' => 'application/vnd.ms-excel', 'txt' => 'text/plain', 'csv' => 'text/csv',
    'htm' => 'text/html', 'html' => 'text/html', 'xml' => 'text/xml', 'eml' => 'message/rfc822',
    'rtf' => 'application/rtf',
];

/** Tabela dos anexos + marca "anexos já buscados" no e-mail. Idempotente. */
function inbound_attachments_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = db();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS inbound_email_attachments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inbound_email_id INT UNSIGNED NOT NULL,
            part_index VARCHAR(40) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'imap',
            file_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(150) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            content_id VARCHAR(255) NULL,
            is_inline TINYINT(1) NOT NULL DEFAULT 0,
            sha1 CHAR(40) NULL,
            file_path VARCHAR(500) NULL,
            extraction_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            extracted_text MEDIUMTEXT NULL,
            extraction_error VARCHAR(500) NULL,
            extracted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_iea_part (inbound_email_id, part_index),
            KEY idx_iea_email (inbound_email_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[INBOUND_ATT] schema: ' . $e->getMessage());
    }
    try {
        $col = $db->query("SHOW COLUMNS FROM inbound_emails LIKE 'attachments_fetched_at'")->fetch();
        if (!$col) {
            $db->exec("ALTER TABLE inbound_emails ADD COLUMN attachments_fetched_at DATETIME NULL");
        }
    } catch (Throwable $e) {
        error_log('[INBOUND_ATT] schema inbound_emails: ' . $e->getMessage());
    }
}

/**
 * Pasta dos arquivos. Prefere FORA da pasta pública do site (dados de paciente);
 * se não der, usa storage/inbound_attachments, bloqueada para a web.
 */
function inbound_attachments_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $candidates = [
        dirname(__DIR__, 2) . '/' . basename(dirname(__DIR__)) . '_inbound_attachments',
        dirname(__DIR__) . '/storage/inbound_attachments',
    ];
    foreach ($candidates as $i => $c) {
        if (!@is_dir($c)) {
            @mkdir($c, 0700, true);
        }
        if (@is_dir($c) && @is_writable($c)) {
            if ($i === 1) {
                $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
                if (!is_file($c . '/.htaccess')) {
                    @file_put_contents($c . '/.htaccess', $deny);
                }
                if (!is_file($c . '/index.html')) {
                    @file_put_contents($c . '/index.html', '');
                }
            }
            return $dir = $c;
        }
    }
    return $dir = '';
}

/** Nome de arquivo legível e seguro para mostrar/baixar. */
function inbound_att_clean_name(string $name): string
{
    $name = trim(str_replace(["\r", "\n", "\0", '/', '\\'], ' ', $name));
    $name = preg_replace('/\s+/', ' ', $name) ?? '';
    return mb_substr($name !== '' ? $name : 'anexo', 0, 200);
}

/** Tipo pelo nome quando o e-mail manda "application/octet-stream". */
function inbound_att_guess_mime(string $mime, string $name): string
{
    $mime = strtolower(trim($mime));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (($mime === '' || $mime === 'application/octet-stream' || $mime === 'application/x-unknown') && isset(INBOUND_ATT_EXT_MIME[$ext])) {
        return INBOUND_ATT_EXT_MIME[$ext];
    }
    if ($mime === 'image/jpg' || $mime === 'image/pjpeg') {
        return 'image/jpeg';
    }
    return $mime !== '' ? $mime : 'application/octet-stream';
}

/**
 * Guarda um anexo (arquivo + linha). Ignora ícones/pixels de rastreio
 * (imagens muito pequenas) e repetições do mesmo arquivo no e-mail.
 *
 * @return int|null id do anexo (null se ignorado)
 */
function inbound_attachments_save(int $emailId, string $partIndex, string $name, string $mime, string $data,
    ?string $contentId = null, bool $inline = false, string $source = 'imap'): ?int
{
    inbound_attachments_ensure_schema();
    $db = db();
    $name = inbound_att_clean_name($name);
    $mime = inbound_att_guess_mime($mime, $name);
    $size = strlen($data);
    if ($size === 0) {
        return null;
    }

    if (str_starts_with($mime, 'image/')) {
        $dims = @getimagesizefromstring($data);
        if ($size < 2048 || (is_array($dims) && ($dims[0] <= 64 || $dims[1] <= 64))) {
            return null; // ícone, logo pequeno ou pixel de rastreio
        }
    }

    $existsPart = $db->prepare('SELECT id FROM inbound_email_attachments WHERE inbound_email_id = :e AND part_index = :p LIMIT 1');
    $existsPart->execute(['e' => $emailId, 'p' => $partIndex]);
    if ($found = $existsPart->fetchColumn()) {
        return (int)$found;
    }
    $count = $db->prepare('SELECT COUNT(*) FROM inbound_email_attachments WHERE inbound_email_id = :e');
    $count->execute(['e' => $emailId]);
    if ((int)$count->fetchColumn() >= INBOUND_ATT_MAX_FILES) {
        return null;
    }
    $sha1 = sha1($data);
    $dup = $db->prepare('SELECT id FROM inbound_email_attachments WHERE inbound_email_id = :e AND sha1 = :s LIMIT 1');
    $dup->execute(['e' => $emailId, 's' => $sha1]);
    if ($dup->fetchColumn()) {
        return null; // mesma imagem repetida (ex.: colada duas vezes)
    }

    $status = 'pending';
    $error = null;
    $path = null;
    if ($size > INBOUND_ATT_MAX_BYTES) {
        $status = 'skipped';
        $error = 'Arquivo grande demais (' . round($size / 1048576, 1) . ' MB)';
    } else {
        $dir = inbound_attachments_dir();
        if ($dir === '') {
            $status = 'error';
            $error = 'Sem pasta gravável para guardar anexos';
        } else {
            $sub = $dir . '/' . date('Y-m');
            if (!@is_dir($sub)) {
                @mkdir($sub, 0700, true);
            }
            $path = $sub . '/' . $emailId . '_' . bin2hex(random_bytes(12)) . '.bin';
            if (@file_put_contents($path, $data) === false) {
                $status = 'error';
                $error = 'Falha ao gravar o arquivo';
                $path = null;
            }
        }
    }

    $ins = $db->prepare('INSERT INTO inbound_email_attachments
        (inbound_email_id, part_index, source, file_name, mime_type, size_bytes, content_id, is_inline, sha1, file_path, extraction_status, extraction_error)
        VALUES (:e, :p, :src, :n, :m, :sz, :cid, :inl, :sha, :fp, :st, :err)');
    $ins->execute([
        'e' => $emailId, 'p' => mb_substr($partIndex, 0, 40), 'src' => $source, 'n' => $name, 'm' => mb_substr($mime, 0, 150),
        'sz' => $size, 'cid' => $contentId !== null ? mb_substr($contentId, 0, 255) : null, 'inl' => $inline ? 1 : 0,
        'sha' => $sha1, 'fp' => $path, 'st' => $status, 'err' => $error,
    ]);
    return (int)$db->lastInsertId();
}

/** Valor de parâmetro MIME (filename / name), com decodificação de =?utf-8?...?= e RFC 2231. */
function inbound_att_part_param(object $part, array $names): string
{
    $lists = [];
    if (!empty($part->ifdparameters) && isset($part->dparameters)) {
        $lists[] = $part->dparameters;
    }
    if (!empty($part->ifparameters) && isset($part->parameters)) {
        $lists[] = $part->parameters;
    }
    foreach ($lists as $params) {
        // RFC 2231 pode vir quebrado: filename*0*, filename*1* ...
        $pieces = [];
        foreach ($params as $p) {
            $attr = strtolower((string)($p->attribute ?? ''));
            foreach ($names as $n) {
                if ($attr === $n) {
                    return inbound_att_decode_header((string)$p->value);
                }
                if (str_starts_with($attr, $n . '*')) {
                    $pieces[$attr] = (string)$p->value;
                }
            }
        }
        if ($pieces) {
            ksort($pieces, SORT_NATURAL);
            $raw = implode('', $pieces);
            $charset = 'UTF-8';
            if (preg_match("/^([\w-]+)'[\w-]*'(.*)$/s", $raw, $m)) {
                $charset = $m[1];
                $raw = $m[2];
            }
            $val = rawurldecode($raw);
            if (strtoupper($charset) !== 'UTF-8') {
                $conv = @mb_convert_encoding($val, 'UTF-8', $charset);
                if (is_string($conv)) {
                    $val = $conv;
                }
            }
            return $val;
        }
    }
    return '';
}

function inbound_att_decode_header(string $v): string
{
    if (!str_contains($v, '=?')) {
        return $v;
    }
    if (function_exists('imap_utf8')) {
        $d = imap_utf8($v);
        if (is_string($d) && $d !== '') {
            return $d;
        }
    }
    $d = @mb_decode_mimeheader($v);
    return is_string($d) ? $d : $v;
}

/** Decodifica o conteúdo da parte conforme o encoding do IMAP. */
function inbound_att_decode_body(string $data, int $encoding): string
{
    if ($encoding === 3) {
        return (string)base64_decode($data);
    }
    if ($encoding === 4) {
        return quoted_printable_decode($data);
    }
    return $data;
}

/**
 * Percorre a estrutura MIME e guarda tudo que não é o corpo do e-mail:
 * imagens (anexas ou coladas), PDFs, documentos, planilhas, e-mails anexados.
 *
 * @param resource|\IMAP\Connection $imap
 * @return int quantidade guardada
 */
function inbound_attachments_collect_from_imap($imap, int $uid, $structure, int $emailId): int
{
    if (!$structure) {
        return 0;
    }
    $types = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application', 4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other', 8 => 'model'];
    $saved = 0;

    $walk = function (object $part, string $index) use (&$walk, &$saved, $imap, $uid, $emailId, $types): void {
        $type = (int)($part->type ?? 0);
        if ($type === 1 && !empty($part->parts)) {
            foreach ($part->parts as $i => $sub) {
                $walk($sub, ($index === '' ? '' : $index . '.') . ($i + 1));
            }
            return;
        }
        $disposition = !empty($part->ifdisposition) ? strtolower((string)$part->disposition) : '';
        $fileName = inbound_att_part_param($part, ['filename', 'name']);
        $subtype = strtolower((string)($part->subtype ?? ''));

        // Texto sem nome de arquivo e sem "attachment" = corpo do e-mail (já lido).
        if ($type === 0 && $disposition !== 'attachment' && $fileName === '') {
            return;
        }
        if ($type === 4 || $type === 6) {
            return; // áudio e vídeo: não há o que ler
        }
        $section = $index === '' ? '1' : $index;
        $raw = @imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $data = inbound_att_decode_body($raw, (int)($part->encoding ?? 0));
        $mime = ($types[$type] ?? 'application') . '/' . ($subtype !== '' ? $subtype : 'octet-stream');
        if ($fileName === '') {
            $ext = array_search($mime, INBOUND_ATT_EXT_MIME, true);
            $fileName = ($type === 5 ? 'imagem' : ($type === 2 ? 'email-anexado' : 'anexo')) . '-' . str_replace('.', '-', $section) . ($ext ? '.' . $ext : '');
        }
        $cid = !empty($part->ifid) ? trim((string)$part->id, '<> ') : null;
        if (inbound_attachments_save($emailId, $section, $fileName, $mime, $data, $cid, $disposition === 'inline' || $cid !== null) !== null) {
            $saved++;
        }
    };

    if (!empty($structure->parts)) {
        foreach ($structure->parts as $i => $p) {
            $walk($p, (string)($i + 1));
        }
    } elseif ((int)($structure->type ?? 0) !== 0) {
        $walk($structure, ''); // a mensagem inteira é o anexo (ex.: só uma imagem)
    }
    return $saved;
}

/** Imagens coladas no corpo como data:image/...;base64 (Outlook web, prints colados). */
function inbound_attachments_collect_data_uris(int $emailId, string $html): int
{
    if ($html === '' || !str_contains($html, 'data:image/')) {
        return 0;
    }
    $saved = 0;
    if (preg_match_all('~data:(image/(?:png|jpe?g|gif|webp|bmp));base64,([A-Za-z0-9+/=\s]+)~i', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $i => $hit) {
            $data = base64_decode(preg_replace('/\s+/', '', $hit[2]) ?? '', true);
            if (!is_string($data) || $data === '') {
                continue;
            }
            $mime = strtolower($hit[1]);
            $ext = $mime === 'image/jpeg' || $mime === 'image/jpg' ? 'jpg' : substr($mime, 6);
            if (inbound_attachments_save($emailId, 'html-' . ($i + 1), 'imagem-no-corpo-' . ($i + 1) . '.' . $ext, $mime, $data, null, true, 'html') !== null) {
                $saved++;
            }
        }
    }
    return $saved;
}

/** Marca que os anexos deste e-mail já foram buscados. */
function inbound_attachments_mark_fetched(int $emailId): void
{
    try {
        db()->prepare('UPDATE inbound_emails SET attachments_fetched_at = NOW() WHERE id = :id')->execute(['id' => $emailId]);
    } catch (Throwable $e) {
        error_log('[INBOUND_ATT] mark_fetched: ' . $e->getMessage());
    }
}

/** Configuração IMAP (mesma do cron/smtp_poll.php). */
function inbound_imap_config(): array
{
    $get = static fn(string $in, string $out, string $def = ''): string =>
        trim((string)admin_setting_get($in, '')) !== '' ? (string)admin_setting_get($in, '') : (string)admin_setting_get($out, $def);
    $enc = strtolower(trim($get('smtp.in.encryption', 'smtp.out.encryption', 'ssl')));
    $flags = [];
    if ($enc === 'ssl' || $enc === 'tls') {
        $flags[] = $enc;
    }
    $flags[] = 'novalidate-cert';
    return [
        'server' => '{' . trim($get('smtp.in.host', 'smtp.out.host')) . ':' . (int)admin_setting_get('smtp.in.port', '993') . '/imap/' . implode('/', $flags) . '}',
        'user' => trim($get('smtp.in.username', 'smtp.out.username')),
        'pass' => $get('smtp.in.password', 'smtp.out.password'),
        'mailboxes' => array_values(array_unique(array_filter([
            trim((string)admin_setting_get('smtp.in.archive_mailbox', 'INBOX.Archive')),
            trim((string)admin_setting_get('smtp.in.mailbox', 'INBOX')),
        ]))),
    ];
}

/**
 * E-mails que chegaram antes desta melhoria (ou cujo poll não guardou os anexos):
 * acha a mensagem no IMAP pelo Message-ID e guarda os anexos.
 *
 * @return array{ok:bool, saved:int, message:string}
 */
function inbound_attachments_fetch_for_email(int $emailId): array
{
    inbound_attachments_ensure_schema();
    $st = db()->prepare('SELECT id, message_id, body_html FROM inbound_emails WHERE id = :id LIMIT 1');
    $st->execute(['id' => $emailId]);
    $email = $st->fetch(PDO::FETCH_ASSOC);
    if (!$email) {
        return ['ok' => false, 'saved' => 0, 'message' => 'E-mail não encontrado.'];
    }
    // Imagens coladas no HTML já estão no banco: não dependem do IMAP.
    $saved = inbound_attachments_collect_data_uris($emailId, (string)($email['body_html'] ?? ''));

    $messageId = trim((string)($email['message_id'] ?? ''));
    if (!function_exists('imap_open')) {
        return ['ok' => $saved > 0, 'saved' => $saved, 'message' => 'Extensão IMAP do PHP indisponível.'];
    }
    if ($messageId === '' || str_starts_with($messageId, 'uid-')) {
        inbound_attachments_mark_fetched($emailId);
        return ['ok' => true, 'saved' => $saved, 'message' => 'E-mail sem Message-ID: não dá para achar no servidor.'];
    }
    $cfg = inbound_imap_config();
    if ($cfg['user'] === '' || $cfg['pass'] === '') {
        return ['ok' => false, 'saved' => $saved, 'message' => 'Caixa de e-mail (IMAP) não configurada.'];
    }
    if (function_exists('imap_timeout')) {
        @imap_timeout(IMAP_OPENTIMEOUT, 10);
        @imap_timeout(IMAP_READTIMEOUT, 20);
    }
    $search = 'HEADER Message-ID "' . str_replace('"', '', $messageId) . '"';
    foreach ($cfg['mailboxes'] as $mailbox) {
        $imap = @imap_open($cfg['server'] . $mailbox, $cfg['user'], $cfg['pass'], 0, 1);
        if (!$imap) {
            continue;
        }
        try {
            $uids = @imap_search($imap, $search, SE_UID);
            if (is_array($uids) && $uids) {
                $uid = (int)$uids[0];
                $structure = @imap_fetchstructure($imap, $uid, FT_UID);
                $saved += inbound_attachments_collect_from_imap($imap, $uid, $structure, $emailId);
                inbound_attachments_mark_fetched($emailId);
                return ['ok' => true, 'saved' => $saved, 'message' => 'Mensagem encontrada em ' . $mailbox . '.'];
            }
        } finally {
            @imap_close($imap);
        }
    }
    @imap_errors(); // limpa avisos acumulados
    return ['ok' => $saved > 0, 'saved' => $saved, 'message' => 'Mensagem não encontrada na caixa de e-mail (pode ter sido apagada).'];
}

/** Anexos do e-mail. */
function inbound_attachments_list(int $emailId): array
{
    inbound_attachments_ensure_schema();
    $st = db()->prepare('SELECT * FROM inbound_email_attachments WHERE inbound_email_id = :e ORDER BY id ASC');
    $st->execute(['e' => $emailId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Texto de um XML de documento (docx/odt/xlsx) com quebras nos parágrafos e células. */
function inbound_att_xml_to_text(string $xml): string
{
    // Dentro de uma célula de tabela, parágrafo vira espaço (a célula fica numa linha só).
    $xml = preg_replace_callback('~<(w:tc|table:table-cell)\b.*?</\1>~s',
        static fn(array $m): string => preg_replace('~</w:p>|</text:p>~', ' ', $m[0]) ?? $m[0], $xml) ?? $xml;
    $xml = preg_replace(['~</w:p>|</text:p>|</text:h>|<w:br[^>]*/>|<text:line-break/>~', '~<w:tab[^>]*/>|<text:tab/>~', '~</w:tc>|</table:table-cell>~', '~</w:tr>|</table:table-row>~'],
        ["\n", "\t", ' | ', "\n"], $xml) ?? $xml;
    $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace(["/[ \t]*\|[ \t]*\n/", "/[ \t]+\n/", "/[ \t]{2,}/"], ["\n", "\n", ' '], $text) ?? $text;
    return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
}

function inbound_att_xlsx_text(ZipArchive $zip): string
{
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($ss) && preg_match_all('~<si>(.*?)</si>~s', $ss, $m)) {
        foreach ($m[1] as $si) {
            $shared[] = html_entity_decode(strip_tags($si), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!preg_match('~^xl/worksheets/sheet\d+\.xml$~', $name)) {
            continue;
        }
        $xml = (string)$zip->getFromIndex($i);
        if (!preg_match_all('~<row[^>]*>(.*?)</row>~s', $xml, $rows)) {
            continue;
        }
        $out[] = '[Planilha ' . basename($name, '.xml') . ']';
        foreach ($rows[1] as $row) {
            $cells = [];
            if (preg_match_all('~<c([^>]*?)(?:/>|>(.*?)</c>)~s', $row, $cs, PREG_SET_ORDER)) {
                foreach ($cs as $c) {
                    $body = $c[2] ?? '';
                    $val = '';
                    if (preg_match('~<v>(.*?)</v>~s', $body, $v)) {
                        $val = $v[1];
                        if (preg_match('~t="s"~', $c[1])) {
                            $val = $shared[(int)$val] ?? '';
                        }
                    } elseif (preg_match('~<is>(.*?)</is>~s', $body, $is)) {
                        $val = strip_tags($is[1]);
                    }
                    $val = trim(html_entity_decode((string)$val, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($val !== '') {
                        $cells[] = $val;
                    }
                }
            }
            if ($cells) {
                $out[] = implode(' | ', $cells);
            }
            if (count($out) > 600) {
                break 2;
            }
        }
    }
    return trim(implode("\n", $out));
}

/** Imagens dentro de docx/odt viram anexos próprios (para a IA ler também). */
function inbound_att_queue_embedded_images(array $att, ZipArchive $zip, string $prefix): void
{
    $n = 0;
    for ($i = 0; $i < $zip->numFiles && $n < 5; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!preg_match('~^' . preg_quote($prefix, '~') . '[^/]+\.(png|jpe?g|gif|webp|bmp)$~i', $name, $m)) {
            continue;
        }
        $data = $zip->getFromIndex($i);
        if (!is_string($data) || strlen($data) < 10240) {
            continue;
        }
        $ext = strtolower($m[1]);
        $mime = INBOUND_ATT_EXT_MIME[$ext] ?? 'image/png';
        $saved = inbound_attachments_save((int)$att['inbound_email_id'], mb_substr($att['part_index'] . '-img' . ($i + 1), 0, 40),
            $att['file_name'] . ' - ' . basename($name), $mime, $data, null, false, 'documento');
        if ($saved !== null) {
            $n++;
        }
    }
}

/** Texto de um e-mail anexado (.eml): cabeçalhos principais + corpo. */
function inbound_att_eml_text(string $raw): string
{
    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
    $headers = (string)($parts[0] ?? '');
    $body = (string)($parts[1] ?? '');
    $head = [];
    foreach (['From', 'Subject', 'Date'] as $h) {
        if (preg_match('/^' . $h . ':\s*(.+)$/mi', $headers, $m)) {
            $head[] = $h . ': ' . inbound_att_decode_header(trim($m[1]));
        }
    }
    if (preg_match('/quoted-printable/i', $headers)) {
        $body = quoted_printable_decode($body);
    }
    if (stripos($body, '<html') !== false || stripos($body, '<div') !== false) {
        $body = html_entity_decode(strip_tags(preg_replace('~<(br|/p|/div|/tr)[^>]*>~i', "\n", $body) ?? $body), ENT_QUOTES, 'UTF-8');
    }
    $body = @mb_convert_encoding($body, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252') ?: $body;
    return trim(implode("\n", $head) . "\n\n" . trim($body));
}

/** Modelo da IA para ler imagens/PDF (precisa aceitar imagem; gpt-4o-mini aceita). */
function inbound_att_vision_model(): string
{
    $m = trim((string)admin_setting_get('openai.vision_model', ''));
    if ($m === '') {
        $m = trim((string)admin_setting_get('openai.model', 'gpt-4o-mini'));
    }
    return $m !== '' ? $m : 'gpt-4o-mini';
}

/** Pede à IA a transcrição de uma imagem ou PDF. */
function inbound_att_ai_read(OpenAiApi $api, string $name, string $mime, string $data): array
{
    $b64 = base64_encode($data);
    $filePart = $mime === 'application/pdf'
        ? ['type' => 'file', 'file' => ['filename' => $name, 'file_data' => 'data:application/pdf;base64,' . $b64]]
        : ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64, 'detail' => 'high']];
    $messages = [
        ['role' => 'system', 'content' => "Você transcreve anexos de e-mails de solicitação de atendimento domiciliar (home care).\n"
            . "Transcreva FIELMENTE todo o texto do arquivo, em português, mantendo a ordem e os rótulos "
            . "(ex.: Paciente, Beneficiário, Endereço, CEP, Bairro, Cidade, Telefone, Contato, Especialidade, Frequência, Diagnóstico).\n"
            . "Tabelas: uma linha por linha da tabela, colunas separadas por ' | '. Não resuma, não invente, não corrija dados.\n"
            . "Se for só logotipo, assinatura, ícone ou imagem sem informação útil, responda exatamente [SEM_DADOS]."],
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Arquivo: ' . $name . '. Transcreva o conteúdo.'],
            $filePart,
        ]],
    ];
    $model = inbound_att_vision_model();
    $res = $api->chatCompletions($messages, $model, ['temperature' => 0.0, 'max_tokens' => 4000]);
    $status = (int)($res['status'] ?? 0);
    // Modelo configurado sem suporte a imagem/arquivo: tenta o gpt-4o-mini.
    if (($status === 400 || $status === 404) && $model !== 'gpt-4o-mini') {
        $res = $api->chatCompletions($messages, 'gpt-4o-mini', ['temperature' => 0.0, 'max_tokens' => 4000]);
        $status = (int)($res['status'] ?? 0);
    }
    if ($status < 200 || $status >= 300) {
        $msg = is_array($res['json'] ?? null) ? (string)($res['json']['error']['message'] ?? '') : '';
        return ['status' => 'error', 'text' => '', 'error' => 'IA: ' . ($msg !== '' ? $msg : 'HTTP ' . $status)];
    }
    $text = trim((string)($res['json']['choices'][0]['message']['content'] ?? ''));
    if ($text === '' || (stripos($text, '[SEM_DADOS]') !== false && mb_strlen($text) < 40)) {
        return ['status' => 'empty', 'text' => '', 'error' => null];
    }
    return ['status' => 'done', 'text' => $text, 'error' => null];
}

/**
 * Lê o texto de um anexo.
 *
 * @return array{status:string, text:string, error:?string}
 */
function inbound_attachment_read(?OpenAiApi $api, array $att): array
{
    $path = (string)($att['file_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        return ['status' => 'error', 'text' => '', 'error' => 'Arquivo não encontrado no servidor'];
    }
    $data = (string)file_get_contents($path);
    $mime = strtolower((string)$att['mime_type']);
    $name = (string)$att['file_name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (str_starts_with($mime, 'image/')) {
        if (in_array($mime, ['image/bmp', 'image/x-ms-bmp'], true) && function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($data); // BMP -> PNG (a IA não aceita BMP)
            if ($img) {
                ob_start();
                imagepng($img);
                $data = (string)ob_get_clean();
                imagedestroy($img);
                $mime = 'image/png';
            }
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return ['status' => 'unsupported', 'text' => '', 'error' => 'Formato de imagem não suportado (' . $mime . ')'];
        }
        if ($api === null) {
            return ['status' => 'pending', 'text' => '', 'error' => 'IA indisponível'];
        }
        return inbound_att_ai_read($api, $name, $mime, $data);
    }
    if ($mime === 'application/pdf' || $ext === 'pdf') {
        if ($api === null) {
            return ['status' => 'pending', 'text' => '', 'error' => 'IA indisponível'];
        }
        return inbound_att_ai_read($api, $name, 'application/pdf', $data);
    }
    $isZipDoc = in_array($ext, ['docx', 'xlsx', 'odt'], true) || str_contains($mime, 'openxmlformats') || str_contains($mime, 'opendocument');
    if ($isZipDoc) {
        if (!class_exists('ZipArchive')) {
            return ['status' => 'unsupported', 'text' => '', 'error' => 'Extensão ZIP do PHP indisponível'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'iea');
        file_put_contents($tmp, $data);
        $zip = new ZipArchive();
        $text = '';
        try {
            if ($zip->open($tmp) !== true) {
                return ['status' => 'error', 'text' => '', 'error' => 'Documento corrompido'];
            }
            if ($zip->locateName('word/document.xml') !== false) {
                $text = inbound_att_xml_to_text((string)$zip->getFromName('word/document.xml'));
                inbound_att_queue_embedded_images($att, $zip, 'word/media/');
            } elseif ($zip->locateName('xl/workbook.xml') !== false) {
                $text = inbound_att_xlsx_text($zip);
            } elseif ($zip->locateName('content.xml') !== false) {
                $text = inbound_att_xml_to_text((string)$zip->getFromName('content.xml'));
                inbound_att_queue_embedded_images($att, $zip, 'Pictures/');
            } else {
                return ['status' => 'unsupported', 'text' => '', 'error' => 'Tipo de documento não reconhecido'];
            }
        } finally {
            @$zip->close();
            @unlink($tmp);
        }
        return $text !== '' ? ['status' => 'done', 'text' => $text, 'error' => null] : ['status' => 'empty', 'text' => '', 'error' => null];
    }
    if ($mime === 'message/rfc822' || $ext === 'eml') {
        $text = inbound_att_eml_text($data);
        return $text !== '' ? ['status' => 'done', 'text' => $text, 'error' => null] : ['status' => 'empty', 'text' => '', 'error' => null];
    }
    if (str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'csv', 'htm', 'html', 'xml'], true)) {
        $text = @mb_convert_encoding($data, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252') ?: $data;
        if (str_contains($mime, 'html') || in_array($ext, ['htm', 'html'], true)) {
            $text = html_entity_decode(strip_tags(preg_replace('~<(br|/p|/div|/tr|/li)[^>]*>~i', "\n", $text) ?? $text), ENT_QUOTES, 'UTF-8');
        }
        $text = trim($text);
        return $text !== '' ? ['status' => 'done', 'text' => $text, 'error' => null] : ['status' => 'empty', 'text' => '', 'error' => null];
    }
    return ['status' => 'unsupported', 'text' => '', 'error' => 'Tipo de arquivo não lido (' . ($ext !== '' ? $ext : $mime) . ')'];
}

/** Lê os anexos ainda pendentes e grava o texto (limite por execução). */
function inbound_attachments_process(int $emailId, ?OpenAiApi $api, bool $retryErrors = false): void
{
    $db = db();
    $upd = $db->prepare('UPDATE inbound_email_attachments SET extraction_status = :st, extracted_text = :t,
        extraction_error = :err, extracted_at = NOW() WHERE id = :id');
    $done = 0;
    // Duas voltas: imagens de dentro de documentos entram na lista durante a 1ª.
    for ($round = 0; $round < 2 && $done < INBOUND_ATT_MAX_EXTRACT_PER_RUN; $round++) {
        $statuses = $retryErrors ? ['pending', 'error'] : ['pending'];
        foreach (inbound_attachments_list($emailId) as $att) {
            if (!in_array($att['extraction_status'], $statuses, true)) {
                continue;
            }
            if ($done >= INBOUND_ATT_MAX_EXTRACT_PER_RUN) {
                break;
            }
            try {
                $r = inbound_attachment_read($api, $att);
            } catch (Throwable $e) {
                $r = ['status' => 'error', 'text' => '', 'error' => mb_substr($e->getMessage(), 0, 300)];
            }
            if ($r['status'] === 'pending') {
                continue; // IA indisponível: tenta na próxima vez
            }
            $upd->execute([
                'st' => $r['status'],
                't' => $r['text'] !== '' ? mb_substr($r['text'], 0, INBOUND_ATT_MAX_TEXT) : null,
                'err' => $r['error'] !== null ? mb_substr((string)$r['error'], 0, 500) : null,
                'id' => (int)$att['id'],
            ]);
            $done++;
        }
        $retryErrors = false;
    }
}

/**
 * Texto de todos os anexos do e-mail, pronto para juntar ao corpo antes da IA
 * montar o card. Busca os anexos no IMAP se ainda não foram buscados.
 */
function inbound_attachments_extracted_text(int $emailId, ?OpenAiApi $api, bool $retryErrors = false): string
{
    inbound_attachments_ensure_schema();
    try {
        $st = db()->prepare('SELECT attachments_fetched_at FROM inbound_emails WHERE id = :id');
        $st->execute(['id' => $emailId]);
        if ($st->fetchColumn() === null) {
            $r = inbound_attachments_fetch_for_email($emailId);
            error_log('[INBOUND_ATT] e-mail #' . $emailId . ' busca no IMAP: ' . $r['message'] . ' (' . $r['saved'] . ' anexo(s))');
        }
    } catch (Throwable $e) {
        error_log('[INBOUND_ATT] busca IMAP #' . $emailId . ': ' . $e->getMessage());
    }
    inbound_attachments_process($emailId, $api, $retryErrors);

    $blocks = [];
    $total = 0;
    foreach (inbound_attachments_list($emailId) as $att) {
        $text = trim((string)($att['extracted_text'] ?? ''));
        if ($att['extraction_status'] !== 'done' || $text === '') {
            continue;
        }
        $label = str_starts_with((string)$att['mime_type'], 'image/') ? 'imagem' : 'arquivo';
        $block = '--- ' . ucfirst($label) . ': ' . $att['file_name'] . " ---\n" . $text;
        if ($total + mb_strlen($block) > INBOUND_ATT_MAX_TOTAL_TEXT) {
            $block = mb_substr($block, 0, max(0, INBOUND_ATT_MAX_TOTAL_TEXT - $total)) . '…';
        }
        $blocks[] = $block;
        $total += mb_strlen($block);
        if ($total >= INBOUND_ATT_MAX_TOTAL_TEXT) {
            break;
        }
    }
    if (!$blocks) {
        return '';
    }
    return "=== TEXTO LIDO DOS ANEXOS E IMAGENS DO E-MAIL ===\n\n" . implode("\n\n", $blocks);
}

/**
 * Endereço pelo CEP (ViaCEP). Usado quando a IA achou o CEP mas não a rua/cidade.
 *
 * @return array{street:string, neighborhood:string, city:string, state:string}|null
 */
function inbound_cep_lookup(string $zip): ?array
{
    $zip = preg_replace('/\D+/', '', $zip) ?? '';
    if (strlen($zip) !== 8) {
        return null;
    }
    try {
        $res = http_json_request('GET', 'https://viacep.com.br/ws/' . $zip . '/json/', [], null, 6);
        $j = $res['json'] ?? null;
        if ((int)($res['status'] ?? 0) !== 200 || !is_array($j) || !empty($j['erro'])) {
            return null;
        }
        $state = strtoupper(trim((string)($j['uf'] ?? '')));
        return [
            'street' => trim((string)($j['logradouro'] ?? '')),
            'neighborhood' => trim((string)($j['bairro'] ?? '')),
            'city' => trim((string)($j['localidade'] ?? '')),
            'state' => preg_match('/^[A-Z]{2}$/', $state) ? $state : '',
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** Rótulo do status do anexo para as telas. */
function inbound_attachment_status_label(string $status): string
{
    return [
        'pending' => 'Aguardando leitura',
        'done' => 'Lido',
        'empty' => 'Sem dados (logo/assinatura)',
        'unsupported' => 'Formato não lido',
        'skipped' => 'Ignorado',
        'error' => 'Erro na leitura',
    ][$status] ?? $status;
}

/** Bloco HTML com a lista de anexos (tela do e-mail e do card). */
function inbound_attachments_render_list(array $atts): string
{
    if (!$atts) {
        return '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Nenhum anexo ou imagem encontrado neste e-mail.</div>';
    }
    $html = '<div style="display:flex;flex-direction:column;gap:8px">';
    foreach ($atts as $a) {
        $st = (string)$a['extraction_status'];
        $color = $st === 'done' ? 'hsl(142,76%,36%)' : ($st === 'error' ? 'hsl(0,84%,60%)' : 'hsl(var(--muted-foreground))');
        $kb = max(1, (int)round((int)$a['size_bytes'] / 1024));
        $html .= '<div style="border:1px solid hsl(var(--border));border-radius:8px;padding:10px 12px">';
        $html .= '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">';
        $html .= '<div style="min-width:0;overflow-wrap:anywhere"><strong>' . (str_starts_with((string)$a['mime_type'], 'image/') ? '🖼️ ' : '📄 ') . h((string)$a['file_name']) . '</strong>'
            . ' <span style="font-size:12px;color:hsl(var(--muted-foreground))">' . $kb . ' KB</span></div>';
        $html .= '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        $html .= '<span style="font-size:12px;font-weight:700;color:' . $color . '">' . h(inbound_attachment_status_label($st)) . '</span>';
        if (!empty($a['file_path'])) {
            $html .= '<a class="btn" style="padding:4px 10px;font-size:12px" target="_blank" rel="noopener" href="/inbound_email_attachment.php?id=' . (int)$a['id'] . '">Abrir</a>';
        }
        $html .= '</div></div>';
        if (!empty($a['extraction_error']) && $st !== 'done') {
            $html .= '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">' . h((string)$a['extraction_error']) . '</div>';
        }
        $text = trim((string)($a['extracted_text'] ?? ''));
        if ($text !== '') {
            $html .= '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:12px;font-weight:600">Ver texto lido</summary>'
                . '<pre style="white-space:pre-wrap;font-size:12px;margin:6px 0 0;max-height:300px;overflow:auto">' . h($text) . '</pre></details>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}
