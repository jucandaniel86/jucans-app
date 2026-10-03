# iOS Branding

The installed display name is `CFBundleDisplayName` in `ios/App/App/Info.plist`.
Capacitor's `appName` is configured in `capacitor.config.ts`. Both are `Jucans`.
The existing bundle identifier is `com.jucans.family`.

The icon source is `src/assets/jucans_logo.png`, also used by Android.
From `mobile/`, regenerate only the iOS icon with Java 21 or newer:

```sh
java -Djava.awt.headless=true scripts/GenerateIosIcon.java
```

Android Studio's bundled Java runtime can also run this command.

The generator writes `ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png`.
Despite the existing filename, this is a 1024x1024 opaque RGB PNG. The existing
`Contents.json` defines it as the universal iOS app icon, and Xcode generates
the required iPhone/iPad sizes when compiling the asset catalog. The logo is
centered with uniform scaling on a white canvas; rounded corners are not baked
in. iOS supplies its own mask.

These are checked-in native source resources, not copied web assets. Normal
`npm run build`, `npx cap sync ios`, and `npx cap open ios` preserve the display
name and AppIcon. Regeneration is needed only when changing the logo source.
This generator does not modify Android resources or native project settings.
