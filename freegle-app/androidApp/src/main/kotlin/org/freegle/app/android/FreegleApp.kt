package org.freegle.app.android

import android.app.Application
import org.freegle.app.di.sharedModule
import org.koin.android.ext.koin.androidContext
import org.koin.core.context.startKoin

class FreegleApp : Application() {
    override fun onCreate() {
        super.onCreate()
        startKoin {
            androidContext(this@FreegleApp)
            modules(sharedModule(BuildConfig.API_BASE_URL))
        }
    }
}
