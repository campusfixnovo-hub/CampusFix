# Deploy, atualização, backup e recuperação

Este procedimento é uma lista operacional neutra quanto ao provedor. Não há infraestrutura produtiva selecionada ou configurada; não o execute contra o banco local como simulação.

## Primeira implantação

1. Provisionar domínio/DNS, certificado TLS, servidor Laravel, MySQL privado, SMTP, armazenamento persistente e monitoramento.
2. Criar segredos no cofre do provedor e configurar `.env`/variáveis, `APP_DEBUG=false`, URL HTTPS, origens CORS exatas e cookies seguros.
3. Fazer deploy de artefato revisado, dependências de produção e assets web compilados; apontar document root para `backend/public`.
4. Confirmar backup e restauração de ensaio antes da primeira migration produtiva.
5. Rodar `php artisan migrate --force` com aprovação operacional e conexão produtiva verificada.
6. Aplicar somente roles/permissões, sem dados demo; criar administrador pelo comando interativo com senha fornecida em terminal seguro.
7. Configurar calendário, campus, categorias e SLA reais; testar fluxo e permissões com contas de aceitação dedicadas.
8. Habilitar scheduler por minuto e workers necessários. Monitorar `/up`, logs, fila, armazenamento, e-mail e alertas.
9. Compilar Android release com API HTTPS real e assinatura protegida; validar APK/AAB e distribuição controlada.

## Atualização

Faça release versionado, backup verificável antes de mudanças de esquema, execute migrations compatíveis em janela aprovada, reinicie workers, valide saúde e cenários críticos. Mantenha plano de rollback de código; restaure banco apenas se a migração não puder ser revertida com segurança e após avaliação do impacto sobre gravações desde o backup.

## Backup

Agende dumps MySQL criptografados com retenção definida e cópia em localização independente. Inclua `storage/app/private` e demais dados persistentes necessários. Proteja `APP_KEY` separadamente: sem ela, dados cifrados podem ficar irrecuperáveis. Não armazene dump ou credenciais no repositório. Teste restauração periodicamente em ambiente isolado e documente RPO/RTO com o responsável pela instituição.

Exemplo conceitual de dump; o provedor ou DBA deve adaptar credenciais/TLS e mecanismo de cofre, sem senha literal no comando:

```text
mysqldump --host=<host> --user=<usuario> --single-transaction --routines --triggers <banco> > <arquivo-protegido.sql>
```

## Recuperação

1. Declarar incidente, suspender gravações se necessário e preservar logs/evidências.
2. Provisionar banco isolado de recuperação; nunca sobrescrever o banco original como primeiro passo.
3. Restaurar dump e arquivos privados correspondentes, mantendo versão compatível de `APP_KEY` e permissões.
4. Validar contagens, relações e amostras de anexos no ambiente isolado.
5. Reapontar produção somente após autorização operacional e teste; monitorar escritas após recuperação.
6. Registrar ponto de recuperação, perda potencial e lições do incidente.
