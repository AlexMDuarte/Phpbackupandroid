package pt.alexmduarte.manualtests;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.provider.MediaStore;
import android.os.Bundle;
import android.os.Build;
import android.provider.Settings;
import android.graphics.Color;
import android.view.Gravity;
import android.view.View;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.view.MotionEvent;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.OutputStream;
import java.io.InputStream;
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
    private TestRow cameraRow;

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
                Exception lastError = null;
                String[] targets = {target, target.replace("localhost", "127.0.0.1")};
                for (String attempt : targets) {
                    try {
                        HttpURLConnection connection = (HttpURLConnection) new URL(attempt).openConnection();
                        connection.setRequestMethod("POST");
                        connection.setConnectTimeout(8000);
                        connection.setReadTimeout(8000);
                        connection.setDoOutput(true);
                        connection.setUseCaches(false);
                        connection.setRequestProperty("Connection", "close");
                        connection.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
                        byte[] body = payload.toString().getBytes(StandardCharsets.UTF_8);
                        connection.setFixedLengthStreamingMode(body.length);
                        try (OutputStream output = connection.getOutputStream()) { output.write(body); }
                        int response = connection.getResponseCode();
                        InputStream responseStream = response >= 400 ? connection.getErrorStream() : connection.getInputStream();
                        if (responseStream != null) responseStream.close();
                        connection.disconnect();
                        showStatus(response >= 200 && response < 300 ? "Resultados enviados com sucesso." : "O site respondeu com erro HTTP " + response + ".");
                        return;
                    } catch (Exception error) {
                        lastError = error;
                    }
                }
                throw lastError;
            } catch (Exception error) {
                showStatus("Falha de ligação (" + error.getClass().getSimpleName() + "). Abra o PHP em localhost:8080, toque em Testar tudo na página e tente novamente.");
            }
        });
    }

    private void openCamera(TestRow row) {
        cameraRow = row;
        try {
            startActivityForResult(new Intent(MediaStore.ACTION_IMAGE_CAPTURE), 1001);
        } catch (Exception error) {
            row.markFail("Não foi possível abrir a câmara: " + error.getMessage());
        }
    }

    private void openTouchTest(TestRow row) {
        TouchPad pad = new TouchPad();
        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("Teste do touchscreen")
                .setMessage("Toque em pelo menos 12 pontos diferentes da área abaixo.")
                .setView(pad)
                .setNegativeButton("Cancelar", null)
                .create();
        pad.onComplete = () -> {
            row.markPass("Área de toque confirmada.");
            dialog.dismiss();
        };
        dialog.show();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == 1001 && cameraRow != null) {
            if (resultCode == RESULT_OK) cameraRow.markPass("Câmara abriu e devolveu uma imagem.");
            else cameraRow.markFail("A câmara foi fechada sem confirmar uma imagem.");
            cameraRow = null;
        }
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
            if (name.equals("Câmaras")) {
                pass.setText("Abrir câmara");
                pass.setOnClickListener(view -> openCamera(this));
            } else if (name.equals("Touchscreen")) {
                pass.setText("Iniciar teste");
                pass.setOnClickListener(view -> openTouchTest(this));
            } else {
                pass.setOnClickListener(view -> markPass("Confirmado manualmente."));
            }
            fail.setOnClickListener(view -> markFail("Marcado como falha."));
            actions.addView(pass, new LinearLayout.LayoutParams(0, dp(48), 1));
            actions.addView(fail, new LinearLayout.LayoutParams(0, dp(48), 1));
            container.addView(actions);
            note.setHint("Observação opcional"); note.setSingleLine(true); note.setTextSize(12);
            container.addView(note, new LinearLayout.LayoutParams(-1, dp(46)));
        }

        void markPass(String detail) {
            status = "pass";
            note.setText(detail);
        }

        void markFail(String detail) {
            status = "fail";
            note.setText(detail);
        }
    }

    private class TouchPad extends View {
        private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
        private int touches;
        private Runnable onComplete;

        TouchPad() {
            super(MainActivity.this);
            setBackgroundColor(Color.rgb(232, 240, 226));
            paint.setColor(Color.rgb(52, 113, 87));
            paint.setStrokeWidth(dp(3));
        }

        @Override protected void onDraw(Canvas canvas) {
            super.onDraw(canvas);
            canvas.drawText("Toque e arraste aqui", dp(18), dp(32), paint);
        }

        @Override public boolean onTouchEvent(MotionEvent event) {
            if (event.getAction() == MotionEvent.ACTION_DOWN || event.getAction() == MotionEvent.ACTION_MOVE) {
                touches++;
                canvasPoint(event.getX(), event.getY());
                if (touches >= 12 && onComplete != null) {
                    Runnable complete = onComplete;
                    onComplete = null;
                    complete.run();
                }
                return true;
            }
            return true;
        }

        private void canvasPoint(float x, float y) {
            setBackgroundColor(Color.rgb(210, 235, 207));
            invalidate();
        }
    }
}
