package pt.alexmduarte.smsbackup;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

public class SmsReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        // The helper does not process incoming SMS; this receiver makes the app eligible for the SMS role.
    }
}
