# Deploy em produção

Passo a passo para colocar o OpenMenuExpress no ar no seu domínio.

## 1. Servidor

| Item    | Mínimo                                                                                                            |
| ------- | ----------------------------------------------------------------------------------------------------------------- |
| Sistema | Ubuntu 22.04 ou 24.04                                                                                             |
| Memória | 2 GB (4 GB recomendado)                                                                                           |
| PHP     | 8.3 ou 8.4, com bcmath, ctype, curl, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, pcntl, tokenizer, xml, zip |
| Banco   | MySQL 8.0+ ou MariaDB 10.6+ (MySQL 5.6/5.7 **não** funcionam: faltam tipos usados pelo sistema)                   |
| Web     | nginx com PHP-FPM                                                                                                 |
| Outros  | Composer 2, Supervisor, cron. Node **não** é necessário (o build é feito no GitHub)                               |

No aaPanel: instale o PHP 8.4 pela loja de apps (convive com o 7.4), um MySQL 8 e o
"Supervisor manager". Crie o site com a raiz em `<pasta>/current/public`.

**Correções de segurança do PHP.** Instalado pelo PPA `ondrej/php`, o PHP fica fora das
atualizações automáticas do Ubuntu. Inclua o PPA para receber as correções da mesma
versão (ex.: 8.4.26 → 8.4.27) sem trocar de versão:

```bash
cat > /etc/apt/apt.conf.d/52unattended-upgrades-php <<'EOF'
Unattended-Upgrade::Origins-Pattern {
        "origin=LP-PPA-ondrej-php,archive=${distro_codename}";
};
EOF
unattended-upgrade --dry-run --debug | grep "Allowed origins"   # deve listar o PPA
```

## 2. Primeira instalação

```bash
APP=/var/www/menu-express            # ou /www/wwwroot/seudominio.com.br no aaPanel
mkdir -p $APP/shared
cp .env.example $APP/shared/.env     # e preencha conforme abaixo
```

`.env` de produção (o restante segue o `.env.example`):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_NAME="Nome da sua plataforma"
APP_URL=https://seudominio.com.br
APP_KEY=                       # php artisan key:generate --show
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_DATABASE=menu_express
DB_USERNAME=menu_express
DB_PASSWORD=...

MAIL_MAILER=smtp               # obrigatório: confirmação de e-mail, recuperação de senha e avisos
MESSAGING_DRIVER=evolution     # EVOLUTION_* com o prefixo mx-
BILLING_ENABLED=true
```

Configure o nginx com [deploy/nginx.conf](deploy/nginx.conf), a fila com
[deploy/supervisor.conf](deploy/supervisor.conf) e o agendador no cron do usuário do site:

```cron
* * * * * cd /var/www/menu-express/current && php artisan schedule:run >> /dev/null 2>&1
```

## 3. Publicar uma versão

O workflow **deploy** do GitHub monta a versão (dependências e telas) e a envia ao servidor.
O script [deploy/activate.sh](deploy/activate.sh) descompacta em `releases/<data>`, roda as
migrações, troca o link `current` de uma vez e reinicia a fila. O site não sai do ar.

No GitHub, em _Settings → Secrets and variables → Actions_:

- Secrets: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` (chave privada de um usuário só para
  deploy, sem senha de root) e `DEPLOY_KNOWN_HOSTS` (saída de `ssh-keyscan <host>`).
- Variáveis: `DEPLOY_PATH` (ex.: `/var/www/menu-express`), e se preciso `DEPLOY_PHP`
  (aaPanel: `/www/server/php/84/bin/php`) e `DEPLOY_FPM_RELOAD` (ex.: `sudo systemctl reload php8.4-fpm`).
- `DEPLOY_ON_PUSH=true` publica sozinho a cada push na `main` que passar nos testes.
  Sem ela, publique pela aba _Actions → deploy → Run workflow_.

**Instalações anteriores ao razão de recebimentos:** depois de atualizar, rode uma vez
`php current/artisan app:rebuild-ledger` para registrar as vendas e os pagamentos já feitos.
Pode repetir quando quiser: nada é duplicado, e quantidades de bitcoin que o provedor não
informou na hora são completadas.

**Voltar uma versão:** `ln -sfn releases/<anterior> current && php current/artisan queue:restart`
(migrações novas continuam aplicadas; desfaça com `migrate:rollback` só se necessário).

Depois do primeiro deploy, dê acesso ao painel administrativo. Não existe usuário nem senha
padrão: o administrador é uma conta comum que recebe o acesso pelo servidor.

1. Crie sua conta em `https://seudominio.com.br/register`.
2. Confirme o e-mail pelo link recebido. O painel exige conta confirmada, por isso o SMTP
   precisa estar configurado antes (em desenvolvimento, com `MAIL_MAILER=log`, o link fica em
   `storage/logs/laravel.log`).
3. Libere o acesso e entre em `/admin`:

```bash
php current/artisan app:super-admin seu@email.com
```

Não rode `db:seed` em produção: o seeder só cria uma conta de teste, de senha conhecida, e é
ignorado fora de `APP_ENV=local`.

## 4. Backups

- Banco: dump diário (cron do aaPanel ou `mysqldump`) guardado fora do servidor (ex.: Storage
  Box da Hetzner).
- Arquivos: `shared/storage/app/public` (logos, fotos e banners) e `shared/.env`.
