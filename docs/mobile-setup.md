# CampusFix — ambiente Mobile

O cliente Android encontra-se em `mobile/` e usa Capacitor 8. Os requisitos detectados neste computador são Node.js 24.15.0, npm 11.12.1, JDK 21.0.8, Android Studio 2026.1 e Android SDK em `%LOCALAPPDATA%\Android\Sdk`.

`ANDROID_HOME` e `ANDROID_SDK_ROOT` ainda precisam ser definidos para compilações em novas sessões; adicionar ao `PATH` `platform-tools`, `cmdline-tools\latest\bin` e `emulator`. Não é preciso instalar Gradle globalmente: a plataforma Android usa Gradle Wrapper.

Para desenvolvimento no emulador, defina `VITE_API_URL=http://10.0.2.2:8000/api/v1` em `mobile/.env.development.local` (ignorado pelo Git), inicie Laravel e gere uma build Vite com `--mode development` antes de sincronizar/compilar debug. Em aparelho físico conectado por ADB (USB ou sem fio), use `adb reverse tcp:8000 tcp:8000` e `http://127.0.0.1:8000/api/v1`; confirme o encaminhamento com `adb reverse --list`. A política do aplicativo não autoriza HTTP ao IP LAN do computador. Cleartext fica restrito a `10.0.2.2`, `localhost` e loopback na variante debug; a variante release bloqueia cleartext. O domínio HTTPS ainda não foi definido, portanto nenhum APK de produção deve ser gerado.

O build Vite padrão exige `VITE_API_URL` com HTTPS. Na variante debug, o Capacitor permite conteúdo misto para alcançar a API HTTP local, enquanto a política de rede Android restringe cleartext aos hosts locais autorizados. A configuração principal/release não permite conteúdo misto e bloqueia cleartext. O Capacitor grava um marcador de modo nos assets e a tarefa Gradle de release recusa assets que não tenham sido compilados em modo `production`.

O SDK também pode ser passado apenas ao processo do Gradle com `ANDROID_HOME` e `ANDROID_SDK_ROOT`, sem alterar as variáveis persistentes do Windows. Para teste em dispositivo, inicie um AVD no Device Manager ou conecte um telefone com depuração USB; confirme com `adb devices -l` antes de instalar o APK.

O `sdkmanager` instalado emite aviso de que entende até SDK XML 3, enquanto encontra metadados XML 4 do Android Studio. Recomenda-se atualizar Command-line Tools pelo SDK Manager do Android Studio antes de atualizar plataformas ou ferramentas. O APK de depuração foi compilado em `mobile/android/app/build/outputs/apk/debug/app-debug.apk` com a API de desenvolvimento do emulador. Não há APK de produção porque o domínio HTTPS de hospedagem ainda não foi definido.
