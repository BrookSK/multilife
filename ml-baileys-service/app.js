// Arquivo de início para o painel da hospedagem (Plesk/cPanel "Node.js",
// campo "Arquivo de inicialização" / "Application startup file") e para o pm2.
// O serviço inteiro está em dist/server.cjs (gerado por "npm run build"),
// então o servidor NÃO precisa de "npm install".
require('./dist/server.cjs');
