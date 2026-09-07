package pt.alexmduarte.manualtests;

import android.app.Activity;
import android.os.Bundle;
import android.os.Build;
import android.provider.Settings;
import android.graphics.Color;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class MainActivity extends Activity {
    private final ExecutorService network = Executors.newSingleThreadExecutor();
    private final Map<String, TestRow> tests = new LinkedHashMap<>();
    private TextView status;
    private EditText endpoint;

    private static final String[] NAMES = {"Bateria", "Touchscreen", "Flash", "Câmaras", "Microfone", "Coluna de alta voz", "Auscultador", "Vibrador", "Wi-Fi", "Bluetooth", "GPS"};
    private static final String[] INSTRUCTIONS = {"Verifique o nível e o carregamento.", "Toque em vários pontos do ecrã.", "Ative o flash e confirme a luz.", "Abra as câmaras frontal e traseira.", "Grave e reproduza uma amostra.", "Reproduza um som em volume médio.", "Faça uma chamada ou reproduza áudio.", "Ative a vibração e confirme a resposta.", "Confirme ligação e navegação.", "Ative e procure um dispositivo.", "Abra um mapa e confirme a localização."};

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        buildScreen();
    }

    private void buildScreen() {
        int pad = dp(18);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad, dp(28), pad, pad);

        TextView title = text("Âncora Testes", 29, Color.rgb(23, 33, 28));
        root.addView(title, new LinearLayout.LayoutParams(-1, dp(52)));
        TextView intro = text("Confirme manualmente cada componente do equipamento. Os resultados ficam guardados no site.", 15, Color.rgb(109, 119, 111));
        root.addView(intro, new LinearLayout.LayoutParams(-1, dp(62)));

        endpoint = new EditText(this);
        endpoint.setText("http://127.0.0.1:8080/confirmations.php");
        endpoint.setHint("URL do site");
        endpoint.setSingleLine(true);
        root.addView(endpoint, new LinearLayout.LayoutParams(-1, dp(52)));

        for (int index = 0; index < NAMES.length; index++) {
            TestRow row = new TestRow(NAMES[index], INSTRUCTIONS[index]);
            tests.put(NAMES[index], row);
            root.addView(row.container, new LinearLayout.LayoutParams(-1, -2));
        }

        Button send = new Button(this);
        send.setText("Enviar confirmações para o site");
        send.setOnClickListener(view -> sendResults());
        LinearLayout.LayoutParams sendParams = new LinearLayout.LayoutParams(-1, dp(56));
        sendParams.topMargin = dp(18);
        root.addView(send, sendParams);
        status = text("Nenhum resultado enviado.", 13, Color.rgb(52, 113, 87));
        root.addView(status, new LinearLayout.LayoutParams(-1, dp(55)));

        ScrollView scroll = new ScrollView(this);
        scroll.addView(root);
        setContentView(scroll);
    }

    private void sendResults() {
        final String target = endpoint.getText().toString().trim();
        if (target.isEmpty()) { status.setText("Indique a URL do site."); return; }
        status.setText("A enviar resultados...");
        network.execute(() -> {
            try {
                JSONObject payload = new JSONObject();
                payload.put("device", Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID));
                payload.put("model", Build.MANUFACTURER + " " + Build.MODEL);
                JSONArray results = new JSONArray();
                for (TestRow row : tests.values()) {
                    JSONObject result = new JSONObject();
                    result.put("name", row.name);
                    result.put("status", row.status);
                    result.put("note", row.note.getText().toString());
                    results.put(result);
                }
                payload.put("results", results);
                HttpURLConnection connection = (HttpURLConnection) new URL(target).openConnection();
                connection.setRequestMethod("POST");
                connection.setConnectTimeout(8000);
                connection.setReadTimeout(8000);
                connection.setDoOutput(true);
                connection.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
                try (OutputStream output = connection.getOutputStream()) { output.write(payload.toString().getBytes(StandardCharsets.UTF_8)); }
                int response = connection.getResponseCode();
                showStatus(response >= 200 && response < 300 ? "Resultados enviados com sucesso." : "O site respondeu com erro HTTP " + response + ".");
            } catch (Exception error) {
                showStatus("Não foi possível contactar o site: " + error.getMessage());
            }
        });
    }

    private void showStatus(String message) { runOnUiThread(() -> status.setText(message)); }

    private TextView text(String value, int size, int color) {
        TextView view = new TextView(this);
        view.setText(value); view.setTextSize(size); view.setTextColor(color); view.setPadding(0, dp(5), 0, dp(5));
        return view;
    }

    private int dp(int value) { return (int) (value * getResources().getDisplayMetrics().density + .5f); }

    private class TestRow {
        final String name;
        final LinearLayout container = new LinearLayout(MainActivity.this);
        final EditText note = new EditText(MainActivity.this);
        String status = "fail";

        TestRow(String testName, String instruction) {
            name = testName;
            container.setOrientation(LinearLayout.VERTICAL);
            container.setPadding(0, dp(9), 0, dp(5));
            TextView heading = text(name, 17, Color.rgb(23, 33, 28));
            container.addView(heading, new LinearLayout.LayoutParams(-1, dp(31)));
            container.addView(text(instruction, 12, Color.rgb(109, 119, 111)), new LinearLayout.LayoutParams(-1, dp(30)));
            LinearLayout actions = new LinearLayout(MainActivity.this);
            actions.setGravity(Gravity.CENTER_VERTICAL);
            Button pass = new Button(MainActivity.this); pass.setText("Funcionou");
            Button fail = new Button(MainActivity.this); fail.setText("Falhou");
            pass.setOnClickListener(view -> { status = "pass"; heading.setText(name + "  ✓"); });
            fail.setOnClickListener(view -> { status = "fail"; heading.setText(name + "  ×"); });
            actions.addView(pass, new LinearLayout.LayoutParams(0, dp(48), 1));
            actions.addView(fail, new LinearLayout.LayoutParams(0, dp(48), 1));
            container.addView(actions);
            note.setHint("Observação opcional"); note.setSingleLine(true); note.setTextSize(12);
            container.addView(note, new LinearLayout.LayoutParams(-1, dp(46)));
        }
    }
}
