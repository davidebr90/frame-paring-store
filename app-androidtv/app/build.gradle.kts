plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Indirizzo del server proposto al primo avvio. Si può cambiare senza
// toccare questo file, utile per provare su televisori veri (che non sanno
// cosa sia 10.0.2.2, valido solo dentro l'emulatore):
//   gradlew assembleDebug -PserverUrl=http://192.168.1.78:8080
val serverUrl = project.findProperty("serverUrl") as String?

android {
    namespace = "com.frameparing.tv"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.frameparing.tv"
        // minSdk 22 = Android 5.1: copre anche i Fire Stick più vecchi (Fire OS 5)
        minSdk = 22
        targetSdk = 34
        // Allineata a APP_VERSION di server/core/version.php
        versionCode = 11300
        versionName = "1.13.0"

        // URL del server proposto di default nella schermata di primo avvio
        // (modificabile lì; cambialo qui o con -PserverUrl per le tue build)
        buildConfigField("String", "DEFAULT_BASE_URL", "\"${serverUrl ?: "https://example.com"}\"")
    }

    buildFeatures {
        buildConfig = true
    }

    buildTypes {
        release {
            isMinifyEnabled = false
        }
        debug {
            // 10.0.2.2 = localhost del PC visto dall'emulatore Android
            buildConfigField("String", "DEFAULT_BASE_URL", "\"${serverUrl ?: "http://10.0.2.2:8080"}\"")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    // Tre sole dipendenze, ognuna con un motivo preciso:
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1") // rete/IO fuori dal main thread
    implementation("androidx.media3:media3-exoplayer:1.4.1")                 // riproduzione video
    // Formati delle dirette da sorgente esterna: senza questi moduli un
    // indirizzo .m3u8/.mpd/rtsp:// non sarebbe riproducibile
    implementation("androidx.media3:media3-exoplayer-hls:1.4.1")
    implementation("androidx.media3:media3-exoplayer-dash:1.4.1")
    implementation("androidx.media3:media3-exoplayer-rtsp:1.4.1")
    implementation("com.google.zxing:core:3.5.3")                            // generazione QR on-device (pure Java)
}
