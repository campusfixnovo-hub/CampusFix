# Testes e isolamento

## Backend

O PHPUnit é protegido por `backend/tests/bootstrap.php` e `backend/tests/TestCase.php`: ambos exigem ambiente `testing` e verificam via conexão PDO/Eloquent que a base efetiva é `campusfix_test`. A configuração forçada no `phpunit.xml` não substitui essa verificação real. O arquivo `.env.testing` é local/ignorado.

Configure a base e usuário isolados conforme [mysql-development.md](mysql-development.md). Antes de executar, confira que `campusfix_test` existe e que `campusfix` não aparece na configuração de testes.

```powershell
Set-Location backend
php artisan test --filter=InitialAdministratorCommandTest
php artisan test --filter=DemoUsersCommandTest
php artisan test
```

Não use `migrate:fresh`, `migrate:refresh`, `db:wipe` ou seeds de demonstração na base persistente. Se o guard falhar, pare e corrija `.env.testing`; não contorne a proteção.

## Mobile e bundles web

```powershell
Set-Location mobile
npm test
npm run build -- --mode development
```

Para verificar o bundle web Laravel, execute `npm run build` em `backend/`. O Vite mobile em modo production exige `VITE_API_URL` HTTPS de produção; não use localhost, domínio inventado ou URL de debug.

## Android debug

Depois de build development, sincronize Capacitor com `npx cap sync android` e compile com `android\gradlew.bat assembleDebug`. Para aparelho físico, `adb reverse tcp:8000 tcp:8000` encaminha o backend local a `127.0.0.1` no telefone. Não trate build de debug como release.

## Evidência

Registre comandos, quantidade de testes/assertions, falhas e limitações. Diferencie teste automatizado de teste em aparelho real. Não anexe `.env`, tokens, senhas ou dados pessoais a logs/relatórios.
