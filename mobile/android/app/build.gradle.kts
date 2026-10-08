import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

/*
 * Firebase, only if this checkout has been given a project.
 *
 * `google-services.json` carries a school's own Firebase project id and API
 * key, so it is not in the repository and never should be. Applying the plugin
 * unconditionally would mean a fresh clone fails to build with an error about a
 * missing file, which is a poor welcome for something the app treats as
 * optional anyway — PushService degrades to "push unavailable" without it.
 *
 * Drop the file in at android/app/google-services.json and the next build picks
 * it up. See mobile/README.md.
 */
if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
} else {
    logger.lifecycle(
        "SchoolPilot: no android/app/google-services.json — building without push."
    )
}

/*
 * Release signing, from android/key.properties (git-ignored, never committed).
 *
 * The file names the upload keystore and its passwords; the keystore itself
 * lives outside the repository. Without the file a release build falls back
 * to the debug key with a warning — fine for a test APK, and refused by Google
 * Play, which is the point: a store build cannot be signed by accident with a
 * key anyone holds.
 */
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
val hasReleaseKey = keystorePropertiesFile.exists()
if (hasReleaseKey) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
} else {
    logger.lifecycle("SchoolPilot: no android/key.properties — release builds use the debug key.")
}

android {
    namespace = "ng.schoolpilot.schoolpilot"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        /*
         * flutter_local_notifications refuses to link without this: it uses
         * java.time, which only exists from API 26, and the app ships lower.
         * D8 backports those classes rather than us raising minSdk and
         * dropping the older handsets this product is aimed at.
         */
        isCoreLibraryDesugaringEnabled = true
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        applicationId = "ng.schoolpilot.schoolpilot"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (hasReleaseKey) {
            create("release") {
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
                storeFile = file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (hasReleaseKey) signingConfigs.getByName("release") else signingConfigs.getByName("debug")
        }
    }
}

flutter {
    source = "../.."
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}
