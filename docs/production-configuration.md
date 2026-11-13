# Preparação de produção

## Estado atual

Esta documentação não representa uma implantação. Não foram escolhidos provedor, domínio HTTPS, serviço SMTP, política de retenção, conta de storage externo ou certificado/assinatura do aplicativo. Não existe URL de produção para incluir no app. Até a definição desses elementos, produção está **pendente** e nenhum APK release deve ser distribuído.

## Laravel

Use um servidor suportado pelo provedor, HTTPS, PHP e extensões requeridas pelo `composer.lock`, Composer 2, MySQL compatível, Node apenas no pipeline de build e acesso de escrita restrito a `storage/` e `bootstrap/cache/`. Configure segredos via cofre/ambiente:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<dominio-real-da-aplicacao>
APP_KEY=<segredo-gerado-e-guardado-no-cofre>
DB_CONNECTION=mysql
DB_HOST=<endpoint-privado-do-banco>
DB_PORT=3306
DB_DATABASE=<banco-de-producao>
DB_USERNAME=<usuario-de-aplicacao-com-privilégio-mínimo>
DB_PASSWORD=<segredo-do-cofre>
DB_URL=
CORS_ALLOWED_ORIGINS=<origens-HTTPS-exatas-separadas-por-virgula>
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
LOG_CHANNEL=daily
LOG_LEVEL=warning
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=<transporte-transacional-do-provedor>
```

`<...>` são marcadores, não valores válidos. Não grave segredos neste exemplo. Configure também nome/from de e-mail, timezone, SameSite de cookie e parâmetros TLS MySQL (por exemplo, CA do provedor) conforme infraestrutura real. CORS não é autenticação; permita apenas origens necessárias, incluindo a origem Capacitor `https://localhost` quando exigida pelo runtime.

## Banco e dados iniciais

Crie uma base produtiva nova e usuário exclusivo com privilégio mínimo, em rede privada/TLS conforme provedor. Revise e faça backup antes de migrations. Para first install, aplique migrations com `php artisan migrate --force` somente após confirmar conexão e ambiente. Não execute `DatabaseSeeder`: `CampusFixReferenceSeeder` contém campus, equipe e referências demonstrativas. Inicialize apenas permissões/roles com `php artisan db:seed --class=RolePermissionSeeder --force`, depois crie um administrador interativamente com `php artisan campusfix:admin:create`. Configure campus, localização, equipe, calendário e políticas reais pela aplicação.

`campusfix:demo-users` é somente local e não deve executar em produção.

## Web/API, arquivos e tarefas

O servidor HTTP deve apontar o document root para `backend/public`, nunca para a raiz do projeto ou `storage`. Proteja backups e diretórios de log. Anexos permanecem em armazenamento privado; o disco local exige volume persistente e backup. Para múltiplos nós, provisionar storage privado compartilhado e validar o fluxo antes de escalar.

O agendador precisa ser executado a cada minuto pelo serviço de cron/scheduler do provedor; ele dispara `campusfix:sla-monitor` a cada cinco minutos, com prevenção de sobreposição. Configure workers contínuos se tarefas forem enfileiradas. Monitore scheduler, fila, erros, capacidade de disco, e-mail e backup.

## Android release

Defina a URL real HTTPS em `VITE_API_URL` no pipeline protegido. O build Vite rejeita URL de produção não HTTPS e os assets têm marcador de modo; Gradle recusa assets que não foram compilados em `production`. A política de rede release bloqueia cleartext. Antes de emitir um app, configure assinatura Android com keystore e senhas protegidas pelo CI/cofre. A configuração atual não contém `signingConfig` de release; portanto a assinatura/publicação não está pronta. Nunca incluir keystore, senha ou service account no Git.

Modelos vazios `backend/.env.production.example` e `mobile/.env.production.example` documentam apenas nomes de configuração; não são configuração executável nem devem receber segredos.
