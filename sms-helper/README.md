# Âncora SMS Helper

Aplicação Android auxiliar para criar e restaurar uma cópia XML de SMS. Não é uma aplicação injectada: deve ser instalada normalmente no telemóvel e o Android controla as permissões.

## Funções

- Exporta SMS para `Download/sms-backup.xml`.
- Permite escolher um XML e restaurar as mensagens.
- Pede `READ_SMS` e `WRITE_SMS`.
- Pede para ser temporariamente a aplicação SMS predefinida antes de restaurar.

## Compilar

Abra a pasta `sms-helper` no Android Studio com Android SDK instalado e execute **Build > Build APK(s)**. O APK será criado em:

```text
app/build/outputs/apk/debug/app-debug.apk
```

Também pode compilar por terminal, depois de instalar o SDK e Gradle:

```powershell
gradle assembleDebug
adb install -r app\build\outputs\apk\debug\app-debug.apk
```

## Usar

1. Instale o APK no Android.
2. Abra Âncora SMS e conceda as permissões solicitadas.
3. Toque em **Exportar SMS para Download**.
4. Para restaurar, use o XML criado pela aplicação PHP em `Download/sms-backup.xml`, toque em **Escolher XML e restaurar SMS** e aceite temporariamente a aplicação SMS predefinida.
5. Depois da restauração, volte a selecionar a aplicação SMS habitual como predefinida.

O XML gerado usa elementos `sms` com endereço, data, corpo, tipo e estado de leitura. Faça uma cópia do ficheiro antes de restaurar e evite executar a operação duas vezes, pois isso pode duplicar mensagens.
