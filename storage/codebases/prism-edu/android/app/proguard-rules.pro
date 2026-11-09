# Proguard rules for SchoolPortal WebView hybrid app
-keepclassmembers class * extends android.webkit.WebChromeClient {
    public boolean onShowFileChooser(...);
}
-keepattributes JavascriptInterface
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}
