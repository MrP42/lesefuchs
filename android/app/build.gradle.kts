plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("org.jetbrains.kotlin.plugin.serialization")
}

// Version kommt aus dem Release-Workflow (-PlesefuchsVersion=…), lokal Standardwert.
val appVersionName = (project.findProperty("lesefuchsVersion") as String?) ?: "0.1.0"
val appVersionCode = (project.findProperty("lesefuchsVersionCode") as String?)?.toInt() ?: 1
// Signaturschlüssel nur in CI gesetzt; lokal wird mit dem Debug-Schlüssel gebaut.
val releaseKeystore = System.getenv("LESEFUCHS_KEYSTORE")

android {
    namespace = "de.lesefuchs.spike"
    compileSdk = 34

    defaultConfig {
        applicationId = "de.lesefuchs.spike"
        minSdk = 28          // Fire OS 7 (Android 9)
        targetSdk = 34
        versionCode = appVersionCode
        versionName = appVersionName
        ndk {
            // Fire Tablets: nur arm64 nötig — halbiert die APK-Größe (Konzept §2.2)
            abiFilters += "arm64-v8a"
        }
    }

    signingConfigs {
        create("release") {
            if (releaseKeystore != null) {
                storeFile = file(releaseKeystore)
                storePassword = System.getenv("LESEFUCHS_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("LESEFUCHS_KEY_ALIAS")
                keyPassword = System.getenv("LESEFUCHS_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            // Ohne Schlüssel (lokaler Build) mit Debug-Signatur, damit
            // `assembleRelease` immer durchläuft.
            signingConfig = if (releaseKeystore != null) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        buildConfig = true   // VERSION_NAME für die Update-Prüfung
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.09.02")
    implementation(composeBom)
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.activity:activity-compose:1.9.2")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.6")

    // Wiedergabe (Konzept §5.1) — kein GMS
    implementation("androidx.media3:media3-exoplayer:1.4.1")

    // content.json / manifest.json
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.3")

    // Spike C: ML Kit BUNDLED — Modell im APK, läuft ohne Play Services (Konzept §2.2)
    implementation("com.google.mlkit:text-recognition:16.0.1")

    // Spike C: sherpa-onnx für Piper-TTS — gepinntes AAR aus den GitHub-Releases
    // (v1.13.6, enthält JNI-Libs inkl. arm64-v8a), bewusst kein Maven-Artefakt.
    implementation(files(rootProject.file("libs/sherpa-onnx-1.13.6.aar")))
}
