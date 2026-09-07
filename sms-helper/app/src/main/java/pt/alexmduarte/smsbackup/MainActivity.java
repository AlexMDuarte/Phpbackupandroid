package pt.alexmduarte.smsbackup;

import android.Manifest;
import android.app.Activity;
import android.app.role.RoleManager;
import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.provider.Telephony;
import android.view.Gravity;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import org.w3c.dom.Document;
import org.w3c.dom.Element;
import org.w3c.dom.NodeList;

import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

import javax.xml.parsers.DocumentBuilderFactory;

public class MainActivity extends Activity {
    private static final int PERMISSIONS = 40;
    private static final int PICK_XML = 41;
    private static final String WRITE_SMS_PERMISSION = "android.permission.WRITE_SMS";
    private TextView status;

    @Override
    public void onCreate(Bundle state) {
        super.onCreate(state);
        buildScreen();
    }

    private void buildScreen() {
        int pad = dp(22);
        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(pad, dp(34), pad, pad);

        TextView title = new TextView(this);
        title.setText("Âncora SMS");
        title.setTextSize(30);
        title.setTextColor(0xff17211c);
        title.setGravity(Gravity.CENTER_VERTICAL);
        content.addView(title, new LinearLayout.LayoutParams(-1, dp(55)));

        TextView explanation = new TextView(this);
        explanation.setText("Ferramenta auxiliar para exportar e restaurar SMS. A restauração exige que esta aplicação seja temporariamente a aplicação SMS predefinida.");
        explanation.setTextSize(15);
        explanation.setTextColor(0xff6d776f);
        content.addView(explanation, new LinearLayout.LayoutParams(-1, dp(90)));

        Button export = new Button(this);
        export.setText("Exportar SMS para Download");
        export.setOnClickListener(view -> exportSms());
        content.addView(export, new LinearLayout.LayoutParams(-1, dp(54)));

        Button importButton = new Button(this);
        importButton.setText("Escolher XML e restaurar SMS");
        importButton.setOnClickListener(view -> chooseXml());
        LinearLayout.LayoutParams importParams = new LinearLayout.LayoutParams(-1, dp(54));
        importParams.topMargin = dp(12);
        content.addView(importButton, importParams);

        status = new TextView(this);
        status.setText("Pronto.");
        status.setTextSize(14);
        status.setTextColor(0xff347157);
        status.setPadding(0, dp(24), 0, 0);
        content.addView(status, new LinearLayout.LayoutParams(-1, -2));

        ScrollView scroll = new ScrollView(this);
        scroll.addView(content);
        setContentView(scroll);
    }

    private void exportSms() {
        if (!ensurePermissions()) return;
        new Thread(() -> {
            Cursor cursor = getContentResolver().query(Telephony.Sms.CONTENT_URI, null, null, null, "date ASC");
            if (cursor == null) {
                showStatus("Não foi possível ler os SMS.");
                return;
            }
            File output = new File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS), "sms-backup.xml");
            int count = 0;
            try (BufferedOutputStream stream = new BufferedOutputStream(new FileOutputStream(output))) {
                stream.write("<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\" ?><smses>\n".getBytes("UTF-8"));
                while (cursor.moveToNext()) {
                    String address = xml(cursor.getString(cursor.getColumnIndexOrThrow(Telephony.Sms.ADDRESS)));
                    String body = xml(cursor.getString(cursor.getColumnIndexOrThrow(Telephony.Sms.BODY)));
                    long date = cursor.getLong(cursor.getColumnIndexOrThrow(Telephony.Sms.DATE));
                    int type = cursor.getInt(cursor.getColumnIndexOrThrow(Telephony.Sms.TYPE));
                    stream.write(("  <sms address=\"" + address + "\" date=\"" + date + "\" type=\"" + type + "\" body=\"" + body + "\" read=\"1\" />\n").getBytes("UTF-8"));
                    count++;
                }
                stream.write("</smses>\n".getBytes("UTF-8"));
                showStatus(count + " SMS exportados para " + output.getAbsolutePath());
            } catch (Exception error) {
                showStatus("Erro ao exportar: " + error.getMessage());
            } finally {
                cursor.close();
            }
        }).start();
    }

    private void chooseXml() {
        if (!ensurePermissions()) return;
        if (isDefaultSmsApp()) {
            openXmlPicker();
        } else {
            requestDefaultSmsApp();
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == 42) {
            if (isDefaultSmsApp()) {
                openXmlPicker();
            } else {
                showStatus("Defina Âncora SMS como aplicação SMS predefinida para restaurar.");
            }
            return;
        }
        if (requestCode == PICK_XML && resultCode == RESULT_OK && data != null) {
            new Thread(() -> importSms(data.getData())).start();
        }
    }

    private void openXmlPicker() {
        Intent picker = new Intent(Intent.ACTION_OPEN_DOCUMENT);
        picker.setType("text/xml");
        picker.addCategory(Intent.CATEGORY_OPENABLE);
        startActivityForResult(picker, PICK_XML);
    }

    private void importSms(Uri source) {
        int count = 0;
        try (InputStream stream = new BufferedInputStream(getContentResolver().openInputStream(source))) {
            Document document = DocumentBuilderFactory.newInstance().newDocumentBuilder().parse(stream);
            NodeList messages = document.getElementsByTagName("sms");
            ContentResolver resolver = getContentResolver();
            for (int index = 0; index < messages.getLength(); index++) {
                Element sms = (Element) messages.item(index);
                ContentValues values = new ContentValues();
                values.put(Telephony.Sms.ADDRESS, sms.getAttribute("address"));
                values.put(Telephony.Sms.BODY, sms.getAttribute("body"));
                values.put(Telephony.Sms.DATE, Long.parseLong(sms.getAttribute("date")));
                values.put(Telephony.Sms.TYPE, Integer.parseInt(sms.getAttribute("type")));
                values.put(Telephony.Sms.READ, 1);
                Uri inserted = resolver.insert(Telephony.Sms.CONTENT_URI, values);
                if (inserted == null) {
                    throw new IllegalStateException("O Android recusou a inserção da mensagem " + (index + 1));
                }
                count++;
            }
            showStatus(count + " SMS restaurados.");
        } catch (Exception error) {
            showStatus("Erro ao restaurar. Defina Âncora SMS como app predefinida e tente novamente: " + error.getMessage());
        }
    }

    private boolean ensurePermissions() {
        if (Build.VERSION.SDK_INT >= 23 && (checkSelfPermission(Manifest.permission.READ_SMS) != PackageManager.PERMISSION_GRANTED || checkSelfPermission(WRITE_SMS_PERMISSION) != PackageManager.PERMISSION_GRANTED)) {
            requestPermissions(new String[]{Manifest.permission.READ_SMS, WRITE_SMS_PERMISSION}, PERMISSIONS);
            return false;
        }
        return true;
    }

    private void requestDefaultSmsApp() {
        if (Build.VERSION.SDK_INT >= 29) {
            RoleManager roles = getSystemService(RoleManager.class);
            if (roles != null && roles.isRoleAvailable(RoleManager.ROLE_SMS) && !roles.isRoleHeld(RoleManager.ROLE_SMS)) {
                startActivityForResult(roles.createRequestRoleIntent(RoleManager.ROLE_SMS), 42);
            }
        } else {
            Intent intent = new Intent(Telephony.Sms.Intents.ACTION_CHANGE_DEFAULT);
            intent.putExtra(Telephony.Sms.Intents.EXTRA_PACKAGE_NAME, getPackageName());
            startActivity(intent);
        }
    }

    private boolean isDefaultSmsApp() {
        if (Build.VERSION.SDK_INT >= 29) {
            RoleManager roles = getSystemService(RoleManager.class);
            return roles != null && roles.isRoleHeld(RoleManager.ROLE_SMS);
        }
        return getPackageName().equals(Telephony.Sms.getDefaultSmsPackage(this));
    }

    private String xml(String value) {
        if (value == null) return "";
        return value.replace("&", "&amp;").replace("\"", "&quot;").replace("<", "&lt;").replace(">", "&gt;");
    }

    private void showStatus(String text) {
        runOnUiThread(() -> status.setText(text));
    }

    private int dp(int value) { return (int) (value * getResources().getDisplayMetrics().density + .5f); }
}
