// WebM (Opus) -> Ogg (Opus) sem recodificar, em JavaScript puro.
//
// O áudio gravado no navegador (MediaRecorder do Chrome/Edge) vem em WebM com
// Opus; o WhatsApp só toca nota de voz em Ogg/Opus. Na hospedagem não há ffmpeg,
// então trocamos só o "envelope" (os pacotes Opus são os mesmos).

const ID = {
  EBML: 0x1a45dfa3,
  SEGMENT: 0x18538067,
  CLUSTER: 0x1f43b675,
  BLOCKGROUP: 0xa0,
  TRACKS: 0x1654ae6b,
  TRACKENTRY: 0xae,
  AUDIO: 0xe1,
  SIMPLEBLOCK: 0xa3,
  BLOCK: 0xa1,
  CODECID: 0x86,
  CODECPRIVATE: 0x63a2,
  TRACKNUMBER: 0xd7,
  CHANNELS: 0x9f,
};
// Elementos "contêiner": entra neles em vez de pular (aceita tamanho desconhecido).
const DESCEND = new Set([ID.SEGMENT, ID.CLUSTER, ID.BLOCKGROUP, ID.TRACKS, ID.TRACKENTRY, ID.AUDIO]);

export const isWebm = (buf) => Buffer.isBuffer(buf) && buf.length > 4 && buf.readUInt32BE(0) === ID.EBML;
export const isOgg = (buf) => Buffer.isBuffer(buf) && buf.length > 4 && buf.toString('latin1', 0, 4) === 'OggS';

function readVint(buf, pos, keepMarker) {
  const first = buf[pos];
  if (first === undefined || first === 0) return null;
  let len = 1;
  while (len <= 8 && !(first & (0x80 >> (len - 1)))) len++;
  if (len > 8 || pos + len > buf.length) return null;
  let value = keepMarker ? first : first & (0xff >> len);
  let allOnes = (first & (0xff >> len)) === (0xff >> len);
  for (let i = 1; i < len; i++) {
    value = value * 256 + buf[pos + i];
    if (buf[pos + i] !== 0xff) allOnes = false;
  }
  return { value, len, unknown: !keepMarker && allOnes };
}

/** Amostras (48 kHz) de um pacote Opus, pelo byte TOC. */
function opusSamples(packet) {
  if (!packet.length) return 0;
  const toc = packet[0];
  const cfg = toc >> 3;
  let ms;
  if (cfg < 12) ms = [10, 20, 40, 60][cfg % 4];
  else if (cfg < 16) ms = [10, 20][cfg % 2];
  else ms = [2.5, 5, 10, 20][cfg % 4];
  const code = toc & 3;
  let frames = 1;
  if (code === 1 || code === 2) frames = 2;
  else if (code === 3) frames = packet.length > 1 ? packet[1] & 0x3f : 0;
  return Math.round(frames * ms * 48);
}

/** Lê o WebM: pacotes Opus da faixa de áudio + OpusHead (CodecPrivate). */
function parseWebm(buf) {
  let pos = 0;
  let codec = null;
  let codecPrivate = null;
  let channels = 1;
  let audioTrack = null;
  let currentTrack = null;
  const packets = [];
  while (pos < buf.length) {
    const id = readVint(buf, pos, true);
    if (!id) break;
    const size = readVint(buf, pos + id.len, false);
    if (!size) break;
    const start = pos + id.len + size.len;
    if (DESCEND.has(id.value)) {
      pos = start;
      continue;
    }
    if (size.unknown) break; // só contêiner pode ter tamanho desconhecido
    const end = start + size.value;
    if (end > buf.length) break;
    const data = buf.subarray(start, end);
    switch (id.value) {
      case ID.TRACKNUMBER:
        currentTrack = data.readUIntBE(0, Math.min(6, data.length));
        break;
      case ID.CODECID:
        if (data.toString('latin1') === 'A_OPUS') {
          codec = 'A_OPUS';
          audioTrack = currentTrack;
        }
        break;
      case ID.CODECPRIVATE:
        if (currentTrack === audioTrack || audioTrack === null) codecPrivate = Buffer.from(data);
        break;
      case ID.CHANNELS:
        channels = data[0] || 1;
        break;
      case ID.SIMPLEBLOCK:
      case ID.BLOCK: {
        const tn = readVint(data, 0, false);
        if (!tn) break;
        if (audioTrack !== null && tn.value !== audioTrack) break;
        const flags = data[tn.len + 2];
        const lacing = (flags >> 1) & 3;
        const payload = data.subarray(tn.len + 3);
        if (lacing === 0) {
          packets.push(Buffer.from(payload));
        } else if (lacing === 2) {
          // tamanho fixo: n quadros iguais
          const n = payload[0] + 1;
          const body = payload.subarray(1);
          const each = Math.floor(body.length / n);
          for (let i = 0; i < n; i++) packets.push(Buffer.from(body.subarray(i * each, (i + 1) * each)));
        } else {
          throw new Error('WebM com lacing não suportado');
        }
        break;
      }
      default:
        break;
    }
    pos = end;
  }
  if (codec !== 'A_OPUS' || !packets.length) return null;
  return { packets, codecPrivate, channels };
}

// CRC do Ogg (polinômio 0x04c11db7, sem reflexão)
const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let i = 0; i < 256; i++) {
    let r = i << 24;
    for (let j = 0; j < 8; j++) r = r & 0x80000000 ? ((r << 1) ^ 0x04c11db7) >>> 0 : (r << 1) >>> 0;
    t[i] = r >>> 0;
  }
  return t;
})();
function crc32(buf) {
  let crc = 0;
  for (let i = 0; i < buf.length; i++) crc = ((crc << 8) ^ CRC_TABLE[((crc >>> 24) ^ buf[i]) & 0xff]) >>> 0;
  return crc >>> 0;
}

function oggPage(packets, granule, serial, seq, headerType) {
  const lacing = [];
  for (const p of packets) {
    let n = p.length;
    while (n >= 255) {
      lacing.push(255);
      n -= 255;
    }
    lacing.push(n);
  }
  const header = Buffer.alloc(27 + lacing.length);
  header.write('OggS', 0, 'latin1');
  header[4] = 0;
  header[5] = headerType;
  header.writeBigUInt64LE(BigInt(granule), 6);
  header.writeUInt32LE(serial, 14);
  header.writeUInt32LE(seq, 18);
  header.writeUInt32LE(0, 22);
  header[26] = lacing.length;
  Buffer.from(lacing).copy(header, 27);
  const page = Buffer.concat([header, ...packets]);
  page.writeUInt32LE(crc32(page), 22);
  return page;
}

function opusHead(channels) {
  const b = Buffer.alloc(19);
  b.write('OpusHead', 0, 'latin1');
  b[8] = 1;
  b[9] = channels;
  b.writeUInt16LE(312, 10);
  b.writeUInt32LE(48000, 12);
  b.writeInt16LE(0, 16);
  b[18] = 0;
  return b;
}

function opusTags() {
  const vendor = Buffer.from('ml-baileys-service', 'utf8');
  const b = Buffer.alloc(8 + 4 + vendor.length + 4);
  b.write('OpusTags', 0, 'latin1');
  b.writeUInt32LE(vendor.length, 8);
  vendor.copy(b, 12);
  b.writeUInt32LE(0, 12 + vendor.length);
  return b;
}

/** WebM/Opus -> Ogg/Opus. null se o arquivo não for WebM com Opus. */
export function webmOpusToOgg(buf) {
  if (!isWebm(buf)) return null;
  const parsed = parseWebm(buf);
  if (!parsed) return null;
  const serial = (Math.random() * 0xffffffff) >>> 0;
  const head = parsed.codecPrivate && parsed.codecPrivate.toString('latin1', 0, 8) === 'OpusHead'
    ? parsed.codecPrivate
    : opusHead(parsed.channels);
  const pages = [oggPage([head], 0, serial, 0, 0x02), oggPage([opusTags()], 0, serial, 1, 0)];
  let seq = 2;
  let granule = 0;
  let batch = [];
  let segs = 0;
  const flush = (last) => {
    if (!batch.length) return;
    pages.push(oggPage(batch, granule, serial, seq++, last ? 0x04 : 0));
    batch = [];
    segs = 0;
  };
  parsed.packets.forEach((p, i) => {
    const need = Math.floor(p.length / 255) + 1;
    if (segs + need > 255 || batch.length >= 50) flush(false);
    batch.push(p);
    segs += need;
    granule += opusSamples(p);
    if (i === parsed.packets.length - 1) flush(true);
  });
  return Buffer.concat(pages);
}
