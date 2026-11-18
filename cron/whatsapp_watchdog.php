<?php

declare(strict_types=1);

/**
 * Monitor de conexão real do WhatsApp (watchdog da Evolution).
 * Lê o veredito de cada instância, faz 1 reconexão automática quando a conexão
 * fica "zumbi" e avisa o time quando não resolve (ver app/whatsapp_watchdog.php).
 *
 * As telas abertas já rodam isto a cada minuto (polling de notificações). Este
 * cron cobre os horários sem ninguém logado. Agendar a cada 1–2 min:
 *   https://<site>/cron/whatsapp_watchdog.php?token=<cron.token>
 */

require_once __DIR__ . '/_bootstrap.php';

echo wa_watchdog_tick(true) . "\n";
