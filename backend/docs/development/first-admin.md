# Criação do primeiro administrador

O cadastro público aceita somente estudante, professor e funcionário. Não existe senha administrativa padrão nem cadastro web com função privilegiada.

Depois de aplicar as migrations e executar os seeders, um operador autorizado deve abrir um terminal seguro no servidor e executar:

```powershell
php artisan campusfix:admin:create
```

O comando solicita nome, e-mail, senha e confirmação interativamente. A senha é lida sem eco, deve ter ao menos 12 caracteres e combinar letras maiúsculas/minúsculas, números e símbolo. Ela não é aceita como argumento, não é gravada em arquivo de configuração e não aparece na saída do comando.

O comando cria uma conta ativa, marca o e-mail como verificado e atribui a função `administrator` em uma transação. Se já existir qualquer usuário com essa função, o comando recusa a criação. Depois da primeira conta, funções podem ser atribuídas somente por um administrador autenticado a outro usuário; ninguém pode alterar suas próprias funções.

Proteja o acesso ao terminal e às credenciais de produção com os controles operacionais do servidor. Em produção, configure também um mailer seguro para o fluxo de recuperação de senha.
