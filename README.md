# CampusFix

Sistema acadêmico de gestão de solicitações de manutenção para instituições de ensino. O projeto oferece duas interfaces clientes independentes ligadas ao mesmo backend Laravel e ao mesmo banco MySQL:

- **Web:** Laravel, Blade e Tailwind CSS.
- **Android:** interface JavaScript própria, empacotada com Capacitor.
- **Backend:** API REST `/api/v1`, autenticação Sanctum, regras de negócio compartilhadas e acesso ao MySQL.

## Estrutura

```text
backend/  Laravel: web, API, domínio, persistência e testes PHP
mobile/   cliente Android JavaScript/Capacitor e testes Node
docs/     guias de desenvolvimento, segurança e operação
```

As duas interfaces usam o mesmo banco e os mesmos serviços do backend. A API não é um segundo backend. Anexos permanecem privados e são entregues somente após autorização.

## Começar

Leia [preparação do ambiente](docs/development-environment.md), [configuração MySQL local](docs/mysql-development.md) e [configuração Android](docs/mobile-setup.md). Para executar testes com isolamento, siga [testes](docs/testing.md).

## Documentação operacional

- [Arquitetura](docs/architecture.md)
- [Autenticação e segurança](docs/authentication-and-security.md)
- [Configuração de produção](docs/production-configuration.md)
- [Deploy, atualizações, backup e recuperação](docs/deployment-backup-recovery.md)
- [Auditoria final e estado de prontidão](docs/final-security-review.md)
- [Contrato da API v1](backend/docs/api-v1.md)

## Estado de produção

O projeto ainda não está pronto para publicação: não há provedor, domínio HTTPS, serviço de e-mail ou assinatura de release definidos. Não publique o cliente Android nem exponha o backend até concluir os itens marcados como pendentes na documentação de produção. Nenhuma credencial real deve ser guardada no repositório.
