# Autenticação e segurança

## Perfis e autorização

Cadastro público cria apenas perfil solicitante (estudante, professor ou funcionário). Perfis de técnico, gestor e administrador são atribuídos por administrador autorizado. Administradores têm escopo global; gestores e técnicos são limitados aos vínculos e campi aplicáveis; solicitantes acessam apenas seus próprios chamados. Policies e verificações de backend são a autoridade.

## Web e API

Web usa sessão Laravel, cookie HttpOnly e CSRF. A API `/api/v1` utiliza Sanctum com Bearer token e não usa sessão mobile. `401` indica sessão inválida/expirada; indisponibilidade de rede não deve apagar o token do armazenamento Android. Logout mobile revoga somente o token atual.

Novos tokens mobile recebem expiração individual de 90 dias. Tokens legados sem `expires_at` preservam seu comportamento anterior; não há refresh automático. Após redefinição ou alteração de senha concluída, todos os tokens Sanctum daquela conta são revogados. O usuário faz login novamente.

## Aplicativo Android

O token é guardado por `@aparajita/capacitor-secure-storage` usando AES/GCM e chave no Android Keystore. O app não persiste tokens em Preferences/localStorage nem os registra em logs. `allowBackup=false` e regras de extração excluem os dados do backup.

HTTP é permitido somente para builds debug e hosts locais restritos; release exige HTTPS e rejeita assets Vite que não sejam de produção. Não relaxe essas regras para contornar erros de conexão.

## Anexos e dados sensíveis

Fotos e PDFs são privados. A API valida tipo/tamanho, associa ao chamado e exige autorização ao listar, visualizar ou baixar. Não disponibilize `storage/app/private` pela web pública. Nunca registre senha, token Bearer, chave de aplicação ou conteúdo sensível em logs.

## Operação de produção

Use HTTPS para web e API, `APP_DEBUG=false`, segredo forte para `APP_KEY`, banco dedicado com privilégio mínimo, CORS com origens exatas, cookies seguros, e-mail transacional seguro, logs com retenção e backups protegidos. Os valores reais devem vir do cofre de segredos ou ambiente do provedor, não deste repositório.
