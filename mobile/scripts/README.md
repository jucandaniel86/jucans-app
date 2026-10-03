# Android Launcher Icons

Source: `src/assets/jucans_logo.png` (existing transparent Jucans artwork).

From `mobile/`, regenerate with Java 21 or newer:

```sh
java -Djava.awt.headless=true scripts/GenerateAndroidIcons.java
```

On macOS, Android Studio's bundled runtime can be used:

```sh
"/Applications/Android Studio.app/Contents/jbr/Contents/Home/bin/java" -Djava.awt.headless=true scripts/GenerateAndroidIcons.java
```

The generator writes launcher, round-launcher, and transparent adaptive foreground
PNGs at mdpi through xxxhdpi. Adaptive XML resources in
`android/app/src/main/res/mipmap-anydpi-v26/` reference these foregrounds and the
existing white background. Artwork stays inside the central 66dp safe circle so
Samsung's rounded-square and Android's circular masks do not clip the logo.

Native label strings and icon resources are checked-in Android source resources,
not copied web assets. `npm run build` and `npx cap sync android` preserve them.
No icon-generation step is required for normal builds or synchronization.
