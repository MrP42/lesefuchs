plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("org.jetbrains.kotlin.plugin.serialization")
}

android {
    namespace = "de.lesefuchs.spike"
    compileSdk = 34

    defaultConfig {
        applicationId = "de.lesefuchs.spike"
        minSdk = 28          // Fire OS 7 (Android 9)
        targetSdk = 34
        versionCode = 1
        versionName = "0.1"
        ndk {
            // Fire Tablets: nur arm64 nötig — halbiert die APK-Größe (Konzept §2.2)
            abiFilters += "arm64-v8a"
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
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
