# Âncora Testes

APK simples para confirmação manual dos testes físicos do Android. Cada teste permite marcar **Funcionou** ou **Falhou**, adicionar uma observação e enviar tudo para o site PHP.

## Usar com o site local

Com a Depuração USB ativa e o servidor PHP em execução:

```powershell
adb reverse tcp:8080 tcp:8080
adb install -r manual-tests\app\build\outputs\apk\debug\app-debug.apk
```

Na APK, mantenha a URL:

```text
http://127.0.0.1:8080/confirmations.php
```

Na página `http://localhost:8080/tests.php`, o botão **Testar tudo** instala e abre automaticamente esta APK no equipamento selecionado. Depois marque os testes na aplicação e toque em **Enviar confirmações para o site**. O último relatório aparece no fundo da página de testes.

Para outro computador ou servidor, substitua a URL no campo da aplicação. O site guarda os últimos 50 envios em `test-results/manual-results.json`, que está excluído do Git.
