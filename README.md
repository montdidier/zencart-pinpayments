# Zencart integration with [Pin Payments](https://pin.net.au)

## Installing 

**Before you proceed, make a backup of your database. Installation is done at your own risk.**

Clone this repository to your computer or download one of the tarballs from the [release](https://github.com/montdidier/zencart-pinpayments/releases) section.

Using ftp or a similar method, upload all the files from the *files/new_files* directory to your store root.

A number of core files have been modified within this integration and consideration should be made for each (i.e. if modified by another module or integration). Changes made for this integration have been clearly commented. If you do need to merge changes rather than simply overwrite the distribution files you can use a tool such as [DiffMerge](https://sourcegear.com/diffmerge/).

1. ajax.php - This file is required 
2. admin/orders.php - to allow issuing refunds directly from the admin section. Make sure you use the version that matches you Zencart installion. Optional as refunds can be issued directly from the Pin Payments merchant dashboard.

After all desired files are in place, log in to your Zencart admin section and navigate to admin->Modules->Payment. Locate *Pin Payments* and select it, then click on the "install" button on the right hand side. Enter you transaction key (obtained from the Pin Payments dashboard). Change any other settings as required and click "update".

In case installation fails to add the required database tables, you will have to install the tables manually. Log in to your admin section and go to Tools->Install SQL Patches. Open the "mysql_updates/pin_payments.sql that comes with this plugin, copy the content, paste it in the admin section and click the "send" button. NOTE: do NOT do this if you're not getting an error message instructing you to do that.

Your integration is now installed and ready for use.

**Keep in mind this module requires a secure connection (SSL certificate) to work.**

## Usage

Accept credit and debit cards, face‑to‑face or across the world. Simple signup with same-day activation. Visit https://pin.net.au/ for more information.

This plugin has a built-in support for Tokens - the card tokens API allows you to securely store credit card details in exchange for a card token. This card token can then be used to create a single charge, or to create multiple charges over time.

## License 

GPL. Please see the LICENSE.txt file within the repository for further details.
