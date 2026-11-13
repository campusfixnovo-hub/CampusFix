# CampusFix REST API v1

Base URL: `https://<host>/api/v1`. Todas as respostas são JSON, exceto `GET /reports/export.csv`.

`GET /catalogs` retorna somente campi, categorias, prédios e locais ativos para preencher formulários. Os parâmetros opcionais `campus_id` e `building_id` limitam prédios/locais aos selecionados; a chamada exige token.

## Autenticação

`POST /auth/login` recebe `email`, `password` e `device_name`. Em caso de sucesso responde `200` com `data.user`, `data.token` e `data.token_type`. O token em texto puro só é apresentado nesta resposta; o banco armazena seu hash.

Tokens mobile novos recebem expiração individual em 90 dias. Tokens emitidos antes dessa política e sem `expires_at` não recebem prazo retroativo. Não há renovação automática: após `401`, o aplicativo solicita novo login. Uma redefinição ou alteração de senha concluída revoga todos os tokens da conta; o logout normal revoga somente o token da sessão atual.

Envie `Authorization: Bearer <token>` nas rotas protegidas. `POST /auth/logout` revoga o token em uso (`200`); `GET /auth/me` retorna a identidade autenticada. O login tem limite de seis tentativas por minuto. Contas inativas recebem `403`; credenciais inválidas recebem `422`.

## Chamados

| Método | Caminho | Uso |
| --- | --- | --- |
| GET | `/tickets` | Lista paginada e limitada ao escopo do usuário. Filtros: `q`, `status`, `priority`, `category_id`, `team_id`, `sla=overdue\|paused`, `per_page` (1–100). |
| POST | `/tickets` | Abre chamado; aceita título, descrição, categoria, prioridade e localização. Retorna `201`. |
| GET | `/tickets/{id}` | Detalhes, responsável, atribuição e SLA. |
| PATCH | `/tickets/{id}` | Solicitante pode editar título/descrição somente em aberto ou triagem. |
| POST | `/tickets/{id}/status` | `{ "status": "in_progress" }`; transições são validadas por `TicketWorkflow`. O valor `cancelled` cancela conforme Policy. |
| GET | `/tickets/{id}/assignment-options` | Equipes ativas do campus/global e técnicos ativos vinculados; autorizado pela Policy `assign` do chamado. |
| POST | `/tickets/{id}/assignment` | `{ "team_id": 1, "assigned_user_id": 2 }`; gestores/admin e validação do workflow. `team_id` e `assigned_user_id` aceitam `null` para remover uma atribuição. |
| GET | `/tickets/{id}/history` | Histórico paginado de auditoria. |
| GET/POST | `/tickets/{id}/comments` | Lista/adiciona comentários; `is_internal` exige autorização interna. |
| GET | `/tickets/{id}/attachments` | Metadados paginados de anexos visíveis no escopo do usuário. |
| POST | `/tickets/{id}/attachments` | Multipart: `file` (JPG/PNG/WebP/PDF até 10 MB), opcional `comment_id`. |
| GET | `/attachments/{id}` | Download privado após autorização do chamado e comentário associado. |
| GET/POST | `/tickets/{id}/rating` | Consulta ou registra avaliação (nota de 1 a 5) pelo solicitante de chamado resolvido. |

## Dashboard, relatórios e notificações

- `GET /dashboard`: totais e chamados recentes limitados pelo `TicketAccessScope`.
- `GET /reports`: aceita filtros de período, campus, prédio/local, categoria, prioridade, equipe e técnico; exige `reports.view-all` (admin) ou `reports.view-team` (gestor/técnico). Reutiliza o cálculo e escopo do relatório web e retorna resumo, SLA, categorias, prioridades, evolução mensal, localizações, equipes e técnicos.
- `GET /reports/export.csv`: mesmas permissões e exportação existente, em chunks.
- `GET /notifications`: notificações do usuário autenticado, paginadas.
- `PATCH /notifications/{id}/read`: marca apenas notificação própria como lida; retorna `204`.

## Respostas e autorização

Resources omitem senha, hash de token e caminhos privados. Páginas usam `data`, `links` e `meta`. Erros seguem JSON Laravel: `401` sem autenticação, `403` sem autorização/conta inativa, `404` recurso inexistente ou fora de alcance, `422` validação/transição, `429` limite excedido e `500` falha inesperada. Chamados sempre passam por Policies e escopos existentes; comentários e arquivos internos herdam o acesso interno.

Web permanece em sessão/CSRF. A API mobile usa somente tokens pessoais; CORS permite origens da Capacitor configuradas por `CORS_ALLOWED_ORIGINS` (separadas por vírgula), sem credenciais cross-origin. Defina origens web explícitas no ambiente de produção se necessário.

## Exemplo

```http
POST /api/v1/auth/login
Content-Type: application/json

{"email":"usuario@instituicao.edu.br","password":"<senha>","device_name":"CampusFix Android"}
```

```json
{"data":{"user":{"id":1,"name":"Usuário","email":"usuario@instituicao.edu.br","user_type":"student","roles":["student"]},"token":"<retornado-uma-vez>","token_type":"Bearer"}}
```

## OpenAPI

Não há pacote OpenAPI instalado. Este documento é a referência da versão v1; a especificação OpenAPI formal poderá ser adicionada quando houver aprovação para incluir uma dependência.
