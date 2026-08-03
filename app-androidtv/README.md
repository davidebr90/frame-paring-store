# Frame Paring — App Android TV / Fire Stick

Player di digital signage in Kotlin puro. Tre sole dipendenze:
coroutines (rete fuori dal main thread), Media3/ExoPlayer (video),
ZXing core (QR generato sulla TV).

## Compilazione

**Con Android Studio (consigliato, nessun prerequisito):**
1. Installare [Android Studio](https://developer.android.com/studio) — include JDK e Android SDK.
2. `File → Open` e selezionare questa cartella (`app-androidtv`).
3. Attendere il sync di Gradle (al primo avvio scarica SDK e dipendenze).
4. `Build → Build App Bundle(s) / APK(s) → Build APK(s)`.
5. APK generata in `app/build/outputs/apk/debug/app-debug.apk`.

**Da riga di comando** (serve JDK 17 e la variabile `ANDROID_HOME`):
```
gradlew assembleDebug
```

La APK **debug** è già firmata (chiave debug) e installabile subito sui
dispositivi. Per distribuzioni durature usare `assembleRelease` con una
propria chiave di firma (Build → Generate Signed App Bundle/APK).

Prima di compilare puoi impostare il server predefinito in
`app/build.gradle.kts` → `DEFAULT_BASE_URL` (resta comunque modificabile
sulla TV al primo avvio).

## Installazione (sideload)

La stessa APK va bene per **entrambe** le piattaforme (Fire OS è Android).

**Fire TV Stick**
1. Impostazioni → La mia Fire TV → Opzioni sviluppatore →
   attivare *Debug ADB* e *App da origini sconosciute*.
2. Dal PC, sulla stessa rete: `adb connect <IP_della_TV>` poi
   `adb install app-debug.apk`.
   (In alternativa: app *Downloader* sulla TV e URL diretto della APK.)

**Android TV / Google TV**
1. Impostazioni → Info → premere 7 volte su *Build* per sbloccare le
   Opzioni sviluppatore → attivare *Debug USB / Debug di rete*.
2. `adb connect <IP_della_TV>` poi `adb install app-debug.apk`.

## Avvio automatico al boot

- Fire OS 5/6/7 e Android ≤ 9: funziona subito dopo il primo avvio manuale.
- Android 10+ / Fire OS 8: concedere una tantum all'app il permesso
  **"Visualizza sopra altre app"** (Impostazioni → App → Frame Paring →
  autorizzazioni speciali), altrimenti il sistema blocca l'avvio dal boot.

## Note sui formati

- Video consigliati: **MP4 H.264 + AAC** (hardware decoding su tutti i
  Fire Stick e le Android TV). WebM/VP9 dipende dal modello.
- Le GIF vengono mostrate come immagine statica (primo frame).
