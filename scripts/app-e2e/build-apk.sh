#!/usr/bin/env bash
# Builds the app the emulator gate installs: the Nuxt bundle generated exactly as the
# release Android job generates it (same exports as the orb's build-android job), except
# that the API, image, upload and site URLs point at the local Docker stack, wrapped in the
# `e2e` Gradle build type (release R8 config, debug signing; see android/app/build.gradle).
#
# Output: $OUT_DIR/freegle-e2e.apk, plus the app's origin in $OUT_DIR/app-origin.txt
# (what the WebView serves the bundle from), which the Playwright `app` project uses as
# its base URL.
#
# Environment: GOOGLE_SERVICES_JSON_BASE64 (optional) writes android/app/google-services.json
# as the CI jobs do, so push registration works against a google_apis image.
# SKIP_NPM_CI=1 skips `npm ci` when node_modules is already current.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

command -v node >/dev/null || die "node is required (22+)"
jv="$(java_major)"
[ -n "$jv" ] && [ "$jv" -ge 17 ] || die "the Android build needs JDK 17 or newer, found '${jv:-none}'"
require_sdk

cd "$NUXT_DIR"
mkdir -p "$OUT_DIR"

if [ ! -f node_modules/.package-lock.json ] && [ "${SKIP_NPM_CI:-0}" != 1 ]; then
	log "npm ci"
	npm ci --no-audit --no-fund
fi

if [ "${SKIP_GENERATE:-0}" = 1 ] && [ -f .output/public/index.html ]; then
	log "SKIP_GENERATE=1: reusing .output/public"
else
	log "generating the app bundle against $STACK_API_V2"
	# Mirrors the orb's build-android job (Build Nuxt App): the same exports, with the
	# stack URLs substituted. IZNIK_NUXT3_IS_APP is a CircleCI project variable there
	# (config.js reads it, not ISAPP), so it is set explicitly here.
	export NODE_OPTIONS="--max-old-space-size=3584"
	export ISAPP=true
	export IZNIK_NUXT3_IS_APP=true
	export APP_ENV=production
	export IZNIK_API_V2="$STACK_API_V2"
	export TUS_UPLOADER="$STACK_TUS_UPLOADER"
	export IMAGE_DELIVERY="$STACK_IMAGE_DELIVERY"
	export USER_SITE="$STACK_USER_SITE"
	export NUXT_NITRO_PRERENDER_ROUTES=false
	# Not the app's Sentry project (SENTRY_DSN_APP_FD in CI): its events carry no marker
	# that separates them from members' devices. Unset, the bundle reports to the web
	# project, as the local and CI stacks do.
	unset SENTRY_DSN
	# A CircleCI project variable in the release build. The app's donation component
	# mounts Stripe with it and reports its absence to the console, so a machine
	# without the key gets a test-mode placeholder rather than a false alarm.
	if [ -z "${STRIPE_PUBLISHABLE_KEY:-}" ]; then
		log "STRIPE_PUBLISHABLE_KEY unset: using a placeholder test key (Stripe itself is not exercised)"
		export STRIPE_PUBLISHABLE_KEY="pk_test_placeholder_app_e2e"
	fi
	npm run generate
fi

if [ -n "${GOOGLE_SERVICES_JSON_BASE64:-}" ]; then
	log "writing android/app/google-services.json from GOOGLE_SERVICES_JSON_BASE64"
	echo "$GOOGLE_SERVICES_JSON_BASE64" | tr -d '\n' | base64 -d >android/app/google-services.json
	node -e 'JSON.parse(require("fs").readFileSync("android/app/google-services.json","utf8"))' ||
		die "google-services.json is not valid JSON"
elif [ ! -f android/app/google-services.json ]; then
	# Without any Firebase configuration the push plugin's register() throws on a plugin
	# thread and the whole app dies the moment it starts, so a machine without the real
	# file (CI has it) gets a placeholder: Firebase initialises, token registration fails
	# with the plugin's registrationError event, and the fake-FCM delivery tests still run.
	log "no google-services.json and no GOOGLE_SERVICES_JSON_BASE64: using the placeholder (push registration will fail, delivery tests still run)"
	cp "$APP_E2E_DIR/google-services.placeholder.json" android/app/google-services.json
	PLACEHOLDER_FIREBASE=1
fi

log "cap sync android (FREEGLE_APP_E2E=true)"
# capacitor.config.ts throws unless the keystore variables are set; the e2e build type signs
# with the debug key and never reads them, so placeholders keep config evaluation honest
# without a keystore on the machine.
export FREEGLE_NUXT3_KEYSTORE_PATH="${FREEGLE_NUXT3_KEYSTORE_PATH:-/dev/null}"
export FREEGLE_NUXT3_KEYSTORE_PASSWORD="${FREEGLE_NUXT3_KEYSTORE_PASSWORD:-unused}"
export FREEGLE_NUXT3_KEYSTORE_ALIAS="${FREEGLE_NUXT3_KEYSTORE_ALIAS:-unused}"
export FREEGLE_NUXT3_KEYALIAS_PASSWORD="${FREEGLE_NUXT3_KEYALIAS_PASSWORD:-unused}"
FREEGLE_APP_E2E=true npx cap sync android

cfg="android/app/src/main/assets/capacitor.config.json"
[ -f "$cfg" ] || die "cap sync did not write $cfg"
node -e '
const c = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"))
if (!c.android || c.android.webContentsDebuggingEnabled !== true || c.android.allowMixedContent !== true) {
  console.error("capacitor.config.json lacks the e2e WebView settings; FREEGLE_APP_E2E did not reach cap sync")
  process.exit(1)
}
const scheme = (c.server && c.server.androidScheme) || "https"
const host = (c.server && c.server.hostname) || "localhost"
process.stdout.write(scheme + "://" + host)
' "$cfg" >"$OUT_DIR/app-origin.txt"
log "app origin: $(cat "$OUT_DIR/app-origin.txt")"

mkdir -p ~/.android
if [ ! -f ~/.android/debug.keystore ]; then
	log "creating the debug keystore"
	keytool -genkey -v -keystore ~/.android/debug.keystore -storepass android \
		-alias androiddebugkey -keypass android -keyalg RSA -keysize 2048 -validity 10000 \
		-dname "CN=Android Debug,O=Android,C=US" >/dev/null 2>&1
fi

log "gradle assembleE2e"
# `bash gradlew`, not `./gradlew`: the wrapper is committed without the execute bit and a
# chmod would leave the checkout with a mode change to revert.
(cd android && bash ./gradlew assembleE2e --no-daemon --stacktrace ${GRADLE_FLAGS:-} 2>&1 | grep -v '^\s*$' | tail -40)
apk="android/app/build/outputs/apk/e2e/app-e2e.apk"
[ -f "$apk" ] || die "expected $apk after the build, it is missing"
cp "$apk" "$OUT_DIR/freegle-e2e.apk"
log "APK: $OUT_DIR/freegle-e2e.apk ($(du -h "$OUT_DIR/freegle-e2e.apk" | cut -f1))"
