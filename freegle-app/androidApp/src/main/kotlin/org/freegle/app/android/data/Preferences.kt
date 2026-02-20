package org.freegle.app.android.data

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.doublePreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "freegle_prefs")

class FreeglePreferences(private val context: Context) {

    companion object {
        private val POSTCODE = stringPreferencesKey("postcode")
        private val LOCATION_NAME = stringPreferencesKey("location_name")
        private val LAT = doublePreferencesKey("lat")
        private val LNG = doublePreferencesKey("lng")
        private val DEVICE_ID = stringPreferencesKey("device_id")
        private val ONBOARDING_COMPLETE = booleanPreferencesKey("onboarding_complete")
        private val TOUR_COMPLETE = booleanPreferencesKey("tour_complete")
    }

    suspend fun saveLocation(postcode: String, locationName: String, lat: Double, lng: Double) {
        context.dataStore.edit { prefs ->
            prefs[POSTCODE] = postcode
            prefs[LOCATION_NAME] = locationName
            prefs[LAT] = lat
            prefs[LNG] = lng
        }
    }

    suspend fun getPostcode(): String =
        context.dataStore.data.map { it[POSTCODE] ?: "" }.first()

    suspend fun getLocationName(): String =
        context.dataStore.data.map { it[LOCATION_NAME] ?: "" }.first()

    suspend fun getLat(): Double =
        context.dataStore.data.map { it[LAT] ?: 0.0 }.first()

    suspend fun getLng(): Double =
        context.dataStore.data.map { it[LNG] ?: 0.0 }.first()

    suspend fun getOrCreateDeviceId(): String {
        val existing = context.dataStore.data.map { it[DEVICE_ID] }.first()
        if (existing != null) return existing
        val newId = java.util.UUID.randomUUID().toString()
        context.dataStore.edit { it[DEVICE_ID] = newId }
        return newId
    }

    suspend fun getDeviceId(): String? =
        context.dataStore.data.map { it[DEVICE_ID] }.first()

    suspend fun isOnboardingComplete(): Boolean =
        context.dataStore.data.map { it[ONBOARDING_COMPLETE] ?: false }.first()

    suspend fun setOnboardingComplete() {
        context.dataStore.edit { it[ONBOARDING_COMPLETE] = true }
    }

    suspend fun isTourComplete(): Boolean =
        context.dataStore.data.map { it[TOUR_COMPLETE] ?: false }.first()

    suspend fun setTourComplete() {
        context.dataStore.edit { it[TOUR_COMPLETE] = true }
    }
}
