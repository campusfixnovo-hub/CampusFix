# MySQL local

O Laravel usa um único banco MySQL por ambiente. Em desenvolvimento, o nome aprovado é `campusfix`; a aplicação conecta com um usuário dedicado de privilégio limitado ao banco, nunca como `root`. O isolamento do PHPUnit usa exclusivamente `campusfix_test` com usuário separado.

## Preparar o banco de desenvolvimento

1. Confirme que o serviço MySQL local está em execução.
2. Configure `.env` a partir de `backend/.env.example`, preenchendo host, porta, `DB_DATABASE=campusfix`, usuário de aplicação e senha local fora do Git.
3. Se ainda não existir, um DBA pode criar uma base `utf8mb4` e um usuário restrito a ela. Não use credenciais de produção no computador.
4. Antes de qualquer migration, confira a base efetiva com `php artisan about` ou uma consulta somente leitura `SELECT DATABASE()` na conexão configurada.
5. Aplique migrations somente na base intencional e com backup adequado quando houver dados importantes.

Não inclua senhas reais em linha de comando, relatório ou histórico. Prefira um gerenciador de segredos/entrada interativa ao criar credenciais. Não sobrescreva ou recrie bases existentes.

## Banco de testes

`backend/.env.testing.example` é um modelo. O arquivo local `.env.testing` deve declarar `APP_ENV=testing`, conexão `mysql`, `DB_DATABASE=campusfix_test`, usuário de teste dedicado, senha local e `DB_URL=` vazio. PHPUnit e `tests/bootstrap.php` verificam os valores configurados e a base efetiva por PDO antes da suíte. O guard do `TestCase` verifica novamente a conexão antes de usar traits que preparam tabelas.

Crie `campusfix_test` somente se uma consulta administrativa confirmar que o nome está livre. Não aponte PHPUnit para `campusfix`; nunca rode testes na base persistente de desenvolvimento. Para comandos e verificações, consulte [testing.md](testing.md).
