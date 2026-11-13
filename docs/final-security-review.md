# Auditoria final e estado de prontidão

Estado observado em **11/11/2026**. Esta auditoria é uma revisão do checkout e das configurações locais disponíveis; não é certificação, pentest independente nem implantação.

| Área | Estado | Evidência/pendência |
|---|---|---|
| Arquitetura web/API/Android | CONCLUÍDO | Dois clientes independentes, backend Laravel único, API v1 e MySQL compartilhado. |
| Cadastro e autorização | CONCLUÍDO | Cadastro público limitado a solicitante; Policies, escopo e funções privilegiadas no backend. |
| Tokens mobile | CONCLUÍDO | Novos tokens têm expiração individual de 90 dias; tokens legados sem expiração foram preservados; senha redefinida/alterada revoga tokens da conta; logout revoga o atual. |
| Armazenamento Android | CONCLUÍDO | AES/GCM + Android Keystore; manifest base declara `allowBackup=false` e regras de extração excluem dados; release declara cleartext negado. Manifest merged debug confirmou backup desabilitado. |
| Anexos | CONCLUÍDO | Uso de disco privado e endpoints autorizados; backup de storage precisa fazer parte de operação produtiva. |
| Isolamento PHPUnit | CONCLUÍDO | Bootstrap e TestCase exigem `campusfix_test` e consultam a base efetiva; nunca executar testes se o guard falhar. |
| Segredos e templates | CONCLUÍDO | Arquivos locais `.env.*` ignorados e templates `*.example` permitidos. Git metadata não existe neste checkout; verificação de rastreamento remoto não é possível aqui. |
| Ambiente produtivo | PENDENTE | Provedor, domínio, TLS, banco, SMTP, cofre, monitoramento, retenção e restore drill ainda precisam ser definidos. |
| Release Android assinado | PENDENTE | URL HTTPS real e `signingConfig`/keystore no CI ainda não configurados. Não distribuir release. |
| Auditoria de dependências | BLOQUEADO | `composer audit` e `npm audit` não conseguiram alcançar Packagist/npm por falha de DNS/rede; executar em CI ou rede autorizada antes do deploy. |
| Scheduler em produção | PENDENTE | Registrar comando do Laravel Scheduler por minuto no provedor e monitorar execução de `campusfix:sla-monitor`. |
| Backup/recuperação | PENDENTE | Definir RPO/RTO, retenção, destino protegido e comprovar restauração em ambiente isolado. |

## Revisão de configuração

- Laravel: não colocar credenciais em código; produção deve executar `APP_DEBUG=false`, HTTPS, cookie seguro, CORS com origens exatas, banco privado e log sem segredos.
- MySQL: banco e usuário próprios por ambiente; aplicação nunca usa `root`.
- Android: URLs de debug HTTP são exclusivamente locais. Produção exige `VITE_API_URL` HTTPS e assets production; release tem cleartext negado.
- Uploads: `storage/app/private`, autorização por chamado/comentário e ausência de acesso público direto.
- Dados demo: `DatabaseSeeder` contém dados de exemplo; não executar em produção. Inicializar somente roles/permissões e administrador controlado.

## Limites desta revisão

Não foi possível verificar arquivos rastreados por Git porque a pasta examinada não contém `.git`. Auditoria online de dependências foi bloqueada por DNS. Disponibilidade do serviço externo, DNS, TLS, e-mail, hardening do host, firewall, backups, restauração e comportamento de produção não foram testados. Não foram alteradas contas ou dados do banco principal nesta etapa.

## Validações executadas nesta etapa

- Testes focados do primeiro administrador: 2 testes, 11 assertions; aprovados.
- Testes de usuários demo: 5 testes, 82 assertions; aprovados.
- Suíte backend Pest: 71 testes, 473 assertions; aprovada após ajustar uma asserção frágil que comparava IDs entre coleções JSON distintas. Nenhuma regra de produção foi alterada.
- Testes JavaScript mobile: 18 aprovados.
- Sintaxe PHP: 105 arquivos aprovados; `composer check-platform-reqs` aprovado.
- Build web Vite aprovado; build Android debug aprovado em `mobile/android/app/build/outputs/apk/debug/app-debug.apk`.
- Build Vite production foi corretamente recusado por ausência de URL HTTPS real. Gradle também bloqueou processamento de release porque os assets atuais estão marcados `development`; nenhuma proteção foi contornada e nenhum release foi gerado.
- Contagens consultadas somente leitura em `campusfix`: users 5, teams 3, team_members 3, tickets 7, comments 1, attachments 5, ratings 1, ticket_histories 20, team_histories 4. Iguais ao baseline observado antes desta etapa; a suíte acessou somente `campusfix_test`.
- `php artisan db:show --counts` identificou `campusfix`, mas não concluiu a formatação das contagens porque a extensão opcional PHP `intl` está ausente. As contagens acima foram verificadas por consultas diretas somente leitura. `intl` não consta como requisito obrigatório do Composer.
