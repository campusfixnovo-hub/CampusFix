# Indicadores e SLA

## Período e escopo

Os filtros de data definem uma coorte pela data de abertura (`tickets.created_at`), incluindo as datas inicial e final. Filtros opcionais podem restringir campus, prédio, local, categoria, prioridade, equipe e técnico. Administradores consultam o conjunto global; gestores ficam limitados aos campi das equipes em que são gestores; técnicos consultam chamados atribuídos a eles ou às equipes das quais fazem parte. Solicitantes não têm permissão para abrir relatórios.

## Indicadores gerais

- **Abertos:** status `open` ou `in_triage`.
- **Em andamento:** `awaiting_service`, `in_progress`, `awaiting_material`, `awaiting_requester` ou `reopened`.
- **Resolvidos:** status atual `resolved`; **encerrados:** `closed`; **cancelados:** `cancelled`.
- **Sem atribuição:** chamados em status ativo sem `assigned_user_id`.
- **Próximos do vencimento:** chamados ativos, sem pausa aberta, com minutos de expediente restantes entre zero e `warn_before_minutes` da política. Sem política, usa 240 minutos como limite de exibição.
- **Atrasados agora:** prazo passado, status ativo diferente de `awaiting_requester` e sem pausa SLA aberta.
- **Pausados:** chamados com intervalo SLA sem `ended_at`.

## Indicadores SLA

- **Casos mensuráveis:** chamados com prazo SLA que foram resolvidos, têm violação registrada ou venceram enquanto ainda estavam ativos e sem pausa. Chamados pendentes dentro do prazo, sem prazo e cancelados não entram no denominador.
- **Cumprimento:** casos resolvidos até `due_at`, sem violação registrada, dividido pelos casos mensuráveis × 100.
- **Excederam o SLA:** casos mensuráveis com `sla_breached_at`, resolução posterior a `due_at` ou prazo vencido ainda ativo, dividido pelos casos mensuráveis × 100. O chamado pausado antes do vencimento não entra como atraso atual.
- **Tempo médio de resolução:** média dos minutos de expediente entre abertura e resolução, descontando a interseção dos intervalos de pausa com esse período. Só chamados com `resolved_at` entram.
- **Tempo médio de primeira resposta:** média dos minutos de expediente entre abertura e `first_response_at`, também descontando pausas. Só marcos preenchidos entram.
- **Calendário:** usa o calendário da política SLA; se o chamado não tiver política, tenta o calendário institucional padrão. Se nenhum estiver configurado, o relatório usa minutos corridos.
- **Evolução mensal:** agrupa chamados pelo mês de abertura e apresenta casos resolvidos no prazo e violações mensuráveis daquele grupo.

`SlaClock::businessMinutesBetween` soma interseções com os segmentos de expediente por dia e desconta feriados não úteis; não percorre minuto a minuto o intervalo inteiro. A data gravada em `sla_breached_at` é o prazo SLA que foi ultrapassado, para que a detecção agendada seja repetível sem mover o instante original da violação.

## Categoria, prioridade, local e equipe

- Categoria e prioridade contam os chamados abertos no período filtrado.
- A taxa de atraso por categoria é `violações / casos SLA mensuráveis × 100`; o relatório mostra junto o volume e o tamanho da amostra.
- Prédios e locais exibem os dez maiores volumes do período, sem inferir qualidade ou desempenho.
- Equipes e técnicos exibem quantidade resolvida, média de resolução em expediente e, para equipes, pendências atuais. Não há ranking.
- Novos eventos de resolução registram equipe e técnico associados naquele momento. Chamados resolvidos antes dessa mudança, sem esse snapshot de auditoria, são agrupados pelo vínculo atualmente salvo, portanto uma reatribuição posterior pode afetar a atribuição histórica desses registros antigos.

## Notificações de SLA

O comando `campusfix:sla-monitor` roda a cada cinco minutos pelo scheduler do Laravel. Deve haver um processo `php artisan schedule:run` a cada minuto no ambiente hospedado. O comando:

1. marca `sla_breached_at` com `due_at` uma única vez e grava um evento de auditoria de sistema;
2. ignora chamados com pausa SLA aberta ou estado `awaiting_requester` ao detectar atraso ativo;
3. envia alertas de prazo próximo conforme `warn_before_minutes` e de violação;
4. grava cada envio como notificação de banco para solicitante, técnico atribuído e gestores ativos do campus/equipe; a caixa de notificações do web app mostra e permite marcar os próprios avisos como lidos;
5. usa identificador estável por destinatário, chamado, evento e prazo. A chave primária da tabela `notifications` impede duplicação mesmo se execuções se sobrepuserem.

Os avisos são notificações internas do CampusFix. E-mail, SMS e push não estão configurados nesta fase.

## Exportação e impressão

O CSV exporta os chamados que passaram pelos mesmos filtros e escopo do relatório, por lotes de 300, sem carregar todo o conjunto em memória. Campos que poderiam ser interpretados como fórmulas por planilhas são prefixados com apóstrofo. A impressão usa regras CSS para remover navegação e filtros e preservar seções inteiras entre páginas.
