# Ambiente de desenvolvimento

## Requisitos verificados neste computador

- PHP 8.5.12, Composer disponível e Laravel 13.40.0.
- MySQL local 8.x; banco de desenvolvimento `campusfix` e usuário de aplicação próprio.
- Node.js 24.15.0 e npm 11.12.1.
- JDK 21.0.8, Android SDK, Gradle Wrapper 8.14.3, Android Gradle Plugin 8.13.0 e Capacitor 8.6.0.
- Git CLI está disponível, mas a pasta de trabalho examinada não contém metadados `.git`; confirme o checkout/repositório remoto antes de operações Git.

## Backend web/API

No PowerShell, entre em `backend/` e prepare uma cópia local de `.env.example` para `.env`. Gere `APP_KEY` com `php artisan key:generate`; preencha a conexão MySQL local com credenciais locais que não sejam `root`. Instale dependências com `composer install`, instale as dependências web com `npm ci` quando houver lockfile válido, e prepare o banco local somente após conferir o nome da conexão.

Para executar a aplicação em desenvolvimento, use `php artisan serve --host=127.0.0.1 --port=8000`. A interface web fica em `/`; o endpoint de saúde é `/up`; a API tem prefixo `/api/v1` e não possui rota raiz `/api/v1` para health check. O aplicativo Android usa URL própria conforme o dispositivo (ver [mobile-setup.md](mobile-setup.md)).

Não use `php artisan migrate:fresh`, `db:wipe` ou `migrate:refresh` contra a base de desenvolvimento. Para o primeiro administrador, use o procedimento interativo documentado em [first-admin.md](../backend/docs/development/first-admin.md).

## Cliente Android

Em `mobile/`, use Node/npm compatíveis com Vite 8. Configure a URL de desenvolvimento em `.env.development.local`, arquivo local ignorado pelo Git. Emulador: `http://10.0.2.2:8000/api/v1`. Aparelho físico: encaminhe a porta com `adb reverse tcp:8000 tcp:8000` e use `http://127.0.0.1:8000/api/v1`. Builds debug podem usar HTTP somente nos hosts loopback permitidos; produção requer URL HTTPS.

O Gradle Wrapper está em `mobile/android/gradlew.bat`; não é necessário instalar Gradle globalmente. Defina `ANDROID_HOME` e `ANDROID_SDK_ROOT` para o SDK Android no ambiente da sessão, se o Gradle não o encontrar. Adicione `platform-tools` ao PATH se for usar `adb`.

## Configuração e dados locais

Arquivos `.env`, `.env.testing`, `.env.development.local` e quaisquer arquivos de produção são locais e não devem ser versionados. `.env.example` e arquivos `*.example` são modelos sem segredos. Nunca copie credenciais reais para exemplos, documentação, logs ou comandos registrados.

Os seeders gerais incluem dados de demonstração. Consulte [produção](production-configuration.md) antes de semear qualquer ambiente persistente.
