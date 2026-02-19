plugins {
    alias(libs.plugins.androidApplication)
    alias(libs.plugins.composeCompiler)
    alias(libs.plugins.kotlinAndroid)
}

android {
    namespace = "org.freegle.app.android"
    compileSdk = 35

    defaultConfig {
        applicationId = "org.freegle.app"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"

        // API base URL - configurable for different environments
        // Port 18193 is the external port mapped from apiv2 container's 8192
        buildConfigField("String", "API_BASE_URL", "\"http://10.0.2.2:18193/api\"")
    }

    buildFeatures {
        compose = true
        buildConfig = true
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
    implementation(project(":shared"))

    // Compose
    val composeBom = platform(libs.compose.bom)
    implementation(composeBom)
    implementation(libs.compose.ui)
    implementation(libs.compose.ui.graphics)
    implementation(libs.compose.ui.tooling.preview)
    implementation(libs.compose.material3)
    implementation(libs.compose.material.icons)
    implementation(libs.compose.navigation)
    debugImplementation(libs.compose.ui.tooling)

    // Lifecycle
    implementation(libs.lifecycle.runtime)
    implementation(libs.lifecycle.viewmodel)

    // Activity
    implementation(libs.activity.compose)

    // Coroutines
    implementation(libs.kotlinx.coroutines.android)

    // Koin
    implementation(libs.koin.android)
    implementation(libs.koin.compose)

    // Coil (image loading)
    implementation(libs.coil.compose)
    implementation(libs.coil.network.ktor)

    // DataStore
    implementation(libs.datastore.preferences)

    // Google Fonts for Nunito (warm community typography)
    implementation("androidx.compose.ui:ui-text-google-fonts:1.7.8")
}
