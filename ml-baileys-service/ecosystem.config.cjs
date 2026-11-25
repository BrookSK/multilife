// pm2 start ecosystem.config.cjs
// Lições da Evolution aplicadas: memória com sufixo G (o pm2 lê número puro como
// bytes), logs com rotação (pm2 install pm2-logrotate; 50M por arquivo, 7 cópias).
module.exports = {
  apps: [
    {
      name: 'ml-baileys',
      script: 'app.js',
      cwd: __dirname,
      instances: 1,
      exec_mode: 'fork',
      node_args: '--max-old-space-size=2048',
      max_memory_restart: '2G',
      kill_timeout: 10000,
      restart_delay: 3000,
      env: {
        NODE_ENV: 'production',
      },
      out_file: './logs/out.log',
      error_file: './logs/error.log',
      merge_logs: true,
      time: true,
    },
  ],
};
