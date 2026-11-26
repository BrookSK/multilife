<?php

declare(strict_types=1);

/**
 * WebM (Opus) -> Ogg (Opus) sem recodificar, em PHP puro.
 *
 * O áudio gravado no navegador (Chrome/Edge) vem em WebM com Opus; a API oficial
 * do WhatsApp só aceita nota de voz em Ogg/Opus e a hospedagem não tem ffmpeg.
 * Só troca o "envelope": os pacotes Opus são os mesmos. Mesma lógica de
 * ml-baileys-service/src/oggOpus.js.
 */

function wa_ogg_is_webm(string $bin): bool
{
    return strlen($bin) > 4 && strncmp($bin, "\x1a\x45\xdf\xa3", 4) === 0;
}

function wa_ogg_is_ogg(string $bin): bool
{
    return strlen($bin) > 4 && strncmp($bin, 'OggS', 4) === 0;
}

/** Inteiro de tamanho variável do EBML. */
function wa_ogg_vint(string $buf, int $pos, bool $keepMarker): ?array
{
    if (!isset($buf[$pos])) {
        return null;
    }
    $first = ord($buf[$pos]);
    if ($first === 0) {
        return null;
    }
    $len = 1;
    while ($len <= 8 && !($first & (0x80 >> ($len - 1)))) {
        $len++;
    }
    if ($len > 8 || $pos + $len > strlen($buf)) {
        return null;
    }
    $mask = 0xff >> $len;
    $value = $keepMarker ? $first : ($first & $mask);
    $allOnes = ($first & $mask) === $mask;
    for ($i = 1; $i < $len; $i++) {
        $b = ord($buf[$pos + $i]);
        $value = $value * 256 + $b;
        if ($b !== 0xff) {
            $allOnes = false;
        }
    }
    return ['value' => $value, 'len' => $len, 'unknown' => !$keepMarker && $allOnes];
}

/** Amostras (48 kHz) de um pacote Opus pelo byte TOC. */
function wa_ogg_opus_samples(string $packet): int
{
    if ($packet === '') {
        return 0;
    }
    $toc = ord($packet[0]);
    $cfg = $toc >> 3;
    if ($cfg < 12) {
        $ms = [10, 20, 40, 60][$cfg % 4];
    } elseif ($cfg < 16) {
        $ms = [10, 20][$cfg % 2];
    } else {
        $ms = [2.5, 5, 10, 20][$cfg % 4];
    }
    $code = $toc & 3;
    $frames = 1;
    if ($code === 1 || $code === 2) {
        $frames = 2;
    } elseif ($code === 3) {
        $frames = strlen($packet) > 1 ? (ord($packet[1]) & 0x3f) : 0;
    }
    return (int)round($frames * $ms * 48);
}

/** @return array{packets:string[], codecPrivate:?string, channels:int}|null */
function wa_ogg_parse_webm(string $buf): ?array
{
    $DESCEND = [0x18538067 => 1, 0x1f43b675 => 1, 0xa0 => 1, 0x1654ae6b => 1, 0xae => 1, 0xe1 => 1];
    $pos = 0;
    $n = strlen($buf);
    $codec = null;
    $codecPrivate = null;
    $channels = 1;
    $audioTrack = null;
    $currentTrack = null;
    $packets = [];
    while ($pos < $n) {
        $id = wa_ogg_vint($buf, $pos, true);
        if (!$id) {
            break;
        }
        $size = wa_ogg_vint($buf, $pos + $id['len'], false);
        if (!$size) {
            break;
        }
        $start = $pos + $id['len'] + $size['len'];
        if (isset($DESCEND[$id['value']])) {
            $pos = $start;
            continue;
        }
        if ($size['unknown']) {
            break;
        }
        $end = $start + (int)$size['value'];
        if ($end > $n) {
            break;
        }
        $data = substr($buf, $start, (int)$size['value']);
        switch ($id['value']) {
            case 0xd7: // TrackNumber
                $v = 0;
                for ($i = 0, $l = min(6, strlen($data)); $i < $l; $i++) {
                    $v = $v * 256 + ord($data[$i]);
                }
                $currentTrack = $v;
                break;
            case 0x86: // CodecID
                if ($data === 'A_OPUS') {
                    $codec = 'A_OPUS';
                    $audioTrack = $currentTrack;
                }
                break;
            case 0x63a2: // CodecPrivate
                if ($currentTrack === $audioTrack || $audioTrack === null) {
                    $codecPrivate = $data;
                }
                break;
            case 0x9f: // Channels
                $channels = $data !== '' ? (ord($data[0]) ?: 1) : 1;
                break;
            case 0xa3: // SimpleBlock
            case 0xa1: // Block
                $tn = wa_ogg_vint($data, 0, false);
                if (!$tn) {
                    break;
                }
                if ($audioTrack !== null && $tn['value'] !== $audioTrack) {
                    break;
                }
                if (!isset($data[$tn['len'] + 2])) {
                    break;
                }
                $flags = ord($data[$tn['len'] + 2]);
                $lacing = ($flags >> 1) & 3;
                $payload = substr($data, $tn['len'] + 3);
                if ($lacing === 0) {
                    $packets[] = $payload;
                } elseif ($lacing === 2) {
                    $count = ord($payload[0]) + 1;
                    $body = substr($payload, 1);
                    $each = intdiv(strlen($body), $count);
                    for ($i = 0; $i < $count; $i++) {
                        $packets[] = substr($body, $i * $each, $each);
                    }
                } else {
                    throw new RuntimeException('WebM com lacing não suportado');
                }
                break;
        }
        $pos = $end;
    }
    if ($codec !== 'A_OPUS' || !$packets) {
        return null;
    }
    return ['packets' => $packets, 'codecPrivate' => $codecPrivate, 'channels' => $channels];
}

function wa_ogg_crc_table(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = [];
    for ($i = 0; $i < 256; $i++) {
        $r = $i << 24;
        for ($j = 0; $j < 8; $j++) {
            $r = ($r & 0x80000000) ? ((($r << 1) ^ 0x04c11db7) & 0xffffffff) : (($r << 1) & 0xffffffff);
        }
        $t[$i] = $r & 0xffffffff;
    }
    return $t;
}

function wa_ogg_crc32(string $buf): int
{
    $t = wa_ogg_crc_table();
    $crc = 0;
    for ($i = 0, $n = strlen($buf); $i < $n; $i++) {
        $crc = ((($crc << 8) & 0xffffffff) ^ $t[(($crc >> 24) ^ ord($buf[$i])) & 0xff]) & 0xffffffff;
    }
    return $crc;
}

/** @param string[] $packets */
function wa_ogg_page(array $packets, int $granule, int $serial, int $seq, int $headerType): string
{
    $lacing = '';
    $segs = 0;
    foreach ($packets as $p) {
        $len = strlen($p);
        while ($len >= 255) {
            $lacing .= chr(255);
            $len -= 255;
            $segs++;
        }
        $lacing .= chr($len);
        $segs++;
    }
    $header = 'OggS' . chr(0) . chr($headerType) . pack('P', $granule) . pack('V', $serial) . pack('V', $seq) . pack('V', 0) . chr($segs) . $lacing;
    $page = $header . implode('', $packets);
    $crc = wa_ogg_crc32($page);
    return substr($page, 0, 22) . pack('V', $crc) . substr($page, 26);
}

function wa_ogg_opus_head(int $channels): string
{
    return 'OpusHead' . chr(1) . chr($channels) . pack('v', 312) . pack('V', 48000) . pack('v', 0) . chr(0);
}

function wa_ogg_opus_tags(): string
{
    $vendor = 'multilife';
    return 'OpusTags' . pack('V', strlen($vendor)) . $vendor . pack('V', 0);
}

/** WebM/Opus -> Ogg/Opus. null se não for WebM com Opus. */
function wa_ogg_from_webm(string $buf): ?string
{
    if (!wa_ogg_is_webm($buf)) {
        return null;
    }
    try {
        $parsed = wa_ogg_parse_webm($buf);
    } catch (Throwable $e) {
        return null;
    }
    if (!$parsed) {
        return null;
    }
    $serial = random_int(1, 0x7fffffff);
    $head = ($parsed['codecPrivate'] !== null && strncmp($parsed['codecPrivate'], 'OpusHead', 8) === 0)
        ? $parsed['codecPrivate'] : wa_ogg_opus_head($parsed['channels']);
    $out = wa_ogg_page([$head], 0, $serial, 0, 0x02) . wa_ogg_page([wa_ogg_opus_tags()], 0, $serial, 1, 0);
    $seq = 2;
    $granule = 0;
    $batch = [];
    $segs = 0;
    $total = count($parsed['packets']);
    foreach ($parsed['packets'] as $i => $p) {
        $need = intdiv(strlen($p), 255) + 1;
        if ($batch && ($segs + $need > 255 || count($batch) >= 50)) {
            $out .= wa_ogg_page($batch, $granule, $serial, $seq++, 0);
            $batch = [];
            $segs = 0;
        }
        $batch[] = $p;
        $segs += $need;
        $granule += wa_ogg_opus_samples($p);
        if ($i === $total - 1) {
            $out .= wa_ogg_page($batch, $granule, $serial, $seq++, 0x04);
            $batch = [];
        }
    }
    return $out;
}
