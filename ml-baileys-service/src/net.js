// HTTP de saída do serviço para o SISTEMA (webhook e mídia enviada por link).
//
// Na hospedagem compartilhada o servidor pode não conseguir abrir o próprio
// endereço público (conexão recusada). Quando isso acontece, a requisição vai
// para o IP interno do servidor (informado pelo PHP) ou 127.0.0.1, mantendo o
// domínio no SNI, no certificado e no cabeçalho Host: é o mesmo site, só que
// pelo caminho de dentro. O caminho que funcionou fica lembrado por domínio e o
// público é testado de novo a cada 10 minutos.
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import { config } from './config.js';
import { ev } from './events.js';

// Erros de rede em que vale tentar o endereço interno do servidor
const ROUTE_ERRORS = new Set(['ECONNREFUSED', 'EHOSTUNREACH', 'ENETUNREACH', 'ETIMEDOUT', 'ENOTFOUND', 'EAI_AGAIN', 'ECONNRESET', 'TIMEOUT']);
const ROUTE_RECHECK_MS = 10 * 60 * 1000;
/** Caminho que funcionou por domínio: host -> {ip, at}. ip null = DNS normal. */
const routes = new Map();

export const errText = (e) => (e.code === 'TIMEOUT' ? e.message : `${e.message}${e.code && !String(e.message).includes(e.code) ? ' (' + e.code + ')' : ''}`);

/**
 * Uma requisição. Com `ip`, conecta nesse IP mas mantém o domínio da URL.
 * @param {{method?:string, body?:string|Buffer, headers?:object, timeoutMs:number, ip?:string|null, maxBytes?:number}} o
 * @returns {Promise<{status:number, headers:object, body:Buffer}>}
 */
function request(url, o) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const lib = u.protocol === 'https:' ? https : http;
    const ip = o.ip ?? null;
    const family = ip && net.isIPv6(ip) ? 6 : 4;
    const headers = { ...(o.headers ?? {}) };
    if (o.body !== undefined) headers['Content-Length'] = Buffer.byteLength(o.body);
    const maxBytes = o.maxBytes ?? 2000;
    const req = lib.request(u, {
      method: o.method ?? 'GET',
      headers,
      agent: false,
      ...(ip ? {
        lookup: (_host, opts, cb) => (opts && opts.all ? cb(null, [{ address: ip, family }]) : cb(null, ip, family)),
        servername: u.hostname,
      } : {}),
    }, (res) => {
      const chunks = [];
      let size = 0;
      res.on('data', (c) => {
        size += c.length;
        if (size > maxBytes && o.maxBytes !== undefined && o.failOnLimit) {
          const e = new Error(`arquivo maior que ${Math.round(maxBytes / 1048576)} MB`);
          e.code = 'TOO_LARGE';
          req.destroy(e);
          return;
        }
        if (size <= maxBytes) chunks.push(c);
      });
      res.on('end', () => resolve({ status: res.statusCode ?? 0, headers: res.headers, body: Buffer.concat(chunks) }));
      res.on('error', reject);
    });
    req.setTimeout(o.timeoutMs, () => {
      const e = new Error('timeout ' + o.timeoutMs + ' ms');
      e.code = 'TIMEOUT';
      req.destroy(e);
    });
    req.on('error', reject);
    req.end(o.body);
  });
}

/**
 * Requisição escolhendo o caminho (público ou interno) e lembrando o que deu certo.
 * @param {string} url
 * @param {object} o opções de request()
 * @param {{instance?:string, purpose?:string}} ctx para o log
 * @returns {Promise<{res?:{status:number, headers:object, body:Buffer}, error?:Error, via:string|null}>}
 */
export async function requestWithRoute(url, o, ctx = {}) {
  const host = new URL(url).hostname;
  const known = routes.get(host);
  const tryLocalFirst = known && known.ip && Date.now() - known.at < ROUTE_RECHECK_MS;
  const order = tryLocalFirst
    ? [known.ip, null, ...config.webhook.localIps.filter((ip) => ip !== known.ip)]
    : [null, ...config.webhook.localIps];
  if (net.isIP(host)) order.splice(1); // URL já é um IP: sem caminho alternativo
  let lastErr = null;
  const tried = [];
  for (const ip of order) {
    try {
      const res = await request(url, { ...o, ip, timeoutMs: ip ? Math.min(o.timeoutMs, 15000) : o.timeoutMs });
      const before = routes.get(host)?.ip ?? null;
      routes.set(host, { ip, at: Date.now() });
      if (before !== ip) {
        ev(ip ? 'warn' : 'info', ctx.purpose === 'midia' ? 'midia' : 'webhook', ip ? 'rota_interna' : 'rota_publica_voltou', {
          instance: ctx.instance,
          http: res.status,
          summary: ip
            ? `O endereço público do sistema (${host}) recusou a conexão; o serviço passou a usar o endereço interno do servidor (${ip}), com o mesmo domínio.`
            : `O endereço público do sistema (${host}) voltou a responder: o serviço usa ele de novo.`,
          details: { host, ip, erroAntes: lastErr ? errText(lastErr) : null, para: ctx.purpose ?? 'webhook' },
        });
      }
      return { res, via: ip };
    } catch (e) {
      lastErr = e;
      tried.push((ip ? 'interno ' + ip : 'público') + ': ' + errText(e));
      // Só erro de rede justifica trocar de caminho (erro HTTP é resposta do site)
      if (!ROUTE_ERRORS.has(e.code)) break;
    }
  }
  if (lastErr && tried.length > 1) {
    const combined = new Error(tried.join(' | '));
    combined.code = lastErr.code;
    return { error: combined, via: null };
  }
  return { error: lastErr, via: null };
}

/**
 * Baixa um arquivo por URL (mídia enviada por link), com o mesmo desvio de
 * caminho e até 3 redirecionamentos.
 * @returns {Promise<{buffer:Buffer, mimetype:string|null}>}
 */
export async function download(url, ctx = {}) {
  let current = url;
  for (let hop = 0; hop < 4; hop++) {
    const { res, error } = await requestWithRoute(current, {
      method: 'GET', timeoutMs: 60000, maxBytes: config.maxMediaBytes, failOnLimit: true,
      headers: { 'User-Agent': 'ml-baileys-service' },
    }, { ...ctx, purpose: 'midia' });
    if (error) throw new Error('download falhou (' + current + '): ' + errText(error));
    if (res.status >= 300 && res.status < 400 && res.headers.location) {
      current = new URL(res.headers.location, current).toString();
      continue;
    }
    if (res.status < 200 || res.status >= 300) throw new Error('download falhou (' + current + '): HTTP ' + res.status);
    const ct = res.headers['content-type'];
    return { buffer: res.body, mimetype: ct ? String(ct).split(';')[0].trim() : null };
  }
  throw new Error('download falhou: redirecionamentos demais (' + url + ')');
}
