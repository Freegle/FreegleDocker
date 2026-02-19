package org.freegle.app.api

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

class AuthManager {
    private val _token = MutableStateFlow<String?>(null)
    val token: StateFlow<String?> = _token.asStateFlow()

    private val _userId = MutableStateFlow<Long?>(null)
    val userId: StateFlow<Long?> = _userId.asStateFlow()

    val isLoggedIn: Boolean get() = _token.value != null

    fun setCredentials(token: String, userId: Long) {
        _token.value = token
        _userId.value = userId
    }

    fun clearCredentials() {
        _token.value = null
        _userId.value = null
    }
}
