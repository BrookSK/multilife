<?php

declare(strict_types=1);

/**
 * Criptografia reversível (AES-256-GCM) para dados sensíveis que precisam ser
 * reenviados depois (ex.: senha do sistema externo do profissional, que vai na
 * orientação da operadora). Antes era gravada em texto puro no banco.
 *
 * Chave: config/config.php → app.secret_key (versionada junto com o código, então
 * não se perde em troca de servidor/deploy). Pode ser sobrescrita pela variável de
 * ambiente MULTILIFE_SECRET_KEY. NÃO altere o valor depois de em uso.
 *
 * Proteção contra perda: se por qualquer motivo a senha não puder ser aberta, o
 * sistema não quebra — devolve vazio e o operador só precisa digitar a senha de
 * novo na pré-admissão (o dado de origem é a operadora, não o sistema).
 *
 * Formato gravado: "enc:v1:" + base64(iv[12] | tag[16] | cifrado).
 * Valores antigos sem esse prefixo são devolvidos como estão (compatibilidade).
 */

const SECURE_STORE_PREFIX = 'enc:v1:';

/** Decodifica uma chave base64 de 32 bytes. */
function secure_store_decode_key($value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $raw = base64_decode($value, true);
    return ($raw !== false && strlen($raw) === 32) ? $raw : null;
}

/** Chave principal (usada para criptografar). */
function secure_store_key(): ?string
{
    static $key = false;
    if ($key !== false) {
        return $key;
    }
    $config = $GLOBALS['config'] ?? null;
    if (!is_array($config)) {
        $config = require dirname(__DIR__) . '/config/config.php';
    }
    $key = secure_store_decode_key($config['app']['secret_key'] ?? null);
    if ($key === null) {
        error_log('[SECURE_STORE] app.secret_key ausente/inválida em config/config.php.');
    }
    return $key;
}

/**
 * Chaves aceitas para ABRIR dados (principal + a de config/secret_key.php, caso
 * algum servidor tenha gerado esse arquivo na versão anterior desta rotina).
 *
 * @return string[]
 */
function secure_store_read_keys(): array
{
    $keys = [];
    $main = secure_store_key();
    if ($main !== null) {
        $keys[] = $main;
    }
    $legacyFile = dirname(__DIR__) . '/config/secret_key.php';
    if (is_file($legacyFile)) {
        $legacy = secure_store_decode_key(include $legacyFile);
        if ($legacy !== null && $legacy !== $main) {
            $keys[] = $legacy;
        }
    }
    return $keys;
}

/** Criptografa. Sem chave/openssl, devolve o texto original (e registra no log). */
function secure_encrypt(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return $plain;
    }
    if (strncmp($plain, SECURE_STORE_PREFIX, strlen(SECURE_STORE_PREFIX)) === 0) {
        return $plain; // já criptografado
    }
    $key = secure_store_key();
    if ($key === null || !function_exists('openssl_encrypt')) {
        error_log('[SECURE_STORE] Sem chave/openssl: valor gravado sem criptografia.');
        return $plain;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        return $plain;
    }
    return SECURE_STORE_PREFIX . base64_encode($iv . $tag . $cipher);
}

/** Descriptografa (valores antigos em texto puro voltam como estão). */
function secure_decrypt(?string $stored): string
{
    $stored = (string)$stored;
    if ($stored === '' || strncmp($stored, SECURE_STORE_PREFIX, strlen(SECURE_STORE_PREFIX)) !== 0) {
        return $stored;
    }
    $bin = base64_decode(substr($stored, strlen(SECURE_STORE_PREFIX)), true);
    if ($bin === false || strlen($bin) < 29) {
        return '';
    }
    foreach (secure_store_read_keys() as $key) {
        $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        if ($plain !== false) {
            return $plain;
        }
    }
    error_log('[SECURE_STORE] Não foi possível abrir um valor criptografado (chave diferente).');
    return '';
}
