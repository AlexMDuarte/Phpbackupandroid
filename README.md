# Âncora

Aplicação PHP local para copiar dados de um telemóvel Android através de ADB. Os ficheiros permanecem no computador e não são enviados para a cloud.

## Requisitos

- PHP 8.0 ou superior
- Android SDK Platform-Tools (`adb`)
- Telemóvel Android com **Opções de programador** e **Depuração USB** ativas
- Cabo USB de dados

## Arranque no Windows

1. Instale o [Android SDK Platform-Tools](https://developer.android.com/tools/releases/platform-tools).
2. Adicione a pasta `platform-tools` ao PATH do Windows, ou defina `ADB_PATH` com o caminho completo para `adb.exe`.
3. Ligue o telemóvel, desbloqueie-o e aceite a mensagem **Permitir depuração USB**.
4. Abra o PowerShell nesta pasta e confirme a ligação:

```powershell
adb devices
```

O estado deve aparecer como `device`, não `unauthorized`.

5. Inicie a aplicação:

```powershell
php -S localhost:8080
```

6. Abra http://localhost:8080 no navegador.

Os backups são criados em `backups\NOME-ESCOLHIDO`. No formulário, escreva manualmente o nome do backup. A aplicação copia as pastas `DCIM`, `Pictures`, `Movies`, `Music`, `Documents` e `Download` que forem selecionadas. Também pode exportar contactos e mensagens através do serviço de conteúdos do Android; os contactos ficam guardados como `contactos.vcf` e as mensagens como `sms-backup.xml`.

Para restaurar os contactos manualmente, copie `contactos.vcf` para o telemóvel, abra-o no gestor de ficheiros e escolha **Contactos** como aplicação de importação. Se o Android não abrir o ficheiro diretamente, use a opção **Importar de ficheiro** na aplicação Contactos e selecione o `.vcf`.

Na secção **Restaurar para o telemóvel**, escolha um backup e uma ou mais pastas. O restauro envia novamente os ficheiros para as pastas públicas do Android usando `adb push`. Se selecionar **Contactos (VCF para Download)** e **SMS (XML para Download)**, os ficheiros são colocados diretamente em `Download\contactos.vcf` e `Download\sms-backup.xml` para importar/restaurar no telemóvel.

As pastas públicas são copiadas pelo conteúdo da pasta, não pela pasta exterior. Assim, um backup novo mantém `DCIM\ficheiro.jpg` e o restauro mantém `DCIM\ficheiro.jpg`, sem criar `DCIM\DCIM`. Backups antigos com a duplicação também são reconhecidos durante o restauro.

Ao selecionar SMS, a aplicação PHP instala automaticamente `sms-helper\app\build\outputs\apk\debug\app-debug.apk` com `adb install -r`, envia o XML para Download e abre a aplicação Âncora SMS. O Android ainda mostra os pedidos de permissão e de aplicação SMS predefinida, que têm de ser confirmados manualmente.

Durante o backup e o restauro, a aplicação mostra uma barra de progresso visual com as etapas da operação. A percentagem é uma estimativa enquanto o pedido ADB está em execução; o resultado final apresentado pelo servidor é a confirmação efetiva.

## Notas

- A aplicação usa o primeiro dispositivo ADB autorizado encontrado.
- Não desligue o cabo durante a cópia.
- Contactos e mensagens podem ser bloqueados pelo Android por razões de segurança. Se o comando `content query` for recusado, a aplicação mostra a falha e não apaga dados.
- Contactos e mensagens são exportados para leitura, mas não são reinseridos automaticamente: o Android exige uma aplicação de contactos/SMS com permissões próprias ou uma confirmação explícita do sistema para esse processo.
- Esta versão não copia dados privados de outras aplicações nem conteúdos protegidos pelo Android.

## Restauro de SMS

O projeto auxiliar `sms-helper` contém uma APK Android para exportar SMS para `Download\sms-backup.xml` e restaurá-los com as permissões oficiais do Android. A aplicação tem de ser instalada normalmente e definida temporariamente como aplicação SMS predefinida durante o restauro. Consulte `sms-helper\README.md` para compilar no Android Studio. A APK compilada localmente não é incluída no Git por ser um artefacto de build; gere-a com `gradle assembleDebug` antes de usar o restauro automático num clone novo.
