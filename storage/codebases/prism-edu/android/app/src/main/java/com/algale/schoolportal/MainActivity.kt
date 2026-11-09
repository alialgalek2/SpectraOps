package com.algale.schoolportal

import android.annotation.SuppressLint
import android.app.Activity
import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.webkit.*
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var offlineLayout: LinearLayout
    private lateinit var btnRetry: Button
    private lateinit var tvServerUrlInfo: TextView
    private lateinit var btnChangeServer: Button

    private var fileChooserCallback: ValueCallback<Array<Uri>>? = null
    private var innerScrollTop: Int = 0

    // Target Server URL (Dynamically loaded from SharedPreferences / assets/app_config.json with fallback)
    private var appUrl: String = "http://192.168.1.30:8080/school_system/login.php"

    /**
     * Normalizes a server URL string to always target the login.php portal endpoint cleanly.
     */
    private fun normalizePortalUrl(rawUrl: String): String {
        val trimmed = rawUrl.trim().trimEnd('/')
        return if (trimmed.endsWith(".php")) {
            trimmed
        } else {
            "$trimmed/login.php"
        }
    }

    /**
     * Resolves the target portal URL with preference order:
     * 1. User-customized URL from SharedPreferences
     * 2. Injected server_url from assets/app_config.json
     * 3. Built-in network fallback
     */
    private fun loadServerUrl(): String {
        val prefs = getSharedPreferences("prism_prefs", MODE_PRIVATE)
        val customUrl = prefs.getString("custom_server_url", null)
        if (!customUrl.isNullOrBlank()) {
            return normalizePortalUrl(customUrl)
        }

        return try {
            val inputStream: java.io.InputStream = assets.open("app_config.json")
            val jsonString = inputStream.bufferedReader().use { it.readText() }
            val json = org.json.JSONObject(jsonString)
            val server = json.optString("server_url", "http://192.168.1.30:8080/school_system")
            normalizePortalUrl(server)
        } catch (_: Exception) {
            "http://192.168.1.30:8080/school_system/login.php"
        }
    }

    // JavaScript Interface to receive scroll position events from the web layout component
    inner class WebAppInterface {
        @JavascriptInterface
        fun onScrollPositionChanged(scrollTop: Int) {
            innerScrollTop = scrollTop
            runOnUiThread {
                // Pull gesture only activates when the user is genuinely at the top of the content
                swipeRefresh.isEnabled = (innerScrollTop == 0 && webView.scrollY == 0 && !webView.canScrollVertically(-1))
            }
        }

        @JavascriptInterface
        fun logoutAndExit() {
            runOnUiThread {
                logoutAndCleanSession()
                finishAffinity()
            }
        }

        /**
         * Allows the authenticated web portal to dynamically update the app's target server URL.
         */
        @JavascriptInterface
        fun setServerUrl(newServerUrl: String) {
            if (newServerUrl.isNotBlank()) {
                val normalized = normalizePortalUrl(newServerUrl)
                runOnUiThread {
                    val prefs = getSharedPreferences("prism_prefs", MODE_PRIVATE)
                    prefs.edit().putString("custom_server_url", normalized).apply()
                    appUrl = normalized
                }
            }
        }
    }

    private val fileChooserLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            val data: Intent? = result.data
            val results: Array<Uri>? = when {
                data?.dataString != null -> arrayOf(Uri.parse(data.dataString))
                data?.clipData != null -> {
                    val count = data.clipData!!.itemCount
                    Array(count) { i -> data.clipData!!.getItemAt(i).uri }
                }
                else -> null
            }
            fileChooserCallback?.onReceiveValue(results)
        } else {
            fileChooserCallback?.onReceiveValue(null)
        }
        fileChooserCallback = null
    }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.webView)
        swipeRefresh = findViewById(R.id.swipeRefreshLayout)
        offlineLayout = findViewById(R.id.offlineLayout)
        btnRetry = findViewById(R.id.btnRetry)
        tvServerUrlInfo = findViewById(R.id.tvServerUrlInfo)
        btnChangeServer = findViewById(R.id.btnChangeServer)

        configureWebView()
        configureSwipeRefresh()

        btnRetry.setOnClickListener {
            loadPortal()
        }

        btnChangeServer.setOnClickListener {
            showChangeServerDialog()
        }

        appUrl = loadServerUrl()

        if (savedInstanceState == null) {
            // App launched from closed state: require fresh login by clearing any residual session data
            clearSessionData()
            loadPortal()
        }
    }

    /**
     * Displays a dialog allowing the user or network administrator to update the portal server link.
     */
    private fun showChangeServerDialog() {
        val currentBase = if (appUrl.endsWith("/login.php")) {
            appUrl.substringBeforeLast("/login.php")
        } else {
            appUrl
        }

        val input = EditText(this).apply {
            setText(currentBase)
            setSelection(text.length)
            setHint("http://192.168.1.30:8080/school_system")
            setPadding(40, 24, 40, 24)
        }

        AlertDialog.Builder(this)
            .setTitle("Change Server URL")
            .setMessage("Enter the school server address:")
            .setView(input)
            .setPositiveButton("Save & Connect") { _, _ ->
                val newUrl = input.text.toString().trim()
                if (newUrl.isNotBlank()) {
                    val normalized = normalizePortalUrl(newUrl)
                    val prefs = getSharedPreferences("prism_prefs", MODE_PRIVATE)
                    prefs.edit().putString("custom_server_url", normalized).apply()
                    appUrl = normalized
                    clearSessionData()
                    loadPortal()
                }
            }
            .setNeutralButton("Reset Default") { _, _ ->
                val prefs = getSharedPreferences("prism_prefs", MODE_PRIVATE)
                prefs.edit().remove("custom_server_url").apply()
                appUrl = loadServerUrl()
                clearSessionData()
                loadPortal()
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun configureWebView() {
        // Enable Cookie Persistence across sessions
        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(webView, true)

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            useWideViewPort = true
            loadWithOverviewMode = true
            builtInZoomControls = false
            displayZoomControls = false
            cacheMode = WebSettings.LOAD_DEFAULT
            allowFileAccess = true
        }

        // Custom User-Agent tag for mobile app detection (PrismApp/2.5.0)
        val defaultAgent = webView.settings.userAgentString
        webView.settings.userAgentString = "$defaultAgent PrismApp/2.5.0"

        // Bridge JavaScript scroll events from the web app's main layout
        webView.addJavascriptInterface(WebAppInterface(), "AndroidInterface")

        // Track WebView vertical scroll position
        webView.setOnScrollChangeListener { _, _, scrollY, _, _ ->
            // Prevent accidental refreshes from mid-scroll
            swipeRefresh.isEnabled = (scrollY == 0 && innerScrollTop == 0 && !webView.canScrollVertically(-1))
        }

        webView.webViewClient = object : WebViewClient() {
            override fun onReceivedError(
                view: WebView?,
                request: WebResourceRequest?,
                error: WebResourceError?
            ) {
                if (request?.isForMainFrame == true) {
                    showOfflineView()
                }
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                swipeRefresh.isRefreshing = false
                cookieManager.flush() // Persist auth session cookies
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            // File upload handler (Receipt photos, homework PDFs, documents)
            override fun onShowFileChooser(
                webView: WebView?,
                filePathCallback: ValueCallback<Array<Uri>>?,
                fileChooserParams: FileChooserParams?
            ): Boolean {
                fileChooserCallback?.onReceiveValue(null)
                fileChooserCallback = filePathCallback

                val intent = fileChooserParams?.createIntent() ?: Intent(Intent.ACTION_GET_CONTENT).apply {
                    type = "*/*"
                    addCategory(Intent.CATEGORY_OPENABLE)
                }

                try {
                    fileChooserLauncher.launch(intent)
                } catch (e: Exception) {
                    fileChooserCallback = null
                    return false
                }
                return true
            }
        }
    }

    private fun configureSwipeRefresh() {
        // Implement scroll position check: verify that container scroll top is zero
        // before allowing pull gesture to activate
        swipeRefresh.setOnChildScrollUpCallback { _, _ ->
            // Return true if child can scroll up (disables pull-to-refresh)
            // Return false ONLY when genuinely at the very top (scrollTop == 0)
            innerScrollTop > 0 || webView.scrollY > 0 || webView.canScrollVertically(-1)
        }

        swipeRefresh.setOnRefreshListener {
            if (isNetworkAvailable()) {
                offlineLayout.visibility = View.GONE
                webView.visibility = View.VISIBLE
                webView.reload()
            } else {
                showOfflineView()
            }
        }
    }

    private fun loadPortal() {
        if (isNetworkAvailable()) {
            offlineLayout.visibility = View.GONE
            webView.visibility = View.VISIBLE
            webView.loadUrl(appUrl)
        } else {
            showOfflineView()
        }
    }

    private fun showOfflineView() {
        swipeRefresh.isRefreshing = false
        webView.visibility = View.GONE
        tvServerUrlInfo.text = "Target: $appUrl"
        offlineLayout.visibility = View.VISIBLE
    }

    private fun isNetworkAvailable(): Boolean {
        val connectivityManager = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        val network = connectivityManager.activeNetwork ?: return false
        val capabilities = connectivityManager.getNetworkCapabilities(network) ?: return false
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    /**
     * Terminate the server-side PHP session and invalidate local authentication cookies & storage.
     */
    private fun logoutAndCleanSession() {
        try {
            val cookieManager = CookieManager.getInstance()
            val currentCookies = cookieManager.getCookie(appUrl)
            performServerLogout(currentCookies)
            clearSessionData()
        } catch (_: Exception) { }
    }

    /**
     * Dispatch an asynchronous network request to the backend logout script to invalidate the PHP session.
     */
    private fun performServerLogout(cookies: String?) {
        if (cookies.isNullOrEmpty()) return
        Thread {
            try {
                val logoutUrl = if (appUrl.contains("/")) {
                    appUrl.substringBeforeLast("/") + "/logout.php"
                } else {
                    "http://192.168.1.30:8080/school_system/logout.php"
                }
                val url = java.net.URL(logoutUrl)
                val conn = url.openConnection() as java.net.HttpURLConnection
                conn.requestMethod = "GET"
                conn.setRequestProperty("Cookie", cookies)
                if (::webView.isInitialized) {
                    conn.setRequestProperty("User-Agent", webView.settings.userAgentString)
                }
                conn.connectTimeout = 2000
                conn.readTimeout = 2000
                conn.responseCode
                conn.disconnect()
            } catch (_: Exception) {
                // Silently ignore if offline or unreachable
            }
        }.start()
    }

    /**
     * Purge all local cookies, cache, web storage, and history to require re-authentication.
     */
    private fun clearSessionData() {
        try {
            val cookieManager = CookieManager.getInstance()
            cookieManager.removeSessionCookies(null)
            cookieManager.removeAllCookies(null)
            cookieManager.flush()
            WebStorage.getInstance().deleteAllData()
            if (::webView.isInitialized) {
                webView.clearCache(true)
                webView.clearFormData()
                webView.clearHistory()
            }
        } catch (_: Exception) {
            CookieManager.getInstance().removeAllCookies(null)
            CookieManager.getInstance().flush()
        }
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack() // Navigate back inside web history
        } else {
            // Exiting app: execute logout rule and require user to log back in
            logoutAndCleanSession()
            super.onBackPressed()
        }
    }

    override fun onDestroy() {
        // When the app closes, logout the user and invalidate session
        logoutAndCleanSession()
        super.onDestroy()
    }
}
