# Shopware 6 Plugins - JOVEpay

JOVEpay Gateway
```
zip -r jovepay-shopware.zip jovepay-shopware  \
  -x "*.git*" "*/node_modules/*" "*.DS_Store"
```
Install the Shopware plugin and Activate it
Go to Plugins >> Configuration and enter your JOVEpay API key and the IPN URL.
Click “Save”
Go to Sales, find payment methods and select "JOVEpay” as a default payment method
Save everything
Create a test order with our Payment Gateway
Complete the payment