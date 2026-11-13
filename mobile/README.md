# CampusFix Android

Cliente JavaScript independente, baseado em Vite e Capacitor 8. Comunica-se somente com a API Laravel `/api/v1`.

## Desenvolvimento local

1. Configure `VITE_API_URL` em `.env.development.local` (ignorado pelo Git). Para uma nova cópia, use `.env.example` como referência.
2. Para o emulador, use `http://10.0.2.2:8000/api/v1` e execute o Laravel ligado ao host (`php artisan serve --host=0.0.0.0`).
3. Para um dispositivo físico ligado por ADB USB ou sem fio, encaminhe a porta com `adb reverse tcp:8000 tcp:8000` e use `http://127.0.0.1:8000/api/v1`. A origem Capacitor `https://localhost` já está permitida no CORS local. A validação do app não permite HTTP para o IP LAN do computador. Não use a URL de desenvolvimento em builds de produção.
4. Execute `npm install` e `npm test`. Para o APK local, use `npm run build -- --mode development` e `npx cap sync android`; para uma release, use `npm run build` com um endereço HTTPS de produção.
5. O aplicativo exige HTTPS em build de produção. Configure o domínio de produção por `VITE_API_URL` no ambiente do Vite.

O tráfego HTTP e o conteúdo misto são permitidos somente na variante Android de depuração e apenas para `10.0.2.2`, `localhost` e loopback. A configuração Capacitor de depuração habilita conteúdo misto para a API local; a política Android continua negando cleartext para outros hosts. A variante release mantém conteúdo misto desativado, bloqueia cleartext e exige assets Vite de produção; o build Vite de produção também exige uma URL HTTPS.

## Sessão e arquivos

No Android, o token é persistido pelo `@aparajita/capacitor-secure-storage`, que cifra os valores com AES/GCM e uma chave criada no Android Keystore. O token não é salvo em Preferences, `localStorage`, arquivos de configuração ou logs. No modo navegador, ele fica somente em memória durante a sessão da página.

Fotos e PDFs são escolhidos pelo seletor do sistema e enviados ao endpoint autenticado. O app não solicita acesso amplo a fotos ou armazenamento. Arquivos são baixados pela rota privada da API com o token Bearer.

## Atribuições e limites conhecidos

- Gestores e administradores podem consultar opções compatíveis do chamado e atribuir equipe/técnico. A API aplica a Policy e o workflow verifica campus, equipe, vínculo ativo e perfil de técnico; a lista da interface não substitui essas validações.
- Não há recuperação de senha mobile nem renovação automática de token. Tokens novos expiram individualmente 90 dias após a emissão; tokens legados sem `expires_at` permanecem válidos até revogação. Quando a API responde `401`, a sessão local é removida e o usuário retorna ao login. Falhas de rede ao validar a sessão na inicialização preservam o token e exibem a opção de tentar novamente.
- Uma redefinição de senha concluída revoga no servidor todos os tokens Sanctum da conta; o aplicativo solicita login na próxima chamada autenticada.
- Notificações push não fazem parte desta fase. A tela consulta notificações persistidas no backend.

O endpoint de opções é `GET /tickets/{id}/assignment-options`. Ele não cria nem altera dados e só retorna equipes ativas compatíveis com o campus do chamado e técnicos ativos vinculados.

## Build Android

O SDK local está em `%LOCALAPPDATA%\Android\Sdk`. Para a sessão PowerShell atual, configure temporariamente `ANDROID_HOME` e `ANDROID_SDK_ROOT` com esse caminho e acrescente `platform-tools`, `cmdline-tools\latest\bin` e `emulator` ao `PATH`. O `JAVA_HOME` de máquina já aponta ao JDK 21. As variáveis persistentes do Windows não foram alteradas.
