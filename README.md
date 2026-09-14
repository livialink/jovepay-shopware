# Shopware 6 Plugins - JOVEpay

JOVEpay Gateway

## Build install ZIP

Shopware rejects ZIPs where `composer.json` sits at the archive root. The first entry must be the plugin folder:

```
jovepay-shopware.zip
└── jovepay-shopware/
    ├── composer.json
    └── src/
```

From the repo root:

```bash
./libs/jovepay-shopware/bin/build-release.sh
```

That writes `libs/jovepay-shopware.zip`.

Manual equivalent (run from `libs/`, **not** from inside the plugin folder):

```bash
cd libs
zip -r jovepay-shopware.zip jovepay-shopware \
  -x "jovepay-shopware/.git/*" \
  -x "jovepay-shopware/**/.DS_Store" \
  -x "**/__MACOSX/**"
```

Do **not** use macOS Finder “Compress”, and do **not** zip the folder contents (`cd jovepay-shopware && zip -r ../plugin.zip .`) — both produce invalid archives.

## Install

1. Upload `jovepay-shopware.zip` in Administration → Extensions → My extensions → Upload extension.
2. Install and activate the plugin (activation assigns JOVEpay to all sales channels).
3. Go to Extensions → JOVEpay → Configuration and enter your JOVEpay API key.
4. Click Save.
5. Optional: under Sales Channels → your channel → Payment methods, set JOVEpay as the default.
6. Create a test order and complete payment with JOVEpay.

If the method is missing after an upgrade from an older zip, deactivate and reactivate the plugin once (or clear cache) so sales-channel assignment runs again.
