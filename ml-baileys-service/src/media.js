// Mídia: download (com reenvio do arquivo pelo celular quando o link expira) e
// conversão de áudio para nota de voz (ogg/opus) via ffmpeg, quando instalado.
import { spawn } from 'node:child_process';
import { downloadMediaMessage, normalizeMessageContent, getContentType } from 'baileys';
import { config } from './config.js';
import { baileysLogger, logger } from './logger.js';
import { MEDIA_TYPES, tsToNumber } from './util.js';

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
  if (!ffmpegOk) logger.warn('ffmpeg não encontrado: áudio enviado como está (pode não tocar como nota de voz)');
  return ffmpegOk;
}

/** Converte qualquer áudio para ogg/opus (nota de voz). Sem ffmpeg devolve null. */
export async function toVoiceNote(input) {
  if (!(await hasFfmpeg())) return null;
  let src = input;
  if (!Buffer.isBuffer(input)) {
    const res = await fetch(input.url);
    if (!res.ok) throw new Error('download do áudio falhou: HTTP ' + res.status);
    src = Buffer.from(await res.arrayBuffer());
  }
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
