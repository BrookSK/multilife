// Mídia: download (com reenvio do arquivo pelo celular quando o link expira) e
// conversão de áudio para nota de voz (ogg/opus) via ffmpeg, quando instalado.
import { spawn } from 'node:child_process';
import { downloadMediaMessage, normalizeMessageContent, getContentType } from 'baileys';
import { config } from './config.js';
import { baileysLogger, logger } from './logger.js';
import { MEDIA_TYPES, tsToNumber } from './util.js';
import { isOgg, isWebm, webmOpusToOgg } from './oggOpus.js';

/** Conteúdo de mídia da mensagem (desembrulhado) ou null. */
export function mediaContent(message) {
  const m = normalizeMessageContent(message);
  if (!m) return null;
  const type = getContentType(m);
  if (!type || !MEDIA_TYPES.has(type)) return null;
  return { type, content: m[type] };
}

/**
 * Baixa a mídia de uma mensagem.
 * @returns {Promise<{buffer:Buffer, type:string, mimetype:string, fileName:string|null, caption:string|null, size:object}|null>}
 */
export async function downloadMedia(sock, msg) {
  const media = mediaContent(msg?.message);
  if (!media) return null;
  const length = tsToNumber(media.content?.fileLength);
  if (length && length > config.maxMediaBytes) {
    throw new Error(`mídia de ${Math.round(length / 1048576)} MB acima do limite (${Math.round(config.maxMediaBytes / 1048576)} MB)`);
  }
  const buffer = await downloadMediaMessage(
    msg,
    'buffer',
    {},
    { logger: baileysLogger, reuploadRequest: sock ? sock.updateMediaMessage : undefined },
  );
  return {
    buffer,
    type: media.type,
    mimetype: media.content?.mimetype || 'application/octet-stream',
    fileName: media.content?.fileName || null,
    caption: media.content?.caption || null,
    size: {
      fileLength: length || buffer.length,
      height: media.content?.height ?? 0,
      width: media.content?.width ?? 0,
    },
  };
}

let ffmpegOk = null;

/** ffmpeg disponível? (testado uma vez) */
export async function hasFfmpeg() {
  if (ffmpegOk !== null) return ffmpegOk;
  ffmpegOk = await new Promise((resolve) => {
    try {
      const p = spawn(config.ffmpegPath, ['-version'], { stdio: 'ignore' });
      p.on('error', () => resolve(false));
      p.on('close', (code) => resolve(code === 0));
    } catch {
      resolve(false);
    }
  });
  if (!ffmpegOk) logger.info('ffmpeg não encontrado: áudio do navegador (WebM) é convertido sem ele; outros formatos vão como áudio comum');
  return ffmpegOk;
}

/** Tipo do áudio pelo conteúdo. */
function audioMime(buf, fallback) {
  if (isOgg(buf)) return 'audio/ogg; codecs=opus';
  if (isWebm(buf)) return 'audio/webm';
  if (buf.length > 3 && (buf.toString('latin1', 0, 3) === 'ID3' || (buf[0] === 0xff && (buf[1] & 0xe0) === 0xe0))) return 'audio/mpeg';
  if (buf.length > 8 && buf.toString('latin1', 4, 8) === 'ftyp') return 'audio/mp4';
  if (buf.length > 4 && buf.toString('latin1', 0, 4) === 'RIFF') return 'audio/wav';
  return fallback || 'audio/mpeg';
}

/**
 * Prepara um áudio para nota de voz (ogg/opus):
 *  1. já é Ogg: vai como está;
 *  2. WebM/Opus (gravado no navegador): troca o envelope para Ogg, sem ffmpeg;
 *  3. outro formato: converte com ffmpeg, se o servidor tiver;
 *  4. senão: vai como áudio comum (toca normalmente, mas sem o visual de nota de voz).
 * @returns {Promise<{audio: Buffer, mimetype: string, ptt: boolean}>}
 */
export async function prepareVoiceNote(input, fallbackMime) {
  let src = input;
  if (!Buffer.isBuffer(input)) {
    const res = await fetch(input.url);
    if (!res.ok) throw new Error('download do áudio falhou: HTTP ' + res.status);
    src = Buffer.from(await res.arrayBuffer());
  }
  if (isOgg(src)) return { audio: src, mimetype: 'audio/ogg; codecs=opus', ptt: true };
  if (isWebm(src)) {
    try {
      const ogg = webmOpusToOgg(src);
      if (ogg) return { audio: ogg, mimetype: 'audio/ogg; codecs=opus', ptt: true };
    } catch (e) {
      logger.warn({ err: e.message }, 'conversão WebM -> Ogg falhou');
    }
  }
  try {
    const converted = await toVoiceNote(src);
    if (converted) return { audio: converted, mimetype: 'audio/ogg; codecs=opus', ptt: true };
  } catch (e) {
    logger.warn({ err: e.message }, 'conversão do áudio com ffmpeg falhou');
  }
  return { audio: src, mimetype: audioMime(src, fallbackMime), ptt: false };
}

/** Converte qualquer áudio para ogg/opus com ffmpeg. Sem ffmpeg devolve null. */
export async function toVoiceNote(src) {
  if (!(await hasFfmpeg())) return null;
  return new Promise((resolve, reject) => {
    const p = spawn(config.ffmpegPath, ['-hide_banner', '-loglevel', 'error', '-i', 'pipe:0', '-vn', '-ac', '1', '-ar', '48000', '-c:a', 'libopus', '-b:a', '64k', '-f', 'ogg', 'pipe:1']);
    const chunks = [];
    let err = '';
    p.stdout.on('data', (c) => chunks.push(c));
    p.stderr.on('data', (c) => { err += c.toString(); });
    p.on('error', reject);
    p.on('close', (code) => {
      if (code === 0 && chunks.length) resolve(Buffer.concat(chunks));
      else reject(new Error('ffmpeg: ' + (err.trim() || 'código ' + code)));
    });
    p.stdin.on('error', () => {});
    p.stdin.end(src);
  });
}
