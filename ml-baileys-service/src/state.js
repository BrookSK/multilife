// Situação do processo (para /health e para as rotas responderem "iniciando").
export const state = {
  startedAt: Date.now(),
  /** configured | db | leader | ready */
  phase: 'aguardando configuração',
  ready: false,
  leader: false,
  lastError: null,
};
